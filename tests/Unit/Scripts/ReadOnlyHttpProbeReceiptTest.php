<?php

declare(strict_types=1);

use Ops\ReadOnlyProbeReceiptV1;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ops/lib/ReadOnlyProbeReceiptV1.php';

final class ReadOnlyHttpProbeReceiptTest extends TestCase
{
    public function testReceiptIsCanonicalAndContainsOnlyClosedSecurityProperties(): void
    {
        $checks = [
            'modern_confirmation_redirect' => true,
            'legacy_confirmation_redirect' => true,
            'modern_ics_missing' => true,
            'legacy_ics_missing' => true,
            'modern_ics_headers_safe' => true,
            'legacy_ics_headers_safe' => true,
        ];
        $receipt = ReadOnlyProbeReceiptV1::create(
            'passed',
            $checks,
            ['modern' => 'appointments', 'legacy' => 'appointments'],
            ['modern' => 'not_calendar_no_disposition', 'legacy' => 'not_calendar_no_disposition'],
        );
        $encoded = ReadOnlyProbeReceiptV1::canonicalJson($receipt);

        self::assertSame($receipt, ReadOnlyProbeReceiptV1::decode($encoded));
        self::assertStringNotContainsString('http', $encoded);
        self::assertStringNotContainsString('capability', $encoded);
        self::assertStringNotContainsString('location', $encoded);
    }

    public function testApplicationFailureKeepsClosedFailedProperties(): void
    {
        $receipt = ReadOnlyProbeReceiptV1::create(
            'application_failed',
            [],
            ['modern' => 'unexpected', 'legacy' => 'appointments'],
            ['modern' => 'calendar', 'legacy' => 'not_calendar_no_disposition'],
        );

        self::assertSame(20, $receipt['exit_code']);
        self::assertSame($receipt, ReadOnlyProbeReceiptV1::decode(ReadOnlyProbeReceiptV1::canonicalJson($receipt)));
    }

    public function testEnvironmentAndUnknownOutcomesCannotClaimProperties(): void
    {
        foreach (['environment_failed' => 21, 'unknown' => 70] as $outcome => $exitCode) {
            $receipt = ReadOnlyProbeReceiptV1::create($outcome);

            self::assertSame($exitCode, $receipt['exit_code']);
            self::assertSame($receipt, ReadOnlyProbeReceiptV1::decode(ReadOnlyProbeReceiptV1::canonicalJson($receipt)));

            $thrown = false;
            try {
                ReadOnlyProbeReceiptV1::create(
                    $outcome,
                    ['modern_confirmation_redirect' => true],
                    ['modern' => 'appointments', 'legacy' => 'malformed'],
                    ['modern' => 'malformed', 'legacy' => 'malformed'],
                );
            } catch (RuntimeException) {
                $thrown = true;
            }
            self::assertTrue($thrown, $outcome . ' must not claim a check or class');
        }
    }

    public function testPassedReceiptRejectsAnyFailedProperty(): void
    {
        $this->expectException(RuntimeException::class);
        ReadOnlyProbeReceiptV1::create(
            'passed',
            ['modern_confirmation_redirect' => true],
            ['modern' => 'appointments', 'legacy' => 'appointments'],
            ['modern' => 'not_calendar_no_disposition', 'legacy' => 'not_calendar_no_disposition'],
        );
    }

    public function testWrapperSuccessUsesBothCapabilityRoutesAndEmitsOneReceipt(): void
    {
        [$status, $output] = $this->runWrapper('success');

        self::assertSame(0, $status);
        self::assertCount(1, $output);
        $receipt = ReadOnlyProbeReceiptV1::decode($output[0] . "\n");
        self::assertSame('passed', $receipt['outcome']);
        self::assertSame(6, $receipt['check_count']);
        self::assertStringNotContainsString('dasforscherhaus', $output[0]);
        self::assertStringNotContainsString('booking_confirmation', $output[0]);
    }

    public function testWrapperClassifiesHttpHeaderAndRedirectFailuresAsApplicationFailure(): void
    {
        foreach (['unexpected_http', 'unexpected_header', 'unexpected_redirect'] as $scenario) {
            [$status, $output] = $this->runWrapper($scenario);

            self::assertSame(20, $status, $scenario);
            self::assertCount(1, $output, $scenario);
            self::assertSame('application_failed', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);
        }
    }

    public function testWrapperSeparatesCurlFailureAndMalformedOutput(): void
    {
        [$curlStatus, $curlOutput] = $this->runWrapper('curl_nonzero');
        self::assertSame(21, $curlStatus);
        self::assertCount(1, $curlOutput);
        self::assertSame('environment_failed', ReadOnlyProbeReceiptV1::decode($curlOutput[0] . "\n")['outcome']);

        [$malformedStatus, $malformedOutput] = $this->runWrapper('malformed');
        self::assertSame(70, $malformedStatus);
        self::assertCount(1, $malformedOutput);
        self::assertSame('unknown', ReadOnlyProbeReceiptV1::decode($malformedOutput[0] . "\n")['outcome']);
    }

    public function testWrapperRejectsAnUnapprovedTargetWithoutLeakingIt(): void
    {
        $output = [];
        $status = 0;
        exec(
            'READ_ONLY_PROBE_BASE_URL=https://unapproved.example bash ' .
                escapeshellarg(__DIR__ . '/../../../scripts/ops/run_read_only_http_probe.sh'),
            $output,
            $status,
        );

        self::assertSame(70, $status);
        self::assertCount(1, $output);
        self::assertSame('unknown', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);
        self::assertStringNotContainsString('unapproved.example', $output[0]);
    }

    /** @return array{0:int,1:array<int,string>} */
    private function runWrapper(string $scenario): array
    {
        $directory = sys_get_temp_dir() . '/read-only-probe-curl-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $curl = $directory . '/curl';
        file_put_contents(
            $curl,
            <<<'SH'
            #!/bin/sh
            header=''
            url=''
            while [ "$#" -gt 0 ]; do
                case "$1" in
                    -D|--dump-header) header="$2"; shift 2; continue ;;
                    --output|--write-out|--max-time) shift 2; continue ;;
                    --silent|--show-error) shift; continue ;;
                esac
                url="$1"
                shift
            done
            if [ "${MOCK_CURL_SCENARIO}" != 'curl_nonzero' ] && [ "${MOCK_CURL_SCENARIO}" != 'malformed' ]; then
                capability="${url##*/}"
                case "${url}" in
                    */booking_confirmation/of/*|*/appointments/ics/*)
                        case "${#capability}" in 64|12) ;; *) exit 9 ;; esac
                        case "${capability}" in *[!0-9a-f]*) exit 9 ;; esac
                        ;;
                    *) exit 9 ;;
                esac
            fi
            case "${MOCK_CURL_SCENARIO}" in
                curl_nonzero) exit 7 ;;
                malformed) printf 'not-a-status'; exit 0 ;;
                unexpected_http)
                    [ -n "${header}" ] && printf 'HTTP/1.1 200 OK\n\n' >"${header}"
                    printf '200'
                    exit 0
                    ;;
                unexpected_redirect)
                    [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\nLocation: /other\n\n' >"${header}"
                    printf '307'
                    exit 0
                    ;;
                unexpected_header)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\nContent-Type: text/calendar\nContent-Disposition: attachment\n\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\nLocation: /appointments\n\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                success)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
            esac
            exit 2
            SH
            ,
        );
        chmod($curl, 0700);

        try {
            $output = [];
            $status = 0;
            $command =
                'PATH=' .
                escapeshellarg($directory . ':/usr/bin:/bin') .
                ' MOCK_CURL_SCENARIO=' .
                escapeshellarg($scenario) .
                ' READ_ONLY_PROBE_BASE_URL=https://dasforscherhaus-leg.de bash ' .
                escapeshellarg(__DIR__ . '/../../../scripts/ops/run_read_only_http_probe.sh');
            exec($command, $output, $status);

            return [$status, $output];
        } finally {
            unlink($curl);
            rmdir($directory);
        }
    }
}

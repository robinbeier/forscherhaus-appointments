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
            'production',
            $checks,
            ['modern' => 'appointments', 'legacy' => 'appointments'],
            ['modern' => 'not_calendar_no_disposition', 'legacy' => 'not_calendar_no_disposition'],
        );
        $encoded = ReadOnlyProbeReceiptV1::canonicalJson($receipt);

        self::assertSame($receipt, ReadOnlyProbeReceiptV1::decode($encoded));
        self::assertStringNotContainsString('http', $encoded);
        self::assertStringNotContainsString('capability', $encoded);
        self::assertStringNotContainsString('location', $encoded);
        self::assertSame('production', $receipt['target_class']);
    }

    public function testApplicationFailureKeepsClosedFailedProperties(): void
    {
        $receipt = ReadOnlyProbeReceiptV1::create(
            'application_failed',
            'production',
            [
                'legacy_confirmation_redirect' => true,
                'modern_ics_missing' => true,
                'legacy_ics_missing' => true,
                'legacy_ics_headers_safe' => true,
            ],
            ['modern' => 'unexpected', 'legacy' => 'appointments'],
            ['modern' => 'calendar', 'legacy' => 'not_calendar_no_disposition'],
        );

        self::assertSame(20, $receipt['exit_code']);
        self::assertSame($receipt, ReadOnlyProbeReceiptV1::decode(ReadOnlyProbeReceiptV1::canonicalJson($receipt)));
    }

    public function testReceiptRejectsUnknownTargetClass(): void
    {
        $this->expectException(RuntimeException::class);
        ReadOnlyProbeReceiptV1::create('environment_failed', 'other');
    }

    public function testCanonicalJsonUsesFixedTopLevelFieldOrder(): void
    {
        $receipt = ReadOnlyProbeReceiptV1::create('environment_failed', 'production');
        $reordered = array_reverse($receipt, true);

        self::assertSame(
            ReadOnlyProbeReceiptV1::canonicalJson($receipt),
            ReadOnlyProbeReceiptV1::canonicalJson($reordered),
        );

        $this->expectException(RuntimeException::class);
        ReadOnlyProbeReceiptV1::decode((string) json_encode($reordered, JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function testApplicationFailureRejectsContradictoryOrFullyPassingEvidence(): void
    {
        $passingChecks = [
            'modern_confirmation_redirect' => true,
            'legacy_confirmation_redirect' => true,
            'modern_ics_missing' => true,
            'legacy_ics_missing' => true,
            'modern_ics_headers_safe' => true,
            'legacy_ics_headers_safe' => true,
        ];

        foreach (
            [
                [
                    $passingChecks,
                    ['modern' => 'unexpected', 'legacy' => 'appointments'],
                    ['modern' => 'not_calendar_no_disposition', 'legacy' => 'not_calendar_no_disposition'],
                ],
                [
                    $passingChecks,
                    ['modern' => 'appointments', 'legacy' => 'appointments'],
                    ['modern' => 'not_calendar_no_disposition', 'legacy' => 'not_calendar_no_disposition'],
                ],
            ]
            as [$checks, $redirectClass, $icsHeaderClass]
        ) {
            $thrown = false;
            try {
                ReadOnlyProbeReceiptV1::create(
                    'application_failed',
                    'production',
                    $checks,
                    $redirectClass,
                    $icsHeaderClass,
                );
            } catch (RuntimeException) {
                $thrown = true;
            }
            self::assertTrue($thrown);
        }
    }

    public function testEnvironmentAndUnknownOutcomesCannotClaimProperties(): void
    {
        foreach (['environment_failed' => 21, 'unknown' => 70] as $outcome => $exitCode) {
            $receipt = ReadOnlyProbeReceiptV1::create($outcome, 'production');

            self::assertSame($exitCode, $receipt['exit_code']);
            self::assertSame($receipt, ReadOnlyProbeReceiptV1::decode(ReadOnlyProbeReceiptV1::canonicalJson($receipt)));

            $thrown = false;
            try {
                ReadOnlyProbeReceiptV1::create(
                    $outcome,
                    'production',
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
            'production',
            ['modern_confirmation_redirect' => true],
            ['modern' => 'appointments', 'legacy' => 'appointments'],
            ['modern' => 'not_calendar_no_disposition', 'legacy' => 'not_calendar_no_disposition'],
        );
    }

    public function testWrapperSuccessUsesBothCapabilityRoutesAndEmitsOneReceipt(): void
    {
        [$status, $output, $stderr] = $this->runWrapper('success');

        self::assertSame(0, $status);
        self::assertCount(1, $output);
        self::assertSame('', $stderr);
        $receipt = ReadOnlyProbeReceiptV1::decode($output[0] . "\n");
        self::assertSame('passed', $receipt['outcome']);
        self::assertSame('production', $receipt['target_class']);
        self::assertSame(6, $receipt['check_count']);
        self::assertStringNotContainsString('dasforscherhaus', $output[0]);
        self::assertStringNotContainsString('booking_confirmation', $output[0]);
    }

    public function testReceiptBindsEvidenceToProductionOrLocalTargetClass(): void
    {
        [$productionStatus, $productionOutput] = $this->runWrapper('success');
        [$localStatus, $localOutput] = $this->runWrapper('success', 'http://127.0.0.1:8123');

        self::assertSame(0, $productionStatus);
        self::assertSame(0, $localStatus);
        $productionReceipt = ReadOnlyProbeReceiptV1::decode($productionOutput[0] . "\n");
        $localReceipt = ReadOnlyProbeReceiptV1::decode($localOutput[0] . "\n");
        self::assertSame('production', $productionReceipt['target_class']);
        self::assertSame('local', $localReceipt['target_class']);
        self::assertNotSame($productionOutput[0], $localOutput[0]);
    }

    public function testWrapperAcceptsExpectedRelativeAndSameOriginAppRedirects(): void
    {
        foreach (['absolute_same_origin', 'app_relative', 'intermediate_unsafe_final_safe'] as $scenario) {
            [$status, $output, $stderr] = $this->runWrapper($scenario);

            self::assertSame(0, $status, $scenario);
            self::assertCount(1, $output, $scenario);
            self::assertSame('', $stderr, $scenario);
            self::assertSame('passed', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);
        }
    }

    public function testWrapperClassifiesHttpHeaderAndRedirectFailuresAsApplicationFailure(): void
    {
        foreach (
            [
                'unexpected_http',
                'unexpected_header',
                'unexpected_redirect',
                'external_redirect',
                'suffix_redirect',
                'duplicate_location',
            ]
            as $scenario
        ) {
            [$status, $output, $stderr] = $this->runWrapper($scenario);

            self::assertSame(20, $status, $scenario);
            self::assertCount(1, $output, $scenario);
            self::assertSame('', $stderr, $scenario);
            self::assertSame('application_failed', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);
        }
    }

    public function testWrapperClassifiesHeaderReadFailureAsEnvironmentFailure(): void
    {
        [$status, $output, $stderr] = $this->runWrapper('header_read_failure');

        self::assertSame(21, $status);
        self::assertCount(1, $output);
        self::assertSame('', $stderr);
        self::assertSame('environment_failed', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);
    }

    public function testTemporaryFileFailureEmitsOnlyEnvironmentReceipt(): void
    {
        [$status, $output, $stderr] = $this->runWrapper('mktemp_failure');

        self::assertSame(21, $status);
        self::assertCount(1, $output);
        self::assertSame('', $stderr);
        self::assertSame('environment_failed', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);
    }

    public function testReceiptOutputFailureRemovesAllTemporaryHeadersBeforeFailingClosed(): void
    {
        [$status, $output, $stderr, $headerFiles] = $this->runWrapper(
            'success',
            'https://dasforscherhaus-leg.de',
            'closed',
        );

        self::assertSame(70, $status);
        self::assertSame([], $output);
        self::assertSame('', $stderr);
        self::assertCount(4, $headerFiles);
        foreach ($headerFiles as $headerFile) {
            self::assertFalse(is_file($headerFile), $headerFile . ' must be removed before receipt output');
        }
    }

    public function testLateHeaderFailureClearsEarlierObservations(): void
    {
        [$status, $output, $stderr] = $this->runWrapper('late_header_read_failure');

        self::assertSame(21, $status);
        self::assertCount(1, $output);
        self::assertSame('', $stderr);
        $receipt = ReadOnlyProbeReceiptV1::decode($output[0] . "\n");
        self::assertSame('environment_failed', $receipt['outcome']);
        self::assertSame(
            [
                'modern_confirmation_redirect' => false,
                'legacy_confirmation_redirect' => false,
                'modern_ics_missing' => false,
                'legacy_ics_missing' => false,
                'modern_ics_headers_safe' => false,
                'legacy_ics_headers_safe' => false,
            ],
            $receipt['checks'],
        );
        self::assertSame(['modern' => 'malformed', 'legacy' => 'malformed'], $receipt['redirect_class']);
        self::assertSame(['modern' => 'malformed', 'legacy' => 'malformed'], $receipt['ics_header_class']);
    }

    public function testWrapperSeparatesCurlFailureAndMalformedOutput(): void
    {
        [$curlStatus, $curlOutput, $curlStderr] = $this->runWrapper('curl_nonzero');
        self::assertSame(21, $curlStatus);
        self::assertCount(1, $curlOutput);
        self::assertSame('', $curlStderr);
        self::assertSame('environment_failed', ReadOnlyProbeReceiptV1::decode($curlOutput[0] . "\n")['outcome']);

        [$malformedStatus, $malformedOutput, $malformedStderr] = $this->runWrapper('malformed');
        self::assertSame(70, $malformedStatus);
        self::assertCount(1, $malformedOutput);
        self::assertSame('', $malformedStderr);
        self::assertSame('unknown', ReadOnlyProbeReceiptV1::decode($malformedOutput[0] . "\n")['outcome']);
    }

    public function testWrapperRejectsAnUnapprovedTargetWithoutLeakingIt(): void
    {
        $output = [];
        $status = 0;
        $stderrFile = sys_get_temp_dir() . '/read-only-probe-invalid-' . bin2hex(random_bytes(8));
        exec(
            'READ_ONLY_PROBE_BASE_URL=https://unapproved.example bash ' .
                escapeshellarg(__DIR__ . '/../../../scripts/ops/run_read_only_http_probe.sh') .
                ' 2>' .
                escapeshellarg($stderrFile),
            $output,
            $status,
        );

        self::assertSame(70, $status);
        self::assertCount(1, $output);
        self::assertSame('', (string) file_get_contents($stderrFile));
        self::assertSame('unknown', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);
        self::assertSame('unapproved', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['target_class']);
        self::assertStringNotContainsString('unapproved.example', $output[0]);
        unlink($stderrFile);
    }

    /** @return array{0:int,1:array<int,string>,2:string,3:array<int,string>} */
    private function runWrapper(
        string $scenario,
        string $baseUrl = 'https://dasforscherhaus-leg.de',
        ?string $stdoutTarget = null,
    ): array {
        $directory = sys_get_temp_dir() . '/read-only-probe-curl-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $curl = $directory . '/curl';
        file_put_contents(
            $curl,
            <<<'SH'
            #!/bin/sh
            header=''
            url=''
            request_method=''
            retry=''
            max_redirs=''
            config=''
            [ "$1" = '--disable' ] || exit 8
            shift
            while [ "$#" -gt 0 ]; do
                case "$1" in
                    -D|--dump-header) header="$2"; shift 2; continue ;;
                    --output|--write-out|--max-time) shift 2; continue ;;
                    --silent|--show-error) shift; continue ;;
                    --config) config="$2"; shift 2; continue ;;
                    --request) request_method="$2"; shift 2; continue ;;
                    --retry) retry="$2"; shift 2; continue ;;
                    --max-redirs) max_redirs="$2"; shift 2; continue ;;
                    --location|--location-trusted) exit 8 ;;
                esac
                url="$1"
                shift
            done
            if [ -n "${MOCK_CURL_HEADER_LOG}" ]; then
                printf '%s\n' "${header}" >>"${MOCK_CURL_HEADER_LOG}"
            fi
            [ "${config}" = '/dev/null' ] || exit 8
            [ "${request_method}" = 'GET' ] || exit 8
            [ "${retry}" = '0' ] || exit 8
            [ "${max_redirs}" = '0' ] || exit 8
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
                absolute_same_origin)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: https://dasforscherhaus-leg.de/index.php/appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                app_relative)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /index.php/appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                intermediate_unsafe_final_safe)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/calendar\r\nContent-Disposition: attachment\r\n\r\nHTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 100 Continue\r\nLocation: https://external.example/appointments\r\n\r\nHTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                late_header_read_failure)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        capability="${url##*/}"
                        if [ "${#capability}" -eq 12 ]; then
                            rm -f -- "${header}"
                        else
                            [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        fi
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                external_redirect)
                    [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: https://external.example/appointments\r\n\r\n' >"${header}"
                    printf '307'
                    exit 0
                    ;;
                suffix_redirect)
                    [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /other/appointments\r\n\r\n' >"${header}"
                    printf '307'
                    exit 0
                    ;;
                duplicate_location)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: https://external.example/appointments\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                header_read_failure)
                    rm -f -- "${header}"
                    printf '307'
                    exit 0
                    ;;
                unexpected_header)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type:text/calendar\r\nContent-Disposition:attachment\r\n\r\n' >"${header}"
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
        $mktemp = $directory . '/mktemp';
        file_put_contents(
            $mktemp,
            <<<'SH'
            #!/bin/sh
            if [ "${MOCK_CURL_SCENARIO}" = 'mktemp_failure' ]; then
                printf 'private-local-template\n' >&2
                exit 1
            fi
            exec /usr/bin/mktemp "$@"
            SH
            ,
        );
        chmod($mktemp, 0700);

        try {
            $output = [];
            $status = 0;
            $stderrFile = $directory . '/stderr.log';
            $headerLog = $directory . '/headers.log';
            $command =
                'PATH=' .
                escapeshellarg($directory . ':/usr/bin:/bin') .
                ' MOCK_CURL_SCENARIO=' .
                escapeshellarg($scenario) .
                ' MOCK_CURL_HEADER_LOG=' .
                escapeshellarg($headerLog) .
                ' READ_ONLY_PROBE_BASE_URL=' .
                escapeshellarg($baseUrl) .
                ' ';
            $wrapperPath = __DIR__ . '/../../../scripts/ops/run_read_only_http_probe.sh';
            if ($stdoutTarget === 'closed') {
                $command .= 'bash -c ' . escapeshellarg('exec 1>&-; exec bash ' . escapeshellarg($wrapperPath));
            } else {
                $command .= 'bash ' . escapeshellarg($wrapperPath);
            }
            $command .= ' 2>' . escapeshellarg($stderrFile);
            if ($stdoutTarget !== null && $stdoutTarget !== 'closed') {
                $command .= ' >' . escapeshellarg($stdoutTarget);
            }
            exec($command, $output, $status);

            $headerFiles = is_file($headerLog)
                ? array_values(
                    array_filter(
                        file($headerLog, FILE_IGNORE_NEW_LINES) ?: [],
                        static fn(string $path): bool => $path !== '',
                    ),
                )
                : [];

            return [$status, $output, (string) file_get_contents($stderrFile), $headerFiles];
        } finally {
            unlink($curl);
            unlink($mktemp);
            if (isset($stderrFile) && is_file($stderrFile)) {
                unlink($stderrFile);
            }
            if (isset($headerLog) && is_file($headerLog)) {
                unlink($headerLog);
            }
            rmdir($directory);
        }
    }
}

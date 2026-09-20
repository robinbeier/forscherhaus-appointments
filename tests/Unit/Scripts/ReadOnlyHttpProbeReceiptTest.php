<?php

declare(strict_types=1);

use Ops\ReadOnlyProbeReceiptV1;
use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', dirname(__DIR__, 3) . '/system/');
require_once __DIR__ . '/../../../scripts/ops/lib/ReadOnlyProbeReceiptV1.php';
require_once __DIR__ . '/../../../application/core/Read_only_probe_request.php';
require_once __DIR__ . '/../../../application/libraries/Session/drivers/Session_null_driver.php';

final class ReadOnlyHttpProbeReceiptTest extends TestCase
{
    public function testProbeRequestClassifierIsLoopbackGetAndExactCapabilityBound(): void
    {
        self::assertTrue(
            $this->classifyRequest([
                'REQUEST_METHOD' => 'GET',
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => 'localhost:8123',
                'REQUEST_URI' => '/index.php/booking_confirmation/of/' . str_repeat('a', 64),
            ]),
        );
        self::assertTrue(
            $this->classifyRequest([
                'REQUEST_METHOD' => 'GET',
                'REMOTE_ADDR' => '::1',
                'HTTP_HOST' => '[::1]',
                'REQUEST_URI' => '/appointments/ics/' . str_repeat('b', 12),
            ]),
        );

        foreach (
            [
                ['HTTP_HOST' => 'dasforscherhaus-leg.de'],
                ['REMOTE_ADDR' => '192.0.2.10'],
                ['REQUEST_METHOD' => 'POST'],
                ['REQUEST_URI' => '/booking_confirmation/of/' . str_repeat('A', 64)],
                ['REQUEST_URI' => '/appointments/ics/' . str_repeat('c', 12) . '/suffix'],
            ]
            as $override
        ) {
            self::assertFalse($this->classifyRequest($override));
        }
    }

    public function testNullSessionDriverNeverReadsOrPersistsState(): void
    {
        $driver = new Session_null_driver();

        self::assertTrue($driver->open(sys_get_temp_dir(), 'ea_session'));
        self::assertSame('', $driver->read('session-id'));
        self::assertTrue($driver->write('session-id', 'private-state'));
        self::assertSame('', $driver->read('session-id'));
        self::assertTrue($driver->destroy('session-id'));
        self::assertTrue($driver->close());
    }

    public function testProbe404LoggingSuppressionIsLimitedToClassifiedRequests(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../application/controllers/Appointments.php');

        self::assertSame(3, substr_count($source, "show_404('', !Read_only_probe_request::is());"));
    }

    public function testCodeIgniterLoaderUsesNullSessionDriverWithoutFiles(): void
    {
        $directory = sys_get_temp_dir() . '/read-only-probe-session-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $script = $directory . '/loader.php';
        $basePath = dirname(__DIR__, 3) . '/system/';
        $appPath = dirname(__DIR__, 3) . '/application/';
        file_put_contents(
            $script,
            sprintf(
                <<<'PHP'
                <?php
                define('BASEPATH', %s);
                define('APPPATH', %s);
                function is_cli(): bool { return false; }
                function config_item(string $key): mixed {
                    return [
                        'sess_driver' => 'null', 'sess_cookie_name' => 'ea_session',
                        'sess_expiration' => 7200, 'sess_match_ip' => false,
                        'sess_time_to_update' => 0, 'cookie_path' => '/',
                        'cookie_domain' => '', 'cookie_secure' => false,
                    ][$key] ?? null;
                }
                function log_message(string $level, string $message): void {}
                $_SERVER['REQUEST_METHOD'] = 'GET';
                $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
                $_SERVER['HTTP_HOST'] = '127.0.0.1';
                require BASEPATH . 'libraries/Session/Session.php';
                $session = new CI_Session(['driver' => 'null', 'save_path' => %s]);
                $_SESSION['probe'] = 'synthetic';
                session_write_close();
                echo json_encode(['driver_loaded' => class_exists('Session_null_driver')]);
                PHP
                ,
                var_export($basePath, true),
                var_export($appPath, true),
                var_export($directory, true),
            ),
        );

        $output = [];
        $status = 0;
        exec('php ' . escapeshellarg($script), $output, $status);

        self::assertSame(0, $status);
        self::assertSame(['{"driver_loaded":true}'], $output);
        self::assertSame([], array_values(array_diff(scandir($directory) ?: [], ['.', '..', 'loader.php'])));

        unlink($script);
        rmdir($directory);
    }

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

    public function testUnapprovedTargetCreateAllowsOnlyNeutralUnknown(): void
    {
        $unknown = ReadOnlyProbeReceiptV1::create('unknown', 'unapproved');
        self::assertSame($unknown, ReadOnlyProbeReceiptV1::decode(ReadOnlyProbeReceiptV1::canonicalJson($unknown)));

        $fixtures = [
            [
                'passed',
                [
                    'modern_confirmation_redirect' => true,
                    'legacy_confirmation_redirect' => true,
                    'modern_ics_missing' => true,
                    'legacy_ics_missing' => true,
                    'modern_ics_headers_safe' => true,
                    'legacy_ics_headers_safe' => true,
                ],
                ['modern' => 'appointments', 'legacy' => 'appointments'],
                ['modern' => 'not_calendar_no_disposition', 'legacy' => 'not_calendar_no_disposition'],
            ],
            [
                'application_failed',
                [
                    'legacy_confirmation_redirect' => true,
                    'modern_ics_missing' => true,
                    'legacy_ics_missing' => true,
                    'legacy_ics_headers_safe' => true,
                ],
                ['modern' => 'unexpected', 'legacy' => 'appointments'],
                ['modern' => 'calendar', 'legacy' => 'not_calendar_no_disposition'],
            ],
            [
                'environment_failed',
                [],
                ['modern' => 'malformed', 'legacy' => 'malformed'],
                ['modern' => 'malformed', 'legacy' => 'malformed'],
            ],
        ];

        foreach ($fixtures as [$outcome, $checks, $redirectClass, $icsHeaderClass]) {
            $thrown = false;
            try {
                ReadOnlyProbeReceiptV1::create($outcome, 'unapproved', $checks, $redirectClass, $icsHeaderClass);
            } catch (RuntimeException) {
                $thrown = true;
            }
            self::assertTrue($thrown, $outcome . ' must reject an unapproved target');
        }
    }

    public function testDecodeRejectsNonUnknownUnapprovedReceipts(): void
    {
        $fixtures = [
            ReadOnlyProbeReceiptV1::create(
                'passed',
                'production',
                [
                    'modern_confirmation_redirect' => true,
                    'legacy_confirmation_redirect' => true,
                    'modern_ics_missing' => true,
                    'legacy_ics_missing' => true,
                    'modern_ics_headers_safe' => true,
                    'legacy_ics_headers_safe' => true,
                ],
                ['modern' => 'appointments', 'legacy' => 'appointments'],
                ['modern' => 'not_calendar_no_disposition', 'legacy' => 'not_calendar_no_disposition'],
            ),
            ReadOnlyProbeReceiptV1::create(
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
            ),
            ReadOnlyProbeReceiptV1::create('environment_failed', 'production'),
        ];

        foreach ($fixtures as $receipt) {
            $receipt['target_class'] = 'unapproved';
            $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES) . "\n";
            $thrown = false;
            try {
                ReadOnlyProbeReceiptV1::decode($encoded);
            } catch (RuntimeException) {
                $thrown = true;
            }
            self::assertTrue($thrown, $receipt['outcome'] . ' must reject an unapproved target');
        }
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
        self::assertSame('local', $receipt['target_class']);
        self::assertSame(6, $receipt['check_count']);
        self::assertStringNotContainsString('dasforscherhaus', $output[0]);
        self::assertStringNotContainsString('booking_confirmation', $output[0]);
    }

    public function testLocalReceiptCarriesExplicitlyUnverifiedProductionStateBoundary(): void
    {
        [$status, $output] = $this->runWrapper('success', 'http://127.0.0.1:8123');

        self::assertSame(0, $status);
        $receipt = ReadOnlyProbeReceiptV1::decode($output[0] . "\n");
        self::assertSame('local', $receipt['target_class']);
        self::assertSame(
            ['session' => 'not_applicable', 'rate_limit' => 'not_applicable', 'app_log' => 'not_applicable'],
            $receipt['state'],
        );
        self::assertSame('not_applicable', $receipt['cleanup']);
    }

    public function testWrapperAcceptsExpectedRelativeAndSameOriginAppRedirects(): void
    {
        foreach (
            ['absolute_same_origin', 'app_relative', 'intermediate_unsafe_final_safe', 'trailer_ics_headers']
            as $scenario
        ) {
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
                'folded_content_type',
                'folded_content_disposition',
                'whitespace_only_continuation',
                'trailer_location',
                'whitespace_location',
                'whitespace_content_type',
                'whitespace_content_disposition',
                'embedded_location_control',
            ]
            as $scenario
        ) {
            [$status, $output, $stderr] = $this->runWrapper($scenario);

            self::assertSame(20, $status, $scenario);
            self::assertCount(1, $output, $scenario);
            self::assertSame('', $stderr, $scenario);
            $receipt = ReadOnlyProbeReceiptV1::decode($output[0] . "\n");
            self::assertSame('application_failed', $receipt['outcome']);
            if ($scenario === 'whitespace_location') {
                self::assertSame(
                    ['modern' => 'malformed', 'legacy' => 'malformed'],
                    $receipt['redirect_class'],
                    $scenario,
                );
            }
            if (in_array($scenario, ['whitespace_content_type', 'whitespace_content_disposition'], true)) {
                self::assertSame(
                    ['modern' => 'malformed', 'legacy' => 'malformed'],
                    $receipt['ics_header_class'],
                    $scenario,
                );
                self::assertFalse($receipt['checks']['modern_ics_headers_safe']);
                self::assertFalse($receipt['checks']['legacy_ics_headers_safe']);
            }
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

    public function testMissingRmFailsBeforeCapabilityGenerationOrRequests(): void
    {
        [$status, $output, $stderr, $headerFiles] = $this->runWrapper('missing_rm');

        self::assertSame(21, $status);
        self::assertCount(1, $output);
        self::assertSame('', $stderr);
        self::assertSame([], $headerFiles);
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

    public function testReceiptOutputFailureRemovesAllTemporaryHeadersBeforeFailingClosed(): void
    {
        [$status, $output, $stderr, $headerFiles] = $this->runWrapper('success', 'http://127.0.0.1:8123', 'closed');

        self::assertSame(70, $status);
        self::assertSame([], $output);
        self::assertSame('', $stderr);
        self::assertCount(4, $headerFiles);
        foreach ($headerFiles as $headerFile) {
            self::assertFalse(is_file($headerFile), $headerFile . ' must be removed before receipt output');
        }
    }

    public function testCleanupFailureAfterLocalProbeCannotClaimCleanupNotApplicable(): void
    {
        [$status, $output, $stderr] = $this->runWrapper('cleanup_failure');

        self::assertSame(21, $status);
        self::assertCount(1, $output);
        self::assertSame('', $stderr);
        $receipt = ReadOnlyProbeReceiptV1::decode($output[0] . "\n");
        self::assertSame('environment_failed', $receipt['outcome']);
        self::assertSame('not_verified', $receipt['cleanup']);
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

    public function testResponseBindingRejectsMissingAndMalformedHeaders(): void
    {
        [$status, $output] = $this->runWrapper('binding_success');
        self::assertSame(0, $status);
        self::assertSame('passed', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);

        foreach (
            [
                'binding_missing',
                'binding_duplicate',
                'binding_folded',
                'binding_whitespace',
                'binding_control',
                'binding_mismatch',
            ]
            as $scenario
        ) {
            [$status, $output, $stderr] = $this->runWrapper($scenario);
            self::assertSame(21, $status, $scenario);
            self::assertCount(1, $output, $scenario);
            self::assertSame('', $stderr, $scenario);
            self::assertSame('environment_failed', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['outcome']);
        }
    }

    public function testEntropyPipelineFailuresEmitOnlyEnvironmentReceipt(): void
    {
        foreach (['od_failure', 'tr_failure'] as $scenario) {
            [$status, $output, $stderr, $headerFiles] = $this->runWrapper($scenario);

            self::assertSame(21, $status, $scenario);
            self::assertCount(1, $output, $scenario);
            self::assertSame('', $stderr, $scenario);
            self::assertSame([], $headerFiles, $scenario);
            $receipt = ReadOnlyProbeReceiptV1::decode($output[0] . "\n");
            self::assertSame('environment_failed', $receipt['outcome'], $scenario);
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
                $scenario,
            );
        }
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
        self::assertSame('not_verified', ReadOnlyProbeReceiptV1::decode($output[0] . "\n")['cleanup']);
        self::assertStringNotContainsString('unapproved.example', $output[0]);
        unlink($stderrFile);
    }

    public function testProductionModeFailsClosedWithoutExpectedReleaseAndActiveRoot(): void
    {
        $output = [];
        $status = 0;
        $stderrFile = sys_get_temp_dir() . '/read-only-probe-production-' . bin2hex(random_bytes(8));
        exec(
            'env -u READ_ONLY_PROBE_BASE_URL -u READ_ONLY_PROBE_EXPECTED_RELEASE bash ' .
                escapeshellarg(__DIR__ . '/../../../scripts/ops/run_read_only_http_probe.sh') .
                ' 2>' .
                escapeshellarg($stderrFile),
            $output,
            $status,
        );

        self::assertSame(70, $status);
        self::assertCount(1, $output);
        self::assertSame('', (string) file_get_contents($stderrFile));
        $receipt = ReadOnlyProbeReceiptV1::decode($output[0] . "\n");
        self::assertSame('unapproved', $receipt['target_class']);
        self::assertSame('unknown', $receipt['outcome']);
        self::assertSame(
            ['session' => 'unknown', 'rate_limit' => 'unknown', 'app_log' => 'unknown'],
            $receipt['state'],
        );
        self::assertSame('not_verified', $receipt['cleanup']);
        unlink($stderrFile);
    }

    /** @return array{0:int,1:array<int,string>,2:string,3:array<int,string>} */
    private function runWrapper(
        string $scenario,
        string $baseUrl = 'http://127.0.0.1:8123',
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
            noproxy=''
            config=''
            cookie=''
            cookie_jar=''
            [ "$1" = '--disable' ] || exit 8
            shift
            while [ "$#" -gt 0 ]; do
                case "$1" in
                    -D|--dump-header) header="$2"; shift 2; continue ;;
                    --output|--write-out|--max-time) shift 2; continue ;;
                    --silent|--show-error) shift; continue ;;
                    --cookie) cookie="$2"; shift 2; continue ;;
                    --cookie-jar) cookie_jar="$2"; shift 2; continue ;;
                    --config) config="$2"; shift 2; continue ;;
                    --request) request_method="$2"; shift 2; continue ;;
                    --retry) retry="$2"; shift 2; continue ;;
                    --max-redirs) max_redirs="$2"; shift 2; continue ;;
                    --noproxy) noproxy="$2"; shift 2; continue ;;
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
            [ "${noproxy}" = '*' ] || exit 8
            [ -n "${cookie}" ] || exit 8
            [ "${cookie}" = "${cookie_jar}" ] || exit 8
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
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:8123/index.php/appointments\r\n\r\n' >"${header}"
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
                folded_content_type)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type:\r\n text/calendar\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                folded_content_disposition)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\nContent-Disposition:\r\n attachment\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                whitespace_only_continuation)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n \t\r\nContent-Disposition: attachment\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                trailer_location)
                    [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nX-Test: normal\r\n\r\nLocation: /appointments\r\n' >"${header}"
                    printf '307'
                    exit 0
                    ;;
                trailer_ics_headers)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\nContent-Type: text/calendar\r\nContent-Disposition: attachment\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                whitespace_location)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation : /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                whitespace_content_type)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type : text/calendar\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                whitespace_content_disposition)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\nContent-Disposition : attachment\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appointments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
                    exit 0
                    ;;
                embedded_location_control)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        printf '404'
                    else
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nLocation: /appoint\rments\r\n\r\n' >"${header}"
                        printf '307'
                    fi
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
                binding_success|binding_missing|binding_duplicate|binding_folded|binding_whitespace|binding_control|binding_mismatch)
                    if printf '%s' "${url}" | grep -q '/appointments/ics/'; then
                        status='404'
                        if [ "${MOCK_CURL_SCENARIO}" = 'binding_missing' ]; then
                            [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        elif [ "${MOCK_CURL_SCENARIO}" = 'binding_duplicate' ]; then
                            [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nX-FH-Read-Only-Probe-Root: dev:1:2\r\nX-FH-Read-Only-Probe-Root: dev:1:2\r\nX-FH-Read-Only-Probe-Release: ea_test\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        elif [ "${MOCK_CURL_SCENARIO}" = 'binding_folded' ]; then
                            [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nX-FH-Read-Only-Probe-Root: dev:1:2\r\n folded\r\nX-FH-Read-Only-Probe-Release: ea_test\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        elif [ "${MOCK_CURL_SCENARIO}" = 'binding_whitespace' ]; then
                            [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nX-FH-Read-Only-Probe-Root : dev:1:2\r\nX-FH-Read-Only-Probe-Release: ea_test\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        elif [ "${MOCK_CURL_SCENARIO}" = 'binding_control' ]; then
                            [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nX-FH-Read-Only-Probe-Root: dev:1:2\r\nX-FH-Read-Only-Probe-Release: ea_test\001\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        elif [ "${MOCK_CURL_SCENARIO}" = 'binding_mismatch' ]; then
                            [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nX-FH-Read-Only-Probe-Root: dev:9:9\r\nX-FH-Read-Only-Probe-Release: ea_test\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        else
                            [ -n "${header}" ] && printf 'HTTP/1.1 404 Not Found\r\nX-FH-Read-Only-Probe-Root: dev:1:2\r\nX-FH-Read-Only-Probe-Release: ea_test\r\nContent-Type: text/html\r\n\r\n' >"${header}"
                        fi
                    else
                        status='307'
                        [ -n "${header}" ] && printf 'HTTP/1.1 307 Temporary Redirect\r\nX-FH-Read-Only-Probe-Root: dev:1:2\r\nX-FH-Read-Only-Probe-Release: ea_test\r\nLocation: /appointments\r\n\r\n' >"${header}"
                    fi
                    printf '%s' "${status}"
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

        $od = $directory . '/od';
        $tr = $directory . '/tr';
        $rm = null;
        if ($scenario === 'od_failure') {
            file_put_contents($od, "#!/bin/sh\nprintf 'od diagnostic' >&2\nexit 1\n");
            chmod($od, 0700);
        }
        if ($scenario === 'tr_failure') {
            file_put_contents($tr, "#!/bin/sh\nprintf 'tr diagnostic' >&2\nexit 1\n");
            chmod($tr, 0700);
        }

        if ($scenario === 'cleanup_failure') {
            $rm = $directory . '/rm';
            file_put_contents($rm, "#!/bin/sh\nexit 1\n");
            chmod($rm, 0700);
        }

        if ($scenario === 'missing_rm') {
            foreach (['od', 'tr', 'awk'] as $command) {
                symlink('/usr/bin/' . $command, $directory . '/' . $command);
            }
        }

        try {
            $output = [];
            $status = 0;
            $stderrFile = $directory . '/stderr.log';
            $headerLog = $directory . '/headers.log';
            $path = $scenario === 'missing_rm' ? $directory : $directory . ':/usr/bin:/bin';
            $baseUrlEnv =
                $baseUrl === 'http://127.0.0.1' ? '' : ' READ_ONLY_PROBE_BASE_URL=' . escapeshellarg($baseUrl);
            if (str_starts_with($scenario, 'binding_')) {
                $baseUrlEnv .=
                    " READ_ONLY_PROBE_EXPECTED_LOCAL_ROOT='dev:1:2' READ_ONLY_PROBE_EXPECTED_LOCAL_RELEASE='ea_test'";
            }
            $command =
                'PATH=' .
                escapeshellarg($path) .
                ' MOCK_CURL_SCENARIO=' .
                escapeshellarg($scenario) .
                ' MOCK_CURL_HEADER_LOG=' .
                escapeshellarg($headerLog) .
                $baseUrlEnv .
                ' ';
            $wrapperPath = __DIR__ . '/../../../scripts/ops/run_read_only_http_probe.sh';
            if ($stdoutTarget === 'closed') {
                $command .=
                    '/bin/bash -c ' . escapeshellarg('exec 1>&-; exec /bin/bash ' . escapeshellarg($wrapperPath));
            } else {
                $command .= '/bin/bash ' . escapeshellarg($wrapperPath);
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
            if (isset($od) && is_file($od)) {
                unlink($od);
            }
            if (isset($tr) && is_file($tr)) {
                unlink($tr);
            }
            if ($rm !== null && is_file($rm)) {
                unlink($rm);
            }
            if ($scenario === 'missing_rm') {
                foreach (['od', 'tr', 'awk'] as $command) {
                    $link = $directory . '/' . $command;
                    if (is_link($link)) {
                        unlink($link);
                    }
                }
            }
            if (isset($stderrFile) && is_file($stderrFile)) {
                unlink($stderrFile);
            }
            if (isset($headerLog) && is_file($headerLog)) {
                unlink($headerLog);
            }
            foreach (glob($directory . '/*') ?: [] as $leftover) {
                if (is_file($leftover) || is_link($leftover)) {
                    unlink($leftover);
                }
            }
            rmdir($directory);
        }
    }

    /** @param array<string,string> $override */
    private function classifyRequest(array $override): bool
    {
        $original = $_SERVER;
        $_SERVER = array_merge(
            [
                'REQUEST_METHOD' => 'GET',
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_HOST' => '127.0.0.1:8123',
                'REQUEST_URI' => '/appointments/ics/' . str_repeat('a', 12),
            ],
            $override,
        );

        try {
            return Read_only_probe_request::is();
        } finally {
            $_SERVER = $original;
        }
    }
}

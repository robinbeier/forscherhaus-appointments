<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/run_deep_runtime_suite.php';

class DeepRuntimeSuiteTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/deep-runtime-suite-' . uniqid('', true);

        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for deep runtime suite tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testResolveRequestedDeepRuntimeSuitesDeduplicatesAndPreservesRegistryOrder(): void
    {
        self::assertSame(
            ['api-contract-openapi', 'write-contract-booking', 'integration-smoke'],
            resolveRequestedDeepRuntimeSuites(
                'integration-smoke,write-contract-booking,api-contract-openapi,write-contract-booking',
            ),
        );
    }

    public function testResolveRequestedDeepRuntimeSuitesRejectsUnknownSuite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown deep runtime suite');

        resolveRequestedDeepRuntimeSuites('integration-smoke,unknown-suite');
    }

    public function testBuildDeepRuntimeSuiteDefinitionsUsesFixedReportPaths(): void
    {
        $config = deepRuntimeSuiteDefaultConfig();
        $config['suites_raw'] = 'write-contract-api,api-contract-openapi';
        $config['report_dir'] = $this->tmpDir;
        $config['manifest_path'] = $this->tmpDir . '/manifest.json';

        $definitions = buildDeepRuntimeSuiteDefinitions($config);

        self::assertSame('api-contract-openapi', $definitions[0]['id']);
        self::assertSame($this->tmpDir . '/api-contract-openapi.json', $definitions[0]['report_path']);
        self::assertSame($this->tmpDir . '/api-contract-openapi.log', $definitions[0]['log_path']);

        self::assertSame('write-contract-api', $definitions[1]['id']);
        self::assertSame($this->tmpDir . '/write-contract-api.json', $definitions[1]['report_path']);
        self::assertSame($this->tmpDir . '/write-contract-api.log', $definitions[1]['log_path']);
    }

    public function testIntegrationSmokeSuiteIncludesLdapGuardrailChecks(): void
    {
        $config = deepRuntimeSuiteDefaultConfig();
        $config['suites_raw'] = 'integration-smoke';
        $config['report_dir'] = $this->tmpDir;
        $config['manifest_path'] = $this->tmpDir . '/manifest.json';

        $definitions = buildDeepRuntimeSuiteDefinitions($config);
        $command = $definitions[0]['command'];

        self::assertMatchesRegularExpression("/--checks='([^']+)'/", $command);
        preg_match("/--checks='([^']+)'/", $command, $matches);

        self::assertSame(
            [
                'readiness_login_page',
                'auth_login_validate',
                'ldap_settings_search',
                'ldap_settings_search_missing_keyword',
                'ldap_sso_success',
                'ldap_sso_wrong_password',
                'dashboard_metrics',
                'booking_page_readiness',
                'booking_extract_bootstrap',
                'booking_available_hours',
                'booking_unavailable_dates',
                'api_unauthorized_guard',
                'api_appointments_index',
                'api_availabilities',
            ],
            explode(',', $matches[1]),
        );
    }

    public function testRunConfiguredDeepRuntimeSuitesContinuesAfterFailuresAndBuildsManifest(): void
    {
        $suiteDefinitions = [
            [
                'id' => 'api-contract-openapi',
                'command' => 'first-command',
                'log_path' => $this->tmpDir . '/api.log',
                'report_path' => $this->tmpDir . '/api.json',
                'failure_status' => 'contract_failure',
            ],
            [
                'id' => 'booking-controller-flows',
                'command' => 'second-command',
                'log_path' => $this->tmpDir . '/flows.log',
                'report_path' => null,
                'failure_status' => 'runtime_error',
            ],
        ];

        $manifest = runConfiguredDeepRuntimeSuites($suiteDefinitions, static function (array $suite): int {
            return $suite['id'] === 'api-contract-openapi' ? 1 : 0;
        });

        self::assertSame(['api-contract-openapi', 'booking-controller-flows'], $manifest['requested_suites']);
        self::assertSame('contract_failure', $manifest['suites']['api-contract-openapi']['status']);
        self::assertSame(1, $manifest['suites']['api-contract-openapi']['exit_code']);
        self::assertSame('pass', $manifest['suites']['booking-controller-flows']['status']);
        self::assertSame(0, $manifest['suites']['booking-controller-flows']['exit_code']);
        self::assertNotSame('', $manifest['completed_at_utc']);
    }

    public function testPrepareDeepRuntimeReportDirectoryRemovesStaleSuiteArtifacts(): void
    {
        file_put_contents($this->tmpDir . '/manifest.json', '{}');
        file_put_contents($this->tmpDir . '/api-contract-openapi.json', '{}');
        file_put_contents($this->tmpDir . '/write-contract-booking.log', 'stale');
        file_put_contents($this->tmpDir . '/keep-me.txt', 'keep');

        prepareDeepRuntimeReportDirectory($this->tmpDir);

        self::assertFileDoesNotExist($this->tmpDir . '/manifest.json');
        self::assertFileDoesNotExist($this->tmpDir . '/api-contract-openapi.json');
        self::assertFileDoesNotExist($this->tmpDir . '/write-contract-booking.log');
        self::assertFileExists($this->tmpDir . '/keep-me.txt');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/assert_deep_runtime_suite.php';

class AssertDeepRuntimeSuiteTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/assert-deep-runtime-suite-' . uniqid('', true);

        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for deep runtime suite assertion tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testAssertDeepRuntimeSuiteResultPassesForSuccessfulSuite(): void
    {
        $manifest = [
            'schema_version' => 1,
            'requested_suites' => ['integration-smoke'],
            'completed_at_utc' => '2026-03-06T00:00:00Z',
            'suites' => [
                'integration-smoke' => [
                    'status' => 'pass',
                    'exit_code' => 0,
                    'duration_seconds' => 42,
                    'report_path' => '/tmp/integration.json',
                    'log_path' => '/tmp/integration.log',
                ],
            ],
        ];

        assertDeepRuntimeSuiteResult($manifest, 'integration-smoke');
        self::addToAssertionCount(1);
    }

    public function testAssertDeepRuntimeSuiteResultFailsForNonPassStatusAndIncludesReportPath(): void
    {
        $manifest = [
            'suites' => [
                'write-contract-booking' => [
                    'status' => 'contract_failure',
                    'report_path' => '/tmp/write-contract-booking.json',
                    'log_path' => '/tmp/write-contract-booking.log',
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('/tmp/write-contract-booking.json');

        assertDeepRuntimeSuiteResult($manifest, 'write-contract-booking');
    }

    public function testRunAssertDeepRuntimeSuiteCliFailsForMissingSuite(): void
    {
        $manifestPath = $this->tmpDir . '/manifest.json';
        file_put_contents(
            $manifestPath,
            json_encode(
                [
                    'schema_version' => 1,
                    'requested_suites' => ['api-contract-openapi'],
                    'completed_at_utc' => '2026-03-06T00:00:00Z',
                    'suites' => [],
                ],
                JSON_PRETTY_PRINT,
            ),
        );

        $exitCode = runAssertDeepRuntimeSuiteCli([
            'assert_deep_runtime_suite.php',
            '--manifest=' . $manifestPath,
            '--suite=api-contract-openapi',
        ]);

        self::assertSame(ASSERT_DEEP_RUNTIME_SUITE_EXIT_FAILURE, $exitCode);
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class CiPerformanceWorkflowContractTest extends TestCase
{
    public function testBuildTestRunsTheGeneralSuiteFailClosedBeforePinnedRob444Tests(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../../.github/workflows/ci.yml');
        self::assertNotFalse($workflow);

        $start = strpos($workflow, "\n  build-test:\n");
        $end = strpos($workflow, "\n  js-lint-changed:\n", (int) $start + 1);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $job = substr($workflow, (int) $start, (int) $end - (int) $start);

        self::assertStringNotContainsString('php-actions/phpunit', $job);
        self::assertStringNotContainsString('continue-on-error:', $job);
        self::assertStringNotContainsString('|| true', $job);

        $preparePosition = strpos($job, '- name: Prepare root test configuration');
        $generalPosition = strpos($job, '- name: PHPUnit Tests');
        $rob444Position = strpos($job, '- name: ROB-444 CI baseline regression tests');
        $rob442Position = strpos($job, '- name: ROB-442 root deployment regression tests');
        self::assertNotFalse($preparePosition);
        self::assertNotFalse($generalPosition);
        self::assertNotFalse($rob444Position);
        self::assertNotFalse($rob442Position);
        self::assertLessThan($generalPosition, $preparePosition);
        self::assertLessThan($rob444Position, $generalPosition);
        self::assertLessThan($rob442Position, $rob444Position);

        $prepare = substr($job, $preparePosition, $generalPosition - $preparePosition);
        self::assertStringContainsString('test ! -e config.php', $prepare);
        self::assertStringContainsString('install -m 0600 config-sample.php config.php', $prepare);

        $general = substr($job, $generalPosition, $rob444Position - $generalPosition);
        self::assertStringContainsString('if ! APP_ENV=testing php -d memory_limit=512M vendor/bin/phpunit', $general);
        self::assertStringContainsString('--configuration phpunit.xml', $general);
        self::assertStringContainsString('--fail-on-empty-test-suite', $general);
        self::assertStringContainsString('| tee storage/logs/ci/phpunit-general.log', $general);
        self::assertStringContainsString('The general PHPUnit suite failed.', $general);
        self::assertStringContainsString('exit 1', $general);
        self::assertStringContainsString(
            "grep -Eq '^(OK \\([1-9][0-9]* tests?,|Tests: [1-9][0-9]*,)' storage/logs/ci/phpunit-general.log",
            $general,
        );

        $rob444 = substr($job, $rob444Position, $rob442Position - $rob444Position);
        self::assertStringContainsString('if ! php -d memory_limit=512M vendor/bin/phpunit', $rob444);
        self::assertStringContainsString('--no-configuration', $rob444);
        self::assertStringContainsString('--bootstrap vendor/autoload.php', $rob444);
        self::assertStringContainsString('--fail-on-empty-test-suite', $rob444);
        self::assertSame(
            [
                'tests/Unit/Scripts/CiPerformanceBaselineTest.php',
                'tests/Unit/Scripts/CiPerformanceWorkflowContractTest.php',
                'tests/Unit/Scripts/CiPathFilterMatrixTest.php',
            ],
            array_values(
                array_filter(
                    array_map(
                        static fn(string $line): string => preg_replace('/\\s+\\\\$/', '', trim($line)) ?? '',
                        explode("\n", $rob444),
                    ),
                    static fn(string $line): bool => str_starts_with($line, 'tests/Unit/Scripts/'),
                ),
            ),
        );
        self::assertStringContainsString('| tee storage/logs/ci/phpunit-rob444.log', $rob444);
        self::assertStringContainsString('The ROB-444 PHPUnit suite failed.', $rob444);
        self::assertStringContainsString('exit 1', $rob444);
        self::assertStringContainsString(
            "grep -Eq '^(OK \\([1-9][0-9]* tests?,|Tests: [1-9][0-9]*,)' storage/logs/ci/phpunit-rob444.log",
            $rob444,
        );
    }

    public function testBaselineMeasurementStaysInsideTheExistingAdvisorySignalJob(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../../.github/workflows/ci.yml');
        self::assertNotFalse($workflow);

        $start = strpos($workflow, "\n  heavy-job-duration-trends:\n");
        $end = strpos($workflow, "\n  pdf-renderer-latency:\n", (int) $start + 1);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $job = substr($workflow, (int) $start, (int) $end - (int) $start);

        self::assertStringContainsString("github.event_name == 'push'", $job);
        self::assertStringContainsString("github.ref == 'refs/heads/main'", $job);
        self::assertStringContainsString('Measure full-gate PR performance baseline', $job);
        self::assertStringContainsString('set +e', $job);
        self::assertStringContainsString('ci-performance-baseline exited with status', $job);
        self::assertStringContainsString('ci-performance-baseline-latest.json', $job);
        self::assertStringNotContainsString('continue-on-error:', $job);

        $measurementPosition = strpos($job, '- name: Measure full-gate PR performance baseline');
        $uploadPosition = strpos($job, '- name: Upload heavy job trend artifacts');
        $diagnosticsPosition = strpos($job, '- name: Diagnostics (heavy job trend report)');
        self::assertNotFalse($measurementPosition);
        self::assertNotFalse($uploadPosition);
        self::assertNotFalse($diagnosticsPosition);
        self::assertLessThan($uploadPosition, $measurementPosition);
        self::assertLessThan($diagnosticsPosition, $uploadPosition);

        $upload = substr($job, $uploadPosition, $diagnosticsPosition - $uploadPosition);
        $pathStart = strpos($upload, "          path: |\n");
        $pathEnd = strpos($upload, '          if-no-files-found:', (int) $pathStart);
        self::assertNotFalse($pathStart);
        self::assertNotFalse($pathEnd);
        $pathBlock = substr(
            $upload,
            (int) $pathStart + strlen("          path: |\n"),
            (int) $pathEnd - ((int) $pathStart + strlen("          path: |\n")),
        );
        $paths = array_values(array_filter(array_map('trim', explode("\n", $pathBlock))));
        self::assertSame(
            [
                'storage/logs/ci/heavy-job-duration-trends-latest.json',
                'storage/logs/ci/ci-performance-baseline-latest.json',
            ],
            $paths,
        );
    }
}

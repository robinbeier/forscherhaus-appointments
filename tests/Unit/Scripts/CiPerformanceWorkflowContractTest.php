<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class CiPerformanceWorkflowContractTest extends TestCase
{
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

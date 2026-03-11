<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/check_harness_report_dates.php';

class HarnessReportDateSanityTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/harness-report-date-sanity-' . uniqid('', true);
        if (!mkdir($this->tmpDir . '/docs/reports', 0777, true) && !is_dir($this->tmpDir . '/docs/reports')) {
            self::fail('Failed to create temp directory for harness report date sanity tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testDetectsFutureDatedFilename(): void
    {
        $path = $this->tmpDir . '/docs/agent-readiness-refresh-2026-03-18.md';
        file_put_contents($path, "# Agent Readiness Refresh - 2026-03-18\n");

        $evaluation = evaluateHarnessReportDateSanity(
            $this->tmpDir,
            new DateTimeImmutable('2026-03-11', new DateTimeZone('UTC')),
            0,
        );

        self::assertSame('fail', $evaluation['status']);
        self::assertNotEmpty($evaluation['violations']);
        self::assertStringContainsString(
            'Filename date 2026-03-18 exceeds allowed future window',
            $evaluation['violations'][0]['message'],
        );
    }

    public function testDetectsHeaderDateMismatchAgainstFilename(): void
    {
        $path = $this->tmpDir . '/docs/reports/doc-review-2026-03-09.md';
        file_put_contents($path, "# Documentation Drift Review - 2026-03-10\n");

        $evaluation = evaluateHarnessReportDateSanity(
            $this->tmpDir,
            new DateTimeImmutable('2026-03-11', new DateTimeZone('UTC')),
            0,
        );

        self::assertSame('fail', $evaluation['status']);
        self::assertStringContainsString('does not match filename date', $evaluation['violations'][0]['message']);
    }

    public function testPassesForPlausibleCurrentReports(): void
    {
        $path = $this->tmpDir . '/docs/reports/doc-review-2026-03-09.md';
        file_put_contents($path, "# Documentation Drift Review - 2026-03-09\n\nBody.\n");

        $evaluation = evaluateHarnessReportDateSanity(
            $this->tmpDir,
            new DateTimeImmutable('2026-03-11', new DateTimeZone('UTC')),
            0,
        );

        self::assertSame('pass', $evaluation['status']);
        self::assertSame([], $evaluation['violations']);
    }

    public function testFailsWhenNoDatedReportsArePresent(): void
    {
        $evaluation = evaluateHarnessReportDateSanity(
            $this->tmpDir,
            new DateTimeImmutable('2026-03-11', new DateTimeZone('UTC')),
            0,
        );

        self::assertSame('fail', $evaluation['status']);
        self::assertStringContainsString(
            'No dated readiness or audit reports were found',
            $evaluation['violations'][0]['message'],
        );
    }

    public function testRunHarnessReportDateSanityCliFailsForUnknownOption(): void
    {
        $outputFile = $this->tmpDir . '/report.json';

        $exitCode = runHarnessReportDateSanityCli([
            'check_harness_report_dates.php',
            '--output-json=' . $outputFile,
            '--bogus',
        ]);

        $report = $this->readReport($outputFile);

        self::assertSame(HARNESS_REPORT_DATE_SANITY_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertSame('error', $report['status']);
        self::assertStringContainsString('Unknown CLI option', (string) $report['error']['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($directory);
    }
}

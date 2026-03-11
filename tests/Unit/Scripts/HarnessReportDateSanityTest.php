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

    public function testAcceptsAbsolutePathOutsideRepoRoot(): void
    {
        $externalDir = sys_get_temp_dir() . '/harness-report-date-sanity-external-' . uniqid('', true);
        self::assertTrue(mkdir($externalDir, 0777, true) || is_dir($externalDir));

        try {
            $externalPath = $externalDir . '/doc-review-2026-03-09.md';
            file_put_contents($externalPath, "# Documentation Drift Review - 2026-03-09\n");

            $evaluation = evaluateHarnessReportDateSanity(
                $this->tmpDir,
                new DateTimeImmutable('2026-03-11', new DateTimeZone('UTC')),
                0,
                [$externalPath],
            );

            self::assertSame('pass', $evaluation['status']);
            self::assertSame($externalPath, $evaluation['files'][0]['file']);
        } finally {
            $this->removeDirectory($externalDir);
        }
    }

    public function testRecordsMalformedOpeningDatesAsViolationsWithoutCrashing(): void
    {
        $invalidPath = $this->tmpDir . '/docs/reports/doc-review-2026-03-09.md';
        $validPath = $this->tmpDir . '/docs/reports/doc-review-2026-03-10.md';
        file_put_contents($invalidPath, "# Documentation Drift Review - 2026-02-31\n");
        file_put_contents($validPath, "# Documentation Drift Review - 2026-03-10\n");

        $evaluation = evaluateHarnessReportDateSanity(
            $this->tmpDir,
            new DateTimeImmutable('2026-03-11', new DateTimeZone('UTC')),
            0,
        );

        self::assertSame('fail', $evaluation['status']);
        self::assertCount(2, $evaluation['files']);
        self::assertSame('opening_date_invalid', $evaluation['violations'][0]['source']);
        self::assertStringContainsString(
            'opening date must be a valid ISO date',
            $evaluation['violations'][0]['message'],
        );
        self::assertSame('pass', $evaluation['files'][1]['status']);
    }

    public function testRejectsUndatedReportFilenames(): void
    {
        $path = $this->tmpDir . '/docs/reports/doc-review-latest.md';
        file_put_contents($path, "# Documentation Drift Review\n");

        $evaluation = evaluateHarnessReportDateSanity(
            $this->tmpDir,
            new DateTimeImmutable('2026-03-11', new DateTimeZone('UTC')),
            0,
            [$path],
        );

        self::assertSame('fail', $evaluation['status']);
        self::assertSame('filename_date_missing', $evaluation['violations'][0]['source']);
        self::assertStringContainsString(
            'must include a YYYY-MM-DD date token',
            $evaluation['violations'][0]['message'],
        );
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

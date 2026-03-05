<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseReport;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseReport.php';

class ZeroSurpriseReportTest extends TestCase
{
    public function testDetermineExitCodeReturnsSuccessWhenAllChecksPass(): void
    {
        $report = $this->createReport();

        $report->addStep('restore_dump', ZeroSurpriseReport::STATUS_PASS, 0, 100.25);
        $report->addStep('booking_write_replay', ZeroSurpriseReport::STATUS_PASS, 0, 200.5);
        $report->addInvariant('unexpected_5xx', ZeroSurpriseReport::STATUS_PASS, ['count' => 0]);
        $report->addInvariant('overbooking', ZeroSurpriseReport::STATUS_PASS, ['slot_appointments_count' => 1]);

        $this->assertSame(0, $report->determineExitCode());

        $data = $report->toArray();
        $this->assertSame(2, $data['summary']['passed_steps']);
        $this->assertSame(0, $data['summary']['failed_steps']);
        $this->assertSame(2, $data['summary']['passed_invariants']);
        $this->assertSame(0, $data['summary']['failed_invariants']);
        $this->assertSame(0, $data['summary']['exit_code']);
    }

    public function testDetermineExitCodeReturnsAssertionFailureWhenInvariantFails(): void
    {
        $report = $this->createReport();

        $report->addStep('restore_dump', ZeroSurpriseReport::STATUS_PASS, 0, 100.0);
        $report->addStep('dashboard_replay', ZeroSurpriseReport::STATUS_PASS, 0, 200.0);
        $report->addInvariant('pdf_exports', ZeroSurpriseReport::STATUS_FAIL, ['principal_pdf' => 'fail']);

        $this->assertSame(1, $report->determineExitCode());
    }

    public function testDetermineExitCodeReturnsRuntimeFailureWhenStepFailsWithRuntimeExitCode(): void
    {
        $report = $this->createReport();

        $report->addStep('restore_dump', ZeroSurpriseReport::STATUS_FAIL, 2, 100.0, ['error' => 'dump import failed']);
        $report->addInvariant('unexpected_5xx', ZeroSurpriseReport::STATUS_PASS, ['count' => 0]);

        $this->assertSame(2, $report->determineExitCode());
    }

    public function testDetermineExitCodeReturnsRuntimeFailureWhenFailureIsRecorded(): void
    {
        $report = $this->createReport();

        $report->addStep('restore_dump', ZeroSurpriseReport::STATUS_PASS, 0, 10.0);
        $report->addInvariant('unexpected_5xx', ZeroSurpriseReport::STATUS_PASS, ['count' => 0]);
        $report->setFailure('cleanup failed', \RuntimeException::class, 'runtime_error');

        $this->assertSame(2, $report->determineExitCode());
    }

    public function testWritePersistsReportJsonToDisk(): void
    {
        $outputPath = sys_get_temp_dir() . '/zero-surprise-report-test-' . bin2hex(random_bytes(4)) . '.json';
        $report = new ZeroSurpriseReport(
            'ea_20260305_1200',
            'zs-ea_20260305_1200-20260305110000-a1b2',
            [
                'dump_file' => '/tmp/dump.sql.gz',
                'base_url' => 'http://nginx',
            ],
            $outputPath,
        );

        $report->addStep('restore_dump', ZeroSurpriseReport::STATUS_PASS, 0, 50.0);
        $report->addInvariant('fill_rate_math', ZeroSurpriseReport::STATUS_PASS, [
            'source_check' => 'dashboard_metrics',
        ]);

        $writtenPath = $report->write();

        $this->assertSame($outputPath, $writtenPath);
        $this->assertFileExists($outputPath);

        $raw = file_get_contents($outputPath);
        $this->assertIsString($raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ea_20260305_1200', $decoded['meta']['release_id']);
        $this->assertSame('predeploy', $decoded['meta']['mode']);
        $this->assertSame('pass', $decoded['invariants']['fill_rate_math']['status']);

        @unlink($outputPath);
    }

    private function createReport(): ZeroSurpriseReport
    {
        $outputPath = sys_get_temp_dir() . '/zero-surprise-report-' . bin2hex(random_bytes(4)) . '.json';

        return new ZeroSurpriseReport(
            'ea_20260305_1200',
            'zs-ea_20260305_1200-20260305110000-a1b2',
            [
                'dump_file' => '/tmp/dump.sql.gz',
                'base_url' => 'http://nginx',
                'index_page' => 'index.php',
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
            ],
            $outputPath,
        );
    }
}

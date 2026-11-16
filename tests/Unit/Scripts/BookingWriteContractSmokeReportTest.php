<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BookingWriteContractSmokeReportTest extends TestCase
{
    public function testSmokeReportUsesPrivateMode(): void
    {
        $directory = $this->makeTemporaryDirectory('booking-write-report-');
        $path = $directory . '/report.json';

        try {
            [$exitCode, $stdout, $stderr] = $this->runSmoke($path);

            self::assertSame(2, $exitCode, $stdout . $stderr);
            self::assertFileExists($path);
            self::assertSame(0600, fileperms($path) & 0777);
            $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertNotSame('', $report['failure']['classification'] ?? '');
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testSmokeReportRejectsPreexistingSymlink(): void
    {
        $directory = $this->makeTemporaryDirectory('booking-write-report-link-');
        $target = $directory . '/target.json';
        $path = $directory . '/report.json';
        file_put_contents($target, 'sentinel');
        symlink($target, $path);

        try {
            [$exitCode, $stdout, $stderr] = $this->runSmoke($path);

            self::assertSame(2, $exitCode, $stdout . $stderr);
            self::assertStringContainsString('Failed to write booking write contract report', $stderr);
            self::assertSame('sentinel', file_get_contents($target));
        } finally {
            @unlink($path);
            @unlink($target);
            @rmdir($directory);
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runSmoke(string $outputPath): array
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 3) . '/scripts/ci/booking_write_contract_smoke.php',
            '--base-url=http://127.0.0.1:1',
            '--index-page=index.php',
            '--username=fixture-user',
            '--password=fixture-password',
            '--http-timeout=1',
            '--retry-count=0',
            '--checks=booking_register_success_contract',
            '--output-json=' . $outputPath,
        ];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    private function makeTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($directory, 0700, true));

        return $directory;
    }
}

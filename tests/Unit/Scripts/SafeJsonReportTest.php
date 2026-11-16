<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\SafeJsonReport;

require_once __DIR__ . '/../../../scripts/release-gate/lib/SafeJsonReport.php';

final class SafeJsonReportTest extends TestCase
{
    public function testReportIsCreatedWithPrivateMode(): void
    {
        $directory = sys_get_temp_dir() . '/safe-json-report-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $path = $directory . '/report.json';

        try {
            SafeJsonReport::write($path, ['status' => 'pass']);
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(['status' => 'pass'], json_decode((string) file_get_contents($path), true));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testPreexistingSymlinkIsRejectedWithoutTouchingTarget(): void
    {
        $directory = sys_get_temp_dir() . '/safe-json-report-link-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $target = $directory . '/target.json';
        $path = $directory . '/report.json';
        file_put_contents($target, 'sentinel');
        symlink($target, $path);

        try {
            $this->expectException(\RuntimeException::class);
            SafeJsonReport::write($path, ['status' => 'fail']);
        } finally {
            self::assertSame('sentinel', file_get_contents($target));
            @unlink($path);
            @unlink($target);
            @rmdir($directory);
        }
    }
}

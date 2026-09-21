<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    public function testPostControllerHookReadsMagicOutputPropertyAndEmitsReportOnlyHeader(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/csp_hook_probe.php';
        $process = proc_open(
            [PHP_BINARY, $fixture, dirname(__DIR__, 3)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );

        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr);
        $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(["Content-Security-Policy-Report-Only: default-src 'self'"], $result['headers']);
    }
}

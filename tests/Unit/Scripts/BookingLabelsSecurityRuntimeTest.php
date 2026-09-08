<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BookingLabelsSecurityRuntimeTest extends TestCase
{
    public function testJavaScriptRuntimeContract(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);

        $process = proc_open(
            ['node', '--test', 'tests/JavaScript/booking_labels_security.test.js'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repositoryRoot,
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, trim($stdout . PHP_EOL . $stderr));
        self::assertMatchesRegularExpression('/(?:#|ℹ) tests [1-9]\d*/', $stdout);
        self::assertMatchesRegularExpression('/(?:#|ℹ) fail 0/', $stdout);
    }
}

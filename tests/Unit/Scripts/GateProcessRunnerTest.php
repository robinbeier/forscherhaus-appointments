<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateProcessRunner;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateProcessRunner.php';

final class GateProcessRunnerTest extends TestCase
{
    public function testSendsExactStdinPayloadAndClosesItWithEof(): void
    {
        $payload = "synthetic\0stdin\nbytes";
        $result = GateProcessRunner::run(
            [PHP_BINARY, '-r', 'echo base64_encode(stream_get_contents(STDIN));'],
            null,
            null,
            5,
            $payload,
        );

        self::assertSame(0, $result['exit_code']);
        self::assertSame(base64_encode($payload), $result['stdout']);
        self::assertFalse($result['timed_out']);
    }

    public function testNoInputPreservesExistingBehavior(): void
    {
        $result = GateProcessRunner::run(
            [PHP_BINARY, '-r', 'echo stream_get_contents(STDIN) === "" ? "no-input" : "unexpected";'],
            null,
            null,
            5,
        );

        self::assertSame(0, $result['exit_code']);
        self::assertSame('no-input', $result['stdout']);
        self::assertFalse($result['timed_out']);
    }

    public function testTimeoutRemainsBoundedWhenChildDoesNotConsumeInput(): void
    {
        $startedAt = microtime(true);
        $result = GateProcessRunner::run(
            [PHP_BINARY, '-r', 'sleep(3);'],
            null,
            null,
            1,
            str_repeat('x', 2 * 1024 * 1024),
        );

        self::assertTrue($result['timed_out']);
        self::assertSame(124, $result['exit_code']);
        self::assertLessThan(2.5, microtime(true) - $startedAt);
    }

    public function testChildClosingStdinEarlyDoesNotSpinUntilTimeout(): void
    {
        $result = GateProcessRunner::run(
            [PHP_BINARY, '-r', 'fclose(STDIN); echo "closed";'],
            null,
            null,
            5,
            str_repeat('x', 2 * 1024 * 1024),
        );

        self::assertSame(0, $result['exit_code']);
        self::assertSame('closed', $result['stdout']);
        self::assertFalse($result['timed_out']);
    }

    public function testCommandResultDoesNotContainStdinSecret(): void
    {
        $secret = 'synthetic-secret-that-must-not-leak';
        $result = GateProcessRunner::run(
            [PHP_BINARY, '-r', 'stream_get_contents(STDIN); echo "ok";'],
            null,
            null,
            5,
            $secret,
        );

        self::assertSame('ok', $result['stdout']);
        self::assertStringNotContainsString($secret, $result['command']);
        self::assertStringNotContainsString($secret, $result['stderr']);
    }
}

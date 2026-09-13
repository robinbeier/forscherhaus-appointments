<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class PythonGateDiffScopeTest extends TestCase
{
    public function testPythonGateReadersKeepTheWholePullRequestScope(): void
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            ['python3', '-B', $root . '/tests/Fixtures/python_gate_diff_scope.py', $root],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertStringContainsString('Ran 3 tests', $stderr);
        self::assertStringContainsString('OK', $stderr);
    }
}

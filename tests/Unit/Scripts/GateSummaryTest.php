<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class GateSummaryTest extends TestCase
{
    public function testPythonDiagnosticRegressionsPass(): void
    {
        $command =
            'python3 -B -m unittest discover -s ' .
            escapeshellarg(__DIR__ . '/../../Python') .
            ' -p ' .
            escapeshellarg('test_gate_*.py') .
            ' 2>&1';
        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('OK', implode("\n", $output));
    }
}

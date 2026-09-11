<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class FocusedTestLifecycleTest extends TestCase
{
    public function testPythonFocusedLifecycleRegressionSuitePasses(): void
    {
        $command =
            'python3 -B -m unittest discover -s ' .
            escapeshellarg(__DIR__ . '/../../Python') .
            ' -p ' .
            escapeshellarg('test_focused_test_lifecycle.py') .
            ' 2>&1';
        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('OK', implode("\n", $output));
    }
}

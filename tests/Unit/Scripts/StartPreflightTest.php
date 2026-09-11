<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class StartPreflightTest extends TestCase
{
    public function testPythonRegressionSuitePasses(): void
    {
        $script = __DIR__ . '/../../../scripts/ci/start_preflight.py';
        $test = __DIR__ . '/../../Python';
        $command =
            'python3 -B -m unittest discover -s ' .
            escapeshellarg($test) .
            ' -p ' .
            escapeshellarg('test_start_preflight.py');
        exec($command . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertFileExists($script);
    }
}

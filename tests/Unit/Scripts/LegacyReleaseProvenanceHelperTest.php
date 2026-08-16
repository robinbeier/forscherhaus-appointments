<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class LegacyReleaseProvenanceHelperTest extends TestCase
{
    public function testOfflineHelperIntegrationMatrix(): void
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            ['python3', $root . '/tests/Unit/Scripts/legacy_release_provenance_v1_test.py'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $root,
            array_merge($_ENV, ['PYTHONDONTWRITEBYTECODE' => '1']),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), ($stdout ?: '') . ($stderr ?: ''));
    }
}

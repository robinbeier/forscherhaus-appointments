<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/ordinary_probe_phpstan_bootstrap.php';

final class OrdinaryProbeEntrypointContractTest extends TestCase
{
    public function testActualEntrypointImportsHaveRuntimeLibraries(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/ops/ordinary_live_probe.php');
        self::assertIsString($source);

        $files = ordinary_probe_required_libraries($source, __DIR__ . '/../../../scripts/release-gate/lib');

        self::assertCount(15, $files);
    }

    public function testMissingRuntimeRequireIsRejected(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/ops/ordinary_live_probe.php');
        self::assertIsString($source);
        $mutated = str_replace(
            "    require_once dirname(__DIR__) . '/release-gate/lib/OrdinaryAccountProbe.php';\n",
            '',
            $source,
        );
        self::assertNotSame($source, $mutated);

        $this->expectException(RuntimeException::class);
        ordinary_probe_required_libraries($mutated, __DIR__ . '/../../../scripts/release-gate/lib');
    }
}

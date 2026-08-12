<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\HostRunnerBuildHelper;
use Ops\ProtectedHostBuildCollector;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerBuildV1.php';

final class DeploymentHostRunnerBuildV1Test extends TestCase
{
    private const RELEASE_ID = 'ea_20260812';
    private const AUTHORIZED_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCollectorReturnsOneAuthorityBoundToExactProtectedMeasurements(): void
    {
        $provenance = "{\"schema\":\"release_build_provenance.v1\"}\n";
        $helper = new BuildHelperFake([
            'archive_sha256' => str_repeat('b', 64),
            'archive_size_bytes' => 12_345,
            'artifact_deploy_script_sha256' => str_repeat('c', 64),
            'host_deploy_script_sha256' => str_repeat('d', 64),
            'provenance_sha256' => self::AUTHORIZED_SHA,
            'stage_file_count' => 101,
            'stage_inode_count' => 121,
            'stage_unpacked_bytes' => 45_678,
            'temp_scratch_bytes' => 67_108_864,
            'provenance_bytes' => $provenance,
        ]);

        $authority = (new ProtectedHostBuildCollector($helper))->collect(self::RELEASE_ID, self::AUTHORIZED_SHA);

        self::assertSame([[self::RELEASE_ID, self::AUTHORIZED_SHA]], $helper->calls);
        self::assertSame($provenance, $authority->expectedCommit->provenanceBytes);
        self::assertSame(self::AUTHORIZED_SHA, $authority->expectedCommit->pinnedProvenanceSha256);
        self::assertSame($provenance, $authority->verifiedSources->provenanceBytes);
        self::assertSame(self::RELEASE_ID, $authority->verifiedSources->releaseId);
        self::assertSame(str_repeat('b', 64), $authority->verifiedSources->archiveSha256);
        self::assertSame(12_345, $authority->verifiedSources->archiveSizeBytes);
        self::assertSame(str_repeat('d', 64), $authority->verifiedSources->hostDeployScriptSha256);
        self::assertSame(str_repeat('c', 64), $authority->verifiedSources->artifactDeployScriptSha256);
        self::assertSame(101, $authority->verifiedSources->stageFileCount);
        self::assertSame(121, $authority->verifiedSources->stageInodeCount);
        self::assertSame(45_678, $authority->verifiedSources->stageUnpackedBytes);
        self::assertSame(67_108_864, $authority->verifiedSources->tempScratchBytes);
    }
}

final class BuildHelperFake implements HostRunnerBuildHelper
{
    /** @var list<array{string,string}> */
    public array $calls = [];

    /** @param array<string,int|string> $value */
    public function __construct(private readonly array $value) {}

    public function observe(string $releaseId, string $authorizedSha256): array
    {
        $this->calls[] = [$releaseId, $authorizedSha256];
        return $this->value;
    }
}

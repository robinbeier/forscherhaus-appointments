<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\BuildVerifiedSourcesV1;
use Ops\DeploymentEvidenceAuthorityV1;
use Ops\DumpObservationV1;
use Ops\HostRunnerCapacityHelper;
use Ops\ProtectedHostCapacityCollector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerCapacityV1.php';

final class DeploymentHostRunnerCapacityV1Test extends TestCase
{
    private const RUN_ID = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const INTENT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const RELEASE = 'ea_20260812';
    private const COMMIT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DUMP_SHA = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function testCollectorUsesLogicalLiveStorageWhenItExceedsAllocatedBytes(): void
    {
        [$build, $dump] = $this->authorities();
        $helper = new CapacityHelperFake($this->rawCapacity(100, 10_000));

        $result = (new ProtectedHostCapacityCollector($helper))->collect(
            self::RUN_ID, self::INTENT, self::RELEASE, self::COMMIT, 'external', $build, $dump,
        );

        self::assertSame([[self::RUN_ID, self::RELEASE, 'external']], $helper->calls);
        self::assertNotNull($result->verifiedSources);
        self::assertSame(100, $result->verifiedSources->liveStorageAllocatedBytes);
        self::assertSame(10_000, $result->verifiedSources->liveStorageLogicalBytes);
        $derived = $this->derive($result->verifiedSources);
        self::assertSame(31_000 + 536_870_912, $derived['projected_required_bytes']);
        self::assertSame(18, $derived['stage_inode_count']);
        self::assertSame(7, $derived['restore_inode_count']);
    }

    public function testCollectorUsesAllocatedLiveStorageWhenItExceedsLogicalBytes(): void
    {
        [$build, $dump] = $this->authorities();
        $result = (new ProtectedHostCapacityCollector(new CapacityHelperFake($this->rawCapacity(20_000, 10_000))))
            ->collect(self::RUN_ID, self::INTENT, self::RELEASE, self::COMMIT, 'external', $build, $dump);

        self::assertNotNull($result->verifiedSources);
        self::assertSame(41_000 + 536_870_912, $this->derive($result->verifiedSources)['projected_required_bytes']);
    }

    public function testIncompleteDumpAndHelperFailureBecomeInvalidWithoutInventedMeasurements(): void
    {
        [$build] = $this->authorities();
        $helper = new CapacityHelperFake($this->rawCapacity(100, 10_000));
        $missing = new DumpObservationV1(null, null, null, null, null, self::DUMP_SHA, true, null, null);

        $result = (new ProtectedHostCapacityCollector($helper))->collect(
            self::RUN_ID, self::INTENT, self::RELEASE, self::COMMIT, 'external', $build, $missing,
        );
        self::assertNull($result->verifiedSources);
        self::assertNull($result->projectedRequiredBytes);
        self::assertSame([], $helper->calls);

        [, $completeDump] = $this->authorities();
        $failed = (new ProtectedHostCapacityCollector(new CapacityHelperFake(null)))
            ->collect(self::RUN_ID, self::INTENT, self::RELEASE, self::COMMIT, 'external', $build, $completeDump);
        self::assertNull($failed->verifiedSources);
        self::assertNull($failed->availableBytes);
    }

    /** @return array{BuildVerifiedSourcesV1,DumpObservationV1} */
    private function authorities(): array
    {
        $archiveSha = str_repeat('d', 64);
        $scriptSha = str_repeat('e', 64);
        $provenance = DeploymentEvidenceAuthorityV1::encodeFile([
            'schema' => DeploymentEvidenceAuthorityV1::BUILD_PROVENANCE_SCHEMA,
            'release_id' => self::RELEASE,
            'expected_commit' => self::COMMIT,
            'observed_commit' => self::COMMIT,
            'archive' => ['name' => self::RELEASE . '.tar.gz', 'size_bytes' => 1_000, 'sha256' => $archiveSha],
            'capacity_bounds' => [
                'stage_file_count' => 7, 'stage_inode_count' => 10,
                'stage_unpacked_bytes' => 2_000, 'temp_scratch_bytes' => 4_000,
            ],
            'source' => [
                'build_script_sha256' => str_repeat('f', 64),
                'composer_lock_sha256' => str_repeat('1', 64),
                'package_lock_sha256' => str_repeat('2', 64),
                'deploy_ea_sha256' => $scriptSha,
            ],
        ]);
        $build = new BuildVerifiedSourcesV1(
            $provenance, hash('sha256', $provenance), self::RELEASE, $archiveSha, 1_000,
            $scriptSha, $scriptSha, 7, 10, 2_000, 4_000,
        );
        $attestation = DeploymentEvidenceAuthorityV1::encodeFile([
            'schema' => DeploymentEvidenceAuthorityV1::DUMP_ATTESTATION_SCHEMA,
            'dump' => [
                'sha256' => self::DUMP_SHA, 'size_bytes' => 3_000, 'uncompressed_size_bytes' => 5_000,
                'created_at_utc' => '2026-08-12T11:30:00Z',
            ],
            'verification' => [
                'method' => 'mariadb_10_11_isolated_restore_v1', 'sha256_verified' => true,
                'gzip_verified' => true, 'restore_verified' => true,
                'restored_datadir_allocated_bytes' => 6_000, 'restored_datadir_inode_count' => 7,
                'restored_at_utc' => '2026-08-12T11:50:00Z',
            ],
            'attested_at_utc' => '2026-08-12T11:55:00Z',
        ]);
        $dump = new DumpObservationV1(
            $attestation, hash('sha256', $attestation), 3_000, '2026-08-12T12:00:00Z',
            1_800, self::DUMP_SHA, true, true, true,
        );
        return [$build, $dump];
    }

    /** @return array<string,mixed> */
    private function rawCapacity(int $allocated, int $logical): array
    {
        $policy = DeploymentEvidenceAuthorityV1::encodeFile([
            'schema' => DeploymentEvidenceAuthorityV1::RENDERER_CAPACITY_POLICY_SCHEMA,
            'external' => ['bytes' => 0, 'inodes' => 0],
            'host' => ['bytes' => 1_000_000, 'inodes' => 1_000],
        ]);
        $devices = array_fill_keys([
            'artifact', 'dump_pin', 'live_storage', 'release_root', 'renderer_state',
            'restore_scratch', 'stage', 'state_root', 'temp',
        ], 7);
        return [
            'block_size' => 4096, 'blocks' => 1_000_000, 'blocks_available' => 900_000,
            'component_devices' => $devices, 'filesystem_device' => 7,
            'inodes' => 1_000_000, 'inodes_available' => 900_000,
            'live_storage_allocated_bytes' => $allocated, 'live_storage_inode_count' => 8,
            'live_storage_logical_bytes' => $logical, 'policy_bytes' => $policy,
        ];
    }

    /** @return array<string,mixed> */
    private function derive(\Ops\CapacityVerifiedSourcesV1 $sources): array
    {
        $build = $sources->build;
        return DeploymentEvidenceAuthorityV1::verifyAndDeriveCapacityEvidence(
            $sources->filesystemDevice, $sources->blockSize, $sources->blocks, $sources->blocksAvailable,
            $sources->inodes, $sources->inodesAvailable,
            $build->provenanceBytes, $build->authorizedProvenanceSha256, self::RELEASE, self::COMMIT,
            $build->stageFileCount, $build->stageInodeCount, $build->stageUnpackedBytes, $build->tempScratchBytes,
            $sources->attestationBytes, $sources->attestationSha256, self::RUN_ID, self::INTENT,
            $sources->dumpSha256, $sources->dumpSizeBytes, $sources->observedAtUtc,
            $sources->liveStorageAllocatedBytes, $sources->liveStorageLogicalBytes,
            $sources->liveStorageInodeCount, $sources->rendererInstallBytes,
            $sources->rendererInstallInodeCount, $sources->componentDevices,
        );
    }
}

final class CapacityHelperFake implements HostRunnerCapacityHelper
{
    /** @var list<array{string,string,string}> */
    public array $calls = [];

    /** @param ?array<string,mixed> $value */
    public function __construct(private readonly ?array $value) {}

    public function observe(string $runId, string $releaseId, string $rendererMode): array
    {
        $this->calls[] = [$runId, $releaseId, $rendererMode];
        return $this->value ?? throw new RuntimeException('unavailable');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentEvidenceAuthorityV1;
use Ops\DeploymentHostRunnerContractV1;
use Ops\HostRunnerBuildHelper;
use Ops\HostRunnerCapacityHelper;
use Ops\HostRunnerDumpHelper;
use Ops\HostRunnerStorage;
use Ops\HostRunnerTrafficHelper;
use Ops\HostRunnerTrafficMetadata;
use Ops\ProtectedHostBuildCollector;
use Ops\ProtectedHostCapacityCollector;
use Ops\ProtectedHostDumpCollector;
use Ops\ProtectedHostPredeployObservationProvider;
use Ops\ProtectedHostTrafficCollector;
use Ops\SystemHostRunnerProtectedObservationSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerProtectedSourceV1.php';

final class DeploymentHostRunnerProtectedSourceV1Test extends TestCase
{
    public function testConcreteSourceProducesOnePassedFiveGateAssemblyFromProtectedCollectors(): void
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $archiveSha = str_repeat('d', 64);
        $scriptSha = str_repeat('e', 64);
        $dumpSha = $input['parameters']['zero_surprise_dump']['sha256'];
        $provenance = DeploymentEvidenceAuthorityV1::encodeFile([
            'schema' => DeploymentEvidenceAuthorityV1::BUILD_PROVENANCE_SCHEMA,
            'release_id' => $request['release_id'],
            'expected_commit' => $request['expected_commit'],
            'observed_commit' => $request['expected_commit'],
            'archive' => ['name' => $request['release_id'] . '.tar.gz', 'size_bytes' => 1_000, 'sha256' => $archiveSha],
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
        $input['parameters']['artifact_provenance_sha256'] = hash('sha256', $provenance);
        $attestation = DeploymentEvidenceAuthorityV1::encodeFile([
            'schema' => DeploymentEvidenceAuthorityV1::DUMP_ATTESTATION_SCHEMA,
            'dump' => [
                'sha256' => $dumpSha, 'size_bytes' => 3_000, 'uncompressed_size_bytes' => 5_000,
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
        $storage = new SourceStorageFake();
        $buildHelper = new SourceBuildHelperFake([
            'archive_sha256' => $archiveSha, 'archive_size_bytes' => 1_000,
            'artifact_deploy_script_sha256' => $scriptSha, 'host_deploy_script_sha256' => $scriptSha,
            'provenance_sha256' => hash('sha256', $provenance), 'stage_file_count' => 7,
            'stage_inode_count' => 10, 'stage_unpacked_bytes' => 2_000, 'temp_scratch_bytes' => 4_000,
            'provenance_bytes' => $provenance,
        ]);
        $trafficHelper = new SourceTrafficHelperFake($this->trafficReport($request['traffic_mode']));
        $trafficMetadata = new SourceTrafficMetadataFake();
        $dumpHelper = new SourceDumpHelperFake([
            'status' => 'observed', 'attestation_bytes' => $attestation,
            'attestation_sha256' => hash('sha256', $attestation), 'dump_sha256' => $dumpSha,
            'dump_size_bytes' => 3_000, 'observed_at_utc' => '2026-08-12T12:00:00Z',
        ]);
        $capacityHelper = new SourceCapacityHelperFake($this->capacityRaw());
        $source = new SystemHostRunnerProtectedObservationSource(
            $request['expected_commit'],
            $storage,
            new ProtectedHostBuildCollector($buildHelper),
            new ProtectedHostTrafficCollector($trafficHelper, $trafficMetadata),
            new ProtectedHostDumpCollector($storage, $dumpHelper),
            new ProtectedHostCapacityCollector($capacityHelper),
        );
        $provider = new ProtectedHostPredeployObservationProvider($source, $request, $input);

        $assembly = DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $provider,
            $request['run_id'],
            $request['intent_sha256'],
            $request['release_id'],
            $request['expected_commit'],
            $request['traffic_mode'],
        );

        self::assertSame('passed', $assembly['status']);
        self::assertSame(0, $assembly['exit_code']);
        self::assertSame([[$request['release_id'], hash('sha256', $provenance)]], $buildHelper->calls);
        self::assertSame([[$request['run_id'], $request['traffic_mode']]], $trafficHelper->calls);
        self::assertSame([[$request['run_id'], 'deploy-ref-zero-surprise-dump.sql.gz', $dumpSha]], $dumpHelper->calls);
        self::assertSame([[$request['run_id'], $request['release_id'], 'host']], $capacityHelper->calls);
        self::assertSame(1, $storage->pinCount);
        self::assertSame('passed', $assembly['sections']['capacity']['status']);
        self::assertSame('passed', $assembly['sections']['artifact']['status']);
    }

    private function trafficReport(string $mode): string
    {
        $counts = array_fill_keys([
            'documented_health', 'documented_periodic_ops', 'public_read', 'denied_external',
            'business_or_authenticated', 'unclassified', 'status_5xx', 'write', 'authenticated',
            'customers_or_sensitive', 'scanner_success', 'source_unknown', 'method_unknown',
            'target_unknown', 'pre_window_completion', 'lines_seen', 'lines_in_window',
            'parse_errors', 'rotation_errors', 'total',
        ], 0);
        $counts['documented_health'] = 1;
        $counts['lines_seen'] = $counts['lines_in_window'] = $counts['total'] = 1;
        return json_encode([
            'schema' => 'traffic_gate.v1', 'producer_sha256' => str_repeat('9', 64),
            'policy_version' => 'traffic_gate_policy.v1', 'catalog_version' => '2026-08-09.1',
            'purpose' => 'deploy', 'mode' => $mode, 'window_start_epoch' => 100,
            'window_end_epoch' => 190, 'window_seconds' => 90, 'log_set_sha256' => str_repeat('8', 64),
            'rotation_complete' => true, 'parse_complete' => true, 'evidence_complete' => true,
            'decision' => 'allow', 'exit_code' => 0, 'counts' => $counts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string,mixed> */
    private function capacityRaw(): array
    {
        $devices = array_fill_keys([
            'artifact', 'dump_pin', 'live_storage', 'release_root', 'renderer_state',
            'restore_scratch', 'stage', 'state_root', 'temp',
        ], 7);
        $policy = DeploymentEvidenceAuthorityV1::encodeFile([
            'schema' => DeploymentEvidenceAuthorityV1::RENDERER_CAPACITY_POLICY_SCHEMA,
            'external' => ['bytes' => 0, 'inodes' => 0],
            'host' => ['bytes' => 1_000_000, 'inodes' => 1_000],
        ]);
        return [
            'block_size' => 4096, 'blocks' => 1_000_000, 'blocks_available' => 900_000,
            'component_devices' => $devices, 'filesystem_device' => 7,
            'inodes' => 1_000_000, 'inodes_available' => 900_000,
            'live_storage_allocated_bytes' => 10_000, 'live_storage_inode_count' => 8,
            'live_storage_logical_bytes' => 20_000, 'policy_bytes' => $policy,
        ];
    }
}

final class SourceBuildHelperFake implements HostRunnerBuildHelper
{
    public array $calls = [];
    public function __construct(private readonly array $value) {}
    public function observe(string $releaseId, string $authorizedSha256): array
    { $this->calls[] = [$releaseId, $authorizedSha256]; return $this->value; }
}

final class SourceTrafficHelperFake implements HostRunnerTrafficHelper
{
    public array $calls = [];
    public function __construct(private readonly string $bytes) {}
    public function collect(string $runId, string $mode): array
    {
        $this->calls[] = [$runId, $mode];
        return ['status' => 'pinned', 'bytes' => $this->bytes, 'sha256' => hash('sha256', $this->bytes), 'started_epoch' => 99, 'finished_epoch' => 191];
    }
}

final class SourceTrafficMetadataFake implements HostRunnerTrafficMetadata
{
    public function current(): array
    { return ['producer_sha256' => str_repeat('9', 64), 'catalog_version' => '2026-08-09.1']; }
}

final class SourceDumpHelperFake implements HostRunnerDumpHelper
{
    public array $calls = [];
    public function __construct(private readonly array $value) {}
    public function observe(string $runId, string $leaf, string $expectedSha256): array
    { $this->calls[] = [$runId, $leaf, $expectedSha256]; return $this->value; }
}

final class SourceCapacityHelperFake implements HostRunnerCapacityHelper
{
    public array $calls = [];
    public function __construct(private readonly array $value) {}
    public function observe(string $runId, string $releaseId, string $rendererMode): array
    { $this->calls[] = [$runId, $releaseId, $rendererMode]; return $this->value; }
}

final class SourceStorageFake implements HostRunnerStorage
{
    public int $pinCount = 0;
    public function prepareRun(string $runId): void {}
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void { $this->pinCount++; }
    public function read(string $relative, int $maxBytes): ?string { return null; }
    public function pin(string $relative, string $bytes, int $maxBytes): string { throw new RuntimeException('unused'); }
    public function cow(string $relative, string $bytes, int $maxBytes): void { throw new RuntimeException('unused'); }
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void { throw new RuntimeException('unused'); }
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void { throw new RuntimeException('unused'); }
    public function clearActiveClaim(string $expectedBytes): void { throw new RuntimeException('unused'); }
    public function reservedCandidates(): iterable { return []; }
}

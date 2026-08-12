<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\ArtifactObservationV1;
use Ops\BuildVerifiedSourcesV1;
use Ops\CapacityObservationV1;
use Ops\CapacityVerifiedSourcesV1;
use Ops\DeploymentContractV1;
use Ops\DeploymentEvidenceAuthorityV1;
use Ops\DeploymentHostRunnerContractV1;
use Ops\DumpObservationV1;
use Ops\ExpectedCommitObservationV1;
use Ops\HostRunnerOrchestratorClock;
use Ops\HostRunnerPredeployOrchestrator;
use Ops\HostRunnerProtectedObservationSource;
use Ops\HostRunnerStorage;
use Ops\ProtectedHostPredeployObservationProvider;
use Ops\ProtectedPredeployObservationProvider;
use Ops\TrafficObservationV1;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerPredeployV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerEvidenceProviderV1.php';

final class DeploymentHostRunnerPredeployV1Test extends TestCase
{
    public function testProductionProviderShapeBindsRootExecutionInputDigestAndOrderedSources(): void
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile(
            $this->provenance($request['expected_commit'], $request['release_id']),
        );
        $input['parameters']['artifact_provenance_sha256'] = hash('sha256', $provenanceBytes);
        $delegate = $this->passedProvider($request);
        $source = new DelegatingProtectedObservationSource($delegate);
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
        self::assertSame(['expected_commit', 'traffic_gate', 'dump', 'capacity', 'artifact'], $source->ledger);
        self::assertSame($input['parameters']['artifact_provenance_sha256'], $source->authorizedProvenanceSha256);
        self::assertSame($input['parameters']['zero_surprise_dump'], $source->dumpReference);
    }

    public function testProductionProviderRejectsProvenanceDigestSubstitutionBeforeTraffic(): void
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $source = new DelegatingProtectedObservationSource($this->passedProvider($request));
        $provider = new ProtectedHostPredeployObservationProvider($source, $request, $input);

        $this->expectException(RuntimeException::class);
        try {
            DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
                $provider,
                $request['run_id'],
                $request['intent_sha256'],
                $request['release_id'],
                $request['expected_commit'],
                $request['traffic_mode'],
            );
        } finally {
            self::assertSame(['expected_commit'], $source->ledger);
        }
    }

    public function testPassedProviderPersistsArtifactVerifiedAttachableStateWithoutTerminalEvidence(): void
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $provider = $this->passedProvider($request);
        $storage = new PredeployMemoryStorage();

        $response = (new HostRunnerPredeployOrchestrator($storage, new PredeployFixedClock()))->collect(
            $request,
            $input,
            $provider,
        );

        self::assertSame('attach_pre_deploy', $response['disposition']);
        self::assertSame('artifact_verified', $response['state']);
        self::assertSame(['expected_commit', 'traffic_gate', 'dump', 'capacity', 'artifact'], $provider->ledger);
        $prefix = 'runs/' . $request['run_id'] . '/';
        self::assertArrayHasKey($prefix . 'predeploy-evidence.json', $storage->files);
        self::assertArrayNotHasKey($prefix . 'evidence.json', $storage->files);
        $state = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        self::assertSame('artifact_verified', $state['state']);
        self::assertSame(
            'current',
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, $storage->files[$prefix . 'events.jsonl']),
        );
    }

    public function testExpectedCommitMismatchPersistsValidatedTerminalBundleBeforeAnyReservation(): void
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $provenance = $this->provenance('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $request['release_id']);
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($provenance);
        $provider = new ExpectedCommitOnlyProvider($provenanceBytes);
        $storage = new PredeployMemoryStorage();
        $clock = new PredeployFixedClock();
        $runner = new HostRunnerPredeployOrchestrator($storage, $clock);

        $response = $runner->collect($request, $input, $provider);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame(25, $response['result_exit_code']);
        self::assertSame('expected_commit_mismatch', $response['result_reason']);
        self::assertSame(['expected_commit'], $provider->ledger);
        self::assertArrayNotHasKey('active-run.json', $storage->files);

        $prefix = 'runs/' . $request['run_id'] . '/';
        $eventsBytes = $storage->files[$prefix . 'events.jsonl'];
        $evidenceBytes = $storage->files[$prefix . 'evidence.json'];
        $stateBytes = $storage->files[$prefix . 'state.json'];
        self::assertArrayHasKey($prefix . 'orchestrator-finish.json', $storage->files);
        $finishPin = array_search(['pin', $prefix . 'orchestrator-finish.json'], $storage->operations, true);
        $firstEvidenceWrite = array_search(['cow', $prefix . 'evidence.json'], $storage->operations, true);
        self::assertIsInt($finishPin);
        self::assertIsInt($firstEvidenceWrite);
        self::assertLessThan($firstEvidenceWrite, $finishPin);
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        $evidence = json_decode($evidenceBytes, true, 64, JSON_THROW_ON_ERROR);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        self::assertSame('failed_before_write', DeploymentContractV1::validateRunLines($lines)['state']);
        self::assertSame('failed_before_write', $state['state']);
        self::assertSame(hash('sha256', $evidenceBytes), $state['evidence_sha256']);
        self::assertSame('terminal', DeploymentContractV1::validateBundle($lines, $evidence)['recovery']);
        self::assertSame(['cow', $prefix . 'evidence.json'], array_slice($storage->operations, -4, 1)[0]);
        self::assertSame(
            [
                ['cow', $prefix . 'evidence.json'],
                ['cow', $prefix . 'events.jsonl'],
                ['cow', $prefix . 'evidence.json'],
                ['cow', $prefix . 'state.json'],
            ],
            array_slice($storage->operations, -4),
        );
    }

    public function testTerminalPredeployReplayUsesExactPinnedStartAndEvidence(): void
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile(
            $this->provenance('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $request['release_id']),
        );
        $storage = new PredeployMemoryStorage();
        $first = new HostRunnerPredeployOrchestrator($storage, new PredeployFixedClock());
        $first->collect($request, $input, new ExpectedCommitOnlyProvider($provenanceBytes));
        $durable = $storage->files;

        $secondClock = new PredeployFixedClock(['2026-08-12T13:00:00Z'], [99_000_000_000]);
        $response = (new HostRunnerPredeployOrchestrator($storage, $secondClock))->collect(
            $request,
            $input,
            new ExpectedCommitOnlyProvider($provenanceBytes),
        );

        self::assertSame('terminal', $response['disposition']);
        self::assertSame($durable, $storage->files);
        self::assertSame(0, $secondClock->nowCalls);
        self::assertSame(0, $secondClock->monotonicCalls);
        self::assertSame(0, $secondClock->bootCalls);
    }

    /** @return array<string,mixed> */
    private function provenance(string $commit, string $releaseId): array
    {
        $sha = str_repeat('b', 64);
        return [
            'schema' => DeploymentEvidenceAuthorityV1::BUILD_PROVENANCE_SCHEMA,
            'release_id' => $releaseId,
            'expected_commit' => $commit,
            'observed_commit' => $commit,
            'archive' => ['name' => $releaseId . '.tar.gz', 'size_bytes' => 123456, 'sha256' => $sha],
            'capacity_bounds' => [
                'stage_file_count' => 1234,
                'stage_inode_count' => 2000,
                'stage_unpacked_bytes' => 400_000_000,
                'temp_scratch_bytes' => 67_108_864,
            ],
            'source' => [
                'build_script_sha256' => $sha,
                'composer_lock_sha256' => $sha,
                'package_lock_sha256' => $sha,
                'deploy_ea_sha256' => $sha,
            ],
        ];
    }

    private function passedProvider(array $request): PassedPredeployProvider
    {
        $sha = str_repeat('b', 64);
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile(
            $this->provenance($request['expected_commit'], $request['release_id']),
        );
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile([
            'schema' => DeploymentEvidenceAuthorityV1::DUMP_ATTESTATION_SCHEMA,
            'dump' => [
                'sha256' => $sha,
                'size_bytes' => 1_000_000,
                'uncompressed_size_bytes' => 4_000_000,
                'created_at_utc' => '2026-08-12T11:30:00Z',
            ],
            'verification' => [
                'method' => 'mariadb_10_11_isolated_restore_v1',
                'sha256_verified' => true,
                'gzip_verified' => true,
                'restore_verified' => true,
                'restored_datadir_allocated_bytes' => 8_000_000,
                'restored_datadir_inode_count' => 256,
                'restored_at_utc' => '2026-08-12T11:50:00Z',
            ],
            'attested_at_utc' => '2026-08-12T11:55:00Z',
        ]);
        $build = new BuildVerifiedSourcesV1(
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            $request['release_id'],
            $sha,
            123456,
            $sha,
            $sha,
            1234,
            2000,
            400_000_000,
            67_108_864,
        );
        return new PassedPredeployProvider(
            new ExpectedCommitObservationV1($provenanceBytes, hash('sha256', $provenanceBytes)),
            new TrafficObservationV1(
                $this->trafficReportBytes(),
                hash('sha256', $this->trafficReportBytes()),
                $sha,
                '2026-08-09.1',
                1,
                91,
            ),
            new DumpObservationV1(
                $attestationBytes,
                hash('sha256', $attestationBytes),
                1_000_000,
                '2026-08-12T12:00:00Z',
                null,
                $sha,
                null,
                null,
                null,
            ),
            new CapacityObservationV1(
                new CapacityVerifiedSourcesV1(
                    1,
                    4096,
                    1_000_000,
                    900_000,
                    10_000_000,
                    9_000_000,
                    $build,
                    $attestationBytes,
                    hash('sha256', $attestationBytes),
                    $sha,
                    1_000_000,
                    '2026-08-12T12:00:00Z',
                    50_000_000,
                    60_000_000,
                    500,
                    70_000_000,
                    700,
                    $this->capacityDevices(1),
                ),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
            ),
            new ArtifactObservationV1($build, null, null, null, null, null),
        );
    }

    private function trafficReportBytes(): string
    {
        $counts = array_fill_keys(
            [
                'documented_health',
                'documented_periodic_ops',
                'public_read',
                'denied_external',
                'business_or_authenticated',
                'unclassified',
                'status_5xx',
                'write',
                'authenticated',
                'customers_or_sensitive',
                'scanner_success',
                'source_unknown',
                'method_unknown',
                'target_unknown',
                'pre_window_completion',
                'lines_seen',
                'lines_in_window',
                'parse_errors',
                'rotation_errors',
                'total',
            ],
            0,
        );
        $counts['documented_health'] = 1;
        $counts['lines_seen'] = 1;
        $counts['lines_in_window'] = 1;
        $counts['total'] = 1;
        return json_encode(
            [
                'schema' => 'traffic_gate.v1',
                'producer_sha256' => str_repeat('b', 64),
                'policy_version' => 'traffic_gate_policy.v1',
                'catalog_version' => '2026-08-09.1',
                'purpose' => 'deploy',
                'mode' => 'normal',
                'window_start_epoch' => 1,
                'window_end_epoch' => 91,
                'window_seconds' => 90,
                'log_set_sha256' => str_repeat('b', 64),
                'rotation_complete' => true,
                'parse_complete' => true,
                'evidence_complete' => true,
                'decision' => 'allow',
                'exit_code' => 0,
                'counts' => $counts,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return array<string,int> */
    private function capacityDevices(int $device): array
    {
        return array_fill_keys(
            [
                'artifact',
                'dump_pin',
                'live_storage',
                'release_root',
                'renderer_state',
                'restore_scratch',
                'stage',
                'state_root',
                'temp',
            ],
            $device,
        );
    }
}

final class PassedPredeployProvider implements ProtectedPredeployObservationProvider
{
    /** @var list<string> */
    public array $ledger = [];

    public function __construct(
        private readonly ExpectedCommitObservationV1 $expected,
        private readonly TrafficObservationV1 $trafficValue,
        private readonly DumpObservationV1 $dumpValue,
        private readonly CapacityObservationV1 $capacityValue,
        private readonly ArtifactObservationV1 $artifactValue,
    ) {}

    public function expectedCommit(): ExpectedCommitObservationV1
    {
        $this->ledger[] = 'expected_commit';
        return $this->expected;
    }
    public function traffic(): TrafficObservationV1
    {
        $this->ledger[] = 'traffic_gate';
        return $this->trafficValue;
    }
    public function dump(): DumpObservationV1
    {
        $this->ledger[] = 'dump';
        return $this->dumpValue;
    }
    public function capacity(): CapacityObservationV1
    {
        $this->ledger[] = 'capacity';
        return $this->capacityValue;
    }
    public function artifact(): ArtifactObservationV1
    {
        $this->ledger[] = 'artifact';
        return $this->artifactValue;
    }
}

final class DelegatingProtectedObservationSource implements HostRunnerProtectedObservationSource
{
    /** @var list<string> */
    public array $ledger = [];
    public ?string $authorizedProvenanceSha256 = null;
    /** @var ?array<string,mixed> */
    public ?array $dumpReference = null;

    public function __construct(private readonly PassedPredeployProvider $delegate) {}

    public function buildProvenance(
        string $runId,
        string $releaseId,
        string $authorizedSha256,
    ): ExpectedCommitObservationV1 {
        $this->ledger[] = 'expected_commit';
        $this->authorizedProvenanceSha256 = $authorizedSha256;
        return $this->delegate->expectedCommit();
    }

    public function traffic(string $runId, string $intentSha256, string $mode): TrafficObservationV1
    {
        $this->ledger[] = 'traffic_gate';
        return $this->delegate->traffic();
    }

    public function dump(string $runId, string $intentSha256, array $dumpReference): DumpObservationV1
    {
        $this->ledger[] = 'dump';
        $this->dumpReference = $dumpReference;
        return $this->delegate->dump();
    }

    public function capacity(
        string $runId,
        string $intentSha256,
        array $input,
        ExpectedCommitObservationV1 $provenance,
        DumpObservationV1 $dump,
    ): CapacityObservationV1 {
        $this->ledger[] = 'capacity';
        return $this->delegate->capacity();
    }

    public function artifact(
        string $runId,
        string $intentSha256,
        ExpectedCommitObservationV1 $provenance,
        CapacityObservationV1 $capacity,
    ): ArtifactObservationV1 {
        $this->ledger[] = 'artifact';
        return $this->delegate->artifact();
    }
}

final class ExpectedCommitOnlyProvider implements ProtectedPredeployObservationProvider
{
    /** @var list<string> */
    public array $ledger = [];

    public function __construct(private readonly string $provenanceBytes) {}

    public function expectedCommit(): ExpectedCommitObservationV1
    {
        $this->ledger[] = 'expected_commit';
        return new ExpectedCommitObservationV1($this->provenanceBytes, hash('sha256', $this->provenanceBytes));
    }

    public function traffic(): TrafficObservationV1
    {
        throw new RuntimeException('traffic must not run');
    }
    public function dump(): DumpObservationV1
    {
        throw new RuntimeException('dump must not run');
    }
    public function capacity(): CapacityObservationV1
    {
        throw new RuntimeException('capacity must not run');
    }
    public function artifact(): ArtifactObservationV1
    {
        throw new RuntimeException('artifact must not run');
    }
}

final class PredeployFixedClock implements HostRunnerOrchestratorClock
{
    public int $nowCalls = 0;
    public int $bootCalls = 0;
    public int $monotonicCalls = 0;

    /** @param list<string> $times @param list<int> $monotonic */
    public function __construct(
        private array $times = ['2026-08-12T12:00:00Z', '2026-08-12T12:00:10Z'],
        private array $monotonic = [1_000_000_000, 11_000_000_000],
    ) {}

    public function nowUtc(): string
    {
        $value = $this->times[$this->nowCalls] ?? throw new RuntimeException('unexpected UTC read');
        $this->nowCalls++;
        return $value;
    }

    public function bootId(): string
    {
        $this->bootCalls++;
        return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    }

    public function monotonicNs(): int
    {
        $value = $this->monotonic[$this->monotonicCalls] ?? throw new RuntimeException('unexpected monotonic read');
        $this->monotonicCalls++;
        return $value;
    }
}

final class PredeployMemoryStorage implements HostRunnerStorage
{
    /** @var array<string,string> */
    public array $files = [];
    /** @var list<array{string,string}> */
    public array $operations = [];

    public function prepareRun(string $runId): void
    {
        $this->operations[] = ['prepare', $runId];
    }
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void
    {
        throw new RuntimeException('unused');
    }
    public function read(string $relative, int $maxBytes): ?string
    {
        return $this->files[$relative] ?? null;
    }
    public function pin(string $relative, string $bytes, int $maxBytes): string
    {
        if (isset($this->files[$relative]) && !hash_equals($this->files[$relative], $bytes)) {
            throw new RuntimeException('pin conflict');
        }
        $this->files[$relative] = $bytes;
        $this->operations[] = ['pin', $relative];
        return 'pinned_or_resumed_exact';
    }
    public function cow(string $relative, string $bytes, int $maxBytes): void
    {
        $this->files[$relative] = $bytes;
        $this->operations[] = ['cow', $relative];
    }
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void
    {
        throw new RuntimeException('unused');
    }
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void
    {
        throw new RuntimeException('unused');
    }
    public function clearActiveClaim(string $expectedBytes): void
    {
        throw new RuntimeException('unused');
    }
    public function reservedCandidates(): iterable
    {
        return [];
    }
}

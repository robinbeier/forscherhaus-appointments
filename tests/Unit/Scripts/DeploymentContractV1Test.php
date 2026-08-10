<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentContractV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentContractV1.php';

final class DeploymentContractV1Test extends TestCase
{
    private const RUN_ID = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const TIMING_RUN_ID = '128f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testValidLifecycleAndSuccessfulEvidenceAreAccepted(): void
    {
        $lines = $this->successfulRunLines();
        $evidence = $this->validEvidence($lines);

        $result = DeploymentContractV1::validateBundle($lines, $evidence);

        self::assertSame(self::RUN_ID, $result['run_id']);
        self::assertSame('succeeded', $result['state']);
        self::assertSame('terminal', $result['recovery']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['evidence_sha256']);
    }

    public function testCanonicalFailedBeforeWriteFixturesAreAccepted(): void
    {
        $fixtureRoot = dirname(__DIR__, 2) . '/Fixtures/deployment-contract-v1';
        $runBytes = file_get_contents($fixtureRoot . '/failed-before-write.jsonl');
        $evidenceBytes = file_get_contents($fixtureRoot . '/failed-before-write-evidence.json');
        self::assertIsString($runBytes);
        self::assertIsString($evidenceBytes);
        $lines = explode("\n", rtrim($runBytes, "\n"));
        $evidence = json_decode(rtrim($evidenceBytes, "\n"), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($evidence);

        $result = DeploymentContractV1::validateBundle($lines, $evidence);

        self::assertSame('failed_before_write', $result['state']);
        self::assertSame('terminal', $result['recovery']);
    }

    public function testEveryRequiredProgressTransitionIsAccepted(): void
    {
        $result = DeploymentContractV1::validateRunLines($this->successfulRunLines());

        self::assertSame(count(DeploymentContractV1::PROGRESS_STATES), $result['records']);
        self::assertSame(1, $result['deploy_invocation_count']);
    }

    #[DataProvider('invalidTransitionProvider')]
    public function testInvalidTransitionIsRejected(string $previous, string $next, int $count): void
    {
        $lines = [$this->encode($this->intent())];
        $states = DeploymentContractV1::PROGRESS_STATES;
        $targetIndex = array_search($previous, $states, true);
        self::assertIsInt($targetIndex);
        foreach (array_slice($states, 1, $targetIndex) as $state) {
            $lines[] = $this->encode($this->transition($lines, $state));
        }
        $lines[] = $this->encode($this->transition($lines, $next, $count));

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateRunLines($lines);
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function invalidTransitionProvider(): iterable
    {
        yield 'skip' => ['planned', 'uploaded', 0];
        yield 'repeat' => ['built', 'built', 0];
        yield 'backward' => ['uploaded', 'built', 0];
        yield 'post failure too early' => ['accepted', 'failed_post_switch_rollback_succeeded', 1];
        yield 'pre-switch after post-gates' => ['post_gates_running', 'failed_pre_switch', 1];
    }

    public function testTerminalStateCannotBeMutated(): void
    {
        $lines = $this->successfulRunLines();
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 31, 'rollback_failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('terminal deployment state is immutable');
        DeploymentContractV1::validateRunLines($lines);
    }

    public function testSecondInvocationIsRejected(): void
    {
        $lines = $this->runThrough('deploy_running');
        $record = $this->transition($lines, 'post_gates_running', 2);
        $lines[] = $this->encode($record);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at most one');
        DeploymentContractV1::validateRunLines($lines);
    }

    public function testCrashPrefixBeforeInvocationCanOnlyAttachPreDeploy(): void
    {
        $lines = $this->runThrough('artifact_verified');
        $result = DeploymentContractV1::validateRunLines($lines);

        self::assertSame('attach_pre_deploy', $result['recovery']);
        self::assertSame(0, $result['deploy_invocation_count']);
    }

    public function testCrashPrefixAfterInvocationReservationIsObserveOnly(): void
    {
        $lines = $this->runThrough('deploy_running');
        $result = DeploymentContractV1::validateRunLines($lines);

        self::assertSame('attach_observe_only', $result['recovery']);
        self::assertSame(1, $result['deploy_invocation_count']);
    }

    public function testAttachAcceptsSameRunAndIntent(): void
    {
        $lines = $this->runThrough('uploaded');

        self::assertSame('attach_pre_deploy', DeploymentContractV1::attachmentDecision($lines, $this->intent()));
    }

    public function testAttachRejectsSameRunWithChangedIntent(): void
    {
        $lines = $this->runThrough('uploaded');
        $changed = DeploymentContractV1::createIntentRecord(
            self::RUN_ID,
            '2026-08-10T04:00:00Z',
            self::COMMIT,
            'ea_changed',
            'normal',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different intent_sha256');
        DeploymentContractV1::attachmentDecision($lines, $changed);
    }

    public function testNewAuthorizationRequiresANewRunId(): void
    {
        $lines = $this->runThrough('uploaded');
        $newRun = DeploymentContractV1::createIntentRecord(
            '228f6f52-4c87-4d4e-8b19-6a66e6e1af25',
            '2026-08-10T04:00:00Z',
            self::COMMIT,
            'ea_contract',
            'normal',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('logical deployment intent');
        DeploymentContractV1::attachmentDecision($lines, $newRun);
    }

    public function testIntentHashChangesForEveryImmutableField(): void
    {
        $base = $this->intent();
        foreach (
            [
                ['expected_commit', str_repeat('c', 40)],
                ['release_id', 'ea_other'],
                ['traffic_mode', 'no-business-traffic'],
                ['dump_policy', 'other'],
                ['artifact_expectation', 'other'],
            ]
            as [$field, $value]
        ) {
            $changed = $base;
            $changed[$field] = $value;
            self::assertNotSame(
                $base['intent_sha256'],
                DeploymentContractV1::canonicalSha256([
                    'expected_commit' => $changed['expected_commit'],
                    'release_id' => $changed['release_id'],
                    'traffic_mode' => $changed['traffic_mode'],
                    'dump_policy' => $changed['dump_policy'],
                    'artifact_expectation' => $changed['artifact_expectation'],
                ]),
            );
        }
    }

    public function testCanonicalSerializationIsDeterministicAcrossObjectKeyOrder(): void
    {
        $first = ['z' => 1, 'nested' => ['b' => true, 'a' => 'x'], 'a' => [2, 1]];
        $second = ['a' => [2, 1], 'nested' => ['a' => 'x', 'b' => true], 'z' => 1];

        self::assertSame(DeploymentContractV1::canonicalJson($first), DeploymentContractV1::canonicalJson($second));
        self::assertSame(DeploymentContractV1::canonicalSha256($first), DeploymentContractV1::canonicalSha256($second));
    }

    public function testCanonicalSerializationRejectsFloats(): void
    {
        $this->expectException(RuntimeException::class);
        DeploymentContractV1::canonicalJson(['duration' => 1.5]);
    }

    public function testRunRejectsCorruptMixedMissingDuplicateAndUnknownRecords(): void
    {
        $valid = $this->runThrough('built');
        $cases = [
            [],
            ['{'],
            [$valid[0], ''],
            [$valid[0], $valid[1], $valid[1]],
            [$valid[0], $this->encode([...json_decode($valid[1], true, 64, JSON_THROW_ON_ERROR), 'future' => true])],
            [
                $valid[0],
                $this->encode([
                    ...json_decode($valid[1], true, 64, JSON_THROW_ON_ERROR),
                    'run_id' => self::TIMING_RUN_ID,
                ]),
            ],
        ];

        foreach ($cases as $case) {
            try {
                DeploymentContractV1::validateRunLines($case);
                self::fail('invalid run case was accepted');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRunRejectsBackwardTimeAndWrongScalarTypes(): void
    {
        $lines = $this->runThrough('built');
        $record = json_decode($lines[1], true, 64, JSON_THROW_ON_ERROR);
        $record['recorded_at_utc'] = '2026-08-10T03:59:59Z';
        $record['sequence'] = '2';
        $lines[1] = $this->encode($record);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateRunLines($lines);
    }

    #[DataProvider('terminalResultProvider')]
    public function testStablePublicExitReasonPairsAreAccepted(
        string $from,
        string $state,
        int $count,
        int $exit,
        string $reason,
    ): void {
        $lines = $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, $state, $count, $exit, $reason));

        self::assertSame('terminal', DeploymentContractV1::validateRunLines($lines)['recovery']);
    }

    /** @return iterable<string,array{string,string,int,int,string}> */
    public static function terminalResultProvider(): iterable
    {
        yield 'traffic' => ['expected_commit_verified', 'failed_before_write', 0, 20, 'traffic_hard_stop'];
        yield 'evidence' => ['expected_commit_verified', 'failed_before_write', 0, 21, 'traffic_evidence_invalid'];
        yield 'dump' => ['traffic_gate_passed', 'failed_before_write', 0, 22, 'dump_verification_failed'];
        yield 'capacity' => ['dump_verified', 'failed_before_write', 0, 23, 'capacity_gate_failed'];
        yield 'artifact' => ['capacity_passed', 'failed_before_write', 0, 24, 'artifact_verification_failed'];
        yield 'commit' => ['lock_acquired', 'failed_before_write', 0, 25, 'expected_commit_mismatch'];
        yield 'pre-switch' => ['deploy_running', 'failed_pre_switch', 1, 30, 'deploy_failed'];
        yield 'rollback succeeded' => [
            'post_gates_running',
            'failed_post_switch_rollback_succeeded',
            1,
            30,
            'deploy_failed',
        ];
        yield 'rollback failed' => [
            'post_gates_running',
            'failed_post_switch_rollback_failed',
            1,
            31,
            'rollback_failed',
        ];
        yield 'switch recovery' => [
            'deploy_running',
            'failed_switch_recovery_required',
            1,
            32,
            'switch_recovery_required',
        ];
        yield 'contract' => ['planned', 'failed_before_write', 0, 70, 'contract_invalid'];
        yield 'conflict' => ['accepted', 'failed_before_write', 0, 75, 'state_conflict'];
        yield 'interrupted before write' => ['artifact_verified', 'failed_before_write', 0, 143, 'interrupted'];
        yield 'interrupted after invocation' => ['deploy_running', 'manual_recovery_required', 1, 143, 'interrupted'];
    }

    public function testWrongExitReasonPairIsRejected(): void
    {
        $lines = $this->runThrough('lock_acquired');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 25, 'capacity_gate_failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stable pair');
        DeploymentContractV1::validateRunLines($lines);
    }

    public function testTrafficEvidenceContainsOnlyShaAndNormalizedCore(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $traffic = $evidence['traffic_gate'];

        self::assertArrayNotHasKey('raw_report', $traffic);
        self::assertArrayNotHasKey('snapshot', $traffic);
        self::assertArrayNotHasKey('path', $traffic);
        self::assertSame(DeploymentContractV1::TRAFFIC_COUNT_KEYS, array_keys($traffic['counts']));
        DeploymentContractV1::validateEvidence($evidence);
    }

    #[DataProvider('invalidTrafficMutationProvider')]
    public function testTrafficCoreFailsClosed(string $field, mixed $value): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        if (str_starts_with($field, 'counts.')) {
            $evidence['traffic_gate']['counts'][substr($field, 7)] = $value;
        } else {
            $evidence['traffic_gate'][$field] = $value;
        }

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidTrafficMutationProvider(): iterable
    {
        yield 'purpose' => ['purpose', 'customers-ui-smoke'];
        yield 'policy' => ['policy_version', 'old'];
        yield 'wrong type' => ['counts.lines_seen', '1'];
        yield 'negative' => ['counts.lines_seen', -1];
        yield 'class sum' => ['counts.public_read', 1];
        yield 'window arithmetic' => ['window_seconds', 89];
        yield 'false completeness' => ['parse_complete', false];
        yield 'decision mismatch' => ['decision', 'hard_stop'];
        yield 'raw sha malformed' => ['report_sha256', 'ABC'];
    }

    public function testTrafficUnknownFieldAndFullReportInjectionAreRejected(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['traffic_gate']['future_raw_path'] = '/secret';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected fields');
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testEvidenceRejectsTrafficModeThatDiffersFromIntent(): void
    {
        $lines = $this->successfulRunLines();
        $evidence = $this->validEvidence($lines);
        $evidence['traffic_gate']['mode'] = 'no-business-traffic';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bound traffic mode');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testDumpAgeAtExactly240MinutesIsRejected(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['dump']['age_seconds'] = 14400;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dump status is inconsistent');
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testArtifactHashOrHostScriptMismatchIsRejected(): void
    {
        foreach (['remote_sha256', 'artifact_script_sha256'] as $field) {
            $evidence = $this->validEvidence($this->successfulRunLines());
            $evidence['artifact'][$field] = str_repeat('c', 64);
            try {
                DeploymentContractV1::validateEvidence($evidence);
                self::fail('artifact mismatch was accepted');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSuccessfulEvidenceRequiresEveryPostGateIncludingKumaThirteenOfThirteen(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['post_gates']['kuma_healthy_count'] = 12;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testOuterWallClockNeverReplacesOrMixesDeployTiming(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());

        self::assertSame(
            ['status', 'authoritative_sha256', 'run_id', 'total_ms'],
            array_keys($evidence['deploy_timing']),
        );
        self::assertSame(
            ['started_at_utc', 'finished_at_utc', 'wall_clock_ms'],
            array_keys($evidence['orchestrator_timing']),
        );
        self::assertNotSame($evidence['deploy_timing']['total_ms'], $evidence['orchestrator_timing']['wall_clock_ms']);
        self::assertNotSame($evidence['run_id'], $evidence['deploy_timing']['run_id']);
    }

    public function testTimingObservabilityGapDoesNotRewriteSuccessfulDeployOutcome(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['deploy_timing'] = [
            'status' => 'not_observed',
            'authoritative_sha256' => null,
            'run_id' => null,
            'total_ms' => null,
        ];

        DeploymentContractV1::validateEvidence($evidence);
        self::assertSame('succeeded', $evidence['result']['state']);
    }

    public function testSecretPiiPathAndFreeFormFieldsCannotEnterClosedEvidence(): void
    {
        foreach (['secret', 'customer_email', 'path', 'stderr', 'url'] as $field) {
            $evidence = $this->validEvidence($this->successfulRunLines());
            $evidence[$field] = 'SENSITIVE_PERSON_MARKER';
            try {
                DeploymentContractV1::validateEvidence($evidence);
                self::fail('secret-bearing field was accepted');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testForwardUnknownSchemaAndFieldsAreRejected(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['schema'] = 'deployment_evidence.v2';

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    /** @return array<string,mixed> */
    private function intent(): array
    {
        return DeploymentContractV1::createIntentRecord(
            self::RUN_ID,
            '2026-08-10T04:00:00Z',
            self::COMMIT,
            'ea_contract',
            'normal',
        );
    }

    /** @return list<string> */
    private function successfulRunLines(): array
    {
        return $this->runThrough('succeeded');
    }

    /** @return list<string> */
    private function runThrough(string $target): array
    {
        $lines = [$this->encode($this->intent())];
        if ($target === 'planned') {
            return $lines;
        }
        foreach (array_slice(DeploymentContractV1::PROGRESS_STATES, 1) as $state) {
            $lines[] = $this->encode($this->transition($lines, $state));
            if ($state === $target) {
                break;
            }
        }

        return $lines;
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function transition(
        array $lines,
        string $state,
        ?int $count = null,
        int $exit = 0,
        string $reason = 'ok',
    ): array {
        $previous = json_decode($lines[array_key_last($lines)], true, 64, JSON_THROW_ON_ERROR);
        $count ??= in_array(
            $state,
            [
                'deploy_running',
                'post_gates_running',
                'succeeded',
                'failed_pre_switch',
                'failed_switch_recovery_required',
                'failed_post_switch_rollback_succeeded',
                'failed_post_switch_rollback_failed',
                'manual_recovery_required',
            ],
            true,
        )
            ? 1
            : 0;

        return [
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => sprintf('2026-08-10T04:00:%02dZ', count($lines)),
            'previous_state' => $previous['state'],
            'state' => $state,
            'deploy_invocation_count' => $count,
            'intent_sha256' => $this->intent()['intent_sha256'],
            'exit_code' => $exit,
            'reason' => $reason,
        ];
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function validEvidence(array $lines): array
    {
        $intent = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
        $counts = array_fill_keys(DeploymentContractV1::TRAFFIC_COUNT_KEYS, 0);
        $counts['documented_health'] = 1;
        $counts['total'] = 1;
        $counts['lines_seen'] = 1;
        $counts['lines_in_window'] = 1;

        return [
            'schema' => DeploymentContractV1::EVIDENCE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $intent['intent_sha256'],
            'captured_at_utc' => '2026-08-10T04:01:00Z',
            'expected_commit' => ['expected' => self::COMMIT, 'observed' => self::COMMIT, 'verified' => true],
            'traffic_gate' => [
                'status' => 'passed',
                'report_sha256' => self::SHA,
                'schema' => 'traffic_gate.v1',
                'producer_sha256' => self::SHA,
                'policy_version' => 'traffic_gate_policy.v1',
                'catalog_version' => '2026-08-09.1',
                'purpose' => 'deploy',
                'mode' => 'normal',
                'window_start_epoch' => 1786334400,
                'window_end_epoch' => 1786334490,
                'window_seconds' => 90,
                'log_set_sha256' => self::SHA,
                'rotation_complete' => true,
                'parse_complete' => true,
                'evidence_complete' => true,
                'decision' => 'allow',
                'exit_code' => 0,
                'counts' => $counts,
            ],
            'dump' => [
                'status' => 'passed',
                'policy' => DeploymentContractV1::DUMP_POLICY,
                'age_seconds' => 60,
                'max_age_seconds' => 14400,
                'sha256' => self::SHA,
                'gzip_verified' => true,
                'restore_verified' => true,
            ],
            'capacity' => [
                'status' => 'passed',
                'available_bytes' => 8_000_000_000,
                'projected_required_bytes' => 1_000_000_000,
                'observed_percent' => 81,
                'projected_percent' => 84,
                'passed' => true,
            ],
            'artifact' => [
                'status' => 'passed',
                'expectation' => DeploymentContractV1::ARTIFACT_EXPECTATION,
                'local_sha256' => self::SHA,
                'remote_sha256' => self::SHA,
                'manifest_sha256' => self::SHA,
                'host_script_sha256' => self::SHA,
                'artifact_script_sha256' => self::SHA,
                'verified' => true,
            ],
            'deploy' => [
                'status' => 'succeeded',
                'invocation_count' => 1,
                'exit_code' => 0,
                'rollback_outcome' => 'not_run',
            ],
            'post_gates' => [
                'status' => 'passed',
                'kuma_healthy_count' => 13,
                'kuma_total_count' => 13,
                'runtime_config_passed' => true,
                'services_passed' => true,
                'endpoints_passed' => true,
                'logs_passed' => true,
                'scanner_passed' => true,
                'dormant_clean_passed' => true,
                'passed' => true,
            ],
            'deploy_timing' => [
                'status' => 'valid',
                'authoritative_sha256' => self::SHA,
                'run_id' => self::TIMING_RUN_ID,
                'total_ms' => 124020,
            ],
            'orchestrator_timing' => [
                'started_at_utc' => '2026-08-10T04:00:00Z',
                'finished_at_utc' => '2026-08-10T04:01:00Z',
                'wall_clock_ms' => 180000,
            ],
            'result' => ['state' => 'succeeded', 'exit_code' => 0, 'reason' => 'ok'],
        ];
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return DeploymentContractV1::canonicalJson($value);
    }
}

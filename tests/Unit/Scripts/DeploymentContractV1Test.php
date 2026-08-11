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

    public function testRollbackReservationIsDurableAndObserveOnly(): void
    {
        $result = DeploymentContractV1::validateRunLines($this->rollbackRunningLines());

        self::assertSame('rollback_running', $result['state']);
        self::assertSame('attach_observe_only', $result['recovery']);
        self::assertSame(1, $result['deploy_invocation_count']);
    }

    public function testSuccessfulProgressionDoesNotEnterRollbackReservation(): void
    {
        self::assertNotContains('rollback_running', DeploymentContractV1::PROGRESS_STATES);
        $states = array_map(
            static fn(string $line): string => json_decode($line, true, 64, JSON_THROW_ON_ERROR)['state'],
            array_slice($this->successfulRunLines(), 1),
        );
        $postGateIndex = array_search('post_gates_running', $states, true);
        self::assertIsInt($postGateIndex);
        self::assertSame('succeeded', $states[$postGateIndex + 1]);
    }

    public function testRollbackReservationCanOnlyFollowPostGates(): void
    {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'rollback_running', 1));

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateRunLines($lines);
    }

    #[DataProvider('transitionForbiddenAfterRollbackReservationProvider')]
    public function testRollbackReservationRejectsIllegalContinuation(
        string $state,
        int $exitCode,
        string $reason,
    ): void {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode($this->transition($lines, $state, 1, $exitCode, $reason));

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateRunLines($lines);
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function transitionForbiddenAfterRollbackReservationProvider(): iterable
    {
        yield 'second reservation' => ['rollback_running', 0, 'ok'];
        yield 'backward to post-gates' => ['post_gates_running', 0, 'ok'];
        yield 'success skips rollback verdict' => ['succeeded', 0, 'ok'];
        yield 'failed before write after invocation' => ['failed_before_write', 70, 'contract_invalid'];
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
        $lines = $from === 'rollback_running' ? $this->rollbackRunningLines() : $this->runThrough($from);
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
            'rollback_running',
            'failed_post_switch_rollback_succeeded',
            1,
            30,
            'deploy_failed',
        ];
        yield 'rollback failed' => ['rollback_running', 'failed_post_switch_rollback_failed', 1, 31, 'rollback_failed'];
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

    public function testObservedInterruptedInvocationUsesFailedPreSwitchJournalTerminal(): void
    {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'failed_pre_switch', 1, 143, 'interrupted'));

        $result = DeploymentContractV1::validateRunLines($lines);

        self::assertSame('failed_pre_switch', $result['state']);
        self::assertSame('terminal', $result['recovery']);
    }

    public function testObservedInterruptedInvocationUsesFailedPreSwitchBundleTerminal(): void
    {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'failed_pre_switch', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'failed_pre_switch',
            143,
            'interrupted',
            143,
            'not_run',
            'not_observed',
        );

        $result = DeploymentContractV1::validateBundle($lines, $evidence);

        self::assertSame('failed_pre_switch', $result['state']);
        self::assertSame('terminal', $result['recovery']);
    }

    #[DataProvider('failedPreSwitchEvidenceExitMismatchProvider')]
    public function testFailedPreSwitchEvidenceExitMustMatchTerminalReason(
        int $publicExit,
        string $reason,
        int $deployExit,
    ): void {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'failed_pre_switch', 1, $publicExit, $reason));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'failed_pre_switch',
            $publicExit,
            $reason,
            $deployExit,
            'not_run',
            'not_observed',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deploy and rollback evidence');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{int,string,int}> */
    public static function failedPreSwitchEvidenceExitMismatchProvider(): iterable
    {
        yield 'deploy failure cannot claim interrupted child' => [30, 'deploy_failed', 143];
        yield 'interrupted terminal cannot claim ordinary deploy failure' => [143, 'interrupted', 30];
    }

    public function testWrongExitReasonPairIsRejected(): void
    {
        $lines = $this->runThrough('lock_acquired');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 25, 'capacity_gate_failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stable pair');
        DeploymentContractV1::validateRunLines($lines);
    }

    public function testSwitchRecoveryRequiredCannotFollowPostGates(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_switch_recovery_required', 1, 32, 'switch_recovery_required'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deployment phase');
        DeploymentContractV1::validateRunLines($lines);
    }

    public function testSwitchRecoveryBundleRejectsLegacyDeployExitCode(): void
    {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_switch_recovery_required', 1, 32, 'switch_recovery_required'),
        );
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'failed_switch_recovery_required',
            32,
            'switch_recovery_required',
            31,
            'recovery_required',
            'not_observed',
        );

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testManualRecoveryCannotAliasSwitchRecoveryFailureAfterPostGates(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode(
            $this->transition($lines, 'manual_recovery_required', 1, 32, 'switch_recovery_required'),
        );

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateRunLines($lines);
    }

    public function testPostGateRollbackFailureCannotSkipWriteAheadReservation(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 31, 'rollback_failed'));

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateRunLines($lines);
    }

    public function testFailedBeforeWriteReasonMustMatchTheJournalPredecessor(): void
    {
        $lines = $this->runThrough('planned');
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_before_write', 0, 24, 'artifact_verification_failed'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('claimed gate');
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

    #[DataProvider('impossibleTrafficCompletenessProvider')]
    public function testTrafficCompletenessIsDerivedFromProducerCounts(string $count, int $value): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts'][$count] = $value;
        if ($count === 'parse_errors') {
            $evidence['traffic_gate']['counts']['lines_seen'] = 2;
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('completeness');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,int}> */
    public static function impossibleTrafficCompletenessProvider(): iterable
    {
        yield 'parse errors contradict parse complete' => ['parse_errors', 1];
        yield 'rotation error contradicts rotation complete' => ['rotation_errors', 1];
    }

    #[DataProvider('unknownTrafficOverlayProvider')]
    public function testUnknownTrafficOverlayCannotExceedUnclassified(string $overlay): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['documented_health'] = 1;
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 0;
        $evidence['traffic_gate']['counts'][$overlay] = 1;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string}> */
    public static function unknownTrafficOverlayProvider(): iterable
    {
        yield 'unknown source' => ['source_unknown'];
        yield 'unknown method' => ['method_unknown'];
        yield 'unknown target' => ['target_unknown'];
    }

    #[DataProvider('unknownTrafficOverlayProvider')]
    public function testUnknownTrafficOverlayCanEqualUnclassified(string $overlay): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 0;
        $evidence['traffic_gate']['counts']['unclassified'] = 1;
        $evidence['traffic_gate']['counts'][$overlay] = 1;
        if ($overlay === 'method_unknown') {
            $evidence['traffic_gate']['counts']['business_or_authenticated'] = 1;
            $evidence['traffic_gate']['counts']['total'] = 2;
            $evidence['traffic_gate']['counts']['lines_seen'] = 2;
            $evidence['traffic_gate']['counts']['lines_in_window'] = 2;
            $evidence['traffic_gate']['counts']['write'] = 1;
        }

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testUnknownMethodCannotExceedWriteTraffic(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 0;
        $evidence['traffic_gate']['counts']['unclassified'] = 1;
        $evidence['traffic_gate']['counts']['method_unknown'] = 1;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testUnknownMethodWriteCanBeContainedByOneUnclassifiedLine(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 0;
        $evidence['traffic_gate']['counts']['unclassified'] = 1;
        $evidence['traffic_gate']['counts']['method_unknown'] = 1;
        $evidence['traffic_gate']['counts']['write'] = 1;

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testUnknownTargetAndSensitiveCustomerTrafficCannotShareOneLine(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 0;
        $evidence['traffic_gate']['counts']['unclassified'] = 1;
        $evidence['traffic_gate']['counts']['target_unknown'] = 1;
        $evidence['traffic_gate']['counts']['customers_or_sensitive'] = 1;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testUnknownTargetAndSensitiveCustomerTrafficCanFitTwoLines(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['unclassified'] = 1;
        $evidence['traffic_gate']['counts']['target_unknown'] = 1;
        $evidence['traffic_gate']['counts']['customers_or_sensitive'] = 1;
        $evidence['traffic_gate']['counts']['total'] = 2;
        $evidence['traffic_gate']['counts']['lines_seen'] = 2;
        $evidence['traffic_gate']['counts']['lines_in_window'] = 2;

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testScannerUnknownTargetAndSensitiveTrafficCannotOccupyTwoClassLines(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['unclassified'] = 1;
        $evidence['traffic_gate']['counts']['scanner_success'] = 1;
        $evidence['traffic_gate']['counts']['target_unknown'] = 1;
        $evidence['traffic_gate']['counts']['customers_or_sensitive'] = 1;
        $evidence['traffic_gate']['counts']['total'] = 2;
        $evidence['traffic_gate']['counts']['lines_seen'] = 2;
        $evidence['traffic_gate']['counts']['lines_in_window'] = 2;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testScannerUnknownTargetAndSensitiveTrafficCanFitThreeClassLines(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 2;
        $evidence['traffic_gate']['counts']['unclassified'] = 1;
        $evidence['traffic_gate']['counts']['scanner_success'] = 1;
        $evidence['traffic_gate']['counts']['target_unknown'] = 1;
        $evidence['traffic_gate']['counts']['customers_or_sensitive'] = 1;
        $evidence['traffic_gate']['counts']['total'] = 3;
        $evidence['traffic_gate']['counts']['lines_seen'] = 3;
        $evidence['traffic_gate']['counts']['lines_in_window'] = 3;

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testUnclassifiedTrafficCannotExceedCombinedUnknownOverlays(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 0;
        $evidence['traffic_gate']['counts']['unclassified'] = 1;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('invalidTrafficReportProvider')]
    public function testTrafficEvidenceInvalidCanRepresentUnavailableOrMalformedReport(?string $reportSha256): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 21, 'traffic_evidence_invalid'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 21, 'traffic_evidence_invalid');
        $evidence['traffic_gate'] = $this->invalidTrafficReportEvidence($evidence['traffic_gate'], $reportSha256);

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{?string}> */
    public static function invalidTrafficReportProvider(): iterable
    {
        yield 'unavailable report' => [null];
        yield 'malformed report bytes retained by hash' => [self::SHA];
    }

    #[DataProvider('invalidTrafficReportContradictionProvider')]
    public function testTrafficEvidenceInvalidRejectsPartialOrContradictoryReportCore(string $field, mixed $value): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 21, 'traffic_evidence_invalid'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 21, 'traffic_evidence_invalid');
        $evidence['traffic_gate'] = $this->invalidTrafficReportEvidence($evidence['traffic_gate'], null);
        $evidence['traffic_gate'][$field] = $value;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidTrafficReportContradictionProvider(): iterable
    {
        yield 'malformed report hash' => ['report_sha256', 'not-a-sha'];
        yield 'wrong producer exit' => ['exit_code', 20];
        yield 'schema' => ['schema', 'traffic_gate.v1'];
        yield 'producer hash' => ['producer_sha256', self::SHA];
        yield 'policy version' => ['policy_version', 'traffic_gate_policy.v1'];
        yield 'catalog version' => ['catalog_version', '2026-08-09.1'];
        yield 'purpose' => ['purpose', 'deploy'];
        yield 'mode' => ['mode', 'normal'];
        yield 'window start' => ['window_start_epoch', 1];
        yield 'window end' => ['window_end_epoch', 2];
        yield 'window seconds' => ['window_seconds', 1];
        yield 'log set hash' => ['log_set_sha256', self::SHA];
        yield 'rotation completeness' => ['rotation_complete', false];
        yield 'parse completeness' => ['parse_complete', false];
        yield 'evidence completeness' => ['evidence_complete', false];
        yield 'decision' => ['decision', 'invalid'];
        yield 'partial counts' => ['counts', ['documented_health' => 0]];
    }

    #[DataProvider('trafficOutcomeWithoutCoreProvider')]
    public function testObservedTrafficOutcomeStillRequiresFullCore(string $status, int $exitCode): void
    {
        $lines = $status === 'passed' ? $this->successfulRunLines() : $this->runThrough('expected_commit_verified');
        if ($status === 'failed') {
            $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        }
        $evidence =
            $status === 'passed'
                ? $this->validEvidence($lines)
                : $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate'] = $this->invalidTrafficReportEvidence($evidence['traffic_gate'], self::SHA);
        $evidence['traffic_gate']['status'] = $status;
        $evidence['traffic_gate']['exit_code'] = $exitCode;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,int}> */
    public static function trafficOutcomeWithoutCoreProvider(): iterable
    {
        yield 'passed without core' => ['passed', 0];
        yield 'hard stop without core' => ['failed', 20];
    }

    #[DataProvider('hazardousTrafficOverlayProvider')]
    public function testHazardousTrafficOverlayCannotExceedUnsafeClasses(string $overlay): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['documented_health'] = 1;
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 0;
        $evidence['traffic_gate']['counts'][$overlay] = 1;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string}> */
    public static function hazardousTrafficOverlayProvider(): iterable
    {
        yield 'write' => ['write'];
        yield 'server error' => ['status_5xx'];
        yield 'authenticated' => ['authenticated'];
        yield 'customers or sensitive' => ['customers_or_sensitive'];
        yield 'scanner success' => ['scanner_success'];
    }

    #[DataProvider('hazardousTrafficOverlayProvider')]
    public function testHazardousTrafficOverlayCanMatchBusinessClass(string $overlay): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts'][$overlay] = 1;

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testScannerSuccessCannotExceedBusinessTraffic(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 0;
        $evidence['traffic_gate']['counts']['unclassified'] = 1;
        $evidence['traffic_gate']['counts']['source_unknown'] = 1;
        $evidence['traffic_gate']['counts']['scanner_success'] = 1;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('nonScannerHazardOverlayProvider')]
    public function testScannerSuccessIsDisjointFromOtherHazardOverlays(string $overlay): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['scanner_success'] = 1;
        $evidence['traffic_gate']['counts'][$overlay] = 1;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('nonScannerHazardOverlayProvider')]
    public function testScannerAndHazardOverlayCanFitTwoBusinessLines(string $overlay): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['traffic_gate']['counts']['business_or_authenticated'] = 2;
        $evidence['traffic_gate']['counts']['total'] = 2;
        $evidence['traffic_gate']['counts']['lines_seen'] = 2;
        $evidence['traffic_gate']['counts']['lines_in_window'] = 2;
        $evidence['traffic_gate']['counts']['scanner_success'] = 1;
        $evidence['traffic_gate']['counts'][$overlay] = 1;

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{string}> */
    public static function nonScannerHazardOverlayProvider(): iterable
    {
        yield 'server error' => ['status_5xx'];
        yield 'write' => ['write'];
        yield 'authenticated' => ['authenticated'];
        yield 'customers or sensitive' => ['customers_or_sensitive'];
    }

    public function testOverlappingHazardOverlaysDoNotRequireExtraBusinessLines(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        foreach (['status_5xx', 'write', 'authenticated', 'customers_or_sensitive'] as $overlay) {
            $evidence['traffic_gate']['counts'][$overlay] = 1;
        }

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testTrafficParsedAndFailedLinesCannotOverlapSourceLines(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 21, 'traffic_evidence_invalid'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 21, 'traffic_evidence_invalid');
        $evidence['traffic_gate']['counts']['lines_seen'] = 1;
        $evidence['traffic_gate']['counts']['parse_errors'] = 1;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('count bounds');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('impossibleTrafficCountBoundProvider')]
    public function testTrafficProducerCountBoundsAreEnforced(
        int $exitCode,
        string $reason,
        array $countOverrides,
        array $coreOverrides,
    ): void {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, $exitCode, $reason));
        $evidence = $this->failedBeforeWriteEvidence($lines, $exitCode, $reason);
        $evidence['traffic_gate']['counts'] = array_replace($evidence['traffic_gate']['counts'], $countOverrides);
        $evidence['traffic_gate'] = array_replace($evidence['traffic_gate'], $coreOverrides);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('count bounds');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{int,string,array<string,int>,array<string,mixed>}> */
    public static function impossibleTrafficCountBoundProvider(): iterable
    {
        yield 'window exceeds seen lines' => [
            20,
            'traffic_hard_stop',
            ['documented_health' => 1, 'total' => 2, 'lines_in_window' => 2],
            [],
        ];
        yield 'pre-window completion exceeds window' => [20, 'traffic_hard_stop', ['pre_window_completion' => 2], []];
        yield 'more than one rotation error' => [
            21,
            'traffic_evidence_invalid',
            ['rotation_errors' => 2],
            ['rotation_complete' => false],
        ];
    }

    public function testTrafficUnknownFieldAndFullReportInjectionAreRejected(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['traffic_gate']['future_raw_path'] = '/secret';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected fields');
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testTrafficCatalogVersionMustMatchProducerGrammar(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['traffic_gate']['catalog_version'] = 'x';

        $this->expectException(RuntimeException::class);
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

    #[DataProvider('missingClaimedFailureEvidenceProvider')]
    public function testFailedBeforeWriteRequiresEvidenceForTheClaimedGate(
        string $from,
        int $exitCode,
        string $reason,
        string $missingSection,
    ): void {
        $lines = $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, $exitCode, $reason));
        $evidence = $this->failedBeforeWriteEvidence($lines, $exitCode, $reason);
        $evidence[$missingSection] = $this->notObservedSection($evidence[$missingSection]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failure evidence');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,int,string,string}> */
    public static function missingClaimedFailureEvidenceProvider(): iterable
    {
        yield 'traffic hard stop' => ['expected_commit_verified', 20, 'traffic_hard_stop', 'traffic_gate'];
        yield 'traffic invalid' => ['expected_commit_verified', 21, 'traffic_evidence_invalid', 'traffic_gate'];
        yield 'dump' => ['traffic_gate_passed', 22, 'dump_verification_failed', 'dump'];
        yield 'capacity' => ['dump_verified', 23, 'capacity_gate_failed', 'capacity'];
        yield 'artifact' => ['capacity_passed', 24, 'artifact_verification_failed', 'artifact'];
    }

    #[DataProvider('claimedFailureEvidenceProvider')]
    public function testFailedBeforeWriteAcceptsMatchingGateEvidence(string $from, int $exitCode, string $reason): void
    {
        $lines = $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, $exitCode, $reason));
        $evidence = $this->failedBeforeWriteEvidence($lines, $exitCode, $reason);

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function claimedFailureEvidenceProvider(): iterable
    {
        yield 'traffic hard stop' => ['expected_commit_verified', 20, 'traffic_hard_stop'];
        yield 'traffic invalid' => ['expected_commit_verified', 21, 'traffic_evidence_invalid'];
        yield 'dump' => ['traffic_gate_passed', 22, 'dump_verification_failed'];
        yield 'capacity' => ['dump_verified', 23, 'capacity_gate_failed'];
        yield 'artifact' => ['capacity_passed', 24, 'artifact_verification_failed'];
        yield 'commit' => ['lock_acquired', 25, 'expected_commit_mismatch'];
    }

    public function testGenericInterruptedFailureMustEvidenceEveryPreviouslyVerifiedGate(): void
    {
        $lines = $this->runThrough('artifact_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 143, 'interrupted'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 143, 'interrupted');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last verified deployment state');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testGenericFailureCannotClaimSuccessBeyondJournalProgress(): void
    {
        $lines = $this->runThrough('planned');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 70, 'contract_invalid'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 70, 'contract_invalid');
        $evidence['expected_commit']['observed'] = null;
        $evidence['expected_commit']['verified'] = false;
        $evidence['capacity'] = $this->validEvidence($lines)['capacity'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('beyond the last verified deployment state');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testGenericContractFailureCannotAlsoClaimInvalidTrafficEvidence(): void
    {
        $lines = $this->runThrough('planned');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 70, 'contract_invalid'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 70, 'contract_invalid');
        $evidence['expected_commit']['observed'] = null;
        $evidence['expected_commit']['verified'] = false;
        $evidence['traffic_gate'] = $this->invalidTrafficReportEvidence($evidence['traffic_gate'], null);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testExpectedCommitMismatchRequiresAnObservedDifferentCommit(): void
    {
        $lines = $this->runThrough('lock_acquired');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 25, 'expected_commit_mismatch'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 25, 'expected_commit_mismatch');
        $evidence['expected_commit']['observed'] = $evidence['expected_commit']['expected'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected commit evidence is inconsistent');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('intentEvidenceMismatchProvider')]
    public function testEveryIntentEvidenceBindingRejectsMismatch(string $section, string $field, mixed $value): void
    {
        $lines = $this->successfulRunLines();
        $evidence = $this->validEvidence($lines);
        $evidence[$section][$field] = $value;
        if ($section === 'expected_commit') {
            $evidence[$section]['observed'] = $value;
        }

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testEvidenceCaptureCannotPrecedeTheTerminalJournalRecord(): void
    {
        $lines = $this->successfulRunLines();
        $evidence = $this->validEvidence($lines);
        $evidence['captured_at_utc'] = '2026-08-10T03:59:59Z';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('terminal journal record');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,string,mixed}> */
    public static function intentEvidenceMismatchProvider(): iterable
    {
        yield 'expected commit' => ['expected_commit', 'expected', str_repeat('c', 40)];
        yield 'dump policy' => ['dump', 'policy', 'future_policy'];
        yield 'artifact expectation' => ['artifact', 'expectation', 'future_expectation'];
    }

    public function testCliValidatesFixturesAndUsesStableUsageAndInvalidExitCodes(): void
    {
        $fixtureRoot = dirname(__DIR__, 2) . '/Fixtures/deployment-contract-v1';
        [$validExit, $validStdout, $validStderr] = $this->runCli([
            '--run-jsonl=' . $fixtureRoot . '/failed-before-write.jsonl',
            '--evidence-json=' . $fixtureRoot . '/failed-before-write-evidence.json',
        ]);
        self::assertSame(0, $validExit, $validStderr);
        self::assertSame('', $validStderr);
        $validOutput = json_decode($validStdout, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['schema', 'valid', 'run_id', 'state', 'records', 'recovery', 'evidence_sha256'],
            array_keys($validOutput),
        );
        self::assertSame('deployment_contract_validation.v1', $validOutput['schema']);
        self::assertTrue($validOutput['valid']);
        self::assertSame(self::RUN_ID, $validOutput['run_id']);
        self::assertSame('failed_before_write', $validOutput['state']);
        self::assertSame('terminal', $validOutput['recovery']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $validOutput['evidence_sha256']);

        [$usageExit, , $usageStderr] = $this->runCli([]);
        self::assertSame(64, $usageExit);
        self::assertStringContainsString('Usage:', $usageStderr);

        [$invalidExit, , $invalidStderr] = $this->runCli([
            '--run-jsonl=' . $fixtureRoot . '/failed-before-write.jsonl',
            '--evidence-json=' . $fixtureRoot . '/missing.json',
        ]);
        self::assertSame(70, $invalidExit);
        self::assertStringContainsString('INVALID:', $invalidStderr);
    }

    public function testStateConflictBeforeWriteBundleRemainsRepresentable(): void
    {
        $lines = $this->runThrough('accepted');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 75, 'state_conflict'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 75, 'state_conflict');
        $evidence['expected_commit']['observed'] = null;
        $evidence['expected_commit']['verified'] = false;

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    #[DataProvider('postWriteStateConflictProvider')]
    public function testStateConflictAfterWriteIsRejectedByJournal(string $from, string $_postGateStatus): void
    {
        $lines = $from === 'rollback_running' ? $this->rollbackRunningLines() : $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 75, 'state_conflict'));

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateRunLines($lines);
    }

    #[DataProvider('postWriteStateConflictProvider')]
    public function testStateConflictAfterWriteIsRejectedByBundle(string $from, string $postGateStatus): void
    {
        $lines = $from === 'rollback_running' ? $this->rollbackRunningLines() : $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 75, 'state_conflict'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            75,
            'state_conflict',
            31,
            'recovery_required',
            $postGateStatus,
        );
        if ($from === 'rollback_running') {
            $evidence['rollback'] = $this->rollbackEvidence('failed');
        }

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,string}> */
    public static function postWriteStateConflictProvider(): iterable
    {
        yield 'deploy running' => ['deploy_running', 'not_observed'];
        yield 'rollback running' => ['rollback_running', 'failed'];
    }

    #[DataProvider('nonSuccessTerminalBundleProvider')]
    public function testEveryNonSuccessTerminalBundleIsRepresentable(
        string $from,
        string $state,
        int $publicExit,
        string $reason,
        int $deployExit,
        string $rollbackOutcome,
        string $postGateStatus,
    ): void {
        $lines = $from === 'rollback_running' ? $this->rollbackRunningLines() : $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, $state, 1, $publicExit, $reason));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            $state,
            $publicExit,
            $reason,
            $deployExit,
            $rollbackOutcome,
            $postGateStatus,
        );

        self::assertSame($state, DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{string,string,int,string,int,string,string}> */
    public static function nonSuccessTerminalBundleProvider(): iterable
    {
        yield 'failed pre-switch' => [
            'deploy_running',
            'failed_pre_switch',
            30,
            'deploy_failed',
            30,
            'not_run',
            'not_observed',
        ];
        yield 'switch recovery required' => [
            'deploy_running',
            'failed_switch_recovery_required',
            32,
            'switch_recovery_required',
            32,
            'recovery_required',
            'not_observed',
        ];
        yield 'rollback succeeded before post-gates' => [
            'deploy_running',
            'failed_post_switch_rollback_succeeded',
            30,
            'deploy_failed',
            30,
            'succeeded',
            'not_observed',
        ];
        yield 'rollback failed before post-gates' => [
            'deploy_running',
            'failed_post_switch_rollback_failed',
            31,
            'rollback_failed',
            31,
            'failed',
            'not_observed',
        ];
        yield 'rollback succeeded after post-gates' => [
            'rollback_running',
            'failed_post_switch_rollback_succeeded',
            30,
            'deploy_failed',
            30,
            'succeeded',
            'failed',
        ];
        yield 'rollback failed after post-gates' => [
            'rollback_running',
            'failed_post_switch_rollback_failed',
            31,
            'rollback_failed',
            31,
            'failed',
            'failed',
        ];
    }

    #[DataProvider('completedPostGateRollbackProvider')]
    public function testCompletedPostGateRollbackRequiresDedicatedReservation(
        string $state,
        int $publicExit,
        string $reason,
        string $rollbackStatus,
    ): void {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode($this->transition($lines, $state, 1, $publicExit, $reason));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            $state,
            $publicExit,
            $reason,
            $publicExit,
            $rollbackStatus,
            'failed',
        );
        $evidence['rollback'] = $this->rollbackEvidence('not_invoked');

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,int,string,string}> */
    public static function completedPostGateRollbackProvider(): iterable
    {
        yield 'rollback succeeded' => ['failed_post_switch_rollback_succeeded', 30, 'deploy_failed', 'succeeded'];
        yield 'rollback failed' => ['failed_post_switch_rollback_failed', 31, 'rollback_failed', 'failed'];
    }

    #[DataProvider('completedPostGateRollbackProvider')]
    public function testCompletedRollbackCannotSkipWriteAheadReservation(
        string $state,
        int $publicExit,
        string $reason,
        string $_rollbackStatus,
    ): void {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode($this->transition($lines, $state, 1, $publicExit, $reason));

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateRunLines($lines);
    }

    #[DataProvider('completedPostGateRollbackProvider')]
    public function testPostGateRollbackTerminalRejectsFailedDeployAlias(
        string $state,
        int $publicExit,
        string $reason,
        string $rollbackStatus,
    ): void {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode($this->transition($lines, $state, 1, $publicExit, $reason));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            $state,
            $publicExit,
            $reason,
            $publicExit,
            $rollbackStatus,
            'failed',
        );
        $evidence['deploy'] = [
            'status' => 'failed',
            'invocation_count' => 1,
            'exit_code' => $publicExit,
            'rollback_outcome' => $rollbackStatus,
        ];

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('completedPostGateRollbackProvider')]
    public function testDirectInternalRollbackCannotClaimDedicatedRollback(
        string $state,
        int $publicExit,
        string $reason,
        string $rollbackStatus,
    ): void {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, $state, 1, $publicExit, $reason));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            $state,
            $publicExit,
            $reason,
            $publicExit,
            $rollbackStatus,
            'not_observed',
        );
        $evidence['rollback'] = $this->rollbackEvidence($rollbackStatus);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testDedicatedRollbackCannotBeClaimedBySuccessfulDeployment(): void
    {
        $lines = $this->successfulRunLines();
        $evidence = $this->validEvidence($lines);
        $evidence['rollback'] = $this->rollbackEvidence('unknown');

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('postGatePhaseMismatchProvider')]
    public function testPostGateEvidenceMustMatchTheFailureTransitionPhase(string $from, string $postGateStatus): void
    {
        $state = 'failed_post_switch_rollback_failed';
        $lines = $from === 'rollback_running' ? $this->rollbackRunningLines() : $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, $state, 1, 31, 'rollback_failed'));
        $evidence = $this->invokedFailureEvidence($lines, $state, 31, 'rollback_failed', 31, 'failed', $postGateStatus);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transition phase');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,string}> */
    public static function postGatePhaseMismatchProvider(): iterable
    {
        yield 'claims failure before post-gates' => ['deploy_running', 'failed'];
        yield 'omits failure after reservation' => ['rollback_running', 'not_observed'];
    }

    #[DataProvider('rollbackSucceededPostGatePhaseMismatchProvider')]
    public function testRollbackSucceededPostGateEvidenceMustMatchTransitionPhase(
        string $from,
        string $postGateStatus,
    ): void {
        $state = 'failed_post_switch_rollback_succeeded';
        $lines = $from === 'rollback_running' ? $this->rollbackRunningLines() : $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, $state, 1, 30, 'deploy_failed'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            $state,
            30,
            'deploy_failed',
            30,
            'succeeded',
            $postGateStatus,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transition phase');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,string}> */
    public static function rollbackSucceededPostGatePhaseMismatchProvider(): iterable
    {
        yield 'claims failure before post-gates' => ['deploy_running', 'failed'];
        yield 'omits failure after reservation' => ['rollback_running', 'not_observed'];
    }

    public function testInterruptedManualRecoveryBeforePostGatesRetainsUnknownDeployOutcome(): void
    {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $notObserved = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'not_observed',
        );
        $notObserved['deploy'] = $this->unknownInvokedDeployEvidence();
        self::assertSame(
            'manual_recovery_required',
            DeploymentContractV1::validateBundle($lines, $notObserved)['state'],
        );

        $observedFailure = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'failed',
        );
        $observedFailure['deploy'] = $this->unknownInvokedDeployEvidence();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transition phase');
        DeploymentContractV1::validateBundle($lines, $observedFailure);
    }

    public function testRejectedReceiptNormalizesToContractInvalidManualRecovery(): void
    {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 70, 'contract_invalid'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            70,
            'contract_invalid',
            31,
            'recovery_required',
            'not_observed',
        );
        $evidence['deploy'] = $this->unknownInvokedDeployEvidence();

        self::assertSame('manual_recovery_required', DeploymentContractV1::validateBundle($lines, $evidence)['state']);

        $evidence['deploy'] = $this->succeededDeployEvidence();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected child result');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('lateContractInvalidProvider')]
    public function testRejectedReceiptNormalizationIsLimitedToDeployRunning(string $from): void
    {
        $lines = $from === 'rollback_running' ? $this->rollbackRunningLines() : $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 70, 'contract_invalid'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected child result');
        DeploymentContractV1::validateRunLines($lines);
    }

    /** @return iterable<string,array{string}> */
    public static function lateContractInvalidProvider(): iterable
    {
        yield 'post gates already observed an accepted deploy' => ['post_gates_running'];
        yield 'dedicated rollback already reserved' => ['rollback_running'];
    }

    #[DataProvider('contradictoryUnknownDeployEvidenceProvider')]
    public function testUnknownDeployOutcomeRejectsPartialOrContradictoryClaims(
        array $overrides,
        ?string $missingField = null,
    ): void {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'not_observed',
        );
        $evidence['deploy'] = array_replace($this->unknownInvokedDeployEvidence(), $overrides);
        if ($missingField !== null) {
            unset($evidence['deploy'][$missingField]);
        }

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{array<string,mixed>,?string}> */
    public static function contradictoryUnknownDeployEvidenceProvider(): iterable
    {
        yield 'observed exit code' => [['exit_code' => 31], null];
        yield 'claimed rollback outcome' => [['rollback_outcome' => 'recovery_required'], null];
        yield 'invocation not retained' => [['invocation_count' => 0], null];
        yield 'failed status alias' => [['status' => 'failed'], null];
        yield 'extra field' => [['child_pid' => null], null];
        yield 'missing field' => [[], 'exit_code'];
    }

    public function testInterruptedManualRecoveryRejectsFabricatedRecoveryOutcome(): void
    {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'not_observed',
        );

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('terminalThatCannotUseUnknownDeployEvidenceProvider')]
    public function testUnknownDeployOutcomeIsRestrictedToDirectInterruptedManualRecovery(
        string $from,
        string $state,
        int $publicExit,
        string $reason,
        int $deployExit,
        string $rollbackOutcome,
        string $postGateStatus,
    ): void {
        $lines = $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, $state, 1, $publicExit, $reason));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            $state,
            $publicExit,
            $reason,
            $deployExit,
            $rollbackOutcome,
            $postGateStatus,
        );
        $evidence['deploy'] = $this->unknownInvokedDeployEvidence();

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,string,int,string,int,string,string}> */
    public static function terminalThatCannotUseUnknownDeployEvidenceProvider(): iterable
    {
        yield 'pre-switch deploy failure' => [
            'deploy_running',
            'failed_pre_switch',
            30,
            'deploy_failed',
            30,
            'not_run',
            'not_observed',
        ];
        yield 'manual recovery for rollback failure' => [
            'deploy_running',
            'manual_recovery_required',
            31,
            'rollback_failed',
            31,
            'recovery_required',
            'not_observed',
        ];
        yield 'interrupted after post-gates began' => [
            'post_gates_running',
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'failed',
        ];
    }

    #[DataProvider('validIncompletePostGateProvider')]
    public function testInterruptedManualRecoveryAfterPostGatesAcceptsIncompleteEvidence(array $observed): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'not_observed',
        );
        $evidence['deploy'] = $this->succeededDeployEvidence();
        $evidence['post_gates'] = array_replace($this->incompletePostGateEvidence($evidence['post_gates']), $observed);

        self::assertSame('manual_recovery_required', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function validIncompletePostGateProvider(): iterable
    {
        yield 'all checks unavailable' => [[]];
        yield 'mixed partial checks' => [
            [
                'kuma_healthy_count' => 13,
                'kuma_total_count' => 13,
                'runtime_config_passed' => true,
            ],
        ];
    }

    public function testInterruptedManualRecoveryAfterRollbackReservationRetainsUnknownOutcome(): void
    {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'failed',
        );
        $evidence['rollback'] = $this->rollbackEvidence('unknown');

        self::assertSame('manual_recovery_required', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testRollbackFailureManualRecoveryRetainsFailedVerifiedOutcome(): void
    {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 31, 'rollback_failed'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            31,
            'rollback_failed',
            31,
            'failed',
            'failed',
        );

        self::assertFalse($evidence['rollback']['verified']);
        self::assertSame('manual_recovery_required', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    #[DataProvider('nonInterruptedRollbackFailureProvider')]
    public function testNonInterruptedRollbackFailureCannotRetainUnknownOutcome(string $state): void
    {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode($this->transition($lines, $state, 1, 31, 'rollback_failed'));
        $evidence = $this->invokedFailureEvidence($lines, $state, 31, 'rollback_failed', 31, 'failed', 'failed');
        $evidence['rollback'] = $this->rollbackEvidence('unknown');

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string}> */
    public static function nonInterruptedRollbackFailureProvider(): iterable
    {
        yield 'failed rollback terminal' => ['failed_post_switch_rollback_failed'];
        yield 'manual recovery terminal' => ['manual_recovery_required'];
    }

    #[DataProvider('contradictoryRollbackEvidenceProvider')]
    public function testRollbackEvidenceRejectsMalformedOrContradictoryShape(
        array $overrides,
        ?string $missingField = null,
    ): void {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'failed',
        );
        $evidence['rollback'] = array_replace($this->rollbackEvidence('unknown'), $overrides);
        if ($missingField !== null) {
            unset($evidence['rollback'][$missingField]);
        }

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{array<string,mixed>,?string}> */
    public static function contradictoryRollbackEvidenceProvider(): iterable
    {
        yield 'missing field' => [[], 'verified'];
        yield 'extra field' => [['exit_code' => null], null];
        yield 'wrong status type' => [['status' => 1], null];
        yield 'wrong invocation count type' => [['invocation_count' => '1'], null];
        yield 'wrong verified type' => [['verified' => 'false'], null];
        yield 'unknown status enum' => [['status' => 'reserved'], null];
        yield 'second invocation' => [['invocation_count' => 2], null];
        yield 'unknown without reservation' => [['invocation_count' => 0], null];
        yield 'unknown with wrong mode' => [['mode' => 'not_applicable'], null];
        yield 'unknown with verdict' => [['verified' => false], null];
        yield 'succeeded without verdict' => [['status' => 'succeeded'], null];
        yield 'failed with successful verdict' => [['status' => 'failed', 'verified' => true], null];
        yield 'not invoked with dedicated mode' => [['status' => 'not_invoked', 'invocation_count' => 0], null];
    }

    #[DataProvider('invalidIncompletePostGateShapeProvider')]
    public function testIncompletePostGateEvidenceRejectsContradictoryShape(array $observed): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'not_observed',
        );
        $evidence['deploy'] = $this->succeededDeployEvidence();
        $evidence['post_gates'] = array_replace($this->incompletePostGateEvidence($evidence['post_gates']), $observed);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidIncompletePostGateShapeProvider(): iterable
    {
        yield 'healthy count without total' => [['kuma_healthy_count' => 13]];
        yield 'total count without healthy' => [['kuma_total_count' => 13]];
        yield 'negative Kuma count' => [['kuma_healthy_count' => -1, 'kuma_total_count' => 13]];
        yield 'wrong Kuma type' => [['kuma_healthy_count' => '13', 'kuma_total_count' => 13]];
        yield 'healthy exceeds total' => [['kuma_healthy_count' => 14, 'kuma_total_count' => 13]];
        yield 'wrong gate type' => [['runtime_config_passed' => 'true']];
        yield 'passed is non-null' => [['passed' => false]];
        yield 'all checks complete' => [
            [
                'kuma_healthy_count' => 13,
                'kuma_total_count' => 13,
                'runtime_config_passed' => true,
                'services_passed' => true,
                'endpoints_passed' => true,
                'logs_passed' => false,
                'scanner_passed' => true,
                'dormant_clean_passed' => true,
            ],
        ];
    }

    #[DataProvider('forbiddenIncompletePostGateTerminalProvider')]
    public function testIncompletePostGateEvidenceIsRestrictedToInterruptedManualRecovery(
        string $from,
        string $state,
        int $publicExit,
        string $reason,
        int $deployExit,
        string $rollbackOutcome,
    ): void {
        $lines = $from === 'rollback_running' ? $this->rollbackRunningLines() : $this->runThrough($from);
        $lines[] = $this->encode($this->transition($lines, $state, 1, $publicExit, $reason));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            $state,
            $publicExit,
            $reason,
            $deployExit,
            $rollbackOutcome,
            'not_observed',
        );
        $evidence['post_gates'] = $this->incompletePostGateEvidence($evidence['post_gates']);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,string,int,string,int,string}> */
    public static function forbiddenIncompletePostGateTerminalProvider(): iterable
    {
        yield 'rollback terminal' => [
            'rollback_running',
            'failed_post_switch_rollback_succeeded',
            30,
            'deploy_failed',
            30,
            'succeeded',
        ];
        yield 'manual recovery rollback reason' => [
            'rollback_running',
            'manual_recovery_required',
            31,
            'rollback_failed',
            31,
            'recovery_required',
        ];
        yield 'manual recovery switch reason' => [
            'post_gates_running',
            'manual_recovery_required',
            32,
            'switch_recovery_required',
            31,
            'recovery_required',
        ];
        yield 'interrupted before post-gates' => [
            'deploy_running',
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
        ];
    }

    public function testSuccessfulDeploymentRejectsIncompletePostGateEvidence(): void
    {
        $lines = $this->successfulRunLines();
        $evidence = $this->validEvidence($lines);
        $evidence['post_gates'] = $this->incompletePostGateEvidence($evidence['post_gates']);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testInterruptedManualRecoveryAfterPostGatesStillAcceptsCompleteFailureEvidence(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'failed',
        );
        $evidence['deploy'] = $this->succeededDeployEvidence();

        self::assertSame('manual_recovery_required', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testInterruptedManualRecoveryAfterPostGatesAcceptsCompletePassedEvidence(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'not_observed',
        );
        $evidence['deploy'] = $this->succeededDeployEvidence();
        $evidence['post_gates'] = $this->validEvidence($lines)['post_gates'];

        self::assertSame('manual_recovery_required', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testInterruptedManualRecoveryAfterPostGatesRejectsFabricatedRecoveryOutcome(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'failed',
        );
        $evidence['deploy'] = [
            'status' => 'failed',
            'invocation_count' => 1,
            'exit_code' => 31,
            'rollback_outcome' => 'recovery_required',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testInterruptedManualRecoveryBeforePostGatesRejectsSucceededDeployOutcome(): void
    {
        $lines = $this->runThrough('deploy_running');
        $lines[] = $this->encode($this->transition($lines, 'manual_recovery_required', 1, 143, 'interrupted'));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'manual_recovery_required',
            143,
            'interrupted',
            31,
            'recovery_required',
            'not_observed',
        );
        $evidence['deploy'] = $this->succeededDeployEvidence();

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('terminalThatCannotRetainPassedPostGatesProvider')]
    public function testPassedPostGatesAreRejectedForOtherFailureOutcomes(
        string $state,
        int $publicExit,
        string $reason,
        int $deployExit,
        string $rollbackOutcome,
    ): void {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode($this->transition($lines, $state, 1, $publicExit, $reason));
        $evidence = $this->invokedFailureEvidence(
            $lines,
            $state,
            $publicExit,
            $reason,
            $deployExit,
            $rollbackOutcome,
            'not_observed',
        );
        $evidence['post_gates'] = $this->validEvidence($lines)['post_gates'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('claim passed post-gates');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,int,string,int,string}> */
    public static function terminalThatCannotRetainPassedPostGatesProvider(): iterable
    {
        yield 'manual recovery for rollback failure' => [
            'manual_recovery_required',
            31,
            'rollback_failed',
            31,
            'recovery_required',
        ];
        yield 'rollback succeeded terminal' => [
            'failed_post_switch_rollback_succeeded',
            30,
            'deploy_failed',
            30,
            'succeeded',
        ];
        yield 'rollback failed terminal' => ['failed_post_switch_rollback_failed', 31, 'rollback_failed', 31, 'failed'];
    }

    public function testDumpAgeAtExactly240MinutesIsRejected(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['dump']['age_seconds'] = 14400;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dump status is inconsistent');
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testFailedDumpCanRepresentUnverifiedSha(): void
    {
        $lines = $this->runThrough('traffic_gate_passed');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 22, 'dump_verification_failed'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 22, 'dump_verification_failed');
        $evidence['dump']['age_seconds'] = 60;
        $evidence['dump']['sha256_verified'] = false;

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    public function testPassedDumpRejectsUnverifiedSha(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['dump']['sha256_verified'] = false;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    #[DataProvider('invalidDumpShaVerificationSchemaProvider')]
    public function testDumpShaVerificationFieldIsClosedAndBoolean(string $mutation): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        if ($mutation === 'missing') {
            unset($evidence['dump']['sha256_verified']);
        } elseif ($mutation === 'wrong_type') {
            $evidence['dump']['sha256_verified'] = 'true';
        } else {
            $evidence['dump']['sha256_verification_source'] = 'invented';
        }

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDumpShaVerificationSchemaProvider(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'wrong type' => ['wrong_type'];
        yield 'extra field' => ['extra'];
    }

    #[DataProvider('validInvalidDumpEvidenceProvider')]
    public function testDumpVerificationFailureCanRepresentUnavailableMeasurements(array $observed): void
    {
        $lines = $this->runThrough('traffic_gate_passed');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 22, 'dump_verification_failed'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 22, 'dump_verification_failed');
        $evidence['dump'] = array_replace($this->invalidDumpEvidence($evidence['dump']), $observed);

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function validInvalidDumpEvidenceProvider(): iterable
    {
        yield 'all measurements unavailable' => [[]];
        yield 'gzip observed before age and digest failure' => [['gzip_verified' => true]];
    }

    #[DataProvider('invalidDumpEvidenceShapeProvider')]
    public function testInvalidDumpEvidenceRejectsMalformedOrCompleteMeasurements(array $observed): void
    {
        $lines = $this->runThrough('traffic_gate_passed');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 22, 'dump_verification_failed'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 22, 'dump_verification_failed');
        $evidence['dump'] = array_replace($this->invalidDumpEvidence($evidence['dump']), $observed);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidDumpEvidenceShapeProvider(): iterable
    {
        yield 'wrong policy' => [['policy' => 'different_dump_policy']];
        yield 'wrong maximum age' => [['max_age_seconds' => 1]];
        yield 'negative age' => [['age_seconds' => -1]];
        yield 'wrong age type' => [['age_seconds' => '60']];
        yield 'malformed digest' => [['sha256' => 'not-a-sha']];
        yield 'wrong digest verification type' => [['sha256_verified' => 'true']];
        yield 'wrong gzip type' => [['gzip_verified' => 'true']];
        yield 'wrong restore type' => [['restore_verified' => 'true']];
        yield 'verified without digest' => [['sha256_verified' => true]];
        yield 'fully observed invalid status' => [
            [
                'age_seconds' => 60,
                'sha256' => self::SHA,
                'sha256_verified' => false,
                'gzip_verified' => true,
                'restore_verified' => true,
            ],
        ];
    }

    public function testPassedDumpRejectsUnavailableMeasurement(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['dump']['age_seconds'] = null;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testGenericFailureCannotAliasInvalidDumpEvidence(): void
    {
        $lines = $this->runThrough('planned');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 70, 'contract_invalid'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 70, 'contract_invalid');
        $evidence['expected_commit']['observed'] = null;
        $evidence['expected_commit']['verified'] = false;
        $evidence['dump'] = $this->invalidDumpEvidence($evidence['dump']);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testCapacityFailureCannotAliasInvalidDumpEvidence(): void
    {
        $lines = $this->runThrough('dump_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 23, 'capacity_gate_failed'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 23, 'capacity_gate_failed');
        $evidence['dump'] = $this->invalidDumpEvidence($evidence['dump']);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('validInvalidCapacityEvidenceProvider')]
    public function testCapacityFailureCanRepresentUnavailableMeasurements(array $observed): void
    {
        $lines = $this->runThrough('dump_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 23, 'capacity_gate_failed'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 23, 'capacity_gate_failed');
        $evidence['capacity'] = array_replace($this->invalidCapacityEvidence($evidence['capacity']), $observed);

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function validInvalidCapacityEvidenceProvider(): iterable
    {
        yield 'all measurements unavailable' => [[]];
        yield 'partial observed percentage' => [['observed_percent' => 81, 'passed' => false]];
    }

    #[DataProvider('invalidCapacityEvidenceShapeProvider')]
    public function testInvalidCapacityEvidenceRejectsMalformedOrCompleteShape(array $observed): void
    {
        $lines = $this->runThrough('dump_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 23, 'capacity_gate_failed'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 23, 'capacity_gate_failed');
        $evidence['capacity'] = array_replace($this->invalidCapacityEvidence($evidence['capacity']), $observed);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidCapacityEvidenceShapeProvider(): iterable
    {
        yield 'wrong maximum percentage' => [['max_used_percent' => 84]];
        foreach (['available_bytes', 'projected_required_bytes', 'observed_percent', 'projected_percent'] as $field) {
            yield 'wrong ' . $field . ' type' => [[$field => '1']];
            yield 'negative ' . $field => [[$field => -1]];
        }
        yield 'observed percentage over one hundred' => [['observed_percent' => 101]];
        yield 'projected percentage over one hundred' => [['projected_percent' => 101]];
        yield 'wrong passed type' => [['passed' => 'false']];
        yield 'passed true' => [['passed' => true]];
        yield 'fully observed invalid status' => [
            [
                'available_bytes' => 8_000_000_000,
                'projected_required_bytes' => 1_000_000_000,
                'observed_percent' => 81,
                'projected_percent' => 84,
                'passed' => false,
            ],
        ];
    }

    public function testPassedCapacityRejectsUnavailableMeasurement(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['capacity']['available_bytes'] = null;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testGenericFailureCannotAliasInvalidCapacityEvidence(): void
    {
        $lines = $this->runThrough('planned');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 70, 'contract_invalid'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 70, 'contract_invalid');
        $evidence['expected_commit']['observed'] = null;
        $evidence['expected_commit']['verified'] = false;
        $evidence['capacity'] = $this->invalidCapacityEvidence($evidence['capacity']);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testArtifactFailureCannotAliasInvalidCapacityEvidence(): void
    {
        $lines = $this->runThrough('capacity_passed');
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_before_write', 0, 24, 'artifact_verification_failed'),
        );
        $evidence = $this->failedBeforeWriteEvidence($lines, 24, 'artifact_verification_failed');
        $evidence['capacity'] = $this->invalidCapacityEvidence($evidence['capacity']);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('validInvalidArtifactEvidenceProvider')]
    public function testArtifactVerificationFailureCanRepresentUnavailableHashes(array $observed): void
    {
        $lines = $this->runThrough('capacity_passed');
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_before_write', 0, 24, 'artifact_verification_failed'),
        );
        $evidence = $this->failedBeforeWriteEvidence($lines, 24, 'artifact_verification_failed');
        $evidence['artifact'] = array_replace($this->invalidArtifactEvidence($evidence['artifact']), $observed);

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function validInvalidArtifactEvidenceProvider(): iterable
    {
        yield 'all hashes unavailable' => [[]];
        yield 'partial local and manifest hashes observed' => [
            [
                'local_sha256' => self::SHA,
                'manifest_sha256' => self::SHA,
                'verified' => false,
            ],
        ];
        yield 'all hashes observed before verification' => [
            [
                'local_sha256' => self::SHA,
                'remote_sha256' => self::SHA,
                'manifest_sha256' => self::SHA,
                'host_script_sha256' => self::SHA,
                'artifact_script_sha256' => self::SHA,
                'verified' => null,
            ],
        ];
    }

    #[DataProvider('invalidArtifactEvidenceShapeProvider')]
    public function testInvalidArtifactEvidenceRejectsMalformedOrCompleteShape(
        array $observed,
        ?string $missingField = null,
    ): void {
        $lines = $this->runThrough('capacity_passed');
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_before_write', 0, 24, 'artifact_verification_failed'),
        );
        $evidence = $this->failedBeforeWriteEvidence($lines, 24, 'artifact_verification_failed');
        $evidence['artifact'] = array_replace($this->invalidArtifactEvidence($evidence['artifact']), $observed);
        if ($missingField !== null) {
            unset($evidence['artifact'][$missingField]);
        }

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{array<string,mixed>,?string}> */
    public static function invalidArtifactEvidenceShapeProvider(): iterable
    {
        yield 'wrong expectation' => [['expectation' => 'different_artifact_expectation'], null];
        foreach (
            ['local_sha256', 'remote_sha256', 'manifest_sha256', 'host_script_sha256', 'artifact_script_sha256']
            as $hashField
        ) {
            yield 'malformed ' . $hashField => [[$hashField => 'not-a-sha'], null];
        }
        yield 'wrong verified type' => [['verified' => 'false'], null];
        yield 'verified true' => [['local_sha256' => self::SHA, 'verified' => true], null];
        yield 'fully observed invalid status' => [
            [
                'local_sha256' => self::SHA,
                'remote_sha256' => self::SHA,
                'manifest_sha256' => self::SHA,
                'host_script_sha256' => self::SHA,
                'artifact_script_sha256' => self::SHA,
                'verified' => false,
            ],
            null,
        ];
        yield 'extra field' => [['extra' => null], null];
        yield 'missing field' => [[], 'remote_sha256'];
    }

    #[DataProvider('completeArtifactStatusRequiringVerificationProvider')]
    public function testCompleteArtifactWithUnknownVerificationCannotAliasObservedStatus(string $status): void
    {
        $lines = $this->runThrough('capacity_passed');
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_before_write', 0, 24, 'artifact_verification_failed'),
        );
        $evidence = $this->failedBeforeWriteEvidence($lines, 24, 'artifact_verification_failed');
        $evidence['artifact'] = $this->validEvidence($lines)['artifact'];
        $evidence['artifact']['status'] = $status;
        $evidence['artifact']['verified'] = null;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string}> */
    public static function completeArtifactStatusRequiringVerificationProvider(): iterable
    {
        yield 'passed' => ['passed'];
        yield 'failed' => ['failed'];
    }

    public function testPassedArtifactRejectsUnavailableHash(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['artifact']['remote_sha256'] = null;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testGenericFailureCannotAliasInvalidArtifactEvidence(): void
    {
        $lines = $this->runThrough('planned');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 70, 'contract_invalid'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 70, 'contract_invalid');
        $evidence['expected_commit']['observed'] = null;
        $evidence['expected_commit']['verified'] = false;
        $evidence['artifact'] = $this->invalidArtifactEvidence($evidence['artifact']);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    public function testCapacityFailureCannotAliasInvalidArtifactEvidence(): void
    {
        $lines = $this->runThrough('dump_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 23, 'capacity_gate_failed'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 23, 'capacity_gate_failed');
        $evidence['artifact'] = $this->invalidArtifactEvidence($evidence['artifact']);

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
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

    public function testFailedPostGateEvidenceRejectsMoreHealthyThanTotalKumaChecks(): void
    {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_post_switch_rollback_failed', 1, 31, 'rollback_failed'),
        );
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'failed_post_switch_rollback_failed',
            31,
            'rollback_failed',
            31,
            'failed',
            'failed',
        );
        $evidence['post_gates']['kuma_healthy_count'] = 14;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    public function testPostGateSummaryIsDerivedFromEveryIndividualCheck(): void
    {
        $lines = $this->rollbackRunningLines();
        $lines[] = $this->encode(
            $this->transition($lines, 'failed_post_switch_rollback_failed', 1, 31, 'rollback_failed'),
        );
        $evidence = $this->invokedFailureEvidence(
            $lines,
            'failed_post_switch_rollback_failed',
            31,
            'rollback_failed',
            31,
            'failed',
            'failed',
        );
        $evidence['post_gates']['logs_passed'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('summary');
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    #[DataProvider('unsafeCapacityProvider')]
    public function testCapacityDecisionIsDerivedFromMeasurements(
        int $availableBytes,
        int $requiredBytes,
        int $observedPercent,
        int $projectedPercent,
    ): void {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['capacity']['max_used_percent'] = 85;
        $evidence['capacity']['available_bytes'] = $availableBytes;
        $evidence['capacity']['projected_required_bytes'] = $requiredBytes;
        $evidence['capacity']['observed_percent'] = $observedPercent;
        $evidence['capacity']['projected_percent'] = $projectedPercent;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('capacity');
        DeploymentContractV1::validateEvidence($evidence);
    }

    /** @return iterable<string,array{int,int,int,int}> */
    public static function unsafeCapacityProvider(): iterable
    {
        yield 'insufficient bytes' => [0, 1, 81, 84];
        yield 'observed threshold reached' => [8_000_000_000, 1_000_000_000, 85, 85];
        yield 'projected threshold reached' => [8_000_000_000, 1_000_000_000, 81, 85];
        yield 'projection moves backwards' => [8_000_000_000, 1_000_000_000, 84, 81];
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

    #[DataProvider('validOrchestratorTimingProvider')]
    public function testOrchestratorWallClockAcceptsSecondPrecisionBounds(
        string $startedAtUtc,
        string $finishedAtUtc,
        int $wallClockMs,
    ): void {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['orchestrator_timing'] = [
            'started_at_utc' => $startedAtUtc,
            'finished_at_utc' => $finishedAtUtc,
            'wall_clock_ms' => $wallClockMs,
        ];

        DeploymentContractV1::validateEvidence($evidence);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function validOrchestratorTimingProvider(): iterable
    {
        yield 'lower 60-second bound' => ['2026-08-10T04:00:00Z', '2026-08-10T04:01:00Z', 59_001];
        yield 'upper 60-second bound' => ['2026-08-10T04:00:00Z', '2026-08-10T04:01:00Z', 60_999];
        yield 'zero delta lower bound' => ['2026-08-10T04:00:00Z', '2026-08-10T04:00:00Z', 0];
        yield 'zero delta upper bound' => ['2026-08-10T04:00:00Z', '2026-08-10T04:00:00Z', 999];
    }

    #[DataProvider('invalidOrchestratorTimingProvider')]
    public function testOrchestratorWallClockRejectsValuesOutsideSecondPrecisionBounds(
        string $startedAtUtc,
        string $finishedAtUtc,
        int $wallClockMs,
    ): void {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['orchestrator_timing'] = [
            'started_at_utc' => $startedAtUtc,
            'finished_at_utc' => $finishedAtUtc,
            'wall_clock_ms' => $wallClockMs,
        ];

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateEvidence($evidence);
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function invalidOrchestratorTimingProvider(): iterable
    {
        yield 'below 60-second bound' => ['2026-08-10T04:00:00Z', '2026-08-10T04:01:00Z', 59_000];
        yield 'above 60-second bound' => ['2026-08-10T04:00:00Z', '2026-08-10T04:01:00Z', 61_000];
        yield 'same timestamp multi-hour wall clock' => ['2026-08-10T04:00:00Z', '2026-08-10T04:00:00Z', 7_200_000];
        yield 'day apart zero wall clock' => ['2026-08-10T04:00:00Z', '2026-08-11T04:00:00Z', 0];
    }

    #[DataProvider('validOrchestratorLifecycleProvider')]
    public function testOrchestratorTimingCanEncloseJournalLifecycle(
        string $startedAtUtc,
        string $finishedAtUtc,
        int $wallClockMs,
    ): void {
        $lines = $this->successfulRunLines();
        $evidence = $this->validEvidence($lines);
        $evidence['orchestrator_timing'] = [
            'started_at_utc' => $startedAtUtc,
            'finished_at_utc' => $finishedAtUtc,
            'wall_clock_ms' => $wallClockMs,
        ];

        self::assertSame('succeeded', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function validOrchestratorLifecycleProvider(): iterable
    {
        yield 'start equals first journal record' => ['2026-08-10T04:00:00Z', '2026-08-10T04:00:30Z', 30_000];
        yield 'start encloses first journal record' => ['2026-08-10T03:59:59Z', '2026-08-10T04:00:30Z', 31_000];
        yield 'finish equals terminal journal record' => ['2026-08-10T04:00:00Z', '2026-08-10T04:00:12Z', 12_000];
        yield 'finish equals evidence capture' => ['2026-08-10T04:00:00Z', '2026-08-10T04:01:00Z', 60_000];
    }

    #[DataProvider('invalidOrchestratorLifecycleProvider')]
    public function testOrchestratorTimingRejectsJournalLifecycleContradictions(
        string $startedAtUtc,
        string $finishedAtUtc,
        int $wallClockMs,
    ): void {
        $lines = $this->successfulRunLines();
        $evidence = $this->validEvidence($lines);
        $evidence['orchestrator_timing'] = [
            'started_at_utc' => $startedAtUtc,
            'finished_at_utc' => $finishedAtUtc,
            'wall_clock_ms' => $wallClockMs,
        ];

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function invalidOrchestratorLifecycleProvider(): iterable
    {
        yield 'start follows first journal record' => ['2026-08-10T04:00:01Z', '2026-08-10T04:00:30Z', 29_000];
        yield 'finish precedes terminal journal record' => ['2026-08-10T03:59:59Z', '2026-08-10T04:00:11Z', 12_000];
        yield 'finish follows evidence capture' => ['2026-08-10T03:59:59Z', '2026-08-10T04:01:01Z', 62_000];
        yield 'both timestamps follow evidence capture by years' => [
            '2027-08-10T04:00:00Z',
            '2027-08-10T04:00:01Z',
            1_000,
        ];
        yield 'both timestamps precede first journal record' => ['2026-08-10T03:59:58Z', '2026-08-10T03:59:59Z', 1_000];
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

    public function testInvalidTimingEvidenceKeepsHashWithoutInventingParsedFields(): void
    {
        $evidence = $this->validEvidence($this->successfulRunLines());
        $evidence['deploy_timing'] = [
            'status' => 'invalid',
            'authoritative_sha256' => self::SHA,
            'run_id' => null,
            'total_ms' => null,
        ];

        DeploymentContractV1::validateEvidence($evidence);
        self::assertSame('succeeded', $evidence['result']['state']);
    }

    #[DataProvider('observedDeployTimingWithoutInvocationProvider')]
    public function testFailedBeforeWriteRejectsObservedDeployTiming(array $deployTiming): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');
        $evidence['deploy_timing'] = $deployTiming;

        $this->expectException(RuntimeException::class);
        DeploymentContractV1::validateBundle($lines, $evidence);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function observedDeployTimingWithoutInvocationProvider(): iterable
    {
        yield 'valid timing' => [
            [
                'status' => 'valid',
                'authoritative_sha256' => self::SHA,
                'run_id' => self::TIMING_RUN_ID,
                'total_ms' => 124_020,
            ],
        ];
        yield 'invalid timing' => [
            [
                'status' => 'invalid',
                'authoritative_sha256' => self::SHA,
                'run_id' => null,
                'total_ms' => null,
            ],
        ];
    }

    public function testFailedBeforeWriteAcceptsUnobservedDeployTiming(): void
    {
        $lines = $this->runThrough('expected_commit_verified');
        $lines[] = $this->encode($this->transition($lines, 'failed_before_write', 0, 20, 'traffic_hard_stop'));
        $evidence = $this->failedBeforeWriteEvidence($lines, 20, 'traffic_hard_stop');

        self::assertSame('failed_before_write', DeploymentContractV1::validateBundle($lines, $evidence)['state']);
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
                'rollback_running',
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

    /** @return list<string> */
    private function rollbackRunningLines(): array
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->encode($this->transition($lines, 'rollback_running', 1));

        return $lines;
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
                'sha256_verified' => true,
                'gzip_verified' => true,
                'restore_verified' => true,
            ],
            'capacity' => [
                'status' => 'passed',
                'available_bytes' => 8_000_000_000,
                'projected_required_bytes' => 1_000_000_000,
                'observed_percent' => 81,
                'projected_percent' => 84,
                'max_used_percent' => DeploymentContractV1::MAX_CAPACITY_USED_PERCENT,
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
            'rollback' => $this->rollbackEvidence('not_invoked'),
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
                'wall_clock_ms' => 60_000,
            ],
            'result' => ['state' => 'succeeded', 'exit_code' => 0, 'reason' => 'ok'],
        ];
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function failedBeforeWriteEvidence(array $lines, int $exitCode, string $reason): array
    {
        $evidence = $this->validEvidence($lines);
        $evidence['deploy'] = [
            'status' => 'not_invoked',
            'invocation_count' => 0,
            'exit_code' => null,
            'rollback_outcome' => 'not_applicable',
        ];
        $evidence['post_gates'] = $this->notObservedSection($evidence['post_gates']);
        $evidence['deploy_timing'] = $this->notObservedSection($evidence['deploy_timing']);
        $evidence['result'] = ['state' => 'failed_before_write', 'exit_code' => $exitCode, 'reason' => $reason];

        foreach (['traffic_gate', 'dump', 'capacity', 'artifact'] as $section) {
            $evidence[$section] = $this->notObservedSection($evidence[$section]);
        }

        if (in_array($reason, ['traffic_hard_stop', 'traffic_evidence_invalid'], true)) {
            $evidence['traffic_gate'] = $this->validEvidence($lines)['traffic_gate'];
            if ($reason === 'traffic_hard_stop') {
                $evidence['traffic_gate']['counts']['documented_health'] = 0;
                $evidence['traffic_gate']['counts']['business_or_authenticated'] = 1;
                $evidence['traffic_gate']['decision'] = 'hard_stop';
                $evidence['traffic_gate']['exit_code'] = 20;
            } else {
                $evidence['traffic_gate']['counts']['lines_seen'] = 2;
                $evidence['traffic_gate']['counts']['parse_errors'] = 1;
                $evidence['traffic_gate']['parse_complete'] = false;
                $evidence['traffic_gate']['evidence_complete'] = false;
                $evidence['traffic_gate']['decision'] = 'invalid';
                $evidence['traffic_gate']['exit_code'] = 21;
            }
            $evidence['traffic_gate']['status'] = 'failed';
        }
        if (
            in_array(
                $reason,
                ['dump_verification_failed', 'capacity_gate_failed', 'artifact_verification_failed'],
                true,
            )
        ) {
            $evidence['traffic_gate'] = $this->validEvidence($lines)['traffic_gate'];
        }
        if (in_array($reason, ['capacity_gate_failed', 'artifact_verification_failed'], true)) {
            $evidence['dump'] = $this->validEvidence($lines)['dump'];
        }
        if ($reason === 'dump_verification_failed') {
            $evidence['dump'] = $this->validEvidence($lines)['dump'];
            $evidence['dump']['age_seconds'] = 14400;
            $evidence['dump']['status'] = 'failed';
        }
        if ($reason === 'capacity_gate_failed') {
            $evidence['capacity'] = $this->validEvidence($lines)['capacity'];
            $evidence['capacity']['projected_percent'] = DeploymentContractV1::MAX_CAPACITY_USED_PERCENT;
            $evidence['capacity']['passed'] = false;
            $evidence['capacity']['status'] = 'failed';
        }
        if ($reason === 'artifact_verification_failed') {
            $evidence['capacity'] = $this->validEvidence($lines)['capacity'];
            $evidence['artifact'] = $this->validEvidence($lines)['artifact'];
            $evidence['artifact']['verified'] = false;
            $evidence['artifact']['status'] = 'failed';
        }
        if ($reason === 'expected_commit_mismatch') {
            $evidence['expected_commit']['observed'] = str_repeat('c', 40);
            $evidence['expected_commit']['verified'] = false;
        }

        return $evidence;
    }

    /**
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private function invokedFailureEvidence(
        array $lines,
        string $state,
        int $publicExit,
        string $reason,
        int $deployExit,
        string $rollbackOutcome,
        string $postGateStatus,
    ): array {
        $evidence = $this->validEvidence($lines);
        $evidence['deploy'] = [
            'status' => 'failed',
            'invocation_count' => 1,
            'exit_code' => $deployExit,
            'rollback_outcome' => $rollbackOutcome,
        ];
        $terminal = json_decode($lines[array_key_last($lines)], true, 64, JSON_THROW_ON_ERROR);
        if (in_array($terminal['previous_state'], ['post_gates_running', 'rollback_running'], true)) {
            $evidence['deploy'] = $this->succeededDeployEvidence();
        }
        if ($terminal['previous_state'] === 'rollback_running') {
            if ($state === 'failed_post_switch_rollback_succeeded') {
                $evidence['rollback'] = $this->rollbackEvidence('succeeded');
            } elseif ($state === 'failed_post_switch_rollback_failed' || $reason === 'rollback_failed') {
                $evidence['rollback'] = $this->rollbackEvidence('failed');
            } elseif ($state === 'manual_recovery_required' && $reason === 'interrupted') {
                $evidence['rollback'] = $this->rollbackEvidence('unknown');
            }
        }
        if ($postGateStatus === 'not_observed') {
            $evidence['post_gates'] = $this->notObservedSection($evidence['post_gates']);
        } else {
            $evidence['post_gates']['logs_passed'] = false;
            $evidence['post_gates']['passed'] = false;
            $evidence['post_gates']['status'] = 'failed';
        }
        $evidence['deploy_timing'] = $this->notObservedSection($evidence['deploy_timing']);
        $evidence['result'] = ['state' => $state, 'exit_code' => $publicExit, 'reason' => $reason];

        return $evidence;
    }

    /** @return array{status:string,invocation_count:int,exit_code:null,rollback_outcome:string} */
    private function unknownInvokedDeployEvidence(): array
    {
        return [
            'status' => 'unknown',
            'invocation_count' => 1,
            'exit_code' => null,
            'rollback_outcome' => 'not_observed',
        ];
    }

    /** @return array{status:string,invocation_count:int,exit_code:int,rollback_outcome:string} */
    private function succeededDeployEvidence(): array
    {
        return [
            'status' => 'succeeded',
            'invocation_count' => 1,
            'exit_code' => 0,
            'rollback_outcome' => 'not_run',
        ];
    }

    /** @return array{status:string,invocation_count:int,mode:string,verified:?bool} */
    private function rollbackEvidence(string $status): array
    {
        return match ($status) {
            'not_invoked' => [
                'status' => 'not_invoked',
                'invocation_count' => 0,
                'mode' => 'not_applicable',
                'verified' => null,
            ],
            'unknown' => [
                'status' => 'unknown',
                'invocation_count' => 1,
                'mode' => 'dedicated_post_gate_recovery',
                'verified' => null,
            ],
            'succeeded' => [
                'status' => 'succeeded',
                'invocation_count' => 1,
                'mode' => 'dedicated_post_gate_recovery',
                'verified' => true,
            ],
            'failed' => [
                'status' => 'failed',
                'invocation_count' => 1,
                'mode' => 'dedicated_post_gate_recovery',
                'verified' => false,
            ],
            default => throw new RuntimeException('unknown rollback test status'),
        };
    }

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private function notObservedSection(array $section): array
    {
        foreach ($section as $field => $_value) {
            $section[$field] = $field === 'status' ? 'not_observed' : null;
        }

        return $section;
    }

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private function incompletePostGateEvidence(array $section): array
    {
        $section = $this->notObservedSection($section);
        $section['status'] = 'incomplete';

        return $section;
    }

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private function invalidDumpEvidence(array $section): array
    {
        $section = $this->notObservedSection($section);
        $section['status'] = 'invalid';
        $section['policy'] = DeploymentContractV1::DUMP_POLICY;
        $section['max_age_seconds'] = 14400;

        return $section;
    }

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private function invalidArtifactEvidence(array $section): array
    {
        $section = $this->notObservedSection($section);
        $section['status'] = 'invalid';
        $section['expectation'] = DeploymentContractV1::ARTIFACT_EXPECTATION;

        return $section;
    }

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private function invalidCapacityEvidence(array $section): array
    {
        $section = $this->notObservedSection($section);
        $section['status'] = 'invalid';
        $section['max_used_percent'] = DeploymentContractV1::MAX_CAPACITY_USED_PERCENT;

        return $section;
    }

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private function invalidTrafficReportEvidence(array $section, ?string $reportSha256): array
    {
        $section = $this->notObservedSection($section);
        $section['status'] = 'invalid';
        $section['report_sha256'] = $reportSha256;
        $section['exit_code'] = 21;

        return $section;
    }

    /** @param list<string> $arguments @return array{int,string,string} */
    private function runCli(array $arguments): array
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 3) . '/scripts/ops/validate_deployment_contract_v1.php',
            ...$arguments,
        ];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return DeploymentContractV1::canonicalJson($value);
    }
}

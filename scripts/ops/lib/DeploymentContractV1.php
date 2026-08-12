<?php

declare(strict_types=1);

namespace Ops;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class DeploymentContractV1
{
    public const RUN_SCHEMA = 'deployment_run.v1';
    public const EVIDENCE_SCHEMA = 'deployment_evidence.v1';
    public const DUMP_POLICY = 'fresh_verified_under_240m';
    public const ARTIFACT_EXPECTATION = 'build_from_expected_commit';
    public const MAX_CAPACITY_USED_PERCENT = 85;

    public const PROGRESS_STATES = [
        'planned',
        'built',
        'uploaded',
        'accepted',
        'lock_acquired',
        'expected_commit_verified',
        'traffic_gate_passed',
        'dump_verified',
        'capacity_passed',
        'artifact_verified',
        'deploy_running',
        'post_gates_running',
        'succeeded',
    ];

    public const ROLLBACK_RESERVATION_STATE = 'rollback_running';

    public const TERMINAL_FAILURE_STATES = [
        'failed_before_write',
        'failed_pre_switch',
        'failed_switch_recovery_required',
        'failed_post_switch_rollback_succeeded',
        'failed_post_switch_rollback_failed',
        'manual_recovery_required',
    ];

    public const EXIT_REASONS = [
        'ok' => 0,
        'traffic_hard_stop' => 20,
        'traffic_evidence_invalid' => 21,
        'dump_verification_failed' => 22,
        'capacity_gate_failed' => 23,
        'artifact_verification_failed' => 24,
        'expected_commit_mismatch' => 25,
        'deploy_failed' => 30,
        'rollback_failed' => 31,
        'switch_recovery_required' => 32,
        'contract_invalid' => 70,
        'state_conflict' => 75,
        'interrupted' => 143,
    ];

    public const TRAFFIC_COUNT_KEYS = [
        'documented_health',
        'documented_periodic_ops',
        'denied_external',
        'public_read',
        'business_or_authenticated',
        'unclassified',
        'total',
        'lines_seen',
        'lines_in_window',
        'parse_errors',
        'source_unknown',
        'method_unknown',
        'target_unknown',
        'status_5xx',
        'write',
        'authenticated',
        'customers_or_sensitive',
        'scanner_success',
        'pre_window_completion',
        'rotation_errors',
    ];

    private const INTENT_KEYS = [
        'schema',
        'record_type',
        'run_id',
        'sequence',
        'recorded_at_utc',
        'state',
        'deploy_invocation_count',
        'expected_commit',
        'release_id',
        'traffic_mode',
        'dump_policy',
        'artifact_expectation',
        'intent_sha256',
        'exit_code',
        'reason',
    ];

    private const TRANSITION_KEYS = [
        'schema',
        'record_type',
        'run_id',
        'sequence',
        'recorded_at_utc',
        'previous_state',
        'state',
        'deploy_invocation_count',
        'intent_sha256',
        'exit_code',
        'reason',
    ];

    private const EVIDENCE_KEYS = [
        'schema',
        'run_id',
        'intent_sha256',
        'captured_at_utc',
        'expected_commit',
        'traffic_gate',
        'dump',
        'capacity',
        'artifact',
        'deploy',
        'rollback',
        'post_gates',
        'deploy_timing',
        'orchestrator_timing',
        'result',
    ];

    /** @return array<string,mixed> */
    public static function createIntentRecord(
        string $runId,
        string $recordedAtUtc,
        string $expectedCommit,
        string $releaseId,
        string $trafficMode,
        string $dumpPolicy = self::DUMP_POLICY,
        string $artifactExpectation = self::ARTIFACT_EXPECTATION,
    ): array {
        $fields = [
            'expected_commit' => $expectedCommit,
            'release_id' => $releaseId,
            'traffic_mode' => $trafficMode,
            'dump_policy' => $dumpPolicy,
            'artifact_expectation' => $artifactExpectation,
        ];

        $record = [
            'schema' => self::RUN_SCHEMA,
            'record_type' => 'intent',
            'run_id' => $runId,
            'sequence' => 1,
            'recorded_at_utc' => $recordedAtUtc,
            'state' => 'planned',
            'deploy_invocation_count' => 0,
            ...$fields,
            'intent_sha256' => self::canonicalSha256($fields),
            'exit_code' => 0,
            'reason' => 'ok',
        ];
        self::validateIntentRecord($record);

        return $record;
    }

    /** @param array<string,mixed> $record */
    public static function validateIntentRecord(array $record): void
    {
        self::assertExactKeys($record, self::INTENT_KEYS, 'intent record');
        self::assertSame($record['schema'], self::RUN_SCHEMA, 'intent schema');
        self::assertSame($record['record_type'], 'intent', 'intent record_type');
        self::assertUuidV4($record['run_id'], 'run_id');
        self::assertSame($record['sequence'], 1, 'intent sequence');
        self::assertUtc($record['recorded_at_utc'], 'recorded_at_utc');
        self::assertSame($record['state'], 'planned', 'intent state');
        self::assertSame($record['deploy_invocation_count'], 0, 'intent deploy_invocation_count');
        self::assertCommit($record['expected_commit'], 'expected_commit');
        self::assertReleaseId($record['release_id']);
        self::assertEnum($record['traffic_mode'], ['normal', 'no-business-traffic'], 'traffic_mode');
        self::assertSame($record['dump_policy'], self::DUMP_POLICY, 'dump_policy');
        self::assertSame($record['artifact_expectation'], self::ARTIFACT_EXPECTATION, 'artifact_expectation');
        self::assertSha256($record['intent_sha256'], 'intent_sha256');
        self::assertSame($record['exit_code'], 0, 'intent exit_code');
        self::assertSame($record['reason'], 'ok', 'intent reason');

        $expected = self::canonicalSha256([
            'expected_commit' => $record['expected_commit'],
            'release_id' => $record['release_id'],
            'traffic_mode' => $record['traffic_mode'],
            'dump_policy' => $record['dump_policy'],
            'artifact_expectation' => $record['artifact_expectation'],
        ]);
        if (!hash_equals($expected, $record['intent_sha256'])) {
            throw new RuntimeException('intent_sha256 does not bind the immutable intent');
        }
    }

    /**
     * @param list<string> $lines
     * @return array{run_id:string,intent_sha256:string,state:string,deploy_invocation_count:int,records:int,recovery:string}
     */
    public static function validateRunLines(array $lines): array
    {
        if ($lines === []) {
            throw new RuntimeException('deployment run is empty');
        }

        $records = [];
        foreach ($lines as $index => $line) {
            if (!is_string($line) || $line === '') {
                throw new RuntimeException('deployment run contains an empty record');
            }
            try {
                $decoded = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException(sprintf('deployment run record %d is corrupt JSON', $index + 1));
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException(sprintf('deployment run record %d must be an object', $index + 1));
            }
            if ($line !== self::canonicalJson($decoded)) {
                throw new RuntimeException(sprintf('deployment run record %d is not canonical JSON', $index + 1));
            }
            $records[] = $decoded;
        }

        self::validateIntentRecord($records[0]);
        $runId = $records[0]['run_id'];
        $intentSha256 = $records[0]['intent_sha256'];
        $previousState = 'planned';
        $invocationCount = 0;
        $previousEpoch = self::utcEpoch($records[0]['recorded_at_utc']);

        foreach (array_slice($records, 1) as $offset => $record) {
            $sequence = $offset + 2;
            self::assertExactKeys($record, self::TRANSITION_KEYS, 'transition record');
            self::assertSame($record['schema'], self::RUN_SCHEMA, 'transition schema');
            self::assertSame($record['record_type'], 'transition', 'transition record_type');
            self::assertSame($record['run_id'], $runId, 'run_id');
            self::assertSame($record['intent_sha256'], $intentSha256, 'intent_sha256');
            self::assertSame($record['sequence'], $sequence, 'transition sequence');
            self::assertUtc($record['recorded_at_utc'], 'recorded_at_utc');
            $epoch = self::utcEpoch($record['recorded_at_utc']);
            if ($epoch < $previousEpoch) {
                throw new RuntimeException('recorded_at_utc is not monotonic');
            }
            if (self::isTerminal($previousState)) {
                throw new RuntimeException('terminal deployment state is immutable');
            }
            self::assertSame($record['previous_state'], $previousState, 'previous_state');
            self::assertStateTransition($previousState, $record['state']);
            self::assertInvocationCount($record['state'], $record['deploy_invocation_count'], $invocationCount);
            self::assertResultCode($record['state'], $record['exit_code'], $record['reason']);
            self::assertFailureReasonMatchesPreviousState($previousState, $record['state'], $record['reason']);
            $previousState = $record['state'];
            $invocationCount = $record['deploy_invocation_count'];
            $previousEpoch = $epoch;
        }

        return [
            'run_id' => $runId,
            'intent_sha256' => $intentSha256,
            'state' => $previousState,
            'deploy_invocation_count' => $invocationCount,
            'records' => count($records),
            'recovery' => self::recoveryClassification($previousState, $invocationCount),
        ];
    }

    /**
     * @param list<string> $existingLines
     * @param array<string,mixed> $candidateIntent
     */
    public static function attachmentDecision(array $existingLines, array $candidateIntent): string
    {
        $existing = self::validateRunLines($existingLines);
        self::validateIntentRecord($candidateIntent);
        if ($candidateIntent['run_id'] !== $existing['run_id']) {
            throw new RuntimeException('run_id does not identify the existing logical deployment intent');
        }
        if (!hash_equals($existing['intent_sha256'], $candidateIntent['intent_sha256'])) {
            throw new RuntimeException('run_id already exists with a different intent_sha256');
        }

        return $existing['recovery'];
    }

    /** @param array<string,mixed> $evidence */
    public static function validateEvidence(array $evidence): void
    {
        self::assertExactKeys($evidence, self::EVIDENCE_KEYS, 'deployment evidence');
        self::assertSame($evidence['schema'], self::EVIDENCE_SCHEMA, 'evidence schema');
        self::assertUuidV4($evidence['run_id'], 'run_id');
        self::assertSha256($evidence['intent_sha256'], 'intent_sha256');
        self::assertUtc($evidence['captured_at_utc'], 'captured_at_utc');
        self::validateExpectedCommitEvidence($evidence['expected_commit']);
        self::validateTrafficEvidence($evidence['traffic_gate']);
        self::validateDumpEvidence($evidence['dump']);
        self::validateCapacityEvidence($evidence['capacity']);
        self::validateArtifactEvidence($evidence['artifact']);
        self::validateDeployEvidence($evidence['deploy']);
        self::validateRollbackEvidence($evidence['rollback']);
        self::validatePostGateEvidence($evidence['post_gates']);
        self::validateDeployTimingEvidence($evidence['deploy_timing']);
        self::validateOrchestratorTiming($evidence['orchestrator_timing']);

        self::assertObject($evidence['result'], 'result');
        self::assertExactKeys($evidence['result'], ['state', 'exit_code', 'reason'], 'result');
        self::assertTerminalState($evidence['result']['state']);
        self::assertResultCode(
            $evidence['result']['state'],
            $evidence['result']['exit_code'],
            $evidence['result']['reason'],
        );
        self::assertDeployResultConsistency(
            $evidence['result']['state'],
            $evidence['result']['reason'],
            $evidence['deploy'],
        );
        self::assertRollbackResultConsistency(
            $evidence['result']['state'],
            $evidence['result']['reason'],
            $evidence['rollback'],
        );
        self::assertTerminalEvidenceConsistency($evidence);
        if (
            $evidence['deploy_timing']['status'] !== 'not_observed' &&
            $evidence['deploy_timing']['run_id'] === $evidence['run_id']
        ) {
            throw new RuntimeException('deploy timing Run-ID must remain separate from the orchestrator Run-ID');
        }
    }

    /**
     * Validate the five ordered pre-deploy evidence sections without accepting
     * them as a terminal bundle. Authority collectors use this at their closed
     * assembly boundary; callers cannot use it to turn a failed section into a
     * passed one because each section is still fully recomputed below.
     *
     * @param array<string,mixed> $sections
     */
    public static function validatePredeploySections(array $sections): void
    {
        self::assertExactKeys(
            $sections,
            ['expected_commit', 'traffic_gate', 'dump', 'capacity', 'artifact'],
            'predeploy evidence sections',
        );
        self::validateExpectedCommitEvidence($sections['expected_commit']);
        self::validateTrafficEvidence($sections['traffic_gate']);
        self::validateDumpEvidence($sections['dump']);
        self::validateCapacityEvidence($sections['capacity']);
        self::validateArtifactEvidence($sections['artifact']);
    }

    /**
     * @param list<string> $runLines
     * @param array<string,mixed> $evidence
     * @return array{run_id:string,state:string,records:int,recovery:string,evidence_sha256:string}
     */
    public static function validateBundle(array $runLines, array $evidence): array
    {
        $run = self::validateRunLines($runLines);
        self::validateEvidence($evidence);
        if (!self::isTerminal($run['state'])) {
            throw new RuntimeException('deployment evidence may only bind a terminal run');
        }
        self::assertSame($evidence['run_id'], $run['run_id'], 'evidence run_id');
        self::assertSame($evidence['intent_sha256'], $run['intent_sha256'], 'evidence intent_sha256');
        self::assertSame($evidence['result']['state'], $run['state'], 'evidence result state');

        $intent = json_decode($runLines[0], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame($evidence['expected_commit']['expected'], $intent['expected_commit'], 'bound expected_commit');
        if (in_array($evidence['traffic_gate']['status'], ['passed', 'failed'], true)) {
            self::assertSame($evidence['traffic_gate']['mode'], $intent['traffic_mode'], 'bound traffic mode');
        }
        if ($evidence['dump']['status'] !== 'not_observed') {
            self::assertSame($evidence['dump']['policy'], $intent['dump_policy'], 'bound dump policy');
        }
        if ($evidence['artifact']['status'] !== 'not_observed') {
            self::assertSame(
                $evidence['artifact']['expectation'],
                $intent['artifact_expectation'],
                'bound artifact expectation',
            );
        }

        $last = json_decode($runLines[array_key_last($runLines)], true, 64, JSON_THROW_ON_ERROR);
        if (self::utcEpoch($evidence['captured_at_utc']) < self::utcEpoch($last['recorded_at_utc'])) {
            throw new RuntimeException('evidence capture precedes the terminal journal record');
        }
        self::assertOrchestratorTimingMatchesLifecycle(
            $evidence['orchestrator_timing'],
            $intent['recorded_at_utc'],
            $last['recorded_at_utc'],
            $evidence['captured_at_utc'],
        );
        self::assertFailureTransitionMatchesEvidence($last, $evidence);
        self::assertSame($evidence['result']['exit_code'], $last['exit_code'], 'evidence exit_code');
        self::assertSame($evidence['result']['reason'], $last['reason'], 'evidence reason');
        self::assertSame(
            $evidence['deploy']['invocation_count'],
            $run['deploy_invocation_count'],
            'evidence invocation_count',
        );

        return [
            'run_id' => $run['run_id'],
            'state' => $run['state'],
            'records' => $run['records'],
            'recovery' => $run['recovery'],
            'evidence_sha256' => self::canonicalSha256($evidence),
        ];
    }

    /** @param array<string,mixed> $value */
    public static function canonicalSha256(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    /** @param array<string,mixed> $value */
    public static function canonicalJson(array $value): string
    {
        try {
            return json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('value cannot be canonically serialized');
        }
    }

    private static function assertStateTransition(string $previous, mixed $next): void
    {
        if (
            !is_string($next) ||
            !in_array(
                $next,
                [...self::PROGRESS_STATES, self::ROLLBACK_RESERVATION_STATE, ...self::TERMINAL_FAILURE_STATES],
                true,
            )
        ) {
            throw new RuntimeException('state is unknown');
        }
        if ($previous === self::ROLLBACK_RESERVATION_STATE) {
            if (
                in_array(
                    $next,
                    [
                        'failed_post_switch_rollback_succeeded',
                        'failed_post_switch_rollback_failed',
                        'manual_recovery_required',
                    ],
                    true,
                )
            ) {
                return;
            }
            throw new RuntimeException('failure state is incompatible with the rollback reservation phase');
        }
        $index = array_search($previous, self::PROGRESS_STATES, true);
        if (!is_int($index)) {
            throw new RuntimeException('previous state is not resumable');
        }
        $expectedNext = self::PROGRESS_STATES[$index + 1] ?? null;
        if ($next === $expectedNext) {
            return;
        }
        if ($previous === 'post_gates_running' && $next === self::ROLLBACK_RESERVATION_STATE) {
            return;
        }
        if (!in_array($next, self::TERMINAL_FAILURE_STATES, true)) {
            throw new RuntimeException('deployment state transition skips or reorders a required state');
        }
        $beforeInvocation = $index < array_search('deploy_running', self::PROGRESS_STATES, true);
        if ($next === 'failed_before_write' && $beforeInvocation) {
            return;
        }
        if ($next === 'failed_pre_switch' && $previous === 'deploy_running') {
            return;
        }
        if ($next === 'failed_switch_recovery_required' && $previous === 'deploy_running') {
            return;
        }
        if (
            in_array($next, ['failed_post_switch_rollback_succeeded', 'failed_post_switch_rollback_failed'], true) &&
            $previous === 'deploy_running'
        ) {
            return;
        }
        if (
            $next === 'manual_recovery_required' &&
            in_array($previous, ['deploy_running', 'post_gates_running'], true)
        ) {
            return;
        }
        throw new RuntimeException('failure state is incompatible with the current deployment phase');
    }

    private static function assertInvocationCount(string $state, mixed $count, int $previous): void
    {
        if (!is_int($count) || $count < 0 || $count > 1 || $count < $previous) {
            throw new RuntimeException('deploy_invocation_count must be monotonic and at most one');
        }
        $expectsOne = in_array(
            $state,
            [
                'deploy_running',
                'post_gates_running',
                self::ROLLBACK_RESERVATION_STATE,
                'succeeded',
                'failed_pre_switch',
                'failed_switch_recovery_required',
                'failed_post_switch_rollback_succeeded',
                'failed_post_switch_rollback_failed',
                'manual_recovery_required',
            ],
            true,
        );
        if (($expectsOne && $count !== 1) || (!$expectsOne && $count !== 0)) {
            throw new RuntimeException('deploy_invocation_count does not match the deployment state');
        }
    }

    private static function assertResultCode(mixed $state, mixed $exitCode, mixed $reason): void
    {
        if (!is_string($state) || !is_int($exitCode) || !is_string($reason)) {
            throw new RuntimeException('state result has an invalid type');
        }
        if (!array_key_exists($reason, self::EXIT_REASONS) || self::EXIT_REASONS[$reason] !== $exitCode) {
            throw new RuntimeException('exit_code and reason are not a stable pair');
        }
        if (
            (in_array($state, self::PROGRESS_STATES, true) || $state === self::ROLLBACK_RESERVATION_STATE) &&
            ($exitCode !== 0 || $reason !== 'ok')
        ) {
            throw new RuntimeException('non-terminal states cannot carry a failure result');
        }
        $allowed = match ($state) {
            'failed_before_write' => [20, 21, 22, 23, 24, 25, 70, 75, 143],
            'failed_pre_switch' => [30, 143],
            'failed_switch_recovery_required' => [32],
            'failed_post_switch_rollback_succeeded' => [30],
            'failed_post_switch_rollback_failed' => [31],
            'manual_recovery_required' => [31, 70, 143],
            default => [0],
        };
        if (!in_array($exitCode, $allowed, true)) {
            throw new RuntimeException('exit_code is incompatible with the deployment state');
        }
    }

    private static function assertFailureReasonMatchesPreviousState(
        string $previous,
        string $state,
        string $reason,
    ): void {
        if ($state === 'manual_recovery_required' && $reason === 'contract_invalid' && $previous !== 'deploy_running') {
            throw new RuntimeException('rejected child result requires the deploy-running phase');
        }
        if (
            $previous === 'post_gates_running' &&
            $state === 'manual_recovery_required' &&
            $reason === 'rollback_failed'
        ) {
            throw new RuntimeException('rollback failure requires a durable rollback reservation');
        }
        if ($state !== 'failed_before_write') {
            return;
        }
        $requiredPrevious = self::requiredPreviousStateForFailureReason($reason);
        if ($requiredPrevious !== null && $previous !== $requiredPrevious) {
            throw new RuntimeException('failure transition does not match its claimed gate');
        }
    }

    private static function requiredPreviousStateForFailureReason(string $reason): ?string
    {
        return match ($reason) {
            'traffic_hard_stop', 'traffic_evidence_invalid' => 'expected_commit_verified',
            'dump_verification_failed' => 'traffic_gate_passed',
            'capacity_gate_failed' => 'dump_verified',
            'artifact_verification_failed' => 'capacity_passed',
            'expected_commit_mismatch' => 'lock_acquired',
            default => null,
        };
    }

    private static function recoveryClassification(string $state, int $invocationCount): string
    {
        if (self::isTerminal($state)) {
            return 'terminal';
        }
        if ($invocationCount === 1) {
            return 'attach_observe_only';
        }

        return 'attach_pre_deploy';
    }

    private static function isTerminal(string $state): bool
    {
        return $state === 'succeeded' || in_array($state, self::TERMINAL_FAILURE_STATES, true);
    }

    private static function assertTerminalState(mixed $state): void
    {
        if (!is_string($state) || !self::isTerminal($state)) {
            throw new RuntimeException('result state must be terminal');
        }
    }

    private static function validateExpectedCommitEvidence(mixed $section): void
    {
        self::assertObject($section, 'expected_commit');
        self::assertExactKeys($section, ['expected', 'observed', 'verified'], 'expected_commit');
        self::assertCommit($section['expected'], 'expected_commit.expected');
        if ($section['observed'] !== null) {
            self::assertCommit($section['observed'], 'expected_commit.observed');
        }
        self::assertBoolean($section['verified'], 'expected_commit.verified');
        $matches = $section['observed'] !== null && hash_equals($section['expected'], $section['observed']);
        if ($section['verified'] !== $matches) {
            throw new RuntimeException('expected commit evidence is inconsistent');
        }
    }

    /** @param array<string,mixed> $evidence */
    private static function assertTerminalEvidenceConsistency(array $evidence): void
    {
        $state = $evidence['result']['state'];
        $reason = $evidence['result']['reason'];
        if ($state === 'succeeded') {
            self::assertEveryPreGatePassed($evidence);
            self::assertSame($evidence['post_gates']['status'], 'passed', 'successful post-gate evidence');
            self::assertSame($evidence['deploy']['status'], 'succeeded', 'successful deploy evidence');
            return;
        }
        if ($state === 'failed_before_write') {
            self::assertFailedBeforeWriteEvidence($reason, $evidence);
            self::assertSame($evidence['post_gates']['status'], 'not_observed', 'pre-write post-gate evidence');
            self::assertSame($evidence['deploy_timing']['status'], 'not_observed', 'pre-write deploy timing evidence');
            return;
        }

        self::assertEveryPreGatePassed($evidence);
        if (
            in_array(
                $state,
                [
                    'failed_post_switch_rollback_succeeded',
                    'failed_post_switch_rollback_failed',
                    'manual_recovery_required',
                ],
                true,
            )
        ) {
            $allowedPostGateStatuses = ['not_observed', 'failed'];
            if ($state === 'manual_recovery_required' && $reason === 'interrupted') {
                $allowedPostGateStatuses = array_merge($allowedPostGateStatuses, ['incomplete', 'passed']);
            }
            if (!in_array($evidence['post_gates']['status'], $allowedPostGateStatuses, true)) {
                throw new RuntimeException('failure evidence cannot claim passed post-gates');
            }
        } else {
            self::assertSame($evidence['post_gates']['status'], 'not_observed', 'pre-post-gate failure evidence');
        }
    }

    /** @param array<string,mixed> $evidence */
    private static function assertFailedBeforeWriteEvidence(string $reason, array $evidence): void
    {
        $expectedStatuses = match ($reason) {
            'traffic_hard_stop', 'traffic_evidence_invalid' => [
                true,
                'failed',
                'not_observed',
                'not_observed',
                'not_observed',
            ],
            'dump_verification_failed' => [true, 'passed', 'failed', 'not_observed', 'not_observed'],
            'capacity_gate_failed' => [true, 'passed', 'passed', 'failed', 'not_observed'],
            'artifact_verification_failed' => [true, 'passed', 'passed', 'passed', 'failed'],
            'expected_commit_mismatch' => [false, 'not_observed', 'not_observed', 'not_observed', 'not_observed'],
            'contract_invalid', 'state_conflict', 'interrupted' => null,
            default => throw new RuntimeException('failed-before-write reason is unknown'),
        };

        if ($reason === 'expected_commit_mismatch') {
            if (
                $evidence['expected_commit']['observed'] === null ||
                hash_equals($evidence['expected_commit']['expected'], $evidence['expected_commit']['observed'])
            ) {
                throw new RuntimeException('expected commit evidence is inconsistent with mismatch failure');
            }
        }
        if ($expectedStatuses === null) {
            foreach (['traffic_gate', 'dump', 'capacity', 'artifact'] as $section) {
                if (in_array($evidence[$section]['status'], ['failed', 'invalid'], true)) {
                    throw new RuntimeException(
                        'generic pre-write failure evidence cannot claim a different gate failure',
                    );
                }
            }
            return;
        }

        $trafficStatus = $evidence['traffic_gate']['status'];
        if ($reason === 'traffic_evidence_invalid' && $trafficStatus === 'invalid') {
            $trafficStatus = 'failed';
        }
        $dumpStatus = $evidence['dump']['status'];
        if ($reason === 'dump_verification_failed' && $dumpStatus === 'invalid') {
            $dumpStatus = 'failed';
        }
        $capacityStatus = $evidence['capacity']['status'];
        if ($reason === 'capacity_gate_failed' && $capacityStatus === 'invalid') {
            $capacityStatus = 'failed';
        }
        $artifactStatus = $evidence['artifact']['status'];
        if ($reason === 'artifact_verification_failed' && $artifactStatus === 'invalid') {
            $artifactStatus = 'failed';
        }
        $actualStatuses = [
            $evidence['expected_commit']['verified'],
            $trafficStatus,
            $dumpStatus,
            $capacityStatus,
            $artifactStatus,
        ];
        if ($actualStatuses !== $expectedStatuses) {
            throw new RuntimeException('failed-before-write result lacks matching failure evidence');
        }
        if (
            ($reason === 'traffic_hard_stop' &&
                ($evidence['traffic_gate']['decision'] !== 'hard_stop' ||
                    $evidence['traffic_gate']['exit_code'] !== 20)) ||
            ($reason === 'traffic_evidence_invalid' &&
                (($evidence['traffic_gate']['status'] === 'failed' &&
                    ($evidence['traffic_gate']['decision'] !== 'invalid' ||
                        $evidence['traffic_gate']['exit_code'] !== 21)) ||
                    ($evidence['traffic_gate']['status'] === 'invalid' &&
                        $evidence['traffic_gate']['exit_code'] !== 21)))
        ) {
            throw new RuntimeException('traffic failure evidence does not match its public result');
        }
    }

    /** @param array<string,mixed> $evidence */
    private static function assertEveryPreGatePassed(array $evidence): void
    {
        $statuses = [
            $evidence['expected_commit']['verified'],
            $evidence['traffic_gate']['status'],
            $evidence['dump']['status'],
            $evidence['capacity']['status'],
            $evidence['artifact']['status'],
        ];
        if ($statuses !== [true, 'passed', 'passed', 'passed', 'passed']) {
            throw new RuntimeException('terminal evidence requires every pre-deploy gate to pass');
        }
    }

    /** @param array<string,mixed> $last @param array<string,mixed> $evidence */
    private static function assertFailureTransitionMatchesEvidence(array $last, array $evidence): void
    {
        if (
            in_array(
                $last['state'],
                [
                    'failed_post_switch_rollback_succeeded',
                    'failed_post_switch_rollback_failed',
                    'manual_recovery_required',
                ],
                true,
            )
        ) {
            $actualDeploy = [
                $evidence['deploy']['status'],
                $evidence['deploy']['invocation_count'],
                $evidence['deploy']['exit_code'],
                $evidence['deploy']['rollback_outcome'],
            ];
            $actualRollback = [
                $evidence['rollback']['status'],
                $evidence['rollback']['invocation_count'],
                $evidence['rollback']['mode'],
                $evidence['rollback']['verified'],
            ];
            $notInvokedRollback = ['not_invoked', 0, 'not_applicable', null];
            if ($last['previous_state'] === 'deploy_running') {
                $expectedDeploy = match ($last['state']) {
                    'failed_post_switch_rollback_succeeded' => ['failed', 1, 30, 'succeeded'],
                    'failed_post_switch_rollback_failed' => ['failed', 1, 31, 'failed'],
                    'manual_recovery_required' => match ($last['reason']) {
                        'interrupted', 'contract_invalid' => ['unknown', 1, null, 'not_observed'],
                        default => ['failed', 1, 31, 'recovery_required'],
                    },
                };
                if ($actualDeploy !== $expectedDeploy || $actualRollback !== $notInvokedRollback) {
                    throw new RuntimeException(
                        'deploy or rollback evidence does not match the failure transition phase',
                    );
                }
            } elseif ($last['previous_state'] === 'post_gates_running') {
                if ($actualDeploy !== ['succeeded', 1, 0, 'not_run']) {
                    throw new RuntimeException('deploy evidence does not match the post-gate transition phase');
                }
                if (
                    $last['state'] !== 'manual_recovery_required' ||
                    $last['reason'] !== 'interrupted' ||
                    $actualRollback !== $notInvokedRollback
                ) {
                    throw new RuntimeException('rollback evidence does not match the pre-reservation transition phase');
                }
            } else {
                if ($actualDeploy !== ['succeeded', 1, 0, 'not_run']) {
                    throw new RuntimeException('deploy evidence does not match the rollback reservation phase');
                }
                $allowedRollback = match ($last['state']) {
                    'failed_post_switch_rollback_succeeded' => [['succeeded', 1, 'dedicated_post_gate_recovery', true]],
                    'failed_post_switch_rollback_failed' => [['failed', 1, 'dedicated_post_gate_recovery', false]],
                    'manual_recovery_required' => $last['reason'] === 'interrupted'
                        ? [['unknown', 1, 'dedicated_post_gate_recovery', null]]
                        : [['failed', 1, 'dedicated_post_gate_recovery', false]],
                };
                if (!in_array($actualRollback, $allowedRollback, true)) {
                    throw new RuntimeException('rollback evidence does not match the rollback reservation phase');
                }
            }

            $allowedPostGateStatuses = in_array(
                $last['previous_state'],
                ['post_gates_running', self::ROLLBACK_RESERVATION_STATE],
                true,
            )
                ? ['failed']
                : ['not_observed'];
            if (
                $last['state'] === 'manual_recovery_required' &&
                $last['reason'] === 'interrupted' &&
                $last['previous_state'] === 'post_gates_running'
            ) {
                $allowedPostGateStatuses = array_merge($allowedPostGateStatuses, ['incomplete', 'passed']);
            }
            if (!in_array($evidence['post_gates']['status'], $allowedPostGateStatuses, true)) {
                throw new RuntimeException('post-gate evidence does not match the failure transition phase');
            }
            return;
        }
        if ($last['state'] !== 'failed_before_write') {
            return;
        }
        $requiredPrevious = self::requiredPreviousStateForFailureReason($last['reason']);
        if ($requiredPrevious !== null && $last['previous_state'] !== $requiredPrevious) {
            throw new RuntimeException('failure transition does not match its claimed gate evidence');
        }

        $previousIndex = array_search($last['previous_state'], self::PROGRESS_STATES, true);
        if (!is_int($previousIndex)) {
            throw new RuntimeException('failure transition previous state is unknown');
        }
        $requiredPassed = [
            'expected_commit_verified' => $evidence['expected_commit']['verified'] === true,
            'traffic_gate_passed' => $evidence['traffic_gate']['status'] === 'passed',
            'dump_verified' => $evidence['dump']['status'] === 'passed',
            'capacity_passed' => $evidence['capacity']['status'] === 'passed',
            'artifact_verified' => $evidence['artifact']['status'] === 'passed',
        ];
        foreach ($requiredPassed as $state => $passed) {
            $stateIndex = array_search($state, self::PROGRESS_STATES, true);
            if (is_int($stateIndex) && $previousIndex >= $stateIndex && !$passed) {
                throw new RuntimeException('failure evidence does not cover the last verified deployment state');
            }
        }
        if (!in_array($last['reason'], ['contract_invalid', 'state_conflict', 'interrupted'], true)) {
            return;
        }
        foreach ($requiredPassed as $state => $_passed) {
            $stateIndex = array_search($state, self::PROGRESS_STATES, true);
            if (!is_int($stateIndex) || $previousIndex >= $stateIndex) {
                continue;
            }
            if ($state === 'expected_commit_verified') {
                if ($evidence['expected_commit']['verified'] || $evidence['expected_commit']['observed'] !== null) {
                    throw new RuntimeException(
                        'failure evidence claims success beyond the last verified deployment state',
                    );
                }
                continue;
            }
            $section = match ($state) {
                'traffic_gate_passed' => 'traffic_gate',
                'dump_verified' => 'dump',
                'capacity_passed' => 'capacity',
                'artifact_verified' => 'artifact',
                default => throw new RuntimeException('failure evidence state is unknown'),
            };
            if ($evidence[$section]['status'] !== 'not_observed') {
                throw new RuntimeException('failure evidence claims success beyond the last verified deployment state');
            }
        }
    }

    private static function validateTrafficEvidence(mixed $section): void
    {
        self::assertObject($section, 'traffic_gate');
        $keys = [
            'status',
            'report_sha256',
            'schema',
            'producer_sha256',
            'policy_version',
            'catalog_version',
            'purpose',
            'mode',
            'window_start_epoch',
            'window_end_epoch',
            'window_seconds',
            'log_set_sha256',
            'rotation_complete',
            'parse_complete',
            'evidence_complete',
            'decision',
            'exit_code',
            'counts',
        ];
        self::assertExactKeys($section, $keys, 'traffic_gate');
        self::assertEnum($section['status'], ['not_observed', 'passed', 'failed', 'invalid'], 'traffic_gate.status');
        if ($section['status'] === 'not_observed') {
            self::assertAllNullExcept($section, ['status'], 'traffic_gate');
            return;
        }
        if ($section['status'] === 'invalid') {
            if ($section['report_sha256'] !== null) {
                self::assertSha256($section['report_sha256'], 'traffic_gate.report_sha256');
            }
            self::assertSame($section['exit_code'], 21, 'traffic_gate.exit_code');
            self::assertAllNullExcept($section, ['status', 'report_sha256', 'exit_code'], 'traffic_gate');
            return;
        }
        self::assertSha256($section['report_sha256'], 'traffic_gate.report_sha256');
        self::assertSame($section['schema'], 'traffic_gate.v1', 'traffic_gate.schema');
        self::assertSha256($section['producer_sha256'], 'traffic_gate.producer_sha256');
        self::assertSame($section['policy_version'], 'traffic_gate_policy.v1', 'traffic_gate.policy_version');
        self::assertVersion($section['catalog_version'], 'traffic_gate.catalog_version');
        self::assertSame($section['purpose'], 'deploy', 'traffic_gate.purpose');
        self::assertEnum($section['mode'], ['normal', 'no-business-traffic'], 'traffic_gate.mode');
        foreach (['window_start_epoch', 'window_end_epoch', 'window_seconds'] as $field) {
            self::assertNonNegativeInteger($section[$field], 'traffic_gate.' . $field);
        }
        if (
            $section['window_start_epoch'] === 0 ||
            $section['window_end_epoch'] < $section['window_start_epoch'] ||
            $section['window_seconds'] !== $section['window_end_epoch'] - $section['window_start_epoch']
        ) {
            throw new RuntimeException('traffic gate window is inconsistent');
        }
        self::assertSha256($section['log_set_sha256'], 'traffic_gate.log_set_sha256');
        foreach (['rotation_complete', 'parse_complete', 'evidence_complete'] as $field) {
            self::assertBoolean($section[$field], 'traffic_gate.' . $field);
        }
        self::assertEnum($section['decision'], ['allow', 'advisory', 'hard_stop', 'invalid'], 'traffic_gate.decision');
        if (!is_int($section['exit_code']) || !in_array($section['exit_code'], [0, 20, 21], true)) {
            throw new RuntimeException('traffic_gate.exit_code is invalid');
        }
        self::assertObject($section['counts'], 'traffic_gate.counts');
        self::assertExactKeys($section['counts'], self::TRAFFIC_COUNT_KEYS, 'traffic_gate.counts');
        foreach (self::TRAFFIC_COUNT_KEYS as $field) {
            self::assertNonNegativeInteger($section['counts'][$field], 'traffic_gate.counts.' . $field);
        }
        $classTotal = 0;
        foreach (array_slice(self::TRAFFIC_COUNT_KEYS, 0, 6) as $field) {
            $classTotal += $section['counts'][$field];
        }
        if (
            $classTotal !== $section['counts']['lines_in_window'] ||
            $section['counts']['total'] !== $section['counts']['lines_in_window']
        ) {
            throw new RuntimeException('traffic gate aggregate counts are inconsistent');
        }
        if (
            $section['counts']['lines_in_window'] > $section['counts']['lines_seen'] ||
            $section['counts']['parse_errors'] > $section['counts']['lines_seen'] ||
            $section['counts']['parse_errors'] + $section['counts']['lines_in_window'] >
                $section['counts']['lines_seen'] ||
            $section['counts']['pre_window_completion'] > $section['counts']['lines_in_window'] ||
            !in_array($section['counts']['rotation_errors'], [0, 1], true)
        ) {
            throw new RuntimeException('traffic gate count bounds are inconsistent');
        }
        foreach (
            [
                'source_unknown',
                'method_unknown',
                'target_unknown',
                'status_5xx',
                'write',
                'authenticated',
                'customers_or_sensitive',
                'scanner_success',
                'pre_window_completion',
            ]
            as $overlay
        ) {
            if ($section['counts'][$overlay] > $section['counts']['lines_in_window']) {
                throw new RuntimeException('traffic gate overlay count exceeds the observation window');
            }
        }
        foreach (['source_unknown', 'method_unknown', 'target_unknown'] as $unknownOverlay) {
            if ($section['counts'][$unknownOverlay] > $section['counts']['unclassified']) {
                throw new RuntimeException('traffic gate unknown overlay exceeds the unclassified count');
            }
        }
        $unknownOverlayCount =
            $section['counts']['source_unknown'] +
            $section['counts']['method_unknown'] +
            $section['counts']['target_unknown'];
        if ($section['counts']['unclassified'] > $unknownOverlayCount) {
            throw new RuntimeException('traffic gate unclassified count lacks an unknown overlay');
        }
        if ($section['counts']['method_unknown'] > $section['counts']['write']) {
            throw new RuntimeException('traffic gate unknown method lacks the required write overlay');
        }
        $unsafeClassCount = $section['counts']['business_or_authenticated'] + $section['counts']['unclassified'];
        foreach (['status_5xx', 'write', 'authenticated', 'customers_or_sensitive'] as $overlay) {
            if ($section['counts'][$overlay] > $unsafeClassCount) {
                throw new RuntimeException('traffic gate hazardous overlay exceeds the unsafe traffic classes');
            }
        }
        if ($section['counts']['scanner_success'] > $section['counts']['business_or_authenticated']) {
            throw new RuntimeException('traffic gate scanner success exceeds the business traffic class');
        }
        $largestHazardOverlay = max(
            $section['counts']['status_5xx'],
            $section['counts']['write'],
            $section['counts']['authenticated'],
            $section['counts']['customers_or_sensitive'],
        );
        $minimumBusinessHazardRows = max(0, $largestHazardOverlay - $section['counts']['unclassified']);
        if (
            $section['counts']['scanner_success'] + $minimumBusinessHazardRows >
            $section['counts']['business_or_authenticated']
        ) {
            throw new RuntimeException('traffic gate scanner success overlaps hazardous business traffic');
        }
        if (
            $section['counts']['target_unknown'] + $section['counts']['customers_or_sensitive'] >
            $section['counts']['unclassified'] + $section['counts']['business_or_authenticated']
        ) {
            throw new RuntimeException('traffic gate unknown target overlaps sensitive traffic');
        }
        if (
            $section['counts']['scanner_success'] +
                $section['counts']['target_unknown'] +
                $section['counts']['customers_or_sensitive'] >
            $section['counts']['unclassified'] + $section['counts']['business_or_authenticated']
        ) {
            throw new RuntimeException('traffic gate scanner, unknown target, and sensitive traffic overlap');
        }
        $rotationComplete = $section['counts']['rotation_errors'] === 0;
        $parseComplete = $section['counts']['parse_errors'] === 0 && $section['counts']['lines_seen'] > 0;
        $evidenceComplete = $rotationComplete && $parseComplete;
        if (
            $section['rotation_complete'] !== $rotationComplete ||
            $section['parse_complete'] !== $parseComplete ||
            $section['evidence_complete'] !== $evidenceComplete
        ) {
            throw new RuntimeException('traffic gate completeness contradicts normalized counts');
        }
        $hardStop =
            $section['counts']['business_or_authenticated'] > 0 ||
            $section['counts']['unclassified'] > 0 ||
            $section['counts']['status_5xx'] > 0 ||
            $section['counts']['write'] > 0 ||
            $section['counts']['authenticated'] > 0 ||
            $section['counts']['customers_or_sensitive'] > 0 ||
            $section['counts']['scanner_success'] > 0 ||
            $section['counts']['source_unknown'] > 0 ||
            $section['counts']['method_unknown'] > 0 ||
            $section['counts']['target_unknown'] > 0 ||
            $section['counts']['pre_window_completion'] > 0 ||
            ($section['mode'] === 'normal' && $section['counts']['public_read'] > 0);
        [$expectedDecision, $expectedExit] = !$evidenceComplete
            ? ['invalid', 21]
            : ($hardStop
                ? ['hard_stop', 20]
                : ($section['counts']['public_read'] > 0 || $section['counts']['denied_external'] > 0
                    ? ['advisory', 0]
                    : ['allow', 0]));
        if ($section['decision'] !== $expectedDecision || $section['exit_code'] !== $expectedExit) {
            throw new RuntimeException('traffic gate decision is inconsistent with normalized counts');
        }
        $passed =
            $evidenceComplete &&
            $rotationComplete &&
            $parseComplete &&
            $section['counts']['rotation_errors'] === 0 &&
            $section['counts']['parse_errors'] === 0 &&
            $section['counts']['lines_seen'] > 0 &&
            in_array($section['decision'], ['allow', 'advisory'], true) &&
            $section['exit_code'] === 0;
        if (($section['status'] === 'passed') !== $passed) {
            throw new RuntimeException('traffic gate status does not match its normalized core');
        }
    }

    private static function validateDumpEvidence(mixed $section): void
    {
        self::assertObject($section, 'dump');
        self::assertExactKeys(
            $section,
            [
                'status',
                'policy',
                'age_seconds',
                'max_age_seconds',
                'sha256',
                'sha256_verified',
                'gzip_verified',
                'restore_verified',
            ],
            'dump',
        );
        self::assertEnum($section['status'], ['not_observed', 'passed', 'failed', 'invalid'], 'dump.status');
        if ($section['status'] === 'not_observed') {
            self::assertAllNullExcept($section, ['status'], 'dump');
            return;
        }
        self::assertSame($section['policy'], self::DUMP_POLICY, 'dump.policy');
        self::assertSame($section['max_age_seconds'], 14400, 'dump.max_age_seconds');
        if ($section['status'] === 'invalid') {
            if ($section['age_seconds'] !== null) {
                self::assertNonNegativeInteger($section['age_seconds'], 'dump.age_seconds');
            }
            if ($section['sha256'] !== null) {
                self::assertSha256($section['sha256'], 'dump.sha256');
            }
            foreach (['sha256_verified', 'gzip_verified', 'restore_verified'] as $field) {
                if ($section[$field] !== null) {
                    self::assertBoolean($section[$field], 'dump.' . $field);
                }
            }
            if ($section['sha256_verified'] === true && $section['sha256'] === null) {
                throw new RuntimeException('dump checksum verification lacks a digest');
            }
            foreach (['age_seconds', 'sha256', 'sha256_verified', 'gzip_verified', 'restore_verified'] as $field) {
                if ($section[$field] === null) {
                    return;
                }
            }
            throw new RuntimeException('invalid dump evidence must retain an unavailable measurement');
        }
        self::assertNonNegativeInteger($section['age_seconds'], 'dump.age_seconds');
        self::assertSha256($section['sha256'], 'dump.sha256');
        self::assertBoolean($section['sha256_verified'], 'dump.sha256_verified');
        self::assertBoolean($section['gzip_verified'], 'dump.gzip_verified');
        self::assertBoolean($section['restore_verified'], 'dump.restore_verified');
        $passed =
            $section['age_seconds'] < $section['max_age_seconds'] &&
            $section['sha256_verified'] &&
            $section['gzip_verified'] &&
            $section['restore_verified'];
        if (($section['status'] === 'passed') !== $passed) {
            throw new RuntimeException('dump status is inconsistent');
        }
    }

    private static function validateCapacityEvidence(mixed $section): void
    {
        self::assertObject($section, 'capacity');
        self::assertExactKeys(
            $section,
            [
                'status',
                'available_bytes',
                'projected_required_bytes',
                'available_inodes',
                'stage_inode_count',
                'restore_inode_count',
                'inode_headroom',
                'projected_required_inodes',
                'observed_percent',
                'projected_percent',
                'max_used_percent',
                'passed',
            ],
            'capacity',
        );
        self::assertEnum($section['status'], ['not_observed', 'passed', 'failed', 'invalid'], 'capacity.status');
        if ($section['status'] === 'not_observed') {
            self::assertAllNullExcept($section, ['status'], 'capacity');
            return;
        }
        self::assertSame($section['max_used_percent'], self::MAX_CAPACITY_USED_PERCENT, 'capacity.max_used_percent');
        if ($section['status'] === 'invalid') {
            foreach (
                [
                    'available_bytes',
                    'projected_required_bytes',
                    'available_inodes',
                    'stage_inode_count',
                    'restore_inode_count',
                    'inode_headroom',
                    'projected_required_inodes',
                    'observed_percent',
                    'projected_percent',
                ]
                as $field
            ) {
                if ($section[$field] !== null) {
                    self::assertNonNegativeInteger($section[$field], 'capacity.' . $field);
                }
            }
            foreach (['observed_percent', 'projected_percent'] as $field) {
                if ($section[$field] !== null && $section[$field] > 100) {
                    throw new RuntimeException('capacity percentages must not exceed 100');
                }
            }
            if (
                $section['observed_percent'] !== null &&
                $section['projected_percent'] !== null &&
                $section['projected_percent'] < $section['observed_percent']
            ) {
                throw new RuntimeException('projected capacity cannot precede observed capacity');
            }
            if ($section['passed'] !== null) {
                self::assertBoolean($section['passed'], 'capacity.passed');
            }
            if ($section['passed'] === true) {
                throw new RuntimeException('invalid capacity evidence cannot claim success');
            }
            foreach (
                [
                    'available_bytes',
                    'projected_required_bytes',
                    'available_inodes',
                    'stage_inode_count',
                    'restore_inode_count',
                    'inode_headroom',
                    'projected_required_inodes',
                    'observed_percent',
                    'projected_percent',
                    'passed',
                ]
                as $field
            ) {
                if ($section[$field] === null) {
                    return;
                }
            }
            throw new RuntimeException('invalid capacity evidence must retain an unavailable measurement');
        }
        foreach (
            [
                'available_bytes',
                'projected_required_bytes',
                'available_inodes',
                'stage_inode_count',
                'restore_inode_count',
                'inode_headroom',
                'projected_required_inodes',
                'observed_percent',
                'projected_percent',
            ] as $field
        ) {
            self::assertNonNegativeInteger($section[$field], 'capacity.' . $field);
        }
        if ($section['stage_inode_count'] === 0 || $section['restore_inode_count'] === 0) {
            throw new RuntimeException('capacity inode counts must be positive');
        }
        self::assertSame($section['inode_headroom'], 64, 'capacity.inode_headroom');
        if (
            $section['stage_inode_count'] > PHP_INT_MAX - $section['restore_inode_count'] ||
            $section['stage_inode_count'] + $section['restore_inode_count'] > PHP_INT_MAX - $section['inode_headroom']
        ) {
            throw new RuntimeException('capacity inode projection overflows');
        }
        self::assertSame(
            $section['projected_required_inodes'],
            $section['stage_inode_count'] + $section['restore_inode_count'] + $section['inode_headroom'],
            'capacity.projected_required_inodes',
        );
        if ($section['observed_percent'] > 100 || $section['projected_percent'] > 100) {
            throw new RuntimeException('capacity percentages must not exceed 100');
        }
        self::assertBoolean($section['passed'], 'capacity.passed');
        $passed =
            $section['available_bytes'] >= $section['projected_required_bytes'] &&
            $section['available_inodes'] >= $section['projected_required_inodes'] &&
            $section['observed_percent'] < $section['max_used_percent'] &&
            $section['projected_percent'] < $section['max_used_percent'] &&
            $section['projected_percent'] >= $section['observed_percent'];
        if ($section['passed'] !== $passed || ($section['status'] === 'passed') !== $passed) {
            throw new RuntimeException('capacity status is inconsistent');
        }
    }

    private static function validateArtifactEvidence(mixed $section): void
    {
        self::assertObject($section, 'artifact');
        self::assertExactKeys(
            $section,
            [
                'status',
                'expectation',
                'local_sha256',
                'remote_sha256',
                'manifest_sha256',
                'host_script_sha256',
                'artifact_script_sha256',
                'verified',
            ],
            'artifact',
        );
        self::assertEnum($section['status'], ['not_observed', 'passed', 'failed', 'invalid'], 'artifact.status');
        if ($section['status'] === 'not_observed') {
            self::assertAllNullExcept($section, ['status'], 'artifact');
            return;
        }
        self::assertSame($section['expectation'], self::ARTIFACT_EXPECTATION, 'artifact.expectation');
        if ($section['status'] === 'invalid') {
            foreach (
                ['local_sha256', 'remote_sha256', 'manifest_sha256', 'host_script_sha256', 'artifact_script_sha256']
                as $field
            ) {
                if ($section[$field] !== null) {
                    self::assertSha256($section[$field], 'artifact.' . $field);
                }
            }
            if ($section['verified'] !== null) {
                self::assertBoolean($section['verified'], 'artifact.verified');
            }
            if ($section['verified'] === true) {
                throw new RuntimeException('invalid artifact evidence cannot claim verification');
            }
            if ($section['verified'] === null) {
                return;
            }
            foreach (
                ['local_sha256', 'remote_sha256', 'manifest_sha256', 'host_script_sha256', 'artifact_script_sha256']
                as $field
            ) {
                if ($section[$field] === null) {
                    return;
                }
            }
            throw new RuntimeException('invalid artifact evidence must retain an unavailable hash');
        }
        foreach (
            ['local_sha256', 'remote_sha256', 'manifest_sha256', 'host_script_sha256', 'artifact_script_sha256']
            as $field
        ) {
            self::assertSha256($section[$field], 'artifact.' . $field);
        }
        self::assertBoolean($section['verified'], 'artifact.verified');
        $passed =
            $section['verified'] &&
            hash_equals($section['local_sha256'], $section['remote_sha256']) &&
            hash_equals($section['host_script_sha256'], $section['artifact_script_sha256']);
        if (($section['status'] === 'passed') !== $passed) {
            throw new RuntimeException('artifact status is inconsistent');
        }
    }

    private static function validateDeployEvidence(mixed $section): void
    {
        self::assertObject($section, 'deploy');
        self::assertExactKeys($section, ['status', 'invocation_count', 'exit_code', 'rollback_outcome'], 'deploy');
        self::assertEnum($section['status'], ['not_invoked', 'succeeded', 'failed', 'unknown'], 'deploy.status');
        if (!is_int($section['invocation_count']) || !in_array($section['invocation_count'], [0, 1], true)) {
            throw new RuntimeException('deploy.invocation_count must be zero or one');
        }
        self::assertEnum(
            $section['rollback_outcome'],
            ['not_applicable', 'not_observed', 'not_run', 'succeeded', 'failed', 'recovery_required'],
            'deploy.rollback_outcome',
        );
        if ($section['status'] === 'not_invoked') {
            if (
                $section['invocation_count'] !== 0 ||
                $section['exit_code'] !== null ||
                $section['rollback_outcome'] !== 'not_applicable'
            ) {
                throw new RuntimeException('not-invoked deploy evidence is inconsistent');
            }
            return;
        }
        if ($section['status'] === 'unknown') {
            if (
                $section['invocation_count'] !== 1 ||
                $section['exit_code'] !== null ||
                $section['rollback_outcome'] !== 'not_observed'
            ) {
                throw new RuntimeException('unknown deploy evidence is inconsistent');
            }
            return;
        }
        if (
            $section['invocation_count'] !== 1 ||
            !is_int($section['exit_code']) ||
            !in_array($section['exit_code'], [0, 30, 31, 32, 143], true)
        ) {
            throw new RuntimeException('invoked deploy evidence is invalid');
        }
        if (
            $section['status'] === 'succeeded' &&
            ($section['exit_code'] !== 0 || $section['rollback_outcome'] !== 'not_run')
        ) {
            throw new RuntimeException('successful deploy evidence is inconsistent');
        }
        if ($section['status'] === 'failed' && $section['exit_code'] === 0) {
            throw new RuntimeException('failed deploy evidence cannot have exit zero');
        }
    }

    private static function validateRollbackEvidence(mixed $section): void
    {
        self::assertObject($section, 'rollback');
        self::assertExactKeys($section, ['status', 'invocation_count', 'mode', 'verified'], 'rollback');
        self::assertEnum($section['status'], ['not_invoked', 'unknown', 'succeeded', 'failed'], 'rollback.status');
        if (!is_int($section['invocation_count']) || !in_array($section['invocation_count'], [0, 1], true)) {
            throw new RuntimeException('rollback.invocation_count must be zero or one');
        }
        self::assertEnum($section['mode'], ['not_applicable', 'dedicated_post_gate_recovery'], 'rollback.mode');
        if ($section['verified'] !== null && !is_bool($section['verified'])) {
            throw new RuntimeException('rollback.verified must be boolean or null');
        }

        $actual = [$section['status'], $section['invocation_count'], $section['mode'], $section['verified']];
        $expected = match ($section['status']) {
            'not_invoked' => ['not_invoked', 0, 'not_applicable', null],
            'unknown' => ['unknown', 1, 'dedicated_post_gate_recovery', null],
            'succeeded' => ['succeeded', 1, 'dedicated_post_gate_recovery', true],
            'failed' => ['failed', 1, 'dedicated_post_gate_recovery', false],
        };
        if ($actual !== $expected) {
            throw new RuntimeException('rollback evidence is inconsistent');
        }
    }

    /** @param array<string,mixed> $deploy */
    private static function assertDeployResultConsistency(string $state, string $reason, array $deploy): void
    {
        $actual = [$deploy['status'], $deploy['invocation_count'], $deploy['exit_code'], $deploy['rollback_outcome']];
        if ($state === 'manual_recovery_required' && $reason === 'contract_invalid') {
            if ($actual !== ['unknown', 1, null, 'not_observed']) {
                throw new RuntimeException('deploy evidence is inconsistent with a rejected child result');
            }
            return;
        }
        if ($state === 'manual_recovery_required' && $reason === 'interrupted') {
            if ($actual !== ['unknown', 1, null, 'not_observed'] && $actual !== ['succeeded', 1, 0, 'not_run']) {
                throw new RuntimeException('deploy and rollback evidence is inconsistent with interrupted recovery');
            }
            return;
        }
        $expected = match ($state) {
            'succeeded' => ['succeeded', 1, 0, 'not_run'],
            'failed_before_write' => ['not_invoked', 0, null, 'not_applicable'],
            'failed_pre_switch' => $reason === 'interrupted'
                ? ['failed', 1, 143, 'not_run']
                : ['failed', 1, 30, 'not_run'],
            'failed_switch_recovery_required' => ['failed', 1, 32, 'recovery_required'],
            'failed_post_switch_rollback_succeeded' => [['failed', 1, 30, 'succeeded'], ['succeeded', 1, 0, 'not_run']],
            'failed_post_switch_rollback_failed' => [['failed', 1, 31, 'failed'], ['succeeded', 1, 0, 'not_run']],
            'manual_recovery_required' => [['failed', 1, 31, 'recovery_required'], ['succeeded', 1, 0, 'not_run']],
            default => throw new RuntimeException('result state is unknown'),
        };
        $allowed = isset($expected[0]) && is_array($expected[0]) ? $expected : [$expected];
        if (!in_array($actual, $allowed, true)) {
            throw new RuntimeException('deploy and rollback evidence is inconsistent with the terminal state');
        }
    }

    /** @param array<string,mixed> $rollback */
    private static function assertRollbackResultConsistency(string $state, string $reason, array $rollback): void
    {
        $actual = [$rollback['status'], $rollback['invocation_count'], $rollback['mode'], $rollback['verified']];
        $notInvoked = ['not_invoked', 0, 'not_applicable', null];
        $unknown = ['unknown', 1, 'dedicated_post_gate_recovery', null];
        $succeeded = ['succeeded', 1, 'dedicated_post_gate_recovery', true];
        $failed = ['failed', 1, 'dedicated_post_gate_recovery', false];

        $allowed = match ($state) {
            'failed_post_switch_rollback_succeeded' => [$notInvoked, $succeeded],
            'failed_post_switch_rollback_failed' => [$notInvoked, $failed],
            'manual_recovery_required' => $reason === 'interrupted' ? [$notInvoked, $unknown] : [$notInvoked, $failed],
            default => [$notInvoked],
        };
        if (!in_array($actual, $allowed, true)) {
            throw new RuntimeException('rollback evidence is inconsistent with the terminal state');
        }
    }

    private static function validatePostGateEvidence(mixed $section): void
    {
        self::assertObject($section, 'post_gates');
        self::assertExactKeys(
            $section,
            [
                'status',
                'kuma_healthy_count',
                'kuma_total_count',
                'runtime_config_passed',
                'services_passed',
                'endpoints_passed',
                'logs_passed',
                'scanner_passed',
                'dormant_clean_passed',
                'passed',
            ],
            'post_gates',
        );
        self::assertEnum($section['status'], ['not_observed', 'passed', 'failed', 'incomplete'], 'post_gates.status');
        if ($section['status'] === 'not_observed') {
            self::assertAllNullExcept($section, ['status'], 'post_gates');
            return;
        }
        if ($section['status'] === 'incomplete') {
            self::assertSame($section['passed'], null, 'post_gates.passed');
            $healthyObserved = $section['kuma_healthy_count'] !== null;
            $totalObserved = $section['kuma_total_count'] !== null;
            if ($healthyObserved !== $totalObserved) {
                throw new RuntimeException('incomplete post-gate Kuma counts must be observed together');
            }
            $hasUnobservedCheck = !$healthyObserved;
            if ($healthyObserved) {
                self::assertNonNegativeInteger($section['kuma_healthy_count'], 'post_gates.kuma_healthy_count');
                self::assertNonNegativeInteger($section['kuma_total_count'], 'post_gates.kuma_total_count');
                if ($section['kuma_healthy_count'] > $section['kuma_total_count']) {
                    throw new RuntimeException('post-gate Kuma counts are inconsistent');
                }
            }
            foreach (
                [
                    'runtime_config_passed',
                    'services_passed',
                    'endpoints_passed',
                    'logs_passed',
                    'scanner_passed',
                    'dormant_clean_passed',
                ]
                as $field
            ) {
                if ($section[$field] === null) {
                    $hasUnobservedCheck = true;
                    continue;
                }
                self::assertBoolean($section[$field], 'post_gates.' . $field);
            }
            if (!$hasUnobservedCheck) {
                throw new RuntimeException('incomplete post-gate evidence must retain an unobserved check');
            }
            return;
        }
        self::assertNonNegativeInteger($section['kuma_healthy_count'], 'post_gates.kuma_healthy_count');
        self::assertNonNegativeInteger($section['kuma_total_count'], 'post_gates.kuma_total_count');
        if ($section['kuma_healthy_count'] > $section['kuma_total_count']) {
            throw new RuntimeException('post-gate Kuma counts are inconsistent');
        }
        foreach (
            [
                'runtime_config_passed',
                'services_passed',
                'endpoints_passed',
                'logs_passed',
                'scanner_passed',
                'dormant_clean_passed',
                'passed',
            ]
            as $field
        ) {
            self::assertBoolean($section[$field], 'post_gates.' . $field);
        }
        $passed =
            $section['kuma_healthy_count'] === 13 &&
            $section['kuma_total_count'] === 13 &&
            $section['runtime_config_passed'] &&
            $section['services_passed'] &&
            $section['endpoints_passed'] &&
            $section['logs_passed'] &&
            $section['scanner_passed'] &&
            $section['dormant_clean_passed'];
        if ($section['passed'] !== $passed || ($section['status'] === 'passed') !== $passed) {
            throw new RuntimeException('post-gate summary or status is inconsistent');
        }
    }

    private static function validateDeployTimingEvidence(mixed $section): void
    {
        self::assertObject($section, 'deploy_timing');
        self::assertExactKeys($section, ['status', 'authoritative_sha256', 'run_id', 'total_ms'], 'deploy_timing');
        self::assertEnum($section['status'], ['not_observed', 'valid', 'invalid'], 'deploy_timing.status');
        if ($section['status'] === 'not_observed') {
            self::assertAllNullExcept($section, ['status'], 'deploy_timing');
            return;
        }
        self::assertSha256($section['authoritative_sha256'], 'deploy_timing.authoritative_sha256');
        if ($section['status'] === 'invalid') {
            if ($section['run_id'] !== null) {
                self::assertUuidV4($section['run_id'], 'deploy_timing.run_id');
            }
            if ($section['total_ms'] !== null) {
                self::assertNonNegativeInteger($section['total_ms'], 'deploy_timing.total_ms');
            }
            return;
        }
        self::assertUuidV4($section['run_id'], 'deploy_timing.run_id');
        self::assertNonNegativeInteger($section['total_ms'], 'deploy_timing.total_ms');
    }

    private static function validateOrchestratorTiming(mixed $section): void
    {
        self::assertObject($section, 'orchestrator_timing');
        self::assertExactKeys($section, ['started_at_utc', 'finished_at_utc', 'wall_clock_ms'], 'orchestrator_timing');
        self::assertUtc($section['started_at_utc'], 'orchestrator_timing.started_at_utc');
        self::assertUtc($section['finished_at_utc'], 'orchestrator_timing.finished_at_utc');
        self::assertNonNegativeInteger($section['wall_clock_ms'], 'orchestrator_timing.wall_clock_ms');
        $startedAtEpoch = self::utcEpoch($section['started_at_utc']);
        $finishedAtEpoch = self::utcEpoch($section['finished_at_utc']);
        if ($finishedAtEpoch < $startedAtEpoch) {
            throw new RuntimeException('orchestrator timing is not monotonic');
        }
        $timestampDeltaMs = ($finishedAtEpoch - $startedAtEpoch) * 1000;
        $minimumWallClockMs = max(0, $timestampDeltaMs - 999);
        $maximumWallClockMs = $timestampDeltaMs + 999;
        if ($section['wall_clock_ms'] < $minimumWallClockMs || $section['wall_clock_ms'] > $maximumWallClockMs) {
            throw new RuntimeException('orchestrator wall clock contradicts its UTC timestamps');
        }
    }

    /** @param array<string,mixed> $section */
    private static function assertOrchestratorTimingMatchesLifecycle(
        array $section,
        string $firstRecordedAtUtc,
        string $terminalRecordedAtUtc,
        string $capturedAtUtc,
    ): void {
        if (self::utcEpoch($section['started_at_utc']) > self::utcEpoch($firstRecordedAtUtc)) {
            throw new RuntimeException('orchestrator timing starts after the deployment journal');
        }
        if (self::utcEpoch($section['finished_at_utc']) < self::utcEpoch($terminalRecordedAtUtc)) {
            throw new RuntimeException('orchestrator timing finishes before the terminal journal record');
        }
        if (self::utcEpoch($section['finished_at_utc']) > self::utcEpoch($capturedAtUtc)) {
            throw new RuntimeException('orchestrator timing finishes after evidence capture');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $except */
    private static function assertAllNullExcept(array $value, array $except, string $context): void
    {
        foreach ($value as $key => $item) {
            if (!in_array($key, $except, true) && $item !== null) {
                throw new RuntimeException($context . ' not_observed fields must be null');
            }
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $context): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException($context . ' contains missing or unexpected fields');
        }
    }

    /** @param mixed $value */
    private static function assertObject(mixed $value, string $field): void
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($field . ' must be an object');
        }
    }

    /** @param mixed $value */
    private static function assertUuidV4(mixed $value, string $field): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) !== 1
        ) {
            throw new RuntimeException($field . ' must be a lowercase UUIDv4');
        }
    }

    /** @param mixed $value */
    private static function assertSha256(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new RuntimeException($field . ' must be a lowercase SHA-256');
        }
    }

    /** @param mixed $value */
    private static function assertCommit(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{40}$/', $value) !== 1) {
            throw new RuntimeException($field . ' must be a full lowercase Git commit');
        }
    }

    /** @param mixed $value */
    private static function assertReleaseId(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) !== 1) {
            throw new RuntimeException('release_id is invalid');
        }
    }

    /** @param mixed $value */
    private static function assertUtc(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            throw new RuntimeException($field . ' must use canonical second-precision UTC');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException($field . ' is not a valid UTC timestamp');
        }
    }

    private static function utcEpoch(string $value): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('UTC timestamp cannot be parsed');
        }

        return $parsed->getTimestamp();
    }

    /** @param mixed $value @param list<string> $allowed */
    private static function assertEnum(mixed $value, array $allowed, string $field): void
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RuntimeException($field . ' is not an allowed enum value');
        }
    }

    /** @param mixed $actual @param mixed $expected */
    private static function assertSame(mixed $actual, mixed $expected, string $field): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException($field . ' has an unexpected value');
        }
    }

    /** @param mixed $value */
    private static function assertNonNegativeInteger(mixed $value, string $field): void
    {
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException($field . ' must be a non-negative integer');
        }
    }

    /** @param mixed $value */
    private static function assertBoolean(mixed $value, string $field): void
    {
        if (!is_bool($value)) {
            throw new RuntimeException($field . ' must be a boolean');
        }
    }

    /** @param mixed $value @return mixed */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_float($value) || is_resource($value) || is_object($value)) {
                throw new RuntimeException('canonical JSON accepts only exact JSON scalar and array types');
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException('canonical JSON object keys must be strings');
            }
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function assertVersion(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\.[1-9][0-9]*$/', $value) !== 1) {
            throw new RuntimeException($field . ' is invalid');
        }
    }
}

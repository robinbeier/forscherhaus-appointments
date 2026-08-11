<?php

declare(strict_types=1);

namespace Ops;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class DeploymentHostRunnerContractV1
{
    public const DEPLOY_REQUEST_SCHEMA = 'deployment_host_runner_request.v1';
    public const RECOVERY_REQUEST_SCHEMA = 'deployment_host_recovery_request.v1';
    public const EXECUTION_INPUT_SCHEMA = 'deployment_host_execution_input.v1';
    public const POST_GATE_REPORT_SCHEMA = 'deployment_host_post_gate_report.v1';
    public const STATE_SCHEMA = 'deployment_host_runner_state.v1';
    public const OPERATOR_EVENT_SCHEMA = 'deployment_host_operator_event.v1';
    public const ACTIVE_RUN_SCHEMA = 'deployment_host_active_run.v1';
    public const RESPONSE_SCHEMA = 'deployment_host_runner_response.v1';

    public const STATE_ROOT = '/var/lib/fh-deploy-orchestrator';
    public const GLOBAL_LOCK_PATH = self::STATE_ROOT . '/locks/fh-production-change.lock';

    public const CLI_ACTIONS = ['deploy', 'post-gates', 'recovery', 'reconcile'];

    private const EXECUTION_INPUT_MAX_BYTES = 16_384;
    private const FIXED_ENVIRONMENT = [
        'LANG' => 'C',
        'LC_ALL' => 'C',
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
    ];

    // Only drives DeploymentContractV1's authoritative field validation; the timestamp is not part of intent_sha256.
    private const INTENT_VALIDATION_TIMESTAMP = '2000-01-01T00:00:00Z';

    private const DEPLOY_REQUEST_KEYS = [
        'schema',
        'run_id',
        'expected_commit',
        'release_id',
        'traffic_mode',
        'dump_policy',
        'artifact_expectation',
        'intent_sha256',
    ];

    private const RECOVERY_REQUEST_KEYS = ['schema', 'run_id', 'intent_sha256'];

    private const EXECUTION_INPUT_KEYS = ['schema', 'run_id', 'intent_sha256', 'action', 'parameters'];

    private const DEPLOY_EXECUTION_PARAMETER_KEYS = [
        'release_id',
        'renderer_deploy_mode',
        'healthz_token',
        'zero_surprise_dump',
        'zero_surprise_predeploy_credentials',
        'zero_surprise_canary_credentials',
        'zero_surprise_incident_webhook',
    ];

    private const POST_GATE_REPORT_KEYS = [
        'schema',
        'run_id',
        'intent_sha256',
        'captured_at_utc',
        'subject',
        'deploy_receipt_sha256',
        'post_gates',
    ];

    private const POST_GATE_KEYS = [
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
    ];

    private const STATE_KEYS = [
        'schema',
        'run_id',
        'intent_sha256',
        'state',
        'sequence',
        'events_sha256',
        'active_action',
        'deploy',
        'post_gates',
        'rollback',
        'evidence_sha256',
        'terminal',
        'updated_at_utc',
    ];

    private const DEPLOY_STATE_KEYS = [
        'request_sha256',
        'execution_input_sha256',
        'invocation_count',
        'unit_name',
        'unit_state',
        'observed_exit_code',
        'receipt_sha256',
    ];

    private const ROLLBACK_STATE_KEYS = [
        'request_sha256',
        'execution_input_sha256',
        'invocation_count',
        'unit_name',
        'unit_state',
        'observed_exit_code',
        'verdict',
    ];

    private const POST_GATE_STATE_KEYS = [
        'deploy_report_sha256',
        'deploy_submission_count',
        'deploy_verdict',
        'rollback_report_sha256',
        'rollback_submission_count',
        'rollback_verdict',
    ];

    private const TERMINAL_STATE_KEYS = ['state', 'exit_code', 'reason'];

    private const OPERATOR_EVENT_KEYS = [
        'schema',
        'run_id',
        'intent_sha256',
        'sequence',
        'recorded_at_utc',
        'action',
        'event',
        'status',
        'reason',
    ];

    private const ACTIVE_RUN_KEYS = [
        'schema',
        'run_id',
        'intent_sha256',
        'state',
        'sequence',
        'events_sha256',
        'claimed_at_utc',
    ];

    private const RESPONSE_KEYS = [
        'schema',
        'run_id',
        'intent_sha256',
        'action',
        'disposition',
        'state',
        'result_exit_code',
        'result_reason',
    ];

    private const UNIT_STATES = ['not_created', 'starting', 'running', 'exited', 'failed', 'killed', 'unknown'];

    private const PRE_DEPLOY_STATES = [
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
    ];

    private const OBSERVE_ONLY_STATES = ['deploy_running', 'post_gates_running', 'rollback_running'];

    private const OPERATOR_EVENTS = [
        'request_accepted',
        'attached',
        'reservation_persisted',
        'unit_started',
        'unit_observed',
        'receipt_accepted',
        'receipt_rejected',
        'post_gates_observed',
        'rollback_reserved',
        'reconciliation_required',
        'terminal_persisted',
        'active_run_cleared',
    ];

    private const OPERATOR_STATUSES = ['ok', 'running', 'failed', 'unknown', 'terminal'];

    private const OPERATOR_REASONS = [
        'none',
        'same_intent',
        'state_conflict',
        'contract_invalid',
        'lock_busy',
        'unit_collision',
        'unit_running',
        'unit_exited',
        'unit_failed',
        'unit_killed',
        'unit_missing',
        'receipt_valid',
        'receipt_missing',
        'receipt_invalid',
        'receipt_mismatch',
        'child_exit_74',
        'interrupted',
        'post_gate_failed',
        'rollback_succeeded',
        'rollback_failed',
        'manual_recovery_required',
    ];

    /** @param array<string,mixed> $request */
    public static function validateDeployRequest(array $request): void
    {
        self::assertExactKeys($request, self::DEPLOY_REQUEST_KEYS, 'deploy request');
        self::assertSame($request['schema'], self::DEPLOY_REQUEST_SCHEMA, 'deploy request schema');

        $intent = DeploymentContractV1::createIntentRecord(
            self::assertString($request['run_id'], 'run_id'),
            self::INTENT_VALIDATION_TIMESTAMP,
            self::assertString($request['expected_commit'], 'expected_commit'),
            self::assertString($request['release_id'], 'release_id'),
            self::assertString($request['traffic_mode'], 'traffic_mode'),
            self::assertString($request['dump_policy'], 'dump_policy'),
            self::assertString($request['artifact_expectation'], 'artifact_expectation'),
        );
        self::assertSha256($request['intent_sha256'], 'intent_sha256');
        if (!hash_equals($intent['intent_sha256'], $request['intent_sha256'])) {
            throw new RuntimeException('deploy request intent_sha256 does not bind the immutable intent');
        }
    }

    /** @return array<string,mixed> */
    public static function decodeDeployRequest(string $encoded): array
    {
        $request = self::decodeFile($encoded, 'deploy request');
        self::validateDeployRequest($request);

        return $request;
    }

    /** @param array<string,mixed> $request */
    public static function validateRecoveryRequest(array $request): void
    {
        self::assertExactKeys($request, self::RECOVERY_REQUEST_KEYS, 'recovery request');
        self::assertSame($request['schema'], self::RECOVERY_REQUEST_SCHEMA, 'recovery request schema');
        self::assertUuidV4($request['run_id'], 'run_id');
        self::assertSha256($request['intent_sha256'], 'intent_sha256');
    }

    /** @return array<string,mixed> */
    public static function decodeRecoveryRequest(string $encoded): array
    {
        $request = self::decodeFile($encoded, 'recovery request');
        self::validateRecoveryRequest($request);

        return $request;
    }

    /** @param array<string,mixed> $input */
    public static function validateExecutionInput(array $input): void
    {
        self::assertExactKeys($input, self::EXECUTION_INPUT_KEYS, 'execution input');
        self::assertSame($input['schema'], self::EXECUTION_INPUT_SCHEMA, 'execution input schema');
        self::assertUuidV4($input['run_id'], 'run_id');
        self::assertSha256($input['intent_sha256'], 'intent_sha256');
        self::assertEnum($input['action'], ['deploy', 'rollback'], 'execution action');
        self::assertObject($input['parameters'], 'execution parameters');
        if ($input['action'] === 'rollback') {
            self::assertExactKeys($input['parameters'], ['release_id'], 'rollback execution parameters');
            self::assertReleaseId($input['parameters']['release_id']);
            return;
        }
        self::assertExactKeys($input['parameters'], self::DEPLOY_EXECUTION_PARAMETER_KEYS, 'deploy parameters');
        self::assertReleaseId($input['parameters']['release_id']);
        self::assertEnum($input['parameters']['renderer_deploy_mode'], ['host', 'external'], 'renderer mode');
        foreach (array_slice(self::DEPLOY_EXECUTION_PARAMETER_KEYS, 2) as $field) {
            self::assertProtectedFileReference($input['parameters'][$field], $field);
        }
    }

    /** @return array<string,mixed> */
    public static function decodeExecutionInput(string $encoded): array
    {
        $input = self::decodeBoundedFile($encoded, 'execution input', self::EXECUTION_INPUT_MAX_BYTES);
        self::validateExecutionInput($input);

        return $input;
    }

    /** @param array<string,mixed> $input */
    public static function encodeExecutionInput(array $input): string
    {
        self::validateExecutionInput($input);
        $encoded = self::encodeFile($input);
        if (strlen($encoded) > self::EXECUTION_INPUT_MAX_BYTES) {
            throw new RuntimeException('execution input encoding is invalid');
        }

        return $encoded;
    }

    /**
     * @param array<string,mixed> $request
     * @param ?array<string,mixed> $originalDeployRequest
     */
    public static function executionInputPinDisposition(
        string $encodedInput,
        ?string $existingPinnedInputBytes,
        array $request,
        ?array $originalDeployRequest = null,
    ): string {
        $input = self::decodeExecutionInput($encodedInput);
        self::validateBoundExecutionInput($request, $input, $originalDeployRequest);
        if ($existingPinnedInputBytes === null) {
            return 'pin';
        }
        self::decodeExecutionInput($existingPinnedInputBytes);
        if (!hash_equals($encodedInput, $existingPinnedInputBytes)) {
            throw new RuntimeException('execution input conflicts with the pinned first input');
        }

        return 'resume';
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $input */
    public static function validateDeployExecutionBundle(array $request, array $input): void
    {
        self::validateDeployRequest($request);
        self::validateExecutionInput($input);
        if (
            $input['action'] !== 'deploy' ||
            $input['parameters']['release_id'] !== $request['release_id'] ||
            $input['run_id'] !== $request['run_id'] ||
            !hash_equals($input['intent_sha256'], $request['intent_sha256'])
        ) {
            throw new RuntimeException('execution input does not bind the immutable deploy request');
        }
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $input
     * @param ?array<string,mixed> $originalDeployRequest
     */
    private static function validateBoundExecutionInput(
        array $request,
        array $input,
        ?array $originalDeployRequest,
    ): void {
        if ($input['action'] === 'deploy') {
            if ($originalDeployRequest !== null) {
                throw new RuntimeException('deploy execution input cannot bind an original recovery request');
            }
            self::validateDeployExecutionBundle($request, $input);
            return;
        }
        if ($originalDeployRequest === null) {
            throw new RuntimeException('recovery execution input requires the immutable deploy request');
        }
        self::validateRecoveryExecutionBundle($request, $originalDeployRequest, $input);
    }

    /**
     * @param array<string,mixed> $recoveryRequest
     * @param array<string,mixed> $originalDeployRequest
     * @param array<string,mixed> $input
     */
    public static function validateRecoveryExecutionBundle(
        array $recoveryRequest,
        array $originalDeployRequest,
        array $input,
    ): void {
        self::validateRecoveryRequest($recoveryRequest);
        self::validateDeployRequest($originalDeployRequest);
        self::validateExecutionInput($input);
        if (
            $input['action'] !== 'rollback' ||
            $input['parameters']['release_id'] !== $originalDeployRequest['release_id'] ||
            $recoveryRequest['run_id'] !== $originalDeployRequest['run_id'] ||
            !hash_equals($recoveryRequest['intent_sha256'], $originalDeployRequest['intent_sha256']) ||
            $input['run_id'] !== $originalDeployRequest['run_id'] ||
            !hash_equals($input['intent_sha256'], $originalDeployRequest['intent_sha256'])
        ) {
            throw new RuntimeException(
                'recovery execution input does not bind the recovery request and immutable deploy request',
            );
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $request
     * @param ?array<string,mixed> $originalDeployRequest
     * @return list<string>
     */
    public static function executionArgv(array $input, array $request, ?array $originalDeployRequest = null): array
    {
        self::validateBoundExecutionInput($request, $input, $originalDeployRequest);
        $argv = [
            '/usr/bin/env',
            '-i',
            'LANG=' . self::FIXED_ENVIRONMENT['LANG'],
            'LC_ALL=' . self::FIXED_ENVIRONMENT['LC_ALL'],
            'PATH=' . self::FIXED_ENVIRONMENT['PATH'],
            '/bin/bash',
            '/root/deploy_ea.sh',
        ];
        if ($input['action'] === 'rollback') {
            return [
                ...$argv,
                '--runtime-config-rollback',
                '--active',
                '/var/www/html/easyappointments',
                '--previous',
                '/var/www/html/easyappointments_prev_' . $input['parameters']['release_id'],
                '--failed',
                '/var/www/html/.fh-failed-' . $input['run_id'],
                '--runtime-user',
                'www-data',
            ];
        }
        $parameters = $input['parameters'];

        return [
            ...$argv,
            '--rel',
            $parameters['release_id'],
            '--renderer-deploy-mode',
            $parameters['renderer_deploy_mode'],
            '--healthz-token-file',
            $parameters['healthz_token']['path'],
            '--zero-surprise-dump-file',
            $parameters['zero_surprise_dump']['path'],
            '--zero-surprise-predeploy-credentials-file',
            $parameters['zero_surprise_predeploy_credentials']['path'],
            '--zero-surprise-canary-credentials-file',
            $parameters['zero_surprise_canary_credentials']['path'],
            '--zero-surprise-incident-webhook-file',
            $parameters['zero_surprise_incident_webhook']['path'],
            '--result-file',
            self::STATE_ROOT . '/runs/' . $input['run_id'] . '/deploy-result.json',
        ];
    }

    /** @param array<string,mixed> $report */
    public static function validatePostGateReport(array $report): void
    {
        self::assertExactKeys($report, self::POST_GATE_REPORT_KEYS, 'post-gate report');
        self::assertSame($report['schema'], self::POST_GATE_REPORT_SCHEMA, 'post-gate report schema');
        self::assertUuidV4($report['run_id'], 'run_id');
        self::assertSha256($report['intent_sha256'], 'intent_sha256');
        self::assertUtc($report['captured_at_utc'], 'captured_at_utc');
        self::assertEnum($report['subject'], ['deploy', 'rollback'], 'post-gate subject');
        self::assertNullableSha256($report['deploy_receipt_sha256'], 'deploy_receipt_sha256');
        if (($report['subject'] === 'deploy') !== ($report['deploy_receipt_sha256'] !== null)) {
            throw new RuntimeException('post-gate receipt binding is incompatible with its subject');
        }
        self::assertObject($report['post_gates'], 'post_gates');
        self::assertExactKeys($report['post_gates'], self::POST_GATE_KEYS, 'post_gates');
        $postGates = $report['post_gates'];
        self::assertEnum($postGates['status'], ['passed', 'failed'], 'post_gates.status');
        foreach (['kuma_healthy_count', 'kuma_total_count'] as $field) {
            if (!is_int($postGates[$field]) || $postGates[$field] < 0) {
                throw new RuntimeException('post-gate counts are invalid');
            }
        }
        if ($postGates['kuma_total_count'] !== 13 || $postGates['kuma_healthy_count'] > 13) {
            throw new RuntimeException('post-gate Kuma observation is invalid');
        }
        foreach (array_slice(self::POST_GATE_KEYS, 3) as $field) {
            if (!is_bool($postGates[$field])) {
                throw new RuntimeException('post-gate booleans are invalid');
            }
        }
        $passed = $postGates['kuma_healthy_count'] === 13;
        foreach (array_slice(self::POST_GATE_KEYS, 3, -1) as $field) {
            $passed = $passed && $postGates[$field];
        }
        if ($postGates['passed'] !== $passed || ($postGates['status'] === 'passed') !== $passed) {
            throw new RuntimeException('post-gate status is inconsistent');
        }
    }

    /** @param array<string,mixed> $report */
    public static function encodePostGateReport(array $report): string
    {
        self::validatePostGateReport($report);
        $encoded = self::encodeFile($report);
        if (strlen($encoded) > self::EXECUTION_INPUT_MAX_BYTES) {
            throw new RuntimeException('post-gate report encoding is invalid');
        }

        return $encoded;
    }

    /** @return array<string,mixed> */
    public static function decodePostGateReport(string $encoded): array
    {
        $report = self::decodeBoundedFile($encoded, 'post-gate report', self::EXECUTION_INPUT_MAX_BYTES);
        self::validatePostGateReport($report);

        return $report;
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $state */
    public static function validatePostGateBundle(array $report, array $state): void
    {
        self::validatePostGateReport($report);
        self::validateState($state);
        if (
            $report['run_id'] !== $state['run_id'] ||
            !hash_equals($report['intent_sha256'], $state['intent_sha256']) ||
            strcmp($report['captured_at_utc'], $state['updated_at_utc']) < 0
        ) {
            throw new RuntimeException('post-gate report does not bind the durable state');
        }
        if ($report['subject'] === 'deploy') {
            if (
                $state['state'] !== 'post_gates_running' ||
                $state['deploy']['unit_state'] !== 'exited' ||
                $state['deploy']['observed_exit_code'] !== 0 ||
                $state['deploy']['receipt_sha256'] !== $report['deploy_receipt_sha256']
            ) {
                throw new RuntimeException('deploy post-gate report does not bind the completed deploy');
            }
            return;
        }
        if (
            $state['state'] !== DeploymentContractV1::ROLLBACK_RESERVATION_STATE ||
            $state['post_gates']['deploy_verdict'] !== 'failed' ||
            $state['rollback']['unit_state'] !== 'exited' ||
            $state['rollback']['observed_exit_code'] !== 0 ||
            $state['rollback']['verdict'] !== 'verification_pending'
        ) {
            throw new RuntimeException('rollback post-gate report does not bind the completed recovery action');
        }
    }

    /**
     * Classifies exact canonical report bytes against the write-once state slot.
     * Storage must pin those same bytes before persisting the returned first-submission transition.
     *
     * @param array<string,mixed> $state
     */
    public static function postGateSubmissionDisposition(
        string $encodedReport,
        array $state,
        ?string $existingPinnedReportBytes = null,
    ): string {
        $report = self::decodePostGateReport($encodedReport);
        self::validateState($state);
        if ($report['run_id'] !== $state['run_id'] || !hash_equals($report['intent_sha256'], $state['intent_sha256'])) {
            throw new RuntimeException('post-gate report does not bind the durable state');
        }

        $subject = $report['subject'];
        $count = $state['post_gates'][$subject . '_submission_count'];
        $storedSha256 = $state['post_gates'][$subject . '_report_sha256'];
        $storedVerdict = $state['post_gates'][$subject . '_verdict'];
        $reportSha256 = self::fileSha256($encodedReport);
        $reportVerdict = $report['post_gates']['status'];
        if ($count === 1) {
            self::assertPersistedPostGateReportBinding($report, $state);
            if (
                is_string($storedSha256) &&
                hash_equals($storedSha256, $reportSha256) &&
                $storedVerdict === $reportVerdict &&
                $existingPinnedReportBytes !== null &&
                hash_equals($encodedReport, $existingPinnedReportBytes)
            ) {
                return 'attach';
            }
            throw new RuntimeException('post-gate report conflicts with the write-once submission');
        }

        self::validatePostGateBundle($report, $state);
        if ($existingPinnedReportBytes !== null) {
            self::decodePostGateReport($existingPinnedReportBytes);
            if (!hash_equals($encodedReport, $existingPinnedReportBytes)) {
                throw new RuntimeException('post-gate report conflicts with the pinned first submission');
            }
            return 'resume_first_submission';
        }

        return 'first_submission';
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $state */
    private static function assertPersistedPostGateReportBinding(array $report, array $state): void
    {
        if ($report['subject'] === 'deploy') {
            if (
                $state['deploy']['unit_state'] !== 'exited' ||
                $state['deploy']['observed_exit_code'] !== 0 ||
                $state['deploy']['receipt_sha256'] === null ||
                !hash_equals($state['deploy']['receipt_sha256'], $report['deploy_receipt_sha256'])
            ) {
                throw new RuntimeException('stored deploy post-gate report does not bind the completed deploy');
            }
            return;
        }
        if (
            $state['post_gates']['deploy_verdict'] !== 'failed' ||
            $state['rollback']['invocation_count'] !== 1 ||
            $state['rollback']['unit_state'] !== 'exited' ||
            $state['rollback']['observed_exit_code'] !== 0
        ) {
            throw new RuntimeException('stored rollback post-gate report does not bind the completed recovery action');
        }
    }

    /** @param array<string,mixed> $state */
    public static function postGateDisposition(
        string $encodedReport,
        array $state,
        ?string $existingPinnedReportBytes = null,
    ): string {
        $submissionDisposition = self::postGateSubmissionDisposition(
            $encodedReport,
            $state,
            $existingPinnedReportBytes,
        );
        if (self::isTerminalState($state['state'])) {
            throw new RuntimeException('post-gate disposition cannot replace an immutable terminal result');
        }
        $report = self::decodePostGateReport($encodedReport);
        if ($report['subject'] === 'deploy') {
            if ($submissionDisposition === 'attach' && !$report['post_gates']['passed']) {
                return 'attach_observe_only';
            }
            return $report['post_gates']['passed'] ? 'succeeded' : 'recovery_required';
        }

        return $report['post_gates']['passed']
            ? 'failed_post_switch_rollback_succeeded'
            : 'failed_post_switch_rollback_failed';
    }

    /** @param list<string> $existingLines @param array<string,mixed> $request @param ?array<string,mixed> $terminalState */
    public static function deployAttachmentDisposition(
        array $existingLines,
        array $request,
        ?array $terminalState = null,
        ?string $terminalEvidenceBytes = null,
    ): string {
        self::validateDeployRequest($request);
        $candidateIntent = DeploymentContractV1::createIntentRecord(
            $request['run_id'],
            self::INTENT_VALIDATION_TIMESTAMP,
            $request['expected_commit'],
            $request['release_id'],
            $request['traffic_mode'],
            $request['dump_policy'],
            $request['artifact_expectation'],
        );

        return self::attachmentDispositionWithTerminalBundle(
            DeploymentContractV1::attachmentDecision($existingLines, $candidateIntent),
            $existingLines,
            $terminalState,
            $terminalEvidenceBytes,
        );
    }

    /** @param list<string> $existingLines @param array<string,mixed> $request @param ?array<string,mixed> $currentState */
    public static function recoveryAttachmentDisposition(
        array $existingLines,
        array $request,
        ?array $currentState = null,
        ?string $terminalEvidenceBytes = null,
        ?string $deployPostGateReportBytes = null,
    ): string {
        self::validateRecoveryRequest($request);
        $run = DeploymentContractV1::validateRunLines($existingLines);
        if ($run['run_id'] !== $request['run_id'] || !hash_equals($run['intent_sha256'], $request['intent_sha256'])) {
            throw new RuntimeException('recovery request does not bind the existing run intent');
        }
        if ($run['state'] === 'post_gates_running') {
            if ($currentState === null || $terminalEvidenceBytes !== null || $deployPostGateReportBytes === null) {
                throw new RuntimeException('recovery admission requires current nonterminal state');
            }
            $deployPostGateReport = self::decodePostGateReport($deployPostGateReportBytes);
            $eventsBytes = implode("\n", $existingLines) . "\n";
            if (
                $deployPostGateReport['subject'] !== 'deploy' ||
                $deployPostGateReport['post_gates']['status'] !== 'failed' ||
                self::stateCacheDisposition($currentState, $eventsBytes) !== 'current' ||
                $currentState['post_gates']['deploy_submission_count'] !== 1 ||
                $currentState['post_gates']['deploy_verdict'] !== 'failed' ||
                self::postGateSubmissionDisposition(
                    $deployPostGateReportBytes,
                    $currentState,
                    $deployPostGateReportBytes,
                ) !== 'attach'
            ) {
                throw new RuntimeException('recovery requires a bound failed deploy post-gate report');
            }
            return 'accepted';
        }
        if ($run['state'] === DeploymentContractV1::ROLLBACK_RESERVATION_STATE) {
            if ($currentState === null || $terminalEvidenceBytes !== null || $deployPostGateReportBytes === null) {
                throw new RuntimeException('recovery attachment requires current nonterminal state');
            }
            $deployPostGateReport = self::decodePostGateReport($deployPostGateReportBytes);
            $eventsBytes = implode("\n", $existingLines) . "\n";
            if (
                $deployPostGateReport['subject'] !== 'deploy' ||
                $deployPostGateReport['post_gates']['status'] !== 'failed' ||
                self::stateCacheDisposition($currentState, $eventsBytes) !== 'current' ||
                self::postGateSubmissionDisposition(
                    $deployPostGateReportBytes,
                    $currentState,
                    $deployPostGateReportBytes,
                ) !== 'attach'
            ) {
                throw new RuntimeException('recovery attachment state is not current');
            }
            return 'attach_observe_only';
        }
        if ($run['recovery'] === 'terminal') {
            return self::attachmentDispositionWithTerminalBundle(
                'terminal',
                $existingLines,
                $currentState,
                $terminalEvidenceBytes,
            );
        }

        throw new RuntimeException('recovery request is incompatible with the current state');
    }

    /**
     * @param list<string> $existingLines
     * @param ?array<string,mixed> $terminalState
     */
    private static function attachmentDispositionWithTerminalBundle(
        string $disposition,
        array $existingLines,
        ?array $terminalState,
        ?string $terminalEvidenceBytes,
    ): string {
        if ($disposition !== 'terminal') {
            self::assertNoTerminalAttachmentBundle($terminalState, $terminalEvidenceBytes);
            return $disposition;
        }
        if ($terminalState === null || $terminalEvidenceBytes === null) {
            throw new RuntimeException('terminal attachment requires durable state and evidence');
        }
        $eventsBytes = implode("\n", $existingLines) . "\n";
        if (self::terminalStateCacheDisposition($terminalState, $eventsBytes, $terminalEvidenceBytes) !== 'current') {
            throw new RuntimeException('terminal attachment bundle is not current');
        }

        return 'terminal';
    }

    /** @param ?array<string,mixed> $terminalState */
    private static function assertNoTerminalAttachmentBundle(
        ?array $terminalState,
        ?string $terminalEvidenceBytes,
    ): void {
        if ($terminalState !== null || $terminalEvidenceBytes !== null) {
            throw new RuntimeException('nonterminal attachment cannot consume a terminal bundle');
        }
    }

    /**
     * @param array<string,mixed> $claim
     * @param array<string,mixed> $referencedState
     */
    public static function activeRunDisposition(
        array $claim,
        array $referencedState,
        string $referencedEventsBytes,
        ?string $referencedEvidenceBytes,
        string $candidateRunId,
        string $candidateIntentSha256,
    ): string {
        self::validateActiveRun($claim);
        self::assertUuidV4($candidateRunId, 'candidate run_id');
        self::assertSha256($candidateIntentSha256, 'candidate intent_sha256');
        if ($candidateRunId !== $claim['run_id'] || !hash_equals($candidateIntentSha256, $claim['intent_sha256'])) {
            throw new RuntimeException('a different run cannot bypass the durable active-run claim');
        }
        $cacheDisposition = self::stateCacheDispositionWithEvidence(
            $referencedState,
            $referencedEventsBytes,
            $referencedEvidenceBytes,
        );
        if (
            $referencedState['run_id'] !== $claim['run_id'] ||
            !hash_equals($referencedState['intent_sha256'], $claim['intent_sha256'])
        ) {
            throw new RuntimeException('active run claim does not bind the referenced state');
        }
        $lines = explode("\n", substr($referencedEventsBytes, 0, -1));
        $run = DeploymentContractV1::validateRunLines($lines);
        if ($claim['sequence'] > count($lines)) {
            throw new RuntimeException('active run claim is ahead of the authoritative journal');
        }
        $claimLines = array_slice($lines, 0, $claim['sequence']);
        $claimEventsBytes = implode("\n", $claimLines) . "\n";
        $claimRun = DeploymentContractV1::validateRunLines($claimLines);
        if (
            $claimRun['run_id'] !== $claim['run_id'] ||
            !hash_equals($claimRun['intent_sha256'], $claim['intent_sha256']) ||
            $claimRun['state'] !== $claim['state'] ||
            $claimRun['records'] !== $claim['sequence'] ||
            !hash_equals(hash('sha256', $claimEventsBytes), $claim['events_sha256'])
        ) {
            throw new RuntimeException('active run claim does not bind a trusted journal prefix');
        }
        if ($run['recovery'] === 'terminal') {
            if ($cacheDisposition !== 'current') {
                throw new RuntimeException('terminal journal requires matching durable state and evidence');
            }
            self::assertReservedUnitsStopped($referencedState);
            if ($claim['state'] !== $referencedState['state']) {
                if (!in_array($claim['state'], self::OBSERVE_ONLY_STATES, true)) {
                    throw new RuntimeException('terminal active run claim contradicts the durable terminal state');
                }
                return 'refresh_terminal_claim';
            }
            if (
                $claim['sequence'] !== $referencedState['sequence'] ||
                !hash_equals($claim['events_sha256'], $referencedState['events_sha256'])
            ) {
                throw new RuntimeException('terminal active run claim does not bind the authoritative journal');
            }
            return 'clear_terminal';
        }
        if (!in_array($claim['state'], self::OBSERVE_ONLY_STATES, true)) {
            throw new RuntimeException('nonterminal journal cannot reconcile a terminal active run claim');
        }

        // The claim and state cache are independently validated journal prefixes. The
        // durable claim remains the global exclusion even when the cache has advanced.
        return 'attach_observe_only';
    }

    /** @param array<string,mixed> $state */
    public static function validateState(array $state): void
    {
        self::assertExactKeys($state, self::STATE_KEYS, 'runner state');
        self::assertSame($state['schema'], self::STATE_SCHEMA, 'runner state schema');
        self::assertUuidV4($state['run_id'], 'run_id');
        self::assertSha256($state['intent_sha256'], 'intent_sha256');
        self::assertEnum(
            $state['state'],
            [
                ...DeploymentContractV1::PROGRESS_STATES,
                DeploymentContractV1::ROLLBACK_RESERVATION_STATE,
                ...DeploymentContractV1::TERMINAL_FAILURE_STATES,
            ],
            'state',
        );
        self::assertPositiveInteger($state['sequence'], 'sequence');
        self::assertSha256($state['events_sha256'], 'events_sha256');
        self::assertEnum($state['active_action'], ['none', 'deploy', 'rollback'], 'active_action');
        self::assertDeployState($state['deploy'], $state['run_id'], $state['intent_sha256']);
        self::assertPostGateState($state['post_gates']);
        self::assertRollbackState($state['rollback'], $state['run_id'], $state['intent_sha256']);
        self::assertNullableSha256($state['evidence_sha256'], 'evidence_sha256');
        self::assertObject($state['terminal'], 'terminal');
        self::assertExactKeys($state['terminal'], self::TERMINAL_STATE_KEYS, 'terminal');
        self::assertUtc($state['updated_at_utc'], 'updated_at_utc');

        self::assertStateReservationCounts($state);
        self::assertStateAction($state);
        self::assertPostGateLifecycle($state);
        self::assertTerminalFields($state);
    }

    /** @return array<string,mixed> */
    public static function decodeState(string $encoded): array
    {
        $state = self::decodeFile($encoded, 'runner state');
        self::validateState($state);

        return $state;
    }

    /** @param array<string,mixed> $state */
    public static function stateCacheDisposition(array $state, string $eventsBytes): string
    {
        return self::stateCacheDispositionWithEvidence($state, $eventsBytes, null);
    }

    /** @param array<string,mixed> $state */
    public static function terminalStateCacheDisposition(
        array $state,
        string $eventsBytes,
        string $evidenceBytes,
    ): string {
        return self::stateCacheDispositionWithEvidence($state, $eventsBytes, $evidenceBytes);
    }

    /** @param array<string,mixed> $state */
    private static function stateCacheDispositionWithEvidence(
        array $state,
        string $eventsBytes,
        ?string $evidenceBytes,
    ): string {
        self::validateState($state);
        if (
            $eventsBytes === '' ||
            strlen($eventsBytes) > 1_048_576 ||
            str_contains($eventsBytes, "\0") ||
            !str_ends_with($eventsBytes, "\n") ||
            str_ends_with($eventsBytes, "\n\n")
        ) {
            throw new RuntimeException('events journal encoding is invalid');
        }
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        $run = DeploymentContractV1::validateRunLines($lines);
        if ($state['sequence'] > $run['records']) {
            throw new RuntimeException('state cache is ahead of the authoritative events journal');
        }
        $stateLines = array_slice($lines, 0, $state['sequence']);
        $stateEventsBytes = implode("\n", $stateLines) . "\n";
        $stateRun = DeploymentContractV1::validateRunLines($stateLines);
        if (
            $run['run_id'] !== $state['run_id'] ||
            !hash_equals($run['intent_sha256'], $state['intent_sha256']) ||
            $stateRun['state'] !== $state['state'] ||
            $stateRun['records'] !== $state['sequence'] ||
            $stateRun['deploy_invocation_count'] !== $state['deploy']['invocation_count'] ||
            !hash_equals(hash('sha256', $stateEventsBytes), $state['events_sha256'])
        ) {
            throw new RuntimeException('state cache contradicts the authoritative events journal');
        }
        $last = json_decode($stateLines[array_key_last($stateLines)], true, 32, JSON_THROW_ON_ERROR);
        $expectsRollback =
            $stateRun['state'] === DeploymentContractV1::ROLLBACK_RESERVATION_STATE ||
            ($last['previous_state'] ?? null) === DeploymentContractV1::ROLLBACK_RESERVATION_STATE;
        if ($expectsRollback !== ($state['rollback']['invocation_count'] === 1)) {
            throw new RuntimeException('state cache contradicts the rollback reservation');
        }

        $stateIsTerminal = self::isTerminalState($state['state']);
        if ($stateIsTerminal) {
            if (
                ($last['exit_code'] ?? null) !== $state['terminal']['exit_code'] ||
                ($last['reason'] ?? null) !== $state['terminal']['reason']
            ) {
                throw new RuntimeException('terminal state cache contradicts the authoritative journal result');
            }
            if ($state['sequence'] !== $run['records'] || $evidenceBytes === null) {
                throw new RuntimeException('terminal state cache requires matching durable evidence');
            }
            $evidence = self::decodeEvidenceFile($evidenceBytes);
            $bundle = DeploymentContractV1::validateBundle($lines, $evidence);
            self::assertTerminalActionEvidence($state, $evidence);
            if (
                !hash_equals(hash('sha256', $evidenceBytes), $state['evidence_sha256']) ||
                $bundle['state'] !== $state['state'] ||
                $bundle['run_id'] !== $state['run_id']
            ) {
                throw new RuntimeException('terminal state cache does not bind the durable evidence bytes');
            }
        } elseif ($evidenceBytes !== null) {
            throw new RuntimeException('nonterminal state cache cannot consume terminal evidence');
        }

        return $state['sequence'] === $run['records'] ? 'current' : 'stale_recoverable';
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $evidence */
    private static function assertTerminalActionEvidence(array $state, array $evidence): void
    {
        if ($state['deploy']['invocation_count'] !== $evidence['deploy']['invocation_count']) {
            throw new RuntimeException('terminal state deploy outcome contradicts the durable evidence');
        }
        if (
            $evidence['deploy']['exit_code'] !== null &&
            $state['deploy']['observed_exit_code'] !== $evidence['deploy']['exit_code']
        ) {
            throw new RuntimeException('terminal state deploy outcome contradicts the durable evidence');
        }
        $hasAcceptedReceipt = $state['deploy']['receipt_sha256'] !== null;
        $evidenceHasKnownDeployOutcome = in_array($evidence['deploy']['status'], ['succeeded', 'failed'], true);
        if ($hasAcceptedReceipt !== $evidenceHasKnownDeployOutcome) {
            throw new RuntimeException('terminal state deploy outcome contradicts the durable evidence');
        }
        if (
            $evidenceHasKnownDeployOutcome &&
            !hash_equals(self::receiptSha256ForDeployEvidence($evidence['deploy']), $state['deploy']['receipt_sha256'])
        ) {
            throw new RuntimeException('terminal state receipt hash does not bind the durable deploy evidence');
        }
        if (
            $state['rollback']['invocation_count'] !== $evidence['rollback']['invocation_count'] ||
            $state['rollback']['verdict'] !== $evidence['rollback']['status']
        ) {
            throw new RuntimeException('terminal state rollback outcome contradicts the durable evidence');
        }
        if (
            $state['post_gates']['deploy_submission_count'] === 1 &&
            $state['post_gates']['deploy_verdict'] !== $evidence['post_gates']['status']
        ) {
            throw new RuntimeException('terminal state deploy post-gates contradict the durable evidence');
        }
    }

    /** @param array<string,mixed> $deployEvidence */
    private static function receiptSha256ForDeployEvidence(array $deployEvidence): string
    {
        ksort($deployEvidence);
        $matchingOutcomes = [];
        foreach (array_keys(DeployResultV1::OUTCOME_EXIT_CODES) as $outcome) {
            $candidate = DeployResultV1::deployEvidence($outcome);
            ksort($candidate);
            if ($candidate === $deployEvidence) {
                $matchingOutcomes[] = $outcome;
            }
        }
        if (count($matchingOutcomes) !== 1) {
            throw new RuntimeException('known deploy evidence does not identify one deploy result outcome');
        }

        $outcome = $matchingOutcomes[0];
        $receipt = DeployResultV1::create($outcome, DeployResultV1::OUTCOME_EXIT_CODES[$outcome]);

        return hash('sha256', DeployResultV1::canonicalJson($receipt));
    }

    /** @param array<string,mixed> $event */
    public static function validateOperatorEvent(array $event): void
    {
        self::assertExactKeys($event, self::OPERATOR_EVENT_KEYS, 'operator event');
        self::assertSame($event['schema'], self::OPERATOR_EVENT_SCHEMA, 'operator event schema');
        self::assertUuidV4($event['run_id'], 'run_id');
        self::assertSha256($event['intent_sha256'], 'intent_sha256');
        self::assertPositiveInteger($event['sequence'], 'sequence');
        self::assertUtc($event['recorded_at_utc'], 'recorded_at_utc');
        self::assertEnum($event['action'], ['none', 'deploy', 'rollback', 'reconcile'], 'action');
        self::assertEnum($event['event'], self::OPERATOR_EVENTS, 'event');
        self::assertEnum($event['status'], self::OPERATOR_STATUSES, 'status');
        self::assertEnum($event['reason'], self::OPERATOR_REASONS, 'reason');
    }

    /** @return array<string,mixed> */
    public static function decodeOperatorEvent(string $encoded): array
    {
        $event = self::decodeFile($encoded, 'operator event');
        self::validateOperatorEvent($event);

        return $event;
    }

    /** @param array<string,mixed> $claim */
    public static function validateActiveRun(array $claim): void
    {
        self::assertExactKeys($claim, self::ACTIVE_RUN_KEYS, 'active run');
        self::assertSame($claim['schema'], self::ACTIVE_RUN_SCHEMA, 'active run schema');
        self::assertUuidV4($claim['run_id'], 'run_id');
        self::assertSha256($claim['intent_sha256'], 'intent_sha256');
        self::assertEnum(
            $claim['state'],
            [...self::OBSERVE_ONLY_STATES, 'succeeded', ...DeploymentContractV1::TERMINAL_FAILURE_STATES],
            'active run state',
        );
        self::assertPositiveInteger($claim['sequence'], 'sequence');
        self::assertSha256($claim['events_sha256'], 'events_sha256');
        self::assertUtc($claim['claimed_at_utc'], 'claimed_at_utc');
    }

    /** @return array<string,mixed> */
    public static function decodeActiveRun(string $encoded): array
    {
        $claim = self::decodeFile($encoded, 'active run');
        self::validateActiveRun($claim);

        return $claim;
    }

    public static function unitName(string $action, string $runId, string $intentSha256): string
    {
        self::assertEnum($action, ['deploy', 'rollback'], 'unit action');
        self::assertUuidV4($runId, 'run_id');
        self::assertSha256($intentSha256, 'intent_sha256');

        return sprintf('fh-%s-%s-%s.service', $action, $runId, substr($intentSha256, 0, 12));
    }

    public static function runLockPath(string $runId): string
    {
        self::assertUuidV4($runId, 'run_id');

        return self::STATE_ROOT . '/runs/' . $runId . '/run.lock';
    }

    public static function activeRunPath(): string
    {
        return self::STATE_ROOT . '/active-run.json';
    }

    /**
     * @return array{
     *   deploy:list<string>,
     *   post_gates:list<string>,
     *   recovery:list<string>,
     *   reconcile:list<string>,
     *   usage_exit:int,
     *   invalid_exit:int,
     *   conflict_exit:int,
     *   terminal_attach_exit:int
     * }
     */
    public static function cliContract(): array
    {
        return [
            'deploy' => ['--action=deploy', '--request-file=ABSOLUTE_PATH', '--execution-input-file=ABSOLUTE_PATH'],
            'post_gates' => ['--action=post-gates', '--request-file=ABSOLUTE_PATH', '--report-file=ABSOLUTE_PATH'],
            'recovery' => ['--action=recovery', '--request-file=ABSOLUTE_PATH', '--execution-input-file=ABSOLUTE_PATH'],
            'reconcile' => ['--action=reconcile', '--run-id=UUIDV4', '--intent-sha256=SHA256'],
            'usage_exit' => 64,
            'invalid_exit' => 70,
            'conflict_exit' => 75,
            'terminal_attach_exit' => 0,
        ];
    }

    /** @return array<string,string> */
    public static function unitProperties(string $action): array
    {
        self::assertEnum($action, ['deploy', 'rollback'], 'unit action');

        return [
            'Type' => 'exec',
            'RemainAfterExit' => 'yes',
            'UMask' => '0077',
            'KillMode' => 'control-group',
            'Restart' => 'no',
            'RuntimeMaxSec' => $action === 'deploy' ? '7200s' : '1800s',
            'TimeoutStopSec' => '300s',
            'StandardInput' => 'null',
            'StandardOutput' => 'null',
            'StandardError' => 'null',
        ];
    }

    /** @return array{disposition:string,observed_exit_code:int}|array{state:string,exit_code:int,reason:string} */
    public static function rollbackNormalExitResult(int $observedExitCode): array
    {
        if ($observedExitCode < 0 || $observedExitCode > 255) {
            throw new RuntimeException('rollback exit must be a byte exit code');
        }
        if ($observedExitCode === 0) {
            return ['disposition' => 'post_recovery_verification_required', 'observed_exit_code' => 0];
        }

        return ['state' => 'failed_post_switch_rollback_failed', 'exit_code' => 31, 'reason' => 'rollback_failed'];
    }

    /** @param array<string,mixed> $response */
    public static function validateResponse(array $response): void
    {
        self::assertExactKeys($response, self::RESPONSE_KEYS, 'runner response');
        self::assertSame($response['schema'], self::RESPONSE_SCHEMA, 'runner response schema');
        self::assertUuidV4($response['run_id'], 'run_id');
        self::assertSha256($response['intent_sha256'], 'intent_sha256');
        self::assertEnum($response['action'], self::CLI_ACTIONS, 'action');
        self::assertEnum(
            $response['disposition'],
            ['accepted', 'attach_pre_deploy', 'attach_observe_only', 'terminal', 'rejected'],
            'disposition',
        );
        self::assertNullableString($response['state'], 'state');
        self::assertNullableExitCode($response['result_exit_code'], 'result_exit_code');
        self::assertNullableString($response['result_reason'], 'result_reason');

        if ($response['disposition'] === 'rejected') {
            if ($response['state'] !== null) {
                throw new RuntimeException('rejected response cannot claim a state');
            }
            self::assertStablePair($response['result_exit_code'], $response['result_reason']);
            if (!in_array($response['result_exit_code'], [70, 75], true)) {
                throw new RuntimeException('rejected response must use contract_invalid or state_conflict');
            }
            return;
        }

        if (!is_string($response['state'])) {
            throw new RuntimeException('accepted response must include state');
        }
        if ($response['disposition'] === 'terminal') {
            self::assertTerminalState($response['state']);
            self::assertStablePair($response['result_exit_code'], $response['result_reason']);
            self::assertExitAllowedForState($response['state'], $response['result_exit_code']);
            return;
        }
        $nonterminalStates = array_values(
            array_filter(
                DeploymentContractV1::PROGRESS_STATES,
                static fn(string $state): bool => $state !== 'succeeded',
            ),
        );
        self::assertEnum(
            $response['state'],
            [...$nonterminalStates, DeploymentContractV1::ROLLBACK_RESERVATION_STATE],
            'response state',
        );
        if ($response['result_exit_code'] !== 0 || $response['result_reason'] !== 'ok') {
            throw new RuntimeException('nonterminal accepted response must use ok');
        }
        self::assertResponseActionDisposition($response['action'], $response['disposition'], $response['state']);
    }

    /** @return array<string,mixed> */
    public static function decodeResponse(string $encoded): array
    {
        $response = self::decodeFile($encoded, 'runner response');
        self::validateResponse($response);

        return $response;
    }

    /** @param array<string,mixed> $response */
    public static function cliExitCode(array $response): int
    {
        self::validateResponse($response);

        return $response['disposition'] === 'rejected' ? $response['result_exit_code'] : 0;
    }

    /** @param array<string,mixed> $value */
    public static function encodeFile(array $value): string
    {
        return DeploymentContractV1::canonicalJson($value) . "\n";
    }

    public static function fileSha256(string $encoded): string
    {
        return hash('sha256', $encoded);
    }

    /** @return array<string,mixed> */
    private static function decodeFile(string $encoded, string $context): array
    {
        return self::decodeBoundedFile($encoded, $context, 4096);
    }

    /** @return array<string,mixed> */
    private static function decodeBoundedFile(string $encoded, string $context, int $maxBytes): array
    {
        if ($encoded === '' || strlen($encoded) > $maxBytes || str_contains($encoded, "\0")) {
            throw new RuntimeException($context . ' encoding is invalid');
        }
        try {
            $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($context . ' JSON is invalid', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($context . ' must be an object');
        }
        if (!hash_equals(self::encodeFile($decoded), $encoded)) {
            throw new RuntimeException($context . ' is not canonical');
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private static function decodeEvidenceFile(string $encoded): array
    {
        if ($encoded === '' || strlen($encoded) > 65_536 || str_contains($encoded, "\0")) {
            throw new RuntimeException('deployment evidence encoding is invalid');
        }
        try {
            $decoded = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('deployment evidence JSON is invalid', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('deployment evidence must be an object');
        }
        if (!hash_equals(DeploymentContractV1::canonicalJson($decoded) . "\n", $encoded)) {
            throw new RuntimeException('deployment evidence is not canonical');
        }

        return $decoded;
    }

    private static function assertResponseActionDisposition(string $action, string $disposition, string $state): void
    {
        $allowedStates = match ($action . ':' . $disposition) {
            'deploy:accepted' => ['deploy_running'],
            'deploy:attach_pre_deploy', 'reconcile:attach_pre_deploy' => self::PRE_DEPLOY_STATES,
            'deploy:attach_observe_only', 'reconcile:attach_observe_only' => self::OBSERVE_ONLY_STATES,
            'post-gates:accepted' => ['post_gates_running'],
            'post-gates:attach_observe_only' => [
                'post_gates_running',
                DeploymentContractV1::ROLLBACK_RESERVATION_STATE,
            ],
            'recovery:accepted' => [DeploymentContractV1::ROLLBACK_RESERVATION_STATE],
            'recovery:attach_observe_only' => [DeploymentContractV1::ROLLBACK_RESERVATION_STATE],
            default => [],
        };
        if (!in_array($state, $allowedStates, true)) {
            throw new RuntimeException('runner response action, disposition, and state are incompatible');
        }
    }

    private static function isTerminalState(string $state): bool
    {
        return $state === 'succeeded' || in_array($state, DeploymentContractV1::TERMINAL_FAILURE_STATES, true);
    }

    /** @param array<string,mixed> $state */
    private static function assertStateReservationCounts(array $state): void
    {
        $deployStates = [
            'deploy_running',
            'post_gates_running',
            DeploymentContractV1::ROLLBACK_RESERVATION_STATE,
            'succeeded',
            'failed_pre_switch',
            'failed_switch_recovery_required',
            'failed_post_switch_rollback_succeeded',
            'failed_post_switch_rollback_failed',
            'manual_recovery_required',
        ];
        if (in_array($state['state'], $deployStates, true) && $state['deploy']['invocation_count'] !== 1) {
            throw new RuntimeException('runner state must preserve the deploy reservation');
        }
        if (!in_array($state['state'], $deployStates, true) && $state['deploy']['invocation_count'] !== 0) {
            throw new RuntimeException('runner state invents a deploy reservation');
        }
        if (
            $state['state'] === DeploymentContractV1::ROLLBACK_RESERVATION_STATE &&
            $state['rollback']['invocation_count'] !== 1
        ) {
            throw new RuntimeException('rollback_running requires the dedicated rollback reservation');
        }
        if (
            !self::isTerminalState($state['state']) &&
            $state['state'] !== DeploymentContractV1::ROLLBACK_RESERVATION_STATE &&
            $state['rollback']['invocation_count'] !== 0
        ) {
            throw new RuntimeException('runner state invents a rollback reservation');
        }
        if (
            in_array($state['state'], ['post_gates_running', DeploymentContractV1::ROLLBACK_RESERVATION_STATE], true) &&
            ($state['deploy']['unit_state'] !== 'exited' ||
                $state['deploy']['observed_exit_code'] !== 0 ||
                $state['deploy']['receipt_sha256'] === null)
        ) {
            throw new RuntimeException('post-deploy state requires a completed successful deploy result');
        }
    }

    /** @param array<string,mixed> $state */
    private static function assertStateAction(array $state): void
    {
        $expected = match ($state['state']) {
            'deploy_running' => 'deploy',
            DeploymentContractV1::ROLLBACK_RESERVATION_STATE => 'rollback',
            default => 'none',
        };
        if ($state['active_action'] !== $expected) {
            throw new RuntimeException('active_action contradicts the deployment state');
        }
    }

    private static function assertDeployState(mixed $deploy, string $runId, string $intentSha256): void
    {
        self::assertObject($deploy, 'deploy');
        self::assertExactKeys($deploy, self::DEPLOY_STATE_KEYS, 'deploy');
        self::assertSha256($deploy['request_sha256'], 'deploy.request_sha256');
        self::assertNullableSha256($deploy['execution_input_sha256'], 'deploy.execution_input_sha256');
        self::assertReservationCount($deploy['invocation_count'], 'deploy.invocation_count');
        self::assertActionUnit(
            'deploy',
            $runId,
            $intentSha256,
            $deploy['invocation_count'],
            $deploy['unit_name'],
            $deploy['unit_state'],
            $deploy['observed_exit_code'],
        );
        self::assertNullableSha256($deploy['receipt_sha256'], 'deploy.receipt_sha256');
        if ($deploy['invocation_count'] === 0 && $deploy['execution_input_sha256'] !== null) {
            throw new RuntimeException('deploy execution input cannot precede reservation');
        }
        if ($deploy['invocation_count'] === 1 && $deploy['execution_input_sha256'] === null) {
            throw new RuntimeException('reserved deploy must bind execution input');
        }
        if ($deploy['receipt_sha256'] !== null && $deploy['observed_exit_code'] === null) {
            throw new RuntimeException('receipt hash requires an independently observed child exit');
        }
        if (
            $deploy['receipt_sha256'] !== null &&
            !in_array($deploy['observed_exit_code'], [0, 30, 31, 32, 143], true)
        ) {
            throw new RuntimeException('receipt hash requires a valid deploy result exit');
        }
    }

    private static function assertRollbackState(mixed $rollback, string $runId, string $intentSha256): void
    {
        self::assertObject($rollback, 'rollback');
        self::assertExactKeys($rollback, self::ROLLBACK_STATE_KEYS, 'rollback');
        self::assertNullableSha256($rollback['request_sha256'], 'rollback.request_sha256');
        self::assertNullableSha256($rollback['execution_input_sha256'], 'rollback.execution_input_sha256');
        self::assertReservationCount($rollback['invocation_count'], 'rollback.invocation_count');
        self::assertActionUnit(
            'rollback',
            $runId,
            $intentSha256,
            $rollback['invocation_count'],
            $rollback['unit_name'],
            $rollback['unit_state'],
            $rollback['observed_exit_code'],
        );
        self::assertEnum(
            $rollback['verdict'],
            ['not_invoked', 'verification_pending', 'succeeded', 'failed', 'unknown'],
            'rollback.verdict',
        );
        if ($rollback['invocation_count'] === 0) {
            if (
                $rollback['request_sha256'] !== null ||
                $rollback['execution_input_sha256'] !== null ||
                $rollback['verdict'] !== 'not_invoked'
            ) {
                throw new RuntimeException('rollback fields cannot precede reservation');
            }
        } elseif ($rollback['request_sha256'] === null || $rollback['execution_input_sha256'] === null) {
            throw new RuntimeException('reserved rollback must bind request and execution input');
        }
        if ($rollback['invocation_count'] === 1 && $rollback['verdict'] === 'not_invoked') {
            throw new RuntimeException('reserved rollback must record an observed or unknown verdict');
        }
        if (
            in_array($rollback['verdict'], ['verification_pending', 'succeeded', 'failed'], true) &&
            $rollback['observed_exit_code'] === null
        ) {
            throw new RuntimeException('known rollback verdict requires an independently observed exit');
        }
        if (
            in_array($rollback['verdict'], ['verification_pending', 'succeeded'], true) &&
            $rollback['observed_exit_code'] !== 0
        ) {
            throw new RuntimeException('rollback verification requires exit zero');
        }
        if ($rollback['verdict'] === 'unknown' && $rollback['observed_exit_code'] !== null) {
            throw new RuntimeException('unknown rollback verdict cannot retain an observed exit');
        }
    }

    private static function assertPostGateState(mixed $postGates): void
    {
        self::assertObject($postGates, 'post_gates');
        self::assertExactKeys($postGates, self::POST_GATE_STATE_KEYS, 'post_gates');
        foreach (['deploy', 'rollback'] as $subject) {
            $sha = $subject . '_report_sha256';
            $count = $subject . '_submission_count';
            $verdict = $subject . '_verdict';
            self::assertNullableSha256($postGates[$sha], 'post_gates.' . $sha);
            self::assertReservationCount($postGates[$count], 'post_gates.' . $count);
            self::assertEnum($postGates[$verdict], ['not_submitted', 'passed', 'failed'], 'post_gates.' . $verdict);
            if ($postGates[$count] === 0) {
                if ($postGates[$sha] !== null || $postGates[$verdict] !== 'not_submitted') {
                    throw new RuntimeException($subject . ' post-gate fields precede submission');
                }
            } elseif ($postGates[$sha] === null || $postGates[$verdict] === 'not_submitted') {
                throw new RuntimeException($subject . ' post-gate submission is incomplete');
            }
        }
        if ($postGates['rollback_submission_count'] === 1 && $postGates['deploy_verdict'] !== 'failed') {
            throw new RuntimeException('rollback post-gates require a failed deploy report');
        }
    }

    /** @param array<string,mixed> $state */
    private static function assertPostGateLifecycle(array $state): void
    {
        $postGates = $state['post_gates'];
        if (
            $postGates['deploy_submission_count'] === 1 &&
            !in_array(
                $state['state'],
                [
                    'post_gates_running',
                    DeploymentContractV1::ROLLBACK_RESERVATION_STATE,
                    'succeeded',
                    'failed_post_switch_rollback_succeeded',
                    'failed_post_switch_rollback_failed',
                    'manual_recovery_required',
                ],
                true,
            )
        ) {
            throw new RuntimeException('deploy post-gate report cannot precede the post-gate lifecycle');
        }
        if (
            $postGates['deploy_submission_count'] === 1 &&
            ($state['deploy']['unit_state'] !== 'exited' ||
                $state['deploy']['observed_exit_code'] !== 0 ||
                $state['deploy']['receipt_sha256'] === null)
        ) {
            throw new RuntimeException('deploy post-gates require a completed deploy result');
        }
        if (
            $state['state'] === DeploymentContractV1::ROLLBACK_RESERVATION_STATE &&
            $postGates['deploy_verdict'] !== 'failed'
        ) {
            throw new RuntimeException('rollback reservation requires a failed deploy report');
        }
        if (
            $postGates['rollback_submission_count'] === 1 &&
            ($state['rollback']['invocation_count'] !== 1 ||
                $state['rollback']['unit_state'] !== 'exited' ||
                $state['rollback']['observed_exit_code'] !== 0 ||
                $postGates['deploy_verdict'] !== 'failed')
        ) {
            throw new RuntimeException('rollback post-gates require a completed recovery action');
        }
        if ($state['rollback']['invocation_count'] === 1) {
            $observedExit = $state['rollback']['observed_exit_code'];
            $rollbackVerdict = $state['rollback']['verdict'];
            $reportCount = $postGates['rollback_submission_count'];
            $reportVerdict = $postGates['rollback_verdict'];
            $validRollbackTuple = match (true) {
                $observedExit === null => $rollbackVerdict === 'unknown' && $reportCount === 0,
                $observedExit === 0 && $reportCount === 0 => $rollbackVerdict === 'verification_pending',
                $observedExit === 0 && $reportCount === 1 => ($reportVerdict === 'passed' &&
                    $rollbackVerdict === 'succeeded') ||
                    ($reportVerdict === 'failed' && $rollbackVerdict === 'failed'),
                is_int($observedExit) && $observedExit !== 0 => $rollbackVerdict === 'failed' && $reportCount === 0,
                default => false,
            };
            if (!$validRollbackTuple) {
                throw new RuntimeException('rollback exit, report, and verdict are incompatible');
            }
        }
        if ($state['state'] === 'succeeded' && $postGates['deploy_verdict'] !== 'passed') {
            throw new RuntimeException('succeeded state requires passed deploy post-gates');
        }
        if ($state['state'] === 'failed_post_switch_rollback_succeeded') {
            if ($state['rollback']['invocation_count'] === 0) {
                if ($postGates['deploy_submission_count'] !== 0 || $postGates['rollback_submission_count'] !== 0) {
                    throw new RuntimeException(
                        'direct rollback-success deploy result cannot contain post-gate submissions',
                    );
                }
            } elseif ($postGates['deploy_verdict'] !== 'failed' || $postGates['rollback_verdict'] !== 'passed') {
                throw new RuntimeException('rollback-success terminal state contradicts post-gates');
            }
        }
        if ($state['state'] === 'failed_post_switch_rollback_failed') {
            if ($state['rollback']['invocation_count'] === 0) {
                if ($postGates['deploy_submission_count'] !== 0 || $postGates['rollback_submission_count'] !== 0) {
                    throw new RuntimeException(
                        'direct rollback-failed deploy result cannot contain post-gate submissions',
                    );
                }
                return;
            }
            $failedCheck =
                $postGates['rollback_verdict'] === 'failed' && $state['rollback']['observed_exit_code'] === 0;
            $failedAction =
                $postGates['rollback_submission_count'] === 0 &&
                is_int($state['rollback']['observed_exit_code']) &&
                $state['rollback']['observed_exit_code'] !== 0;
            if ($postGates['deploy_verdict'] !== 'failed' || (!$failedCheck && !$failedAction)) {
                throw new RuntimeException('rollback-failed terminal state contradicts post-gates');
            }
        }
    }

    /** @param array<string,mixed> $state */
    private static function assertReservedUnitsStopped(array $state): void
    {
        foreach (['deploy', 'rollback'] as $action) {
            if (
                $state[$action]['invocation_count'] === 1 &&
                !in_array($state[$action]['unit_state'], ['exited', 'failed', 'killed'], true)
            ) {
                throw new RuntimeException('terminal active run claim requires every reserved unit to be stopped');
            }
        }
    }

    private static function assertActionUnit(
        string $action,
        string $runId,
        string $intentSha256,
        int $invocationCount,
        mixed $unitName,
        mixed $unitState,
        mixed $observedExitCode,
    ): void {
        self::assertNullableString($unitName, $action . '.unit_name');
        self::assertEnum($unitState, self::UNIT_STATES, $action . '.unit_state');
        self::assertNullableExitCode($observedExitCode, $action . '.observed_exit_code');
        if ($invocationCount === 0) {
            if ($unitName !== null || $unitState !== 'not_created' || $observedExitCode !== null) {
                throw new RuntimeException($action . ' unit cannot precede reservation');
            }
            return;
        }
        if ($unitName !== self::unitName($action, $runId, $intentSha256) || $unitState === 'not_created') {
            throw new RuntimeException($action . ' unit does not bind the reserved action');
        }
        if ($observedExitCode !== null && !in_array($unitState, ['exited', 'failed'], true)) {
            throw new RuntimeException('observed exit requires a terminal unit state');
        }
    }

    /** @param array<string,mixed> $state */
    private static function assertTerminalFields(array $state): void
    {
        $terminal =
            $state['state'] === 'succeeded' ||
            in_array($state['state'], DeploymentContractV1::TERMINAL_FAILURE_STATES, true);
        if (!$terminal) {
            if (
                $state['terminal']['state'] !== null ||
                $state['terminal']['exit_code'] !== null ||
                $state['terminal']['reason'] !== null ||
                $state['evidence_sha256'] !== null
            ) {
                throw new RuntimeException('nonterminal runner state contains terminal evidence');
            }
            return;
        }
        if ($state['terminal']['state'] !== $state['state'] || $state['evidence_sha256'] === null) {
            throw new RuntimeException('terminal runner state is incomplete');
        }
        self::assertStablePair($state['terminal']['exit_code'], $state['terminal']['reason']);
        self::assertExitAllowedForState($state['state'], $state['terminal']['exit_code']);
    }

    private static function assertExitAllowedForState(string $state, int $exitCode): void
    {
        $allowed = match ($state) {
            'succeeded' => [0],
            'failed_before_write' => [20, 21, 22, 23, 24, 25, 70, 75, 143],
            'failed_pre_switch' => [30, 143],
            'failed_switch_recovery_required' => [32],
            'failed_post_switch_rollback_succeeded' => [30],
            'failed_post_switch_rollback_failed' => [31],
            'manual_recovery_required' => [31, 70, 143],
            default => [],
        };
        if (!in_array($exitCode, $allowed, true)) {
            throw new RuntimeException('terminal exit is incompatible with terminal state');
        }
    }

    private static function assertStablePair(mixed $exitCode, mixed $reason): void
    {
        if (!is_int($exitCode) || !is_string($reason)) {
            throw new RuntimeException('result pair has invalid types');
        }
        if ((DeploymentContractV1::EXIT_REASONS[$reason] ?? null) !== $exitCode) {
            throw new RuntimeException('result exit and reason are not a stable pair');
        }
    }

    private static function assertTerminalState(string $state): void
    {
        if ($state !== 'succeeded' && !in_array($state, DeploymentContractV1::TERMINAL_FAILURE_STATES, true)) {
            throw new RuntimeException('response terminal state is invalid');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $context): void
    {
        if (array_is_list($value)) {
            throw new RuntimeException($context . ' must be an object');
        }
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException($context . ' contains missing or unexpected fields');
        }
    }

    private static function assertUuidV4(mixed $value, string $field): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1
        ) {
            throw new RuntimeException($field . ' must be a lowercase UUIDv4');
        }
    }

    private static function assertReleaseId(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value) !== 1) {
            throw new RuntimeException('release_id is invalid');
        }
    }

    private static function assertCanonicalAbsolutePath(mixed $value, string $field): void
    {
        if (
            !is_string($value) ||
            $value === '/' ||
            $value === '' ||
            $value[0] !== '/' ||
            strlen($value) > 4095 ||
            str_ends_with($value, '/') ||
            str_contains($value, '//') ||
            preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            throw new RuntimeException($field . ' must be a canonical absolute non-root path');
        }
        foreach (explode('/', substr($value, 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..' || strlen($component) > 255) {
                throw new RuntimeException($field . ' contains an invalid path component');
            }
        }
    }

    private static function assertProtectedFileReference(mixed $value, string $field): void
    {
        self::assertObject($value, $field);
        self::assertExactKeys($value, ['path', 'sha256'], $field);
        self::assertCanonicalAbsolutePath($value['path'], $field . '.path');
        self::assertSha256($value['sha256'], $field . '.sha256');
    }

    private static function assertObject(mixed $value, string $field): void
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($field . ' must be an object');
        }
    }

    private static function assertSha256(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException($field . ' must be a lowercase SHA-256');
        }
    }

    private static function assertNullableSha256(mixed $value, string $field): void
    {
        if ($value !== null) {
            self::assertSha256($value, $field);
        }
    }

    private static function assertUtc(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new RuntimeException($field . ' must be a second-precision UTC timestamp');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException($field . ' must be a real second-precision UTC timestamp');
        }
    }

    /** @param list<string> $allowed */
    private static function assertEnum(mixed $value, array $allowed, string $field): void
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RuntimeException($field . ' is invalid');
        }
    }

    private static function assertPositiveInteger(mixed $value, string $field): void
    {
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException($field . ' must be a positive integer');
        }
    }

    private static function assertReservationCount(mixed $value, string $field): void
    {
        if (!is_int($value) || !in_array($value, [0, 1], true)) {
            throw new RuntimeException($field . ' must be zero or one');
        }
    }

    private static function assertNullableExitCode(mixed $value, string $field): void
    {
        if ($value !== null && (!is_int($value) || $value < 0 || $value > 255)) {
            throw new RuntimeException($field . ' must be null or a byte exit code');
        }
    }

    private static function assertNullableString(mixed $value, string $field): void
    {
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException($field . ' must be null or a string');
        }
    }

    private static function assertString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new RuntimeException($field . ' must be a string');
        }

        return $value;
    }

    private static function assertSame(mixed $actual, mixed $expected, string $field): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException($field . ' is invalid');
        }
    }
}

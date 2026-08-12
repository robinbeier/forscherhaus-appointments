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
    public const SYSTEMD_LAUNCH_SCHEMA = 'deployment_host_systemd_launch.v1';
    public const UNIT_BINDING_SCHEMA = 'deployment_host_systemd_unit_binding.v1';
    public const UNIT_ABSENCE_SCHEMA = 'deployment_host_systemd_absence.v1';
    public const UNIT_LOADED_OBSERVATION_SCHEMA = 'deployment_host_systemd_loaded_observation.v1';
    public const STATE_SCHEMA = 'deployment_host_runner_state.v1';
    public const OPERATOR_EVENT_SCHEMA = 'deployment_host_operator_event.v1';
    public const ACTIVE_RUN_SCHEMA = 'deployment_host_active_run.v1';
    public const RESPONSE_SCHEMA = 'deployment_host_runner_response.v1';

    public const STATE_ROOT = '/var/lib/fh-deploy-orchestrator';
    public const GLOBAL_LOCK_PATH = self::STATE_ROOT . '/locks/fh-production-change.lock';
    public const MANAGER_BOOT_ID_PATH = '/proc/sys/kernel/random/boot_id';

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

    private const SYSTEMD_LAUNCH_KEYS = [
        'schema',
        'action',
        'run_id',
        'intent_sha256',
        'request_sha256',
        'execution_input_sha256',
        'deploy_script_sha256',
        'argv_sha256',
        'environment_sha256',
        'properties_sha256',
        'unit_name',
        'properties',
        'launch_nonce',
    ];

    private const UNIT_BINDING_KEYS = [
        'schema',
        'run_id',
        'intent_sha256',
        'action',
        'unit_name',
        'unit_launch_sha256',
        'unit_manager_boot_id',
        'unit_invocation_id',
        'binding_state',
    ];

    private const UNIT_ABSENCE_KEYS = ['schema', 'kind', 'manager_boot_id'];

    private const UNIT_LOADED_OBSERVATION_KEYS = ['schema', 'manager_boot_id', 'systemctl_show'];

    private const SYSTEMCTL_SHOW_KEYS = [
        'Id',
        'LoadState',
        'ActiveState',
        'SubState',
        'Result',
        'ExecMainCode',
        'ExecMainStatus',
        'InvocationID',
        'Description',
        'Transient',
        'Type',
        'RemainAfterExit',
        'UMask',
        'KillMode',
        'Restart',
        'RuntimeMaxUSec',
        'TimeoutStopUSec',
        'StandardInput',
        'StandardOutput',
        'StandardError',
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
        'unit_launch_sha256',
        'unit_manager_boot_id',
        'unit_invocation_id',
        'unit_missing_observed_boot_id',
        'unit_state',
        'observed_exit_code',
        'receipt_sha256',
    ];

    private const ROLLBACK_STATE_KEYS = [
        'request_sha256',
        'execution_input_sha256',
        'invocation_count',
        'unit_name',
        'unit_launch_sha256',
        'unit_manager_boot_id',
        'unit_invocation_id',
        'unit_missing_observed_boot_id',
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

    private const UNIT_STATES = [
        'not_created',
        'starting',
        'running',
        'exited',
        'failed',
        'killed',
        'missing',
        'unknown',
    ];

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
        'ok',
        'traffic_hard_stop',
        'traffic_evidence_invalid',
        'dump_verification_failed',
        'capacity_gate_failed',
        'artifact_verification_failed',
        'expected_commit_mismatch',
        'deploy_failed',
        'rollback_failed',
        'switch_recovery_required',
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
        ?string $deployPostGateReportBytes = null,
        ?string $rollbackPostGateReportBytes = null,
        array $unitReconciliationBundles = [],
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
            $deployPostGateReportBytes,
            $rollbackPostGateReportBytes,
            $unitReconciliationBundles,
            true,
        );
    }

    /** @param list<string> $existingLines @param array<string,mixed> $request @param ?array<string,mixed> $currentState */
    public static function recoveryAttachmentDisposition(
        array $existingLines,
        array $request,
        ?array $currentState = null,
        ?string $terminalEvidenceBytes = null,
        ?string $deployPostGateReportBytes = null,
        ?string $rollbackPostGateReportBytes = null,
        array $unitReconciliationBundles = [],
    ): string {
        self::validateRecoveryRequest($request);
        $run = DeploymentContractV1::validateRunLines($existingLines);
        if ($run['run_id'] !== $request['run_id'] || !hash_equals($run['intent_sha256'], $request['intent_sha256'])) {
            throw new RuntimeException('recovery request does not bind the existing run intent');
        }
        if ($run['state'] === 'post_gates_running') {
            if (
                $currentState === null ||
                $terminalEvidenceBytes !== null ||
                $deployPostGateReportBytes === null ||
                $rollbackPostGateReportBytes !== null ||
                $unitReconciliationBundles !== []
            ) {
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
            if (
                $currentState === null ||
                $terminalEvidenceBytes !== null ||
                $deployPostGateReportBytes === null ||
                $rollbackPostGateReportBytes !== null ||
                $unitReconciliationBundles !== []
            ) {
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
                $deployPostGateReportBytes,
                $rollbackPostGateReportBytes,
                $unitReconciliationBundles,
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
        ?string $deployPostGateReportBytes,
        ?string $rollbackPostGateReportBytes,
        array $unitReconciliationBundles,
    ): string {
        if ($disposition !== 'terminal') {
            self::assertNoTerminalAttachmentBundle(
                $terminalState,
                $terminalEvidenceBytes,
                $deployPostGateReportBytes,
                $rollbackPostGateReportBytes,
                $unitReconciliationBundles,
            );
            return $disposition;
        }
        if ($terminalState === null || $terminalEvidenceBytes === null) {
            throw new RuntimeException('terminal attachment requires durable state and evidence');
        }
        $eventsBytes = implode("\n", $existingLines) . "\n";
        if (
            self::terminalStateCacheDisposition(
                $terminalState,
                $eventsBytes,
                $terminalEvidenceBytes,
                $deployPostGateReportBytes,
                $rollbackPostGateReportBytes,
                $unitReconciliationBundles,
            ) !== 'current'
        ) {
            throw new RuntimeException('terminal attachment bundle is not current');
        }

        return 'terminal';
    }

    /** @param ?array<string,mixed> $terminalState */
    private static function assertNoTerminalAttachmentBundle(
        ?array $terminalState,
        ?string $terminalEvidenceBytes,
        ?string $deployPostGateReportBytes,
        ?string $rollbackPostGateReportBytes,
        array $unitReconciliationBundles,
    ): void {
        if (
            $terminalState !== null ||
            $terminalEvidenceBytes !== null ||
            $deployPostGateReportBytes !== null ||
            $rollbackPostGateReportBytes !== null ||
            $unitReconciliationBundles !== []
        ) {
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
        ?string $deployPostGateReportBytes = null,
        ?string $rollbackPostGateReportBytes = null,
        array $unitReconciliationBundles = [],
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
            $deployPostGateReportBytes,
            $rollbackPostGateReportBytes,
            $unitReconciliationBundles,
            true,
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
            if (!self::reservedUnitsAreStopped($referencedState)) {
                return 'terminal_claim_held';
            }
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

    /** @param array<string,mixed> $current @param array<string,mixed> $candidate */
    public static function validateStateEvolution(array $current, array $candidate): void
    {
        self::validateState($current);
        self::validateState($candidate);
        foreach (['schema', 'run_id', 'intent_sha256'] as $field) {
            if ($current[$field] !== $candidate[$field]) {
                throw new RuntimeException('runner state identity is immutable');
            }
        }
        self::assertLifecycleEvolution($current, $candidate);
        if ($candidate['sequence'] < $current['sequence']) {
            throw new RuntimeException('runner state sequence cannot move backwards');
        }
        if (
            $candidate['sequence'] === $current['sequence'] &&
            $candidate['events_sha256'] !== $current['events_sha256']
        ) {
            throw new RuntimeException('unchanged sequence must preserve the events hash');
        }
        if (strcmp($candidate['updated_at_utc'], $current['updated_at_utc']) < 0) {
            throw new RuntimeException('runner state timestamp cannot move backwards');
        }
        foreach (['deploy', 'rollback'] as $action) {
            if ($candidate[$action]['invocation_count'] < $current[$action]['invocation_count']) {
                throw new RuntimeException($action . ' reservation count is immutable');
            }
            foreach (
                [
                    'request_sha256',
                    'execution_input_sha256',
                    'unit_name',
                    'unit_launch_sha256',
                    'unit_manager_boot_id',
                    'unit_invocation_id',
                    'unit_missing_observed_boot_id',
                ]
                as $field
            ) {
                if ($current[$action][$field] !== null && $candidate[$action][$field] !== $current[$action][$field]) {
                    throw new RuntimeException($action . ' durable identity is immutable');
                }
            }
            $currentUnitState = $current[$action]['unit_state'];
            $candidateUnitState = $candidate[$action]['unit_state'];
            $allowedUnitStates = match ($currentUnitState) {
                'not_created' => ['not_created', 'starting'],
                'starting' => ['starting', 'running', 'exited', 'failed', 'killed', 'missing', 'unknown'],
                'running' => ['running', 'exited', 'failed', 'killed', 'missing', 'unknown'],
                'unknown' => ['unknown', 'starting', 'running', 'exited', 'failed', 'killed', 'missing'],
                'exited', 'failed', 'killed', 'missing' => [$currentUnitState],
                default => [],
            };
            if (!in_array($candidateUnitState, $allowedUnitStates, true)) {
                throw new RuntimeException($action . ' unit state cannot move backwards');
            }
            if (
                $current[$action]['observed_exit_code'] !== null &&
                $candidate[$action]['observed_exit_code'] !== $current[$action]['observed_exit_code']
            ) {
                throw new RuntimeException($action . ' observed exit is immutable');
            }
        }
        if (
            $current['deploy']['receipt_sha256'] !== null &&
            $candidate['deploy']['receipt_sha256'] !== $current['deploy']['receipt_sha256']
        ) {
            throw new RuntimeException('accepted deploy receipt is immutable');
        }
        foreach (['deploy', 'rollback'] as $subject) {
            $countField = $subject . '_submission_count';
            $shaField = $subject . '_report_sha256';
            $verdictField = $subject . '_verdict';
            if ($candidate['post_gates'][$countField] < $current['post_gates'][$countField]) {
                throw new RuntimeException($subject . ' post-gate submission cannot be removed');
            }
            if (
                $current['post_gates'][$shaField] !== null &&
                ($candidate['post_gates'][$shaField] !== $current['post_gates'][$shaField] ||
                    $candidate['post_gates'][$verdictField] !== $current['post_gates'][$verdictField])
            ) {
                throw new RuntimeException($subject . ' post-gate result is immutable');
            }
        }
        $rollbackVerdictTransitions = [
            'not_invoked' => ['not_invoked', 'verification_pending', 'succeeded', 'failed', 'unknown'],
            'verification_pending' => ['verification_pending', 'succeeded', 'failed'],
            'unknown' => ['unknown', 'verification_pending', 'succeeded', 'failed'],
            'succeeded' => ['succeeded'],
            'failed' => ['failed'],
        ];
        if (
            !in_array(
                $candidate['rollback']['verdict'],
                $rollbackVerdictTransitions[$current['rollback']['verdict']] ?? [],
                true,
            )
        ) {
            throw new RuntimeException('rollback verdict evolution is invalid');
        }
        if ($current['terminal']['state'] !== null) {
            $currentImmutable = $current;
            $candidateImmutable = $candidate;
            $candidateImmutable['updated_at_utc'] = $currentImmutable['updated_at_utc'];
            foreach (['deploy', 'rollback'] as $action) {
                foreach (
                    ['unit_invocation_id', 'unit_missing_observed_boot_id', 'unit_state', 'observed_exit_code']
                    as $field
                ) {
                    $candidateImmutable[$action][$field] = $currentImmutable[$action][$field];
                }
            }
            if ($candidateImmutable !== $currentImmutable) {
                throw new RuntimeException('terminal result and durable authority are immutable');
            }
        }
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $candidate */
    private static function assertLifecycleEvolution(array $current, array $candidate): void
    {
        if ($current['state'] === $candidate['state']) {
            if (
                $candidate['sequence'] !== $current['sequence'] ||
                !hash_equals($candidate['events_sha256'], $current['events_sha256'])
            ) {
                throw new RuntimeException('same lifecycle state must preserve the authoritative journal prefix');
            }
            return;
        }
        if (self::isTerminalState($current['state'])) {
            throw new RuntimeException('terminal runner state is immutable');
        }
        if (
            $candidate['sequence'] !== $current['sequence'] + 1 ||
            hash_equals($candidate['events_sha256'], $current['events_sha256'])
        ) {
            throw new RuntimeException('lifecycle transition must bind one new authoritative journal record');
        }
        $currentProgress = array_search($current['state'], DeploymentContractV1::PROGRESS_STATES, true);
        $candidateProgress = array_search($candidate['state'], DeploymentContractV1::PROGRESS_STATES, true);
        if (is_int($currentProgress) && is_int($candidateProgress) && $candidateProgress === $currentProgress + 1) {
            return;
        }
        $allowed = match ($current['state']) {
            'planned',
            'built',
            'uploaded',
            'accepted',
            'lock_acquired',
            'expected_commit_verified',
            'traffic_gate_passed',
            'dump_verified',
            'capacity_passed',
            'artifact_verified'
                => ['failed_before_write'],
            'deploy_running' => [
                'post_gates_running',
                'failed_pre_switch',
                'failed_switch_recovery_required',
                'failed_post_switch_rollback_succeeded',
                'failed_post_switch_rollback_failed',
                'manual_recovery_required',
            ],
            'post_gates_running' => [
                'succeeded',
                DeploymentContractV1::ROLLBACK_RESERVATION_STATE,
                'manual_recovery_required',
            ],
            DeploymentContractV1::ROLLBACK_RESERVATION_STATE => [
                'failed_post_switch_rollback_succeeded',
                'failed_post_switch_rollback_failed',
                'manual_recovery_required',
            ],
            default => [],
        };
        if (!in_array($candidate['state'], $allowed, true)) {
            throw new RuntimeException('runner lifecycle state cannot move backwards or cross branches');
        }
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
        return self::stateCacheDispositionWithEvidence($state, $eventsBytes, null, null, null, [], false);
    }

    /** @param array<string,mixed> $state */
    public static function terminalStateCacheDisposition(
        array $state,
        string $eventsBytes,
        string $evidenceBytes,
        ?string $deployPostGateReportBytes = null,
        ?string $rollbackPostGateReportBytes = null,
        array $unitReconciliationBundles = [],
    ): string {
        return self::stateCacheDispositionWithEvidence(
            $state,
            $eventsBytes,
            $evidenceBytes,
            $deployPostGateReportBytes,
            $rollbackPostGateReportBytes,
            $unitReconciliationBundles,
            true,
        );
    }

    /** @param array<string,mixed> $state */
    private static function stateCacheDispositionWithEvidence(
        array $state,
        string $eventsBytes,
        ?string $evidenceBytes,
        ?string $deployPostGateReportBytes,
        ?string $rollbackPostGateReportBytes,
        array $unitReconciliationBundles,
        bool $allowUnstoppedTerminalAttachment,
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
            if (!$allowUnstoppedTerminalAttachment) {
                self::assertReservedUnitsStopped($state);
            }
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
            $reports = self::validateTerminalPinnedReports(
                $state,
                $deployPostGateReportBytes,
                $rollbackPostGateReportBytes,
            );
            self::validateTerminalUnitReconciliationBundles($state, $unitReconciliationBundles);
            self::assertTerminalActionEvidence($state, $evidence);
            if (
                $reports['deploy'] !== null &&
                DeploymentContractV1::canonicalJson($reports['deploy']['post_gates']) !==
                    DeploymentContractV1::canonicalJson($evidence['post_gates'])
            ) {
                throw new RuntimeException('terminal evidence does not bind the pinned deploy post-gate report');
            }
            if (
                !hash_equals(hash('sha256', $evidenceBytes), $state['evidence_sha256']) ||
                $bundle['state'] !== $state['state'] ||
                $bundle['run_id'] !== $state['run_id']
            ) {
                throw new RuntimeException('terminal state cache does not bind the durable evidence bytes');
            }
        } elseif (
            $evidenceBytes !== null ||
            $deployPostGateReportBytes !== null ||
            $rollbackPostGateReportBytes !== null ||
            $unitReconciliationBundles !== []
        ) {
            throw new RuntimeException('nonterminal state cache cannot consume terminal bundle bytes');
        }

        return $state['sequence'] === $run['records'] ? 'current' : 'stale_recoverable';
    }

    /**
     * @param array<string,mixed> $state
     * @return array{deploy:?array<string,mixed>,rollback:?array<string,mixed>}
     */
    private static function validateTerminalPinnedReports(
        array $state,
        ?string $deployPostGateReportBytes,
        ?string $rollbackPostGateReportBytes,
    ): array {
        $reports = ['deploy' => null, 'rollback' => null];
        foreach (
            [
                'deploy' => $deployPostGateReportBytes,
                'rollback' => $rollbackPostGateReportBytes,
            ]
            as $subject => $bytes
        ) {
            $count = $state['post_gates'][$subject . '_submission_count'];
            if ($count === 0) {
                if ($bytes !== null) {
                    throw new RuntimeException('terminal bundle contains an unrecorded post-gate report');
                }
                continue;
            }
            if ($bytes === null) {
                throw new RuntimeException('terminal bundle is missing a pinned post-gate report');
            }
            $report = self::decodePostGateReport($bytes);
            if (
                $report['subject'] !== $subject ||
                self::postGateSubmissionDisposition($bytes, $state, $bytes) !== 'attach'
            ) {
                throw new RuntimeException('terminal bundle post-gate report is not current');
            }
            $reports[$subject] = $report;
        }

        return $reports;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $bundles */
    private static function validateTerminalUnitReconciliationBundles(array $state, array $bundles): void
    {
        if (array_is_list($bundles)) {
            if ($bundles !== []) {
                throw new RuntimeException('unit reconciliation bundles must be keyed by action');
            }
        }
        foreach (['deploy', 'rollback'] as $action) {
            if ($state[$action]['invocation_count'] === 0) {
                if (array_key_exists($action, $bundles)) {
                    throw new RuntimeException('terminal bundle contains an unreserved unit reconciliation');
                }
                continue;
            }
            if (!array_key_exists($action, $bundles) || !is_array($bundles[$action])) {
                throw new RuntimeException('terminal bundle is missing a reserved unit reconciliation');
            }
            self::assertExactKeys($bundles[$action], ['launch', 'binding', 'observation'], $action . ' reconciliation');
            if (
                !is_string($bundles[$action]['launch']) ||
                !is_string($bundles[$action]['binding']) ||
                !is_string($bundles[$action]['observation'])
            ) {
                throw new RuntimeException('terminal unit reconciliation bundle requires exact persisted bytes');
            }
            $launch = self::decodeSystemdLaunch($bundles[$action]['launch']);
            if ($launch['action'] !== $action) {
                throw new RuntimeException('terminal unit reconciliation bundle is keyed by the wrong action');
            }
            $binding = self::decodeUnitBinding($bundles[$action]['binding']);
            $observationEnvelope = self::decodeBoundedFile(
                $bundles[$action]['observation'],
                $action . ' unit observation',
                65_536,
            );
            $observation = match ($observationEnvelope['schema'] ?? null) {
                self::UNIT_ABSENCE_SCHEMA => self::decodeUnitAbsence($bundles[$action]['observation']),
                self::UNIT_LOADED_OBSERVATION_SCHEMA => self::decodeUnitLoadedObservation(
                    $bundles[$action]['observation'],
                    $launch,
                ),
                default => throw new RuntimeException('terminal unit observation schema is unknown'),
            };
            self::validateUnitReconciliationBundle($launch, $binding, $state, $observation);
        }
        foreach (array_keys($bundles) as $action) {
            if (!in_array($action, ['deploy', 'rollback'], true)) {
                throw new RuntimeException('terminal bundle contains an unknown unit reconciliation');
            }
        }
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
        self::assertEnum($event['action'], ['deploy', 'post_gates', 'rollback', 'reconcile'], 'action');
        self::assertEnum($event['event'], self::OPERATOR_EVENTS, 'event');
        self::assertEnum($event['status'], self::OPERATOR_STATUSES, 'status');
        self::assertEnum($event['reason'], self::OPERATOR_REASONS, 'reason');
        self::assertOperatorEventCompatibility($event['action'], $event['event'], $event['status'], $event['reason']);
    }

    private static function assertOperatorEventCompatibility(
        string $action,
        string $event,
        string $status,
        string $reason,
    ): void {
        $valid = match ($event) {
            'request_accepted' => in_array($action, ['deploy', 'post_gates', 'rollback'], true) &&
                $status === 'ok' &&
                $reason === 'none',
            'attached' => in_array($action, ['deploy', 'post_gates', 'rollback', 'reconcile'], true) &&
                $status === 'ok' &&
                $reason === 'same_intent',
            'reservation_persisted', 'unit_started' => in_array($action, ['deploy', 'rollback'], true) &&
                $status === 'running' &&
                $reason === 'none',
            'unit_observed' => in_array(
                [$action, $status, $reason],
                [
                    ['deploy', 'running', 'unit_running'],
                    ['deploy', 'ok', 'unit_exited'],
                    ['deploy', 'failed', 'unit_failed'],
                    ['deploy', 'failed', 'unit_killed'],
                    ['deploy', 'unknown', 'unit_missing'],
                    ['rollback', 'running', 'unit_running'],
                    ['rollback', 'ok', 'unit_exited'],
                    ['rollback', 'failed', 'unit_failed'],
                    ['rollback', 'failed', 'unit_killed'],
                    ['rollback', 'unknown', 'unit_missing'],
                ],
                true,
            ),
            'receipt_accepted' => $action === 'deploy' && $status === 'ok' && $reason === 'receipt_valid',
            'receipt_rejected' => $action === 'deploy' &&
                $status === 'failed' &&
                in_array($reason, ['receipt_missing', 'receipt_invalid', 'receipt_mismatch', 'child_exit_74'], true),
            'post_gates_observed' => $action === 'post_gates' &&
                (($status === 'ok' && $reason === 'none') || ($status === 'failed' && $reason === 'post_gate_failed')),
            'rollback_reserved' => $action === 'rollback' && $status === 'running' && $reason === 'post_gate_failed',
            'reconciliation_required' => $action === 'reconcile' &&
                $status === 'unknown' &&
                in_array(
                    $reason,
                    [
                        'contract_invalid',
                        'unit_running',
                        'unit_missing',
                        'receipt_missing',
                        'receipt_invalid',
                        'receipt_mismatch',
                        'child_exit_74',
                        'interrupted',
                        'manual_recovery_required',
                    ],
                    true,
                ),
            'terminal_persisted' => $status === 'terminal' &&
                self::terminalReasonAllowedForOperatorAction($action, $reason),
            'active_run_cleared' => $action === 'reconcile' && $status === 'ok' && $reason === 'none',
            default => false,
        };
        if (!$valid) {
            throw new RuntimeException('operator action, event, status, and reason are incompatible');
        }
    }

    private static function terminalReasonAllowedForOperatorAction(string $action, string $reason): bool
    {
        $allowed = match ($action) {
            'deploy' => [
                'traffic_hard_stop',
                'traffic_evidence_invalid',
                'dump_verification_failed',
                'capacity_gate_failed',
                'artifact_verification_failed',
                'expected_commit_mismatch',
                'deploy_failed',
                'rollback_failed',
                'switch_recovery_required',
                'contract_invalid',
                'state_conflict',
                'interrupted',
            ],
            'post_gates' => ['ok', 'deploy_failed', 'rollback_failed', 'contract_invalid', 'interrupted'],
            'rollback' => ['rollback_failed', 'contract_invalid', 'interrupted'],
            'reconcile' => array_keys(DeploymentContractV1::EXIT_REASONS),
            default => [],
        };

        return in_array($reason, $allowed, true);
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

    /** @return array<string,mixed> */
    public static function createSystemdLaunch(
        array $executionInput,
        array $request,
        ?array $originalDeployRequest,
        string $deployScriptBytes,
        ?callable $nonceGenerator = null,
    ): array {
        self::validateBoundExecutionInput($request, $executionInput, $originalDeployRequest);
        $action = $executionInput['action'];
        $runId = $executionInput['run_id'];
        $intentSha256 = $executionInput['intent_sha256'];
        $requestSha256 = self::fileSha256(self::encodeFile($request));
        $executionInputSha256 = self::fileSha256(self::encodeExecutionInput($executionInput));
        $argvSha256 = self::argvSha256(self::executionArgv($executionInput, $request, $originalDeployRequest));
        if (
            $deployScriptBytes === '' ||
            strlen($deployScriptBytes) > 1_048_576 ||
            str_contains($deployScriptBytes, "\0")
        ) {
            throw new RuntimeException('deploy script bytes are invalid');
        }
        $deployScriptSha256 = hash('sha256', $deployScriptBytes);
        $nonceBytes = $nonceGenerator === null ? random_bytes(32) : $nonceGenerator();
        if (!is_string($nonceBytes) || strlen($nonceBytes) !== 32) {
            throw new RuntimeException('launch nonce generator must return 32 random bytes');
        }
        $launchNonce = bin2hex($nonceBytes);
        $properties = self::baseUnitProperties($action);

        return [
            'schema' => self::SYSTEMD_LAUNCH_SCHEMA,
            'action' => $action,
            'run_id' => $runId,
            'intent_sha256' => $intentSha256,
            'request_sha256' => $requestSha256,
            'execution_input_sha256' => $executionInputSha256,
            'deploy_script_sha256' => $deployScriptSha256,
            'argv_sha256' => $argvSha256,
            'environment_sha256' => hash('sha256', DeploymentContractV1::canonicalJson(self::FIXED_ENVIRONMENT) . "\n"),
            'properties_sha256' => hash('sha256', DeploymentContractV1::canonicalJson($properties) . "\n"),
            'unit_name' => self::unitName($action, $runId, $intentSha256),
            'properties' => $properties,
            'launch_nonce' => $launchNonce,
        ];
    }

    /** @param array<string,mixed> $launch */
    public static function validateSystemdLaunch(array $launch): void
    {
        self::assertExactKeys($launch, self::SYSTEMD_LAUNCH_KEYS, 'systemd launch');
        self::assertSame($launch['schema'], self::SYSTEMD_LAUNCH_SCHEMA, 'systemd launch schema');
        self::assertEnum($launch['action'], ['deploy', 'rollback'], 'unit action');
        self::assertUuidV4($launch['run_id'], 'run_id');
        foreach (
            [
                'intent_sha256',
                'request_sha256',
                'execution_input_sha256',
                'deploy_script_sha256',
                'argv_sha256',
                'environment_sha256',
                'properties_sha256',
                'launch_nonce',
            ]
            as $field
        ) {
            self::assertSha256($launch[$field], $field);
        }
        if (hash_equals(str_repeat('0', 64), $launch['launch_nonce'])) {
            throw new RuntimeException('systemd launch nonce must not be predictable');
        }
        self::assertSame(
            $launch['unit_name'],
            self::unitName($launch['action'], $launch['run_id'], $launch['intent_sha256']),
            'unit_name',
        );
        self::assertObject($launch['properties'], 'properties');
        $properties = self::baseUnitProperties($launch['action']);
        if (!self::stringMapsEqual($launch['properties'], $properties)) {
            throw new RuntimeException('systemd launch properties are invalid');
        }
        self::assertSame(
            $launch['environment_sha256'],
            hash('sha256', DeploymentContractV1::canonicalJson(self::FIXED_ENVIRONMENT) . "\n"),
            'environment_sha256',
        );
        self::assertSame(
            $launch['properties_sha256'],
            hash('sha256', DeploymentContractV1::canonicalJson($properties) . "\n"),
            'properties_sha256',
        );
    }

    /** @return array<string,mixed> */
    public static function decodeSystemdLaunch(string $encoded): array
    {
        $launch = self::decodeBoundedFile($encoded, 'systemd launch', self::EXECUTION_INPUT_MAX_BYTES);
        self::validateSystemdLaunch($launch);

        return $launch;
    }

    /** @return array<string,string> */
    public static function unitProperties(string $action, ?string $unitLaunchSha256 = null): array
    {
        self::assertEnum($action, ['deploy', 'rollback'], 'unit action');
        $properties = self::baseUnitProperties($action);
        if ($unitLaunchSha256 !== null) {
            self::assertSha256($unitLaunchSha256, 'unit_launch_sha256');
            $properties['Description'] =
                'fh-deployment-host-runner-v1-' .
                hash('sha256', "deployment_host_systemd_description.v1\0" . $unitLaunchSha256);
        }

        return $properties;
    }

    /** @return array<string,string> */
    public static function observedUnitProperties(string $action, string $unitLaunchSha256): array
    {
        $properties = self::unitProperties($action, $unitLaunchSha256);

        return [
            'Transient' => 'yes',
            'Type' => $properties['Type'],
            'RemainAfterExit' => $properties['RemainAfterExit'],
            'UMask' => $properties['UMask'],
            'KillMode' => $properties['KillMode'],
            'Restart' => $properties['Restart'],
            'RuntimeMaxUSec' => $action === 'deploy' ? '7200000000' : '1800000000',
            'TimeoutStopUSec' => '300000000',
            'StandardInput' => $properties['StandardInput'],
            'StandardOutput' => $properties['StandardOutput'],
            'StandardError' => $properties['StandardError'],
            'Description' => $properties['Description'],
        ];
    }

    /** @param array<string,mixed> $raw @return array<string,string> */
    public static function normalizeObservedUnitProperties(string $action, string $unitLaunchSha256, array $raw): array
    {
        $expected = self::observedUnitProperties($action, $unitLaunchSha256);
        self::assertExactKeys($raw, array_keys($expected), 'observed systemd properties');
        $acceptedRuntime = $action === 'deploy' ? ['2h', '7200000000'] : ['30min', '1800000000'];
        if (!in_array($raw['RuntimeMaxUSec'], $acceptedRuntime, true)) {
            throw new RuntimeException('RuntimeMaxUSec is invalid');
        }
        if (!in_array($raw['TimeoutStopUSec'], ['5min', '300000000'], true)) {
            throw new RuntimeException('TimeoutStopUSec is invalid');
        }
        $normalized = $raw;
        $normalized['RuntimeMaxUSec'] = $expected['RuntimeMaxUSec'];
        $normalized['TimeoutStopUSec'] = $expected['TimeoutStopUSec'];
        if (!self::stringMapsEqual($normalized, $expected)) {
            throw new RuntimeException('observed systemd properties are invalid');
        }

        return $expected;
    }

    /** @return array<string,string> */
    private static function baseUnitProperties(string $action): array
    {
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

    /** @param array<string,mixed> $binding */
    public static function validateUnitBinding(array $binding): void
    {
        self::assertExactKeys($binding, self::UNIT_BINDING_KEYS, 'unit binding');
        self::assertSame($binding['schema'], self::UNIT_BINDING_SCHEMA, 'unit binding schema');
        self::assertUuidV4($binding['run_id'], 'run_id');
        self::assertSha256($binding['intent_sha256'], 'intent_sha256');
        self::assertEnum($binding['action'], ['deploy', 'rollback'], 'unit action');
        self::assertSame(
            $binding['unit_name'],
            self::unitName($binding['action'], $binding['run_id'], $binding['intent_sha256']),
            'unit_name',
        );
        self::assertSha256($binding['unit_launch_sha256'], 'unit_launch_sha256');
        self::assertUuid($binding['unit_manager_boot_id'], 'unit_manager_boot_id');
        self::assertEnum($binding['binding_state'], ['reserved', 'observed'], 'binding_state');
        if ($binding['binding_state'] === 'reserved') {
            if ($binding['unit_invocation_id'] !== null) {
                throw new RuntimeException('reserved unit cannot invent an InvocationID');
            }
        } else {
            self::assertInvocationId($binding['unit_invocation_id'], 'unit_invocation_id');
        }
    }

    /** @return array<string,mixed> */
    public static function decodeUnitBinding(string $encoded): array
    {
        $binding = self::decodeBoundedFile($encoded, 'unit binding', 16_384);
        self::validateUnitBinding($binding);

        return $binding;
    }

    /** @param array<string,mixed> $absence */
    public static function validateUnitAbsence(array $absence): void
    {
        self::assertExactKeys($absence, self::UNIT_ABSENCE_KEYS, 'unit absence observation');
        self::assertSame($absence['schema'], self::UNIT_ABSENCE_SCHEMA, 'unit absence schema');
        self::assertEnum($absence['kind'], ['not_found', 'transport_error'], 'unit absence kind');
        if ($absence['kind'] === 'transport_error') {
            if ($absence['manager_boot_id'] !== null) {
                throw new RuntimeException('transport error cannot claim a manager boot ID');
            }
            return;
        }
        self::assertUuid($absence['manager_boot_id'], 'manager_boot_id');
    }

    /** @return array<string,mixed> */
    public static function decodeUnitAbsence(string $encoded): array
    {
        $absence = self::decodeBoundedFile($encoded, 'unit absence observation', 1024);
        self::validateUnitAbsence($absence);

        return $absence;
    }

    /** @param array<string,mixed> $observation @param array<string,mixed> $launch */
    public static function validateUnitLoadedObservation(array $observation, array $launch): void
    {
        self::assertExactKeys($observation, self::UNIT_LOADED_OBSERVATION_KEYS, 'loaded unit observation');
        self::assertSame(
            $observation['schema'],
            self::UNIT_LOADED_OBSERVATION_SCHEMA,
            'loaded unit observation schema',
        );
        self::assertUuid($observation['manager_boot_id'], 'loaded unit manager_boot_id');
        if (!is_string($observation['systemctl_show'])) {
            throw new RuntimeException('loaded unit observation requires exact systemctl bytes');
        }
        self::parseSystemctlShow($observation['systemctl_show'], $launch);
    }

    /** @param array<string,mixed> $launch @return array<string,mixed> */
    public static function decodeUnitLoadedObservation(string $encoded, array $launch): array
    {
        $observation = self::decodeBoundedFile($encoded, 'loaded unit observation', 65_536);
        self::validateUnitLoadedObservation($observation, $launch);

        return $observation;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $candidate */
    public static function validateUnitBindingEvolution(array $current, array $candidate): void
    {
        self::validateUnitBinding($current);
        self::validateUnitBinding($candidate);
        foreach (
            ['schema', 'run_id', 'intent_sha256', 'action', 'unit_name', 'unit_launch_sha256', 'unit_manager_boot_id']
            as $field
        ) {
            if ($current[$field] !== $candidate[$field]) {
                throw new RuntimeException('unit binding identity is immutable');
            }
        }
        if ($current['binding_state'] === 'observed') {
            if (
                $candidate['binding_state'] !== 'observed' ||
                !hash_equals($current['unit_invocation_id'], $candidate['unit_invocation_id'])
            ) {
                throw new RuntimeException('observed InvocationID is immutable');
            }
        }
    }

    /**
     * @param array<string,mixed> $launch
     * @param array<string,mixed> $binding
     * @param array<string,mixed> $state
     * @param array<string,mixed> $observation
     */
    public static function validateUnitReconciliationBundle(
        array $launch,
        array $binding,
        array $state,
        array $observation,
    ): void {
        self::validateSystemdLaunch($launch);
        self::validateUnitBinding($binding);
        self::validateState($state);
        $action = $launch['action'];
        $actionState = $state[$action];
        if ($actionState['invocation_count'] !== 1) {
            throw new RuntimeException('unit reconciliation requires a durable action reservation');
        }
        $launchSha = self::fileSha256(self::encodeFile($launch));
        if (
            $binding['action'] !== $action ||
            $binding['run_id'] !== $launch['run_id'] ||
            $state['run_id'] !== $launch['run_id'] ||
            !hash_equals($binding['intent_sha256'], $launch['intent_sha256']) ||
            !hash_equals($state['intent_sha256'], $launch['intent_sha256']) ||
            $binding['unit_name'] !== $launch['unit_name'] ||
            $actionState['unit_name'] !== $launch['unit_name'] ||
            !hash_equals($binding['unit_launch_sha256'], $launchSha) ||
            !hash_equals($actionState['unit_launch_sha256'], $launchSha) ||
            !hash_equals($actionState['request_sha256'], $launch['request_sha256']) ||
            !hash_equals($actionState['execution_input_sha256'], $launch['execution_input_sha256']) ||
            $actionState['unit_manager_boot_id'] !== $binding['unit_manager_boot_id'] ||
            $actionState['unit_invocation_id'] !== $binding['unit_invocation_id']
        ) {
            throw new RuntimeException('unit reconciliation bundle does not bind launch, state, and systemd identity');
        }
        if (($observation['schema'] ?? null) === self::UNIT_ABSENCE_SCHEMA) {
            self::validateUnitAbsence($observation);
            $absence = $observation;
            unset($absence['schema']);
            $unitState = self::classifyUnitObservation($binding, $absence);
            if (
                $actionState['unit_state'] !== $unitState ||
                $actionState['observed_exit_code'] !== null ||
                ($unitState === 'missing' &&
                    $actionState['unit_missing_observed_boot_id'] !== $observation['manager_boot_id'])
            ) {
                throw new RuntimeException('unit absence observation does not bind the durable state');
            }
            return;
        }
        self::validateUnitLoadedObservation($observation, $launch);
        if (!hash_equals($binding['unit_manager_boot_id'], $observation['manager_boot_id'])) {
            throw new RuntimeException('loaded unit observation changed manager boot identity');
        }
        $result = self::classifySystemdObservation(
            $launch,
            self::parseSystemctlShow($observation['systemctl_show'], $launch),
        );
        if (
            $actionState['unit_state'] !== $result['unit_state'] ||
            $actionState['observed_exit_code'] !== $result['observed_exit_code'] ||
            $actionState['unit_invocation_id'] !== $result['unit_invocation_id']
        ) {
            throw new RuntimeException('loaded unit observation does not bind the durable state');
        }
    }

    /** @param array<string,mixed> $launch */
    public static function unitPreflightDisposition(
        int $exitCode,
        string $stdout,
        string $stderr,
        array $launch,
        string $expectedManagerBootId,
        string $managerBootIdBytes,
    ): string {
        self::assertUuid($expectedManagerBootId, 'expected_manager_boot_id');
        self::validateSystemdLaunch($launch);
        $managerBootId = self::parseManagerBootId($managerBootIdBytes);
        if (!hash_equals($expectedManagerBootId, $managerBootId)) {
            return 'unknown';
        }
        if ($exitCode === 0 && $stderr === '') {
            try {
                $values = self::parseSystemctlShowFields($stdout);
            } catch (RuntimeException) {
                return 'unknown';
            }
            if ($values['Id'] !== $launch['unit_name']) {
                return 'unknown';
            }
            if ($values['LoadState'] === 'not-found') {
                return self::isCanonicalNotFoundSystemctlFields($values) ? 'available' : 'unknown';
            }

            return 'collision';
        }
        $notFound = 'Unit ' . $launch['unit_name'] . " could not be found.\n";
        if ($exitCode === 1 && $stdout === '' && $stderr === $notFound) {
            return 'available';
        }

        return 'unknown';
    }

    /**
     * @param array<string,mixed> $launch
     * @return array{kind:string,manager_boot_id:?string,loaded_observation:?array<string,mixed>}
     */
    public static function systemctlLookupObservation(
        int $exitCode,
        string $stdout,
        string $stderr,
        array $launch,
        string $managerBootIdBytes,
    ): array {
        self::validateSystemdLaunch($launch);
        $managerBootId = self::parseManagerBootId($managerBootIdBytes);
        if ($exitCode === 0 && $stderr === '') {
            $values = self::parseSystemctlShowFields($stdout);
            if ($values['Id'] === $launch['unit_name'] && self::isCanonicalNotFoundSystemctlFields($values)) {
                return ['kind' => 'not_found', 'manager_boot_id' => $managerBootId, 'loaded_observation' => null];
            }
            return [
                'kind' => 'loaded',
                'manager_boot_id' => $managerBootId,
                'loaded_observation' => self::parseSystemctlShow($stdout, $launch),
            ];
        }
        $notFound = 'Unit ' . $launch['unit_name'] . " could not be found.\n";
        if ($exitCode === 1 && $stdout === '' && $stderr === $notFound) {
            return ['kind' => 'not_found', 'manager_boot_id' => $managerBootId, 'loaded_observation' => null];
        }

        return ['kind' => 'transport_error', 'manager_boot_id' => null, 'loaded_observation' => null];
    }

    /** @param array<string,mixed> $launch @return array<string,mixed> */
    public static function unitAbsenceFromSystemctlResult(
        int $exitCode,
        string $stdout,
        string $stderr,
        array $launch,
        string $managerBootIdBytes,
    ): array {
        $observation = self::systemctlLookupObservation($exitCode, $stdout, $stderr, $launch, $managerBootIdBytes);
        if ($observation['kind'] === 'loaded') {
            throw new RuntimeException('a loaded unit is not an absence observation');
        }

        return [
            'schema' => self::UNIT_ABSENCE_SCHEMA,
            'kind' => $observation['kind'],
            'manager_boot_id' => $observation['manager_boot_id'],
        ];
    }

    public static function systemdAdmissionDisposition(bool $reservationDurable, ?int $exitCode): string
    {
        if (!$reservationDurable) {
            throw new RuntimeException('systemd admission requires the durable reservation boundary');
        }
        if ($exitCode !== null && ($exitCode < 0 || $exitCode > 255)) {
            throw new RuntimeException('systemd admission exit code is invalid');
        }

        return $exitCode === 0 ? 'observe_only' : 'observe_only_reconciliation_required';
    }

    public static function parseManagerBootId(string $encoded): string
    {
        if (strlen($encoded) !== 37 || !str_ends_with($encoded, "\n") || str_contains($encoded, "\0")) {
            throw new RuntimeException('manager boot ID bytes are invalid');
        }
        $bootId = substr($encoded, 0, -1);
        self::assertUuid($bootId, 'manager_boot_id');

        return $bootId;
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $observation */
    public static function classifyUnitObservation(array $binding, array $observation): string
    {
        self::validateUnitBinding($binding);
        self::assertExactKeys($observation, ['kind', 'manager_boot_id'], 'unit observation');
        self::assertEnum($observation['kind'], ['not_found', 'transport_error'], 'unit observation kind');
        if ($observation['kind'] === 'transport_error') {
            if ($observation['manager_boot_id'] !== null) {
                throw new RuntimeException('transport error cannot claim a manager boot ID');
            }
            return 'unknown';
        }
        self::assertUuid($observation['manager_boot_id'], 'manager_boot_id');

        return hash_equals($binding['unit_manager_boot_id'], $observation['manager_boot_id']) ? 'unknown' : 'missing';
    }

    /** @param array<string,mixed> $launch @return list<string> */
    public static function systemdRunArgv(
        array $launch,
        array $binding,
        string $managerBootIdBytes,
        array $executionInput,
        array $request,
        ?array $originalDeployRequest = null,
        string $deployScriptBytes = '',
    ): array {
        self::validateSystemdLaunch($launch);
        self::validateUnitBinding($binding);
        self::validateBoundExecutionInput($request, $executionInput, $originalDeployRequest);
        $childArgv = self::executionArgv($executionInput, $request, $originalDeployRequest);
        $launchSha = self::fileSha256(self::encodeFile($launch));
        $currentBootId = self::parseManagerBootId($managerBootIdBytes);
        if (
            $deployScriptBytes === '' ||
            strlen($deployScriptBytes) > 1_048_576 ||
            str_contains($deployScriptBytes, "\0") ||
            !hash_equals($launch['deploy_script_sha256'], hash('sha256', $deployScriptBytes)) ||
            $launch['action'] !== $executionInput['action'] ||
            $launch['run_id'] !== $executionInput['run_id'] ||
            !hash_equals($launch['intent_sha256'], $executionInput['intent_sha256']) ||
            !hash_equals($launch['request_sha256'], self::fileSha256(self::encodeFile($request))) ||
            !hash_equals(
                $launch['execution_input_sha256'],
                self::fileSha256(self::encodeExecutionInput($executionInput)),
            ) ||
            !hash_equals($launch['argv_sha256'], self::argvSha256($childArgv)) ||
            $binding['binding_state'] !== 'reserved' ||
            $binding['action'] !== $launch['action'] ||
            $binding['run_id'] !== $launch['run_id'] ||
            !hash_equals($binding['intent_sha256'], $launch['intent_sha256']) ||
            $binding['unit_name'] !== $launch['unit_name'] ||
            !hash_equals($binding['unit_launch_sha256'], $launchSha) ||
            !hash_equals($binding['unit_manager_boot_id'], $currentBootId)
        ) {
            throw new RuntimeException('systemd launch does not bind the validated execution bundle');
        }
        $argv = [
            '/usr/bin/env',
            '-i',
            'LANG=C',
            'LC_ALL=C',
            'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
            '/usr/bin/systemd-run',
            '--quiet',
            '--expand-environment=no',
            '--unit=' . $launch['unit_name'],
        ];
        foreach (self::unitProperties($launch['action'], $launchSha) as $name => $value) {
            $argv[] = '--property=' . $name . '=' . $value;
        }

        return [...$argv, '--', ...$childArgv];
    }

    /** @param list<string> $argv */
    public static function argvSha256(array $argv): string
    {
        if ($argv === []) {
            throw new RuntimeException('argv must not be empty');
        }
        foreach ($argv as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new RuntimeException('argv is invalid');
            }
        }

        return hash('sha256', DeploymentContractV1::canonicalJson($argv) . "\n");
    }

    /** @return list<string> */
    public static function systemctlShowArgv(string $unitName): array
    {
        if (
            preg_match(
                '/^fh-(?:deploy|rollback)-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}-[0-9a-f]{12}\.service$/D',
                $unitName,
            ) !== 1
        ) {
            throw new RuntimeException('systemd unit name is invalid');
        }

        return [
            '/usr/bin/env',
            '-i',
            'LANG=C',
            'LC_ALL=C',
            'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
            '/bin/systemctl',
            'show',
            '--no-pager',
            '--property=Id,LoadState,ActiveState,SubState,Result,ExecMainCode,ExecMainStatus,InvocationID,Description,Transient,Type,RemainAfterExit,UMask,KillMode,Restart,RuntimeMaxUSec,TimeoutStopUSec,StandardInput,StandardOutput,StandardError',
            $unitName,
        ];
    }

    /** @param array<string,mixed> $launch @return array<string,mixed> */
    public static function parseSystemctlShow(string $encoded, array $launch): array
    {
        self::validateSystemdLaunch($launch);
        $values = self::parseSystemctlShowFields($encoded);
        foreach (['ExecMainCode', 'ExecMainStatus'] as $field) {
            if (preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $values[$field]) !== 1) {
                throw new RuntimeException($field . ' is not a canonical byte integer');
            }
            $number = (int) $values[$field];
            if ($number > 255) {
                throw new RuntimeException($field . ' exceeds a byte integer');
            }
            $values[$field] = $number;
        }
        self::assertInvocationId($values['InvocationID'], 'InvocationID');
        $launchSha = self::fileSha256(self::encodeFile($launch));
        $properties = [];
        foreach (
            [
                'Transient',
                'Type',
                'RemainAfterExit',
                'UMask',
                'KillMode',
                'Restart',
                'RuntimeMaxUSec',
                'TimeoutStopUSec',
                'StandardInput',
                'StandardOutput',
                'StandardError',
                'Description',
            ]
            as $field
        ) {
            $properties[$field] = $values[$field];
        }
        $properties = self::normalizeObservedUnitProperties($launch['action'], $launchSha, $properties);

        return [
            'id' => $values['Id'],
            'load_state' => $values['LoadState'],
            'active_state' => $values['ActiveState'],
            'sub_state' => $values['SubState'],
            'result' => $values['Result'],
            'exec_main_code' => $values['ExecMainCode'],
            'exec_main_status' => $values['ExecMainStatus'],
            'unit_invocation_id' => $values['InvocationID'],
            'description' => $values['Description'],
            'properties' => $properties,
        ];
    }

    /** @return array<string,string> */
    private static function parseSystemctlShowFields(string $encoded): array
    {
        if (
            $encoded === '' ||
            strlen($encoded) > 32_768 ||
            str_contains($encoded, "\0") ||
            !str_ends_with($encoded, "\n") ||
            str_ends_with($encoded, "\n\n")
        ) {
            throw new RuntimeException('systemctl show output encoding is invalid');
        }
        $values = [];
        foreach (explode("\n", substr($encoded, 0, -1)) as $line) {
            if ($line === '' || substr_count($line, '=') !== 1) {
                throw new RuntimeException('systemctl show output line is invalid');
            }
            [$key, $value] = explode('=', $line, 2);
            if (!in_array($key, self::SYSTEMCTL_SHOW_KEYS, true) || array_key_exists($key, $values)) {
                throw new RuntimeException('systemctl show output contains an unknown or duplicate field');
            }
            $values[$key] = $value;
        }
        self::assertExactKeys($values, self::SYSTEMCTL_SHOW_KEYS, 'systemctl show output');

        return $values;
    }

    /** @param array<string,string> $values */
    private static function isCanonicalNotFoundSystemctlFields(array $values): bool
    {
        return $values['LoadState'] === 'not-found' &&
            $values['ActiveState'] === 'inactive' &&
            $values['SubState'] === 'dead' &&
            $values['ExecMainCode'] === '0' &&
            $values['ExecMainStatus'] === '0' &&
            $values['InvocationID'] === '' &&
            $values['Transient'] === 'no';
    }

    /**
     * @param array<string,mixed> $launch
     * @param array<string,mixed> $observation
     * @return array{unit_state:string,observed_exit_code:?int,unit_invocation_id:?string}
     */
    public static function classifySystemdObservation(array $launch, array $observation): array
    {
        self::validateSystemdLaunch($launch);
        self::assertExactKeys(
            $observation,
            [
                'id',
                'load_state',
                'active_state',
                'sub_state',
                'result',
                'exec_main_code',
                'exec_main_status',
                'unit_invocation_id',
                'description',
                'properties',
            ],
            'systemd observation',
        );
        $launchSha = self::fileSha256(self::encodeFile($launch));
        $expectedProperties = self::observedUnitProperties($launch['action'], $launchSha);
        if (
            $observation['id'] !== $launch['unit_name'] ||
            $observation['load_state'] !== 'loaded' ||
            $observation['description'] !== $expectedProperties['Description'] ||
            !is_array($observation['properties']) ||
            !self::stringMapsEqual($observation['properties'], $expectedProperties)
        ) {
            return ['unit_state' => 'unknown', 'observed_exit_code' => null, 'unit_invocation_id' => null];
        }
        if (
            !is_string($observation['unit_invocation_id']) ||
            preg_match('/^[0-9a-f]{32}$/D', $observation['unit_invocation_id']) !== 1
        ) {
            return ['unit_state' => 'unknown', 'observed_exit_code' => null, 'unit_invocation_id' => null];
        }
        foreach (['active_state', 'sub_state', 'result'] as $field) {
            if (!is_string($observation[$field])) {
                throw new RuntimeException('systemd observation field is invalid');
            }
        }
        foreach (['exec_main_code', 'exec_main_status'] as $field) {
            if (!is_int($observation[$field]) || $observation[$field] < 0 || $observation[$field] > 255) {
                throw new RuntimeException('systemd observation exit field is invalid');
            }
        }
        $invocationId = $observation['unit_invocation_id'];
        if (
            $observation['active_state'] === 'activating' &&
            in_array($observation['sub_state'], ['start', 'start-pre', 'start-post'], true) &&
            $observation['result'] === 'success' &&
            $observation['exec_main_code'] === 0 &&
            $observation['exec_main_status'] === 0
        ) {
            return ['unit_state' => 'starting', 'observed_exit_code' => null, 'unit_invocation_id' => $invocationId];
        }
        if (
            $observation['active_state'] === 'active' &&
            $observation['sub_state'] === 'running' &&
            $observation['result'] === 'success' &&
            $observation['exec_main_code'] === 0 &&
            $observation['exec_main_status'] === 0
        ) {
            return ['unit_state' => 'running', 'observed_exit_code' => null, 'unit_invocation_id' => $invocationId];
        }
        $cleanlyExited = [$observation['active_state'], $observation['sub_state']] === ['active', 'exited'];
        $failed = [$observation['active_state'], $observation['sub_state']] === ['failed', 'failed'];
        if (
            $cleanlyExited &&
            $observation['exec_main_code'] === 1 &&
            $observation['exec_main_status'] === 0 &&
            $observation['result'] === 'success'
        ) {
            return ['unit_state' => 'exited', 'observed_exit_code' => 0, 'unit_invocation_id' => $invocationId];
        }
        if (
            $failed &&
            $observation['exec_main_code'] === 1 &&
            $observation['exec_main_status'] > 0 &&
            $observation['result'] === 'exit-code'
        ) {
            return [
                'unit_state' => 'failed',
                'observed_exit_code' => $observation['exec_main_status'],
                'unit_invocation_id' => $invocationId,
            ];
        }
        if (
            $failed &&
            in_array($observation['exec_main_code'], [2, 3], true) &&
            $observation['exec_main_status'] > 0 &&
            in_array($observation['result'], ['signal', 'core-dump', 'timeout', 'watchdog'], true)
        ) {
            return ['unit_state' => 'killed', 'observed_exit_code' => null, 'unit_invocation_id' => $invocationId];
        }

        return ['unit_state' => 'unknown', 'observed_exit_code' => null, 'unit_invocation_id' => $invocationId];
    }

    /** @return list<string> */
    public static function terminalPersistenceOrder(): array
    {
        return [
            'pinned_action_observations',
            'evidence_candidate',
            'terminal_event',
            'terminal_evidence',
            'terminal_state',
            'terminal_active_claim',
            'active_claim_clearance',
        ];
    }

    /** @param list<string> $durableSteps */
    public static function terminalPersistenceResumeStep(array $durableSteps): string
    {
        $order = self::terminalPersistenceOrder();
        if ($durableSteps !== array_slice($order, 0, count($durableSteps))) {
            throw new RuntimeException('terminal persistence crash prefix is not authoritative');
        }

        return $order[count($durableSteps)] ?? 'complete';
    }

    /**
     * @param list<array{state:?array<string,mixed>,events_bytes:string}> $candidates
     */
    public static function activeRunReconstructionDisposition(array $candidates): string
    {
        if (!array_is_list($candidates)) {
            throw new RuntimeException('active-run reconstruction candidates must be a list');
        }
        if ($candidates === []) {
            return 'no_reserved_run';
        }
        if (count($candidates) !== 1) {
            throw new RuntimeException('active-run reconstruction requires exactly one reserved candidate');
        }
        $candidate = $candidates[0];
        self::assertExactKeys($candidate, ['state', 'events_bytes'], 'active-run reconstruction candidate');
        if (
            ($candidate['state'] !== null && !is_array($candidate['state'])) ||
            !is_string($candidate['events_bytes'])
        ) {
            throw new RuntimeException('active-run reconstruction candidate is invalid');
        }
        $cacheDisposition =
            $candidate['state'] === null
                ? null
                : self::stateCacheDisposition($candidate['state'], $candidate['events_bytes']);
        if (
            $candidate['events_bytes'] === '' ||
            strlen($candidate['events_bytes']) > 1_048_576 ||
            str_contains($candidate['events_bytes'], "\0") ||
            !str_ends_with($candidate['events_bytes'], "\n") ||
            str_ends_with($candidate['events_bytes'], "\n\n")
        ) {
            throw new RuntimeException('active-run reconstruction journal encoding is invalid');
        }
        $lines = explode("\n", substr($candidate['events_bytes'], 0, -1));
        $run = DeploymentContractV1::validateRunLines($lines);
        if (
            $candidate['state'] !== null &&
            $cacheDisposition === 'stale_recoverable' &&
            ($run['records'] !== $candidate['state']['sequence'] + 1 ||
                !in_array($run['state'], ['deploy_running', 'rollback_running'], true))
        ) {
            throw new RuntimeException('active-run reconstruction cache may lag only the reservation record');
        }
        if (!in_array($run['state'], self::OBSERVE_ONLY_STATES, true)) {
            throw new RuntimeException('active-run reconstruction candidate has no durable reservation');
        }

        return 'reconstruct_claim_observe_only';
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
            $deploy['unit_launch_sha256'],
            $deploy['unit_manager_boot_id'],
            $deploy['unit_invocation_id'],
            $deploy['unit_missing_observed_boot_id'],
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
            $rollback['unit_launch_sha256'],
            $rollback['unit_manager_boot_id'],
            $rollback['unit_invocation_id'],
            $rollback['unit_missing_observed_boot_id'],
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
        if (!self::reservedUnitsAreStopped($state)) {
            throw new RuntimeException('terminal active run claim requires every reserved unit to be stopped');
        }
    }

    /** @param array<string,mixed> $state */
    private static function reservedUnitsAreStopped(array $state): bool
    {
        foreach (['deploy', 'rollback'] as $action) {
            if (
                $state[$action]['invocation_count'] === 1 &&
                !in_array($state[$action]['unit_state'], ['exited', 'failed', 'killed', 'missing'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    private static function assertActionUnit(
        string $action,
        string $runId,
        string $intentSha256,
        int $invocationCount,
        mixed $unitName,
        mixed $unitLaunchSha256,
        mixed $unitManagerBootId,
        mixed $unitInvocationId,
        mixed $unitMissingObservedBootId,
        mixed $unitState,
        mixed $observedExitCode,
    ): void {
        self::assertNullableString($unitName, $action . '.unit_name');
        self::assertNullableSha256($unitLaunchSha256, $action . '.unit_launch_sha256');
        if ($unitManagerBootId !== null) {
            self::assertUuid($unitManagerBootId, $action . '.unit_manager_boot_id');
        }
        if ($unitInvocationId !== null) {
            self::assertInvocationId($unitInvocationId, $action . '.unit_invocation_id');
        }
        if ($unitMissingObservedBootId !== null) {
            self::assertUuid($unitMissingObservedBootId, $action . '.unit_missing_observed_boot_id');
        }
        self::assertEnum($unitState, self::UNIT_STATES, $action . '.unit_state');
        self::assertNullableExitCode($observedExitCode, $action . '.observed_exit_code');
        if ($invocationCount === 0) {
            if (
                $unitName !== null ||
                $unitLaunchSha256 !== null ||
                $unitManagerBootId !== null ||
                $unitInvocationId !== null ||
                $unitMissingObservedBootId !== null ||
                $unitState !== 'not_created' ||
                $observedExitCode !== null
            ) {
                throw new RuntimeException($action . ' unit cannot precede reservation');
            }
            return;
        }
        if (
            $unitName !== self::unitName($action, $runId, $intentSha256) ||
            $unitLaunchSha256 === null ||
            $unitManagerBootId === null ||
            $unitState === 'not_created'
        ) {
            throw new RuntimeException($action . ' unit does not bind the reserved action and launch');
        }
        if (in_array($unitState, ['running', 'exited', 'failed', 'killed'], true) && $unitInvocationId === null) {
            throw new RuntimeException($action . ' observed unit must bind InvocationID');
        }
        if ($unitState === 'missing') {
            if ($unitMissingObservedBootId === null || hash_equals($unitManagerBootId, $unitMissingObservedBootId)) {
                throw new RuntimeException($action . ' missing unit requires a different observed manager boot');
            }
        } elseif ($unitMissingObservedBootId !== null) {
            throw new RuntimeException($action . ' missing proof requires unit_state missing');
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

    private static function assertUuid(mixed $value, string $field): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1
        ) {
            throw new RuntimeException($field . ' must be a lowercase UUID');
        }
    }

    private static function assertInvocationId(mixed $value, string $field): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9a-f]{32}$/D', $value) !== 1 ||
            hash_equals(str_repeat('0', 32), $value)
        ) {
            throw new RuntimeException($field . ' must be a lowercase systemd InvocationID');
        }
    }

    /** @param array<mixed> $left @param array<mixed> $right */
    private static function stringMapsEqual(array $left, array $right): bool
    {
        if (array_is_list($left) || array_is_list($right)) {
            return false;
        }
        ksort($left);
        ksort($right);

        return $left === $right;
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

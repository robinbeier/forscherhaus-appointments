<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentContractV1;
use Ops\DeploymentHostRunnerContractV1;
use Ops\DeployResultV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentContractV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeployResultV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerContractV1.php';

final class DeploymentHostRunnerContractV1Test extends TestCase
{
    private const RUN_ID = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const INTENT_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testDeployRequestIsClosedCanonicalAndReusesIntentHash(): void
    {
        $request = $this->deployRequest();
        DeploymentHostRunnerContractV1::validateDeployRequest($request);

        $encoded = DeploymentHostRunnerContractV1::encodeFile($request);

        self::assertEquals($request, DeploymentHostRunnerContractV1::decodeDeployRequest($encoded));
        self::assertStringEndsWith("\n", $encoded);
        self::assertSame(hash('sha256', $encoded), DeploymentHostRunnerContractV1::fileSha256($encoded));
    }

    public function testRecoveryRequestContainsOnlyExistingRunIdentity(): void
    {
        $request = [
            'schema' => DeploymentHostRunnerContractV1::RECOVERY_REQUEST_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
        ];

        DeploymentHostRunnerContractV1::validateRecoveryRequest($request);

        self::assertEquals(
            $request,
            DeploymentHostRunnerContractV1::decodeRecoveryRequest(DeploymentHostRunnerContractV1::encodeFile($request)),
        );
    }

    public function testProtectedLockAndExecutionPlanAreClosed(): void
    {
        self::assertSame(
            '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock',
            DeploymentHostRunnerContractV1::GLOBAL_LOCK_PATH,
        );
        $input = $this->deployExecutionInput();
        $encoded = DeploymentHostRunnerContractV1::encodeExecutionInput($input);
        self::assertEquals($input, DeploymentHostRunnerContractV1::decodeExecutionInput($encoded));
        self::assertSame(
            'pin',
            DeploymentHostRunnerContractV1::executionInputPinDisposition($encoded, null, $this->deployRequest()),
        );
        self::assertSame(
            'resume',
            DeploymentHostRunnerContractV1::executionInputPinDisposition($encoded, $encoded, $this->deployRequest()),
        );
        DeploymentHostRunnerContractV1::validateDeployExecutionBundle($this->deployRequest(), $input);
        self::assertSame(
            [
                '/usr/bin/env',
                '-i',
                'LANG=C',
                'LC_ALL=C',
                'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
                '/bin/bash',
                '/root/deploy_ea.sh',
                '--rel',
                'ea_20260811',
                '--renderer-deploy-mode',
                'host',
                '--healthz-token-file',
                '/etc/fh/healthz.token',
                '--zero-surprise-dump-file',
                '/root/backups/predeploy.sql.gz',
                '--zero-surprise-predeploy-credentials-file',
                '/etc/fh/predeploy.ini',
                '--zero-surprise-canary-credentials-file',
                '/etc/fh/canary.ini',
                '--zero-surprise-incident-webhook-file',
                '/etc/fh/incident.ini',
                '--result-file',
                '/var/lib/fh-deploy-orchestrator/runs/' . self::RUN_ID . '/deploy-result.json',
            ],
            DeploymentHostRunnerContractV1::executionArgv($input, $this->deployRequest()),
        );
    }

    #[DataProvider('invalidExecutionInputProvider')]
    public function testExecutionInputRejectsCallerCommandAuthority(array|string $candidate): void
    {
        $this->expectException(RuntimeException::class);
        if (is_string($candidate)) {
            DeploymentHostRunnerContractV1::decodeExecutionInput($candidate);
            return;
        }
        DeploymentHostRunnerContractV1::validateExecutionInput($candidate);
    }

    public static function invalidExecutionInputProvider(): iterable
    {
        $valid = self::staticDeployExecutionInput();
        yield 'executable' => [$valid + ['executable' => '/bin/sh']];
        yield 'argv' => [$valid + ['arguments' => ['-c', 'touch /tmp/marker']]];
        yield 'environment' => [$valid + ['environment' => ['TOKEN' => 'secret']]];
        yield 'inline secret' => [[...$valid, 'parameters' => [...$valid['parameters'], 'token' => 'secret']]];
        yield 'relative path' => [
            [
                ...$valid,
                'parameters' => [
                    ...$valid['parameters'],
                    'healthz_token' => ['path' => '../token', 'sha256' => self::SHA],
                ],
            ],
        ];
        yield 'oversized' => [str_repeat('x', 16_385)];
    }

    public function testRecoveryPlanDerivesPathsFromOriginalImmutableRelease(): void
    {
        $input = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'action' => 'rollback',
            'parameters' => ['release_id' => 'ea_20260811'],
        ];
        DeploymentHostRunnerContractV1::validateRecoveryExecutionBundle(
            $this->recoveryRequest(),
            $this->deployRequest(),
            $input,
        );
        self::assertSame(
            [
                '/usr/bin/env',
                '-i',
                'LANG=C',
                'LC_ALL=C',
                'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
                '/bin/bash',
                '/root/deploy_ea.sh',
                '--runtime-config-rollback',
                '--active',
                '/var/www/html/easyappointments',
                '--previous',
                '/var/www/html/easyappointments_prev_ea_20260811',
                '--failed',
                '/var/www/html/.fh-failed-' . self::RUN_ID,
                '--runtime-user',
                'www-data',
            ],
            DeploymentHostRunnerContractV1::executionArgv($input, $this->recoveryRequest(), $this->deployRequest()),
        );
    }

    public function testChangedExecutionInputCannotReplaceAPinnedFirstInput(): void
    {
        $input = $this->deployExecutionInput();
        $encoded = DeploymentHostRunnerContractV1::encodeExecutionInput($input);
        $input['parameters']['renderer_deploy_mode'] = 'external';

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::executionInputPinDisposition(
            DeploymentHostRunnerContractV1::encodeExecutionInput($input),
            $encoded,
            $this->deployRequest(),
        );
    }

    public function testPostGateReportsAreCanonicalAndBindCurrentActionState(): void
    {
        $state = $this->state();
        $state['state'] = 'post_gates_running';
        $state['active_action'] = 'none';
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['receipt_sha256'] = self::SHA;
        $passed = $this->postGateReport(true, 'deploy');
        $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($passed);
        self::assertEquals($passed, DeploymentHostRunnerContractV1::decodePostGateReport($encoded));
        DeploymentHostRunnerContractV1::validatePostGateBundle($passed, $state);
        self::assertSame('succeeded', DeploymentHostRunnerContractV1::postGateDisposition($encoded, $state));

        $failed = $this->postGateReport(false, 'deploy');
        self::assertSame(
            'recovery_required',
            DeploymentHostRunnerContractV1::postGateDisposition(
                DeploymentHostRunnerContractV1::encodePostGateReport($failed),
                $state,
            ),
        );
        $failed['deploy_receipt_sha256'] = str_repeat('c', 64);
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validatePostGateBundle($failed, $state);
    }

    public function testPostGateSubmissionIsWriteOnceAndExactByteRetriesAttach(): void
    {
        $state = $this->state();
        $state['state'] = 'post_gates_running';
        $state['active_action'] = 'none';
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['receipt_sha256'] = self::SHA;
        $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($this->postGateReport(false, 'deploy'));

        self::assertSame(
            'first_submission',
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition($encoded, $state),
        );
        self::assertSame(
            'resume_first_submission',
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition($encoded, $state, $encoded),
        );

        $differentPinned = $this->postGateReport(false, 'deploy');
        $differentPinned['captured_at_utc'] = '2026-08-11T13:06:00Z';
        try {
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition(
                $encoded,
                $state,
                DeploymentHostRunnerContractV1::encodePostGateReport($differentPinned),
            );
            self::fail('Changed pinned report bytes resumed a first submission.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $encoded);
        $state['post_gates']['deploy_submission_count'] = 1;
        $state['post_gates']['deploy_verdict'] = 'failed';
        self::assertSame(
            'attach',
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition($encoded, $state, $encoded),
        );

        try {
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition($encoded, $state);
            self::fail('A missing pinned report attached from state metadata alone.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $receiptMismatch = $this->postGateReport(false, 'deploy');
        $receiptMismatch['deploy_receipt_sha256'] = str_repeat('c', 64);
        $mismatchBytes = DeploymentHostRunnerContractV1::encodePostGateReport($receiptMismatch);
        $mismatchState = $state;
        $mismatchState['post_gates']['deploy_report_sha256'] = hash('sha256', $mismatchBytes);
        try {
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition(
                $mismatchBytes,
                $mismatchState,
                $mismatchBytes,
            );
            self::fail('A receipt-mismatched stored report attached.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $changed = $this->postGateReport(false, 'deploy');
        $changed['captured_at_utc'] = '2026-08-11T13:06:00Z';
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::postGateSubmissionDisposition(
            DeploymentHostRunnerContractV1::encodePostGateReport($changed),
            $state,
            $encoded,
        );
    }

    public function testRecoveryBundleRequiresBothRecoveryAndOriginalDeployIdentity(): void
    {
        $input = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'action' => 'rollback',
            'parameters' => ['release_id' => 'ea_20260811'],
        ];
        $recovery = $this->recoveryRequest();
        $recovery['intent_sha256'] = self::SHA;

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateRecoveryExecutionBundle($recovery, $this->deployRequest(), $input);
    }

    #[DataProvider('unboundExecutionBundleProvider')]
    public function testPrivilegedExecutionApisRejectEveryUnboundIdentity(
        string $action,
        array $request,
        ?array $originalDeployRequest,
        array $input,
    ): void {
        $encoded = DeploymentHostRunnerContractV1::encodeExecutionInput($input);

        foreach (['bundle', 'pin', 'argv'] as $operation) {
            $rejected = false;
            try {
                if ($operation === 'bundle') {
                    if ($action === 'deploy') {
                        DeploymentHostRunnerContractV1::validateDeployExecutionBundle($request, $input);
                    } else {
                        DeploymentHostRunnerContractV1::validateRecoveryExecutionBundle(
                            $request,
                            $originalDeployRequest ?? [],
                            $input,
                        );
                    }
                } elseif ($operation === 'pin') {
                    DeploymentHostRunnerContractV1::executionInputPinDisposition(
                        $encoded,
                        null,
                        $request,
                        $originalDeployRequest,
                    );
                } else {
                    DeploymentHostRunnerContractV1::executionArgv($input, $request, $originalDeployRequest);
                }
            } catch (RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected, $action . ' ' . $operation . ' accepted an unbound execution input.');
        }
    }

    public static function unboundExecutionBundleProvider(): iterable
    {
        $deployRequest = self::staticDeployRequest();
        $deployInput = self::staticDeployExecutionInput();
        $deployInput['intent_sha256'] = $deployRequest['intent_sha256'];
        $recoveryRequest = [
            'schema' => DeploymentHostRunnerContractV1::RECOVERY_REQUEST_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $deployRequest['intent_sha256'],
        ];
        $rollbackInput = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $deployRequest['intent_sha256'],
            'action' => 'rollback',
            'parameters' => ['release_id' => 'ea_20260811'],
        ];

        yield 'deploy action' => ['deploy', $deployRequest, null, $rollbackInput];
        yield 'deploy release' => [
            'deploy',
            $deployRequest,
            null,
            [...$deployInput, 'parameters' => [...$deployInput['parameters'], 'release_id' => 'ea_unbound']],
        ];
        yield 'deploy run' => [
            'deploy',
            $deployRequest,
            null,
            [...$deployInput, 'run_id' => '228f6f52-4c87-4d4e-8b19-6a66e6e1af25'],
        ];
        yield 'deploy intent' => ['deploy', $deployRequest, null, [...$deployInput, 'intent_sha256' => self::SHA]];
        yield 'recovery action' => ['recovery', $recoveryRequest, $deployRequest, $deployInput];
        yield 'recovery release' => [
            'recovery',
            $recoveryRequest,
            $deployRequest,
            [...$rollbackInput, 'parameters' => ['release_id' => 'ea_unbound']],
        ];
        yield 'recovery request run' => [
            'recovery',
            [...$recoveryRequest, 'run_id' => '228f6f52-4c87-4d4e-8b19-6a66e6e1af25'],
            $deployRequest,
            $rollbackInput,
        ];
        yield 'recovery request intent' => [
            'recovery',
            [...$recoveryRequest, 'intent_sha256' => self::SHA],
            $deployRequest,
            $rollbackInput,
        ];
        yield 'recovery input run' => [
            'recovery',
            $recoveryRequest,
            $deployRequest,
            [...$rollbackInput, 'run_id' => '228f6f52-4c87-4d4e-8b19-6a66e6e1af25'],
        ];
        yield 'recovery input intent' => [
            'recovery',
            $recoveryRequest,
            $deployRequest,
            [...$rollbackInput, 'intent_sha256' => self::SHA],
        ];
    }

    public function testPostGateDispositionRejectsAnUnboundCompletedAction(): void
    {
        $state = $this->state();
        $state['state'] = 'post_gates_running';
        $state['active_action'] = 'none';
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['receipt_sha256'] = self::SHA;
        $report = $this->postGateReport(true, 'deploy');
        $report['deploy_receipt_sha256'] = str_repeat('c', 64);

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::postGateDisposition(
            DeploymentHostRunnerContractV1::encodePostGateReport($report),
            $state,
        );
    }

    public function testRollbackExitZeroWaitsForReportAndNonzeroMapsUniquely(): void
    {
        self::assertSame(
            ['disposition' => 'post_recovery_verification_required', 'observed_exit_code' => 0],
            DeploymentHostRunnerContractV1::rollbackNormalExitResult(0),
        );
        self::assertSame(
            ['state' => 'failed_post_switch_rollback_failed', 'exit_code' => 31, 'reason' => 'rollback_failed'],
            DeploymentHostRunnerContractV1::rollbackNormalExitResult(9),
        );
        self::assertSame(
            ['state' => 'failed_post_switch_rollback_failed', 'exit_code' => 31, 'reason' => 'rollback_failed'],
            DeploymentHostRunnerContractV1::rollbackNormalExitResult(143),
        );
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, 'rollback_running');
        $state = $this->recoveryAdmissionState($lines, 'rollback_running');
        $state['rollback']['unit_state'] = 'exited';
        $state['rollback']['observed_exit_code'] = 0;
        $state['rollback']['verdict'] = 'verification_pending';
        $passedReport = $this->postGateReport(true, 'rollback');
        $passedReport['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $failedReport = $this->postGateReport(false, 'rollback');
        $failedReport['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        self::assertSame(
            'failed_post_switch_rollback_succeeded',
            DeploymentHostRunnerContractV1::postGateDisposition(
                DeploymentHostRunnerContractV1::encodePostGateReport($passedReport),
                $state,
            ),
        );

        $literalDollarInput = $this->deployExecutionInput();
        $literalDollarInput['parameters']['healthz_token']['path'] = '/etc/fh/${FH_HEALTHZ_TOKEN}.token';
        $literalDollarLaunch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $literalDollarInput,
            $this->deployRequest(),
            null,
            "#!/bin/bash\n",
            static fn(): string => str_repeat("\xee", 32),
        );
        $literalDollarArgv = DeploymentHostRunnerContractV1::systemdRunArgv(
            $literalDollarLaunch,
            $this->unitBindingForLaunch($literalDollarLaunch, 'reserved'),
            "cccccccc-cccc-4ccc-8ccc-cccccccccccc\n",
            $literalDollarInput,
            $this->deployRequest(),
            null,
            "#!/bin/bash\n",
        );
        self::assertContains('/etc/fh/${FH_HEALTHZ_TOKEN}.token', $literalDollarArgv);
        self::assertLessThan(
            array_search('--', $literalDollarArgv, true),
            array_search('--expand-environment=no', $literalDollarArgv, true),
        );
        self::assertSame(
            'failed_post_switch_rollback_failed',
            DeploymentHostRunnerContractV1::postGateDisposition(
                DeploymentHostRunnerContractV1::encodePostGateReport($failedReport),
                $state,
            ),
        );
    }

    public function testPostGateDispositionRequiresTheExactPinnedReportAfterSubmission(): void
    {
        $deployState = $this->state();
        $deployState['state'] = 'post_gates_running';
        $deployState['active_action'] = 'none';
        $deployState['deploy']['observed_exit_code'] = 0;
        $deployState['deploy']['receipt_sha256'] = self::SHA;
        foreach ([[false, true], [true, false]] as [$storedPassed, $changedPassed]) {
            $stored = DeploymentHostRunnerContractV1::encodePostGateReport(
                $this->postGateReport($storedPassed, 'deploy'),
            );
            $changed = DeploymentHostRunnerContractV1::encodePostGateReport(
                $this->postGateReport($changedPassed, 'deploy'),
            );
            $state = $deployState;
            $state['post_gates']['deploy_report_sha256'] = hash('sha256', $stored);
            $state['post_gates']['deploy_submission_count'] = 1;
            $state['post_gates']['deploy_verdict'] = $storedPassed ? 'passed' : 'failed';

            try {
                DeploymentHostRunnerContractV1::postGateDisposition($changed, $state, $stored);
                self::fail('A changed deploy post-gate verdict replaced the pinned first submission.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, 'rollback_running');
        $persistedRollbackReports = [
            [true, 'succeeded', 'failed_post_switch_rollback_succeeded'],
            [false, 'failed', 'failed_post_switch_rollback_failed'],
        ];
        foreach ($persistedRollbackReports as [$passed, $verdict, $expected]) {
            $state = $this->recoveryAdmissionState($lines, 'rollback_running');
            $state['rollback']['unit_state'] = 'exited';
            $state['rollback']['observed_exit_code'] = 0;
            $state['rollback']['verdict'] = $verdict;
            $report = $this->postGateReport($passed, 'rollback');
            $report['intent_sha256'] = $this->deployRequest()['intent_sha256'];
            $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($report);
            $state['post_gates']['rollback_report_sha256'] = hash('sha256', $encoded);
            $state['post_gates']['rollback_submission_count'] = 1;
            $state['post_gates']['rollback_verdict'] = $passed ? 'passed' : 'failed';

            self::assertSame(
                $expected,
                DeploymentHostRunnerContractV1::postGateDisposition($encoded, $state, $encoded),
            );
        }
    }

    public function testFailedDeployReportReplayRemainsObserveOnly(): void
    {
        $encoded = $this->failedDeployPostGateReportBytes();
        foreach (['post_gates_running', 'rollback_running'] as $stateName) {
            $lines = $this->runThrough('post_gates_running');
            if ($stateName === 'rollback_running') {
                $lines[] = $this->transition($lines, 'rollback_running');
            }
            $state = $this->recoveryAdmissionState($lines, $stateName);

            self::assertSame(
                'attach_observe_only',
                DeploymentHostRunnerContractV1::postGateDisposition($encoded, $state, $encoded),
            );
        }
    }

    public function testPersistedPassingDeployReportConvergesToSuccess(): void
    {
        $state = $this->state();
        $state['state'] = 'post_gates_running';
        $state['active_action'] = 'none';
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['receipt_sha256'] = self::SHA;
        $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($this->postGateReport(true, 'deploy'));
        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $encoded);
        $state['post_gates']['deploy_submission_count'] = 1;
        $state['post_gates']['deploy_verdict'] = 'passed';

        self::assertSame('succeeded', DeploymentHostRunnerContractV1::postGateDisposition($encoded, $state, $encoded));
    }

    public function testPinnedReportBeforeStateSlotResumesTheFirstDisposition(): void
    {
        $state = $this->state();
        $state['state'] = 'post_gates_running';
        $state['active_action'] = 'none';
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['receipt_sha256'] = self::SHA;
        foreach ([[false, 'recovery_required'], [true, 'succeeded']] as [$passed, $expected]) {
            $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($this->postGateReport($passed, 'deploy'));

            self::assertSame(
                $expected,
                DeploymentHostRunnerContractV1::postGateDisposition($encoded, $state, $encoded),
            );
        }
    }

    public function testPostGateDispositionNeverDerivesAResultOverATerminalState(): void
    {
        foreach ([true, false] as $rollbackPassed) {
            $state = $this->dedicatedRollbackTerminalState($rollbackPassed);
            $encoded = $this->failedDeployPostGateReportBytes();

            try {
                DeploymentHostRunnerContractV1::postGateDisposition($encoded, $state, $encoded);
                self::fail('A post-gate report replay derived a transition over an immutable terminal state.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $state = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $report = $this->postGateReport(true, 'deploy');
        $report['intent_sha256'] = $state['intent_sha256'];
        $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($report);
        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $encoded);
        $manualState = $state;
        $manualState['state'] = 'manual_recovery_required';
        $manualState['terminal'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 70,
            'reason' => 'contract_invalid',
        ];
        DeploymentHostRunnerContractV1::validateState($manualState);

        foreach ([$state, $manualState] as $terminalState) {
            try {
                DeploymentHostRunnerContractV1::postGateDisposition($encoded, $terminalState, $encoded);
                self::fail('A deploy report replay replaced an immutable terminal state.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPersistedDeployReportCannotAttachBeforePostGateLifecycle(): void
    {
        $state = $this->state();
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['receipt_sha256'] = self::SHA;
        $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($this->postGateReport(false, 'deploy'));
        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $encoded);
        $state['post_gates']['deploy_submission_count'] = 1;
        $state['post_gates']['deploy_verdict'] = 'failed';

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::postGateSubmissionDisposition($encoded, $state, $encoded);
    }

    public function testDirectDeployRollbackReceiptsDoNotRequireDedicatedPostGateRecovery(): void
    {
        foreach (
            [
                ['failed_post_switch_rollback_succeeded', 30, 'deploy_failed', 'internal_rollback_succeeded'],
                ['failed_post_switch_rollback_failed', 31, 'rollback_failed', 'rollback_failed_or_unverifiable'],
            ]
            as [$stateName, $exitCode, $reason, $outcome]
        ) {
            $state = $this->state();
            $state['state'] = $stateName;
            $state['active_action'] = 'none';
            $state['deploy']['observed_exit_code'] = $exitCode;
            $state['deploy']['receipt_sha256'] = $this->receiptSha256($outcome);
            $state['evidence_sha256'] = self::SHA;
            $state['terminal'] = ['state' => $stateName, 'exit_code' => $exitCode, 'reason' => $reason];

            DeploymentHostRunnerContractV1::validateState($state);
        }

        self::addToAssertionCount(2);
    }

    public function testRollbackStateClosesExitReportAndVerdictMatrix(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, 'rollback_running');
        $state = $this->recoveryAdmissionState($lines, 'rollback_running');

        DeploymentHostRunnerContractV1::validateState($state);

        $pending = $state;
        $pending['rollback']['unit_state'] = 'exited';
        $pending['rollback']['observed_exit_code'] = 0;
        $pending['rollback']['verdict'] = 'verification_pending';
        DeploymentHostRunnerContractV1::validateState($pending);

        foreach ([['passed', 'succeeded'], ['failed', 'failed']] as [$reportVerdict, $rollbackVerdict]) {
            $submitted = $pending;
            $submitted['post_gates']['rollback_report_sha256'] = self::SHA;
            $submitted['post_gates']['rollback_submission_count'] = 1;
            $submitted['post_gates']['rollback_verdict'] = $reportVerdict;
            $submitted['rollback']['verdict'] = $rollbackVerdict;
            DeploymentHostRunnerContractV1::validateState($submitted);

            $submitted['rollback']['verdict'] = $rollbackVerdict === 'succeeded' ? 'failed' : 'succeeded';
            try {
                DeploymentHostRunnerContractV1::validateState($submitted);
                self::fail('A rollback report/verdict mismatch was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (['succeeded', 'failed'] as $prematureVerdict) {
            $invalid = $pending;
            $invalid['rollback']['verdict'] = $prematureVerdict;
            try {
                DeploymentHostRunnerContractV1::validateState($invalid);
                self::fail('Exit zero produced a final rollback verdict without a report.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $failedAction = $pending;
        $failedAction['rollback']['observed_exit_code'] = 9;
        $failedAction['rollback']['verdict'] = 'failed';
        DeploymentHostRunnerContractV1::validateState($failedAction);
    }

    public function testRollbackPostGateSubmissionFirstAndReplayBindTheFinalVerdict(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, 'rollback_running');
        $state = $this->recoveryAdmissionState($lines, 'rollback_running');
        $state['rollback']['unit_state'] = 'exited';
        $state['rollback']['observed_exit_code'] = 0;
        $state['rollback']['verdict'] = 'verification_pending';
        $report = $this->postGateReport(true, 'rollback');
        $report['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($report);

        self::assertSame(
            'first_submission',
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition($encoded, $state),
        );

        $state['post_gates']['rollback_report_sha256'] = hash('sha256', $encoded);
        $state['post_gates']['rollback_submission_count'] = 1;
        $state['post_gates']['rollback_verdict'] = 'passed';
        $state['rollback']['verdict'] = 'succeeded';
        self::assertSame(
            'attach',
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition($encoded, $state, $encoded),
        );
    }

    #[DataProvider('malformedRecoveryRequestProvider')]
    public function testRecoveryRequestRejectsInvalidOrNoncanonicalInput(array|string $candidate): void
    {
        $this->expectException(RuntimeException::class);
        if (is_string($candidate)) {
            DeploymentHostRunnerContractV1::decodeRecoveryRequest($candidate);
            return;
        }
        DeploymentHostRunnerContractV1::validateRecoveryRequest($candidate);
    }

    public static function malformedRecoveryRequestProvider(): iterable
    {
        $valid = [
            'schema' => DeploymentHostRunnerContractV1::RECOVERY_REQUEST_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
        ];
        yield 'missing field' => [array_diff_key($valid, ['intent_sha256' => true])];
        yield 'extra field' => [$valid + ['path' => '/root/previous']];
        yield 'wrong schema' => [[...$valid, 'schema' => 'deployment_host_recovery_request.v2']];
        yield 'bad run id' => [[...$valid, 'run_id' => '../run']];
        yield 'bad intent hash' => [[...$valid, 'intent_sha256' => 'not-a-hash']];
        yield 'noncanonical bytes' => ["{\n}\n"];
    }

    #[DataProvider('malformedDeployRequestProvider')]
    public function testDeployRequestRejectsMissingExtraOrInvalidFields(array $mutations): void
    {
        $request = $this->deployRequest();
        foreach ($mutations as $key => $value) {
            if ($value === '__unset__') {
                unset($request[$key]);
            } else {
                $request[$key] = $value;
            }
        }

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateDeployRequest($request);
    }

    public static function malformedDeployRequestProvider(): iterable
    {
        yield 'missing field' => [['release_id' => '__unset__']];
        yield 'extra field' => [['command' => '/root/deploy_ea.sh']];
        yield 'wrong schema' => [['schema' => 'deployment_host_runner_request.v2']];
        yield 'bad run id' => [['run_id' => '../run']];
        yield 'bad commit' => [['expected_commit' => str_repeat('g', 40)]];
        yield 'bad release' => [['release_id' => '--unsafe']];
        yield 'bad traffic mode' => [['traffic_mode' => 'guess']];
        yield 'mutable dump policy' => [['dump_policy' => 'latest']];
        yield 'mutable artifact policy' => [['artifact_expectation' => 'uploaded']];
        yield 'changed intent hash' => [['release_id' => 'ea_changed']];
    }

    #[DataProvider('nonCanonicalFileProvider')]
    public function testRequestDecodersRejectNonCanonicalOrUnsafeBytes(string $bytes): void
    {
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::decodeDeployRequest($bytes);
    }

    public static function nonCanonicalFileProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'missing newline' => ['{}'];
        yield 'pretty json' => ["{\n}\n"];
        yield 'nul' => ["{}\0\n"];
        yield 'oversized' => [str_repeat('a', 4097)];
        yield 'list' => ["[]\n"];
    }

    public function testStateSchemaBindsJournalRequestAndObservedResultWithoutFreeText(): void
    {
        $state = $this->state();
        DeploymentHostRunnerContractV1::validateState($state);

        self::assertEquals(
            $state,
            DeploymentHostRunnerContractV1::decodeState(DeploymentHostRunnerContractV1::encodeFile($state)),
        );
    }

    #[DataProvider('invalidStateProvider')]
    public function testStateRejectsContradictoryOrUnclosedShapes(array $changes): void
    {
        $state = $this->state();
        foreach ($changes as $key => $value) {
            if ($value === '__unset__') {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
        }

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    public static function invalidStateProvider(): iterable
    {
        yield 'extra key' => [['raw_output' => 'secret']];
        yield 'bad state' => [['state' => 'retrying']];
        yield 'wrong action' => [['active_action' => 'rollback']];
        yield 'evidence before terminal' => [['evidence_sha256' => self::SHA]];
    }

    public function testStateRejectsNestedActionContradictions(): void
    {
        $state = $this->state();
        $state['deploy']['invocation_count'] = 2;

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    public function testStateRejectsReceiptWithoutObservedExit(): void
    {
        $state = $this->state();
        $state['deploy']['observed_exit_code'] = null;

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    public function testReservedDeployRequiresExecutionInputAndReceiptCompatibleExit(): void
    {
        $state = $this->state();
        $state['deploy']['execution_input_sha256'] = null;

        try {
            DeploymentHostRunnerContractV1::validateState($state);
            self::fail('Reserved deploy without execution input was accepted.');
        } catch (RuntimeException) {
        }

        $state = $this->state();
        $state['deploy']['observed_exit_code'] = 74;

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    public function testReservedRollbackRequiresARealOrUnknownVerdict(): void
    {
        $state = $this->state();
        $state['state'] = DeploymentContractV1::ROLLBACK_RESERVATION_STATE;
        $state['active_action'] = 'rollback';
        $state['deploy']['observed_exit_code'] = 0;
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, self::INTENT_SHA),
            'unit_launch_sha256' => self::SHA,
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => str_repeat('d', 32),
            'unit_missing_observed_boot_id' => null,
            'unit_state' => 'running',
            'observed_exit_code' => null,
            'verdict' => 'not_invoked',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    public function testStateRejectsRollbackReservationBeforeRollbackRunning(): void
    {
        $state = $this->state();
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, self::INTENT_SHA),
            'unit_launch_sha256' => self::SHA,
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => str_repeat('d', 32),
            'unit_missing_observed_boot_id' => null,
            'unit_state' => 'running',
            'observed_exit_code' => null,
            'verdict' => 'unknown',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    #[DataProvider('unprovenTerminalUnitStateProvider')]
    public function testTerminalClaimCannotClearWithoutExactReservedUnitReconciliation(string $unitState): void
    {
        $claimLines = $this->runThrough('deploy_running');
        $lines = $claimLines;
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:11Z',
            'previous_state' => $previous['state'],
            'state' => 'manual_recovery_required',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 143,
            'reason' => 'interrupted',
        ]);
        $events = implode("\n", $lines) . "\n";
        $evidence = $this->succeededEvidence($lines);
        $evidence['deploy'] = [
            'status' => 'unknown',
            'invocation_count' => 1,
            'exit_code' => null,
            'rollback_outcome' => 'not_observed',
        ];
        $evidence['post_gates'] = array_map(static fn(mixed $_value): mixed => null, $evidence['post_gates']);
        $evidence['post_gates']['status'] = 'not_observed';
        $evidence['result'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 143,
            'reason' => 'interrupted',
        ];
        $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";
        $state = $this->terminalState('manual_recovery_required', 143, 'interrupted', null);
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['deploy']['unit_state'] = $unitState;
        $state['deploy']['observed_exit_code'] = null;
        $state['deploy']['receipt_sha256'] = null;
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $claimEvents = implode("\n", $claimLines) . "\n";
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => count($claimLines),
            'events_sha256' => hash('sha256', $claimEvents),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing a reserved unit reconciliation');
        DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $events,
            $evidenceBytes,
            self::RUN_ID,
            $state['intent_sha256'],
        );
    }

    public static function unprovenTerminalUnitStateProvider(): iterable
    {
        yield 'starting' => ['starting'];
        yield 'running' => ['running'];
        yield 'unknown' => ['unknown'];
    }

    public function testUnknownTerminalBundleAttachesButCannotClearTheActiveClaim(): void
    {
        $claimLines = $this->runThrough('deploy_running');
        $lines = $claimLines;
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:11Z',
            'previous_state' => $previous['state'],
            'state' => 'manual_recovery_required',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 143,
            'reason' => 'interrupted',
        ]);
        $events = implode("\n", $lines) . "\n";
        $evidence = $this->succeededEvidence($lines);
        $evidence['deploy'] = [
            'status' => 'unknown',
            'invocation_count' => 1,
            'exit_code' => null,
            'rollback_outcome' => 'not_observed',
        ];
        $evidence['post_gates'] = array_map(static fn(mixed $_value): mixed => null, $evidence['post_gates']);
        $evidence['post_gates']['status'] = 'not_observed';
        $evidence['result'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 143,
            'reason' => 'interrupted',
        ];
        $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";
        $launch = $this->systemdLaunch();
        $claimEvents = implode("\n", $claimLines) . "\n";
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => count($claimLines),
            'events_sha256' => hash('sha256', $claimEvents),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        $observations = [
            'transport error' => [
                'reserved',
                DeploymentHostRunnerContractV1::encodeFile([
                    'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                    'kind' => 'transport_error',
                    'manager_boot_id' => null,
                ]),
            ],
            'same-boot not found' => [
                'reserved',
                DeploymentHostRunnerContractV1::encodeFile([
                    'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                    'kind' => 'not_found',
                    'manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                ]),
            ],
            'loaded contradictory tuple' => [
                'observed',
                DeploymentHostRunnerContractV1::encodeFile([
                    'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
                    'manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                    'systemctl_show' => str_replace(
                        ['ActiveState=failed', 'SubState=failed'],
                        ['ActiveState=active', 'SubState=running'],
                        $this->systemctlShowBytes($launch),
                    ),
                ]),
            ],
        ];
        foreach ($observations as $label => [$bindingState, $observationBytes]) {
            $state = $this->terminalState('manual_recovery_required', 143, 'interrupted', null);
            $state['sequence'] = count($lines);
            $state['events_sha256'] = hash('sha256', $events);
            $state['deploy']['unit_state'] = 'unknown';
            $state['deploy']['observed_exit_code'] = null;
            $state['deploy']['receipt_sha256'] = null;
            $state['deploy']['unit_invocation_id'] = $bindingState === 'observed' ? str_repeat('d', 32) : null;
            $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
            $bundle = [
                'deploy' => [
                    'launch' => DeploymentHostRunnerContractV1::encodeFile($launch),
                    'binding' => DeploymentHostRunnerContractV1::encodeFile(
                        $this->unitBindingForLaunch($launch, $bindingState),
                    ),
                    'observation' => $observationBytes,
                ],
            ];
            self::assertSame(
                'current',
                DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                    $state,
                    $events,
                    $evidenceBytes,
                    null,
                    null,
                    $bundle,
                ),
                $label,
            );
            self::assertSame(
                'terminal',
                DeploymentHostRunnerContractV1::deployAttachmentDisposition(
                    $lines,
                    $this->deployRequest(),
                    $state,
                    $evidenceBytes,
                    null,
                    null,
                    $bundle,
                ),
                $label,
            );
            self::assertSame(
                'terminal_claim_held',
                DeploymentHostRunnerContractV1::activeRunDisposition(
                    $claim,
                    $state,
                    $events,
                    $evidenceBytes,
                    self::RUN_ID,
                    $state['intent_sha256'],
                    null,
                    null,
                    $bundle,
                ),
                $label,
            );
            try {
                DeploymentHostRunnerContractV1::activeRunDisposition(
                    $claim,
                    $state,
                    $events,
                    $evidenceBytes,
                    '228f6f52-4c87-4d4e-8b19-6a66e6e1af25',
                    $state['intent_sha256'],
                    null,
                    null,
                    $bundle,
                );
                self::fail($label . ' allowed a different run past the held terminal claim.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRebootProvenMissingUnitRefreshesAndClearsOnlyWithImmutableExactProof(): void
    {
        $claimLines = $this->runThrough('deploy_running');
        $lines = $claimLines;
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:11Z',
            'previous_state' => $previous['state'],
            'state' => 'manual_recovery_required',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 143,
            'reason' => 'interrupted',
        ]);
        $events = implode("\n", $lines) . "\n";
        $evidence = $this->succeededEvidence($lines);
        $evidence['deploy'] = [
            'status' => 'unknown',
            'invocation_count' => 1,
            'exit_code' => null,
            'rollback_outcome' => 'not_observed',
        ];
        $evidence['post_gates'] = array_map(static fn(mixed $_value): mixed => null, $evidence['post_gates']);
        $evidence['post_gates']['status'] = 'not_observed';
        $evidence['result'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 143,
            'reason' => 'interrupted',
        ];
        $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";

        $launch = $this->systemdLaunch();
        $reservedBootId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $missingBootId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $notFoundShow = str_replace(
            [
                'LoadState=loaded',
                'ActiveState=failed',
                'SubState=failed',
                'ExecMainCode=1',
                'ExecMainStatus=30',
                'InvocationID=' . str_repeat('d', 32),
                'Transient=yes',
            ],
            [
                'LoadState=not-found',
                'ActiveState=inactive',
                'SubState=dead',
                'ExecMainCode=0',
                'ExecMainStatus=0',
                'InvocationID=',
                'Transient=no',
            ],
            $this->systemctlShowBytes($launch),
        );
        $absence = DeploymentHostRunnerContractV1::unitAbsenceFromSystemctlResult(
            0,
            $notFoundShow,
            '',
            $launch,
            $missingBootId . "\n",
        );
        self::assertSame('not_found', $absence['kind']);
        self::assertSame($missingBootId, $absence['manager_boot_id']);

        $state = $this->terminalState('manual_recovery_required', 143, 'interrupted', null);
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['deploy']['unit_invocation_id'] = null;
        $state['deploy']['unit_missing_observed_boot_id'] = $missingBootId;
        $state['deploy']['unit_state'] = 'missing';
        $state['deploy']['observed_exit_code'] = null;
        $state['deploy']['receipt_sha256'] = null;
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        DeploymentHostRunnerContractV1::validateState($state);

        $unknown = $state;
        $unknown['deploy']['unit_missing_observed_boot_id'] = null;
        $unknown['deploy']['unit_state'] = 'unknown';
        DeploymentHostRunnerContractV1::validateState($unknown);
        DeploymentHostRunnerContractV1::validateStateEvolution($unknown, $state);

        $immutableMutations = [];
        $terminalResult = $unknown;
        $terminalResult['terminal'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 70,
            'reason' => 'contract_invalid',
        ];
        $immutableMutations['terminal result'] = $terminalResult;
        $journalPrefix = $unknown;
        $journalPrefix['sequence']++;
        $journalPrefix['events_sha256'] = str_repeat('e', 64);
        $immutableMutations['journal prefix'] = $journalPrefix;
        $evidenceBinding = $unknown;
        $evidenceBinding['evidence_sha256'] = str_repeat('e', 64);
        $immutableMutations['evidence binding'] = $evidenceBinding;
        foreach (['request_sha256', 'execution_input_sha256', 'unit_launch_sha256'] as $field) {
            $identity = $unknown;
            $identity['deploy'][$field] = str_repeat('e', 64);
            $immutableMutations[$field] = $identity;
        }
        $managerBoot = $unknown;
        $managerBoot['deploy']['unit_manager_boot_id'] = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $immutableMutations['manager boot binding'] = $managerBoot;
        foreach ($immutableMutations as $label => $candidate) {
            try {
                DeploymentHostRunnerContractV1::validateStateEvolution($unknown, $candidate);
                self::fail($label . ' changed after terminal persistence.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $completed = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $completedMutations = [];
        $receipt = $completed;
        $receipt['deploy']['receipt_sha256'] = str_repeat('e', 64);
        $completedMutations['receipt'] = $receipt;
        $report = $completed;
        $report['post_gates']['deploy_report_sha256'] = str_repeat('e', 64);
        $completedMutations['post-gate report'] = $report;
        foreach ($completedMutations as $label => $candidate) {
            try {
                DeploymentHostRunnerContractV1::validateStateEvolution($completed, $candidate);
                self::fail($label . ' changed after terminal persistence.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $bundle = [
            'deploy' => [
                'launch' => DeploymentHostRunnerContractV1::encodeFile($launch),
                'binding' => DeploymentHostRunnerContractV1::encodeFile(
                    $this->unitBindingForLaunch($launch, 'reserved'),
                ),
                'observation' => DeploymentHostRunnerContractV1::encodeFile($absence),
            ],
        ];
        self::assertSame(
            'current',
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $state,
                $events,
                $evidenceBytes,
                null,
                null,
                $bundle,
            ),
        );

        $claimEvents = implode("\n", $claimLines) . "\n";
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => count($claimLines),
            'events_sha256' => hash('sha256', $claimEvents),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];
        self::assertSame(
            'refresh_terminal_claim',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                $evidenceBytes,
                self::RUN_ID,
                $state['intent_sha256'],
                null,
                null,
                $bundle,
            ),
        );
        $claim['state'] = 'manual_recovery_required';
        $claim['sequence'] = count($lines);
        $claim['events_sha256'] = hash('sha256', $events);
        self::assertSame(
            'clear_terminal',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                $evidenceBytes,
                self::RUN_ID,
                $state['intent_sha256'],
                null,
                null,
                $bundle,
            ),
        );

        $changedProof = $state;
        $changedProof['deploy']['unit_missing_observed_boot_id'] = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        try {
            DeploymentHostRunnerContractV1::validateStateEvolution($state, $changedProof);
            self::fail('A persisted missing-unit boot proof was replaced.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $removedProof = $state;
        $removedProof['deploy']['unit_missing_observed_boot_id'] = null;
        try {
            DeploymentHostRunnerContractV1::validateState($removedProof);
            self::fail('A missing unit remained authoritative without its reboot proof.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $regressed = $state;
        $regressed['deploy']['unit_missing_observed_boot_id'] = null;
        $regressed['deploy']['unit_state'] = 'running';
        try {
            DeploymentHostRunnerContractV1::validateStateEvolution($state, $regressed);
            self::fail('A reboot-proven missing unit regressed to running.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $impossibleExit = $state;
        $impossibleExit['deploy']['observed_exit_code'] = 0;
        try {
            DeploymentHostRunnerContractV1::validateState($impossibleExit);
            self::fail('A reboot-proven missing unit carried a synthesized exit code.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $sameBootBundle = $bundle;
        $sameBootAbsence = $absence;
        $sameBootAbsence['manager_boot_id'] = $reservedBootId;
        $sameBootBundle['deploy']['observation'] = DeploymentHostRunnerContractV1::encodeFile($sameBootAbsence);
        try {
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $state,
                $events,
                $evidenceBytes,
                null,
                null,
                $sameBootBundle,
            );
            self::fail('Same-boot absence cleared a reboot-proven missing unit.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTerminalClaimCannotClearWhileRollbackUnitStateIsUnknown(): void
    {
        $claimLines = $this->runThrough('post_gates_running');
        $claimLines[] = $this->transition($claimLines, DeploymentContractV1::ROLLBACK_RESERVATION_STATE);
        $lines = $claimLines;
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:13Z',
            'previous_state' => $previous['state'],
            'state' => 'manual_recovery_required',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 143,
            'reason' => 'interrupted',
        ]);
        $events = implode("\n", $lines) . "\n";
        $evidence = $this->rollbackSucceededEvidence($lines);
        $evidence['rollback'] = [
            'status' => 'unknown',
            'invocation_count' => 1,
            'mode' => 'dedicated_post_gate_recovery',
            'verified' => null,
        ];
        $evidence['result'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 143,
            'reason' => 'interrupted',
        ];
        $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";
        $state = $this->terminalState('manual_recovery_required', 143, 'interrupted', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['deploy']['observed_exit_code'] = 0;
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, $state['intent_sha256']),
            'unit_launch_sha256' => self::SHA,
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => str_repeat('d', 32),
            'unit_missing_observed_boot_id' => null,
            'unit_state' => 'unknown',
            'observed_exit_code' => null,
            'verdict' => 'unknown',
        ];
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $claimEvents = implode("\n", $claimLines) . "\n";
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => DeploymentContractV1::ROLLBACK_RESERVATION_STATE,
            'sequence' => count($claimLines),
            'events_sha256' => hash('sha256', $claimEvents),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing a reserved unit reconciliation');
        DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $events,
            $evidenceBytes,
            self::RUN_ID,
            $state['intent_sha256'],
        );
    }

    public function testLateRollbackStopProofClearsTheClaimWithoutRewritingTheTerminalOutcome(): void
    {
        $claimLines = $this->runThrough('post_gates_running');
        $claimLines[] = $this->transition($claimLines, DeploymentContractV1::ROLLBACK_RESERVATION_STATE);
        $lines = $claimLines;
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:13Z',
            'previous_state' => $previous['state'],
            'state' => 'manual_recovery_required',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 143,
            'reason' => 'interrupted',
        ]);
        $events = implode("\n", $lines) . "\n";
        $claimEvents = implode("\n", $claimLines) . "\n";
        $evidence = $this->rollbackSucceededEvidence($lines);
        $evidence['rollback'] = [
            'status' => 'unknown',
            'invocation_count' => 1,
            'mode' => 'dedicated_post_gate_recovery',
            'verified' => null,
        ];
        $evidence['result'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 143,
            'reason' => 'interrupted',
        ];
        $deployReportBytes = $this->failedDeployPostGateReportBytes();
        $evidence['post_gates'] = DeploymentHostRunnerContractV1::decodePostGateReport($deployReportBytes)[
            'post_gates'
        ];
        $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";
        $deployLaunch = $this->systemdLaunch();
        $rollbackLaunch = $this->rollbackSystemdLaunch();

        $unknown = $this->recoveryAdmissionState($claimLines, DeploymentContractV1::ROLLBACK_RESERVATION_STATE);
        $unknown['state'] = 'manual_recovery_required';
        $unknown['sequence'] = count($lines);
        $unknown['events_sha256'] = hash('sha256', $events);
        $unknown['active_action'] = 'none';
        $unknown['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $unknown['terminal'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 143,
            'reason' => 'interrupted',
        ];
        foreach (['request_sha256', 'execution_input_sha256', 'unit_name'] as $field) {
            $unknown['deploy'][$field] = $deployLaunch[$field];
            $unknown['rollback'][$field] = $rollbackLaunch[$field];
        }
        $unknown['deploy']['unit_launch_sha256'] = DeploymentHostRunnerContractV1::fileSha256(
            DeploymentHostRunnerContractV1::encodeFile($deployLaunch),
        );
        $unknown['rollback']['unit_launch_sha256'] = DeploymentHostRunnerContractV1::fileSha256(
            DeploymentHostRunnerContractV1::encodeFile($rollbackLaunch),
        );
        $unknown['rollback']['unit_invocation_id'] = str_repeat('d', 32);
        $unknown['rollback']['unit_state'] = 'unknown';
        $unknown['rollback']['observed_exit_code'] = null;
        $unknown['rollback']['verdict'] = 'unknown';
        $unknown['post_gates']['deploy_report_sha256'] = hash('sha256', $deployReportBytes);

        $initialRollbackShow = str_replace(
            ['ActiveState=failed', 'SubState=failed'],
            ['ActiveState=active', 'SubState=running'],
            $this->systemctlShowBytes($rollbackLaunch),
        );
        $initialBundles = [
            ...$this->deployReconciliationBytes($unknown),
            'rollback' => [
                'launch' => DeploymentHostRunnerContractV1::encodeFile($rollbackLaunch),
                'binding' => DeploymentHostRunnerContractV1::encodeFile(
                    $this->unitBindingForLaunch($rollbackLaunch, 'observed'),
                ),
                'observation' => DeploymentHostRunnerContractV1::encodeFile([
                    'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
                    'manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                    'systemctl_show' => $initialRollbackShow,
                ]),
            ],
        ];
        self::assertSame(
            'current',
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $unknown,
                $events,
                $evidenceBytes,
                $deployReportBytes,
                null,
                $initialBundles,
            ),
        );
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $unknown['intent_sha256'],
            'state' => DeploymentContractV1::ROLLBACK_RESERVATION_STATE,
            'sequence' => count($claimLines),
            'events_sha256' => hash('sha256', $claimEvents),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];
        self::assertSame(
            'terminal_claim_held',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $unknown,
                $events,
                $evidenceBytes,
                self::RUN_ID,
                $unknown['intent_sha256'],
                $deployReportBytes,
                null,
                $initialBundles,
            ),
        );

        $cases = [
            'normal exit zero' => [
                'exited',
                0,
                str_replace(
                    ['ActiveState=failed', 'SubState=failed', 'Result=exit-code', 'ExecMainStatus=30'],
                    ['ActiveState=active', 'SubState=exited', 'Result=success', 'ExecMainStatus=0'],
                    $this->systemctlShowBytes($rollbackLaunch),
                ),
                null,
            ],
            'normal exit nonzero' => [
                'failed',
                9,
                str_replace('ExecMainStatus=30', 'ExecMainStatus=9', $this->systemctlShowBytes($rollbackLaunch)),
                null,
            ],
            'signal' => [
                'killed',
                null,
                str_replace(
                    ['Result=exit-code', 'ExecMainCode=1', 'ExecMainStatus=30'],
                    ['Result=signal', 'ExecMainCode=2', 'ExecMainStatus=15'],
                    $this->systemctlShowBytes($rollbackLaunch),
                ),
                null,
            ],
            'different-boot missing' => ['missing', null, null, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'],
        ];
        foreach ($cases as $label => [$unitState, $observedExit, $showBytes, $missingBoot]) {
            $candidate = $unknown;
            $candidate['rollback']['unit_state'] = $unitState;
            $candidate['rollback']['observed_exit_code'] = $observedExit;
            $candidate['rollback']['unit_missing_observed_boot_id'] = $missingBoot;
            $candidate['updated_at_utc'] = '2026-08-11T13:00:14Z';
            $bundles = $initialBundles;
            $bundles['rollback']['observation'] =
                $showBytes === null
                    ? DeploymentHostRunnerContractV1::encodeFile([
                        'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                        'kind' => 'not_found',
                        'manager_boot_id' => $missingBoot,
                    ])
                    : DeploymentHostRunnerContractV1::encodeFile([
                        'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
                        'manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                        'systemctl_show' => $showBytes,
                    ]);

            DeploymentHostRunnerContractV1::validateStateEvolution($unknown, $candidate);
            self::assertSame('unknown', $candidate['rollback']['verdict'], $label);
            self::assertSame('unknown', $evidence['rollback']['status'], $label);
            self::assertSame(
                'current',
                DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                    $candidate,
                    $events,
                    $evidenceBytes,
                    $deployReportBytes,
                    null,
                    $bundles,
                ),
                $label,
            );
            self::assertSame(
                'refresh_terminal_claim',
                DeploymentHostRunnerContractV1::activeRunDisposition(
                    $claim,
                    $candidate,
                    $events,
                    $evidenceBytes,
                    self::RUN_ID,
                    $candidate['intent_sha256'],
                    $deployReportBytes,
                    null,
                    $bundles,
                ),
                $label,
            );
            $terminalClaim = $claim;
            $terminalClaim['state'] = 'manual_recovery_required';
            $terminalClaim['sequence'] = count($lines);
            $terminalClaim['events_sha256'] = hash('sha256', $events);
            self::assertSame(
                'clear_terminal',
                DeploymentHostRunnerContractV1::activeRunDisposition(
                    $terminalClaim,
                    $candidate,
                    $events,
                    $evidenceBytes,
                    self::RUN_ID,
                    $candidate['intent_sha256'],
                    $deployReportBytes,
                    null,
                    $bundles,
                ),
                $label,
            );
            if ($observedExit !== null) {
                $replacedExit = $candidate;
                $replacedExit['rollback']['observed_exit_code'] = $observedExit === 0 ? 9 : 0;
                try {
                    DeploymentHostRunnerContractV1::validateStateEvolution($candidate, $replacedExit);
                    self::fail($label . ' replaced an already accepted late exit proof.');
                } catch (RuntimeException) {
                    self::addToAssertionCount(1);
                }
            }
        }

        $nonterminal = $unknown;
        $nonterminal['state'] = DeploymentContractV1::ROLLBACK_RESERVATION_STATE;
        $nonterminal['sequence'] = count($claimLines);
        $nonterminal['events_sha256'] = hash('sha256', $claimEvents);
        $nonterminal['active_action'] = 'rollback';
        $nonterminal['evidence_sha256'] = null;
        $nonterminal['terminal'] = ['state' => null, 'exit_code' => null, 'reason' => null];
        $nonterminal['rollback']['unit_state'] = 'exited';
        $nonterminal['rollback']['observed_exit_code'] = 0;
        try {
            DeploymentHostRunnerContractV1::validateState($nonterminal);
            self::fail('Nonterminal rollback kept an unknown verdict after an observed normal exit.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        foreach (['verification_pending', 'failed'] as $changedVerdict) {
            $changed = $unknown;
            $changed['rollback']['unit_state'] = $changedVerdict === 'verification_pending' ? 'exited' : 'failed';
            $changed['rollback']['observed_exit_code'] = $changedVerdict === 'verification_pending' ? 0 : 9;
            $changed['rollback']['verdict'] = $changedVerdict;
            try {
                DeploymentHostRunnerContractV1::validateStateEvolution($unknown, $changed);
                self::fail('Late unit proof rewrote the frozen terminal rollback verdict.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $reported = $unknown;
        $reported['rollback']['unit_state'] = 'exited';
        $reported['rollback']['observed_exit_code'] = 0;
        $reported['post_gates']['rollback_report_sha256'] = self::SHA;
        $reported['post_gates']['rollback_submission_count'] = 1;
        $reported['post_gates']['rollback_verdict'] = 'passed';
        try {
            DeploymentHostRunnerContractV1::validateState($reported);
            self::fail('Late stop proof added a rollback report to a frozen manual terminal.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    #[DataProvider('rollbackVerdictExitProvider')]
    public function testRollbackVerdictMustMatchObservedExit(string $verdict, int $observedExitCode): void
    {
        $state = $this->state();
        $state['state'] = DeploymentContractV1::ROLLBACK_RESERVATION_STATE;
        $state['active_action'] = 'rollback';
        $state['deploy']['observed_exit_code'] = 0;
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, self::INTENT_SHA),
            'unit_launch_sha256' => self::SHA,
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => str_repeat('d', 32),
            'unit_missing_observed_boot_id' => null,
            'unit_state' => 'exited',
            'observed_exit_code' => $observedExitCode,
            'verdict' => $verdict,
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    public static function rollbackVerdictExitProvider(): iterable
    {
        yield 'success with nonzero exit' => ['succeeded', 1];
        yield 'failure with zero exit' => ['failed', 0];
        yield 'unknown verdict with observed exit' => ['unknown', 1];
    }

    public function testTerminalStateRequiresStableResultAndEvidenceBinding(): void
    {
        $state = $this->state();
        $state['state'] = 'failed_pre_switch';
        $state['active_action'] = 'none';
        $state['terminal'] = [
            'state' => 'failed_pre_switch',
            'exit_code' => 30,
            'reason' => 'deploy_failed',
        ];
        $state['evidence_sha256'] = self::SHA;

        DeploymentHostRunnerContractV1::validateState($state);

        $state['terminal']['exit_code'] = 31;
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    public function testSucceededAndManualRecoveryTerminalStatesAreAccepted(): void
    {
        $succeeded = $this->state();
        $succeeded['state'] = 'succeeded';
        $succeeded['active_action'] = 'none';
        $succeeded['deploy']['unit_state'] = 'exited';
        $succeeded['deploy']['observed_exit_code'] = 0;
        $succeeded['post_gates'] = $this->submittedPostGates('passed');
        $succeeded['evidence_sha256'] = self::SHA;
        $succeeded['terminal'] = ['state' => 'succeeded', 'exit_code' => 0, 'reason' => 'ok'];

        DeploymentHostRunnerContractV1::validateState($succeeded);

        $manual = $this->state();
        $manual['state'] = 'manual_recovery_required';
        $manual['active_action'] = 'none';
        $manual['deploy']['unit_state'] = 'unknown';
        $manual['deploy']['observed_exit_code'] = null;
        $manual['deploy']['receipt_sha256'] = null;
        $manual['evidence_sha256'] = self::SHA;
        $manual['terminal'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 70,
            'reason' => 'contract_invalid',
        ];

        DeploymentHostRunnerContractV1::validateState($manual);

        self::addToAssertionCount(2);
    }

    public function testOperatorJournalIsClosedAndFixedEnumOnly(): void
    {
        $event = [
            'schema' => DeploymentHostRunnerContractV1::OPERATOR_EVENT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'sequence' => 1,
            'recorded_at_utc' => '2026-08-11T13:00:00Z',
            'action' => 'deploy',
            'event' => 'reservation_persisted',
            'status' => 'running',
            'reason' => 'none',
        ];

        DeploymentHostRunnerContractV1::validateOperatorEvent($event);

        $event['detail'] = '/secret/path';
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateOperatorEvent($event);
    }

    public function testOperatorJournalRejectsImpossibleTimestamp(): void
    {
        $event = $this->decodeFixture(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/deployment-host-runner-v1/operator-event.json'),
        );
        $event['recorded_at_utc'] = '2026-99-99T13:00:00Z';

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateOperatorEvent($event);
    }

    public function testActiveRunClaimAllowsReservedPhasesAndTerminalClearanceHandoff(): void
    {
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'state' => 'deploy_running',
            'sequence' => 11,
            'events_sha256' => self::SHA,
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        DeploymentHostRunnerContractV1::validateActiveRun($claim);

        $claim['state'] = 'succeeded';
        DeploymentHostRunnerContractV1::validateActiveRun($claim);

        $claim['state'] = 'artifact_verified';
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateActiveRun($claim);
    }

    public function testUnitIdentityBindsActionRunAndIntent(): void
    {
        self::assertSame(
            'fh-deploy-018f6f52-4c87-4d4e-8b19-6a66e6e1af25-aaaaaaaaaaaa.service',
            DeploymentHostRunnerContractV1::unitName('deploy', self::RUN_ID, self::INTENT_SHA),
        );
        self::assertSame(
            'fh-rollback-018f6f52-4c87-4d4e-8b19-6a66e6e1af25-aaaaaaaaaaaa.service',
            DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, self::INTENT_SHA),
        );

        $properties = DeploymentHostRunnerContractV1::unitProperties('deploy');
        self::assertSame('exec', $properties['Type']);
        self::assertSame('yes', $properties['RemainAfterExit']);
        self::assertSame('0077', $properties['UMask']);
        self::assertSame('control-group', $properties['KillMode']);
        self::assertSame('no', $properties['Restart']);
        self::assertSame('null', $properties['StandardInput']);
        self::assertSame('null', $properties['StandardOutput']);
        self::assertSame('null', $properties['StandardError']);
        self::assertArrayNotHasKey('CollectMode', $properties);

        $rollbackProperties = DeploymentHostRunnerContractV1::unitProperties('rollback');
        self::assertSame('1800s', $rollbackProperties['RuntimeMaxSec']);
        self::assertSame('300s', $rollbackProperties['TimeoutStopSec']);
        self::assertSame('no', $rollbackProperties['Restart']);
        self::assertSame('null', $rollbackProperties['StandardOutput']);
        self::assertSame('null', $rollbackProperties['StandardError']);
    }

    public function testSystemdLaunchIdentityIsSecretFreeAndReloadable(): void
    {
        $launch = $this->systemdLaunch();
        DeploymentHostRunnerContractV1::validateSystemdLaunch($launch);
        self::assertEquals(
            $launch,
            DeploymentHostRunnerContractV1::decodeSystemdLaunch(DeploymentHostRunnerContractV1::encodeFile($launch)),
        );

        $launchSha = DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch));
        $properties = DeploymentHostRunnerContractV1::unitProperties('deploy', $launchSha);
        self::assertSame(
            'fh-deployment-host-runner-v1-' . hash('sha256', "deployment_host_systemd_description.v1\0" . $launchSha),
            $properties['Description'],
        );
        self::assertStringNotContainsString($launch['launch_nonce'], $properties['Description']);
        self::assertStringNotContainsString($launch['execution_input_sha256'], $properties['Description']);
        self::assertSame('null', $properties['StandardOutput']);
        self::assertSame('null', $properties['StandardError']);
    }

    public function testUnitPreflightAndDifferentBootAbsenceAreClosed(): void
    {
        $bootId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $launch = $this->systemdLaunch();
        $notFound = 'Unit ' . $launch['unit_name'] . " could not be found.\n";
        $notFoundShow = str_replace(
            [
                'LoadState=loaded',
                'ActiveState=failed',
                'SubState=failed',
                'ExecMainCode=1',
                'ExecMainStatus=30',
                'InvocationID=' . str_repeat('d', 32),
                'Transient=yes',
            ],
            [
                'LoadState=not-found',
                'ActiveState=inactive',
                'SubState=dead',
                'ExecMainCode=0',
                'ExecMainStatus=0',
                'InvocationID=',
                'Transient=no',
            ],
            $this->systemctlShowBytes($launch),
        );
        self::assertSame(
            'available',
            DeploymentHostRunnerContractV1::unitPreflightDisposition(
                0,
                $notFoundShow,
                '',
                $launch,
                $bootId,
                $bootId . "\n",
            ),
        );
        self::assertSame(
            'collision',
            DeploymentHostRunnerContractV1::unitPreflightDisposition(
                0,
                $this->systemctlShowBytes($launch),
                '',
                $launch,
                $bootId,
                $bootId . "\n",
            ),
        );
        self::assertSame(
            'unknown',
            DeploymentHostRunnerContractV1::unitPreflightDisposition(
                1,
                '',
                "transport failed\n",
                $launch,
                $bootId,
                $bootId . "\n",
            ),
        );
        foreach (
            [
                str_replace(
                    ['ActiveState=inactive', 'SubState=dead'],
                    ['ActiveState=active', 'SubState=running'],
                    $notFoundShow,
                ),
                str_replace('Transient=no', 'Transient=yes', $notFoundShow),
            ]
            as $contradictoryNotFound
        ) {
            self::assertSame(
                'unknown',
                DeploymentHostRunnerContractV1::unitPreflightDisposition(
                    0,
                    $contradictoryNotFound,
                    '',
                    $launch,
                    $bootId,
                    $bootId . "\n",
                ),
            );
        }
        self::assertSame(
            'unknown',
            DeploymentHostRunnerContractV1::unitPreflightDisposition(
                1,
                '',
                $notFound,
                $launch,
                $bootId,
                "dddddddd-dddd-4ddd-8ddd-dddddddddddd\n",
            ),
        );

        self::assertSame('observe_only', DeploymentHostRunnerContractV1::systemdAdmissionDisposition(true, 0));
        self::assertSame(
            'observe_only_reconciliation_required',
            DeploymentHostRunnerContractV1::systemdAdmissionDisposition(true, 1),
        );
        self::assertSame(
            'observe_only_reconciliation_required',
            DeploymentHostRunnerContractV1::systemdAdmissionDisposition(true, null),
        );
        foreach ([['deploy', 'deploy_running'], ['recovery', 'rollback_running']] as [$action, $stateName]) {
            $response = [
                'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
                'run_id' => self::RUN_ID,
                'intent_sha256' => self::INTENT_SHA,
                'action' => $action,
                'disposition' => 'accepted',
                'state' => $stateName,
                'result_exit_code' => 0,
                'result_reason' => 'ok',
            ];
            DeploymentHostRunnerContractV1::validateResponse($response);
            $response['disposition'] = 'attach_observe_only';
            DeploymentHostRunnerContractV1::validateResponse($response);
        }
        try {
            DeploymentHostRunnerContractV1::systemdAdmissionDisposition(false, 0);
            self::fail('systemd admission ran before its durable reservation.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(
            [
                'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                'kind' => 'not_found',
                'manager_boot_id' => $bootId,
            ],
            DeploymentHostRunnerContractV1::unitAbsenceFromSystemctlResult(
                0,
                $notFoundShow,
                '',
                $launch,
                $bootId . "\n",
            ),
        );
        self::assertSame(
            'transport_error',
            DeploymentHostRunnerContractV1::unitAbsenceFromSystemctlResult(
                1,
                '',
                "unexpected\n",
                $launch,
                $bootId . "\n",
            )['kind'],
        );
        try {
            DeploymentHostRunnerContractV1::unitAbsenceFromSystemctlResult(
                0,
                $this->systemctlShowBytes($launch),
                '',
                $launch,
                $bootId . "\n",
            );
            self::fail('A loaded unit was accepted as an absence proof.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $binding = $this->unitBinding('observed');
        self::assertSame(
            'unknown',
            DeploymentHostRunnerContractV1::classifyUnitObservation($binding, [
                'kind' => 'not_found',
                'manager_boot_id' => $bootId,
            ]),
        );
        self::assertSame(
            'missing',
            DeploymentHostRunnerContractV1::classifyUnitObservation($binding, [
                'kind' => 'not_found',
                'manager_boot_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            ]),
        );
    }

    public function testObservedUnitBindingCannotBeReplaced(): void
    {
        $reserved = $this->unitBinding('reserved');
        $observed = $this->unitBinding('observed');
        DeploymentHostRunnerContractV1::validateUnitBindingEvolution($reserved, $observed);

        foreach (['unit_launch_sha256', 'unit_manager_boot_id', 'unit_invocation_id'] as $field) {
            $replacement = $observed;
            $replacement[$field] = match ($field) {
                'unit_manager_boot_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                default => str_repeat('e', 64 === strlen((string) $replacement[$field]) ? 64 : 32),
            };
            try {
                DeploymentHostRunnerContractV1::validateUnitBindingEvolution($observed, $replacement);
                self::fail($field . ' replacement was accepted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testUnitReconciliationBundleBindsLaunchStateAndObservedIdentity(): void
    {
        $launch = $this->systemdLaunch();
        $launchSha = DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch));
        $binding = $this->unitBindingForLaunch($launch, 'observed');
        $state = $this->state();
        $state['intent_sha256'] = $launch['intent_sha256'];
        $state['deploy']['request_sha256'] = $launch['request_sha256'];
        $state['deploy']['execution_input_sha256'] = $launch['execution_input_sha256'];
        $state['deploy']['unit_name'] = $launch['unit_name'];
        $state['deploy']['unit_launch_sha256'] = $launchSha;
        $state['deploy']['unit_state'] = 'failed';
        $observation = [
            'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
            'manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'systemctl_show' => $this->systemctlShowBytes($launch),
        ];
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle($launch, $binding, $state, $observation);

        foreach (['unit_launch_sha256', 'unit_manager_boot_id', 'unit_invocation_id'] as $field) {
            $candidate = $state;
            $candidate['deploy'][$field] = match ($field) {
                'unit_manager_boot_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                'unit_invocation_id' => str_repeat('e', 32),
                default => str_repeat('e', 64),
            };
            try {
                DeploymentHostRunnerContractV1::validateUnitReconciliationBundle(
                    $launch,
                    $binding,
                    $candidate,
                    $observation,
                );
                self::fail($field . ' mismatch was accepted');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        $wrongBootObservation = $observation;
        $wrongBootObservation['manager_boot_id'] = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        try {
            DeploymentHostRunnerContractV1::validateUnitReconciliationBundle(
                $launch,
                $binding,
                $state,
                $wrongBootObservation,
            );
            self::fail('A loaded replacement from another manager boot was accepted.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testSystemdCommandsAndObservationMappingAreClosed(): void
    {
        $launch = $this->systemdLaunch();
        $launchSha = DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch));
        $childArgv = DeploymentHostRunnerContractV1::executionArgv(
            $this->deployExecutionInput(),
            $this->deployRequest(),
        );
        $description =
            'fh-deployment-host-runner-v1-' . hash('sha256', "deployment_host_systemd_description.v1\0" . $launchSha);
        self::assertSame(
            [
                '/usr/bin/env',
                '-i',
                'LANG=C',
                'LC_ALL=C',
                'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
                '/usr/bin/systemd-run',
                '--quiet',
                '--expand-environment=no',
                '--unit=' . $launch['unit_name'],
                '--property=Type=exec',
                '--property=RemainAfterExit=yes',
                '--property=UMask=0077',
                '--property=KillMode=control-group',
                '--property=Restart=no',
                '--property=RuntimeMaxSec=7200s',
                '--property=TimeoutStopSec=300s',
                '--property=StandardInput=null',
                '--property=StandardOutput=null',
                '--property=StandardError=null',
                '--property=Description=' . $description,
                '--',
                ...$childArgv,
            ],
            DeploymentHostRunnerContractV1::systemdRunArgv(
                $launch,
                $this->unitBindingForLaunch($launch, 'reserved'),
                "cccccccc-cccc-4ccc-8ccc-cccccccccccc\n",
                $this->deployExecutionInput(),
                $this->deployRequest(),
                null,
                "#!/bin/bash\n",
            ),
        );
        self::assertSame(
            [
                '/usr/bin/env',
                '-i',
                'LANG=C',
                'LC_ALL=C',
                'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
                '/bin/systemctl',
                'show',
                '--no-pager',
                '--property=Id,LoadState,ActiveState,SubState,Result,ExecMainCode,ExecMainStatus,InvocationID,Description,Transient,Type,RemainAfterExit,UMask,KillMode,Restart,RuntimeMaxUSec,TimeoutStopUSec,StandardInput,StandardOutput,StandardError',
                $launch['unit_name'],
            ],
            DeploymentHostRunnerContractV1::systemctlShowArgv($launch['unit_name']),
        );

        $base = $this->systemdObservation($launch, $launchSha);
        $cases = [
            'starting' => [
                [
                    'active_state' => 'activating',
                    'sub_state' => 'start',
                    'result' => 'success',
                    'exec_main_code' => 0,
                    'exec_main_status' => 0,
                ],
                ['unit_state' => 'starting', 'observed_exit_code' => null, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
            'running' => [
                [
                    'active_state' => 'active',
                    'sub_state' => 'running',
                    'result' => 'success',
                    'exec_main_code' => 0,
                    'exec_main_status' => 0,
                ],
                ['unit_state' => 'running', 'observed_exit_code' => null, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
            'exited' => [
                [
                    'active_state' => 'active',
                    'sub_state' => 'exited',
                    'result' => 'success',
                    'exec_main_code' => 1,
                    'exec_main_status' => 0,
                ],
                ['unit_state' => 'exited', 'observed_exit_code' => 0, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
            'failed' => [
                [
                    'active_state' => 'failed',
                    'sub_state' => 'failed',
                    'result' => 'exit-code',
                    'exec_main_code' => 1,
                    'exec_main_status' => 30,
                ],
                ['unit_state' => 'failed', 'observed_exit_code' => 30, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
            'normal exit 143 remains a normal exit' => [
                [
                    'active_state' => 'failed',
                    'sub_state' => 'failed',
                    'result' => 'exit-code',
                    'exec_main_code' => 1,
                    'exec_main_status' => 143,
                ],
                ['unit_state' => 'failed', 'observed_exit_code' => 143, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
            'killed' => [
                [
                    'active_state' => 'failed',
                    'sub_state' => 'failed',
                    'result' => 'signal',
                    'exec_main_code' => 2,
                    'exec_main_status' => 15,
                ],
                ['unit_state' => 'killed', 'observed_exit_code' => null, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
            'core dumped' => [
                [
                    'active_state' => 'failed',
                    'sub_state' => 'failed',
                    'result' => 'core-dump',
                    'exec_main_code' => 3,
                    'exec_main_status' => 11,
                ],
                ['unit_state' => 'killed', 'observed_exit_code' => null, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
            'timeout' => [
                [
                    'active_state' => 'failed',
                    'sub_state' => 'failed',
                    'result' => 'timeout',
                    'exec_main_code' => 2,
                    'exec_main_status' => 15,
                ],
                ['unit_state' => 'killed', 'observed_exit_code' => null, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
            'contradictory active exit' => [
                [
                    'active_state' => 'active',
                    'sub_state' => 'running',
                    'result' => 'exit-code',
                    'exec_main_code' => 1,
                    'exec_main_status' => 30,
                ],
                ['unit_state' => 'unknown', 'observed_exit_code' => null, 'unit_invocation_id' => str_repeat('d', 32)],
            ],
        ];
        foreach ($cases as $label => [$changes, $expected]) {
            self::assertSame(
                $expected,
                DeploymentHostRunnerContractV1::classifySystemdObservation($launch, [...$base, ...$changes]),
                $label,
            );
        }

        foreach (['id', 'description', 'properties', 'unit_invocation_id'] as $field) {
            $mismatch = $base;
            $mismatch[$field] = match ($field) {
                'id' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, self::INTENT_SHA),
                'description' => 'fh-deployment-host-runner-v1-' . str_repeat('0', 64),
                'properties' => [...$base['properties'], 'Restart' => 'always'],
                'unit_invocation_id' => null,
            };
            self::assertSame(
                ['unit_state' => 'unknown', 'observed_exit_code' => null, 'unit_invocation_id' => null],
                DeploymentHostRunnerContractV1::classifySystemdObservation($launch, $mismatch),
                $field . ' mismatch',
            );
        }

        try {
            DeploymentHostRunnerContractV1::systemdRunArgv(
                $launch,
                $this->unitBindingForLaunch($launch, 'reserved'),
                "dddddddd-dddd-4ddd-8ddd-dddddddddddd\n",
                $this->deployExecutionInput(),
                $this->deployRequest(),
                null,
                "#!/bin/bash\n",
            );
            self::fail('A launch reserved on another manager boot was admitted.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $forged = $this->deployExecutionInput();
        $forged['parameters']['release_id'] = 'ea_forged';
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::systemdRunArgv(
            $launch,
            $this->unitBindingForLaunch($launch, 'reserved'),
            "cccccccc-cccc-4ccc-8ccc-cccccccccccc\n",
            $forged,
            $this->deployRequest(),
            null,
            "#!/bin/bash\n",
        );
    }

    public function testSystemctlShowParserRejectsAmbiguousOrIncompleteBytes(): void
    {
        $launch = $this->systemdLaunch();
        $bytes = $this->systemctlShowBytes($launch);
        $parsed = DeploymentHostRunnerContractV1::parseSystemctlShow($bytes, $launch);
        self::assertSame($launch['unit_name'], $parsed['id']);
        self::assertSame(str_repeat('d', 32), $parsed['unit_invocation_id']);

        $mutations = [
            'missing field' => preg_replace('/^Restart=.*\n/m', '', $bytes),
            'duplicate field' => $bytes . 'Id=' . $launch['unit_name'] . "\n",
            'unknown field' => $bytes . "Unknown=value\n",
            'malformed line' => str_replace('Result=exit-code', 'Result', $bytes),
            'embedded equals' => str_replace('Description=', 'Description=bad=', $bytes),
            'signed status' => str_replace('ExecMainStatus=30', 'ExecMainStatus=-1', $bytes),
            'whitespace status' => str_replace('ExecMainStatus=30', 'ExecMainStatus= 30', $bytes),
            'empty invocation' => str_replace('InvocationID=' . str_repeat('d', 32), 'InvocationID=', $bytes),
            'zero invocation' => str_replace(
                'InvocationID=' . str_repeat('d', 32),
                'InvocationID=' . str_repeat('0', 32),
                $bytes,
            ),
            'mismatched Id' => str_replace(
                $launch['unit_name'],
                str_replace('deploy', 'rollback', $launch['unit_name']),
                $bytes,
            ),
            'mismatched Description' => preg_replace('/^Description=.*$/m', 'Description=forged', $bytes),
            'mismatched property' => str_replace('Restart=no', 'Restart=always', $bytes),
            'overflow status' => str_replace('ExecMainStatus=30', 'ExecMainStatus=999999999999999999999', $bytes),
            'missing final newline' => substr($bytes, 0, -1),
            'nul byte' => $bytes . "\0",
            'oversized' => $bytes . str_repeat('x', 32_769),
        ];
        foreach ($mutations as $label => $candidate) {
            self::assertIsString($candidate);
            try {
                DeploymentHostRunnerContractV1::parseSystemctlShow($candidate, $launch);
                self::fail($label . ' was accepted');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testStateEvolutionPreservesLifecycleAndUnitIdentity(): void
    {
        $starting = $this->state();
        $starting['deploy']['unit_state'] = 'starting';
        $starting['deploy']['unit_invocation_id'] = null;
        $starting['deploy']['observed_exit_code'] = null;
        $starting['deploy']['receipt_sha256'] = null;
        $running = $starting;
        $running['deploy']['unit_state'] = 'running';
        $running['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        DeploymentHostRunnerContractV1::validateStateEvolution($starting, $running);

        $invalid = [];
        $replacement = $running;
        $replacement['deploy']['unit_invocation_id'] = str_repeat('e', 32);
        $invalid['InvocationID replacement'] = $replacement;
        $backward = $running;
        $backward['deploy']['unit_state'] = 'starting';
        $invalid['unit state regression'] = $backward;
        $lifecycle = $running;
        $lifecycle['state'] = 'accepted';
        $lifecycle['active_action'] = 'none';
        $invalid['lifecycle regression'] = $lifecycle;
        foreach ($invalid as $label => $candidate) {
            try {
                DeploymentHostRunnerContractV1::validateStateEvolution($running, $candidate);
                self::fail($label . ' was accepted');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $sameStateNewJournal = $running;
        $sameStateNewJournal['sequence']++;
        $sameStateNewJournal['events_sha256'] = str_repeat('e', 64);
        try {
            DeploymentHostRunnerContractV1::validateStateEvolution($running, $sameStateNewJournal);
            self::fail('A same-lifecycle cache update invented a journal record.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $terminal = $this->terminalState('failed_pre_switch', 30, 'deploy_failed', 'failed_pre_switch');
        $terminal['intent_sha256'] = $running['intent_sha256'];
        foreach (
            [
                'request_sha256',
                'execution_input_sha256',
                'unit_name',
                'unit_launch_sha256',
                'unit_manager_boot_id',
                'unit_invocation_id',
            ]
            as $field
        ) {
            $terminal['deploy'][$field] = $running['deploy'][$field];
        }
        $terminal['sequence'] = $running['sequence'] + 1;
        $terminal['events_sha256'] = str_repeat('e', 64);
        DeploymentHostRunnerContractV1::validateStateEvolution($running, $terminal);
        $terminal['sequence'] = $running['sequence'];
        $terminal['events_sha256'] = $running['events_sha256'];
        try {
            DeploymentHostRunnerContractV1::validateStateEvolution($running, $terminal);
            self::fail('A lifecycle transition reused the previous authoritative journal prefix.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $capacityLines = $this->runThrough('capacity_passed');
        $preDeploy = $this->stateForJournal($capacityLines, 'capacity_passed');
        $deployLines = $this->runThrough('deploy_running');
        $skipped = $this->stateForJournal($deployLines, 'deploy_running');
        try {
            DeploymentHostRunnerContractV1::validateStateEvolution($preDeploy, $skipped);
            self::fail('A lifecycle transition skipped artifact verification.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $manual = $preDeploy;
        $manual['state'] = 'manual_recovery_required';
        $manual['sequence']++;
        $manual['events_sha256'] = str_repeat('e', 64);
        $manual['evidence_sha256'] = self::SHA;
        $manual['terminal'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 70,
            'reason' => 'contract_invalid',
        ];
        try {
            DeploymentHostRunnerContractV1::validateStateEvolution($preDeploy, $manual);
            self::fail('A pre-deploy state entered a post-reservation manual terminal.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTerminalPersistenceOrderMakesPinnedInputsRecoverable(): void
    {
        self::assertSame(
            [
                'pinned_action_observations',
                'evidence_candidate',
                'terminal_event',
                'terminal_evidence',
                'terminal_state',
                'terminal_active_claim',
                'active_claim_clearance',
            ],
            DeploymentHostRunnerContractV1::terminalPersistenceOrder(),
        );
    }

    public function testManagerBootIdParserAcceptsOnlyExactKernelBytes(): void
    {
        $bootId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        self::assertSame($bootId, DeploymentHostRunnerContractV1::parseManagerBootId($bootId . "\n"));
        foreach ([$bootId, strtoupper($bootId) . "\n", $bootId . "\nextra", $bootId . "\0\n"] as $encoded) {
            try {
                DeploymentHostRunnerContractV1::parseManagerBootId($encoded);
                self::fail('Invalid manager boot bytes were accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTerminalPersistenceResumesOnlyAnExactDurablePrefix(): void
    {
        $order = DeploymentHostRunnerContractV1::terminalPersistenceOrder();
        foreach (range(0, count($order)) as $length) {
            self::assertSame(
                $order[$length] ?? 'complete',
                DeploymentHostRunnerContractV1::terminalPersistenceResumeStep(array_slice($order, 0, $length)),
            );
        }
        foreach (
            [[$order[1]], [$order[0], $order[2]], [$order[0], $order[0]], [$order[1], $order[0]]]
            as $invalidPrefix
        ) {
            try {
                DeploymentHostRunnerContractV1::terminalPersistenceResumeStep($invalidPrefix);
                self::fail('A non-authoritative terminal crash prefix was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLateTerminalObservationPersistenceCannotOutrunItsPinnedProof(): void
    {
        $order = DeploymentHostRunnerContractV1::lateTerminalObservationPersistenceOrder();
        self::assertSame(
            [
                'pinned_late_action_observation',
                'converged_terminal_state',
                'terminal_active_claim',
                'active_claim_clearance',
            ],
            $order,
        );
        self::assertSame(
            [
                'pin_action_observation',
                'persist_converged_terminal_state',
                'refresh_terminal_claim',
                'clear_terminal',
                'complete',
            ],
            array_map(
                static fn(
                    int $length,
                ): string => DeploymentHostRunnerContractV1::lateTerminalObservationResumeDisposition(
                    array_slice($order, 0, $length),
                ),
                range(0, count($order)),
            ),
        );
        foreach (
            [
                'state ahead of observation' => [$order[1]],
                'clearance before state' => [$order[0], $order[3]],
                'claim refresh before state' => [$order[0], $order[2]],
                'duplicate observation generation' => [$order[0], $order[0]],
                'clearance without refreshed claim' => [$order[0], $order[1], $order[3]],
            ]
            as $label => $invalidPrefix
        ) {
            try {
                DeploymentHostRunnerContractV1::lateTerminalObservationResumeDisposition($invalidPrefix);
                self::fail($label . ' was accepted as a durable late-observation prefix.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testActiveRunReconstructionUsesTheReservationJournalNotAYoungerCache(): void
    {
        self::assertSame('no_reserved_run', DeploymentHostRunnerContractV1::activeRunReconstructionDisposition([]));

        $deployLines = $this->runThrough('deploy_running');
        $deployEvents = implode("\n", $deployLines) . "\n";
        self::assertSame(
            'reconstruct_claim_observe_only',
            DeploymentHostRunnerContractV1::activeRunReconstructionDisposition([
                ['state' => null, 'events_bytes' => $deployEvents],
            ]),
        );

        $staleLines = array_slice($deployLines, 0, -1);
        $stale = $this->stateForJournal($staleLines, 'artifact_verified');
        self::assertSame(
            'reconstruct_claim_observe_only',
            DeploymentHostRunnerContractV1::activeRunReconstructionDisposition([
                ['state' => $stale, 'events_bytes' => $deployEvents],
            ]),
        );

        $postGateLines = $this->runThrough('post_gates_running');
        $postGateEvents = implode("\n", $postGateLines) . "\n";
        self::assertSame(
            'reconstruct_claim_observe_only',
            DeploymentHostRunnerContractV1::activeRunReconstructionDisposition([
                [
                    'state' => $this->stateForJournal($postGateLines, 'post_gates_running'),
                    'events_bytes' => $postGateEvents,
                ],
            ]),
        );

        $rollbackLines = $postGateLines;
        $rollbackLines[] = $this->transition($rollbackLines, 'rollback_running');
        $rollbackEvents = implode("\n", $rollbackLines) . "\n";
        self::assertSame(
            'reconstruct_claim_observe_only',
            DeploymentHostRunnerContractV1::activeRunReconstructionDisposition([
                [
                    'state' => $this->stateForJournal($postGateLines, 'post_gates_running'),
                    'events_bytes' => $rollbackEvents,
                ],
            ]),
        );

        foreach (
            [
                [
                    ['state' => null, 'events_bytes' => $deployEvents],
                    ['state' => null, 'events_bytes' => $deployEvents],
                ],
                [['state' => null, 'events_bytes' => "{}\n"]],
                [['state' => null, 'events_bytes' => $deployEvents . "\0"]],
                [['state' => null, 'events_bytes' => str_repeat('x', 1_048_577)]],
                [['state' => $stale, 'events_bytes' => $postGateEvents]],
                [
                    [
                        'state' => $this->stateForJournal($deployLines, 'deploy_running'),
                        'events_bytes' => $postGateEvents,
                    ],
                ],
                ['not-a-list' => ['state' => null, 'events_bytes' => $deployEvents]],
            ]
            as $candidates
        ) {
            try {
                DeploymentHostRunnerContractV1::activeRunReconstructionDisposition($candidates);
                self::fail('An ambiguous or corrupt reconstruction scan was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOperatorJournalRejectsCartesianButImpossibleTuple(): void
    {
        $event = [
            'schema' => DeploymentHostRunnerContractV1::OPERATOR_EVENT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'sequence' => 1,
            'recorded_at_utc' => '2026-08-11T13:00:00Z',
            'action' => 'rollback',
            'event' => 'receipt_accepted',
            'status' => 'ok',
            'reason' => 'receipt_valid',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateOperatorEvent($event);
    }

    public function testOperatorJournalCompatibilityIsAClosedReachabilityMatrix(): void
    {
        $valid = [
            ['deploy', 'request_accepted', 'ok', 'none'],
            ['post_gates', 'request_accepted', 'ok', 'none'],
            ['rollback', 'request_accepted', 'ok', 'none'],
            ['reconcile', 'attached', 'ok', 'same_intent'],
            ['deploy', 'reservation_persisted', 'running', 'none'],
            ['rollback', 'unit_started', 'running', 'none'],
            ['deploy', 'unit_observed', 'running', 'unit_running'],
            ['deploy', 'unit_observed', 'ok', 'unit_exited'],
            ['rollback', 'unit_observed', 'failed', 'unit_failed'],
            ['rollback', 'unit_observed', 'failed', 'unit_killed'],
            ['rollback', 'unit_observed', 'unknown', 'unit_missing'],
            ['deploy', 'receipt_accepted', 'ok', 'receipt_valid'],
            ['deploy', 'receipt_rejected', 'failed', 'receipt_missing'],
            ['deploy', 'receipt_rejected', 'failed', 'receipt_invalid'],
            ['deploy', 'receipt_rejected', 'failed', 'receipt_mismatch'],
            ['deploy', 'receipt_rejected', 'failed', 'child_exit_74'],
            ['post_gates', 'post_gates_observed', 'ok', 'none'],
            ['post_gates', 'post_gates_observed', 'failed', 'post_gate_failed'],
            ['rollback', 'rollback_reserved', 'running', 'post_gate_failed'],
            ['reconcile', 'reconciliation_required', 'unknown', 'manual_recovery_required'],
            ['reconcile', 'active_run_cleared', 'ok', 'none'],
        ];
        $terminalReasons = [
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
        ];
        foreach ($terminalReasons as $action => $reasons) {
            foreach ($reasons as $reason) {
                $valid[] = [$action, 'terminal_persisted', 'terminal', $reason];
            }
        }
        foreach ($valid as $sequence => [$action, $event, $status, $reason]) {
            DeploymentHostRunnerContractV1::validateOperatorEvent(
                $this->operatorEvent($sequence + 1, $action, $event, $status, $reason),
            );
        }

        $invalid = [
            ['reconcile', 'request_accepted', 'ok', 'none'],
            ['rollback', 'receipt_accepted', 'ok', 'receipt_valid'],
            ['deploy', 'terminal_persisted', 'terminal', 'ok'],
            ['post_gates', 'terminal_persisted', 'terminal', 'expected_commit_mismatch'],
            ['post_gates', 'terminal_persisted', 'terminal', 'state_conflict'],
            ['rollback', 'terminal_persisted', 'terminal', 'deploy_failed'],
            ['deploy', 'terminal_persisted', 'failed', 'deploy_failed'],
        ];
        foreach ($invalid as $sequence => [$action, $event, $status, $reason]) {
            try {
                DeploymentHostRunnerContractV1::validateOperatorEvent(
                    $this->operatorEvent($sequence + 100, $action, $event, $status, $reason),
                );
                self::fail('An unreachable operator tuple was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCliResponseSeparatesAttachExitFromStoredTerminalResult(): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'reconcile',
            'disposition' => 'terminal',
            'state' => 'failed_pre_switch',
            'result_exit_code' => 30,
            'result_reason' => 'deploy_failed',
        ];

        DeploymentHostRunnerContractV1::validateResponse($response);

        self::assertSame(0, DeploymentHostRunnerContractV1::cliExitCode($response));
    }

    #[DataProvider('validTerminalResponseProvider')]
    public function testCliResponseAcceptsEveryDefinedTerminalResult(string $state, int $exitCode, string $reason): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'reconcile',
            'disposition' => 'terminal',
            'state' => $state,
            'result_exit_code' => $exitCode,
            'result_reason' => $reason,
        ];

        DeploymentHostRunnerContractV1::validateResponse($response);

        self::assertSame(0, DeploymentHostRunnerContractV1::cliExitCode($response));
    }

    public static function validTerminalResponseProvider(): iterable
    {
        yield 'succeeded' => ['succeeded', 0, 'ok'];
        yield 'failed pre-switch' => ['failed_pre_switch', 30, 'deploy_failed'];
        yield 'interrupted pre-switch' => ['failed_pre_switch', 143, 'interrupted'];
        yield 'switch recovery required' => ['failed_switch_recovery_required', 32, 'switch_recovery_required'];
        yield 'post-switch rollback succeeded' => ['failed_post_switch_rollback_succeeded', 30, 'deploy_failed'];
        yield 'post-switch rollback failed' => ['failed_post_switch_rollback_failed', 31, 'rollback_failed'];
        yield 'manual rollback failed' => ['manual_recovery_required', 31, 'rollback_failed'];
        yield 'manual contract invalid' => ['manual_recovery_required', 70, 'contract_invalid'];
        yield 'manual interrupted' => ['manual_recovery_required', 143, 'interrupted'];
    }

    public function testCliConflictUsesOnlyStableStateConflictPair(): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'reconcile',
            'disposition' => 'rejected',
            'state' => null,
            'result_exit_code' => 75,
            'result_reason' => 'state_conflict',
        ];

        DeploymentHostRunnerContractV1::validateResponse($response);

        self::assertSame(75, DeploymentHostRunnerContractV1::cliExitCode($response));
    }

    public function testCliContractInvalidRejectionUsesStableInvalidPair(): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'deploy',
            'disposition' => 'rejected',
            'state' => null,
            'result_exit_code' => 70,
            'result_reason' => 'contract_invalid',
        ];

        DeploymentHostRunnerContractV1::validateResponse($response);

        self::assertSame(70, DeploymentHostRunnerContractV1::cliExitCode($response));
    }

    #[DataProvider('invalidRejectedResponseIdentityProvider')]
    public function testCliRejectionRequiresAValidatedIdentity(array $changes): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'deploy',
            'disposition' => 'rejected',
            'state' => null,
            'result_exit_code' => 70,
            'result_reason' => 'contract_invalid',
        ];
        foreach ($changes as $key => $value) {
            $response[$key] = $value;
        }

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateResponse($response);
    }

    public static function invalidRejectedResponseIdentityProvider(): iterable
    {
        yield 'missing trusted run identity' => [['run_id' => null]];
        yield 'malformed run identity' => [['run_id' => 'not-a-run-id']];
        yield 'missing trusted intent identity' => [['intent_sha256' => null]];
        yield 'malformed intent identity' => [['intent_sha256' => 'not-an-intent-hash']];
    }

    #[DataProvider('rejectedResponseProvider')]
    public function testCliRejectedResponseCannotClaimAState(int $exitCode, string $reason): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'deploy',
            'disposition' => 'rejected',
            'state' => 'planned',
            'result_exit_code' => $exitCode,
            'result_reason' => $reason,
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateResponse($response);
    }

    public static function rejectedResponseProvider(): iterable
    {
        yield 'contract invalid' => [70, 'contract_invalid'];
        yield 'state conflict' => [75, 'state_conflict'];
    }

    public function testCliResponseRejectsStateExitMismatchAndUnknownProgressState(): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'reconcile',
            'disposition' => 'terminal',
            'state' => 'failed_pre_switch',
            'result_exit_code' => 31,
            'result_reason' => 'rollback_failed',
        ];

        try {
            DeploymentHostRunnerContractV1::validateResponse($response);
            self::fail('A terminal state/exit mismatch was accepted.');
        } catch (RuntimeException) {
        }

        $response['disposition'] = 'accepted';
        $response['state'] = 'retrying';
        $response['result_exit_code'] = 0;
        $response['result_reason'] = 'ok';

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateResponse($response);
    }

    #[DataProvider('impossibleResponseProvider')]
    public function testCliResponseRejectsActionImpossibleDispositionOrState(array $changes): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'deploy',
            'disposition' => 'attach_pre_deploy',
            'state' => 'artifact_verified',
            'result_exit_code' => 0,
            'result_reason' => 'ok',
        ];
        foreach ($changes as $key => $value) {
            $response[$key] = $value;
        }

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateResponse($response);
    }

    public static function impossibleResponseProvider(): iterable
    {
        yield 'recovery cannot attach before deploy' => [
            ['action' => 'recovery', 'disposition' => 'attach_pre_deploy', 'state' => 'planned'],
        ];
        yield 'reconcile cannot accept new work' => [['action' => 'reconcile', 'disposition' => 'accepted']];
        yield 'succeeded cannot be nonterminal' => [['disposition' => 'accepted', 'state' => 'succeeded']];
        yield 'pre-deploy attach cannot claim deploy reservation' => [
            ['disposition' => 'attach_pre_deploy', 'state' => 'deploy_running'],
        ];
        yield 'observe-only attach requires reserved work' => [
            ['disposition' => 'attach_observe_only', 'state' => 'artifact_verified'],
        ];
        yield 'recovery acceptance requires post gates' => [
            ['action' => 'recovery', 'disposition' => 'accepted', 'state' => 'artifact_verified'],
        ];
        yield 'post-gates acceptance cannot claim rollback reservation' => [
            ['action' => 'post-gates', 'disposition' => 'accepted', 'state' => 'rollback_running'],
        ];
        yield 'post-gates replay cannot claim deploy reservation' => [
            ['action' => 'post-gates', 'disposition' => 'attach_observe_only', 'state' => 'deploy_running'],
        ];
    }

    public function testPassingPostGateSubmissionMayReturnATerminalResponse(): void
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'post-gates',
            'disposition' => 'terminal',
            'state' => 'succeeded',
            'result_exit_code' => 0,
            'result_reason' => 'ok',
        ];

        DeploymentHostRunnerContractV1::validateResponse($response);
        self::assertSame(0, DeploymentHostRunnerContractV1::cliExitCode($response));
    }

    #[DataProvider('validNonterminalResponseProvider')]
    public function testCliResponseAcceptsOnlyDefinedNonterminalActionCombinations(
        string $action,
        string $disposition,
        string $state,
    ): void {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => $action,
            'disposition' => $disposition,
            'state' => $state,
            'result_exit_code' => 0,
            'result_reason' => 'ok',
        ];

        DeploymentHostRunnerContractV1::validateResponse($response);
        self::assertSame(0, DeploymentHostRunnerContractV1::cliExitCode($response));
    }

    public static function validNonterminalResponseProvider(): iterable
    {
        yield 'new deploy accepted after reservation' => ['deploy', 'accepted', 'deploy_running'];
        yield 'deploy attaches before reservation' => ['deploy', 'attach_pre_deploy', 'artifact_verified'];
        yield 'deploy observes reservation' => ['deploy', 'attach_observe_only', 'deploy_running'];
        yield 'failed post gates authorize recovery' => ['post-gates', 'accepted', 'post_gates_running'];
        yield 'failed post gates replay attaches' => ['post-gates', 'attach_observe_only', 'post_gates_running'];
        yield 'rollback post gates replay attaches' => ['post-gates', 'attach_observe_only', 'rollback_running'];
        yield 'recovery accepted after reservation' => ['recovery', 'accepted', 'rollback_running'];
        yield 'recovery observes reservation' => ['recovery', 'attach_observe_only', 'rollback_running'];
        yield 'reconcile reports pre-deploy prefix' => ['reconcile', 'attach_pre_deploy', 'artifact_verified'];
        yield 'reconcile observes active work' => ['reconcile', 'attach_observe_only', 'post_gates_running'];
    }

    public function testCanonicalPositiveFixturesRemainExact(): void
    {
        $root = dirname(__DIR__, 2) . '/Fixtures/deployment-host-runner-v1';
        $deploy = (string) file_get_contents($root . '/deploy-request.json');
        $recovery = (string) file_get_contents($root . '/recovery-request.json');
        $state = (string) file_get_contents($root . '/state.json');
        $operator = (string) file_get_contents($root . '/operator-event.json');
        $active = (string) file_get_contents($root . '/active-run.json');
        $response = (string) file_get_contents($root . '/terminal-response.json');
        $execution = (string) file_get_contents($root . '/execution-input.json');
        $postGates = (string) file_get_contents($root . '/post-gate-report.json');

        $decodedDeploy = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $decodedRecovery = DeploymentHostRunnerContractV1::decodeRecoveryRequest($recovery);
        $decodedState = DeploymentHostRunnerContractV1::decodeState($state);
        $decodedOperator = DeploymentHostRunnerContractV1::decodeOperatorEvent($operator);
        $decodedActive = DeploymentHostRunnerContractV1::decodeActiveRun($active);
        $decodedResponse = DeploymentHostRunnerContractV1::decodeResponse($response);
        $decodedExecution = DeploymentHostRunnerContractV1::decodeExecutionInput($execution);
        $decodedPostGates = DeploymentHostRunnerContractV1::decodePostGateReport($postGates);

        foreach (
            [
                $decodedRecovery,
                $decodedState,
                $decodedOperator,
                $decodedActive,
                $decodedResponse,
                $decodedExecution,
                $decodedPostGates,
            ]
            as $fixture
        ) {
            self::assertSame($decodedDeploy['run_id'], $fixture['run_id']);
            self::assertSame($decodedDeploy['intent_sha256'], $fixture['intent_sha256']);
        }
        self::assertSame($decodedState['run_id'], $decodedActive['run_id']);
        self::assertSame($decodedState['intent_sha256'], $decodedActive['intent_sha256']);
        self::assertSame($decodedState['sequence'], $decodedActive['sequence']);
        self::assertSame($decodedState['events_sha256'], $decodedActive['events_sha256']);
        self::assertSame(8, count(glob($root . '/*.json') ?: []));
    }

    public function testPathsAndInternalCliContractAreDeterministic(): void
    {
        self::assertSame(
            '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock',
            DeploymentHostRunnerContractV1::GLOBAL_LOCK_PATH,
        );
        self::assertSame(
            '/var/lib/fh-deploy-orchestrator/runs/' . self::RUN_ID . '/run.lock',
            DeploymentHostRunnerContractV1::runLockPath(self::RUN_ID),
        );
        self::assertSame(
            '/var/lib/fh-deploy-orchestrator/active-run.json',
            DeploymentHostRunnerContractV1::activeRunPath(),
        );

        $cli = DeploymentHostRunnerContractV1::cliContract();
        self::assertSame(64, $cli['usage_exit']);
        self::assertSame(70, $cli['invalid_exit']);
        self::assertSame(75, $cli['conflict_exit']);
        self::assertSame(0, $cli['terminal_attach_exit']);
        self::assertSame(
            ['--action=deploy', '--request-file=ABSOLUTE_PATH', '--execution-input-file=ABSOLUTE_PATH'],
            $cli['deploy'],
        );
        self::assertSame(
            ['--action=post-gates', '--request-file=ABSOLUTE_PATH', '--report-file=ABSOLUTE_PATH'],
            $cli['post_gates'],
        );
        self::assertSame(
            ['--action=recovery', '--request-file=ABSOLUTE_PATH', '--execution-input-file=ABSOLUTE_PATH'],
            $cli['recovery'],
        );
        self::assertSame(['--action=reconcile', '--run-id=UUIDV4', '--intent-sha256=SHA256'], $cli['reconcile']);
    }

    public function testDeployAttachmentIsDerivedFromPersistedJournal(): void
    {
        $lines = $this->runThrough('artifact_verified');

        self::assertSame(
            'attach_pre_deploy',
            DeploymentHostRunnerContractV1::deployAttachmentDisposition($lines, $this->deployRequest()),
        );

        $lines = $this->runThrough('deploy_running');
        self::assertSame(
            'attach_observe_only',
            DeploymentHostRunnerContractV1::deployAttachmentDisposition($lines, $this->deployRequest()),
        );
    }

    public function testRecoveryRequestIsAcceptedOnlyAfterPostGatesAndNeverReservesTwice(): void
    {
        $request = $this->recoveryRequest();
        $postGateLines = $this->runThrough('post_gates_running');
        $postGateState = $this->recoveryAdmissionState($postGateLines, 'post_gates_running');
        $reportBytes = $this->failedDeployPostGateReportBytes();

        self::assertSame(
            'accepted',
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
                $postGateLines,
                $request,
                $postGateState,
                null,
                $reportBytes,
            ),
        );

        $rollback = $postGateLines;
        $rollback[] = $this->transition($rollback, 'rollback_running');
        $rollbackState = $this->recoveryAdmissionState($rollback, 'rollback_running');
        self::assertSame(
            'attach_observe_only',
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
                $rollback,
                $request,
                $rollbackState,
                null,
                $reportBytes,
            ),
        );

        foreach ([null, "{}\n"] as $invalidReportBytes) {
            try {
                DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
                    $postGateLines,
                    $request,
                    $postGateState,
                    null,
                    $invalidReportBytes,
                );
                self::fail('Recovery accepted a missing or malformed pinned deploy report.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $changedReport = $this->postGateReport(false, 'deploy');
        $changedReport['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $changedReport['deploy_receipt_sha256'] = $this->receiptSha256('succeeded');
        $changedReport['captured_at_utc'] = '2026-08-11T13:06:00Z';
        try {
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
                $postGateLines,
                $request,
                $postGateState,
                null,
                DeploymentHostRunnerContractV1::encodePostGateReport($changedReport),
            );
            self::fail('Recovery accepted changed report bytes after the first submission.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $notSubmitted = $postGateState;
        $notSubmitted['post_gates'] = $this->state()['post_gates'];
        try {
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
                $postGateLines,
                $request,
                $notSubmitted,
                null,
                $reportBytes,
            );
            self::fail('Recovery was accepted before a failed deploy post-gate submission.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $passed = $postGateState;
        $passed['post_gates'] = $this->submittedPostGates('passed');
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
            $postGateLines,
            $request,
            $passed,
            null,
            $reportBytes,
        );
    }

    public function testTerminalAttachmentRequiresMatchingDurableStateAndEvidence(): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->succeededEvidence($lines)) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $reportBytes = $this->passedDeployPostGateReportBytes();
        $unitBundles = $this->deployReconciliationBytes($state);

        self::assertSame(
            'terminal',
            DeploymentHostRunnerContractV1::deployAttachmentDisposition(
                $lines,
                $this->deployRequest(),
                $state,
                $evidenceBytes,
                $reportBytes,
                null,
                $unitBundles,
            ),
        );
        self::assertSame(
            'terminal',
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
                $lines,
                $this->recoveryRequest(),
                $state,
                $evidenceBytes,
                $reportBytes,
                null,
                $unitBundles,
            ),
        );
    }

    #[DataProvider('terminalAttachmentWithoutCompleteBundleProvider')]
    public function testTerminalAttachmentRejectsAnIncompleteBundle(
        string $action,
        bool $includeState,
        bool $includeEvidence,
    ): void {
        $lines = $this->runThrough('succeeded');
        $state = $includeState ? $this->terminalState('succeeded', 0, 'ok', 'succeeded') : null;
        $evidenceBytes = $includeEvidence ? "{}\n" : null;
        $request = $action === 'deploy' ? $this->deployRequest() : $this->recoveryRequest();

        $this->expectException(RuntimeException::class);
        if ($action === 'deploy') {
            DeploymentHostRunnerContractV1::deployAttachmentDisposition($lines, $request, $state, $evidenceBytes);
        } else {
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition($lines, $request, $state, $evidenceBytes);
        }
    }

    public static function terminalAttachmentWithoutCompleteBundleProvider(): iterable
    {
        yield 'deploy missing state and evidence' => ['deploy', false, false];
        yield 'deploy missing evidence' => ['deploy', true, false];
        yield 'deploy missing state' => ['deploy', false, true];
        yield 'recovery missing state and evidence' => ['recovery', false, false];
        yield 'recovery missing evidence' => ['recovery', true, false];
        yield 'recovery missing state' => ['recovery', false, true];
    }

    #[DataProvider('terminalAttachmentActionProvider')]
    public function testTerminalAttachmentRejectsACompleteMismatchedBundle(string $action): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->succeededEvidence($lines)) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = self::SHA;
        $request = $action === 'deploy' ? $this->deployRequest() : $this->recoveryRequest();

        $this->expectException(RuntimeException::class);
        if ($action === 'deploy') {
            DeploymentHostRunnerContractV1::deployAttachmentDisposition($lines, $request, $state, $evidenceBytes);
        } else {
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition($lines, $request, $state, $evidenceBytes);
        }
    }

    public static function terminalAttachmentActionProvider(): iterable
    {
        yield 'deploy' => ['deploy'];
        yield 'recovery' => ['recovery'];
    }

    #[DataProvider('nonterminalAttachmentProvider')]
    public function testNonterminalAttachmentRejectsATerminalBundle(string $action, string $stateName): void
    {
        $lines = $this->runThrough($stateName);
        $request = $action === 'deploy' ? $this->deployRequest() : $this->recoveryRequest();

        $this->expectException(RuntimeException::class);
        if ($action === 'deploy') {
            DeploymentHostRunnerContractV1::deployAttachmentDisposition($lines, $request, $this->state(), "{}\n");
        } else {
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition($lines, $request, $this->state(), "{}\n");
        }
    }

    public static function nonterminalAttachmentProvider(): iterable
    {
        yield 'deploy pre-reservation' => ['deploy', 'artifact_verified'];
        yield 'recovery before rollback reservation' => ['recovery', 'post_gates_running'];
    }

    #[DataProvider('invalidActiveRunProvider')]
    public function testActiveRunClaimRejectsMissingExtraOrMistypedFields(array $claim): void
    {
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateActiveRun($claim);
    }

    public static function invalidActiveRunProvider(): iterable
    {
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'state' => 'deploy_running',
            'sequence' => 11,
            'events_sha256' => self::SHA,
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        $missing = $claim;
        unset($missing['sequence']);
        yield 'missing sequence' => [$missing];

        yield 'extra key' => [$claim + ['detail' => '/secret/path']];

        $mistypedSequence = $claim;
        $mistypedSequence['sequence'] = '11';
        yield 'mistyped sequence' => [$mistypedSequence];

        $mistypedHash = $claim;
        $mistypedHash['events_sha256'] = 123;
        yield 'mistyped events hash' => [$mistypedHash];

        $invalidTimestamp = $claim;
        $invalidTimestamp['claimed_at_utc'] = '2026-08-11T15:00:00+02:00';
        yield 'non-UTC timestamp' => [$invalidTimestamp];
    }

    public function testDurableActiveRunBlocksAnotherRunAndAllowsExactReconcile(): void
    {
        $lines = $this->runThrough('deploy_running');
        $events = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['events_sha256'] = hash('sha256', $events);
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => count($lines),
            'events_sha256' => hash('sha256', $events),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        self::assertSame(
            'attach_observe_only',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                null,
                self::RUN_ID,
                $this->deployRequest()['intent_sha256'],
            ),
        );

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $events,
            null,
            '228f6f52-4c87-4d4e-8b19-6a66e6e1af25',
            $this->deployRequest()['intent_sha256'],
        );
    }

    public function testDurableActiveRunAllowsReconcileAfterNonterminalStateAdvances(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $events = implode("\n", $lines) . "\n";
        $claimLines = array_slice($lines, 0, -1);
        $claimEvents = implode("\n", $claimLines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['state'] = 'post_gates_running';
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['active_action'] = 'none';
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $state['deploy']['observed_exit_code'] = 0;
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => count($claimLines),
            'events_sha256' => hash('sha256', $claimEvents),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        self::assertSame(
            'attach_observe_only',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                null,
                self::RUN_ID,
                $state['intent_sha256'],
            ),
        );
    }

    public function testRollbackRunningActiveClaimRemainsObserveOnly(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, DeploymentContractV1::ROLLBACK_RESERVATION_STATE);
        $events = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['state'] = DeploymentContractV1::ROLLBACK_RESERVATION_STATE;
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['active_action'] = 'rollback';
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $state['deploy']['observed_exit_code'] = 0;
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, $state['intent_sha256']),
            'unit_launch_sha256' => self::SHA,
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => str_repeat('d', 32),
            'unit_missing_observed_boot_id' => null,
            'unit_state' => 'running',
            'observed_exit_code' => null,
            'verdict' => 'unknown',
        ];
        $state['post_gates'] = $this->submittedPostGates('failed');
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => DeploymentContractV1::ROLLBACK_RESERVATION_STATE,
            'sequence' => count($lines),
            'events_sha256' => hash('sha256', $events),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        self::assertSame(
            'attach_observe_only',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                null,
                self::RUN_ID,
                $state['intent_sha256'],
            ),
        );
    }

    public function testStateCacheMustMatchExactAuthoritativeJournalBytes(): void
    {
        $lines = $this->runThrough('deploy_running');
        $events = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['events_sha256'] = hash('sha256', $events);
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );

        self::assertSame('current', DeploymentHostRunnerContractV1::stateCacheDisposition($state, $events));

        $state['sequence']--;
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::stateCacheDisposition($state, $events);
    }

    #[DataProvider('laterNonterminalDeployOutcomeProvider')]
    public function testLaterNonterminalStateRequiresCompletedSuccessfulDeploy(
        string $stateName,
        string $unitState,
        ?int $observedExitCode,
        ?string $receiptSha256,
    ): void {
        $lines = $this->runThrough('post_gates_running');
        if ($stateName === DeploymentContractV1::ROLLBACK_RESERVATION_STATE) {
            $lines[] = $this->transition($lines, DeploymentContractV1::ROLLBACK_RESERVATION_STATE);
        }
        $events = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $launch = $this->systemdLaunch();
        $launchSha = DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch));
        $state['state'] = $stateName;
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['active_action'] = $stateName === DeploymentContractV1::ROLLBACK_RESERVATION_STATE ? 'rollback' : 'none';
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $state['deploy']['unit_state'] = $unitState;
        $state['deploy']['observed_exit_code'] = $observedExitCode;
        $state['deploy']['receipt_sha256'] = $receiptSha256;
        if ($stateName === DeploymentContractV1::ROLLBACK_RESERVATION_STATE) {
            $state['post_gates'] = $this->submittedPostGates('failed');
            $state['rollback'] = [
                'request_sha256' => self::SHA,
                'execution_input_sha256' => self::SHA,
                'invocation_count' => 1,
                'unit_name' => DeploymentHostRunnerContractV1::unitName(
                    'rollback',
                    self::RUN_ID,
                    $state['intent_sha256'],
                ),
                'unit_launch_sha256' => self::SHA,
                'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'unit_invocation_id' => str_repeat('d', 32),
                'unit_missing_observed_boot_id' => null,
                'unit_state' => 'running',
                'observed_exit_code' => null,
                'verdict' => 'unknown',
            ];
        }

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::stateCacheDisposition($state, $events);
    }

    public static function laterNonterminalDeployOutcomeProvider(): iterable
    {
        foreach (['post_gates_running', DeploymentContractV1::ROLLBACK_RESERVATION_STATE] as $state) {
            yield $state . ' with failed deploy exit' => [$state, 'exited', 30, self::SHA];
            yield $state . ' with live deploy unit' => [$state, 'running', null, null];
        }
    }

    public function testActiveRunDocumentationFreezesSatisfiableReservationOrdering(): void
    {
        $documentation = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/deployment-run-v1.md');

        self::assertStringContainsString(
            '1. append and fsync the `deploy_running` or `rollback_running` journal reservation;',
            $documentation,
        );
        self::assertStringContainsString(
            '2. atomically persist and fsync `active-run.json` bound to that exact reserved journal prefix;',
            $documentation,
        );
        self::assertStringContainsString(
            '3. atomically persist and fsync the matching `state.json` cache;',
            $documentation,
        );
        self::assertStringContainsString('4. start the reserved unit exactly once.', $documentation);
    }

    public function testCompleteJournalCanProveARecoverablyStaleStateCache(): void
    {
        $lines = $this->runThrough('deploy_running');
        $prefixLines = array_slice($lines, 0, -1);
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['state'] = 'artifact_verified';
        $state['sequence'] = count($prefixLines);
        $state['events_sha256'] = hash('sha256', implode("\n", $prefixLines) . "\n");
        $state['active_action'] = 'none';
        $state['deploy']['execution_input_sha256'] = null;
        $state['deploy']['invocation_count'] = 0;
        $state['deploy']['unit_name'] = null;
        $state['deploy']['unit_launch_sha256'] = null;
        $state['deploy']['unit_manager_boot_id'] = null;
        $state['deploy']['unit_invocation_id'] = null;
        $state['deploy']['unit_missing_observed_boot_id'] = null;
        $state['deploy']['unit_state'] = 'not_created';
        $state['deploy']['observed_exit_code'] = null;
        $state['deploy']['receipt_sha256'] = null;

        self::assertSame(
            'stale_recoverable',
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, implode("\n", $lines) . "\n"),
        );
    }

    #[DataProvider('corruptJournalProvider')]
    public function testStateJournalRejectsCorruptOrPartialBytes(string $events): void
    {
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::stateCacheDisposition($this->state(), $events);
    }

    public static function corruptJournalProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'missing newline' => ['{}'];
        yield 'double newline' => ["{}\n\n"];
        yield 'nul byte' => ["{}\0\n"];
        yield 'oversized' => [str_repeat('x', 1_048_577)];
    }

    public function testManualRecoveryAlwaysPreservesDeployReservation(): void
    {
        $state = $this->state();
        $state['state'] = 'manual_recovery_required';
        $state['active_action'] = 'none';
        $state['deploy']['execution_input_sha256'] = null;
        $state['deploy']['invocation_count'] = 0;
        $state['deploy']['unit_name'] = null;
        $state['deploy']['unit_launch_sha256'] = null;
        $state['deploy']['unit_manager_boot_id'] = null;
        $state['deploy']['unit_invocation_id'] = null;
        $state['deploy']['unit_missing_observed_boot_id'] = null;
        $state['deploy']['unit_state'] = 'not_created';
        $state['deploy']['observed_exit_code'] = null;
        $state['deploy']['receipt_sha256'] = null;
        $state['evidence_sha256'] = self::SHA;
        $state['terminal'] = [
            'state' => 'manual_recovery_required',
            'exit_code' => 70,
            'reason' => 'contract_invalid',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
    }

    public function testTerminalJournalCannotClearAClaimBeforeTerminalStateAndEvidenceAreDurable(): void
    {
        $lines = $this->runThrough('deploy_running');
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:11Z',
            'previous_state' => $previous['state'],
            'state' => 'failed_pre_switch',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 30,
            'reason' => 'deploy_failed',
        ]);
        $events = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['events_sha256'] = hash('sha256', implode("\n", array_slice($lines, 0, -1)) . "\n");
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => $state['sequence'],
            'events_sha256' => $state['events_sha256'],
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $events,
            null,
            self::RUN_ID,
            $state['intent_sha256'],
        );
    }

    public function testTerminalStateCacheMustMatchJournalResult(): void
    {
        $lines = $this->failedPreSwitchLines();
        $events = implode("\n", $lines) . "\n";
        $state = $this->terminalState('failed_pre_switch', 143, 'interrupted', 'interrupted_pre_switch');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->failedPreSwitchEvidence($lines)) . "\n";
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('journal result');
        DeploymentHostRunnerContractV1::terminalStateCacheDisposition($state, $events, $evidenceBytes);
    }

    public function testTerminalStateCacheRejectsDeployOutcomeThatContradictsEvidence(): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->succeededEvidence($lines)) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $unitBundles = $this->deployReconciliationBytes($state);
        $state['deploy']['observed_exit_code'] = 30;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deploy post-gates');
        DeploymentHostRunnerContractV1::terminalStateCacheDisposition($state, $events, $evidenceBytes);
    }

    public function testTerminalStateCacheRejectsReceiptHashThatDoesNotBindDeployEvidence(): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->succeededEvidence($lines)) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $state['deploy']['receipt_sha256'] = self::SHA;
        $report = $this->postGateReport(true, 'deploy');
        $report['intent_sha256'] = $state['intent_sha256'];
        $report['deploy_receipt_sha256'] = self::SHA;
        $reportBytes = DeploymentHostRunnerContractV1::encodePostGateReport($report);
        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $reportBytes);
        $unitBundles = $this->deployReconciliationBytes($state);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('receipt hash');
        DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
            $state,
            $events,
            $evidenceBytes,
            $reportBytes,
            null,
            $unitBundles,
        );
    }

    public function testTerminalStateCacheRejectsRollbackVerdictThatContradictsEvidence(): void
    {
        $lines = $this->rollbackSucceededLines();
        $events = implode("\n", $lines) . "\n";
        $evidence = $this->rollbackSucceededEvidence($lines);
        $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";
        $state = $this->terminalState('failed_post_switch_rollback_succeeded', 30, 'deploy_failed', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $state['deploy']['observed_exit_code'] = 0;
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, $state['intent_sha256']),
            'unit_launch_sha256' => self::SHA,
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => str_repeat('d', 32),
            'unit_missing_observed_boot_id' => null,
            'unit_state' => 'exited',
            'observed_exit_code' => 1,
            'verdict' => 'failed',
        ];
        $state['post_gates'] = $this->submittedPostGates('failed', 'passed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('post-gates');
        DeploymentHostRunnerContractV1::terminalStateCacheDisposition($state, $events, $evidenceBytes);
    }

    public function testActiveRunClaimBindsAProvenJournalPrefix(): void
    {
        $lines = $this->runThrough('deploy_running');
        $events = implode("\n", $lines) . "\n";
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => count($lines),
            'events_sha256' => hash('sha256', $events),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        DeploymentHostRunnerContractV1::validateActiveRun($claim);
    }

    public function testTerminalActiveClaimClearsOnlyWithMatchingStateEvidenceAndCandidate(): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->succeededEvidence($lines)) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $reportBytes = $this->passedDeployPostGateReportBytes();
        $unitBundles = $this->deployReconciliationBytes($state);
        $claimLines = array_slice($lines, 0, -1);
        $claimEvents = implode("\n", $claimLines) . "\n";
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => 'post_gates_running',
            'sequence' => count($claimLines),
            'events_sha256' => hash('sha256', $claimEvents),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        self::assertSame(
            'refresh_terminal_claim',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                $evidenceBytes,
                self::RUN_ID,
                $state['intent_sha256'],
                $reportBytes,
                null,
                $unitBundles,
            ),
        );

        $claim['state'] = 'succeeded';
        $claim['sequence'] = count($lines);
        $claim['events_sha256'] = hash('sha256', $events);
        self::assertSame(
            'clear_terminal',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                $evidenceBytes,
                self::RUN_ID,
                $state['intent_sha256'],
                $reportBytes,
                null,
                $unitBundles,
            ),
        );

        try {
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                $evidenceBytes,
                '228f6f52-4c87-4d4e-8b19-6a66e6e1af25',
                $state['intent_sha256'],
                $reportBytes,
                null,
                $unitBundles,
            );
            self::fail('A mismatched candidate cleared the durable active-run claim.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $state['evidence_sha256'] = self::SHA;
        try {
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                $evidenceBytes,
                self::RUN_ID,
                $state['intent_sha256'],
                $reportBytes,
                null,
                $unitBundles,
            );
            self::fail('Mismatched evidence bytes cleared the durable active-run claim.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $claim['events_sha256'] = self::SHA;
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $events,
            $evidenceBytes,
            self::RUN_ID,
            $state['intent_sha256'],
            $reportBytes,
            null,
            $unitBundles,
        );
    }

    public function testTerminalClearanceRequiresExactPinnedReportsAndUnitObservationBytes(): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->succeededEvidence($lines)) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $reportBytes = $this->passedDeployPostGateReportBytes();
        $bundles = $this->deployReconciliationBytes($state);

        self::assertSame(
            'current',
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $state,
                $events,
                $evidenceBytes,
                $reportBytes,
                null,
                $bundles,
            ),
        );

        $rollbackInput = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'action' => 'rollback',
            'parameters' => ['release_id' => 'ea_20260811'],
        ];
        $rollbackLaunch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $rollbackInput,
            $this->recoveryRequest(),
            $this->deployRequest(),
            "#!/bin/bash\n",
            static fn(): string => str_repeat("\xdd", 32),
        );
        $wrongActionBundles = $bundles;
        $wrongActionBundles['deploy']['launch'] = DeploymentHostRunnerContractV1::encodeFile($rollbackLaunch);

        $mutatedObservation = $bundles;
        $mutatedObservation['deploy']['observation'] = str_replace(
            'Restart=no',
            'Restart=always',
            $mutatedObservation['deploy']['observation'],
        );
        $cases = [
            'missing report' => [null, $bundles],
            'changed report' => [$reportBytes . ' ', $bundles],
            'missing unit bundle' => [$reportBytes, []],
            'changed observation' => [$reportBytes, $mutatedObservation],
            'wrong action bundle key' => [$reportBytes, $wrongActionBundles],
        ];
        foreach ($cases as $label => [$candidateReport, $candidateBundles]) {
            try {
                DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                    $state,
                    $events,
                    $evidenceBytes,
                    $candidateReport,
                    null,
                    $candidateBundles,
                );
                self::fail($label . ' was accepted for terminal clearance.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDedicatedRollbackTerminalRequiresDistinctExactActionBundles(): void
    {
        $lines = $this->rollbackSucceededLines();
        $events = implode("\n", $lines) . "\n";
        $evidence = $this->rollbackSucceededEvidence($lines);
        $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";
        $state = $this->dedicatedRollbackTerminalState(true);
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $deployLaunch = $this->systemdLaunch();
        $rollbackLaunch = $this->rollbackSystemdLaunch();
        foreach (['request_sha256', 'execution_input_sha256', 'unit_name'] as $field) {
            $state['deploy'][$field] = $deployLaunch[$field];
            $state['rollback'][$field] = $rollbackLaunch[$field];
        }
        $state['deploy']['unit_launch_sha256'] = DeploymentHostRunnerContractV1::fileSha256(
            DeploymentHostRunnerContractV1::encodeFile($deployLaunch),
        );
        $state['rollback']['unit_launch_sha256'] = DeploymentHostRunnerContractV1::fileSha256(
            DeploymentHostRunnerContractV1::encodeFile($rollbackLaunch),
        );
        $deployReport = $this->postGateReport(false, 'deploy');
        $deployReport['intent_sha256'] = $state['intent_sha256'];
        $deployReport['deploy_receipt_sha256'] = $state['deploy']['receipt_sha256'];
        $deployReport['post_gates'] = $evidence['post_gates'];
        $deployReportBytes = DeploymentHostRunnerContractV1::encodePostGateReport($deployReport);
        $rollbackReport = $this->postGateReport(true, 'rollback');
        $rollbackReport['intent_sha256'] = $state['intent_sha256'];
        $rollbackReportBytes = DeploymentHostRunnerContractV1::encodePostGateReport($rollbackReport);
        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $deployReportBytes);
        $state['post_gates']['rollback_report_sha256'] = hash('sha256', $rollbackReportBytes);
        $bundles = [...$this->deployReconciliationBytes($state), ...$this->rollbackReconciliationBytes($state)];

        self::assertSame(
            'current',
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $state,
                $events,
                $evidenceBytes,
                $deployReportBytes,
                $rollbackReportBytes,
                $bundles,
            ),
        );

        $duplicatedRollback = ['deploy' => $bundles['rollback'], 'rollback' => $bundles['rollback']];
        $mutations = [
            'duplicate rollback under deploy key' => [$deployReportBytes, $rollbackReportBytes, $duplicatedRollback],
            'missing rollback report' => [$deployReportBytes, null, $bundles],
            'changed rollback report' => [$deployReportBytes, $rollbackReportBytes . ' ', $bundles],
            'missing rollback bundle' => [$deployReportBytes, $rollbackReportBytes, ['deploy' => $bundles['deploy']]],
        ];
        foreach (['launch', 'binding', 'observation'] as $field) {
            $changed = $bundles;
            $changed['rollback'][$field] .= ' ';
            $mutations['changed rollback ' . $field] = [$deployReportBytes, $rollbackReportBytes, $changed];
        }
        foreach ($mutations as $label => [$deployBytes, $rollbackBytes, $candidateBundles]) {
            try {
                DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                    $state,
                    $events,
                    $evidenceBytes,
                    $deployBytes,
                    $rollbackBytes,
                    $candidateBundles,
                );
                self::fail($label . ' was accepted for dedicated rollback terminal clearance.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDirectDeployRollbackReceiptsNeverRequireADedicatedRollbackBundle(): void
    {
        $cases = [
            ['failed_post_switch_rollback_succeeded', 30, 'deploy_failed', 'internal_rollback_succeeded', 'succeeded'],
            ['failed_post_switch_rollback_failed', 31, 'rollback_failed', 'rollback_failed_or_unverifiable', 'failed'],
        ];
        foreach ($cases as [$stateName, $exitCode, $reason, $receiptOutcome, $rollbackOutcome]) {
            $lines = $this->directDeployRollbackLines($stateName, $exitCode, $reason);
            $events = implode("\n", $lines) . "\n";
            $evidence = $this->directDeployRollbackEvidence($lines, $stateName, $exitCode, $reason, $rollbackOutcome);
            $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";
            $state = $this->terminalState($stateName, $exitCode, $reason, $receiptOutcome);
            $state['sequence'] = count($lines);
            $state['events_sha256'] = hash('sha256', $events);
            $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
            $bundles = $this->deployReconciliationBytes($state);

            self::assertSame(
                'current',
                DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                    $state,
                    $events,
                    $evidenceBytes,
                    null,
                    null,
                    $bundles,
                ),
                $receiptOutcome,
            );

            $extraRollback = $bundles;
            $extraRollback['rollback'] = $bundles['deploy'];
            foreach (
                [
                    'unexpected rollback report' => [$this->postGateReport(true, 'rollback'), $bundles],
                    'unexpected rollback bundle' => [null, $extraRollback],
                ]
                as $label => [$rollbackReport, $candidateBundles]
            ) {
                $rollbackBytes =
                    $rollbackReport === null
                        ? null
                        : DeploymentHostRunnerContractV1::encodePostGateReport($rollbackReport);
                try {
                    DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                        $state,
                        $events,
                        $evidenceBytes,
                        null,
                        $rollbackBytes,
                        $candidateBundles,
                    );
                    self::fail($receiptOutcome . ': ' . $label . ' was accepted.');
                } catch (RuntimeException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function testFailedDedicatedRollbackReportBindsTheTwoActionTerminalBundle(): void
    {
        $lines = $this->rollbackFailedLines();
        $events = implode("\n", $lines) . "\n";
        $evidence = $this->rollbackFailedEvidence($lines);
        $evidenceBytes = DeploymentContractV1::canonicalJson($evidence) . "\n";
        $state = $this->dedicatedRollbackTerminalState(false);
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $deployLaunch = $this->systemdLaunch();
        $rollbackLaunch = $this->rollbackSystemdLaunch();
        foreach (['request_sha256', 'execution_input_sha256', 'unit_name'] as $field) {
            $state['deploy'][$field] = $deployLaunch[$field];
            $state['rollback'][$field] = $rollbackLaunch[$field];
        }
        $state['deploy']['unit_launch_sha256'] = DeploymentHostRunnerContractV1::fileSha256(
            DeploymentHostRunnerContractV1::encodeFile($deployLaunch),
        );
        $state['rollback']['unit_launch_sha256'] = DeploymentHostRunnerContractV1::fileSha256(
            DeploymentHostRunnerContractV1::encodeFile($rollbackLaunch),
        );
        $deployReport = $this->postGateReport(false, 'deploy');
        $deployReport['intent_sha256'] = $state['intent_sha256'];
        $deployReport['deploy_receipt_sha256'] = $state['deploy']['receipt_sha256'];
        $deployReport['post_gates'] = $evidence['post_gates'];
        $deployReportBytes = DeploymentHostRunnerContractV1::encodePostGateReport($deployReport);
        $rollbackReport = $this->postGateReport(false, 'rollback');
        $rollbackReport['intent_sha256'] = $state['intent_sha256'];
        $rollbackReportBytes = DeploymentHostRunnerContractV1::encodePostGateReport($rollbackReport);
        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $deployReportBytes);
        $state['post_gates']['rollback_report_sha256'] = hash('sha256', $rollbackReportBytes);
        $bundles = [...$this->deployReconciliationBytes($state), ...$this->rollbackReconciliationBytes($state)];

        self::assertSame(
            'current',
            DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
                $state,
                $events,
                $evidenceBytes,
                $deployReportBytes,
                $rollbackReportBytes,
                $bundles,
            ),
        );
    }

    public function testFailedTerminalActiveClaimRefreshesThenClears(): void
    {
        $lines = $this->failedPreSwitchLines();
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->failedPreSwitchEvidence($lines)) . "\n";
        $state = $this->terminalState('failed_pre_switch', 30, 'deploy_failed', 'failed_pre_switch');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $unitBundles = $this->deployReconciliationBytes($state);
        $claimLines = array_slice($lines, 0, -1);
        $claimEvents = implode("\n", $claimLines) . "\n";
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => count($claimLines),
            'events_sha256' => hash('sha256', $claimEvents),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        self::assertSame(
            'refresh_terminal_claim',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                $evidenceBytes,
                self::RUN_ID,
                $state['intent_sha256'],
                null,
                null,
                $unitBundles,
            ),
        );

        $claim['state'] = 'failed_pre_switch';
        $claim['sequence'] = count($lines);
        $claim['events_sha256'] = hash('sha256', $events);
        self::assertSame(
            'clear_terminal',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                $evidenceBytes,
                self::RUN_ID,
                $state['intent_sha256'],
                null,
                null,
                $unitBundles,
            ),
        );
    }

    #[DataProvider('invalidTerminalEvidenceBytesProvider')]
    public function testTerminalStateCacheRejectsMalformedOrNoncanonicalEvidenceBytes(string $evidenceBytes): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok', 'succeeded');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::terminalStateCacheDisposition($state, $events, $evidenceBytes);
    }

    public static function invalidTerminalEvidenceBytesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ["{\n"];
        yield 'nul byte' => ["{}\0\n"];
        yield 'missing final newline' => ['{}'];
        yield 'oversized' => [str_repeat('x', 65_537)];
    }

    public function testRollbackReservationStateMatchesItsAuthoritativeJournal(): void
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, DeploymentContractV1::ROLLBACK_RESERVATION_STATE);
        $events = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['state'] = DeploymentContractV1::ROLLBACK_RESERVATION_STATE;
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['active_action'] = 'rollback';
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $state['deploy']['observed_exit_code'] = 0;
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, $state['intent_sha256']),
            'unit_launch_sha256' => self::SHA,
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => str_repeat('d', 32),
            'unit_missing_observed_boot_id' => null,
            'unit_state' => 'running',
            'observed_exit_code' => null,
            'verdict' => 'unknown',
        ];
        $state['post_gates'] = $this->submittedPostGates('failed');

        self::assertSame('current', DeploymentHostRunnerContractV1::stateCacheDisposition($state, $events));
    }

    public function testActiveRunClaimMustBindExactJournalPrefix(): void
    {
        $lines = $this->runThrough('deploy_running');
        $events = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['events_sha256'] = hash('sha256', $events);
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $state['intent_sha256'],
            'state' => 'deploy_running',
            'sequence' => count($lines),
            'events_sha256' => self::SHA,
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $events,
            null,
            self::RUN_ID,
            $state['intent_sha256'],
        );
    }

    /** @return array<string,mixed> */
    private function deployRequest(): array
    {
        return self::staticDeployRequest();
    }

    /** @return array<string,mixed> */
    private static function staticDeployRequest(): array
    {
        $intent = DeploymentContractV1::createIntentRecord(
            self::RUN_ID,
            '2026-08-11T13:00:00Z',
            str_repeat('c', 40),
            'ea_20260811',
            'normal',
        );

        return [
            'schema' => DeploymentHostRunnerContractV1::DEPLOY_REQUEST_SCHEMA,
            'run_id' => $intent['run_id'],
            'expected_commit' => $intent['expected_commit'],
            'release_id' => $intent['release_id'],
            'traffic_mode' => $intent['traffic_mode'],
            'dump_policy' => $intent['dump_policy'],
            'artifact_expectation' => $intent['artifact_expectation'],
            'intent_sha256' => $intent['intent_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private function deployExecutionInput(): array
    {
        $input = self::staticDeployExecutionInput();
        $input['intent_sha256'] = $this->deployRequest()['intent_sha256'];

        return $input;
    }

    /** @return array<string,mixed> */
    private static function staticDeployExecutionInput(): array
    {
        $file = static fn(string $path): array => ['path' => $path, 'sha256' => self::SHA];

        return [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'deploy',
            'parameters' => [
                'release_id' => 'ea_20260811',
                'renderer_deploy_mode' => 'host',
                'healthz_token' => $file('/etc/fh/healthz.token'),
                'zero_surprise_dump' => $file('/root/backups/predeploy.sql.gz'),
                'zero_surprise_predeploy_credentials' => $file('/etc/fh/predeploy.ini'),
                'zero_surprise_canary_credentials' => $file('/etc/fh/canary.ini'),
                'zero_surprise_incident_webhook' => $file('/etc/fh/incident.ini'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function postGateReport(bool $passed, string $subject): array
    {
        return [
            'schema' => DeploymentHostRunnerContractV1::POST_GATE_REPORT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'captured_at_utc' => '2026-08-11T13:05:00Z',
            'subject' => $subject,
            'deploy_receipt_sha256' => $subject === 'deploy' ? self::SHA : null,
            'post_gates' => [
                'status' => $passed ? 'passed' : 'failed',
                'kuma_healthy_count' => $passed ? 13 : 12,
                'kuma_total_count' => 13,
                'runtime_config_passed' => true,
                'services_passed' => true,
                'endpoints_passed' => true,
                'logs_passed' => $passed,
                'scanner_passed' => true,
                'dormant_clean_passed' => true,
                'passed' => $passed,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function unitBinding(string $state): array
    {
        return [
            'schema' => DeploymentHostRunnerContractV1::UNIT_BINDING_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'action' => 'deploy',
            'unit_name' => DeploymentHostRunnerContractV1::unitName('deploy', self::RUN_ID, self::INTENT_SHA),
            'unit_launch_sha256' => self::SHA,
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => $state === 'observed' ? str_repeat('d', 32) : null,
            'binding_state' => $state,
        ];
    }

    /** @param array<string,mixed> $launch @return array<string,mixed> */
    private function unitBindingForLaunch(array $launch, string $state): array
    {
        $binding = $this->unitBinding($state);
        $binding['intent_sha256'] = $launch['intent_sha256'];
        $binding['action'] = $launch['action'];
        $binding['unit_name'] = $launch['unit_name'];
        $binding['unit_launch_sha256'] = DeploymentHostRunnerContractV1::fileSha256(
            DeploymentHostRunnerContractV1::encodeFile($launch),
        );

        return $binding;
    }

    /** @return array<string,mixed> */
    private function systemdLaunch(): array
    {
        return DeploymentHostRunnerContractV1::createSystemdLaunch(
            $this->deployExecutionInput(),
            $this->deployRequest(),
            null,
            "#!/bin/bash\n",
            static fn(): string => str_repeat("\xee", 32),
        );
    }

    /** @param array<string,mixed> $launch @return array<string,mixed> */
    private function systemdObservation(array $launch, string $launchSha): array
    {
        return [
            'id' => $launch['unit_name'],
            'load_state' => 'loaded',
            'active_state' => 'failed',
            'sub_state' => 'failed',
            'result' => 'exit-code',
            'exec_main_code' => 1,
            'exec_main_status' => 1,
            'unit_invocation_id' => str_repeat('d', 32),
            'description' => DeploymentHostRunnerContractV1::unitProperties('deploy', $launchSha)['Description'],
            'properties' => DeploymentHostRunnerContractV1::observedUnitProperties('deploy', $launchSha),
        ];
    }

    /** @param array<string,mixed> $launch */
    private function systemctlShowBytes(array $launch): string
    {
        $launchSha = DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch));
        $properties = DeploymentHostRunnerContractV1::observedUnitProperties($launch['action'], $launchSha);
        $values = [
            'Id' => $launch['unit_name'],
            'LoadState' => 'loaded',
            'ActiveState' => 'failed',
            'SubState' => 'failed',
            'Result' => 'exit-code',
            'ExecMainCode' => '1',
            'ExecMainStatus' => '30',
            'InvocationID' => str_repeat('d', 32),
            'Description' => $properties['Description'],
            'Transient' => $properties['Transient'],
            'Type' => $properties['Type'],
            'RemainAfterExit' => $properties['RemainAfterExit'],
            'UMask' => $properties['UMask'],
            'KillMode' => $properties['KillMode'],
            'Restart' => $properties['Restart'],
            'RuntimeMaxUSec' => $properties['RuntimeMaxUSec'],
            'TimeoutStopUSec' => $properties['TimeoutStopUSec'],
            'StandardInput' => $properties['StandardInput'],
            'StandardOutput' => $properties['StandardOutput'],
            'StandardError' => $properties['StandardError'],
        ];

        return implode(
            "\n",
            array_map(
                static fn(string $key, string $value): string => $key . '=' . $value,
                array_keys($values),
                array_values($values),
            ),
        ) . "\n";
    }

    /** @param array<string,mixed> $state @return array<string,array<string,string>> */
    private function deployReconciliationBytes(array $state): array
    {
        $launch = $this->systemdLaunch();
        $launchSha = DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch));
        $binding = $this->unitBindingForLaunch($launch, 'observed');
        $observation = $this->systemctlShowBytes($launch);
        if ($state['deploy']['unit_state'] === 'exited') {
            $observation = str_replace(
                ['ActiveState=failed', 'SubState=failed', 'Result=exit-code', 'ExecMainStatus=30'],
                ['ActiveState=active', 'SubState=exited', 'Result=success', 'ExecMainStatus=0'],
                $observation,
            );
        } elseif ($state['deploy']['unit_state'] === 'failed') {
            $observation = str_replace(
                'ExecMainStatus=30',
                'ExecMainStatus=' . (string) $state['deploy']['observed_exit_code'],
                $observation,
            );
        } else {
            self::fail('Unsupported deploy terminal unit state in fixture');
        }

        return [
            'deploy' => [
                'launch' => DeploymentHostRunnerContractV1::encodeFile($launch),
                'binding' => DeploymentHostRunnerContractV1::encodeFile($binding),
                'observation' => DeploymentHostRunnerContractV1::encodeFile([
                    'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
                    'manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                    'systemctl_show' => $observation,
                ]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function rollbackSystemdLaunch(): array
    {
        return DeploymentHostRunnerContractV1::createSystemdLaunch(
            [
                'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
                'run_id' => self::RUN_ID,
                'intent_sha256' => $this->deployRequest()['intent_sha256'],
                'action' => 'rollback',
                'parameters' => ['release_id' => 'ea_20260811'],
            ],
            $this->recoveryRequest(),
            $this->deployRequest(),
            "#!/bin/bash\n",
            static fn(): string => str_repeat("\xdd", 32),
        );
    }

    /** @param array<string,mixed> $state @return array<string,array<string,string>> */
    private function rollbackReconciliationBytes(array $state): array
    {
        $launch = $this->rollbackSystemdLaunch();
        $observation = $this->systemctlShowBytes($launch);
        if ($state['rollback']['unit_state'] === 'exited') {
            $observation = str_replace(
                ['ActiveState=failed', 'SubState=failed', 'Result=exit-code', 'ExecMainStatus=30'],
                ['ActiveState=active', 'SubState=exited', 'Result=success', 'ExecMainStatus=0'],
                $observation,
            );
        } elseif ($state['rollback']['unit_state'] === 'failed') {
            $observation = str_replace(
                'ExecMainStatus=30',
                'ExecMainStatus=' . (string) $state['rollback']['observed_exit_code'],
                $observation,
            );
        } else {
            self::fail('Unsupported rollback terminal unit state in fixture');
        }

        return [
            'rollback' => [
                'launch' => DeploymentHostRunnerContractV1::encodeFile($launch),
                'binding' => DeploymentHostRunnerContractV1::encodeFile(
                    $this->unitBindingForLaunch($launch, 'observed'),
                ),
                'observation' => DeploymentHostRunnerContractV1::encodeFile([
                    'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
                    'manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                    'systemctl_show' => $observation,
                ]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function recoveryRequest(): array
    {
        return [
            'schema' => DeploymentHostRunnerContractV1::RECOVERY_REQUEST_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
        ];
    }

    /** @return list<string> */
    private function runThrough(string $target): array
    {
        $intent = DeploymentContractV1::createIntentRecord(
            self::RUN_ID,
            '2026-08-11T13:00:00Z',
            str_repeat('c', 40),
            'ea_20260811',
            'normal',
        );
        $lines = [DeploymentContractV1::canonicalJson($intent)];
        if ($target === 'planned') {
            return $lines;
        }
        foreach (array_slice(DeploymentContractV1::PROGRESS_STATES, 1) as $state) {
            $lines[] = $this->transition($lines, $state);
            if ($state === $target) {
                return $lines;
            }
        }

        self::fail('Unknown target state: ' . $target);
    }

    /** @param list<string> $lines */
    private function transition(array $lines, string $state): string
    {
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $count = in_array($state, ['deploy_running', 'post_gates_running', 'rollback_running', 'succeeded'], true)
            ? 1
            : 0;
        $record = [
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => sprintf('2026-08-11T13:00:%02dZ', count($lines)),
            'previous_state' => $previous['state'],
            'state' => $state,
            'deploy_invocation_count' => $count,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 0,
            'reason' => 'ok',
        ];

        return DeploymentContractV1::canonicalJson($record);
    }

    /** @return list<string> */
    private function failedPreSwitchLines(): array
    {
        $lines = $this->runThrough('deploy_running');
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:11Z',
            'previous_state' => $previous['state'],
            'state' => 'failed_pre_switch',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 30,
            'reason' => 'deploy_failed',
        ]);

        return $lines;
    }

    /** @return list<string> */
    private function rollbackSucceededLines(): array
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, DeploymentContractV1::ROLLBACK_RESERVATION_STATE);
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:13Z',
            'previous_state' => $previous['state'],
            'state' => 'failed_post_switch_rollback_succeeded',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 30,
            'reason' => 'deploy_failed',
        ]);

        return $lines;
    }

    /** @return list<string> */
    private function rollbackFailedLines(): array
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, DeploymentContractV1::ROLLBACK_RESERVATION_STATE);
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:13Z',
            'previous_state' => $previous['state'],
            'state' => 'failed_post_switch_rollback_failed',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => 31,
            'reason' => 'rollback_failed',
        ]);

        return $lines;
    }

    /** @return list<string> */
    private function directDeployRollbackLines(string $state, int $exitCode, string $reason): array
    {
        $lines = $this->runThrough('deploy_running');
        $previous = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-11T13:00:11Z',
            'previous_state' => $previous['state'],
            'state' => $state,
            'deploy_invocation_count' => 1,
            'intent_sha256' => $this->deployRequest()['intent_sha256'],
            'exit_code' => $exitCode,
            'reason' => $reason,
        ]);

        return $lines;
    }

    /** @return array<string,mixed> */
    private function terminalState(string $stateName, int $exitCode, string $reason, ?string $receiptOutcome): array
    {
        $state = $this->state();
        $launch = $this->systemdLaunch();
        $launchSha = DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch));
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['state'] = $stateName;
        $state['active_action'] = 'none';
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $state['deploy']['request_sha256'] = $launch['request_sha256'];
        $state['deploy']['execution_input_sha256'] = $launch['execution_input_sha256'];
        $state['deploy']['unit_launch_sha256'] = $launchSha;
        $state['deploy']['unit_manager_boot_id'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        $state['deploy']['unit_state'] = $exitCode === 0 ? 'exited' : 'failed';
        $state['deploy']['observed_exit_code'] = $exitCode;
        $state['deploy']['receipt_sha256'] = $receiptOutcome === null ? null : $this->receiptSha256($receiptOutcome);
        $state['evidence_sha256'] = self::SHA;
        $state['terminal'] = ['state' => $stateName, 'exit_code' => $exitCode, 'reason' => $reason];
        if ($stateName === 'succeeded') {
            $state['post_gates'] = $this->submittedPostGates('passed');
            $state['post_gates']['deploy_report_sha256'] = hash('sha256', $this->passedDeployPostGateReportBytes());
        }

        return $state;
    }

    /** @return array<string,mixed> */
    private function submittedPostGates(string $deployVerdict, string $rollbackVerdict = 'not_submitted'): array
    {
        return [
            'deploy_report_sha256' => self::SHA,
            'deploy_submission_count' => 1,
            'deploy_verdict' => $deployVerdict,
            'rollback_report_sha256' => $rollbackVerdict === 'not_submitted' ? null : self::SHA,
            'rollback_submission_count' => $rollbackVerdict === 'not_submitted' ? 0 : 1,
            'rollback_verdict' => $rollbackVerdict,
        ];
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function recoveryAdmissionState(array $lines, string $stateName): array
    {
        $events = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['state'] = $stateName;
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['active_action'] = $stateName === 'rollback_running' ? 'rollback' : 'none';
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['receipt_sha256'] = $this->receiptSha256('succeeded');
        $state['post_gates'] = $this->submittedPostGates('failed');
        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $this->failedDeployPostGateReportBytes());
        if ($stateName === 'rollback_running') {
            $state['rollback'] = [
                'request_sha256' => self::SHA,
                'execution_input_sha256' => self::SHA,
                'invocation_count' => 1,
                'unit_name' => DeploymentHostRunnerContractV1::unitName(
                    'rollback',
                    self::RUN_ID,
                    $state['intent_sha256'],
                ),
                'unit_launch_sha256' => self::SHA,
                'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'unit_invocation_id' => str_repeat('d', 32),
                'unit_missing_observed_boot_id' => null,
                'unit_state' => 'running',
                'observed_exit_code' => null,
                'verdict' => 'unknown',
            ];
        }

        return $state;
    }

    /** @return array<string,mixed> */
    private function dedicatedRollbackTerminalState(bool $rollbackPassed): array
    {
        $lines = $this->runThrough('post_gates_running');
        $lines[] = $this->transition($lines, 'rollback_running');
        $state = $this->recoveryAdmissionState($lines, 'rollback_running');
        $state['state'] = $rollbackPassed
            ? 'failed_post_switch_rollback_succeeded'
            : 'failed_post_switch_rollback_failed';
        $state['active_action'] = 'none';
        $state['rollback']['unit_state'] = 'exited';
        $state['rollback']['observed_exit_code'] = 0;
        $state['rollback']['verdict'] = $rollbackPassed ? 'succeeded' : 'failed';
        $report = $this->postGateReport($rollbackPassed, 'rollback');
        $report['intent_sha256'] = $state['intent_sha256'];
        $encoded = DeploymentHostRunnerContractV1::encodePostGateReport($report);
        $state['post_gates']['rollback_report_sha256'] = hash('sha256', $encoded);
        $state['post_gates']['rollback_submission_count'] = 1;
        $state['post_gates']['rollback_verdict'] = $rollbackPassed ? 'passed' : 'failed';
        $state['evidence_sha256'] = self::SHA;
        $state['terminal'] = [
            'state' => $state['state'],
            'exit_code' => $rollbackPassed ? 30 : 31,
            'reason' => $rollbackPassed ? 'deploy_failed' : 'rollback_failed',
        ];
        DeploymentHostRunnerContractV1::validateState($state);

        return $state;
    }

    private function failedDeployPostGateReportBytes(): string
    {
        $report = $this->postGateReport(false, 'deploy');
        $report['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $report['deploy_receipt_sha256'] = $this->receiptSha256('succeeded');

        return DeploymentHostRunnerContractV1::encodePostGateReport($report);
    }

    private function passedDeployPostGateReportBytes(): string
    {
        $report = $this->postGateReport(true, 'deploy');
        $report['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $report['deploy_receipt_sha256'] = $this->receiptSha256('succeeded');

        return DeploymentHostRunnerContractV1::encodePostGateReport($report);
    }

    private function receiptSha256(string $outcome): string
    {
        $exitCode = DeployResultV1::OUTCOME_EXIT_CODES[$outcome] ?? null;
        self::assertIsInt($exitCode);

        return hash('sha256', DeployResultV1::canonicalJson(DeployResultV1::create($outcome, $exitCode)));
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function succeededEvidence(array $lines): array
    {
        $intent = json_decode($lines[0], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($intent);
        $counts = array_fill_keys(DeploymentContractV1::TRAFFIC_COUNT_KEYS, 0);
        $counts['documented_health'] = 1;
        $counts['total'] = 1;
        $counts['lines_seen'] = 1;
        $counts['lines_in_window'] = 1;

        return [
            'schema' => DeploymentContractV1::EVIDENCE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $intent['intent_sha256'],
            'captured_at_utc' => '2026-08-11T13:00:13Z',
            'expected_commit' => [
                'expected' => str_repeat('c', 40),
                'observed' => str_repeat('c', 40),
                'verified' => true,
            ],
            'traffic_gate' => [
                'status' => 'passed',
                'report_sha256' => self::SHA,
                'schema' => 'traffic_gate.v1',
                'producer_sha256' => self::SHA,
                'policy_version' => 'traffic_gate_policy.v1',
                'catalog_version' => '2026-08-09.1',
                'purpose' => 'deploy',
                'mode' => 'normal',
                'window_start_epoch' => 1786453110,
                'window_end_epoch' => 1786453200,
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
            'rollback' => [
                'status' => 'not_invoked',
                'invocation_count' => 0,
                'mode' => 'not_applicable',
                'verified' => null,
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
                'status' => 'not_observed',
                'authoritative_sha256' => null,
                'run_id' => null,
                'total_ms' => null,
            ],
            'orchestrator_timing' => [
                'started_at_utc' => '2026-08-11T13:00:00Z',
                'finished_at_utc' => '2026-08-11T13:00:12Z',
                'wall_clock_ms' => 12_000,
            ],
            'result' => ['state' => 'succeeded', 'exit_code' => 0, 'reason' => 'ok'],
        ];
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function failedPreSwitchEvidence(array $lines): array
    {
        $evidence = $this->succeededEvidence($lines);
        $evidence['deploy'] = [
            'status' => 'failed',
            'invocation_count' => 1,
            'exit_code' => 30,
            'rollback_outcome' => 'not_run',
        ];
        $evidence['post_gates'] = array_map(static fn(mixed $_value): mixed => null, $evidence['post_gates']);
        $evidence['post_gates']['status'] = 'not_observed';
        $evidence['result'] = [
            'state' => 'failed_pre_switch',
            'exit_code' => 30,
            'reason' => 'deploy_failed',
        ];

        return $evidence;
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function rollbackSucceededEvidence(array $lines): array
    {
        $evidence = $this->succeededEvidence($lines);
        $evidence['captured_at_utc'] = '2026-08-11T13:00:15Z';
        $evidence['orchestrator_timing']['finished_at_utc'] = '2026-08-11T13:00:14Z';
        $evidence['orchestrator_timing']['wall_clock_ms'] = 14_000;
        $evidence['rollback'] = [
            'status' => 'succeeded',
            'invocation_count' => 1,
            'mode' => 'dedicated_post_gate_recovery',
            'verified' => true,
        ];
        $evidence['post_gates']['logs_passed'] = false;
        $evidence['post_gates']['passed'] = false;
        $evidence['post_gates']['status'] = 'failed';
        $evidence['result'] = [
            'state' => 'failed_post_switch_rollback_succeeded',
            'exit_code' => 30,
            'reason' => 'deploy_failed',
        ];

        return $evidence;
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function rollbackFailedEvidence(array $lines): array
    {
        $evidence = $this->rollbackSucceededEvidence($lines);
        $evidence['rollback']['status'] = 'failed';
        $evidence['rollback']['verified'] = false;
        $evidence['result'] = [
            'state' => 'failed_post_switch_rollback_failed',
            'exit_code' => 31,
            'reason' => 'rollback_failed',
        ];

        return $evidence;
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function directDeployRollbackEvidence(
        array $lines,
        string $state,
        int $exitCode,
        string $reason,
        string $rollbackOutcome,
    ): array {
        $evidence = $this->succeededEvidence($lines);
        $evidence['deploy'] = [
            'status' => 'failed',
            'invocation_count' => 1,
            'exit_code' => $exitCode,
            'rollback_outcome' => $rollbackOutcome,
        ];
        $evidence['post_gates'] = array_map(static fn(mixed $_value): mixed => null, $evidence['post_gates']);
        $evidence['post_gates']['status'] = 'not_observed';
        $evidence['result'] = ['state' => $state, 'exit_code' => $exitCode, 'reason' => $reason];

        return $evidence;
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        return [
            'schema' => DeploymentHostRunnerContractV1::STATE_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'state' => 'deploy_running',
            'sequence' => 11,
            'events_sha256' => self::SHA,
            'active_action' => 'deploy',
            'deploy' => [
                'request_sha256' => self::SHA,
                'execution_input_sha256' => self::SHA,
                'invocation_count' => 1,
                'unit_name' => DeploymentHostRunnerContractV1::unitName('deploy', self::RUN_ID, self::INTENT_SHA),
                'unit_launch_sha256' => self::SHA,
                'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'unit_invocation_id' => str_repeat('d', 32),
                'unit_missing_observed_boot_id' => null,
                'unit_state' => 'exited',
                'observed_exit_code' => 30,
                'receipt_sha256' => self::SHA,
            ],
            'post_gates' => [
                'deploy_report_sha256' => null,
                'deploy_submission_count' => 0,
                'deploy_verdict' => 'not_submitted',
                'rollback_report_sha256' => null,
                'rollback_submission_count' => 0,
                'rollback_verdict' => 'not_submitted',
            ],
            'rollback' => [
                'request_sha256' => null,
                'execution_input_sha256' => null,
                'invocation_count' => 0,
                'unit_name' => null,
                'unit_launch_sha256' => null,
                'unit_manager_boot_id' => null,
                'unit_invocation_id' => null,
                'unit_missing_observed_boot_id' => null,
                'unit_state' => 'not_created',
                'observed_exit_code' => null,
                'verdict' => 'not_invoked',
            ],
            'evidence_sha256' => null,
            'terminal' => ['state' => null, 'exit_code' => null, 'reason' => null],
            'updated_at_utc' => '2026-08-11T13:00:00Z',
        ];
    }

    /** @param list<string> $lines @return array<string,mixed> */
    private function stateForJournal(array $lines, string $stateName): array
    {
        $eventsBytes = implode("\n", $lines) . "\n";
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['state'] = $stateName;
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $eventsBytes);
        $state['active_action'] = $stateName === 'deploy_running' ? 'deploy' : 'none';
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        if ($stateName === 'artifact_verified') {
            $state['deploy'] = [
                'request_sha256' => self::SHA,
                'execution_input_sha256' => null,
                'invocation_count' => 0,
                'unit_name' => null,
                'unit_launch_sha256' => null,
                'unit_manager_boot_id' => null,
                'unit_invocation_id' => null,
                'unit_missing_observed_boot_id' => null,
                'unit_state' => 'not_created',
                'observed_exit_code' => null,
                'receipt_sha256' => null,
            ];
        } elseif ($stateName === 'post_gates_running') {
            $state['deploy']['unit_state'] = 'exited';
            $state['deploy']['observed_exit_code'] = 0;
            $state['deploy']['receipt_sha256'] = $this->receiptSha256('succeeded');
        }

        return $state;
    }

    /** @return array<string,mixed> */
    private function operatorEvent(int $sequence, string $action, string $event, string $status, string $reason): array
    {
        return [
            'schema' => DeploymentHostRunnerContractV1::OPERATOR_EVENT_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'sequence' => $sequence,
            'recorded_at_utc' => '2026-08-11T13:00:00Z',
            'action' => $action,
            'event' => $event,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeFixture(string $encoded): array
    {
        $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));
        self::assertSame(DeploymentHostRunnerContractV1::encodeFile($decoded), $encoded);

        return $decoded;
    }
}

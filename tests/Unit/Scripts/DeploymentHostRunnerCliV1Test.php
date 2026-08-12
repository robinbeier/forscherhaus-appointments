<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentHostRunnerCliEnvelopeV1;
use Ops\DeploymentHostRunnerCliApplicationV1;
use Ops\DeploymentHostRunnerContractV1;
use Ops\HostRunnerDeployWorkflow;
use Ops\HostRunnerPostGateWorkflow;
use Ops\HostRunnerReservationReconstructor;
use Ops\HostRunnerRecoveryWorkflow;
use Ops\HostRunnerStorage;
use Ops\HostRunnerStoredReconciler;
use Ops\HostRunnerTerminalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerCliV1.php';

final class DeploymentHostRunnerCliV1Test extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/deployment-host-runner-v1/';

    public function testDeployEnvelopeRetainsTheExactValidatedAuthorityBytes(): void
    {
        $request = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $input = (string) file_get_contents(self::FIXTURES . 'execution-input.json');
        $decodedRequest = DeploymentHostRunnerContractV1::decodeDeployRequest($request);

        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope(
                'deploy',
                $decodedRequest['run_id'],
                $decodedRequest['intent_sha256'],
                $request,
                $input,
                null,
            ),
        );

        self::assertSame($request, $envelope['request_bytes']);
        self::assertSame($input, $envelope['execution_input_bytes']);
        self::assertNull($envelope['report_bytes']);
        self::assertSame($decodedRequest, $envelope['request']);
    }

    public function testRecoveryAndPostGateEnvelopesBindEveryAuthorityToOneIdentity(): void
    {
        $recovery = (string) file_get_contents(self::FIXTURES . 'recovery-request.json');
        $recoveryRequest = DeploymentHostRunnerContractV1::decodeRecoveryRequest($recovery);
        $deployInput = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(self::FIXTURES . 'execution-input.json'),
        );
        $recoveryInput = $deployInput;
        $recoveryInput['action'] = 'rollback';
        $recoveryInput['parameters'] = ['release_id' => $deployInput['parameters']['release_id']];
        $recoveryInputBytes = DeploymentHostRunnerContractV1::encodeExecutionInput($recoveryInput);

        $decoded = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope(
                'recovery',
                $recoveryRequest['run_id'],
                $recoveryRequest['intent_sha256'],
                $recovery,
                $recoveryInputBytes,
                null,
            ),
        );
        self::assertSame('rollback', $decoded['execution_input']['action']);

        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $deployRequest = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $decoded = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope(
                'post-gates',
                $deployRequest['run_id'],
                $deployRequest['intent_sha256'],
                $deploy,
                null,
                $report,
            ),
        );
        self::assertSame($report, $decoded['report_bytes']);
    }

    public function testReconcileRejectsFileAuthorityAndDeployRejectsIdentitySubstitution(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $input = (string) file_get_contents(self::FIXTURES . 'execution-input.json');
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);

        $reconcile = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('reconcile', $request['run_id'], $request['intent_sha256'], null, null, null),
        );
        self::assertNull($reconcile['request']);

        foreach (
            [
                $this->envelope('reconcile', $request['run_id'], $request['intent_sha256'], $deploy, null, null),
                $this->envelope(
                    'deploy',
                    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    $request['intent_sha256'],
                    $deploy,
                    $input,
                    null,
                ),
                $this->envelope('deploy', $request['run_id'], str_repeat('f', 64), $deploy, $input, null),
            ]
            as $invalid
        ) {
            try {
                DeploymentHostRunnerCliEnvelopeV1::decode($invalid);
                self::fail('substituted CLI authority must be rejected');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEnvelopeRejectsNonCanonicalShapeMalformedBase64AndOversize(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $input = (string) file_get_contents(self::FIXTURES . 'execution-input.json');
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $valid = json_decode(
            $this->envelope('deploy', $request['run_id'], $request['intent_sha256'], $deploy, $input, null),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );

        $cases = [];
        $extra = $valid;
        $extra['unexpected'] = true;
        $cases[] = json_encode($extra, JSON_THROW_ON_ERROR) . "\n";
        $badBase64 = $valid;
        $badBase64['request_bytes_base64'] = '*';
        $cases[] = json_encode($badBase64, JSON_THROW_ON_ERROR) . "\n";
        $cases[] = str_repeat('x', 65_537);
        $cases[] = substr(
            $this->envelope('deploy', $request['run_id'], $request['intent_sha256'], $deploy, $input, null),
            0,
            -1,
        );

        foreach ($cases as $case) {
            try {
                DeploymentHostRunnerCliEnvelopeV1::decode($case);
                self::fail('malformed CLI envelope must be rejected');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDeployApplicationReconstructsBeforeStartingANewProtectedWorkflow(): void
    {
        $envelope = $this->deployEnvelope();
        $storage = new CliMemoryStorage();
        $reconstructor = new CliReconstructorFake();
        $reconciler = new CliReconcilerFake();
        $workflow = new CliDeployWorkflowFake($this->response($envelope, 'accepted', 'deploy_running'));

        $response = (new DeploymentHostRunnerCliApplicationV1(
            $storage,
            $reconstructor,
            $reconciler,
            $workflow,
        ))->deploy($envelope);

        self::assertSame('accepted', $response['disposition']);
        self::assertSame(1, $reconstructor->calls);
        self::assertSame(1, $workflow->startCalls);
        self::assertSame(0, $workflow->resumeCalls);
        self::assertSame(0, $reconciler->calls);
    }

    public function testDeployApplicationReturnsDurableTerminalRunBeforeAnyNewPredeployWork(): void
    {
        $envelope = $this->deployEnvelope();
        $state = DeploymentHostRunnerContractV1::decodeState((string) file_get_contents(self::FIXTURES . 'state.json'));
        $state['state'] = 'failed_pre_switch';
        $state['active_action'] = 'none';
        $state['evidence_sha256'] = str_repeat('e', 64);
        $state['terminal'] = [
            'state' => 'failed_pre_switch',
            'exit_code' => 30,
            'reason' => 'deploy_failed',
        ];
        DeploymentHostRunnerContractV1::validateState($state);
        $prefix = 'runs/' . $envelope['run_id'] . '/';
        $storage = new CliMemoryStorage([
            $prefix . 'state.json' => DeploymentHostRunnerContractV1::encodeFile($state),
            $prefix . 'request.json' => $envelope['request_bytes'],
            $prefix . 'execution-input.json' => $envelope['execution_input_bytes'],
        ]);
        $workflow = new CliDeployWorkflowFake($this->response($envelope, 'accepted', 'deploy_running'));
        $terminal = new CliTerminalizerFake([
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $envelope['run_id'],
            'intent_sha256' => $envelope['intent_sha256'],
            'action' => 'deploy',
            'disposition' => 'terminal',
            'state' => 'failed_pre_switch',
            'result_exit_code' => 30,
            'result_reason' => 'deploy_failed',
        ]);

        $response = (new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            new CliReconcilerFake(),
            $workflow,
            new CliPostGateWorkflowFake([]),
            new CliRecoveryWorkflowFake([]),
            $terminal,
        ))->deploy($envelope);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame('failed_pre_switch', $response['state']);
        self::assertSame(1, $terminal->calls);
        self::assertSame('deploy', $terminal->action);
        self::assertSame(0, $workflow->startCalls + $workflow->resumeCalls);
        self::assertSame([], $storage->writes);
    }

    public function testDeployApplicationRejectsChangedExecutionInputBeforeClaimlessTerminalReplay(): void
    {
        $original = $this->deployEnvelope();
        $changedInput = $original['execution_input'];
        $changedInput['parameters']['renderer_deploy_mode'] =
            $changedInput['parameters']['renderer_deploy_mode'] === 'host' ? 'external' : 'host';
        $changedInputBytes = DeploymentHostRunnerContractV1::encodeExecutionInput($changedInput);
        $changed = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope(
                'deploy',
                $original['run_id'],
                $original['intent_sha256'],
                $original['request_bytes'],
                $changedInputBytes,
                null,
            ),
        );
        $state = DeploymentHostRunnerContractV1::decodeState((string) file_get_contents(self::FIXTURES . 'state.json'));
        $state['state'] = 'failed_pre_switch';
        $state['active_action'] = 'none';
        $state['evidence_sha256'] = str_repeat('e', 64);
        $state['terminal'] = ['state' => 'failed_pre_switch', 'exit_code' => 30, 'reason' => 'deploy_failed'];
        DeploymentHostRunnerContractV1::validateState($state);
        $prefix = 'runs/' . $original['run_id'] . '/';
        $storage = new CliMemoryStorage([
            $prefix . 'state.json' => DeploymentHostRunnerContractV1::encodeFile($state),
            $prefix . 'request.json' => $original['request_bytes'],
            $prefix . 'execution-input.json' => $original['execution_input_bytes'],
        ]);
        $workflow = new CliDeployWorkflowFake($this->response($original, 'accepted', 'deploy_running'));
        $terminal = new CliTerminalizerFake(
            $this->terminalResponse($original['run_id'], $original['intent_sha256'], 'deploy'),
        );

        $response = (new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            new CliReconcilerFake(),
            $workflow,
            new CliPostGateWorkflowFake([]),
            new CliRecoveryWorkflowFake([]),
            $terminal,
        ))->deploy($changed);

        self::assertSame('rejected', $response['disposition']);
        self::assertSame(75, $response['result_exit_code']);
        self::assertSame(0, $terminal->calls);
        self::assertSame(0, $workflow->startCalls + $workflow->resumeCalls);
        self::assertSame([], $storage->writes);
    }

    public function testDeployApplicationRejectsDifferentActiveClaimBeforeAnyCandidateWork(): void
    {
        $envelope = $this->deployEnvelope();
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun(
            (string) file_get_contents(self::FIXTURES . 'active-run.json'),
        );
        $claim['intent_sha256'] = str_repeat('f', 64);
        $storage = new CliMemoryStorage([
            'active-run.json' => DeploymentHostRunnerContractV1::encodeFile($claim),
        ]);
        $reconstructor = new CliReconstructorFake();
        $reconciler = new CliReconcilerFake();
        $workflow = new CliDeployWorkflowFake($this->response($envelope, 'accepted', 'deploy_running'));

        $response = (new DeploymentHostRunnerCliApplicationV1(
            $storage,
            $reconstructor,
            $reconciler,
            $workflow,
        ))->deploy($envelope);

        self::assertSame('rejected', $response['disposition']);
        self::assertSame(75, $response['result_exit_code']);
        self::assertSame(1, $reconstructor->calls);
        self::assertSame(0, $reconciler->calls);
        self::assertSame(0, $workflow->startCalls + $workflow->resumeCalls);
        self::assertSame([], $storage->writes);
    }

    public function testDeployApplicationReconcilesSameClaimThenResumesWithoutStartingAgain(): void
    {
        $envelope = $this->deployEnvelope();
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            'runs/' . $envelope['run_id'] . '/state.json' => (string) file_get_contents(self::FIXTURES . 'state.json'),
            'runs/' . $envelope['run_id'] . '/request.json' => $envelope['request_bytes'],
            'runs/' . $envelope['run_id'] . '/execution-input.json' => $envelope['execution_input_bytes'],
        ]);
        $reconstructor = new CliReconstructorFake();
        $reconciler = new CliReconcilerFake();
        $workflow = new CliDeployWorkflowFake($this->response($envelope, 'attach_observe_only', 'deploy_running'));

        $response = (new DeploymentHostRunnerCliApplicationV1(
            $storage,
            $reconstructor,
            $reconciler,
            $workflow,
        ))->deploy($envelope);

        self::assertSame('attach_observe_only', $response['disposition']);
        self::assertSame(1, $reconstructor->calls);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(0, $workflow->startCalls);
        self::assertSame(1, $workflow->resumeCalls);
    }

    public function testDeployApplicationRejectsChangedExecutionInputBeforeAttachment(): void
    {
        $original = $this->deployEnvelope();
        $changedInput = $original['execution_input'];
        $changedInput['parameters']['artifact_provenance_sha256'] = str_repeat('c', 64);
        $changedInputBytes = DeploymentHostRunnerContractV1::encodeExecutionInput($changedInput);
        $changed = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope(
                'deploy',
                $original['run_id'],
                $original['intent_sha256'],
                $original['request_bytes'],
                $changedInputBytes,
                null,
            ),
        );
        $prefix = 'runs/' . $original['run_id'] . '/';
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            $prefix . 'state.json' => (string) file_get_contents(self::FIXTURES . 'state.json'),
            $prefix . 'request.json' => $original['request_bytes'],
            $prefix . 'execution-input.json' => $original['execution_input_bytes'],
        ]);
        $reconciler = new CliReconcilerFake();
        $workflow = new CliDeployWorkflowFake($this->response($original, 'attach_observe_only', 'deploy_running'));

        $response = (new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            $reconciler,
            $workflow,
        ))->deploy($changed);

        self::assertSame('rejected', $response['disposition']);
        self::assertSame(75, $response['result_exit_code']);
        self::assertSame(0, $reconciler->calls);
        self::assertSame(0, $workflow->startCalls + $workflow->resumeCalls);
        self::assertSame([], $storage->writes);
    }

    public function testDeployApplicationReplaysTerminalStateProducedByClaimReconciliation(): void
    {
        $envelope = $this->deployEnvelope();
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $prefix = 'runs/' . $envelope['run_id'] . '/';
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            $prefix . 'state.json' => (string) file_get_contents(self::FIXTURES . 'state.json'),
            $prefix . 'request.json' => $envelope['request_bytes'],
            $prefix . 'execution-input.json' => $envelope['execution_input_bytes'],
        ]);
        $reconciler = new CliReconcilerFake(static function () use ($storage, $prefix, $report): void {
            $storage->files[$prefix . 'state.json'] = self::terminalStateBytesStatic($report);
        });
        $workflow = new CliDeployWorkflowFake($this->response($envelope, 'attach_observe_only', 'deploy_running'));
        $terminal = new CliTerminalizerFake(
            $this->terminalResponse($envelope['run_id'], $envelope['intent_sha256'], 'deploy'),
        );
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            $reconciler,
            $workflow,
            new CliPostGateWorkflowFake([]),
            new CliRecoveryWorkflowFake([]),
            $terminal,
        );

        $response = $app->deploy($envelope);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(1, $terminal->calls);
        self::assertSame('deploy', $terminal->action);
        self::assertSame(0, $workflow->startCalls + $workflow->resumeCalls);
    }

    public function testPostGateApplicationRequiresSameClaimAndRoutesExactReportOnce(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('post-gates', $request['run_id'], $request['intent_sha256'], $deploy, null, $report),
        );
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            'runs/' . $request['run_id'] . '/state.json' => (string) file_get_contents(self::FIXTURES . 'state.json'),
        ]);
        $postGates = new CliPostGateWorkflowFake([
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'post-gates',
            'disposition' => 'attach_observe_only',
            'state' => 'post_gates_running',
            'result_exit_code' => 0,
            'result_reason' => 'ok',
        ]);
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            new CliReconcilerFake(),
            new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running')),
            $postGates,
        );

        $response = $app->postGates($envelope);

        self::assertSame('attach_observe_only', $response['disposition']);
        self::assertSame(1, $postGates->calls);
        self::assertSame($report, $postGates->reportBytes);
    }

    public function testPostGateApplicationReplaysTerminalStateProducedByClaimReconciliation(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('post-gates', $request['run_id'], $request['intent_sha256'], $deploy, null, $report),
        );
        $prefix = 'runs/' . $request['run_id'] . '/';
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            $prefix . 'state.json' => (string) file_get_contents(self::FIXTURES . 'state.json'),
            $prefix . 'deploy-post-gate-report.json' => $report,
        ]);
        $reconciler = new CliReconcilerFake(static function () use ($storage, $prefix, $report): void {
            $storage->files[$prefix . 'state.json'] = self::terminalStateBytesStatic($report);
        });
        $postGates = new CliPostGateWorkflowFake([]);
        $terminal = new CliTerminalizerFake(
            $this->terminalResponse($request['run_id'], $request['intent_sha256'], 'post-gates'),
        );
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            $reconciler,
            new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running')),
            $postGates,
            new CliRecoveryWorkflowFake([]),
            $terminal,
        );

        $response = $app->postGates($envelope);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(1, $terminal->calls);
        self::assertSame('post-gates', $terminal->action);
        self::assertSame(0, $postGates->calls);
    }

    public function testPostGateApplicationReplaysMatchingDurableTerminalReportWithoutClaim(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('post-gates', $request['run_id'], $request['intent_sha256'], $deploy, null, $report),
        );
        $prefix = 'runs/' . $request['run_id'] . '/';
        $storage = new CliMemoryStorage([
            $prefix . 'state.json' => $this->terminalStateBytes($report),
            $prefix . 'deploy-post-gate-report.json' => $report,
        ]);
        $postGates = new CliPostGateWorkflowFake([]);
        $terminal = new CliTerminalizerFake(
            $this->terminalResponse($request['run_id'], $request['intent_sha256'], 'post-gates'),
        );
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            new CliReconcilerFake(),
            new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running')),
            $postGates,
            new CliRecoveryWorkflowFake([]),
            $terminal,
        );

        $response = $app->postGates($envelope);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame('post-gates', $response['action']);
        self::assertSame(1, $terminal->calls);
        self::assertSame('post-gates', $terminal->action);
        self::assertSame(0, $postGates->calls);
        self::assertSame([], $storage->writes);
    }

    public function testPostGateApplicationRejectsChangedReportAgainstDurableTerminalRun(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $changed = DeploymentHostRunnerContractV1::decodePostGateReport($report);
        $changed['captured_at_utc'] = '2026-08-11T13:05:01Z';
        $changedBytes = DeploymentHostRunnerContractV1::encodeFile($changed);
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('post-gates', $request['run_id'], $request['intent_sha256'], $deploy, null, $changedBytes),
        );
        $prefix = 'runs/' . $request['run_id'] . '/';
        $storage = new CliMemoryStorage([
            $prefix . 'state.json' => $this->terminalStateBytes($report),
            $prefix . 'deploy-post-gate-report.json' => $report,
        ]);
        $postGates = new CliPostGateWorkflowFake([]);
        $terminal = new CliTerminalizerFake(
            $this->terminalResponse($request['run_id'], $request['intent_sha256'], 'post-gates'),
        );
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            new CliReconcilerFake(),
            new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running')),
            $postGates,
            new CliRecoveryWorkflowFake([]),
            $terminal,
        );

        $response = $app->postGates($envelope);

        self::assertSame('rejected', $response['disposition']);
        self::assertSame(75, $response['result_exit_code']);
        self::assertSame(0, $terminal->calls);
        self::assertSame(0, $postGates->calls);
        self::assertSame([], $storage->writes);
    }

    public function testReconcileNeverStartsAWorkflowAndReturnsCurrentClaimState(): void
    {
        $envelope = $this->deployEnvelope();
        $reconcileEnvelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('reconcile', $envelope['run_id'], $envelope['intent_sha256'], null, null, null),
        );
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            'runs/' . $envelope['run_id'] . '/state.json' => (string) file_get_contents(self::FIXTURES . 'state.json'),
        ]);
        $workflow = new CliDeployWorkflowFake($this->response($envelope, 'accepted', 'deploy_running'));
        $reconciler = new CliReconcilerFake();
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            $reconciler,
            $workflow,
            new CliPostGateWorkflowFake([]),
        );

        $response = $app->reconcile($reconcileEnvelope);

        self::assertSame('reconcile', $response['action']);
        self::assertSame('attach_observe_only', $response['disposition']);
        self::assertSame('deploy_running', $response['state']);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(0, $workflow->startCalls + $workflow->resumeCalls);
    }

    public function testReconcileReturnsDurableTerminalRunWithoutClaim(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('reconcile', $request['run_id'], $request['intent_sha256'], null, null, null),
        );
        $storage = new CliMemoryStorage([
            'runs/' . $request['run_id'] . '/state.json' => $this->terminalStateBytes($report),
        ]);
        $workflow = new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running'));
        $reconciler = new CliReconcilerFake();
        $terminal = new CliTerminalizerFake(
            $this->terminalResponse($request['run_id'], $request['intent_sha256'], 'reconcile'),
        );
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            $reconciler,
            $workflow,
            new CliPostGateWorkflowFake([]),
            new CliRecoveryWorkflowFake([]),
            $terminal,
        );

        $response = $app->reconcile($envelope);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame('reconcile', $response['action']);
        self::assertSame(1, $terminal->calls);
        self::assertSame('reconcile', $terminal->action);
        self::assertSame(0, $reconciler->calls);
        self::assertSame(0, $workflow->startCalls + $workflow->resumeCalls);
        self::assertSame([], $storage->writes);
    }

    public function testReconcileClearsTerminalClaimThroughValidatedReplay(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('reconcile', $request['run_id'], $request['intent_sha256'], null, null, null),
        );
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            'runs/' . $request['run_id'] . '/state.json' => $this->terminalStateBytes($report),
        ]);
        $reconciler = new CliReconcilerFake();
        $terminal = new CliTerminalizerFake(
            $this->terminalResponse($request['run_id'], $request['intent_sha256'], 'reconcile'),
        );
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            $reconciler,
            new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running')),
            new CliPostGateWorkflowFake([]),
            new CliRecoveryWorkflowFake([]),
            $terminal,
        );

        $response = $app->reconcile($envelope);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(1, $terminal->calls);
        self::assertSame('reconcile', $terminal->action);
    }

    public function testRecoveryRequiresSameClaimAndRoutesExactBoundInput(): void
    {
        $recoveryBytes = (string) file_get_contents(self::FIXTURES . 'recovery-request.json');
        $request = DeploymentHostRunnerContractV1::decodeRecoveryRequest($recoveryBytes);
        $deployInput = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(self::FIXTURES . 'execution-input.json'),
        );
        $input = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'rollback',
            'parameters' => ['release_id' => $deployInput['parameters']['release_id']],
        ];
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope(
                'recovery',
                $request['run_id'],
                $request['intent_sha256'],
                $recoveryBytes,
                DeploymentHostRunnerContractV1::encodeExecutionInput($input),
                null,
            ),
        );
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            'runs/' . $request['run_id'] . '/state.json' => (string) file_get_contents(self::FIXTURES . 'state.json'),
        ]);
        $workflow = new CliRecoveryWorkflowFake([
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'recovery',
            'disposition' => 'accepted',
            'state' => 'rollback_running',
            'result_exit_code' => 0,
            'result_reason' => 'ok',
        ]);
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            new CliReconcilerFake(),
            new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running')),
            new CliPostGateWorkflowFake([]),
            $workflow,
        );

        $response = $app->recovery($envelope);

        self::assertSame('accepted', $response['disposition']);
        self::assertSame(1, $workflow->calls);
        self::assertSame('rollback', $workflow->input['action']);
    }

    public function testRecoveryReplaysTerminalStateProducedByClaimReconciliation(): void
    {
        $recoveryBytes = (string) file_get_contents(self::FIXTURES . 'recovery-request.json');
        $request = DeploymentHostRunnerContractV1::decodeRecoveryRequest($recoveryBytes);
        $deployInput = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(self::FIXTURES . 'execution-input.json'),
        );
        $input = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'rollback',
            'parameters' => ['release_id' => $deployInput['parameters']['release_id']],
        ];
        $inputBytes = DeploymentHostRunnerContractV1::encodeExecutionInput($input);
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope(
                'recovery',
                $request['run_id'],
                $request['intent_sha256'],
                $recoveryBytes,
                $inputBytes,
                null,
            ),
        );
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $prefix = 'runs/' . $request['run_id'] . '/';
        $storage = new CliMemoryStorage([
            'active-run.json' => (string) file_get_contents(self::FIXTURES . 'active-run.json'),
            $prefix . 'state.json' => (string) file_get_contents(self::FIXTURES . 'state.json'),
            $prefix . 'recovery-request.json' => $recoveryBytes,
            $prefix . 'recovery-execution-input.json' => $inputBytes,
        ]);
        $reconciler = new CliReconcilerFake(static function () use ($storage, $prefix, $report): void {
            $storage->files[$prefix . 'state.json'] = self::terminalStateBytesStatic(
                $report,
                'failed_post_switch_rollback_failed',
            );
        });
        $workflow = new CliRecoveryWorkflowFake([]);
        $terminal = new CliTerminalizerFake([
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'recovery',
            'disposition' => 'terminal',
            'state' => 'failed_post_switch_rollback_failed',
            'result_exit_code' => 31,
            'result_reason' => 'rollback_failed',
        ]);
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            $reconciler,
            new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running')),
            new CliPostGateWorkflowFake([]),
            $workflow,
            $terminal,
        );

        $response = $app->recovery($envelope);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(1, $terminal->calls);
        self::assertSame('recovery', $terminal->action);
        self::assertSame(0, $workflow->calls);
    }

    public function testRecoveryReplaysDurableTerminalRunWithoutClaim(): void
    {
        $recoveryBytes = (string) file_get_contents(self::FIXTURES . 'recovery-request.json');
        $request = DeploymentHostRunnerContractV1::decodeRecoveryRequest($recoveryBytes);
        $deployInput = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(self::FIXTURES . 'execution-input.json'),
        );
        $input = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'rollback',
            'parameters' => ['release_id' => $deployInput['parameters']['release_id']],
        ];
        $inputBytes = DeploymentHostRunnerContractV1::encodeExecutionInput($input);
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope(
                'recovery',
                $request['run_id'],
                $request['intent_sha256'],
                $recoveryBytes,
                $inputBytes,
                null,
            ),
        );
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $prefix = 'runs/' . $request['run_id'] . '/';
        $storage = new CliMemoryStorage([
            $prefix . 'state.json' => $this->terminalStateBytes($report, 'failed_post_switch_rollback_failed'),
            $prefix . 'recovery-request.json' => $recoveryBytes,
            $prefix . 'recovery-execution-input.json' => $inputBytes,
        ]);
        $workflow = new CliRecoveryWorkflowFake([]);
        $terminal = new CliTerminalizerFake([
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'recovery',
            'disposition' => 'terminal',
            'state' => 'failed_post_switch_rollback_failed',
            'result_exit_code' => 31,
            'result_reason' => 'rollback_failed',
        ]);
        $app = new DeploymentHostRunnerCliApplicationV1(
            $storage,
            new CliReconstructorFake(),
            new CliReconcilerFake(),
            new CliDeployWorkflowFake($this->response($this->deployEnvelope(), 'accepted', 'deploy_running')),
            new CliPostGateWorkflowFake([]),
            $workflow,
            $terminal,
        );

        $response = $app->recovery($envelope);

        self::assertSame('terminal', $response['disposition']);
        self::assertSame('recovery', $response['action']);
        self::assertSame(1, $terminal->calls);
        self::assertSame('recovery', $terminal->action);
        self::assertSame(0, $workflow->calls);
        self::assertSame([], $storage->writes);
    }

    public function testPublicCliRejectsWrongShapeWithFixedUsageExitAndNoStdout(): void
    {
        $script = __DIR__ . '/../../../scripts/ops/deployment_host_runner_v1.php';
        foreach (
            [
                [],
                ['--action=deploy'],
                ['--action=deploy', '--request-file=/tmp/request', '--report-file=/tmp/report'],
                [
                    '--action=reconcile',
                    '--intent-sha256=' . str_repeat('a', 64),
                    '--run-id=018f6f52-4c87-4d4e-8b19-6a66e6e1af25',
                ],
            ]
            as $arguments
        ) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $script, ...$arguments],
                [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                null,
                [],
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(64, proc_close($process));
            self::assertSame('', $stdout);
            self::assertSame("deployment host runner usage invalid\n", $stderr);
        }
    }

    private function envelope(
        string $action,
        string $runId,
        string $intentSha256,
        ?string $request,
        ?string $input,
        ?string $report,
    ): string {
        return json_encode(
            [
                'action' => $action,
                'execution_input_bytes_base64' => $input === null ? null : base64_encode($input),
                'intent_sha256' => $intentSha256,
                'report_bytes_base64' => $report === null ? null : base64_encode($report),
                'request_bytes_base64' => $request === null ? null : base64_encode($request),
                'run_id' => $runId,
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return array<string,mixed> */
    private function deployEnvelope(): array
    {
        $request = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $input = (string) file_get_contents(self::FIXTURES . 'execution-input.json');
        $decoded = DeploymentHostRunnerContractV1::decodeDeployRequest($request);
        return DeploymentHostRunnerCliEnvelopeV1::decode(
            $this->envelope('deploy', $decoded['run_id'], $decoded['intent_sha256'], $request, $input, null),
        );
    }

    private function terminalStateBytes(string $deployReportBytes, string $terminalState = 'succeeded'): string
    {
        return self::terminalStateBytesStatic($deployReportBytes, $terminalState);
    }

    private static function terminalStateBytesStatic(
        string $deployReportBytes,
        string $terminalState = 'succeeded',
    ): string {
        $state = DeploymentHostRunnerContractV1::decodeState((string) file_get_contents(self::FIXTURES . 'state.json'));
        $state['state'] = $terminalState;
        $state['active_action'] = 'none';
        $state['evidence_sha256'] = str_repeat('e', 64);
        $state['deploy']['observed_exit_code'] = 0;
        $state['post_gates']['deploy_report_sha256'] = hash('sha256', $deployReportBytes);
        $state['post_gates']['deploy_submission_count'] = 1;
        if ($terminalState === 'succeeded') {
            $state['post_gates']['deploy_verdict'] = 'passed';
            $state['terminal'] = ['state' => 'succeeded', 'exit_code' => 0, 'reason' => 'ok'];
        } else {
            $state['post_gates']['deploy_verdict'] = 'failed';
            $state['rollback'] = [
                'execution_input_sha256' => str_repeat('a', 64),
                'invocation_count' => 1,
                'observed_exit_code' => 31,
                'request_sha256' => str_repeat('b', 64),
                'unit_invocation_id' => str_repeat('c', 32),
                'unit_launch_sha256' => str_repeat('d', 64),
                'unit_manager_boot_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                'unit_missing_observed_boot_id' => null,
                'unit_name' => DeploymentHostRunnerContractV1::unitName(
                    'rollback',
                    $state['run_id'],
                    $state['intent_sha256'],
                ),
                'unit_state' => 'exited',
                'verdict' => 'failed',
            ];
            $state['terminal'] = ['state' => $terminalState, 'exit_code' => 31, 'reason' => 'rollback_failed'];
        }
        DeploymentHostRunnerContractV1::validateState($state);
        return DeploymentHostRunnerContractV1::encodeFile($state);
    }

    /** @return array<string,mixed> */
    private function terminalResponse(string $runId, string $intentSha256, string $action): array
    {
        return [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $intentSha256,
            'action' => $action,
            'disposition' => 'terminal',
            'state' => 'succeeded',
            'result_exit_code' => 0,
            'result_reason' => 'ok',
        ];
    }

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    private function response(array $envelope, string $disposition, string $state): array
    {
        return [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $envelope['run_id'],
            'intent_sha256' => $envelope['intent_sha256'],
            'action' => 'deploy',
            'disposition' => $disposition,
            'state' => $state,
            'result_exit_code' => 0,
            'result_reason' => 'ok',
        ];
    }
}

final class CliReconstructorFake implements HostRunnerReservationReconstructor
{
    public int $calls = 0;
    public function reconstruct(): string
    {
        $this->calls++;
        return 'no_reserved_run';
    }
}

final class CliReconcilerFake implements HostRunnerStoredReconciler
{
    public int $calls = 0;
    public function __construct(private readonly ?\Closure $onReconcile = null) {}
    public function reconcile(string $runId, string $intentSha256): string
    {
        $this->calls++;
        if ($this->onReconcile !== null) {
            ($this->onReconcile)($runId, $intentSha256);
        }
        return 'attach_observe_only';
    }
}

final class CliDeployWorkflowFake implements HostRunnerDeployWorkflow
{
    public int $startCalls = 0;
    public int $resumeCalls = 0;
    /** @param array<string,mixed> $response */
    public function __construct(private readonly array $response) {}
    public function start(array $request, array $input): array
    {
        $this->startCalls++;
        return $this->response;
    }
    public function resume(array $request, array $input): array
    {
        $this->resumeCalls++;
        return $this->response;
    }
}

final class CliPostGateWorkflowFake implements HostRunnerPostGateWorkflow
{
    public int $calls = 0;
    public ?string $reportBytes = null;
    /** @param array<string,mixed> $response */
    public function __construct(private readonly array $response) {}
    public function submit(array $request, array $report, string $reportBytes): array
    {
        $this->calls++;
        $this->reportBytes = $reportBytes;
        return $this->response;
    }
}

final class CliRecoveryWorkflowFake implements HostRunnerRecoveryWorkflow
{
    public int $calls = 0;
    /** @var array<string,mixed> */
    public array $input = [];
    /** @param array<string,mixed> $response */
    public function __construct(private readonly array $response) {}
    public function admit(array $request, array $input): array
    {
        $this->calls++;
        $this->input = $input;
        return $this->response;
    }
}

final class CliTerminalizerFake implements HostRunnerTerminalizer
{
    public int $calls = 0;
    public ?string $action = null;
    /** @param array<string,mixed> $response */
    public function __construct(private readonly array $response) {}
    public function resumeTerminal(string $runId, string $action = 'deploy'): array
    {
        $this->calls++;
        $this->action = $action;
        return $this->response;
    }
    public function terminalizeDeploy(string $runId): array
    {
        throw new RuntimeException('not expected');
    }
    public function terminalizeUnverifiableDeploy(string $runId): array
    {
        throw new RuntimeException('not expected');
    }
    public function terminalizeRollback(string $runId): array
    {
        throw new RuntimeException('not expected');
    }
}

final class CliMemoryStorage implements HostRunnerStorage
{
    /** @var list<string> */
    public array $writes = [];
    /** @param array<string,string> $files */
    public function __construct(public array $files = []) {}
    public function prepareRun(string $runId): void
    {
        $this->writes[] = 'prepare:' . $runId;
    }
    public function reservedCandidates(): iterable
    {
        return [];
    }
    public function read(string $relative, int $maxBytes): ?string
    {
        return $this->files[$relative] ?? null;
    }
    public function pin(string $relative, string $bytes, int $maxBytes): string
    {
        $this->writes[] = 'pin:' . $relative;
        $this->files[$relative] = $bytes;
        return 'pinned_or_resumed_exact';
    }
    public function cow(string $relative, string $bytes, int $maxBytes): void
    {
        $this->writes[] = 'cow:' . $relative;
        $this->files[$relative] = $bytes;
    }
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void
    {
        $this->writes[] = 'reference:' . $field;
    }
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void
    {
        $this->writes[] = 'binding:' . $relative;
        $this->files[$relative] = $candidateBytes;
    }
    public function clearActiveClaim(string $expectedBytes): void
    {
        $this->writes[] = 'clear:active-run.json';
        unset($this->files['active-run.json']);
    }
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void
    {
        $this->writes[] = 'claim:active-run.json';
        $this->files['active-run.json'] = $candidateBytes;
    }
}

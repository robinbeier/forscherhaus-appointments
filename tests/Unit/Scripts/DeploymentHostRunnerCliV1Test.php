<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentHostRunnerCliEnvelopeV1;
use Ops\DeploymentHostRunnerCliApplicationV1;
use Ops\DeploymentHostRunnerContractV1;
use Ops\HostRunnerDeployWorkflow;
use Ops\HostRunnerReservationReconstructor;
use Ops\HostRunnerStorage;
use Ops\HostRunnerStoredReconciler;
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

        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode($this->envelope(
            'deploy',
            $decodedRequest['run_id'],
            $decodedRequest['intent_sha256'],
            $request,
            $input,
            null,
        ));

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

        $decoded = DeploymentHostRunnerCliEnvelopeV1::decode($this->envelope(
            'recovery',
            $recoveryRequest['run_id'],
            $recoveryRequest['intent_sha256'],
            $recovery,
            $recoveryInputBytes,
            null,
        ));
        self::assertSame('rollback', $decoded['execution_input']['action']);

        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $report = (string) file_get_contents(self::FIXTURES . 'post-gate-report.json');
        $deployRequest = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $decoded = DeploymentHostRunnerCliEnvelopeV1::decode($this->envelope(
            'post-gates',
            $deployRequest['run_id'],
            $deployRequest['intent_sha256'],
            $deploy,
            null,
            $report,
        ));
        self::assertSame($report, $decoded['report_bytes']);
    }

    public function testReconcileRejectsFileAuthorityAndDeployRejectsIdentitySubstitution(): void
    {
        $deploy = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $input = (string) file_get_contents(self::FIXTURES . 'execution-input.json');
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);

        $reconcile = DeploymentHostRunnerCliEnvelopeV1::decode($this->envelope(
            'reconcile', $request['run_id'], $request['intent_sha256'], null, null, null,
        ));
        self::assertNull($reconcile['request']);

        foreach ([
            $this->envelope('reconcile', $request['run_id'], $request['intent_sha256'], $deploy, null, null),
            $this->envelope('deploy', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $request['intent_sha256'], $deploy, $input, null),
            $this->envelope('deploy', $request['run_id'], str_repeat('f', 64), $deploy, $input, null),
        ] as $invalid) {
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
        $valid = json_decode($this->envelope(
            'deploy', $request['run_id'], $request['intent_sha256'], $deploy, $input, null,
        ), true, 32, JSON_THROW_ON_ERROR);

        $cases = [];
        $extra = $valid;
        $extra['unexpected'] = true;
        $cases[] = json_encode($extra, JSON_THROW_ON_ERROR) . "\n";
        $badBase64 = $valid;
        $badBase64['request_bytes_base64'] = '*';
        $cases[] = json_encode($badBase64, JSON_THROW_ON_ERROR) . "\n";
        $cases[] = str_repeat('x', 65_537);
        $cases[] = substr($this->envelope(
            'deploy', $request['run_id'], $request['intent_sha256'], $deploy, $input, null,
        ), 0, -1);

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
            $storage, $reconstructor, $reconciler, $workflow,
        ))->deploy($envelope);

        self::assertSame('accepted', $response['disposition']);
        self::assertSame(1, $reconstructor->calls);
        self::assertSame(1, $workflow->startCalls);
        self::assertSame(0, $workflow->resumeCalls);
        self::assertSame(0, $reconciler->calls);
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
            $storage, $reconstructor, $reconciler, $workflow,
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
        ]);
        $reconstructor = new CliReconstructorFake();
        $reconciler = new CliReconcilerFake();
        $workflow = new CliDeployWorkflowFake($this->response($envelope, 'attach_observe_only', 'deploy_running'));

        $response = (new DeploymentHostRunnerCliApplicationV1(
            $storage, $reconstructor, $reconciler, $workflow,
        ))->deploy($envelope);

        self::assertSame('attach_observe_only', $response['disposition']);
        self::assertSame(1, $reconstructor->calls);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(0, $workflow->startCalls);
        self::assertSame(1, $workflow->resumeCalls);
    }

    private function envelope(
        string $action,
        string $runId,
        string $intentSha256,
        ?string $request,
        ?string $input,
        ?string $report,
    ): string {
        return json_encode([
            'action' => $action,
            'execution_input_bytes_base64' => $input === null ? null : base64_encode($input),
            'intent_sha256' => $intentSha256,
            'report_bytes_base64' => $report === null ? null : base64_encode($report),
            'request_bytes_base64' => $request === null ? null : base64_encode($request),
            'run_id' => $runId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string,mixed> */
    private function deployEnvelope(): array
    {
        $request = (string) file_get_contents(self::FIXTURES . 'deploy-request.json');
        $input = (string) file_get_contents(self::FIXTURES . 'execution-input.json');
        $decoded = DeploymentHostRunnerContractV1::decodeDeployRequest($request);
        return DeploymentHostRunnerCliEnvelopeV1::decode($this->envelope(
            'deploy', $decoded['run_id'], $decoded['intent_sha256'], $request, $input, null,
        ));
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
    public function reconstruct(): string { $this->calls++; return 'no_reserved_run'; }
}

final class CliReconcilerFake implements HostRunnerStoredReconciler
{
    public int $calls = 0;
    public function reconcile(string $runId, string $intentSha256): string
    {
        $this->calls++;
        return 'attach_observe_only';
    }
}

final class CliDeployWorkflowFake implements HostRunnerDeployWorkflow
{
    public int $startCalls = 0;
    public int $resumeCalls = 0;
    /** @param array<string,mixed> $response */
    public function __construct(private readonly array $response) {}
    public function start(array $request, array $input): array { $this->startCalls++; return $this->response; }
    public function resume(array $request, array $input): array { $this->resumeCalls++; return $this->response; }
}

final class CliMemoryStorage implements HostRunnerStorage
{
    /** @var list<string> */
    public array $writes = [];
    /** @param array<string,string> $files */
    public function __construct(public array $files = []) {}
    public function prepareRun(string $runId): void { $this->writes[] = 'prepare:' . $runId; }
    public function reservedCandidates(): iterable { return []; }
    public function read(string $relative, int $maxBytes): ?string { return $this->files[$relative] ?? null; }
    public function pin(string $relative, string $bytes, int $maxBytes): string { $this->writes[] = 'pin:' . $relative; $this->files[$relative] = $bytes; return 'pinned_or_resumed_exact'; }
    public function cow(string $relative, string $bytes, int $maxBytes): void { $this->writes[] = 'cow:' . $relative; $this->files[$relative] = $bytes; }
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void { $this->writes[] = 'reference:' . $field; }
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void { $this->writes[] = 'binding:' . $relative; $this->files[$relative] = $candidateBytes; }
    public function clearActiveClaim(string $expectedBytes): void { $this->writes[] = 'clear:active-run.json'; unset($this->files['active-run.json']); }
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void { $this->writes[] = 'claim:active-run.json'; $this->files['active-run.json'] = $candidateBytes; }
}

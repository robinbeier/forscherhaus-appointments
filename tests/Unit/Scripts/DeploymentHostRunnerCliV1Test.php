<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentHostRunnerCliEnvelopeV1;
use Ops\DeploymentHostRunnerContractV1;
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
}

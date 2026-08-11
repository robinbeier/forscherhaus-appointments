<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentContractV1;
use Ops\DeploymentHostRunnerContractV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentContractV1.php';
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
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, self::INTENT_SHA),
            'unit_state' => 'running',
            'observed_exit_code' => null,
            'verdict' => 'not_invoked',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateState($state);
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
            'status' => 'ok',
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
        self::assertArrayNotHasKey('CollectMode', $properties);

        $rollbackProperties = DeploymentHostRunnerContractV1::unitProperties('rollback');
        self::assertSame('1800s', $rollbackProperties['RuntimeMaxSec']);
        self::assertSame('300s', $rollbackProperties['TimeoutStopSec']);
        self::assertSame('no', $rollbackProperties['Restart']);
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
        yield 'new deploy accepted' => ['deploy', 'accepted', 'accepted'];
        yield 'deploy attaches before reservation' => ['deploy', 'attach_pre_deploy', 'artifact_verified'];
        yield 'deploy observes reservation' => ['deploy', 'attach_observe_only', 'deploy_running'];
        yield 'recovery accepted after post gates' => ['recovery', 'accepted', 'post_gates_running'];
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

        $decodedDeploy = DeploymentHostRunnerContractV1::decodeDeployRequest($deploy);
        $decodedRecovery = DeploymentHostRunnerContractV1::decodeRecoveryRequest($recovery);
        $decodedState = DeploymentHostRunnerContractV1::decodeState($state);
        $decodedOperator = DeploymentHostRunnerContractV1::decodeOperatorEvent($operator);
        $decodedActive = DeploymentHostRunnerContractV1::decodeActiveRun($active);
        $decodedResponse = DeploymentHostRunnerContractV1::decodeResponse($response);

        foreach ([$decodedRecovery, $decodedState, $decodedOperator, $decodedActive, $decodedResponse] as $fixture) {
            self::assertSame($decodedDeploy['run_id'], $fixture['run_id']);
            self::assertSame($decodedDeploy['intent_sha256'], $fixture['intent_sha256']);
        }
        self::assertSame($decodedState['run_id'], $decodedActive['run_id']);
        self::assertSame($decodedState['intent_sha256'], $decodedActive['intent_sha256']);
        self::assertSame($decodedState['sequence'], $decodedActive['sequence']);
        self::assertSame($decodedState['events_sha256'], $decodedActive['events_sha256']);
        self::assertSame(6, count(glob($root . '/*.json') ?: []));
    }

    public function testPathsAndInternalCliContractAreDeterministic(): void
    {
        self::assertSame('/run/lock/fh-production-change.lock', DeploymentHostRunnerContractV1::GLOBAL_LOCK_PATH);
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

        self::assertSame(
            'accepted',
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
                $this->runThrough('post_gates_running'),
                $request,
            ),
        );

        $rollback = $this->runThrough('post_gates_running');
        $rollback[] = $this->transition($rollback, 'rollback_running');
        self::assertSame(
            'attach_observe_only',
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition($rollback, $request),
        );

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::recoveryAttachmentDisposition($this->runThrough('deploy_running'), $request);
    }

    public function testTerminalAttachmentRequiresMatchingDurableStateAndEvidence(): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->succeededEvidence($lines)) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);

        self::assertSame(
            'terminal',
            DeploymentHostRunnerContractV1::deployAttachmentDisposition(
                $lines,
                $this->deployRequest(),
                $state,
                $evidenceBytes,
            ),
        );
        self::assertSame(
            'terminal',
            DeploymentHostRunnerContractV1::recoveryAttachmentDisposition(
                $lines,
                $this->recoveryRequest(),
                $state,
                $evidenceBytes,
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
        $state = $includeState ? $this->terminalState('succeeded', 0, 'ok') : null;
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
        $state = $this->terminalState('failed_pre_switch', 143, 'interrupted');
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
        $state = $this->terminalState('succeeded', 0, 'ok');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $state['deploy']['observed_exit_code'] = 30;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deploy outcome');
        DeploymentHostRunnerContractV1::terminalStateCacheDisposition($state, $events, $evidenceBytes);
    }

    public function testTerminalStateCacheRejectsRollbackVerdictThatContradictsEvidence(): void
    {
        $lines = $this->rollbackSucceededLines();
        $events = implode("\n", $lines) . "\n";
        $evidenceBytes = DeploymentContractV1::canonicalJson($this->rollbackSucceededEvidence($lines)) . "\n";
        $state = $this->terminalState('failed_post_switch_rollback_succeeded', 30, 'deploy_failed');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
        $state['deploy']['observed_exit_code'] = 0;
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, $state['intent_sha256']),
            'unit_state' => 'exited',
            'observed_exit_code' => 1,
            'verdict' => 'failed',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rollback outcome');
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
        $state = $this->terminalState('succeeded', 0, 'ok');
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $events);
        $state['evidence_sha256'] = hash('sha256', $evidenceBytes);
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
        );
    }

    #[DataProvider('invalidTerminalEvidenceBytesProvider')]
    public function testTerminalStateCacheRejectsMalformedOrNoncanonicalEvidenceBytes(string $evidenceBytes): void
    {
        $lines = $this->runThrough('succeeded');
        $events = implode("\n", $lines) . "\n";
        $state = $this->terminalState('succeeded', 0, 'ok');
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
        $state['rollback'] = [
            'request_sha256' => self::SHA,
            'execution_input_sha256' => self::SHA,
            'invocation_count' => 1,
            'unit_name' => DeploymentHostRunnerContractV1::unitName('rollback', self::RUN_ID, $state['intent_sha256']),
            'unit_state' => 'running',
            'observed_exit_code' => null,
            'verdict' => 'unknown',
        ];

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

    /** @return array<string,mixed> */
    private function terminalState(string $stateName, int $exitCode, string $reason): array
    {
        $state = $this->state();
        $state['intent_sha256'] = $this->deployRequest()['intent_sha256'];
        $state['state'] = $stateName;
        $state['active_action'] = 'none';
        $state['deploy']['unit_name'] = DeploymentHostRunnerContractV1::unitName(
            'deploy',
            self::RUN_ID,
            $state['intent_sha256'],
        );
        $state['deploy']['observed_exit_code'] = $exitCode;
        $state['evidence_sha256'] = self::SHA;
        $state['terminal'] = ['state' => $stateName, 'exit_code' => $exitCode, 'reason' => $reason];

        return $state;
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
                'unit_state' => 'exited',
                'observed_exit_code' => 30,
                'receipt_sha256' => self::SHA,
            ],
            'rollback' => [
                'request_sha256' => null,
                'execution_input_sha256' => null,
                'invocation_count' => 0,
                'unit_name' => null,
                'unit_state' => 'not_created',
                'observed_exit_code' => null,
                'verdict' => 'not_invoked',
            ],
            'evidence_sha256' => null,
            'terminal' => ['state' => null, 'exit_code' => null, 'reason' => null],
            'updated_at_utc' => '2026-08-11T13:00:00Z',
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

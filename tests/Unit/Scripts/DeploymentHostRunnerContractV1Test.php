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

    public function testActiveRunClaimAllowsOnlyReservedNonterminalPhases(): void
    {
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::INTENT_SHA,
            'state' => 'deploy_running',
            'state_sha256' => self::SHA,
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        DeploymentHostRunnerContractV1::validateActiveRun($claim);

        $claim['state'] = 'succeeded';
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
        self::assertSame(hash('sha256', $state), $decodedActive['state_sha256']);
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
            'state_sha256' => DeploymentHostRunnerContractV1::fileSha256(
                DeploymentHostRunnerContractV1::encodeFile($state),
            ),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        self::assertSame(
            'attach_observe_only',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                self::RUN_ID,
                $this->deployRequest()['intent_sha256'],
            ),
        );

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $events,
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
        DeploymentHostRunnerContractV1::validateStateAgainstJournal($state, $events);

        $state['sequence']--;
        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::validateStateAgainstJournal($state, $events);
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
        DeploymentHostRunnerContractV1::validateStateAgainstJournal($this->state(), $events);
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

    public function testTerminalJournalAllowsClearingAStaleActiveClaim(): void
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
            'state_sha256' => DeploymentHostRunnerContractV1::fileSha256(
                DeploymentHostRunnerContractV1::encodeFile($state),
            ),
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        self::assertSame(
            'clear_terminal',
            DeploymentHostRunnerContractV1::activeRunDisposition(
                $claim,
                $state,
                $events,
                self::RUN_ID,
                $state['intent_sha256'],
            ),
        );
    }

    public function testActiveRunClaimMustBindExactStateBytes(): void
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
            'state_sha256' => self::SHA,
            'claimed_at_utc' => '2026-08-11T13:00:00Z',
        ];

        $this->expectException(RuntimeException::class);
        DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $events,
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

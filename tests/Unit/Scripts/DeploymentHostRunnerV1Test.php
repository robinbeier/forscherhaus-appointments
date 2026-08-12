<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentHostRunnerContractV1;
use Ops\DeploymentHostRunnerV1;
use Ops\HostRunnerProcessResult;
use Ops\HostRunnerSystemAdapter;
use Ops\HelperBackedHostRunnerSystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerTerminalV1.php';

final class DeploymentHostRunnerV1Test extends TestCase
{
    private const BOOT = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    public function testPreflightConsumesRawSystemctlBytesAndNeverReservesOnCollisionOrUnknown(): void
    {
        $fixture = $this->fixture();
        $foreign = str_replace('LoadState=not-found', 'LoadState=loaded', $this->notFoundShow($fixture['launch']));
        $adapter = new ScriptedSystemAdapter([
            new HostRunnerProcessResult(0, $this->notFoundShow($fixture['launch']), ''),
            new HostRunnerProcessResult(0, $foreign, ''),
            new HostRunnerProcessResult(null, '', '', true),
        ]);
        $runner = new DeploymentHostRunnerV1($adapter);

        self::assertSame('available', $runner->preflightUnit($fixture['launch'], self::BOOT, self::BOOT . "\n"));
        self::assertSame('collision', $runner->preflightUnit($fixture['launch'], self::BOOT, self::BOOT . "\n"));
        self::assertSame('unknown', $runner->preflightUnit($fixture['launch'], self::BOOT, self::BOOT . "\n"));
        self::assertCount(3, $adapter->calls);
        foreach ($adapter->calls as $call) {
            self::assertSame(DeploymentHostRunnerContractV1::systemctlShowArgv($fixture['launch']['unit_name']), $call['argv']);
            self::assertSame(DeploymentHostRunnerV1::CONTROLLER_ENVIRONMENT, $call['environment']);
            self::assertSame(30, $call['timeout']);
        }
    }

    #[DataProvider('admissionOutcomeProvider')]
    public function testDurableReservationMakesEveryAdmissionOutcomeExactlyOnceAndObserveOnly(
        HostRunnerProcessResult|RuntimeException $outcome,
        string $expectedDisposition,
    ): void {
        $fixture = $this->fixture();
        $adapter = new ScriptedSystemAdapter([$outcome]);
        $runner = new DeploymentHostRunnerV1($adapter);

        $result = $runner->admitReservedUnit(
            $fixture['launch'],
            $fixture['binding'],
            self::BOOT . "\n",
            $fixture['input'],
            $fixture['request'],
            null,
            $fixture['script'],
            true,
        );

        self::assertSame($expectedDisposition, $result);
        self::assertCount(1, $adapter->calls);
        self::assertSame(
            DeploymentHostRunnerContractV1::systemdRunArgv(
                $fixture['launch'],
                $fixture['binding'],
                self::BOOT . "\n",
                $fixture['input'],
                $fixture['request'],
                null,
                $fixture['script'],
            ),
            $adapter->calls[0]['argv'],
        );
        self::assertSame(DeploymentHostRunnerV1::CONTROLLER_ENVIRONMENT, $adapter->calls[0]['environment']);
        self::assertSame(60, $adapter->calls[0]['timeout']);
    }

    /** @return iterable<string,array{HostRunnerProcessResult|RuntimeException,string}> */
    public static function admissionOutcomeProvider(): iterable
    {
        yield 'accepted by manager' => [new HostRunnerProcessResult(0, '', ''), 'observe_only'];
        yield 'nonzero after call' => [new HostRunnerProcessResult(1, '', ''), 'observe_only_reconciliation_required'];
        yield 'response lost' => [new HostRunnerProcessResult(null, '', '', true), 'observe_only_reconciliation_required'];
        yield 'adapter throws' => [new RuntimeException('secret transport detail'), 'observe_only_reconciliation_required'];
    }

    public function testObservationUsesRawParserAndKeepsSignalDistinctFromNormalExit143(): void
    {
        $fixture = $this->fixture();
        $normal143 = $this->loadedShow($fixture['launch'], 'failed', 'failed', 'exit-code', 1, 143);
        $signal15 = $this->loadedShow($fixture['launch'], 'failed', 'failed', 'signal', 2, 15);
        $adapter = new ScriptedSystemAdapter([
            new HostRunnerProcessResult(0, $normal143, ''),
            new HostRunnerProcessResult(0, $signal15, ''),
        ]);
        $runner = new DeploymentHostRunnerV1($adapter);

        $normal = $runner->observeUnit($fixture['launch'], self::BOOT . "\n");
        $signal = $runner->observeUnit($fixture['launch'], self::BOOT . "\n");
        $decoded = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation($normal['pinned_bytes'], $fixture['launch']);
        self::assertSame($normal143, $decoded['systemctl_show']);
        self::assertSame(
            ['unit_state' => 'failed', 'observed_exit_code' => 143, 'unit_invocation_id' => str_repeat('d', 32)],
            DeploymentHostRunnerContractV1::classifySystemdObservation($fixture['launch'], $normal['lookup']['loaded_observation']),
        );
        self::assertSame(
            ['unit_state' => 'killed', 'observed_exit_code' => null, 'unit_invocation_id' => str_repeat('d', 32)],
            DeploymentHostRunnerContractV1::classifySystemdObservation($fixture['launch'], $signal['lookup']['loaded_observation']),
        );
    }

    public function testNoSystemCommandRunsBeforeDurableReservationOrValidLaunch(): void
    {
        $fixture = $this->fixture();
        $adapter = new ScriptedSystemAdapter([new HostRunnerProcessResult(0, '', '')]);
        $runner = new DeploymentHostRunnerV1($adapter);
        try {
            $runner->admitReservedUnit($fixture['launch'], $fixture['binding'], self::BOOT . "\n", $fixture['input'], $fixture['request'], null, $fixture['script'], false);
            self::fail('Expected reservation guard.');
        } catch (RuntimeException $error) {
            self::assertSame('systemd admission requires the durable reservation boundary', $error->getMessage());
        }
        self::assertSame([], $adapter->calls);

        $fixture['launch']['intent_sha256'] = str_repeat('a', 64);
        try {
            $runner->preflightUnit($fixture['launch'], self::BOOT, self::BOOT . "\n");
            self::fail('Expected launch rejection.');
        } catch (RuntimeException) {
            self::assertSame([], $adapter->calls);
        }
    }

    public function testMalformedAndThrownObservationFailsClosedWithoutClassification(): void
    {
        $fixture = $this->fixture();
        $adapter = new ScriptedSystemAdapter([
            new RuntimeException('private transport'),
            new HostRunnerProcessResult(0, "malformed\n", ''),
            new RuntimeException('private transport'),
            new HostRunnerProcessResult(0, "malformed\n", ''),
        ]);
        $runner = new DeploymentHostRunnerV1($adapter);
        self::assertSame('unknown', $runner->preflightUnit($fixture['launch'], self::BOOT, self::BOOT . "\n"));
        self::assertSame('unknown', $runner->preflightUnit($fixture['launch'], self::BOOT, self::BOOT . "\n"));
        $thrown = $runner->observeUnit($fixture['launch'], self::BOOT . "\n");
        self::assertSame('transport_error', $thrown['lookup']['kind']);
        self::assertSame('transport_error', DeploymentHostRunnerContractV1::decodeUnitAbsence($thrown['pinned_bytes'])['kind']);
        $malformed = $runner->observeUnit($fixture['launch'], self::BOOT . "\n");
        self::assertSame('transport_error', $malformed['lookup']['kind']);
        self::assertSame('transport_error', DeploymentHostRunnerContractV1::decodeUnitAbsence($malformed['pinned_bytes'])['kind']);
    }

    public function testCorruptBootBytesRejectBeforeObservationCall(): void
    {
        $fixture = $this->fixture();
        $adapter = new ScriptedSystemAdapter([]);
        $runner = new DeploymentHostRunnerV1($adapter);
        foreach (['preflight', 'observe'] as $operation) {
            try {
                $operation === 'preflight'
                    ? $runner->preflightUnit($fixture['launch'], self::BOOT, "bad\n")
                    : $runner->observeUnit($fixture['launch'], "bad\n");
                self::fail('Expected boot-byte rejection.');
            } catch (RuntimeException) {
                self::assertSame([], $adapter->calls);
            }
        }
    }

    public function testRebootAbsenceWiringDistinguishesUnknownFromMissing(): void
    {
        $fixture = $this->fixture();
        $adapter = new ScriptedSystemAdapter([
            new HostRunnerProcessResult(0, $this->notFoundShow($fixture['launch']), ''),
            new HostRunnerProcessResult(0, $this->notFoundShow($fixture['launch']), ''),
        ]);
        $runner = new DeploymentHostRunnerV1($adapter);
        $same = $runner->observeUnit($fixture['launch'], self::BOOT . "\n");
        $changedBoot = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $changed = $runner->observeUnit($fixture['launch'], $changedBoot . "\n");
        self::assertSame('not_found', $same['lookup']['kind']);
        $sameAbsence = DeploymentHostRunnerContractV1::decodeUnitAbsence($same['pinned_bytes']);
        $changedAbsence = DeploymentHostRunnerContractV1::decodeUnitAbsence($changed['pinned_bytes']);
        unset($sameAbsence['schema'], $changedAbsence['schema']);
        self::assertSame('unknown', DeploymentHostRunnerContractV1::classifyUnitObservation($fixture['binding'], $sameAbsence));
        self::assertSame('missing', DeploymentHostRunnerContractV1::classifyUnitObservation($fixture['binding'], $changedAbsence));
    }

    public function testProductAdapterUsesFixedHelperCommandAndDecodesOnlyCanonicalBoundedResponse(): void
    {
        $captured = [];
        $adapter = new HelperBackedHostRunnerSystemAdapter(
            static function (array $command, string $payload, float $timeout) use (&$captured): array {
                $captured = compact('command', 'payload', 'timeout');
                return [
                    'exit_code' => 0,
                    'stdout' => json_encode([
                        'exit_code' => 0,
                        'stderr_base64' => base64_encode(''),
                        'stdout_base64' => base64_encode("raw\n"),
                        'transport_lost' => false,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
                ];
            },
        );
        $fixture = $this->fixture();
        $result = $adapter->run(
            DeploymentHostRunnerContractV1::systemctlShowArgv($fixture['launch']['unit_name']),
            DeploymentHostRunnerV1::CONTROLLER_ENVIRONMENT,
            30,
        );
        self::assertSame(0, $result->exitCode);
        self::assertSame("raw\n", $result->stdout);
        self::assertSame(125.0, $captured['timeout']);
        self::assertSame(['/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', '/usr/bin/python3', '-I', '-B'], array_slice($captured['command'], 0, 8));
        $payload = json_decode($captured['payload'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(30, $payload['timeout_seconds']);
        self::assertSame(DeploymentHostRunnerV1::CONTROLLER_ENVIRONMENT, $payload['environment']);
    }

    public function testProductAdapterRejectsTimedOutOrMalformedControllerWithoutLeakingOutput(): void
    {
        foreach ([
            ['exit_code' => 70, 'stdout' => 'partial secret'],
            ['exit_code' => 0, 'stdout' => str_repeat('x', 180_001)],
            ['exit_code' => 0, 'stdout' => "{}\n"],
        ] as $transportResult) {
            $adapter = new HelperBackedHostRunnerSystemAdapter(
                static fn(): array => $transportResult,
                0.05,
            );
            try {
                $adapter->run(['/bin/false'], DeploymentHostRunnerV1::CONTROLLER_ENVIRONMENT, 30);
                self::fail('Expected fixed controller failure.');
            } catch (RuntimeException $error) {
                self::assertStringStartsWith('host-runner controller', $error->getMessage());
                self::assertStringNotContainsString('partial secret', $error->getMessage());
            }
        }
    }

    public function testReservationPersistencePublishesJournalThenClaimThenStateBeforeAdmission(): void
    {
        $fixture = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $fixture['prior_events_bytes'];
        $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $fixture['prior_state_bytes'];
        $this->seedDeployAdmissionAuthority($storage);
        $persistence = new \Ops\HostRunnerReservationPersistence(
            $storage,
            null,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );

        $persistence->persist(
            $fixture['run_id'],
            $fixture['events_bytes'],
            $fixture['claim_bytes'],
            $fixture['state_bytes'],
        );

        self::assertSame([
            ['cow', 'runs/' . self::fixtureRunId() . '/events.jsonl', $fixture['events_bytes']],
            ['pin', 'active-run.json', $fixture['claim_bytes']],
            ['cow', 'runs/' . self::fixtureRunId() . '/state.json', $fixture['state_bytes']],
        ], $storage->operations);
    }

    #[DataProvider('reservationCrashStepProvider')]
    public function testReservationCrashPrefixesNeverReachAdmission(string $crashAfter, array $expectedLeaves): void
    {
        $fixture = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $fixture['prior_events_bytes'];
        $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $fixture['prior_state_bytes'];
        $this->seedDeployAdmissionAuthority($storage);
        $persistence = new \Ops\HostRunnerReservationPersistence(
            $storage,
            static function (string $step) use ($crashAfter): void {
                if ($step === $crashAfter) {
                    throw new RuntimeException('injected crash');
                }
            },
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        try {
            $persistence->persist($fixture['run_id'], $fixture['events_bytes'], $fixture['claim_bytes'], $fixture['state_bytes']);
            self::fail('Expected injected crash.');
        } catch (RuntimeException $error) {
            self::assertSame('injected crash', $error->getMessage());
        }
        self::assertSame($expectedLeaves, array_column($storage->operations, 1));
        $storage->operations = [];
        $bundle = $this->fixture();
        $adapter = new ScriptedSystemAdapter([
            new HostRunnerProcessResult(0, $this->notFoundShow($bundle['launch']), ''),
        ]);
        $orchestrator = new \Ops\HostRunnerStartOrchestrator($persistence, new DeploymentHostRunnerV1($adapter), new ScriptedBootReader());
        self::assertSame(
            'attach_observe_only',
            $orchestrator->resumeReserved(
                $fixture['run_id'],
                $fixture['events_bytes'],
                $fixture['claim_bytes'],
                $fixture['state_bytes'],
            ),
        );
        self::assertCount(1, $adapter->calls);
        self::assertSame('/bin/systemctl', $adapter->calls[0]['argv'][5]);
        self::assertNotContains('run', array_column($storage->operations, 0));
    }

    /** @return iterable<string,array{string,list<string>}> */
    public static function reservationCrashStepProvider(): iterable
    {
        $run = self::fixtureRunId();
        yield 'journal durable only' => ['reservation_journal_durable', ['runs/' . $run . '/events.jsonl']];
        yield 'claim durable after journal' => ['reservation_claim_durable', ['runs/' . $run . '/events.jsonl', 'active-run.json']];
        yield 'state durable after claim' => ['reservation_state_durable', ['runs/' . $run . '/events.jsonl', 'active-run.json', 'runs/' . $run . '/state.json']];
    }

    public function testReservationPersistenceRejectsOverwriteTruncationExtraRecordAndNonReservation(): void
    {
        $fixture = $this->reservationPersistenceFixture();
        foreach (['overwrite', 'truncate', 'extra', 'non_reservation'] as $case) {
            $storage = new RecordingHostRunnerStorage();
            $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $fixture['prior_events_bytes'];
            $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $fixture['prior_state_bytes'];
            $this->seedDeployAdmissionAuthority($storage);
            $events = $fixture['events_bytes'];
            $claim = $fixture['claim_bytes'];
            $state = $fixture['state_bytes'];
            if ($case === 'overwrite') {
                $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = str_replace('artifact_verified', 'capacity_passed', $fixture['prior_events_bytes']);
            } elseif ($case === 'truncate') {
                $events = $fixture['prior_events_bytes'];
            } elseif ($case === 'extra') {
                $events .= substr($events, strlen($fixture['prior_events_bytes']));
            } else {
                $decoded = DeploymentHostRunnerContractV1::decodeState($state);
                $decoded['state'] = 'post_gates_running';
                $decoded['active_action'] = 'none';
                $state = DeploymentHostRunnerContractV1::encodeFile($decoded);
            }
            try {
                (new \Ops\HostRunnerReservationPersistence($storage))->persist($fixture['run_id'], $events, $claim, $state);
                self::fail('Expected ' . $case . ' rejection.');
            } catch (RuntimeException) {
                self::assertSame([], $storage->operations, $case);
            }
        }
    }

    public function testReservationPersistenceRequiresExactPinnedAuthorityBeforeAnyWrite(): void
    {
        $fixture = $this->reservationPersistenceFixture();
        foreach (['missing', 'changed_launch', 'changed_binding'] as $case) {
            $storage = new RecordingHostRunnerStorage();
            $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $fixture['prior_events_bytes'];
            $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $fixture['prior_state_bytes'];
            if ($case !== 'missing') {
                $this->seedDeployAdmissionAuthority($storage);
                $leaf = 'runs/' . self::fixtureRunId() . '/' . ($case === 'changed_launch'
                    ? 'deploy-systemd-launch.json'
                    : 'deploy-unit-binding.json');
                $decoded = json_decode($storage->files[$leaf], true, 32, JSON_THROW_ON_ERROR);
                $decoded[$case === 'changed_launch' ? 'launch_nonce' : 'unit_manager_boot_id'] = $case === 'changed_launch'
                    ? str_repeat('4', 64)
                    : 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
                $storage->files[$leaf] = DeploymentHostRunnerContractV1::encodeFile($decoded);
            }
            try {
                (new \Ops\HostRunnerReservationPersistence($storage))->persist(
                    $fixture['run_id'], $fixture['events_bytes'], $fixture['claim_bytes'], $fixture['state_bytes'],
                );
                self::fail('Expected pinned authority rejection.');
            } catch (RuntimeException) {
                self::assertSame([], $storage->operations, $case);
            }
        }
    }

    public function testReservationCannotReplaceAnotherDurableActiveClaim(): void
    {
        $fixture = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $fixture['prior_events_bytes'];
        $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $fixture['prior_state_bytes'];
        $this->seedDeployAdmissionAuthority($storage);
        $otherClaim = DeploymentHostRunnerContractV1::decodeActiveRun($fixture['claim_bytes']);
        $otherClaim['run_id'] = '028f6f52-4c87-4d4e-8b19-6a66e6e1af25';
        $storage->files['active-run.json'] = DeploymentHostRunnerContractV1::encodeFile($otherClaim);
        try {
            (new \Ops\HostRunnerReservationPersistence($storage))->persist(
                $fixture['run_id'],
                $fixture['events_bytes'],
                $fixture['claim_bytes'],
                $fixture['state_bytes'],
            );
            self::fail('Expected active claim conflict.');
        } catch (RuntimeException $error) {
            self::assertSame('reservation conflicts with the durable active-run claim', $error->getMessage());
            self::assertSame([], $storage->operations);
        }
    }

    public function testAdmissionBundlePinsExactActionSpecificAuthoritiesBeforeReservation(): void
    {
        $fixture = $this->fixture();
        $storage = new RecordingHostRunnerStorage();
        $persistence = new \Ops\HostRunnerReservationPersistence(
            $storage,
            null,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        $persistence->pinAdmissionBundle(
            self::fixtureRunId(),
            'deploy',
            $fixture['request'],
            $fixture['input'],
            $fixture['launch'],
            $fixture['binding'],
        );
        self::assertSame([
            'healthz-token',
            'zero-surprise-dump-sql-gz',
            'predeploy-credentials',
            'canary-credentials',
            'incident-webhook',
            'runs/' . self::fixtureRunId() . '/request.json',
            'runs/' . self::fixtureRunId() . '/execution-input.json',
            'runs/' . self::fixtureRunId() . '/deploy-systemd-launch.json',
            'runs/' . self::fixtureRunId() . '/deploy-unit-binding.json',
        ], array_column($storage->operations, 1));
        $before = $storage->files;
        $changed = $fixture['launch'];
        $changed['launch_nonce'] = str_repeat('3', 64);
        try {
            $persistence->pinAdmissionBundle(self::fixtureRunId(), 'deploy', $fixture['request'], $fixture['input'], $changed, $fixture['binding']);
            self::fail('Expected launch replacement conflict.');
        } catch (RuntimeException) {
            self::assertSame($before, $storage->files);
        }
    }

    public function testBindingRefreshIsCanonicalOneWayAndActiveClaimClearIsExact(): void
    {
        $fixture = $this->fixture();
        $storage = new RecordingHostRunnerStorage();
        $leaf = 'runs/' . self::fixtureRunId() . '/deploy-unit-binding.json';
        $reserved = DeploymentHostRunnerContractV1::encodeFile($fixture['binding']);
        $observedBinding = $fixture['binding'];
        $observedBinding['unit_invocation_id'] = str_repeat('d', 32);
        $observedBinding['binding_state'] = 'observed';
        $observed = DeploymentHostRunnerContractV1::encodeFile($observedBinding);
        $storage->files[$leaf] = $reserved;
        $storage->refreshBinding($leaf, $reserved, $observed);
        self::assertSame($observed, $storage->files[$leaf]);
        try {
            $storage->refreshBinding($leaf, $reserved, $observed);
            self::fail('Expected binding CAS conflict.');
        } catch (RuntimeException) {
            self::assertSame($observed, $storage->files[$leaf]);
        }

        $persistence = $this->reservationPersistenceFixture();
        $storage->files['active-run.json'] = $persistence['claim_bytes'];
        try {
            $storage->clearActiveClaim(str_replace('deploy_running', 'rollback_running', $persistence['claim_bytes']));
            self::fail('Expected exact claim conflict.');
        } catch (RuntimeException) {
            self::assertArrayHasKey('active-run.json', $storage->files);
        }
        $storage->clearActiveClaim($persistence['claim_bytes']);
        self::assertArrayNotHasKey('active-run.json', $storage->files);

        $validatorStorage = new RecordingHostRunnerStorage();
        $validatorStorage->files[$leaf] = $reserved;
        $reconciliation = new \Ops\HostRunnerReconciliationPersistence($validatorStorage);
        $reconciliation->refreshBinding('deploy', $reserved, $observed);
        self::assertSame($observed, $validatorStorage->files[$leaf]);
        foreach ([
            " \n" . $observed,
            DeploymentHostRunnerContractV1::encodeFile([...$observedBinding, 'unit_invocation_id' => str_repeat('e', 32)]),
            DeploymentHostRunnerContractV1::encodeFile([...$observedBinding, 'binding_state' => 'reserved', 'unit_invocation_id' => null]),
        ] as $invalid) {
            try {
                $reconciliation->refreshBinding('deploy', $observed, $invalid);
                self::fail('Expected binding evolution rejection.');
            } catch (RuntimeException) {
                self::assertSame($observed, $validatorStorage->files[$leaf]);
            }
        }
    }

    public function testIntegratedStartPreflightsPinsPersistsThenCallsRunExactlyOnce(): void
    {
        $bundle = $this->fixture();
        $durable = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $durable['prior_events_bytes'];
        $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $durable['prior_state_bytes'];
        $adapter = new ScriptedSystemAdapter([
            new HostRunnerProcessResult(0, $this->notFoundShow($bundle['launch']), ''),
            new HostRunnerProcessResult(1, '', ''),
        ]);
        $orchestrator = new \Ops\HostRunnerStartOrchestrator(
            new \Ops\HostRunnerReservationPersistence($storage),
            new DeploymentHostRunnerV1($adapter),
            new ScriptedBootReader(),
        );
        self::assertSame('observe_only_reconciliation_required', $orchestrator->persistThenAdmit(
            self::fixtureRunId(),
            $durable['events_bytes'],
            $durable['claim_bytes'],
            $durable['state_bytes'],
            $bundle['launch'],
            $bundle['binding'],
            $bundle['input'],
            $bundle['request'],
            null,
            $bundle['script'],
        ));
        self::assertCount(2, $adapter->calls);
        self::assertSame('/bin/systemctl', $adapter->calls[0]['argv'][5]);
        self::assertSame('/usr/bin/systemd-run', $adapter->calls[1]['argv'][5]);
        self::assertSame([
            'healthz-token', 'zero-surprise-dump-sql-gz', 'predeploy-credentials', 'canary-credentials', 'incident-webhook',
            'request.json', 'execution-input.json', 'deploy-systemd-launch.json', 'deploy-unit-binding.json',
            'events.jsonl', 'active-run.json', 'state.json',
        ], array_map(static fn(array $operation): string => basename($operation[1]), $storage->operations));
    }

    public function testIntegratedCollisionNeverWritesOrRunsAndCrossBindingRejectsBeforeShow(): void
    {
        $bundle = $this->fixture();
        $durable = $this->reservationPersistenceFixture();
        $foreign = str_replace('LoadState=not-found', 'LoadState=loaded', $this->notFoundShow($bundle['launch']));
        $adapter = new ScriptedSystemAdapter([new HostRunnerProcessResult(0, $foreign, '')]);
        $storage = new RecordingHostRunnerStorage();
        $orchestrator = new \Ops\HostRunnerStartOrchestrator(
            new \Ops\HostRunnerReservationPersistence($storage),
            new DeploymentHostRunnerV1($adapter),
            new ScriptedBootReader(),
        );
        self::assertSame('collision', $orchestrator->persistThenAdmit(
            self::fixtureRunId(), $durable['events_bytes'], $durable['claim_bytes'], $durable['state_bytes'],
            $bundle['launch'], $bundle['binding'], $bundle['input'], $bundle['request'], null, $bundle['script'],
        ));
        self::assertSame([], $storage->operations);
        self::assertCount(1, $adapter->calls);

        $changed = $bundle['launch'];
        $changed['launch_nonce'] = str_repeat('3', 64);
        try {
            $orchestrator->persistThenAdmit(
                self::fixtureRunId(), $durable['events_bytes'], $durable['claim_bytes'], $durable['state_bytes'],
                $changed, $bundle['binding'], $bundle['input'], $bundle['request'], null, $bundle['script'],
            );
            self::fail('Expected cross-binding rejection.');
        } catch (RuntimeException) {
            self::assertCount(1, $adapter->calls);
            self::assertSame([], $storage->operations);
        }
    }

    public function testIntegratedUnknownPreflightNeverPersistsOrRuns(): void
    {
        $bundle = $this->fixture();
        $durable = $this->reservationPersistenceFixture();
        foreach ([
            new HostRunnerProcessResult(null, '', '', true),
            new HostRunnerProcessResult(0, "malformed\n", ''),
            new RuntimeException('private transport'),
        ] as $outcome) {
            $storage = new RecordingHostRunnerStorage();
            $adapter = new ScriptedSystemAdapter([$outcome]);
            $orchestrator = new \Ops\HostRunnerStartOrchestrator(
                new \Ops\HostRunnerReservationPersistence($storage),
                new DeploymentHostRunnerV1($adapter),
                new ScriptedBootReader(),
            );
            self::assertSame('unknown', $orchestrator->persistThenAdmit(
                self::fixtureRunId(), $durable['events_bytes'], $durable['claim_bytes'], $durable['state_bytes'],
                $bundle['launch'], $bundle['binding'], $bundle['input'], $bundle['request'], null, $bundle['script'],
            ));
            self::assertSame([], $storage->operations);
            self::assertCount(1, $adapter->calls);
            self::assertSame('/bin/systemctl', $adapter->calls[0]['argv'][5]);
        }
    }

    public function testBootRaceBeforeReservationNeverWritesAndAfterReservationNeverSpawns(): void
    {
        $bundle = $this->fixture();
        $durable = $this->reservationPersistenceFixture();
        $otherBoot = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd' . "\n";
        foreach (['during_show', 'before_run'] as $case) {
            $storage = new RecordingHostRunnerStorage();
            $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $durable['prior_events_bytes'];
            $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $durable['prior_state_bytes'];
            $adapter = new ScriptedSystemAdapter([
                new HostRunnerProcessResult(0, $this->notFoundShow($bundle['launch']), ''),
            ]);
            $boots = $case === 'during_show'
                ? [self::BOOT . "\n", $otherBoot]
                : [self::BOOT . "\n", self::BOOT . "\n", $otherBoot];
            $orchestrator = new \Ops\HostRunnerStartOrchestrator(
                new \Ops\HostRunnerReservationPersistence($storage),
                new DeploymentHostRunnerV1($adapter),
                new ScriptedBootReader($boots),
            );
            self::assertSame(
                $case === 'during_show' ? 'unknown' : 'observe_only_reconciliation_required',
                $orchestrator->persistThenAdmit(
                    self::fixtureRunId(), $durable['events_bytes'], $durable['claim_bytes'], $durable['state_bytes'],
                    $bundle['launch'], $bundle['binding'], $bundle['input'], $bundle['request'], null, $bundle['script'],
                ),
            );
            self::assertSame(0, count(array_filter(
                $adapter->calls,
                static fn(array $call): bool => $call['argv'][5] === '/usr/bin/systemd-run',
            )));
            if ($case === 'during_show') {
                self::assertSame([], $storage->operations);
            } else {
                self::assertArrayHasKey('active-run.json', $storage->files);
            }
        }
    }

    public function testObservationDurabilityPinsExactBytesBeforeBindingAndState(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $durable['state_bytes'];
        $this->seedDeployAdmissionAuthority($storage);
        $raw = $this->loadedShow($bundle['launch'], 'active', 'running', 'success', 0, 0);
        $adapter = new ScriptedSystemAdapter([new HostRunnerProcessResult(0, $raw, '')]);
        $runner = new DeploymentHostRunnerV1($adapter);
        $observed = $runner->observeUnit($bundle['launch'], self::BOOT . "\n");
        $observed['lookup'] = ['kind' => 'transport_error'];
        $persistence = new \Ops\HostRunnerReservationPersistence(
            $storage,
            null,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        $persistence->persistObservation(self::fixtureRunId(), $bundle['launch'], $observed);
        self::assertSame(['cow', 'binding-refresh', 'cow'], array_column($storage->operations, 0));
        self::assertSame('runs/' . self::fixtureRunId() . '/deploy-unit-observation.json', $storage->operations[0][1]);
        self::assertSame($observed['pinned_bytes'], $storage->operations[0][2]);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding(
            $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-binding.json'],
        );
        self::assertSame('observed', $binding['binding_state']);
        self::assertSame(str_repeat('d', 32), $binding['unit_invocation_id']);
        $state = DeploymentHostRunnerContractV1::decodeState(
            $storage->files['runs/' . self::fixtureRunId() . '/state.json'],
        );
        self::assertSame('running', $state['deploy']['unit_state']);
    }

    public function testObservationCrashAndIdentityOrClockFailuresNeverPublishStateAheadOfProof(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $raw = $this->loadedShow($bundle['launch'], 'active', 'running', 'success', 0, 0);
        $observed = (new DeploymentHostRunnerV1(new ScriptedSystemAdapter([
            new HostRunnerProcessResult(0, $raw, ''),
        ])))->observeUnit($bundle['launch'], self::BOOT . "\n");

        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $durable['state_bytes'];
        $this->seedDeployAdmissionAuthority($storage);
        $crashing = new \Ops\HostRunnerReservationPersistence(
            $storage,
            static function (string $step): void {
                if ($step === 'unit_observation_durable') { throw new RuntimeException('injected crash'); }
            },
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        try {
            $crashing->persistObservation(self::fixtureRunId(), $bundle['launch'], $observed);
            self::fail('Expected observation crash.');
        } catch (RuntimeException $error) {
            self::assertSame('injected crash', $error->getMessage());
        }
        self::assertSame($observed['pinned_bytes'], $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-observation.json']);
        self::assertSame(DeploymentHostRunnerContractV1::encodeFile($bundle['binding']), $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-binding.json']);
        self::assertSame($durable['state_bytes'], $storage->files['runs/' . self::fixtureRunId() . '/state.json']);

        $badBinding = $bundle['binding'];
        $badBinding['unit_manager_boot_id'] = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($badBinding);
        $storage->operations = [];
        foreach (['identity', 'clock'] as $case) {
            if ($case === 'clock') {
                $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($bundle['binding']);
            }
            try {
                (new \Ops\HostRunnerReservationPersistence(
                    $storage,
                    null,
                    new FixedHostRunnerClock($case === 'clock' ? '2026-08-12T10:00:10Z' : '2026-08-12T10:00:12Z'),
                ))->persistObservation(self::fixtureRunId(), $bundle['launch'], $observed);
                self::fail('Expected observation ' . $case . ' rejection.');
            } catch (RuntimeException) {
                self::assertSame([], $storage->operations, $case);
            }
        }
    }

    #[DataProvider('observationCrashStepProvider')]
    public function testObservationCrashRetryFinishesPinnedProofBeforeAnyFreshPoll(string $crashStep): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $durable['events_bytes'];
        $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $durable['state_bytes'];
        $storage->files['active-run.json'] = $durable['claim_bytes'];
        $this->seedDeployAdmissionAuthority($storage);
        $rawA = $this->loadedShow($bundle['launch'], 'active', 'running', 'success', 0, 0);
        $observedA = (new DeploymentHostRunnerV1(new ScriptedSystemAdapter([
            new HostRunnerProcessResult(0, $rawA, ''),
        ])))->observeUnit($bundle['launch'], self::BOOT . "\n");
        $crashing = new \Ops\HostRunnerReservationPersistence(
            $storage,
            static function (string $step) use ($crashStep): void {
                if ($step === $crashStep) { throw new RuntimeException('injected crash'); }
            },
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        try {
            $crashing->persistObservation(self::fixtureRunId(), $bundle['launch'], $observedA);
            self::fail('Expected observation prefix crash.');
        } catch (RuntimeException $error) {
            self::assertSame('injected crash', $error->getMessage());
        }
        $pinnedBeforeRetry = $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-observation.json'];
        $adapter = new ScriptedSystemAdapter([
            $crashStep === 'unit_state_durable'
                ? new HostRunnerProcessResult(0, $rawA, '')
                : new HostRunnerProcessResult(null, '', '', true),
        ]);
        $resume = new \Ops\HostRunnerStartOrchestrator(
            new \Ops\HostRunnerReservationPersistence(
                $storage,
                null,
                new FixedHostRunnerClock('2026-08-12T10:00:13Z'),
            ),
            new DeploymentHostRunnerV1($adapter),
            new ScriptedBootReader(),
        );
        $resumeStateBytes = $storage->files['runs/' . self::fixtureRunId() . '/state.json'];
        self::assertSame('attach_observe_only', $resume->resumeReserved(
            self::fixtureRunId(), $durable['events_bytes'], $durable['claim_bytes'], $resumeStateBytes,
        ));
        self::assertCount($crashStep === 'unit_state_durable' ? 1 : 0, $adapter->calls);
        self::assertSame($pinnedBeforeRetry, $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-observation.json']);
        $state = DeploymentHostRunnerContractV1::decodeState($storage->files['runs/' . self::fixtureRunId() . '/state.json']);
        self::assertSame('running', $state['deploy']['unit_state']);
        self::assertSame(str_repeat('d', 32), $state['deploy']['unit_invocation_id']);
        $adapter->addOutcome(new HostRunnerProcessResult(0, $rawA, ''));
        self::assertSame('attach_observe_only', $resume->resumeReserved(
            self::fixtureRunId(),
            $durable['events_bytes'],
            $durable['claim_bytes'],
            $storage->files['runs/' . self::fixtureRunId() . '/state.json'],
        ));
        self::assertSame(0, count(array_filter(
            $adapter->calls,
            static fn(array $call): bool => $call['argv'][5] === '/usr/bin/systemd-run',
        )));
    }

    /** @return iterable<string,array{string}> */
    public static function observationCrashStepProvider(): iterable
    {
        yield 'after observation' => ['unit_observation_durable'];
        yield 'after binding' => ['unit_binding_durable'];
        yield 'after state' => ['unit_state_durable'];
    }

    public function testReservationRecoveryRejectsWrongClaimIntentBeforeRepair(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $durable['events_bytes'];
        $this->seedDeployAdmissionAuthority($storage);
        $wrong = DeploymentHostRunnerContractV1::decodeActiveRun($durable['claim_bytes']);
        $wrong['intent_sha256'] = str_repeat('e', 64);
        try {
            (new \Ops\HostRunnerReservationPersistence($storage))->resumeAfterReservation(
                self::fixtureRunId(),
                $durable['events_bytes'],
                DeploymentHostRunnerContractV1::encodeFile($wrong),
                $durable['state_bytes'],
            );
            self::fail('Expected wrong claim intent rejection.');
        } catch (RuntimeException) {
            self::assertSame([], $storage->operations);
            self::assertArrayNotHasKey('active-run.json', $storage->files);
        }
    }

    public function testReservedCandidateScanIgnoresHistoryAndDerivesClaimOnlyFromJournal(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $this->seedDeployAdmissionAuthority($storage);
        $storage->candidates = [
            [
                'run_id' => '028f6f52-4c87-4d4e-8b19-6a66e6e1af25',
                'events_bytes' => $this->plannedEvents('028f6f52-4c87-4d4e-8b19-6a66e6e1af25'),
                'state_bytes' => null,
            ],
        ];
        $persistence = new \Ops\HostRunnerReservationPersistence(
            $storage,
            null,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        self::assertSame('no_reserved_run', $persistence->reconstructSoleReservedClaim());
        self::assertSame([], $storage->operations);

        $storage->candidates[] = [
            'run_id' => self::fixtureRunId(),
            'events_bytes' => $durable['events_bytes'],
            'state_bytes' => null,
        ];
        self::assertSame('reconstruct_claim_observe_only', $persistence->reconstructSoleReservedClaim());
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($storage->files['active-run.json']);
        self::assertSame('deploy_running', $claim['state']);
        self::assertSame(substr_count($durable['events_bytes'], "\n"), $claim['sequence']);
        self::assertSame(hash('sha256', $durable['events_bytes']), $claim['events_sha256']);
        self::assertSame('2026-08-12T10:00:10Z', $claim['claimed_at_utc']);
        $state = DeploymentHostRunnerContractV1::decodeState(
            $storage->files['runs/' . self::fixtureRunId() . '/state.json'],
        );
        self::assertSame('deploy_running', $state['state']);
        self::assertSame($claim['sequence'], $state['sequence']);
        self::assertSame($claim['events_sha256'], $state['events_sha256']);
        self::assertSame(['pin', 'cow'], array_column($storage->operations, 0));
    }

    public function testReservedCandidateScanAdvancesExactlyOneRecordStaleDeployCache(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $this->seedDeployAdmissionAuthority($storage);
        $storage->candidates = [[
            'run_id' => self::fixtureRunId(),
            'events_bytes' => $durable['events_bytes'],
            'state_bytes' => $durable['prior_state_bytes'],
        ]];
        self::assertSame(
            'reconstruct_claim_observe_only',
            (new \Ops\HostRunnerReservationPersistence($storage))->reconstructSoleReservedClaim(),
        );
        $state = DeploymentHostRunnerContractV1::decodeState(
            $storage->files['runs/' . self::fixtureRunId() . '/state.json'],
        );
        self::assertSame('deploy_running', $state['state']);
        self::assertSame(hash('sha256', $durable['events_bytes']), $state['events_sha256']);
        self::assertSame(['pin', 'cow'], array_column($storage->operations, 0));
    }

    public function testReservedCandidateScanRejectsMultipleOrCorruptCandidatesWithoutClaim(): void
    {
        $durable = $this->reservationPersistenceFixture();
        foreach (['multiple', 'corrupt'] as $case) {
            $storage = new RecordingHostRunnerStorage();
            $candidate = [
                'run_id' => self::fixtureRunId(),
                'events_bytes' => $durable['events_bytes'],
                'state_bytes' => $durable['state_bytes'],
            ];
            $storage->candidates = $case === 'multiple'
                ? [$candidate, $candidate]
                : [[...$candidate, 'events_bytes' => "not-json\n"]];
            try {
                (new \Ops\HostRunnerReservationPersistence($storage))->reconstructSoleReservedClaim();
                self::fail('Expected reserved scan rejection.');
            } catch (RuntimeException) {
                self::assertArrayNotHasKey('active-run.json', $storage->files);
                self::assertSame([], $storage->operations);
            }
        }
    }

    public function testReservedCandidateScanValidatesPinnedAuthorityBeforeClaimWrite(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->candidates = [[
            'run_id' => self::fixtureRunId(),
            'events_bytes' => $durable['events_bytes'],
            'state_bytes' => null,
        ]];
        try {
            (new \Ops\HostRunnerReservationPersistence($storage))->reconstructSoleReservedClaim();
            self::fail('Expected missing pinned authority rejection.');
        } catch (RuntimeException) {
            self::assertSame([], $storage->operations);
            self::assertArrayNotHasKey('active-run.json', $storage->files);
        }
    }

    public function testReservedCandidateScanPreservesCurrentObservedStateWithoutRewrite(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $storage = new RecordingHostRunnerStorage();
        $this->seedDeployAdmissionAuthority($storage);
        $state = DeploymentHostRunnerContractV1::decodeState($durable['state_bytes']);
        $binding = $bundle['binding'];
        $binding['binding_state'] = 'observed';
        $binding['unit_invocation_id'] = str_repeat('d', 32);
        $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($binding);
        $raw = $this->loadedShow($bundle['launch'], 'active', 'running', 'success', 0, 0);
        $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-observation.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
            'manager_boot_id' => self::BOOT,
            'systemctl_show' => $raw,
        ]);
        $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        $state['deploy']['unit_state'] = 'running';
        $stateBytes = DeploymentHostRunnerContractV1::encodeFile($state);
        $storage->candidates = [[
            'run_id' => self::fixtureRunId(), 'events_bytes' => $durable['events_bytes'], 'state_bytes' => $stateBytes,
        ]];
        self::assertSame(
            'reconstruct_claim_observe_only',
            (new \Ops\HostRunnerReservationPersistence($storage))->reconstructSoleReservedClaim(),
        );
        self::assertSame(['pin'], array_column($storage->operations, 0));
        self::assertArrayNotHasKey('runs/' . self::fixtureRunId() . '/state.json', $storage->files);
    }

    public function testCurrentPostGatesRunReconstructsClaimOnlyWithCompleteDeployAuthority(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $lines = explode("\n", rtrim($durable['events_bytes'], "\n"));
        $previous = json_decode($lines[array_key_last($lines)], true, 16, JSON_THROW_ON_ERROR);
        $lines[] = \Ops\DeploymentContractV1::canonicalJson([
            'schema' => \Ops\DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition', 'run_id' => self::fixtureRunId(),
            'sequence' => count($lines) + 1, 'recorded_at_utc' => '2026-08-12T10:00:12Z',
            'previous_state' => $previous['state'], 'state' => 'post_gates_running',
            'deploy_invocation_count' => 1, 'intent_sha256' => $previous['intent_sha256'],
            'exit_code' => 0, 'reason' => 'ok',
        ]);
        $eventsBytes = implode("\n", $lines) . "\n";
        $state = DeploymentHostRunnerContractV1::decodeState($durable['state_bytes']);
        $state['state'] = 'post_gates_running';
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $eventsBytes);
        $state['active_action'] = 'none';
        $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        $state['deploy']['unit_state'] = 'exited';
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        $state['state'] = 'post_gates_running';
        $state['active_action'] = 'none';
        $receipt = \Ops\DeployResultV1::canonicalJson(\Ops\DeployResultV1::create('succeeded', 0));
        $state['deploy']['receipt_sha256'] = hash('sha256', $receipt);
        $state['updated_at_utc'] = '2026-08-12T10:00:12Z';
        $storage = new RecordingHostRunnerStorage();
        $this->seedDeployAdmissionAuthority($storage);
        $binding = $bundle['binding'];
        $binding['binding_state'] = 'observed';
        $binding['unit_invocation_id'] = str_repeat('d', 32);
        $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($binding);
        $storage->files['runs/' . self::fixtureRunId() . '/deploy-unit-observation.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
            'manager_boot_id' => self::BOOT,
            'systemctl_show' => $this->loadedShow($bundle['launch'], 'active', 'exited', 'success', 1, 0),
        ]);
        $storage->files['runs/' . self::fixtureRunId() . '/deploy-result.json'] = $receipt;
        $storage->candidates = [[
            'run_id' => self::fixtureRunId(), 'events_bytes' => $eventsBytes,
            'state_bytes' => DeploymentHostRunnerContractV1::encodeFile($state),
        ]];
        self::assertSame('reconstruct_claim_observe_only', (new \Ops\HostRunnerReservationPersistence($storage))->reconstructSoleReservedClaim());
        self::assertSame(['pin'], array_column($storage->operations, 0));
        self::assertSame('post_gates_running', DeploymentHostRunnerContractV1::decodeActiveRun($storage->files['active-run.json'])['state']);
        unset($storage->files['active-run.json']);
        $storage->operations = [];
        unset($storage->files['runs/' . self::fixtureRunId() . '/deploy-result.json']);
        try {
            (new \Ops\HostRunnerReservationPersistence($storage))->reconstructSoleReservedClaim();
            self::fail('Expected incomplete post-gates authority rejection.');
        } catch (RuntimeException) {
            self::assertSame([], $storage->operations);
        }
    }

    public function testReservedCandidateScanAdvancesExactlyOneRecordStaleRollbackCache(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $receipt = \Ops\DeployResultV1::canonicalJson(\Ops\DeployResultV1::create('succeeded', 0));
        $lines = explode("\n", rtrim($durable['events_bytes'], "\n"));
        $previous = json_decode($lines[array_key_last($lines)], true, 16, JSON_THROW_ON_ERROR);
        $lines[] = \Ops\DeploymentContractV1::canonicalJson([
            'schema' => \Ops\DeploymentContractV1::RUN_SCHEMA, 'record_type' => 'transition',
            'run_id' => self::fixtureRunId(), 'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-12T10:00:12Z', 'previous_state' => $previous['state'],
            'state' => 'post_gates_running', 'deploy_invocation_count' => 1,
            'intent_sha256' => $previous['intent_sha256'], 'exit_code' => 0, 'reason' => 'ok',
        ]);
        $postEvents = implode("\n", $lines) . "\n";
        $postState = DeploymentHostRunnerContractV1::decodeState($durable['state_bytes']);
        $postState['state'] = 'post_gates_running';
        $postState['sequence'] = count($lines);
        $postState['events_sha256'] = hash('sha256', $postEvents);
        $postState['active_action'] = 'none';
        $postState['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        $postState['deploy']['unit_state'] = 'exited';
        $postState['deploy']['observed_exit_code'] = 0;
        $postState['deploy']['receipt_sha256'] = hash('sha256', $receipt);
        $report = [
            'schema' => DeploymentHostRunnerContractV1::POST_GATE_REPORT_SCHEMA,
            'run_id' => self::fixtureRunId(), 'intent_sha256' => $postState['intent_sha256'],
            'captured_at_utc' => '2026-08-12T10:00:13Z', 'subject' => 'deploy',
            'deploy_receipt_sha256' => hash('sha256', $receipt),
            'post_gates' => [
                'status' => 'failed', 'kuma_healthy_count' => 12, 'kuma_total_count' => 13,
                'runtime_config_passed' => true, 'services_passed' => true, 'endpoints_passed' => true,
                'logs_passed' => false, 'scanner_passed' => true, 'dormant_clean_passed' => true, 'passed' => false,
            ],
        ];
        $reportBytes = DeploymentHostRunnerContractV1::encodePostGateReport($report);
        $postState['post_gates'] = [
            'deploy_report_sha256' => hash('sha256', $reportBytes), 'deploy_submission_count' => 1,
            'deploy_verdict' => 'failed', 'rollback_report_sha256' => null,
            'rollback_submission_count' => 0, 'rollback_verdict' => 'not_submitted',
        ];
        $postState['updated_at_utc'] = '2026-08-12T10:00:13Z';
        $previous = json_decode($lines[array_key_last($lines)], true, 16, JSON_THROW_ON_ERROR);
        $lines[] = \Ops\DeploymentContractV1::canonicalJson([
            'schema' => \Ops\DeploymentContractV1::RUN_SCHEMA, 'record_type' => 'transition',
            'run_id' => self::fixtureRunId(), 'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-12T10:00:14Z', 'previous_state' => $previous['state'],
            'state' => 'rollback_running', 'deploy_invocation_count' => 1,
            'intent_sha256' => $previous['intent_sha256'], 'exit_code' => 0, 'reason' => 'ok',
        ]);
        $rollbackEvents = implode("\n", $lines) . "\n";
        $request = DeploymentHostRunnerContractV1::decodeRecoveryRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/recovery-request.json'),
        );
        $input = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => self::fixtureRunId(), 'intent_sha256' => $postState['intent_sha256'],
            'action' => 'rollback', 'parameters' => ['release_id' => $bundle['request']['release_id']],
        ];
        $launch = DeploymentHostRunnerContractV1::createSystemdLaunch($input, $request, $bundle['request'], $bundle['script'], static fn(): string => str_repeat("\x22", 32));
        $binding = [
            'schema' => DeploymentHostRunnerContractV1::UNIT_BINDING_SCHEMA, 'run_id' => self::fixtureRunId(),
            'intent_sha256' => $postState['intent_sha256'], 'action' => 'rollback',
            'unit_name' => $launch['unit_name'], 'unit_launch_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($launch)),
            'unit_manager_boot_id' => self::BOOT, 'unit_invocation_id' => null, 'binding_state' => 'reserved',
        ];
        $storage = new RecordingHostRunnerStorage();
        $this->seedDeployAdmissionAuthority($storage);
        $storage->files[$prefix . 'recovery-request.json'] = DeploymentHostRunnerContractV1::encodeFile($request);
        $storage->files[$prefix . 'recovery-execution-input.json'] = DeploymentHostRunnerContractV1::encodeExecutionInput($input);
        $storage->files[$prefix . 'rollback-systemd-launch.json'] = DeploymentHostRunnerContractV1::encodeFile($launch);
        $storage->files[$prefix . 'rollback-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($binding);
        $storage->files[$prefix . 'deploy-post-gate-report.json'] = $reportBytes;
        $storage->candidates = [[
            'run_id' => self::fixtureRunId(), 'events_bytes' => $rollbackEvents,
            'state_bytes' => DeploymentHostRunnerContractV1::encodeFile($postState),
        ]];
        self::assertSame('reconstruct_claim_observe_only', (new \Ops\HostRunnerReservationPersistence($storage))->reconstructSoleReservedClaim());
        $state = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        self::assertSame('rollback_running', $state['state']);
        self::assertSame(1, $state['rollback']['invocation_count']);
        self::assertSame(['pin', 'cow'], array_column($storage->operations, 0));
    }

    public function testDeployReceiptRequiresExactCanonicalBytesAndIndependentNormalExit(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        foreach ([0, 30, 31, 32, 143] as $exit) {
            $storage = new RecordingHostRunnerStorage();
            $prefix = 'runs/' . self::fixtureRunId() . '/';
            $this->seedDeployAdmissionAuthority($storage);
            $storage->files[$prefix . 'events.jsonl'] = $durable['events_bytes'];
            $storage->files['active-run.json'] = $durable['claim_bytes'];
            $state = DeploymentHostRunnerContractV1::decodeState($durable['state_bytes']);
            $state['deploy']['unit_state'] = $exit === 0 ? 'exited' : 'failed';
            $state['deploy']['observed_exit_code'] = $exit;
            $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
            $binding = $bundle['binding'];
            $binding['binding_state'] = 'observed';
            $binding['unit_invocation_id'] = str_repeat('d', 32);
            $storage->files[$prefix . 'state.json'] = DeploymentHostRunnerContractV1::encodeFile($state);
            $storage->files[$prefix . 'deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($binding);
            $storage->files[$prefix . 'deploy-unit-observation.json'] = DeploymentHostRunnerContractV1::encodeFile([
                'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
                'manager_boot_id' => self::BOOT,
                'systemctl_show' => $exit === 0
                    ? $this->loadedShow($bundle['launch'], 'active', 'exited', 'success', 1, 0)
                    : $this->loadedShow($bundle['launch'], 'failed', 'failed', 'exit-code', 1, $exit),
            ]);
            $outcome = match ($exit) {
                0 => 'succeeded', 30 => 'failed_pre_switch', 31 => 'rollback_failed_or_unverifiable',
                32 => 'switch_recovery_required', 143 => 'interrupted_pre_switch',
            };
            $receiptBytes = \Ops\DeployResultV1::canonicalJson(\Ops\DeployResultV1::create($outcome, $exit));
            $storage->files[$prefix . 'deploy-result.json'] = $receiptBytes;
            $result = (new \Ops\HostRunnerActionCompletion($storage))->requireDeployReceiptForStoppedUnit(self::fixtureRunId());
            self::assertSame($receiptBytes, $result['receipt_bytes']);
            self::assertSame($exit, $result['receipt']['exit_code']);

            $storage->files[$prefix . 'deploy-result.json'] = \Ops\DeployResultV1::canonicalJson(\Ops\DeployResultV1::create('succeeded', 0));
            if ($exit !== 0) {
                try {
                    (new \Ops\HostRunnerActionCompletion($storage))->requireDeployReceiptForStoppedUnit(self::fixtureRunId());
                    self::fail('Expected receipt/exit mismatch rejection.');
                } catch (RuntimeException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function testDeployReceiptNeverUsesLiveKilledMissingOrMalformedAuthority(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        foreach (['running', 'killed', 'missing', 'malformed'] as $case) {
            $storage = new RecordingHostRunnerStorage();
            $prefix = 'runs/' . self::fixtureRunId() . '/';
            $this->seedDeployAdmissionAuthority($storage);
            $storage->files[$prefix . 'events.jsonl'] = $durable['events_bytes'];
            $storage->files['active-run.json'] = $durable['claim_bytes'];
            $state = DeploymentHostRunnerContractV1::decodeState($durable['state_bytes']);
            $state['deploy']['unit_state'] = $case === 'running' ? 'running' : $case;
            $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
            $binding = $bundle['binding'];
            $binding['binding_state'] = 'observed'; $binding['unit_invocation_id'] = str_repeat('d', 32);
            $storage->files[$prefix . 'state.json'] = DeploymentHostRunnerContractV1::encodeFile($state);
            $storage->files[$prefix . 'deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($binding);
            $storage->files[$prefix . 'deploy-unit-observation.json'] = $case === 'malformed' ? "{}\n" : DeploymentHostRunnerContractV1::encodeFile([
                'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
                'manager_boot_id' => self::BOOT,
                'systemctl_show' => $this->loadedShow($bundle['launch'], 'active', 'running', 'success', 0, 0),
            ]);
            $storage->files[$prefix . 'deploy-result.json'] = \Ops\DeployResultV1::canonicalJson(\Ops\DeployResultV1::create('succeeded', 0));
            try {
                (new \Ops\HostRunnerActionCompletion($storage))->requireDeployReceiptForStoppedUnit(self::fixtureRunId());
                self::fail('Expected unsafe completion rejection.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSuccessfulReceiptAdvancesJournalThenStateAndRecoversJournalOnlyPrefix(): void
    {
        [$storage, $durable] = $this->successfulStoppedDeployStorage();
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $completion = new \Ops\HostRunnerActionCompletion(
            $storage,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );

        $response = $completion->acceptSucceededDeployReceipt(self::fixtureRunId());

        self::assertSame('attach_observe_only', $response['disposition']);
        self::assertSame('post_gates_running', $response['state']);
        self::assertSame(['cow', 'cow'], array_column(array_slice($storage->operations, -2), 0));
        self::assertSame(
            [$prefix . 'events.jsonl', $prefix . 'state.json'],
            array_column(array_slice($storage->operations, -2), 1),
        );
        $postEvents = $storage->files[$prefix . 'events.jsonl'];
        $postState = $storage->files[$prefix . 'state.json'];

        // Exact crash prefix: journal durable, previous deploy_running cache.
        $storage->files[$prefix . 'state.json'] = $durable['state_bytes'];
        $storage->operations = [];
        $replayed = $completion->acceptSucceededDeployReceipt(self::fixtureRunId());
        self::assertSame('attach_observe_only', $replayed['disposition']);
        self::assertSame($postEvents, $storage->files[$prefix . 'events.jsonl']);
        self::assertSame($postState, $storage->files[$prefix . 'state.json']);
        self::assertSame([['cow', $prefix . 'state.json', $postState]], $storage->operations);

        $storage->operations = [];
        self::assertSame('attach_observe_only', $completion->acceptSucceededDeployReceipt(self::fixtureRunId())['disposition']);
        self::assertSame([], $storage->operations);
    }

    public function testAcceptedDeployPostGateReportUpdatesOnlyTheDerivedStateCache(): void
    {
        [$storage] = $this->successfulStoppedDeployStorage();
        $completion = new \Ops\HostRunnerActionCompletion(
            $storage,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        $completion->acceptSucceededDeployReceipt(self::fixtureRunId());
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $state = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        $receipt = $storage->files[$prefix . 'deploy-result.json'];
        $report = [
            'schema' => DeploymentHostRunnerContractV1::POST_GATE_REPORT_SCHEMA,
            'run_id' => self::fixtureRunId(),
            'intent_sha256' => $state['intent_sha256'],
            'captured_at_utc' => '2026-08-12T10:00:13Z',
            'subject' => 'deploy',
            'deploy_receipt_sha256' => hash('sha256', $receipt),
            'post_gates' => [
                'status' => 'failed',
                'kuma_healthy_count' => 12,
                'kuma_total_count' => 13,
                'runtime_config_passed' => true,
                'services_passed' => true,
                'endpoints_passed' => true,
                'logs_passed' => false,
                'scanner_passed' => true,
                'dormant_clean_passed' => true,
                'passed' => false,
            ],
        ];
        $bytes = DeploymentHostRunnerContractV1::encodePostGateReport($report);
        $eventsBefore = $storage->files[$prefix . 'events.jsonl'];
        $storage->operations = [];

        $accepted = $completion->acceptDeployPostGateReport(self::fixtureRunId(), $bytes);

        self::assertSame('recovery_required', $accepted['disposition']);
        self::assertSame('failed', $accepted['state']['post_gates']['deploy_verdict']);
        self::assertSame(hash('sha256', $bytes), $accepted['state']['post_gates']['deploy_report_sha256']);
        self::assertSame($eventsBefore, $storage->files[$prefix . 'events.jsonl']);
        self::assertSame(['pin', 'cow'], array_column($storage->operations, 0));
        $storage->operations = [];
        $replay = $completion->acceptDeployPostGateReport(self::fixtureRunId(), $bytes);
        self::assertSame('attach_observe_only', $replay['disposition']);
        self::assertSame([], $storage->operations);
    }

    public function testSucceededDeployTerminalizesExactBundleThenClearsClaimAndReplaysWithoutFreshAuthority(): void
    {
        $storage = $this->successfulPassedPostGateStorage();
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $clock = new FixedTerminalClock();
        $timing = new NotObservedTimingPin();
        $storage->operations = [];

        $response = (new \Ops\HostRunnerTerminalPersistence($storage, $clock, $timing))
            ->terminalizeDeploy(self::fixtureRunId());

        self::assertSame('terminal', $response['disposition']);
        self::assertSame('succeeded', $response['state']);
        self::assertSame(0, $response['result_exit_code']);
        self::assertArrayNotHasKey('active-run.json', $storage->files);
        self::assertArrayHasKey($prefix . 'orchestrator-finish.json', $storage->files);
        self::assertArrayHasKey($prefix . 'deploy-child-observation.json', $storage->files);
        $terminalState = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        $terminalEvidence = json_decode($storage->files[$prefix . 'evidence.json'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(
            'terminal',
            \Ops\DeploymentContractV1::validateBundle(
                explode("\n", substr($storage->files[$prefix . 'events.jsonl'], 0, -1)),
                $terminalEvidence,
            )['recovery'],
        );
        self::assertSame(hash('sha256', $storage->files[$prefix . 'evidence.json']), $terminalState['evidence_sha256']);
        self::assertSame(1, $clock->nowCalls);
        self::assertSame(1, $timing->calls);
        self::assertSame(['claim-refresh', 'clear-exact'], array_column(array_slice($storage->operations, -2), 0));

        $operations = $storage->operations;
        $replay = (new \Ops\HostRunnerTerminalPersistence($storage, $clock, $timing))
            ->terminalizeDeploy(self::fixtureRunId());
        self::assertSame($response, $replay);
        self::assertSame($operations, $storage->operations);
        self::assertSame(1, $clock->nowCalls);
        self::assertSame(1, $timing->calls);
    }

    public function testTerminalCrashPrefixesResumeExactBytesWithoutFreshClockOrRespawnAuthority(): void
    {
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $source = $this->successfulPassedPostGateStorage();
        $initial = $source->files;
        $activeClaim = $initial['active-run.json'];
        (new \Ops\HostRunnerTerminalPersistence($source, new FixedTerminalClock(), new NotObservedTimingPin()))
            ->terminalizeDeploy(self::fixtureRunId());
        $terminal = $source->files;

        $prefixes = [
            'finish' => ['orchestrator-finish.json'],
            'child' => ['orchestrator-finish.json', 'deploy-child-observation.json'],
            'evidence' => ['orchestrator-finish.json', 'deploy-child-observation.json', 'evidence.json'],
            'journal' => ['orchestrator-finish.json', 'deploy-child-observation.json', 'evidence.json', 'events.jsonl'],
            'state' => [
                'orchestrator-finish.json', 'deploy-child-observation.json', 'evidence.json',
                'events.jsonl', 'state.json',
            ],
        ];
        foreach ($prefixes as $name => $leaves) {
            $storage = new RecordingHostRunnerStorage();
            $storage->files = $initial;
            foreach ($leaves as $leaf) {
                $storage->files[$prefix . $leaf] = $terminal[$prefix . $leaf];
            }
            $storage->files['active-run.json'] = $activeClaim;
            $clock = new FixedTerminalClock();
            $timing = new NotObservedTimingPin();

            $response = (new \Ops\HostRunnerTerminalPersistence($storage, $clock, $timing))
                ->terminalizeDeploy(self::fixtureRunId());

            self::assertSame('succeeded', $response['state'], $name);
            self::assertSame($terminal, $storage->files, $name);
            self::assertSame(0, $clock->nowCalls, $name);
            self::assertSame($name === 'state' ? 0 : 1, $timing->calls, $name);
        }
    }

    #[DataProvider('directDeployTerminalCases')]
    public function testStoppedNonSuccessReceiptsProduceValidatedTerminalBundles(
        string $outcome,
        int $exitCode,
        string $expectedState,
        string $expectedReason,
    ): void {
        $storage = $this->failedStoppedDeployStorage($outcome, $exitCode);
        $prefix = 'runs/' . self::fixtureRunId() . '/';

        $response = (new \Ops\HostRunnerTerminalPersistence(
            $storage,
            new FixedTerminalClock(),
            new NotObservedTimingPin(),
        ))->terminalizeDeploy(self::fixtureRunId());

        self::assertSame($expectedState, $response['state']);
        self::assertSame($exitCode, $response['result_exit_code']);
        self::assertSame($expectedReason, $response['result_reason']);
        $evidence = json_decode($storage->files[$prefix . 'evidence.json'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('not_observed', $evidence['post_gates']['status']);
        self::assertSame(
            'terminal',
            \Ops\DeploymentContractV1::validateBundle(
                explode("\n", substr($storage->files[$prefix . 'events.jsonl'], 0, -1)),
                $evidence,
            )['recovery'],
        );
        self::assertArrayNotHasKey('active-run.json', $storage->files);
    }

    /** @return iterable<string,array{string,int,string,string}> */
    public static function directDeployTerminalCases(): iterable
    {
        yield 'pre-switch failure' => ['failed_pre_switch', 30, 'failed_pre_switch', 'deploy_failed'];
        yield 'internal rollback succeeded' => [
            'internal_rollback_succeeded', 30, 'failed_post_switch_rollback_succeeded', 'deploy_failed',
        ];
        yield 'internal rollback failed' => [
            'rollback_failed_or_unverifiable', 31, 'failed_post_switch_rollback_failed', 'rollback_failed',
        ];
        yield 'switch recovery required' => [
            'switch_recovery_required', 32, 'failed_switch_recovery_required', 'switch_recovery_required',
        ];
        yield 'interrupted before switch' => ['interrupted_pre_switch', 143, 'failed_pre_switch', 'interrupted'];
    }

    #[DataProvider('dedicatedRollbackTerminalCases')]
    public function testDedicatedRollbackReportTerminalizesPreservedDeployAuthority(
        bool $reportPassed,
        string $expectedState,
        int $expectedExit,
        string $expectedReason,
    ): void {
        $storage = $this->completedRollbackStorage($reportPassed);
        $prefix = 'runs/' . self::fixtureRunId() . '/';

        $response = (new \Ops\HostRunnerTerminalPersistence(
            $storage,
            new FixedTerminalClock(),
            new NotObservedTimingPin(),
        ))->terminalizeRollback(self::fixtureRunId());

        self::assertSame('recovery', $response['action']);
        self::assertSame($expectedState, $response['state']);
        self::assertSame($expectedExit, $response['result_exit_code']);
        self::assertSame($expectedReason, $response['result_reason']);
        self::assertArrayNotHasKey('active-run.json', $storage->files);
        $evidence = json_decode($storage->files[$prefix . 'evidence.json'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame($reportPassed ? 'succeeded' : 'failed', $evidence['rollback']['status']);
        self::assertSame('failed', $evidence['post_gates']['status']);
        self::assertSame(
            'terminal',
            \Ops\DeploymentContractV1::validateBundle(
                explode("\n", substr($storage->files[$prefix . 'events.jsonl'], 0, -1)),
                $evidence,
            )['recovery'],
        );
    }

    /** @return iterable<string,array{bool,string,int,string}> */
    public static function dedicatedRollbackTerminalCases(): iterable
    {
        yield 'verified rollback' => [true, 'failed_post_switch_rollback_succeeded', 30, 'deploy_failed'];
        yield 'failed verification' => [false, 'failed_post_switch_rollback_failed', 31, 'rollback_failed'];
    }

    public function testDedicatedRollbackTerminalCrashPrefixesResumeExactBytes(): void
    {
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $source = $this->completedRollbackStorage(true);
        $initial = $source->files;
        $activeClaim = $initial['active-run.json'];
        (new \Ops\HostRunnerTerminalPersistence($source, new FixedTerminalClock(), new NotObservedTimingPin()))
            ->terminalizeRollback(self::fixtureRunId());
        $terminal = $source->files;
        foreach (
            [
                'finish' => ['orchestrator-finish.json'],
                'child' => ['orchestrator-finish.json', 'deploy-child-observation.json'],
                'evidence' => ['orchestrator-finish.json', 'deploy-child-observation.json', 'evidence.json'],
                'journal' => [
                    'orchestrator-finish.json', 'deploy-child-observation.json', 'evidence.json', 'events.jsonl',
                ],
                'state' => [
                    'orchestrator-finish.json', 'deploy-child-observation.json', 'evidence.json',
                    'events.jsonl', 'state.json',
                ],
            ]
            as $name => $leaves
        ) {
            $storage = new RecordingHostRunnerStorage();
            $storage->files = $initial;
            foreach ($leaves as $leaf) {
                $storage->files[$prefix . $leaf] = $terminal[$prefix . $leaf];
            }
            $storage->files['active-run.json'] = $activeClaim;
            $clock = new FixedTerminalClock();
            $timing = new NotObservedTimingPin();
            $response = (new \Ops\HostRunnerTerminalPersistence($storage, $clock, $timing))
                ->terminalizeRollback(self::fixtureRunId());
            self::assertSame('failed_post_switch_rollback_succeeded', $response['state'], $name);
            self::assertSame($terminal, $storage->files, $name);
            self::assertSame(0, $clock->nowCalls, $name);
            self::assertSame($name === 'state' ? 0 : 1, $timing->calls, $name);
        }
    }

    public function testPostGateSubmissionPinsFirstExactReportAndNeverReplacesIt(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $storage = new RecordingHostRunnerStorage();
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $state = DeploymentHostRunnerContractV1::decodeState($durable['state_bytes']);
        $state['deploy']['unit_state'] = 'exited';
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        $receipt = \Ops\DeployResultV1::canonicalJson(\Ops\DeployResultV1::create('succeeded', 0));
        $state['deploy']['receipt_sha256'] = hash('sha256', $receipt);
        $state['state'] = 'post_gates_running';
        $state['active_action'] = 'none';
        $lines = explode("\n", rtrim($durable['events_bytes'], "\n"));
        $previous = json_decode($lines[array_key_last($lines)], true, 16, JSON_THROW_ON_ERROR);
        $lines[] = \Ops\DeploymentContractV1::canonicalJson([
            'schema' => \Ops\DeploymentContractV1::RUN_SCHEMA, 'record_type' => 'transition',
            'run_id' => self::fixtureRunId(), 'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-12T10:00:12Z', 'previous_state' => $previous['state'],
            'state' => 'post_gates_running', 'deploy_invocation_count' => 1,
            'intent_sha256' => $previous['intent_sha256'], 'exit_code' => 0, 'reason' => 'ok',
        ]);
        $eventsBytes = implode("\n", $lines) . "\n";
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $eventsBytes);
        $state['updated_at_utc'] = '2026-08-12T10:00:12Z';
        $storage->files[$prefix . 'events.jsonl'] = $eventsBytes;
        $storage->files['active-run.json'] = $durable['claim_bytes'];
        $this->seedDeployAdmissionAuthority($storage);
        $binding = $bundle['binding'];
        $binding['binding_state'] = 'observed';
        $binding['unit_invocation_id'] = str_repeat('d', 32);
        $storage->files[$prefix . 'deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($binding);
        $storage->files[$prefix . 'deploy-unit-observation.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
            'manager_boot_id' => self::BOOT,
            'systemctl_show' => $this->loadedShow($bundle['launch'], 'active', 'exited', 'success', 1, 0),
        ]);
        $storage->files[$prefix . 'deploy-result.json'] = $receipt;
        $storage->files[$prefix . 'state.json'] = DeploymentHostRunnerContractV1::encodeFile($state);
        $report = [
            'schema' => DeploymentHostRunnerContractV1::POST_GATE_REPORT_SCHEMA,
            'run_id' => self::fixtureRunId(), 'intent_sha256' => $state['intent_sha256'],
            'captured_at_utc' => '2026-08-12T10:00:13Z', 'subject' => 'deploy',
            'deploy_receipt_sha256' => hash('sha256', $receipt),
            'post_gates' => [
                'status' => 'failed', 'kuma_healthy_count' => 12, 'kuma_total_count' => 13,
                'runtime_config_passed' => true, 'services_passed' => true, 'endpoints_passed' => true,
                'logs_passed' => false, 'scanner_passed' => true, 'dormant_clean_passed' => true, 'passed' => false,
            ],
        ];
        $bytes = DeploymentHostRunnerContractV1::encodePostGateReport($report);
        $completion = new \Ops\HostRunnerActionCompletion($storage);
        self::assertSame('recovery_required', $completion->submitPostGateReport(self::fixtureRunId(), $bytes));
        self::assertSame($bytes, $storage->files[$prefix . 'deploy-post-gate-report.json']);
        self::assertSame('recovery_required', $completion->submitPostGateReport(self::fixtureRunId(), $bytes));
        $changed = $report;
        $changed['captured_at_utc'] = '2026-08-12T10:00:14Z';
        try {
            $completion->submitPostGateReport(self::fixtureRunId(), DeploymentHostRunnerContractV1::encodePostGateReport($changed));
            self::fail('Expected changed post-gate report rejection.');
        } catch (RuntimeException) {
            self::assertSame($bytes, $storage->files[$prefix . 'deploy-post-gate-report.json']);
        }
    }

    public function testReservedCandidateClaimCrashRetriesDeterministicallyBeforeStateWithoutRespawn(): void
    {
        $durable = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $this->seedDeployAdmissionAuthority($storage);
        $storage->candidates = [[
            'run_id' => self::fixtureRunId(), 'events_bytes' => $durable['events_bytes'], 'state_bytes' => null,
        ]];
        $crashing = new \Ops\HostRunnerReservationPersistence(
            $storage,
            static function (string $step): void {
                if ($step === 'reconstruction_claim_durable') { throw new RuntimeException('injected crash'); }
            },
        );
        try {
            $crashing->reconstructSoleReservedClaim();
            self::fail('Expected reconstructed claim crash.');
        } catch (RuntimeException $error) {
            self::assertSame('injected crash', $error->getMessage());
        }
        $claimBytes = $storage->files['active-run.json'];
        self::assertArrayNotHasKey('runs/' . self::fixtureRunId() . '/state.json', $storage->files);
        $storage->operations = [];
        self::assertSame(
            'reconstruct_claim_observe_only',
            (new \Ops\HostRunnerReservationPersistence($storage))->reconstructSoleReservedClaim(),
        );
        self::assertSame($claimBytes, $storage->files['active-run.json']);
        self::assertArrayHasKey('runs/' . self::fixtureRunId() . '/state.json', $storage->files);
        self::assertSame(['pin', 'cow'], array_column($storage->operations, 0));
    }

    #[DataProvider('integratedAdmissionOutcomeProvider')]
    public function testIntegratedAdmissionOutcomesHaveOneRunAndRestartNeverRespawns(
        HostRunnerProcessResult|RuntimeException $outcome,
        string $expectedDisposition,
    ): void {
        $bundle = $this->fixture();
        $durable = $this->reservationPersistenceFixture();
        $storage = new RecordingHostRunnerStorage();
        $storage->files['runs/' . self::fixtureRunId() . '/events.jsonl'] = $durable['prior_events_bytes'];
        $storage->files['runs/' . self::fixtureRunId() . '/state.json'] = $durable['prior_state_bytes'];
        $adapter = new ScriptedSystemAdapter([
            new HostRunnerProcessResult(0, $this->notFoundShow($bundle['launch']), ''),
            $outcome,
        ]);
        $persistence = new \Ops\HostRunnerReservationPersistence(
            $storage,
            null,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        $orchestrator = new \Ops\HostRunnerStartOrchestrator($persistence, new DeploymentHostRunnerV1($adapter), new ScriptedBootReader());
        self::assertSame($expectedDisposition, $orchestrator->persistThenAdmit(
            self::fixtureRunId(), $durable['events_bytes'], $durable['claim_bytes'], $durable['state_bytes'],
            $bundle['launch'], $bundle['binding'], $bundle['input'], $bundle['request'], null, $bundle['script'],
        ));
        self::assertCount(2, $adapter->calls);
        $adapter->addOutcome(new HostRunnerProcessResult(0, $this->notFoundShow($bundle['launch']), ''));
        self::assertSame('attach_observe_only', $orchestrator->resumeReserved(
            self::fixtureRunId(), $durable['events_bytes'], $durable['claim_bytes'], $durable['state_bytes'],
        ));
        self::assertCount(3, $adapter->calls);
        self::assertSame(1, count(array_filter(
            $adapter->calls,
            static fn(array $call): bool => $call['argv'][5] === '/usr/bin/systemd-run',
        )));
    }

    /** @return iterable<string,array{HostRunnerProcessResult|RuntimeException,string}> */
    public static function integratedAdmissionOutcomeProvider(): iterable
    {
        yield 'manager accepted' => [new HostRunnerProcessResult(0, '', ''), 'observe_only'];
        yield 'manager nonzero' => [new HostRunnerProcessResult(1, '', ''), 'observe_only_reconciliation_required'];
        yield 'response lost' => [new HostRunnerProcessResult(null, '', '', true), 'observe_only_reconciliation_required'];
        yield 'adapter throws' => [new RuntimeException('private'), 'observe_only_reconciliation_required'];
    }

    /** @return array{run_id:string,prior_events_bytes:string,prior_state_bytes:string,events_bytes:string,claim_bytes:string,state_bytes:string} */
    private function reservationPersistenceFixture(): array
    {
        $runId = self::fixtureRunId();
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $intentSha = $request['intent_sha256'];
        $lines = [\Ops\DeploymentContractV1::canonicalJson(\Ops\DeploymentContractV1::createIntentRecord(
            $runId,
            '2026-08-12T10:00:00Z',
            $request['expected_commit'],
            $request['release_id'],
            $request['traffic_mode'],
        ))];
        foreach (array_slice(\Ops\DeploymentContractV1::PROGRESS_STATES, 1) as $lifecycle) {
            $previous = json_decode($lines[array_key_last($lines)], true, 16, JSON_THROW_ON_ERROR);
            $lines[] = \Ops\DeploymentContractV1::canonicalJson([
                'schema' => \Ops\DeploymentContractV1::RUN_SCHEMA,
                'record_type' => 'transition',
                'run_id' => $runId,
                'sequence' => count($lines) + 1,
                'recorded_at_utc' => sprintf('2026-08-12T10:00:%02dZ', count($lines)),
                'previous_state' => $previous['state'],
                'state' => $lifecycle,
                'deploy_invocation_count' => $lifecycle === 'deploy_running' ? 1 : 0,
                'intent_sha256' => $intentSha,
                'exit_code' => 0,
                'reason' => 'ok',
            ]);
            if ($lifecycle === 'deploy_running') {
                break;
            }
        }
        $eventsBytes = implode("\n", $lines) . "\n";
        $priorEventsBytes = implode("\n", array_slice($lines, 0, -1)) . "\n";
        $eventsSha = hash('sha256', $eventsBytes);
        $admission = $this->fixture();
        $unitName = $admission['launch']['unit_name'];
        $sha = DeploymentHostRunnerContractV1::fileSha256(
            DeploymentHostRunnerContractV1::encodeFile($admission['launch']),
        );
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $intentSha,
            'state' => 'deploy_running',
            'sequence' => count($lines),
            'events_sha256' => $eventsSha,
            'claimed_at_utc' => '2026-08-12T10:00:00Z',
        ];
        $state = [
            'schema' => DeploymentHostRunnerContractV1::STATE_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $intentSha,
            'state' => 'deploy_running',
            'sequence' => count($lines),
            'events_sha256' => $eventsSha,
            'active_action' => 'deploy',
            'deploy' => [
                'request_sha256' => DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($request)),
                'execution_input_sha256' => DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeExecutionInput($input)),
                'invocation_count' => 1,
                'unit_name' => $unitName,
                'unit_launch_sha256' => $sha,
                'unit_manager_boot_id' => self::BOOT,
                'unit_invocation_id' => null,
                'unit_missing_observed_boot_id' => null,
                'unit_state' => 'starting',
                'observed_exit_code' => null,
                'receipt_sha256' => null,
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
            'updated_at_utc' => '2026-08-12T10:00:11Z',
        ];
        $priorState = $state;
        $priorState['state'] = 'artifact_verified';
        $priorState['sequence'] = count($lines) - 1;
        $priorState['events_sha256'] = hash('sha256', $priorEventsBytes);
        $priorState['active_action'] = 'none';
        $priorState['deploy']['execution_input_sha256'] = null;
        $priorState['deploy']['invocation_count'] = 0;
        $priorState['deploy']['unit_name'] = null;
        $priorState['deploy']['unit_launch_sha256'] = null;
        $priorState['deploy']['unit_manager_boot_id'] = null;
        $priorState['deploy']['unit_state'] = 'not_created';
        $priorState['updated_at_utc'] = '2026-08-12T10:00:10Z';

        return [
            'run_id' => $runId,
            'prior_events_bytes' => $priorEventsBytes,
            'prior_state_bytes' => DeploymentHostRunnerContractV1::encodeFile($priorState),
            'events_bytes' => $eventsBytes,
            'claim_bytes' => DeploymentHostRunnerContractV1::encodeFile($claim),
            'state_bytes' => DeploymentHostRunnerContractV1::encodeFile($state),
        ];
    }

    private function plannedEvents(string $runId): string
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        return \Ops\DeploymentContractV1::canonicalJson(\Ops\DeploymentContractV1::createIntentRecord(
            $runId,
            '2026-08-12T10:00:00Z',
            $request['expected_commit'],
            $request['release_id'],
            $request['traffic_mode'],
        )) . "\n";
    }

    private function successfulPassedPostGateStorage(): RecordingHostRunnerStorage
    {
        [$storage] = $this->successfulStoppedDeployStorage();
        $this->addPassedPredeployAuthority($storage);
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $completion = new \Ops\HostRunnerActionCompletion(
            $storage,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        $completion->acceptSucceededDeployReceipt(self::fixtureRunId());
        $state = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        $receiptBytes = $storage->files[$prefix . 'deploy-result.json'];
        $completion->acceptDeployPostGateReport(
            self::fixtureRunId(),
            DeploymentHostRunnerContractV1::encodePostGateReport([
                'schema' => DeploymentHostRunnerContractV1::POST_GATE_REPORT_SCHEMA,
                'run_id' => self::fixtureRunId(),
                'intent_sha256' => $state['intent_sha256'],
                'captured_at_utc' => '2026-08-12T10:00:13Z',
                'subject' => 'deploy',
                'deploy_receipt_sha256' => hash('sha256', $receiptBytes),
                'post_gates' => [
                    'status' => 'passed', 'kuma_healthy_count' => 13, 'kuma_total_count' => 13,
                    'runtime_config_passed' => true, 'services_passed' => true,
                    'endpoints_passed' => true, 'logs_passed' => true,
                    'scanner_passed' => true, 'dormant_clean_passed' => true, 'passed' => true,
                ],
            ]),
        );
        return $storage;
    }

    private function failedStoppedDeployStorage(string $outcome, int $exitCode): RecordingHostRunnerStorage
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $storage = new RecordingHostRunnerStorage();
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $this->seedDeployAdmissionAuthority($storage);
        $storage->files[$prefix . 'events.jsonl'] = $durable['events_bytes'];
        $storage->files['active-run.json'] = $durable['claim_bytes'];
        $state = DeploymentHostRunnerContractV1::decodeState($durable['state_bytes']);
        $state['deploy']['unit_state'] = 'failed';
        $state['deploy']['observed_exit_code'] = $exitCode;
        $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        $binding = $bundle['binding'];
        $binding['binding_state'] = 'observed';
        $binding['unit_invocation_id'] = str_repeat('d', 32);
        $storage->files[$prefix . 'state.json'] = DeploymentHostRunnerContractV1::encodeFile($state);
        $storage->files[$prefix . 'deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($binding);
        $storage->files[$prefix . 'deploy-unit-observation.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
            'manager_boot_id' => self::BOOT,
            'systemctl_show' => $this->loadedShow($bundle['launch'], 'failed', 'failed', 'exit-code', 1, $exitCode),
        ]);
        $storage->files[$prefix . 'deploy-result.json'] = \Ops\DeployResultV1::canonicalJson(
            \Ops\DeployResultV1::create($outcome, $exitCode),
        );
        $this->addPassedPredeployAuthority($storage);
        return $storage;
    }

    private function completedRollbackStorage(bool $reportPassed): RecordingHostRunnerStorage
    {
        [$storage] = $this->successfulStoppedDeployStorage();
        $this->addPassedPredeployAuthority($storage);
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $bundle = $this->fixture();
        $completion = new \Ops\HostRunnerActionCompletion(
            $storage,
            new FixedHostRunnerClock('2026-08-12T10:00:12Z'),
        );
        $completion->acceptSucceededDeployReceipt(self::fixtureRunId());
        $state = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        $receiptBytes = $storage->files[$prefix . 'deploy-result.json'];
        $deployReport = [
            'schema' => DeploymentHostRunnerContractV1::POST_GATE_REPORT_SCHEMA,
            'run_id' => self::fixtureRunId(),
            'intent_sha256' => $state['intent_sha256'],
            'captured_at_utc' => '2026-08-12T10:00:13Z',
            'subject' => 'deploy',
            'deploy_receipt_sha256' => hash('sha256', $receiptBytes),
            'post_gates' => [
                'status' => 'failed', 'kuma_healthy_count' => 12, 'kuma_total_count' => 13,
                'runtime_config_passed' => true, 'services_passed' => true,
                'endpoints_passed' => true, 'logs_passed' => false,
                'scanner_passed' => true, 'dormant_clean_passed' => true, 'passed' => false,
            ],
        ];
        $deployReportBytes = DeploymentHostRunnerContractV1::encodePostGateReport($deployReport);
        $completion->acceptDeployPostGateReport(self::fixtureRunId(), $deployReportBytes);
        $state = DeploymentHostRunnerContractV1::decodeState($storage->files[$prefix . 'state.json']);
        $recoveryRequest = DeploymentHostRunnerContractV1::decodeRecoveryRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/recovery-request.json'),
        );
        $recoveryInput = [
            'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
            'run_id' => self::fixtureRunId(),
            'intent_sha256' => $state['intent_sha256'],
            'action' => 'rollback',
            'parameters' => ['release_id' => $bundle['request']['release_id']],
        ];
        $rollbackLaunch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $recoveryInput,
            $recoveryRequest,
            $bundle['request'],
            $bundle['script'],
            static fn(): string => str_repeat("\x22", 32),
        );
        $rollbackBinding = [
            'schema' => DeploymentHostRunnerContractV1::UNIT_BINDING_SCHEMA,
            'run_id' => self::fixtureRunId(),
            'intent_sha256' => $state['intent_sha256'],
            'action' => 'rollback',
            'unit_name' => $rollbackLaunch['unit_name'],
            'unit_launch_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($rollbackLaunch)),
            'unit_manager_boot_id' => self::BOOT,
            'unit_invocation_id' => str_repeat('e', 32),
            'binding_state' => 'observed',
        ];
        $lines = explode("\n", substr($storage->files[$prefix . 'events.jsonl'], 0, -1));
        $lines[] = \Ops\DeploymentContractV1::canonicalJson([
            'schema' => \Ops\DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::fixtureRunId(),
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-12T10:00:14Z',
            'previous_state' => 'post_gates_running',
            'state' => 'rollback_running',
            'deploy_invocation_count' => 1,
            'intent_sha256' => $state['intent_sha256'],
            'exit_code' => 0,
            'reason' => 'ok',
        ]);
        $eventsBytes = implode("\n", $lines) . "\n";
        $state['state'] = 'rollback_running';
        $state['sequence'] = count($lines);
        $state['events_sha256'] = hash('sha256', $eventsBytes);
        $state['active_action'] = 'rollback';
        $state['rollback'] = [
            'request_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($recoveryRequest)),
            'execution_input_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeExecutionInput($recoveryInput)),
            'invocation_count' => 1,
            'unit_name' => $rollbackLaunch['unit_name'],
            'unit_launch_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($rollbackLaunch)),
            'unit_manager_boot_id' => self::BOOT,
            'unit_invocation_id' => str_repeat('e', 32),
            'unit_missing_observed_boot_id' => null,
            'unit_state' => 'exited',
            'observed_exit_code' => 0,
            'verdict' => 'verification_pending',
        ];
        $state['updated_at_utc'] = '2026-08-12T10:00:15Z';
        $storage->files[$prefix . 'events.jsonl'] = $eventsBytes;
        $storage->files[$prefix . 'state.json'] = DeploymentHostRunnerContractV1::encodeFile($state);
        $storage->files[$prefix . 'recovery-request.json'] = DeploymentHostRunnerContractV1::encodeFile($recoveryRequest);
        $storage->files[$prefix . 'recovery-execution-input.json'] = DeploymentHostRunnerContractV1::encodeExecutionInput($recoveryInput);
        $storage->files[$prefix . 'rollback-systemd-launch.json'] = DeploymentHostRunnerContractV1::encodeFile($rollbackLaunch);
        $storage->files[$prefix . 'rollback-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($rollbackBinding);
        $storage->files[$prefix . 'rollback-unit-observation.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
            'manager_boot_id' => self::BOOT,
            'systemctl_show' => $this->loadedRollbackShow($rollbackLaunch, 0),
        ]);
        $storage->files['active-run.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => self::fixtureRunId(),
            'intent_sha256' => $state['intent_sha256'],
            'state' => 'rollback_running',
            'sequence' => $state['sequence'],
            'events_sha256' => $state['events_sha256'],
            'claimed_at_utc' => '2026-08-12T10:00:14Z',
        ]);
        $rollbackPostGates = $deployReport['post_gates'];
        $rollbackPostGates['status'] = $reportPassed ? 'passed' : 'failed';
        $rollbackPostGates['kuma_healthy_count'] = $reportPassed ? 13 : 12;
        $rollbackPostGates['logs_passed'] = $reportPassed;
        $rollbackPostGates['passed'] = $reportPassed;
        $completion->acceptRollbackPostGateReport(
            self::fixtureRunId(),
            DeploymentHostRunnerContractV1::encodePostGateReport([
                'schema' => DeploymentHostRunnerContractV1::POST_GATE_REPORT_SCHEMA,
                'run_id' => self::fixtureRunId(),
                'intent_sha256' => $state['intent_sha256'],
                'captured_at_utc' => '2026-08-12T10:00:16Z',
                'subject' => 'rollback',
                'deploy_receipt_sha256' => null,
                'post_gates' => $rollbackPostGates,
            ]),
        );
        return $storage;
    }

    /** @param array<string,mixed> $launch */
    private function loadedRollbackShow(array $launch, int $exitCode): string
    {
        $properties = DeploymentHostRunnerContractV1::observedUnitProperties(
            'rollback',
            DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch)),
        );
        return implode("\n", [
            'Id=' . $launch['unit_name'], 'LoadState=loaded', 'ActiveState=active', 'SubState=exited',
            'Result=success', 'ExecMainCode=1', 'ExecMainStatus=' . $exitCode,
            'InvocationID=' . str_repeat('e', 32), 'Description=' . $properties['Description'],
            'Transient=yes', 'Type=' . $properties['Type'], 'RemainAfterExit=' . $properties['RemainAfterExit'],
            'UMask=' . $properties['UMask'], 'KillMode=' . $properties['KillMode'], 'Restart=' . $properties['Restart'],
            'RuntimeMaxUSec=' . $properties['RuntimeMaxUSec'], 'TimeoutStopUSec=' . $properties['TimeoutStopUSec'],
            'StandardInput=' . $properties['StandardInput'], 'StandardOutput=' . $properties['StandardOutput'],
            'StandardError=' . $properties['StandardError'],
        ]) . "\n";
    }

    private function addPassedPredeployAuthority(RecordingHostRunnerStorage $storage): void
    {
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $request = $this->fixture()['request'];
        $storage->files[$prefix . 'predeploy-evidence.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => \Ops\DeploymentEvidenceAuthorityV1::PREDEPLOY_ASSEMBLY_SCHEMA,
            'status' => 'passed',
            'exit_code' => 0,
            'reason' => 'ok',
            'sections' => $this->passedPredeploySections($request),
        ]);
        $storage->files[$prefix . 'orchestrator-start.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => \Ops\DeploymentEvidenceAuthorityV1::ORCHESTRATOR_START_SCHEMA,
            'run_id' => self::fixtureRunId(),
            'started_at_utc' => '2026-08-12T10:00:00Z',
            'boot_id' => self::BOOT,
            'monotonic_ns' => 1_000_000_000,
        ]);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function passedPredeploySections(array $request): array
    {
        $sha = str_repeat('b', 64);
        $counts = array_fill_keys(\Ops\DeploymentContractV1::TRAFFIC_COUNT_KEYS, 0);
        $counts['documented_health'] = 1;
        $counts['lines_seen'] = 1;
        $counts['lines_in_window'] = 1;
        $counts['total'] = 1;
        return [
            'expected_commit' => [
                'expected' => $request['expected_commit'],
                'observed' => $request['expected_commit'],
                'verified' => true,
            ],
            'traffic_gate' => [
                'status' => 'passed', 'report_sha256' => $sha, 'schema' => 'traffic_gate.v1',
                'producer_sha256' => $sha, 'policy_version' => 'traffic_gate_policy.v1',
                'catalog_version' => '2026-08-09.1', 'purpose' => 'deploy', 'mode' => 'normal',
                'window_start_epoch' => 1, 'window_end_epoch' => 91, 'window_seconds' => 90,
                'log_set_sha256' => $sha, 'rotation_complete' => true, 'parse_complete' => true,
                'evidence_complete' => true, 'decision' => 'allow', 'exit_code' => 0, 'counts' => $counts,
            ],
            'dump' => [
                'status' => 'passed', 'policy' => \Ops\DeploymentContractV1::DUMP_POLICY,
                'age_seconds' => 60, 'max_age_seconds' => 14400, 'sha256' => $sha,
                'sha256_verified' => true, 'gzip_verified' => true, 'restore_verified' => true,
            ],
            'capacity' => [
                'status' => 'passed', 'available_bytes' => 8_000_000_000,
                'projected_required_bytes' => 1_000_000_000, 'available_inodes' => 8_000_000,
                'stage_inode_count' => 999_904, 'restore_inode_count' => 32, 'inode_headroom' => 64,
                'projected_required_inodes' => 1_000_000, 'observed_percent' => 81,
                'projected_percent' => 84,
                'max_used_percent' => \Ops\DeploymentContractV1::MAX_CAPACITY_USED_PERCENT,
                'passed' => true,
            ],
            'artifact' => [
                'status' => 'passed', 'expectation' => \Ops\DeploymentContractV1::ARTIFACT_EXPECTATION,
                'local_sha256' => $sha, 'remote_sha256' => $sha, 'manifest_sha256' => $sha,
                'host_script_sha256' => $sha, 'artifact_script_sha256' => $sha, 'verified' => true,
            ],
        ];
    }

    /** @return array{RecordingHostRunnerStorage,array<string,string>} */
    private function successfulStoppedDeployStorage(): array
    {
        $durable = $this->reservationPersistenceFixture();
        $bundle = $this->fixture();
        $storage = new RecordingHostRunnerStorage();
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $this->seedDeployAdmissionAuthority($storage);
        $storage->files[$prefix . 'events.jsonl'] = $durable['events_bytes'];
        $storage->files['active-run.json'] = $durable['claim_bytes'];
        $state = DeploymentHostRunnerContractV1::decodeState($durable['state_bytes']);
        $state['deploy']['unit_state'] = 'exited';
        $state['deploy']['observed_exit_code'] = 0;
        $state['deploy']['unit_invocation_id'] = str_repeat('d', 32);
        $binding = $bundle['binding'];
        $binding['binding_state'] = 'observed';
        $binding['unit_invocation_id'] = str_repeat('d', 32);
        $storage->files[$prefix . 'state.json'] = DeploymentHostRunnerContractV1::encodeFile($state);
        $durable['state_bytes'] = $storage->files[$prefix . 'state.json'];
        $storage->files[$prefix . 'deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($binding);
        $storage->files[$prefix . 'deploy-unit-observation.json'] = DeploymentHostRunnerContractV1::encodeFile([
            'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
            'manager_boot_id' => self::BOOT,
            'systemctl_show' => $this->loadedShow($bundle['launch'], 'active', 'exited', 'success', 1, 0),
        ]);
        $storage->files[$prefix . 'deploy-result.json'] = \Ops\DeployResultV1::canonicalJson(
            \Ops\DeployResultV1::create('succeeded', 0),
        );
        return [$storage, $durable];
    }

    private static function fixtureRunId(): string
    {
        return '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    }

    private function seedDeployAdmissionAuthority(RecordingHostRunnerStorage $storage): void
    {
        $bundle = $this->fixture();
        $prefix = 'runs/' . self::fixtureRunId() . '/';
        $storage->files[$prefix . 'request.json'] = DeploymentHostRunnerContractV1::encodeFile($bundle['request']);
        $storage->files[$prefix . 'execution-input.json'] = DeploymentHostRunnerContractV1::encodeExecutionInput($bundle['input']);
        $storage->files[$prefix . 'deploy-systemd-launch.json'] = DeploymentHostRunnerContractV1::encodeFile($bundle['launch']);
        $storage->files[$prefix . 'deploy-unit-binding.json'] = DeploymentHostRunnerContractV1::encodeFile($bundle['binding']);
    }

    /** @return array{request:array<string,mixed>,input:array<string,mixed>,launch:array<string,mixed>,binding:array<string,mixed>,script:string} */
    private function fixture(): array
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $script = "#!/bin/bash\nexit 0\n";
        $launch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $input,
            $request,
            null,
            $script,
            static fn(): string => str_repeat("\x11", 32),
        );
        $binding = [
            'schema' => DeploymentHostRunnerContractV1::UNIT_BINDING_SCHEMA,
            'run_id' => $request['run_id'],
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'deploy',
            'unit_name' => $launch['unit_name'],
            'unit_launch_sha256' => DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch)),
            'unit_manager_boot_id' => self::BOOT,
            'unit_invocation_id' => null,
            'binding_state' => 'reserved',
        ];

        return compact('request', 'input', 'launch', 'binding', 'script');
    }

    /** @param array<string,mixed> $launch */
    private function notFoundShow(array $launch): string
    {
        return $this->show($launch, 'not-found', 'inactive', 'dead', 'success', 0, 0, '', 'no');
    }

    /** @param array<string,mixed> $launch */
    private function loadedShow(array $launch, string $active, string $sub, string $result, int $code, int $status): string
    {
        return $this->show($launch, 'loaded', $active, $sub, $result, $code, $status, str_repeat('d', 32), 'yes');
    }

    /** @param array<string,mixed> $launch */
    private function show(array $launch, string $load, string $active, string $sub, string $result, int $code, int $status, string $invocation, string $transient): string
    {
        $properties = DeploymentHostRunnerContractV1::observedUnitProperties(
            'deploy',
            DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch)),
        );
        return implode("\n", [
            'Id=' . $launch['unit_name'], 'LoadState=' . $load, 'ActiveState=' . $active, 'SubState=' . $sub,
            'Result=' . $result, 'ExecMainCode=' . $code, 'ExecMainStatus=' . $status,
            'InvocationID=' . $invocation, 'Description=' . $properties['Description'],
            'Transient=' . $transient, 'Type=' . $properties['Type'], 'RemainAfterExit=' . $properties['RemainAfterExit'],
            'UMask=' . $properties['UMask'], 'KillMode=' . $properties['KillMode'], 'Restart=' . $properties['Restart'],
            'RuntimeMaxUSec=' . $properties['RuntimeMaxUSec'], 'TimeoutStopUSec=' . $properties['TimeoutStopUSec'],
            'StandardInput=' . $properties['StandardInput'], 'StandardOutput=' . $properties['StandardOutput'],
            'StandardError=' . $properties['StandardError'],
        ]) . "\n";
    }
}

final class ScriptedSystemAdapter implements HostRunnerSystemAdapter
{
    /** @var list<HostRunnerProcessResult|RuntimeException> */
    private array $outcomes;
    /** @var list<array{argv:list<string>,environment:array<string,string>,timeout:int}> */
    public array $calls = [];

    /** @param list<HostRunnerProcessResult|RuntimeException> $outcomes */
    public function __construct(array $outcomes) { $this->outcomes = $outcomes; }
    public function addOutcome(HostRunnerProcessResult|RuntimeException $outcome): void { $this->outcomes[] = $outcome; }

    public function run(array $argv, array $environment, int $timeoutSeconds): HostRunnerProcessResult
    {
        $this->calls[] = compact('argv', 'environment') + ['timeout' => $timeoutSeconds];
        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof RuntimeException) { throw $outcome; }
        if (!$outcome instanceof HostRunnerProcessResult) { throw new RuntimeException('unexpected adapter call'); }
        return $outcome;
    }
}

final class FixedHostRunnerClock implements \Ops\HostRunnerClock
{
    public function __construct(private readonly string $now) {}
    public function nowUtc(): string { return $this->now; }
}

final class FixedTerminalClock implements \Ops\HostRunnerOrchestratorClock
{
    public int $nowCalls = 0;
    public function nowUtc(): string { $this->nowCalls++; return '2026-08-12T10:00:20Z'; }
    public function bootId(): string { return 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'; }
    public function monotonicNs(): int { return 21_000_000_000; }
}

final class NotObservedTimingPin implements \Ops\HostRunnerTimingPin
{
    public int $calls = 0;
    public function pin(string $timingRunId, string $runId): array
    {
        $this->calls++;
        return ['status' => 'not_observed', 'bytes' => '', 'sha256' => null];
    }
}

final class ScriptedBootReader implements \Ops\HostRunnerBootReader
{
    /** @var list<string> */
    private array $values;
    public function __construct(array $values = []) { $this->values = $values; }
    public function read(): string
    {
        return array_shift($this->values) ?? 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' . "\n";
    }
}

final class RecordingHostRunnerStorage implements \Ops\HostRunnerStorage
{
    /** @var list<array{string,string,string}> */
    public array $operations = [];
    /** @var array<string,string> */
    public array $files = [];
    /** @var list<array{run_id:string,events_bytes:string,state_bytes:?string}> */
    public array $candidates = [];

    public function prepareRun(string $runId): void { $this->operations[] = ['prepare-run', $runId, '']; }
    public function reservedCandidates(): iterable { return $this->candidates; }
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void
    {
        $this->operations[] = ['pin-reference', $field, $sourcePath . ':' . $sha256];
    }

    public function read(string $relative, int $maxBytes): ?string { return $this->files[$relative] ?? null; }
    public function pin(string $relative, string $bytes, int $maxBytes): string
    {
        if (isset($this->files[$relative]) && $this->files[$relative] !== $bytes) {
            throw new RuntimeException('pin conflict');
        }
        $this->operations[] = ['pin', $relative, $bytes];
        $this->files[$relative] = $bytes;
        return 'pinned_or_resumed_exact';
    }
    public function cow(string $relative, string $bytes, int $maxBytes): void
    {
        $this->operations[] = ['cow', $relative, $bytes];
        $this->files[$relative] = $bytes;
    }
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void
    {
        if (($this->files[$relative] ?? null) !== $currentBytes) { throw new RuntimeException('binding CAS conflict'); }
        $this->operations[] = ['binding-refresh', $relative, $candidateBytes];
        $this->files[$relative] = $candidateBytes;
    }
    public function clearActiveClaim(string $expectedBytes): void
    {
        if (($this->files['active-run.json'] ?? null) !== $expectedBytes) { throw new RuntimeException('claim clear conflict'); }
        $this->operations[] = ['clear-exact', 'active-run.json', $expectedBytes];
        unset($this->files['active-run.json']);
    }
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void
    {
        if (($this->files['active-run.json'] ?? null) !== $currentBytes) { throw new RuntimeException('claim CAS conflict'); }
        $this->operations[] = ['claim-refresh', 'active-run.json', $candidateBytes];
        $this->files['active-run.json'] = $candidateBytes;
    }
}

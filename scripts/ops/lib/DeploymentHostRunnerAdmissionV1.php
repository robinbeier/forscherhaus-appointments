<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/DeploymentHostRunnerPredeployV1.php';

interface HostRunnerDeployScriptReader
{
    public function read(): string;
}

interface HostRunnerLaunchNonceSource
{
    public function bytes(): string;
}

final class SystemHostRunnerLaunchNonceSource implements HostRunnerLaunchNonceSource
{
    public function bytes(): string { return random_bytes(32); }
}

final class HelperBackedHostRunnerDeployScriptReader implements HostRunnerDeployScriptReader
{
    public function read(): string
    {
        $pipes = [];
        $process = proc_open(
            [
                '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
                '/usr/bin/python3', '-I', '-B', __DIR__ . '/../libexec/deployment_host_runner_fs_v1.py',
                'read-host-deploy-script', '/root',
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w'], 198 => ['file', '/dev/null', 'r'], 199 => ['file', '/dev/null', 'r']],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('deploy script reader is unavailable');
        }
        stream_set_blocking($pipes[1], false);
        $bytes = '';
        $deadline = microtime(true) + 10.0;
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            $bytes .= (string) stream_get_contents($pipes[1]);
            if (strlen($bytes) > 1_048_576) {
                proc_terminate($process, 9);
                break;
            }
            usleep(10_000);
            $status = proc_get_status($process);
        }
        $bytes .= (string) stream_get_contents($pipes[1]);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit === -1) {
            $exit = $status['exitcode'];
        }
        if ($exit !== 0 || $bytes === '' || strlen($bytes) > 1_048_576 || str_contains($bytes, "\0")) {
            throw new RuntimeException('deploy script reader rejected protected bytes');
        }
        return $bytes;
    }
}

final class HostRunnerDeployAdmission
{
    private readonly HostRunnerStartOrchestrator $start;
    private readonly HostRunnerBootReader $bootReader;
    private readonly HostRunnerClock $clock;

    public function __construct(
        private readonly HostRunnerStorage $storage,
        ?HostRunnerStartOrchestrator $start = null,
        private readonly HostRunnerDeployScriptReader $scriptReader = new HelperBackedHostRunnerDeployScriptReader(),
        private readonly HostRunnerLaunchNonceSource $nonceSource = new SystemHostRunnerLaunchNonceSource(),
        ?HostRunnerBootReader $bootReader = null,
        ?HostRunnerClock $clock = null,
    ) {
        $this->bootReader = $bootReader ?? new HelperBackedHostRunnerBootReader();
        $this->clock = $clock ?? new SystemHostRunnerClock();
        $this->start = $start ?? new HostRunnerStartOrchestrator(
            new HostRunnerReservationPersistence($storage, null, $this->clock),
            new DeploymentHostRunnerV1(new HelperBackedHostRunnerSystemAdapter()),
            $this->bootReader,
        );
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $input @return array<string,mixed> */
    public function admit(array $request, array $input): array
    {
        DeploymentHostRunnerContractV1::validateDeployExecutionBundle($request, $input);
        $runId = $request['run_id'];
        $prefix = 'runs/' . $runId . '/';
        $eventsBytes = $this->required($prefix . 'events.jsonl', 1_048_576);
        $stateBytes = $this->required($prefix . 'state.json', 4_096);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        if ($state['state'] === 'deploy_running') {
            $claimBytes = $this->required('active-run.json', 4_096);
            $this->start->resumeReserved($runId, $eventsBytes, $claimBytes, $stateBytes);
            return self::response($request, 'attach_observe_only', 'deploy_running', 0, 'ok');
        }
        if ($state['state'] !== 'artifact_verified' || $state['active_action'] !== 'none') {
            throw new RuntimeException('deploy admission requires artifact-verified durable state');
        }
        $assemblyBytes = $this->required($prefix . 'predeploy-evidence.json', 65_536);
        try {
            $assembly = json_decode($assemblyBytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('predeploy evidence assembly is invalid');
        }
        if (
            !is_array($assembly) || array_is_list($assembly) ||
            DeploymentEvidenceAuthorityV1::encodeFile($assembly) !== $assemblyBytes ||
            ($assembly['status'] ?? null) !== 'passed' || ($assembly['run_id'] ?? null) !== $runId ||
            !hash_equals($assembly['intent_sha256'] ?? '', $request['intent_sha256'])
        ) {
            throw new RuntimeException('deploy admission lacks passed protected predeploy evidence');
        }
        $script = $this->scriptReader->read();
        if (!hash_equals($assembly['sections']['artifact']['host_script_sha256'] ?? '', hash('sha256', $script))) {
            throw new RuntimeException('deploy script changed after protected artifact verification');
        }
        $launch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $input,
            $request,
            null,
            $script,
            fn(): string => $this->nonceSource->bytes(),
        );
        $bootBytes = $this->bootReader->read();
        $binding = [
            'schema' => DeploymentHostRunnerContractV1::UNIT_BINDING_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $request['intent_sha256'],
            'action' => 'deploy',
            'unit_name' => $launch['unit_name'],
            'unit_launch_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($launch)),
            'unit_manager_boot_id' => DeploymentHostRunnerContractV1::parseManagerBootId($bootBytes),
            'unit_invocation_id' => null,
            'binding_state' => 'reserved',
        ];
        DeploymentHostRunnerContractV1::validateUnitBinding($binding);
        $recordedAtUtc = $this->clock->nowUtc();
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition', 'run_id' => $runId, 'sequence' => count($lines) + 1,
            'recorded_at_utc' => $recordedAtUtc, 'previous_state' => 'artifact_verified',
            'state' => 'deploy_running', 'deploy_invocation_count' => 1,
            'intent_sha256' => $request['intent_sha256'], 'exit_code' => 0, 'reason' => 'ok',
        ]);
        $candidateEventsBytes = implode("\n", $lines) . "\n";
        $candidate = $state;
        $candidate['state'] = 'deploy_running';
        $candidate['sequence'] = count($lines);
        $candidate['events_sha256'] = hash('sha256', $candidateEventsBytes);
        $candidate['active_action'] = 'deploy';
        $candidate['deploy'] = [
            'request_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($request)),
            'execution_input_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeExecutionInput($input)),
            'invocation_count' => 1, 'unit_name' => $launch['unit_name'],
            'unit_launch_sha256' => hash('sha256', DeploymentHostRunnerContractV1::encodeFile($launch)),
            'unit_manager_boot_id' => $binding['unit_manager_boot_id'], 'unit_invocation_id' => null,
            'unit_missing_observed_boot_id' => null, 'unit_state' => 'starting',
            'observed_exit_code' => null, 'receipt_sha256' => null,
        ];
        $candidate['updated_at_utc'] = $recordedAtUtc;
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidate);
        if (DeploymentHostRunnerContractV1::stateCacheDisposition($candidate, $candidateEventsBytes) !== 'current') {
            throw new RuntimeException('deploy admission candidate does not bind its journal');
        }
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => $runId, 'intent_sha256' => $request['intent_sha256'], 'state' => 'deploy_running',
            'sequence' => count($lines), 'events_sha256' => hash('sha256', $candidateEventsBytes),
            'claimed_at_utc' => $recordedAtUtc,
        ];
        $disposition = $this->start->persistThenAdmit(
            $runId, $candidateEventsBytes, DeploymentHostRunnerContractV1::encodeFile($claim),
            DeploymentHostRunnerContractV1::encodeFile($candidate), $launch, $binding,
            $input, $request, null, $script,
        );
        if (in_array($disposition, ['observe_only', 'observe_only_reconciliation_required'], true)) {
            return self::response($request, 'accepted', 'deploy_running', 0, 'ok');
        }
        if ($disposition === 'collision') {
            return self::response($request, 'rejected', 'artifact_verified', 75, 'state_conflict');
        }
        return self::response($request, 'rejected', 'artifact_verified', 70, 'contract_invalid');
    }

    private function required(string $relative, int $limit): string
    {
        return $this->storage->read($relative, $limit) ?? throw new RuntimeException('deploy admission authority is incomplete');
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private static function response(array $request, string $disposition, string $state, int $exitCode, string $reason): array
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $request['run_id'], 'intent_sha256' => $request['intent_sha256'],
            'action' => 'deploy', 'disposition' => $disposition, 'state' => $state,
            'result_exit_code' => $exitCode, 'result_reason' => $reason,
        ];
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Ops;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/DeploymentContractV1.php';
require_once __DIR__ . '/DeployResultV1.php';
require_once __DIR__ . '/DeploymentHostRunnerContractV1.php';

final class HostRunnerProcessResult
{
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $transportLost = false,
    ) {
        if ($exitCode !== null && ($exitCode < 0 || $exitCode > 255)) {
            throw new RuntimeException('host-runner process exit code is invalid');
        }
        if (strlen($stdout) > 65_536 || strlen($stderr) > 65_536 || str_contains($stdout . $stderr, "\0")) {
            throw new RuntimeException('host-runner process output is invalid');
        }
        if ($transportLost && $exitCode !== null) {
            throw new RuntimeException('lost transport cannot claim an exit code');
        }
    }
}

interface HostRunnerSystemAdapter
{
    /** @param list<string> $argv @param array<string,string> $environment */
    public function run(array $argv, array $environment, int $timeoutSeconds): HostRunnerProcessResult;
}

interface HostRunnerStorage
{
    public function prepareRun(string $runId): void;
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void;
    public function read(string $relative, int $maxBytes): ?string;
    public function pin(string $relative, string $bytes, int $maxBytes): string;
    public function cow(string $relative, string $bytes, int $maxBytes): void;
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void;
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void;
    public function clearActiveClaim(string $expectedBytes): void;
    /** @return iterable<array{run_id:string,events_bytes:string,state_bytes:?string}> */
    public function reservedCandidates(): iterable;
}

interface HostRunnerClock
{
    public function nowUtc(): string;
}

interface HostRunnerBootReader
{
    public function read(): string;
}

final class HelperBackedHostRunnerBootReader implements HostRunnerBootReader
{
    private const COMMAND = [
        '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', __DIR__ . '/../libexec/deployment_host_runner_fs_v1.py', 'read-boot-id',
    ];

    public function read(): string
    {
        $pipes = [];
        $process = proc_open(self::COMMAND, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w'], 198 => ['file', '/dev/null', 'r'], 199 => ['file', '/dev/null', 'r']], $pipes, null, []);
        if (!is_resource($process)) { throw new RuntimeException('host-runner boot reader unavailable'); }
        stream_set_blocking($pipes[1], false);
        $bytes = '';
        $deadline = microtime(true) + 5.0;
        do {
            $bytes .= (string) stream_get_contents($pipes[1]);
            if (strlen($bytes) > 37) { proc_terminate($process, 9); break; }
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        if ($status['running']) { proc_terminate($process, 9); }
        $bytes .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit === -1) { $exit = $status['exitcode']; }
        if ($exit !== 0 || strlen($bytes) !== 37) {
            throw new RuntimeException('host-runner boot reader failed');
        }
        DeploymentHostRunnerContractV1::parseManagerBootId($bytes);
        return $bytes;
    }
}

final class SystemHostRunnerClock implements HostRunnerClock
{
    public function nowUtc(): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z');
    }
}

final class HelperBackedHostRunnerStorage implements HostRunnerStorage
{
    private const HELPER = __DIR__ . '/../libexec/deployment_host_runner_fs_v1.py';
    private const ROOT = DeploymentHostRunnerContractV1::STATE_ROOT;
    private const COMMAND_PREFIX = [
        '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', self::HELPER,
    ];

    public function read(string $relative, int $maxBytes): ?string
    {
        $result = $this->invoke('read-optional', $relative, $maxBytes, '');
        if ($result['exit_code'] === 78 && $result['stdout'] === '') {
            return null;
        }
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('host-runner protected read failed');
        }

        return $result['stdout'];
    }

    public function prepareRun(string $runId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $runId) !== 1) {
            throw new RuntimeException('host-runner run ID is invalid');
        }
        $result = $this->runHelper([...self::COMMAND_PREFIX, 'prepare-run', self::ROOT, $runId], '', 0);
        if ($result['exit_code'] !== 0 || $result['stdout'] !== '') { throw new RuntimeException('host-runner run preparation failed'); }
    }

    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void
    {
        $allowed = [
            'healthz-token',
            'zero-surprise-dump-sql',
            'zero-surprise-dump-sql-gz',
            'predeploy-credentials',
            'canary-credentials',
            'incident-webhook',
        ];
        if (
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $runId) !== 1 ||
            !in_array($field, $allowed, true) ||
            preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1 ||
            $sourcePath === '' || strlen($sourcePath) > 4_096 || str_contains($sourcePath, "\0")
        ) {
            throw new RuntimeException('host-runner protected reference is invalid');
        }
        $payload = json_encode(
            ['source_path' => $sourcePath, 'sha256' => $sha256],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $result = $this->runHelper(
            [...self::COMMAND_PREFIX, 'pin-reference', self::ROOT, $runId, $field],
            $payload,
            0,
            str_starts_with($field, 'zero-surprise-dump-') ? 1_800.0 : 30.0,
        );
        if ($result['exit_code'] !== 0 || $result['stdout'] !== '') {
            throw new RuntimeException('host-runner protected reference pin failed');
        }
    }

    public function pin(string $relative, string $bytes, int $maxBytes): string
    {
        $result = $this->invoke('pin', $relative, $maxBytes, $bytes);
        if ($result['exit_code'] === 0) {
            if ($result['stdout'] !== '') {
                throw new RuntimeException('host-runner protected pin returned output');
            }
            return 'pinned_or_resumed_exact';
        }
        if ($result['exit_code'] === 75) {
            throw new RuntimeException('host-runner protected pin conflicts with durable bytes');
        }
        throw new RuntimeException('host-runner protected pin failed');
    }

    public function cow(string $relative, string $bytes, int $maxBytes): void
    {
        $result = $this->invoke('cow', $relative, $maxBytes, $bytes);
        if ($result['exit_code'] !== 0 || $result['stdout'] !== '') {
            throw new RuntimeException('host-runner protected publish failed');
        }
    }

    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void
    {
        $current = DeploymentHostRunnerContractV1::decodeUnitBinding($currentBytes);
        $candidate = DeploymentHostRunnerContractV1::decodeUnitBinding($candidateBytes);
        DeploymentHostRunnerContractV1::validateUnitBindingEvolution($current, $candidate);
        $payload = json_encode(
            ['current' => base64_encode($currentBytes), 'candidate' => base64_encode($candidateBytes)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $result = $this->invoke('binding-refresh', $relative, 131_072, $payload);
        if ($result['exit_code'] !== 0 || $result['stdout'] !== '') {
            throw new RuntimeException('host-runner binding refresh failed');
        }
    }

    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void
    {
        DeploymentHostRunnerContractV1::decodeActiveRun($currentBytes);
        DeploymentHostRunnerContractV1::decodeActiveRun($candidateBytes);
        $payload = json_encode(
            ['current' => base64_encode($currentBytes), 'candidate' => base64_encode($candidateBytes)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $result = $this->invoke('claim-refresh', 'active-run.json', 131_072, $payload);
        if ($result['exit_code'] !== 0 || $result['stdout'] !== '') {
            throw new RuntimeException('host-runner active claim refresh failed');
        }
    }

    public function clearActiveClaim(string $expectedBytes): void
    {
        DeploymentHostRunnerContractV1::decodeActiveRun($expectedBytes);
        $result = $this->invoke('clear-exact', 'active-run.json', 4_096, $expectedBytes);
        if ($result['exit_code'] !== 0 || $result['stdout'] !== '') {
            throw new RuntimeException('host-runner active claim clearance failed');
        }
    }

    public function reservedCandidates(): iterable
    {
        $cursor = '-';
        $deadline = microtime(true) + 300.0;
        do {
            if (microtime(true) >= $deadline) { throw new RuntimeException('host-runner reserved scan deadline exceeded'); }
            $result = $this->runHelper([...self::COMMAND_PREFIX, 'scan-run-ids', self::ROOT, $cursor], '', 16_384, 30.0);
            if ($result['exit_code'] !== 0) { throw new RuntimeException('host-runner reserved scan failed'); }
            $page = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($page) || array_keys($page) !== ['next_cursor', 'run_ids'] || !is_array($page['run_ids']) || !array_is_list($page['run_ids']) || count($page['run_ids']) > 128) {
                throw new RuntimeException('host-runner reserved scan response is invalid');
            }
            $prior = $cursor;
            foreach ($page['run_ids'] as $runId) {
            if (!is_string($runId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $runId) !== 1) {
                throw new RuntimeException('host-runner reserved scan run ID is invalid');
            }
            if ($prior !== '-' && strcmp($runId, $prior) <= 0) { throw new RuntimeException('host-runner reserved scan order is invalid'); }
            $prior = $runId;
            $bundleResult = $this->runHelper(
                [...self::COMMAND_PREFIX, 'scan-run-bundle', self::ROOT, $runId],
                '',
                1_500_000,
                10.0,
            );
            if ($bundleResult['exit_code'] !== 0) { throw new RuntimeException('host-runner reserved bundle scan failed'); }
            $candidate = json_decode($bundleResult['stdout'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($candidate) || array_keys($candidate) !== ['events_bytes', 'run_id', 'state_bytes']) {
                throw new RuntimeException('host-runner reserved scan candidate is invalid');
            }
            if ($candidate['run_id'] !== $runId || ($candidate['events_bytes'] !== null && !is_string($candidate['events_bytes']))) {
                throw new RuntimeException('host-runner reserved scan identity is invalid');
            }
            $events = $candidate['events_bytes'] === null ? null : base64_decode($candidate['events_bytes'], true);
            $state = $candidate['state_bytes'] === null ? null : base64_decode($candidate['state_bytes'], true);
            if (($candidate['events_bytes'] !== null && !is_string($events)) || ($candidate['state_bytes'] !== null && !is_string($state))) {
                throw new RuntimeException('host-runner reserved scan bytes are invalid');
            }
            if ($events !== null) { yield ['run_id' => $runId, 'events_bytes' => $events, 'state_bytes' => $state]; }
            }
            $next = $page['next_cursor'];
            if ($next !== null && (!is_string($next) || $page['run_ids'] === [] || $next !== $prior || $next === $cursor)) {
                throw new RuntimeException('host-runner reserved scan cursor is invalid');
            }
            $cursor = $next ?? '-';
        } while ($page['next_cursor'] !== null);
    }

    /** @return array{exit_code:int,stdout:string} */
    private function invoke(string $operation, string $relative, int $maxBytes, string $stdin): array
    {
        if (
            !in_array($operation, ['read', 'read-optional', 'pin', 'cow', 'binding-refresh', 'claim-refresh', 'clear-exact'], true) ||
            preg_match('/^(?:active-run\.json|runs\/[0-9a-f-]{36}\/[A-Za-z0-9._-]+)$/D', $relative) !== 1 ||
            $maxBytes < 1 || $maxBytes > 1_048_576 ||
            strlen($stdin) > $maxBytes
        ) {
            throw new RuntimeException('host-runner storage operation is invalid');
        }
        $command = [...self::COMMAND_PREFIX, $operation, self::ROOT, $relative, (string) $maxBytes];
        return $this->runHelper($command, $stdin, $maxBytes);
    }

    /** @param list<string> $command @return array{exit_code:int,stdout:string} */
    private function runHelper(array $command, string $stdin, int $maxOutputBytes, float $timeoutSeconds = 5.0): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['file', '/dev/null', 'w'],
                198 => ['file', '/dev/null', 'r'],
                199 => ['file', '/dev/null', 'r'],
            ],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('host-runner storage helper unavailable');
        }
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        if ($timeoutSeconds <= 0.0 || $timeoutSeconds > 1_800.0) {
            proc_terminate($process, 9);
            throw new RuntimeException('host-runner storage helper timeout is invalid');
        }
        $deadline = microtime(true) + $timeoutSeconds;
        $offset = 0;
        while ($offset < strlen($stdin)) {
            $written = fwrite($pipes[0], substr($stdin, $offset, 65_536));
            if ($written === false || microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[0]);
                throw new RuntimeException('host-runner storage helper input failed');
            }
            if ($written === 0) {
                usleep(10_000);
                continue;
            }
            $offset += $written;
        }
        fclose($pipes[0]);
        $stdout = '';
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            if (strlen($stdout) > $maxOutputBytes) {
                proc_terminate($process, 9);
                break;
            }
            usleep(10_000);
            $status = proc_get_status($process);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = $status['exitcode'];
        }
        if (strlen($stdout) > $maxOutputBytes) {
            throw new RuntimeException('host-runner storage helper output exceeded the fixed bound');
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout];
    }
}

final class HostRunnerReservationPersistence
{
    private readonly HostRunnerClock $clock;

    /** @param null|callable(string):void $afterDurableStep */
    public function __construct(
        private readonly HostRunnerStorage $storage,
        private readonly mixed $afterDurableStep = null,
        ?HostRunnerClock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemHostRunnerClock();
    }

    public function persist(
        string $runId,
        string $eventsBytes,
        string $claimBytes,
        string $stateBytes,
    ): void {
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $prefix = 'runs/' . $runId . '/';
        $priorEventsBytes = $this->storage->read($prefix . 'events.jsonl', 1_048_576);
        $priorStateBytes = $this->storage->read($prefix . 'state.json', 4_096);
        $existingClaimBytes = $this->storage->read('active-run.json', 4_096);
        if (!is_string($priorStateBytes)) {
            throw new RuntimeException('reservation requires a durable prior state cache');
        }
        $priorState = DeploymentHostRunnerContractV1::decodeState($priorStateBytes);
        $this->assertPinnedReservationAuthority($runId, $state, $priorState);
        DeploymentHostRunnerContractV1::validateStateEvolution($priorState, $state);
        if (
            !is_string($priorEventsBytes) ||
            DeploymentHostRunnerContractV1::stateCacheDisposition($priorState, $priorEventsBytes) !== 'current' ||
            !in_array(
                [$priorState['state'], $state['state']],
                [['artifact_verified', 'deploy_running'], ['post_gates_running', 'rollback_running']],
                true,
            )
        ) {
            throw new RuntimeException('reservation predecessor is not authoritative');
        }
        if ($existingClaimBytes !== null) {
            $existingClaim = DeploymentHostRunnerContractV1::decodeActiveRun($existingClaimBytes);
            if (
                $state['state'] !== 'rollback_running' ||
                $existingClaim['run_id'] !== $priorState['run_id'] ||
                !hash_equals($existingClaim['intent_sha256'], $priorState['intent_sha256']) ||
                $existingClaim['state'] !== 'post_gates_running' ||
                $existingClaim['sequence'] !== $priorState['sequence'] ||
                !hash_equals($existingClaim['events_sha256'], $priorState['events_sha256']) ||
                !hash_equals(
                    hash('sha256', implode("\n", array_slice(explode("\n", rtrim($priorEventsBytes, "\n")), 0, $existingClaim['sequence'])) . "\n"),
                    $existingClaim['events_sha256'],
                )
            ) {
                throw new RuntimeException('reservation conflicts with the durable active-run claim');
            }
        }
        if (
            !is_string($priorEventsBytes) ||
            $priorEventsBytes === '' ||
            !str_ends_with($priorEventsBytes, "\n") ||
            !str_starts_with($eventsBytes, $priorEventsBytes) ||
            substr_count(substr($eventsBytes, strlen($priorEventsBytes)), "\n") !== 1 ||
            $claim['run_id'] !== $runId ||
            $state['run_id'] !== $runId ||
            $claim['intent_sha256'] !== $state['intent_sha256'] ||
            $claim['state'] !== $state['state'] ||
            $claim['sequence'] !== $state['sequence'] ||
            !hash_equals($claim['events_sha256'], $state['events_sha256']) ||
            !in_array($state['state'], ['deploy_running', 'rollback_running'], true) ||
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current'
        ) {
            throw new RuntimeException('reservation persistence bundle is inconsistent');
        }
        $this->storage->cow($prefix . 'events.jsonl', $eventsBytes, 1_048_576);
        $this->after('reservation_journal_durable');
        $existingClaimBytes === null
            ? $this->storage->pin('active-run.json', $claimBytes, 4_096)
            : $this->storage->refreshActiveClaim($existingClaimBytes, $claimBytes);
        $this->after('reservation_claim_durable');
        $this->storage->cow($prefix . 'state.json', $stateBytes, 4_096);
        $this->after('reservation_state_durable');
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $priorState */
    private function assertPinnedReservationAuthority(
        string $runId,
        array $state,
        array $priorState,
        bool $allowObservedBindingPrefix = false,
    ): void
    {
        $action = $state['active_action'];
        if (!in_array($action, ['deploy', 'rollback'], true)) {
            throw new RuntimeException('reservation has no pinned action authority');
        }
        $prefix = 'runs/' . $runId . '/';
        $requestLeaf = $action === 'deploy' ? 'request.json' : 'recovery-request.json';
        $inputLeaf = $action === 'deploy' ? 'execution-input.json' : 'recovery-execution-input.json';
        $requestBytes = $this->storage->read($prefix . $requestLeaf, 16_384);
        $inputBytes = $this->storage->read($prefix . $inputLeaf, 16_384);
        $launchBytes = $this->storage->read($prefix . $action . '-systemd-launch.json', 16_384);
        $bindingBytes = $this->storage->read($prefix . $action . '-unit-binding.json', 16_384);
        if ($requestBytes === null || $inputBytes === null || $launchBytes === null || $bindingBytes === null) {
            throw new RuntimeException('reservation requires the exact pinned admission authority');
        }
        $request = $action === 'deploy'
            ? DeploymentHostRunnerContractV1::decodeDeployRequest($requestBytes)
            : DeploymentHostRunnerContractV1::decodeRecoveryRequest($requestBytes);
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput($inputBytes);
        $launch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($launchBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $actionState = $state[$action];
        if (
            $request['run_id'] !== $runId ||
            $input['run_id'] !== $runId ||
            $launch['run_id'] !== $runId ||
            $binding['run_id'] !== $runId ||
            $launch['action'] !== $action ||
            $binding['action'] !== $action ||
            (!($binding['binding_state'] === 'reserved' && $binding['unit_invocation_id'] === null) &&
                !$allowObservedBindingPrefix) ||
            !hash_equals($state['intent_sha256'], $request['intent_sha256']) ||
            !hash_equals($state['intent_sha256'], $input['intent_sha256']) ||
            !hash_equals($state['intent_sha256'], $launch['intent_sha256']) ||
            !hash_equals($state['intent_sha256'], $binding['intent_sha256']) ||
            !hash_equals($actionState['request_sha256'], hash('sha256', $requestBytes)) ||
            !hash_equals($actionState['execution_input_sha256'], hash('sha256', $inputBytes)) ||
            !hash_equals($actionState['unit_launch_sha256'], hash('sha256', $launchBytes)) ||
            $actionState['unit_name'] !== $launch['unit_name'] ||
            $binding['unit_name'] !== $launch['unit_name'] ||
            !hash_equals($binding['unit_launch_sha256'], hash('sha256', $launchBytes)) ||
            $actionState['unit_manager_boot_id'] !== $binding['unit_manager_boot_id']
        ) {
            throw new RuntimeException('pinned admission authority contradicts the reservation');
        }
        if ($binding['binding_state'] === 'observed') {
            $observationBytes = $this->storage->read($prefix . $action . '-unit-observation.json', 65_536);
            if (!$allowObservedBindingPrefix || $observationBytes === null) {
                throw new RuntimeException('observed binding has no authorized durable observation prefix');
            }
            $envelope = json_decode($observationBytes, true, 32, JSON_THROW_ON_ERROR);
            if (($envelope['schema'] ?? null) === DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA) {
                $observation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation($observationBytes, $launch);
                $result = DeploymentHostRunnerContractV1::classifySystemdObservation(
                    $launch,
                    DeploymentHostRunnerContractV1::parseSystemctlShow($observation['systemctl_show'], $launch),
                );
                if (
                    !hash_equals($binding['unit_manager_boot_id'], $observation['manager_boot_id']) ||
                    $result['unit_invocation_id'] === null ||
                    !hash_equals($binding['unit_invocation_id'], $result['unit_invocation_id'])
                ) {
                    throw new RuntimeException('observed binding contradicts its durable observation prefix');
                }
            } elseif (($envelope['schema'] ?? null) === DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA) {
                DeploymentHostRunnerContractV1::decodeUnitAbsence($observationBytes);
            } else {
                throw new RuntimeException('observed binding has an invalid durable observation prefix');
            }
            if (
                $state[$action]['unit_invocation_id'] !== null &&
                !hash_equals($state[$action]['unit_invocation_id'], $binding['unit_invocation_id'])
            ) {
                throw new RuntimeException('observed binding contradicts durable state identity');
            }
        }
        if ($action === 'rollback') {
            $deployRequestBytes = $this->storage->read($prefix . 'request.json', 16_384);
            $reportBytes = $this->storage->read($prefix . 'deploy-post-gate-report.json', 16_384);
            if ($deployRequestBytes === null || $reportBytes === null) {
                throw new RuntimeException('rollback reservation requires pinned deploy authority');
            }
            $deployRequest = DeploymentHostRunnerContractV1::decodeDeployRequest($deployRequestBytes);
            $report = DeploymentHostRunnerContractV1::decodePostGateReport($reportBytes);
            if ($priorState['state'] === 'post_gates_running') {
                DeploymentHostRunnerContractV1::validatePostGateBundle($report, $priorState);
            } elseif (
                $priorState['state'] !== 'rollback_running' ||
                DeploymentHostRunnerContractV1::postGateSubmissionDisposition(
                    $reportBytes,
                    $priorState,
                    $reportBytes,
                ) !== 'attach'
            ) {
                throw new RuntimeException('rollback resume report authority is invalid');
            }
            if (
                $deployRequest['run_id'] !== $runId ||
                !hash_equals($deployRequest['intent_sha256'], $state['intent_sha256']) ||
                $report['post_gates']['status'] !== 'failed' ||
                !hash_equals($priorState['post_gates']['deploy_report_sha256'], hash('sha256', $reportBytes))
            ) {
                throw new RuntimeException('rollback reservation deploy authority is invalid');
            }
        }
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $input @param array<string,mixed> $launch @param array<string,mixed> $binding */
    public function pinAdmissionBundle(
        string $runId,
        string $action,
        array $request,
        array $input,
        array $launch,
        array $binding,
    ): void {
        $prefix = 'runs/' . $runId . '/';
        if ($action === 'deploy') {
            foreach (DeploymentHostRunnerContractV1::deployReferencePins($input) as $field => $pin) {
                $operationField = match ($field) {
                    'healthz_token' => 'healthz-token',
                    'zero_surprise_dump' => str_ends_with($pin['source_path'], '.sql.gz')
                        ? 'zero-surprise-dump-sql-gz'
                        : 'zero-surprise-dump-sql',
                    'zero_surprise_predeploy_credentials' => 'predeploy-credentials',
                    'zero_surprise_canary_credentials' => 'canary-credentials',
                    'zero_surprise_incident_webhook' => 'incident-webhook',
                };
                $this->storage->pinReference($runId, $operationField, $pin['source_path'], $pin['sha256']);
                $this->after('pinned_deploy_reference_' . str_replace('-', '_', $operationField));
            }
        }
        $items = [
            ($action === 'deploy' ? 'request.json' : 'recovery-request.json') => DeploymentHostRunnerContractV1::encodeFile($request),
            ($action === 'deploy' ? 'execution-input.json' : 'recovery-execution-input.json') => DeploymentHostRunnerContractV1::encodeExecutionInput($input),
            $action . '-systemd-launch.json' => DeploymentHostRunnerContractV1::encodeFile($launch),
            $action . '-unit-binding.json' => DeploymentHostRunnerContractV1::encodeFile($binding),
        ];
        foreach ($items as $leaf => $bytes) {
            $this->storage->pin($prefix . $leaf, $bytes, 65_536);
            $this->after('pinned_' . str_replace(['.json', '-'], ['', '_'], $leaf));
        }
    }

    /** @return array<string,mixed> */
    public function requirePinnedFailedDeployReport(string $runId, string $expectedBytes, array $priorState): array
    {
        $pinned = $this->storage->read('runs/' . $runId . '/deploy-post-gate-report.json', 16_384);
        if ($pinned === null || !hash_equals($pinned, $expectedBytes)) {
            throw new RuntimeException('recovery requires the exact pinned deploy report');
        }
        $report = DeploymentHostRunnerContractV1::decodePostGateReport($pinned);
        DeploymentHostRunnerContractV1::validatePostGateBundle($report, $priorState);
        if (
            $report['subject'] !== 'deploy' ||
            $report['post_gates']['status'] !== 'failed' ||
            $priorState['post_gates']['deploy_submission_count'] !== 1 ||
            !hash_equals($priorState['post_gates']['deploy_report_sha256'], hash('sha256', $pinned))
        ) {
            throw new RuntimeException('recovery requires a bound failed deploy report');
        }
        return $report;
    }

    public function resumeAfterReservation(
        string $runId,
        string $eventsBytes,
        string $claimBytes,
        string $stateBytes,
    ): string {
        $prefix = 'runs/' . $runId . '/';
        $durableEvents = $this->storage->read($prefix . 'events.jsonl', 1_048_576);
        if (!is_string($durableEvents) || !hash_equals($durableEvents, $eventsBytes)) {
            throw new RuntimeException('reservation recovery requires the exact durable journal');
        }
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $durablePriorStateBytes = $this->storage->read($prefix . 'state.json', 4_096);
        if ($durablePriorStateBytes !== null) {
            $durablePriorState = DeploymentHostRunnerContractV1::decodeState($durablePriorStateBytes);
            if ($durablePriorState['sequence'] < $state['sequence']) {
                $this->assertPinnedReservationAuthority($runId, $state, $durablePriorState, true);
            } else {
                $this->assertPinnedReservationAuthority($runId, $state, $state, true);
            }
        } else {
            $this->assertPinnedReservationAuthority($runId, $state, $state, true);
        }
        if (
            $claim['run_id'] !== $runId ||
            $state['run_id'] !== $runId ||
            !hash_equals($claim['intent_sha256'], $state['intent_sha256']) ||
            !in_array($state['state'], ['deploy_running', 'rollback_running'], true) ||
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current' ||
            !hash_equals($claim['events_sha256'], $state['events_sha256']) ||
            $claim['sequence'] !== $state['sequence'] ||
            $claim['state'] !== $state['state']
        ) {
            throw new RuntimeException('reservation recovery bundle is inconsistent');
        }
        $durableClaim = $this->storage->read('active-run.json', 4_096);
        if ($durableClaim === null) {
            $this->storage->pin('active-run.json', $claimBytes, 4_096);
        } elseif (!hash_equals($durableClaim, $claimBytes)) {
            throw new RuntimeException('reservation recovery claim conflicts');
        }
        $durableState = $durablePriorStateBytes;
        if ($durableState === null || !hash_equals($durableState, $stateBytes)) {
            if ($durableState !== null) {
                $prior = DeploymentHostRunnerContractV1::decodeState($durableState);
                DeploymentHostRunnerContractV1::validateStateEvolution($prior, $state);
            }
            $this->storage->cow($prefix . 'state.json', $stateBytes, 4_096);
        }

        return 'attach_observe_only';
    }

    public function reconstructSoleReservedClaim(): string
    {
        $scanned = $this->storage->reservedCandidates();
        $candidates = [];
        $contractCandidates = [];
        foreach ($scanned as $candidate) {
            if (!is_array($candidate) || array_keys($candidate) !== ['run_id', 'events_bytes', 'state_bytes']) {
                throw new RuntimeException('reserved scan candidate is invalid');
            }
            $eventsBytes = $candidate['events_bytes'];
            if (!is_string($eventsBytes) || $eventsBytes === '' || !str_ends_with($eventsBytes, "\n")) {
                throw new RuntimeException('reserved scan journal is invalid');
            }
            $run = DeploymentContractV1::validateRunLines(explode("\n", substr($eventsBytes, 0, -1)));
            if ($run['run_id'] !== $candidate['run_id']) {
                throw new RuntimeException('reserved scan journal identity is invalid');
            }
            $state = $candidate['state_bytes'] === null
                ? null
                : DeploymentHostRunnerContractV1::decodeState($candidate['state_bytes']);
            if (!in_array($run['state'], ['deploy_running', 'post_gates_running', 'rollback_running'], true)) {
                $this->validateHistoricalScanEntry($run, $eventsBytes, $state);
                continue;
            }
            $candidates[] = $candidate;
            $contractCandidates[] = [
                'state' => $state,
                'events_bytes' => $eventsBytes,
            ];
            if (count($candidates) > 1) {
                throw new RuntimeException('reserved scan has multiple active candidates');
            }
        }
        $disposition = DeploymentHostRunnerContractV1::activeRunReconstructionDisposition($contractCandidates);
        if ($disposition === 'no_reserved_run') { return $disposition; }
        if ($disposition !== 'reconstruct_claim_observe_only' || count($candidates) !== 1) {
            throw new RuntimeException('reserved scan is not uniquely reconstructable');
        }
        $candidate = $candidates[0];
        $eventsBytes = $candidate['events_bytes'];
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        $run = DeploymentContractV1::validateRunLines($lines);
        $state = $candidate['state_bytes'] === null ? null : DeploymentHostRunnerContractV1::decodeState($candidate['state_bytes']);
        if ($run['run_id'] !== $candidate['run_id'] || ($state !== null && $state['run_id'] !== $candidate['run_id'])) {
            throw new RuntimeException('reserved scan identity is invalid');
        }
        $claim = [
            'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
            'run_id' => $run['run_id'],
            'intent_sha256' => $run['intent_sha256'],
            'state' => $run['state'],
            'sequence' => $run['records'],
            'events_sha256' => hash('sha256', $eventsBytes),
            'claimed_at_utc' => json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR)['recorded_at_utc'],
        ];
        $stateIsCurrent = $state !== null &&
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) === 'current';
        if ($stateIsCurrent) {
            $run['state'] === 'post_gates_running'
                ? $this->assertCompletedDeployAuthority($run['run_id'], $state)
                : $this->assertPinnedReservationAuthority($run['run_id'], $state, $state, true);
            $reservationState = $state;
        } else {
            $reservationState = $this->deriveReservationState($run, $eventsBytes, $lines, $state);
            if ($state !== null) { DeploymentHostRunnerContractV1::validateStateEvolution($state, $reservationState); }
        }
        $this->storage->pin('active-run.json', DeploymentHostRunnerContractV1::encodeFile($claim), 4_096);
        $this->after('reconstruction_claim_durable');
        if (!$stateIsCurrent) {
            $this->storage->cow(
                'runs/' . $run['run_id'] . '/state.json',
                DeploymentHostRunnerContractV1::encodeFile($reservationState),
                4_096,
            );
            $this->after('reconstruction_state_durable');
        }
        return $disposition;
    }

    /** @param array<string,mixed> $run @param list<string> $lines @param ?array<string,mixed> $cached */
    private function deriveReservationState(array $run, string $eventsBytes, array $lines, ?array $cached): array
    {
        $action = $run['state'] === 'rollback_running' ? 'rollback' : 'deploy';
        $prefix = 'runs/' . $run['run_id'] . '/';
        $required = function (string $leaf, int $max) use ($prefix): string {
            $bytes = $this->storage->read($prefix . $leaf, $max);
            if ($bytes === null) { throw new RuntimeException('reservation reconstruction authority is missing'); }
            return $bytes;
        };
        $requestLeaf = $action === 'deploy' ? 'request.json' : 'recovery-request.json';
        $inputLeaf = $action === 'deploy' ? 'execution-input.json' : 'recovery-execution-input.json';
        $requestBytes = $required($requestLeaf, 16_384);
        $inputBytes = $required($inputLeaf, 16_384);
        $launchBytes = $required($action . '-systemd-launch.json', 16_384);
        $bindingBytes = $required($action . '-unit-binding.json', 16_384);
        $request = $action === 'deploy'
            ? DeploymentHostRunnerContractV1::decodeDeployRequest($requestBytes)
            : DeploymentHostRunnerContractV1::decodeRecoveryRequest($requestBytes);
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput($inputBytes);
        $launch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($launchBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $last = json_decode($lines[array_key_last($lines)], true, 32, JSON_THROW_ON_ERROR);
        $state = $cached ?? $this->emptyReservationState($run, $requestBytes);
        $state['state'] = $run['state'];
        $state['sequence'] = $run['records'];
        $state['events_sha256'] = hash('sha256', $eventsBytes);
        $state['active_action'] = $action;
        $state['updated_at_utc'] = $last['recorded_at_utc'];
        $state[$action] = array_replace($state[$action], [
            'request_sha256' => hash('sha256', $requestBytes),
            'execution_input_sha256' => hash('sha256', $inputBytes),
            'invocation_count' => 1,
            'unit_name' => $launch['unit_name'],
            'unit_launch_sha256' => hash('sha256', $launchBytes),
            'unit_manager_boot_id' => $binding['unit_manager_boot_id'],
            'unit_invocation_id' => $binding['unit_invocation_id'],
            'unit_missing_observed_boot_id' => null,
            'unit_state' => $binding['binding_state'] === 'observed' ? 'unknown' : 'starting',
            'observed_exit_code' => null,
        ]);
        if ($action === 'rollback') {
            $state['rollback']['verdict'] = 'unknown';
            $deployRequestBytes = $required('request.json', 16_384);
            $reportBytes = $required('deploy-post-gate-report.json', 16_384);
            $report = DeploymentHostRunnerContractV1::decodePostGateReport($reportBytes);
            $state['deploy']['request_sha256'] = hash('sha256', $deployRequestBytes);
            $state['deploy']['invocation_count'] = 1;
            $state['deploy']['observed_exit_code'] = 0;
            $state['deploy']['receipt_sha256'] = $report['deploy_receipt_sha256'];
            $state['post_gates'] = [
                'deploy_report_sha256' => hash('sha256', $reportBytes),
                'deploy_submission_count' => 1,
                'deploy_verdict' => 'failed',
                'rollback_report_sha256' => null,
                'rollback_submission_count' => 0,
                'rollback_verdict' => 'not_submitted',
            ];
        }
        DeploymentHostRunnerContractV1::validateState($state);
        if (DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current') {
            throw new RuntimeException('derived reservation state is not current');
        }
        $this->assertPinnedReservationAuthority($run['run_id'], $state, $state, true);
        return $state;
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function emptyReservationState(array $run, string $deployRequestBytes): array
    {
        return [
            'schema' => DeploymentHostRunnerContractV1::STATE_SCHEMA,
            'run_id' => $run['run_id'], 'intent_sha256' => $run['intent_sha256'],
            'state' => $run['state'], 'sequence' => $run['records'], 'events_sha256' => str_repeat('0', 64),
            'active_action' => $run['state'] === 'rollback_running' ? 'rollback' : 'deploy',
            'deploy' => [
                'request_sha256' => hash('sha256', $deployRequestBytes), 'execution_input_sha256' => null,
                'invocation_count' => $run['state'] === 'rollback_running' ? 1 : 0,
                'unit_name' => null, 'unit_launch_sha256' => null, 'unit_manager_boot_id' => null,
                'unit_invocation_id' => null, 'unit_missing_observed_boot_id' => null,
                'unit_state' => 'not_created', 'observed_exit_code' => null, 'receipt_sha256' => null,
            ],
            'post_gates' => [
                'deploy_report_sha256' => null, 'deploy_submission_count' => 0, 'deploy_verdict' => 'not_submitted',
                'rollback_report_sha256' => null, 'rollback_submission_count' => 0, 'rollback_verdict' => 'not_submitted',
            ],
            'rollback' => [
                'request_sha256' => null, 'execution_input_sha256' => null, 'invocation_count' => 0,
                'unit_name' => null, 'unit_launch_sha256' => null, 'unit_manager_boot_id' => null,
                'unit_invocation_id' => null, 'unit_missing_observed_boot_id' => null,
                'unit_state' => 'not_created', 'observed_exit_code' => null, 'verdict' => 'not_invoked',
            ],
            'evidence_sha256' => null,
            'terminal' => ['state' => null, 'exit_code' => null, 'reason' => null],
            'updated_at_utc' => '2000-01-01T00:00:00Z',
        ];
    }

    /** @param array<string,mixed> $run @param ?array<string,mixed> $state */
    private function validateHistoricalScanEntry(array $run, string $eventsBytes, ?array $state): void
    {
        if ($state === null) {
            if (in_array($run['state'], ['succeeded', ...DeploymentContractV1::TERMINAL_FAILURE_STATES], true)) {
                throw new RuntimeException('terminal history is missing its state cache');
            }
            return;
        }
        if (
            $state['run_id'] !== $run['run_id'] ||
            !hash_equals($state['intent_sha256'], $run['intent_sha256']) ||
            $state['state'] !== $run['state'] ||
            $state['sequence'] !== $run['records'] ||
            !hash_equals($state['events_sha256'], hash('sha256', $eventsBytes))
        ) {
            throw new RuntimeException('historical run contradicts its journal');
        }
        if (!in_array($run['state'], ['succeeded', ...DeploymentContractV1::TERMINAL_FAILURE_STATES], true)) {
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes);
            if ($run['state'] === 'post_gates_running') {
                $this->assertCompletedDeployAuthority($run['run_id'], $state);
            }
            return;
        }
        $prefix = 'runs/' . $run['run_id'] . '/';
        $evidence = $this->storage->read($prefix . 'evidence.json', 65_536);
        if ($evidence === null) { throw new RuntimeException('terminal history is missing evidence'); }
        $deployReport = $state['post_gates']['deploy_submission_count'] === 0
            ? null
            : $this->storage->read($prefix . 'deploy-post-gate-report.json', 16_384);
        $rollbackReport = $state['post_gates']['rollback_submission_count'] === 0
            ? null
            : $this->storage->read($prefix . 'rollback-post-gate-report.json', 16_384);
        $bundles = [];
        foreach (['deploy', 'rollback'] as $action) {
            if ($state[$action]['invocation_count'] !== 1) { continue; }
            $launch = $this->storage->read($prefix . $action . '-systemd-launch.json', 16_384);
            $binding = $this->storage->read($prefix . $action . '-unit-binding.json', 16_384);
            $observation = $this->storage->read($prefix . $action . '-unit-observation.json', 65_536);
            if ($launch === null || $binding === null || $observation === null) {
                throw new RuntimeException('terminal history has an incomplete unit bundle');
            }
            $bundles[$action] = ['launch' => $launch, 'binding' => $binding, 'observation' => $observation];
        }
        DeploymentHostRunnerContractV1::terminalStateCacheDisposition(
            $state,
            $eventsBytes,
            $evidence,
            $deployReport,
            $rollbackReport,
            $bundles,
        );
    }

    /** @param array<string,mixed> $state */
    private function assertCompletedDeployAuthority(string $runId, array $state): void
    {
        $prefix = 'runs/' . $runId . '/';
        $required = function (string $leaf, int $max) use ($prefix): string {
            $bytes = $this->storage->read($prefix . $leaf, $max);
            if ($bytes === null) { throw new RuntimeException('completed deploy authority is missing'); }
            return $bytes;
        };
        $requestBytes = $required('request.json', 16_384);
        $inputBytes = $required('execution-input.json', 16_384);
        $launchBytes = $required('deploy-systemd-launch.json', 16_384);
        $bindingBytes = $required('deploy-unit-binding.json', 16_384);
        $observationBytes = $required('deploy-unit-observation.json', 65_536);
        $receiptBytes = $required('deploy-result.json', 4_096);
        $launch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($launchBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $observation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation($observationBytes, $launch);
        DeploymentHostRunnerContractV1::decodeDeployRequest($requestBytes);
        DeploymentHostRunnerContractV1::decodeExecutionInput($inputBytes);
        $receipt = DeployResultV1::decode($receiptBytes);
        if (
            !hash_equals($state['deploy']['request_sha256'], hash('sha256', $requestBytes)) ||
            !hash_equals($state['deploy']['execution_input_sha256'], hash('sha256', $inputBytes)) ||
            !hash_equals($state['deploy']['unit_launch_sha256'], hash('sha256', $launchBytes)) ||
            !hash_equals($state['deploy']['receipt_sha256'], hash('sha256', $receiptBytes)) ||
            $receipt['exit_code'] !== $state['deploy']['observed_exit_code'] ||
            $receipt['outcome'] !== 'succeeded'
        ) {
            throw new RuntimeException('completed deploy authority contradicts its state');
        }
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle($launch, $binding, $state, $observation);
    }

    private function after(string $step): void
    {
        if (is_callable($this->afterDurableStep)) {
            ($this->afterDurableStep)($step);
        }
    }

    public function storageReadForValidation(string $relative, int $maxBytes): ?string
    {
        return $this->storage->read($relative, $maxBytes);
    }

    /** @return array<string,mixed> */
    public function pinnedLaunchForReservedRun(string $runId): array
    {
        $prefix = 'runs/' . $runId . '/';
        $stateBytes = $this->storage->read($prefix . 'state.json', 4_096);
        if ($stateBytes === null) { throw new RuntimeException('reserved run state is missing'); }
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $action = $state['active_action'];
        if (!in_array($action, ['deploy', 'rollback'], true)) {
            throw new RuntimeException('reserved run has no active action');
        }
        $launchBytes = $this->storage->read($prefix . $action . '-systemd-launch.json', 16_384);
        if ($launchBytes === null) { throw new RuntimeException('reserved launch is missing'); }
        $launch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($launchBytes);
        if (!hash_equals($state[$action]['unit_launch_sha256'], hash('sha256', $launchBytes))) {
            throw new RuntimeException('reserved launch contradicts durable state');
        }
        return $launch;
    }

    /** @param array<string,mixed> $launch @param array{lookup:array<string,mixed>,pinned_bytes:string} $observed */
    public function persistObservation(string $runId, array $launch, array $observed): void
    {
        $action = $launch['action'];
        $prefix = 'runs/' . $runId . '/';
        $stateBytes = $this->storage->read($prefix . 'state.json', 4_096);
        $bindingBytes = $this->storage->read($prefix . $action . '-unit-binding.json', 16_384);
        if ($stateBytes === null || $bindingBytes === null) {
            throw new RuntimeException('unit observation requires durable state and binding');
        }
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $candidate = $state;
        $candidate['updated_at_utc'] = $this->clock->nowUtc();
        $decoded = json_decode($observed['pinned_bytes'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['schema'] ?? null)) {
            throw new RuntimeException('unit observation bytes are invalid');
        }
        $nextBinding = $binding;
        if ($decoded['schema'] === DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA) {
            $observation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation(
                $observed['pinned_bytes'],
                $launch,
            );
            $result = DeploymentHostRunnerContractV1::classifySystemdObservation(
                $launch,
                DeploymentHostRunnerContractV1::parseSystemctlShow($observation['systemctl_show'], $launch),
            );
            $candidate[$action]['unit_state'] = $result['unit_state'];
            $candidate[$action]['observed_exit_code'] = $result['observed_exit_code'];
            $candidate[$action]['unit_invocation_id'] = $result['unit_invocation_id'];
            if ($binding['binding_state'] === 'reserved' && $result['unit_invocation_id'] !== null) {
                $nextBinding['binding_state'] = 'observed';
                $nextBinding['unit_invocation_id'] = $result['unit_invocation_id'];
            }
        } elseif ($decoded['schema'] === DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA) {
            $observation = DeploymentHostRunnerContractV1::decodeUnitAbsence($observed['pinned_bytes']);
            $absence = $observation;
            unset($absence['schema']);
            $candidate[$action]['unit_state'] = DeploymentHostRunnerContractV1::classifyUnitObservation($binding, $absence);
            if ($candidate[$action]['unit_state'] === 'missing') {
                $candidate[$action]['unit_missing_observed_boot_id'] = $absence['manager_boot_id'];
            }
        } else {
            throw new RuntimeException('unit observation schema is invalid');
        }
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidate);
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle(
            $launch,
            $nextBinding,
            $candidate,
            $observation,
        );
        $this->storage->cow($prefix . $action . '-unit-observation.json', $observed['pinned_bytes'], 65_536);
        $this->after('unit_observation_durable');
        if ($nextBinding !== $binding) {
            $this->storage->refreshBinding(
                $prefix . $action . '-unit-binding.json',
                $bindingBytes,
                DeploymentHostRunnerContractV1::encodeFile($nextBinding),
            );
            $this->after('unit_binding_durable');
        }
        $this->storage->cow($prefix . 'state.json', DeploymentHostRunnerContractV1::encodeFile($candidate), 4_096);
        $this->after('unit_state_durable');
    }

    public function resumeObservationPersistence(string $runId, array $launch): bool
    {
        $action = $launch['action'];
        $prefix = 'runs/' . $runId . '/';
        $observationBytes = $this->storage->read($prefix . $action . '-unit-observation.json', 65_536);
        if ($observationBytes === null) {
            return false;
        }
        $stateBytes = $this->storage->read($prefix . 'state.json', 4_096);
        $bindingBytes = $this->storage->read($prefix . $action . '-unit-binding.json', 16_384);
        if ($stateBytes === null || $bindingBytes === null) {
            throw new RuntimeException('durable observation has no state or binding authority');
        }
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $envelope = json_decode($observationBytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($envelope)) { throw new RuntimeException('durable observation is invalid'); }
        $candidate = $state;
        $candidate['updated_at_utc'] = $this->clock->nowUtc();
        $nextBinding = $binding;
        if (($envelope['schema'] ?? null) === DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA) {
            $observation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation($observationBytes, $launch);
            $result = DeploymentHostRunnerContractV1::classifySystemdObservation(
                $launch,
                DeploymentHostRunnerContractV1::parseSystemctlShow($observation['systemctl_show'], $launch),
            );
            $candidate[$action]['unit_state'] = $result['unit_state'];
            $candidate[$action]['observed_exit_code'] = $result['observed_exit_code'];
            $candidate[$action]['unit_invocation_id'] = $result['unit_invocation_id'];
            if ($binding['binding_state'] === 'reserved' && $result['unit_invocation_id'] !== null) {
                $nextBinding['binding_state'] = 'observed';
                $nextBinding['unit_invocation_id'] = $result['unit_invocation_id'];
            }
        } elseif (($envelope['schema'] ?? null) === DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA) {
            $observation = DeploymentHostRunnerContractV1::decodeUnitAbsence($observationBytes);
            $absence = $observation;
            unset($absence['schema']);
            $candidate[$action]['unit_state'] = DeploymentHostRunnerContractV1::classifyUnitObservation($binding, $absence);
            if ($candidate[$action]['unit_state'] === 'missing') {
                $candidate[$action]['unit_missing_observed_boot_id'] = $absence['manager_boot_id'];
            }
        } else {
            throw new RuntimeException('durable observation schema is invalid');
        }
        if ($candidate[$action]['unit_state'] === $state[$action]['unit_state'] &&
            $candidate[$action]['observed_exit_code'] === $state[$action]['observed_exit_code'] &&
            $candidate[$action]['unit_invocation_id'] === $state[$action]['unit_invocation_id'] &&
            $candidate[$action]['unit_missing_observed_boot_id'] === $state[$action]['unit_missing_observed_boot_id']) {
            DeploymentHostRunnerContractV1::validateUnitReconciliationBundle($launch, $binding, $state, $observation);
            return false;
        }
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidate);
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle($launch, $nextBinding, $candidate, $observation);
        if ($nextBinding !== $binding) {
            $this->storage->refreshBinding(
                $prefix . $action . '-unit-binding.json',
                $bindingBytes,
                DeploymentHostRunnerContractV1::encodeFile($nextBinding),
            );
            $this->after('unit_binding_durable');
        }
        $this->storage->cow($prefix . 'state.json', DeploymentHostRunnerContractV1::encodeFile($candidate), 4_096);
        $this->after('unit_state_durable');
        return true;
    }
}

final class HostRunnerReconciliationPersistence
{
    public function __construct(private readonly HostRunnerStorage $storage) {}

    public function refreshBinding(string $action, string $currentBytes, string $candidateBytes): void
    {
        $current = DeploymentHostRunnerContractV1::decodeUnitBinding($currentBytes);
        $candidate = DeploymentHostRunnerContractV1::decodeUnitBinding($candidateBytes);
        DeploymentHostRunnerContractV1::validateUnitBindingEvolution($current, $candidate);
        if ($current['action'] !== $action) {
            throw new RuntimeException('unit binding action is invalid');
        }
        $this->storage->refreshBinding(
            'runs/' . $current['run_id'] . '/' . $action . '-unit-binding.json',
            $currentBytes,
            $candidateBytes,
        );
    }

    /** @param array<string,mixed> $claim @param array<string,mixed> $state */
    public function reconcileActiveClaim(
        array $claim,
        array $state,
        string $eventsBytes,
        ?string $evidenceBytes,
        string $candidateRunId,
        string $candidateIntentSha256,
        ?string $deployReportBytes = null,
        ?string $rollbackReportBytes = null,
        array $unitBundles = [],
    ): string {
        $disposition = DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $eventsBytes,
            $evidenceBytes,
            $candidateRunId,
            $candidateIntentSha256,
            $deployReportBytes,
            $rollbackReportBytes,
            $unitBundles,
        );
        if ($disposition === 'refresh_terminal_claim') {
            $terminalClaim = [
                'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
                'run_id' => $state['run_id'],
                'intent_sha256' => $state['intent_sha256'],
                'state' => $state['state'],
                'sequence' => $state['sequence'],
                'events_sha256' => $state['events_sha256'],
                'claimed_at_utc' => $state['updated_at_utc'],
            ];
            $this->storage->refreshActiveClaim(
                DeploymentHostRunnerContractV1::encodeFile($claim),
                DeploymentHostRunnerContractV1::encodeFile($terminalClaim),
            );
        } elseif ($disposition === 'clear_terminal') {
            $this->storage->clearActiveClaim(DeploymentHostRunnerContractV1::encodeFile($claim));
        }
        return $disposition;
    }

    public function reconcileStored(string $runId, string $intentSha256): string
    {
        $prefix = 'runs/' . $runId . '/';
        $required = static function (?string $bytes, string $name): string {
            if ($bytes === null) { throw new RuntimeException('missing durable ' . $name); }
            return $bytes;
        };
        $claimBytes = $required($this->storage->read('active-run.json', 4_096), 'active claim');
        $stateBytes = $required($this->storage->read($prefix . 'state.json', 4_096), 'state');
        $eventsBytes = $required($this->storage->read($prefix . 'events.jsonl', 1_048_576), 'journal');
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $evidenceBytes = $state['evidence_sha256'] === null
            ? null
            : $required($this->storage->read($prefix . 'evidence.json', 65_536), 'evidence');
        $deployReportBytes = $state['post_gates']['deploy_submission_count'] === 0
            ? null
            : $required($this->storage->read($prefix . 'deploy-post-gate-report.json', 16_384), 'deploy report');
        $rollbackReportBytes = $state['post_gates']['rollback_submission_count'] === 0
            ? null
            : $required($this->storage->read($prefix . 'rollback-post-gate-report.json', 16_384), 'rollback report');
        $bundles = [];
        if (in_array($state['state'], ['succeeded', ...DeploymentContractV1::TERMINAL_FAILURE_STATES], true)) {
            foreach (['deploy', 'rollback'] as $action) {
                if ($state[$action]['invocation_count'] !== 1) { continue; }
                $bundles[$action] = [
                    'launch' => $required($this->storage->read($prefix . $action . '-systemd-launch.json', 16_384), $action . ' launch'),
                    'binding' => $required($this->storage->read($prefix . $action . '-unit-binding.json', 16_384), $action . ' binding'),
                    'observation' => $required($this->storage->read($prefix . $action . '-unit-observation.json', 65_536), $action . ' observation'),
                ];
            }
        }
        $disposition = DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $eventsBytes,
            $evidenceBytes,
            $runId,
            $intentSha256,
            $deployReportBytes,
            $rollbackReportBytes,
            $bundles,
        );
        if ($disposition === 'refresh_terminal_claim') {
            $terminalClaim = [
                'schema' => DeploymentHostRunnerContractV1::ACTIVE_RUN_SCHEMA,
                'run_id' => $state['run_id'],
                'intent_sha256' => $state['intent_sha256'],
                'state' => $state['state'],
                'sequence' => $state['sequence'],
                'events_sha256' => $state['events_sha256'],
                'claimed_at_utc' => $state['updated_at_utc'],
            ];
            $this->storage->refreshActiveClaim($claimBytes, DeploymentHostRunnerContractV1::encodeFile($terminalClaim));
        } elseif ($disposition === 'clear_terminal') {
            $this->storage->clearActiveClaim($claimBytes);
        }
        return $disposition;
    }
}

final class HostRunnerActionCompletion
{
    private readonly HostRunnerClock $clock;

    public function __construct(
        private readonly HostRunnerStorage $storage,
        ?HostRunnerClock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemHostRunnerClock();
    }

    /** @return array{receipt_bytes:string,receipt:array{schema:string,outcome:string,exit_code:int}} */
    public function requireDeployReceiptForStoppedUnit(string $runId): array
    {
        $prefix = 'runs/' . $runId . '/';
        $stateBytes = $this->storage->read($prefix . 'state.json', 4_096);
        $eventsBytes = $this->storage->read($prefix . 'events.jsonl', 1_048_576);
        $claimBytes = $this->storage->read('active-run.json', 4_096);
        $launchBytes = $this->storage->read($prefix . 'deploy-systemd-launch.json', 16_384);
        $bindingBytes = $this->storage->read($prefix . 'deploy-unit-binding.json', 16_384);
        $observationBytes = $this->storage->read($prefix . 'deploy-unit-observation.json', 65_536);
        $receiptBytes = $this->storage->read($prefix . 'deploy-result.json', 4_096);
        if ($stateBytes === null || $eventsBytes === null || $claimBytes === null || $launchBytes === null || $bindingBytes === null || $observationBytes === null || $receiptBytes === null) {
            throw new RuntimeException('deploy completion authority is incomplete');
        }
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        if (
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current' ||
            $claim['run_id'] !== $runId || $state['run_id'] !== $runId ||
            $state['state'] !== 'deploy_running' || $claim['state'] !== 'deploy_running' ||
            $state['deploy']['receipt_sha256'] !== null ||
            !hash_equals($claim['intent_sha256'], $state['intent_sha256']) ||
            $claim['state'] !== $state['state'] || $claim['sequence'] !== $state['sequence'] ||
            !hash_equals($claim['events_sha256'], $state['events_sha256'])
        ) {
            throw new RuntimeException('deploy completion state and active claim are inconsistent');
        }
        $launch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($launchBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $observationEnvelope = json_decode($observationBytes, true, 32, JSON_THROW_ON_ERROR);
        if (($observationEnvelope['schema'] ?? null) !== DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA) {
            throw new RuntimeException('deploy receipt requires an exact loaded terminal observation');
        }
        $observation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation($observationBytes, $launch);
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle($launch, $binding, $state, $observation);
        if (
            !in_array($state['deploy']['unit_state'], ['exited', 'failed'], true) ||
            $state['deploy']['observed_exit_code'] === null
        ) {
            throw new RuntimeException('deploy receipt cannot precede an independently observed normal exit');
        }
        $receipt = DeployResultV1::decode($receiptBytes);
        if ($receipt['exit_code'] !== $state['deploy']['observed_exit_code']) {
            throw new RuntimeException('deploy receipt contradicts the independently observed normal exit');
        }
        return ['receipt_bytes' => $receiptBytes, 'receipt' => $receipt];
    }

    /**
     * Accept the exact successful child receipt and durably advance the
     * authoritative journal before its derived state cache. A replay after the
     * journal-only crash prefix derives the same state without another unit.
     *
     * @return array<string,mixed> canonical runner response
     */
    public function acceptSucceededDeployReceipt(string $runId): array
    {
        $prefix = 'runs/' . $runId . '/';
        $result = $this->requireDeployReceiptForStoppedUnitOrCurrentSuccess($runId);
        $receiptBytes = $result['receipt_bytes'];
        $receipt = $result['receipt'];
        if ($receipt['outcome'] !== 'succeeded' || $receipt['exit_code'] !== 0) {
            throw new RuntimeException('post-gate transition requires a successful deploy receipt');
        }

        $eventsBytes = $this->required($prefix . 'events.jsonl', 1_048_576);
        $stateBytes = $this->required($prefix . 'state.json', 4_096);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $lines = explode("\n", substr($eventsBytes, 0, -1));
        $run = DeploymentContractV1::validateRunLines($lines);
        if ($run['run_id'] !== $runId || !hash_equals($run['intent_sha256'], $state['intent_sha256'])) {
            throw new RuntimeException('deploy receipt transition identity is invalid');
        }

        if ($run['state'] === 'deploy_running') {
            if ($state['state'] !== 'deploy_running' || DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current') {
                throw new RuntimeException('deploy receipt transition requires current deploy state');
            }
            $recordedAtUtc = $this->clock->nowUtc();
            $lines[] = DeploymentContractV1::canonicalJson([
                'schema' => DeploymentContractV1::RUN_SCHEMA,
                'record_type' => 'transition',
                'run_id' => $runId,
                'sequence' => count($lines) + 1,
                'recorded_at_utc' => $recordedAtUtc,
                'previous_state' => 'deploy_running',
                'state' => 'post_gates_running',
                'deploy_invocation_count' => 1,
                'intent_sha256' => $state['intent_sha256'],
                'exit_code' => 0,
                'reason' => 'ok',
            ]);
            $candidateEventsBytes = implode("\n", $lines) . "\n";
            $this->storage->cow($prefix . 'events.jsonl', $candidateEventsBytes, 1_048_576);
        } elseif ($run['state'] === 'post_gates_running') {
            if ($state['state'] === 'post_gates_running') {
                if (
                    DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current' ||
                    !hash_equals($state['deploy']['receipt_sha256'], hash('sha256', $receiptBytes))
                ) {
                    throw new RuntimeException('durable post-gate state contradicts the successful receipt');
                }
                return $this->acceptedResponse($state, 'attach_observe_only');
            }
            if (
                $state['state'] !== 'deploy_running' ||
                DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'stale_recoverable'
            ) {
                throw new RuntimeException('deploy receipt crash prefix is not recoverable');
            }
            $candidateEventsBytes = $eventsBytes;
            $last = json_decode($lines[array_key_last($lines)], true, 16, JSON_THROW_ON_ERROR);
            $recordedAtUtc = $last['recorded_at_utc'];
        } else {
            throw new RuntimeException('deploy receipt cannot advance the current lifecycle');
        }

        $candidate = $state;
        $candidate['state'] = 'post_gates_running';
        $candidate['sequence'] = count($lines);
        $candidate['events_sha256'] = hash('sha256', $candidateEventsBytes);
        $candidate['active_action'] = 'none';
        $candidate['deploy']['receipt_sha256'] = hash('sha256', $receiptBytes);
        $candidate['updated_at_utc'] = $recordedAtUtc;
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidate);
        if (DeploymentHostRunnerContractV1::stateCacheDisposition($candidate, $candidateEventsBytes) !== 'current') {
            throw new RuntimeException('deploy receipt transition did not produce a current state');
        }
        $this->storage->cow(
            $prefix . 'state.json',
            DeploymentHostRunnerContractV1::encodeFile($candidate),
            4_096,
        );
        return $this->acceptedResponse($candidate, 'attach_observe_only');
    }

    public function submitPostGateReport(string $runId, string $reportBytes): string
    {
        $prefix = 'runs/' . $runId . '/';
        $stateBytes = $this->storage->read($prefix . 'state.json', 4_096);
        $eventsBytes = $this->storage->read($prefix . 'events.jsonl', 1_048_576);
        $claimBytes = $this->storage->read('active-run.json', 4_096);
        if ($stateBytes === null || $eventsBytes === null || $claimBytes === null) {
            throw new RuntimeException('post-gate submission requires durable state and claim');
        }
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
        if (DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current') {
            throw new RuntimeException('post-gate state and active claim are inconsistent');
        }
        if (DeploymentHostRunnerContractV1::activeRunDisposition(
            $claim,
            $state,
            $eventsBytes,
            null,
            $runId,
            $state['intent_sha256'],
        ) !== 'attach_observe_only') {
            throw new RuntimeException('post-gate active claim is not attachable');
        }
        $report = DeploymentHostRunnerContractV1::decodePostGateReport($reportBytes);
        if ($report['run_id'] !== $runId) { throw new RuntimeException('post-gate report run identity is invalid'); }
        $report['subject'] === 'deploy'
            ? $this->validateCompletedDeployAuthority($runId, $state)
            : $this->validateCompletedRollbackAuthority($runId, $state);
        $leaf = $report['subject'] . '-post-gate-report.json';
        $existing = $this->storage->read($prefix . $leaf, 16_384);
        $disposition = DeploymentHostRunnerContractV1::postGateDisposition($reportBytes, $state, $existing);
        if ($existing === null) {
            $this->storage->pin($prefix . $leaf, $reportBytes, 16_384);
        } elseif (!hash_equals($existing, $reportBytes)) {
            throw new RuntimeException('post-gate report conflicts with durable bytes');
        }
        return $disposition;
    }

    /**
     * Pin and reflect a first deploy post-gate report into the monotonic state
     * cache. Terminal publication or rollback reservation consumes that exact
     * report in the following step.
     *
     * @return array{disposition:string,state:array<string,mixed>}
     */
    public function acceptDeployPostGateReport(string $runId, string $reportBytes): array
    {
        $disposition = $this->submitPostGateReport($runId, $reportBytes);
        $report = DeploymentHostRunnerContractV1::decodePostGateReport($reportBytes);
        if ($report['subject'] !== 'deploy') {
            throw new RuntimeException('deploy post-gate state cannot consume a rollback report');
        }
        $prefix = 'runs/' . $runId . '/';
        $eventsBytes = $this->required($prefix . 'events.jsonl', 1_048_576);
        $stateBytes = $this->required($prefix . 'state.json', 4_096);
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        if (
            $state['state'] !== 'post_gates_running' ||
            DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) !== 'current'
        ) {
            throw new RuntimeException('deploy post-gate result requires current post-gate state');
        }
        $sha = hash('sha256', $reportBytes);
        if ($state['post_gates']['deploy_submission_count'] === 1) {
            if (
                !hash_equals($state['post_gates']['deploy_report_sha256'], $sha) ||
                $state['post_gates']['deploy_verdict'] !== $report['post_gates']['status']
            ) {
                throw new RuntimeException('durable deploy post-gate result conflicts with exact report bytes');
            }
            return ['disposition' => $disposition, 'state' => $state];
        }
        $candidate = $state;
        $candidate['post_gates']['deploy_report_sha256'] = $sha;
        $candidate['post_gates']['deploy_submission_count'] = 1;
        $candidate['post_gates']['deploy_verdict'] = $report['post_gates']['status'];
        $candidate['updated_at_utc'] = max($state['updated_at_utc'], $report['captured_at_utc']);
        DeploymentHostRunnerContractV1::validateStateEvolution($state, $candidate);
        $this->storage->cow(
            $prefix . 'state.json',
            DeploymentHostRunnerContractV1::encodeFile($candidate),
            4_096,
        );
        return ['disposition' => $disposition, 'state' => $candidate];
    }

    /** @return array{receipt_bytes:string,receipt:array{schema:string,outcome:string,exit_code:int}} */
    private function requireDeployReceiptForStoppedUnitOrCurrentSuccess(string $runId): array
    {
        try {
            return $this->requireDeployReceiptForStoppedUnit($runId);
        } catch (RuntimeException $error) {
            $prefix = 'runs/' . $runId . '/';
            $stateBytes = $this->storage->read($prefix . 'state.json', 4_096);
            $eventsBytes = $this->storage->read($prefix . 'events.jsonl', 1_048_576);
            $receiptBytes = $this->storage->read($prefix . 'deploy-result.json', 4_096);
            $claimBytes = $this->storage->read('active-run.json', 4_096);
            if ($stateBytes === null || $eventsBytes === null || $receiptBytes === null || $claimBytes === null) {
                throw $error;
            }
            $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
            $receipt = DeployResultV1::decode($receiptBytes);
            $claim = DeploymentHostRunnerContractV1::decodeActiveRun($claimBytes);
            $run = DeploymentContractV1::validateRunLines(explode("\n", substr($eventsBytes, 0, -1)));
            $isJournalOnlyPrefix =
                $state['state'] === 'deploy_running' &&
                $run['state'] === 'post_gates_running' &&
                DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) === 'stale_recoverable';
            $isCurrent =
                $state['state'] === 'post_gates_running' &&
                DeploymentHostRunnerContractV1::stateCacheDisposition($state, $eventsBytes) === 'current';
            if (
                (!$isJournalOnlyPrefix && !$isCurrent) ||
                $receipt['outcome'] !== 'succeeded' ||
                $receipt['exit_code'] !== 0 ||
                $state['deploy']['unit_state'] !== 'exited' ||
                $state['deploy']['observed_exit_code'] !== 0 ||
                ($state['deploy']['receipt_sha256'] !== null &&
                    !hash_equals($state['deploy']['receipt_sha256'], hash('sha256', $receiptBytes))) ||
                $claim['run_id'] !== $runId ||
                !hash_equals($claim['intent_sha256'], $state['intent_sha256'])
            ) {
                throw $error;
            }
            return ['receipt_bytes' => $receiptBytes, 'receipt' => $receipt];
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function acceptedResponse(array $state, string $disposition): array
    {
        $response = [
            'schema' => DeploymentHostRunnerContractV1::RESPONSE_SCHEMA,
            'run_id' => $state['run_id'],
            'intent_sha256' => $state['intent_sha256'],
            'action' => 'deploy',
            'disposition' => $disposition,
            'state' => $state['state'],
            'result_exit_code' => 0,
            'result_reason' => 'ok',
        ];
        DeploymentHostRunnerContractV1::validateResponse($response);
        return $response;
    }

    /** @param array<string,mixed> $state */
    private function validateCompletedDeployAuthority(string $runId, array $state): void
    {
        $prefix = 'runs/' . $runId . '/';
        $launchBytes = $this->required($prefix . 'deploy-systemd-launch.json', 16_384);
        $bindingBytes = $this->required($prefix . 'deploy-unit-binding.json', 16_384);
        $observationBytes = $this->required($prefix . 'deploy-unit-observation.json', 65_536);
        $receiptBytes = $this->required($prefix . 'deploy-result.json', 4_096);
        $launch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($launchBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $observation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation($observationBytes, $launch);
        $receipt = DeployResultV1::decode($receiptBytes);
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle($launch, $binding, $state, $observation);
        if (
            $receipt['outcome'] !== 'succeeded' || $receipt['exit_code'] !== 0 ||
            !hash_equals($state['deploy']['receipt_sha256'], hash('sha256', $receiptBytes)) ||
            $state['deploy']['observed_exit_code'] !== 0
        ) {
            throw new RuntimeException('post-gate deploy authority is inconsistent');
        }
    }

    /** @param array<string,mixed> $state */
    private function validateCompletedRollbackAuthority(string $runId, array $state): void
    {
        $prefix = 'runs/' . $runId . '/';
        $launchBytes = $this->required($prefix . 'rollback-systemd-launch.json', 16_384);
        $bindingBytes = $this->required($prefix . 'rollback-unit-binding.json', 16_384);
        $observationBytes = $this->required($prefix . 'rollback-unit-observation.json', 65_536);
        $deployReportBytes = $this->required($prefix . 'deploy-post-gate-report.json', 16_384);
        $launch = DeploymentHostRunnerContractV1::decodeSystemdLaunch($launchBytes);
        $binding = DeploymentHostRunnerContractV1::decodeUnitBinding($bindingBytes);
        $observation = DeploymentHostRunnerContractV1::decodeUnitLoadedObservation($observationBytes, $launch);
        $deployReport = DeploymentHostRunnerContractV1::decodePostGateReport($deployReportBytes);
        DeploymentHostRunnerContractV1::validateUnitReconciliationBundle($launch, $binding, $state, $observation);
        if (
            DeploymentHostRunnerContractV1::postGateSubmissionDisposition(
                $deployReportBytes,
                $state,
                $deployReportBytes,
            ) !== 'attach' ||
            $state['rollback']['unit_state'] !== 'exited' || $state['rollback']['observed_exit_code'] !== 0 ||
            $state['post_gates']['deploy_verdict'] !== 'failed' ||
            $deployReport['subject'] !== 'deploy' || $deployReport['post_gates']['status'] !== 'failed' ||
            !hash_equals($state['post_gates']['deploy_report_sha256'], hash('sha256', $deployReportBytes))
        ) {
            throw new RuntimeException('post-gate rollback authority is inconsistent');
        }
    }

    private function required(string $relative, int $maxBytes): string
    {
        $bytes = $this->storage->read($relative, $maxBytes);
        if ($bytes === null) { throw new RuntimeException('post-gate action authority is incomplete'); }
        return $bytes;
    }
}

final class HostRunnerStartOrchestrator
{
    private readonly HostRunnerBootReader $bootReader;

    public function __construct(
        private readonly HostRunnerReservationPersistence $persistence,
        private readonly DeploymentHostRunnerV1 $runner,
        ?HostRunnerBootReader $bootReader = null,
    ) {
        $this->bootReader = $bootReader ?? new HelperBackedHostRunnerBootReader();
    }

    /** @param array<string,mixed> $launch @param array<string,mixed> $binding @param array<string,mixed> $input @param array<string,mixed> $request @param ?array<string,mixed> $original */
    public function persistThenAdmit(
        string $runId,
        string $eventsBytes,
        string $claimBytes,
        string $stateBytes,
        array $launch,
        array $binding,
        array $input,
        array $request,
        ?array $original,
        string $deployScriptBytes,
        ?string $failedDeployPostGateReportBytes = null,
    ): string {
        $state = DeploymentHostRunnerContractV1::decodeState($stateBytes);
        $action = $launch['action'] ?? null;
        $actionState = is_string($action) && isset($state[$action]) && is_array($state[$action])
            ? $state[$action]
            : null;
        try {
            $preBoot = $this->bootReader->read();
        } catch (Throwable) {
            return 'unknown';
        }
        $expectedArgv = DeploymentHostRunnerContractV1::systemdRunArgv(
            $launch,
            $binding,
            $preBoot,
            $input,
            $request,
            $original,
            $deployScriptBytes,
        );
        unset($expectedArgv);
        if (
            $actionState === null ||
            $state['active_action'] !== $action ||
            $actionState['invocation_count'] !== 1 ||
            $actionState['unit_name'] !== $launch['unit_name'] ||
            !hash_equals($actionState['unit_launch_sha256'], DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($launch))) ||
            !hash_equals($actionState['unit_manager_boot_id'], $binding['unit_manager_boot_id']) ||
            $actionState['unit_invocation_id'] !== null ||
            !hash_equals($actionState['request_sha256'], DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeFile($request))) ||
            !hash_equals($actionState['execution_input_sha256'], DeploymentHostRunnerContractV1::fileSha256(DeploymentHostRunnerContractV1::encodeExecutionInput($input)))
        ) {
            throw new RuntimeException('reservation state does not bind the admitted launch bundle');
        }
        if ($action === 'rollback') {
            if ($failedDeployPostGateReportBytes === null) {
                throw new RuntimeException('recovery reservation requires the exact failed deploy report');
            }
            $priorStateBytes = $this->persistence->storageReadForValidation(
                'runs/' . $runId . '/state.json',
                4_096,
            );
            if ($priorStateBytes === null) {
                throw new RuntimeException('recovery reservation requires the prior state');
            }
            $priorState = DeploymentHostRunnerContractV1::decodeState($priorStateBytes);
            $report = $this->persistence->requirePinnedFailedDeployReport(
                $runId,
                $failedDeployPostGateReportBytes,
                $priorState,
            );
            if (
                $report['subject'] !== 'deploy' ||
                $report['post_gates']['status'] !== 'failed' ||
                $state['post_gates']['deploy_submission_count'] !== 1 ||
                $state['post_gates']['deploy_verdict'] !== 'failed' ||
                !hash_equals($state['post_gates']['deploy_report_sha256'], hash('sha256', $failedDeployPostGateReportBytes)) ||
                $state['deploy']['receipt_sha256'] !== $report['deploy_receipt_sha256']
            ) {
                throw new RuntimeException('recovery reservation does not bind the failed deploy report');
            }
        } elseif ($failedDeployPostGateReportBytes !== null) {
            throw new RuntimeException('deploy reservation cannot consume a post-gate report');
        }
        $preflight = $this->runner->preflightUnit(
            $launch,
            $binding['unit_manager_boot_id'],
            $preBoot,
        );
        try {
            $postBoot = $this->bootReader->read();
        } catch (Throwable) {
            return 'unknown';
        }
        if (!hash_equals($preBoot, $postBoot)) {
            return 'unknown';
        }
        if ($preflight !== 'available') {
            return $preflight;
        }
        $this->persistence->pinAdmissionBundle($runId, (string) $action, $request, $input, $launch, $binding);
        $this->persistence->persist($runId, $eventsBytes, $claimBytes, $stateBytes);

        try {
            $admissionBoot = $this->bootReader->read();
        } catch (Throwable) {
            return 'observe_only_reconciliation_required';
        }
        if (!hash_equals($postBoot, $admissionBoot)) {
            return 'observe_only_reconciliation_required';
        }
        return $this->runner->admitReservedUnit(
            $launch,
            $binding,
            $admissionBoot,
            $input,
            $request,
            $original,
            $deployScriptBytes,
            true,
        );
    }

    /** @param array<string,mixed> $launch */
    public function resumeReserved(
        string $runId,
        string $eventsBytes,
        string $claimBytes,
        string $stateBytes,
    ): string {
        $disposition = $this->persistence->resumeAfterReservation(
            $runId,
            $eventsBytes,
            $claimBytes,
            $stateBytes,
        );
        $launch = $this->persistence->pinnedLaunchForReservedRun($runId);
        if ($this->persistence->resumeObservationPersistence($runId, $launch)) {
            return $disposition;
        }
        try {
            $preBoot = $this->bootReader->read();
            $observed = $this->runner->observeUnit($launch, $preBoot);
            $postBoot = $this->bootReader->read();
            if (!hash_equals($preBoot, $postBoot)) {
                $observed = self::transportErrorObservation();
            }
        } catch (Throwable) {
            $observed = self::transportErrorObservation();
        }
        $this->persistence->persistObservation($runId, $launch, $observed);
        return $disposition;
    }

    /** @return array{lookup:array<string,mixed>,pinned_bytes:string} */
    private static function transportErrorObservation(): array
    {
        return [
            'lookup' => ['kind' => 'transport_error', 'manager_boot_id' => null, 'loaded_observation' => null],
            'pinned_bytes' => DeploymentHostRunnerContractV1::encodeFile([
                'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                'kind' => 'transport_error',
                'manager_boot_id' => null,
            ]),
        ];
    }
}

final class HelperBackedHostRunnerSystemAdapter implements HostRunnerSystemAdapter
{
    private const MAX_CONTROLLER_RESPONSE_BYTES = 180_000;
    private const HELPER = __DIR__ . '/../libexec/deployment_host_runner_fs_v1.py';
    private const COMMAND = [
        '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', self::HELPER, 'controller',
    ];

    /** @param null|callable(list<string>,string,float):array{exit_code:int,stdout:string} $transport */
    public function __construct(
        private readonly mixed $transport = null,
        private readonly float $wrapperTimeoutSeconds = 125.0,
        private readonly ?string $timeoutProbeToken = null,
    ) {
        if ($wrapperTimeoutSeconds <= 0 || $wrapperTimeoutSeconds > 125.0) {
            throw new RuntimeException('host-runner controller wrapper timeout is invalid');
        }
        if ($timeoutProbeToken !== null && preg_match('/^[0-9a-f]{32}$/D', $timeoutProbeToken) !== 1) {
            throw new RuntimeException('host-runner controller probe token is invalid');
        }
    }

    /** @internal Closed inert process-lifecycle probe used only by the root/Linux regression gate. */
    public static function forTimeoutProbe(string $token, float $wrapperTimeoutSeconds): self
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) {
            throw new RuntimeException('host-runner controller probe token is invalid');
        }

        return new self(
            null,
            $wrapperTimeoutSeconds,
            $token,
        );
    }

    public function run(array $argv, array $environment, int $timeoutSeconds): HostRunnerProcessResult
    {
        if ($environment !== DeploymentHostRunnerV1::CONTROLLER_ENVIRONMENT) {
            throw new RuntimeException('host-runner controller environment is invalid');
        }
        $payload = json_encode(
            ['argv' => $argv, 'environment' => $environment, 'timeout_seconds' => $timeoutSeconds],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $command = self::COMMAND;
        if ($this->timeoutProbeToken !== null) {
            $command = [
                ...array_slice(self::COMMAND, 0, -1),
                'controller-timeout-probe',
                $this->timeoutProbeToken,
            ];
        }
        if (is_callable($this->transport)) {
            $transport = ($this->transport)($command, $payload, $this->wrapperTimeoutSeconds);
            if (!is_array($transport) || array_keys($transport) !== ['exit_code', 'stdout']) {
                throw new RuntimeException('host-runner controller transport is invalid');
            }
            return $this->decodeControllerResult($transport['exit_code'], $transport['stdout']);
        }
        $pipes = [];
        $process = proc_open(
            $command,
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['file', '/dev/null', 'w'],
                198 => ['file', '/dev/null', 'r'],
                199 => ['file', '/dev/null', 'r'],
            ],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('host-runner controller unavailable');
        }
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + $this->wrapperTimeoutSeconds;
        $offset = 0;
        while ($offset < strlen($payload)) {
            $written = fwrite($pipes[0], substr($payload, $offset, 65_536));
            if ($written === false || microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[0]);
                throw new RuntimeException('host-runner controller input failed');
            }
            if ($written === 0) {
                usleep(10_000);
                continue;
            }
            $offset += $written;
        }
        fclose($pipes[0]);
        $stdout = '';
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            if (strlen($stdout) > self::MAX_CONTROLLER_RESPONSE_BYTES) {
                proc_terminate($process, 9);
                break;
            }
            usleep(10_000);
            $status = proc_get_status($process);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        if ($status['running']) {
            proc_terminate($process, 15);
            // The controller owns the actual command process group and may
            // spend up to two seconds TERMing, KILLing, and proving it gone.
            $killDeadline = microtime(true) + 3.0;
            do {
                usleep(10_000);
                $status = proc_get_status($process);
            } while ($status['running'] && microtime(true) < $killDeadline);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
        }
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = $status['exitcode'];
        }
        return $this->decodeControllerResult($exitCode, $stdout);
    }

    private function decodeControllerResult(int $exitCode, string $stdout): HostRunnerProcessResult
    {
        if ($exitCode !== 0 || strlen($stdout) > self::MAX_CONTROLLER_RESPONSE_BYTES) {
            throw new RuntimeException('host-runner controller failed');
        }
        $decoded = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) || array_keys($decoded) !== ['exit_code', 'stderr_base64', 'stdout_base64', 'transport_lost']) {
            throw new RuntimeException('host-runner controller response is invalid');
        }
        foreach (['stdout_base64', 'stderr_base64'] as $field) {
            if (!is_string($decoded[$field]) || base64_decode($decoded[$field], true) === false) {
                throw new RuntimeException('host-runner controller response is invalid');
            }
        }

        return new HostRunnerProcessResult(
            $decoded['exit_code'],
            (string) base64_decode($decoded['stdout_base64'], true),
            (string) base64_decode($decoded['stderr_base64'], true),
            $decoded['transport_lost'],
        );
    }
}

final class DeploymentHostRunnerV1
{
    public const CONTROLLER_ENVIRONMENT = [
        'LANG' => 'C',
        'LC_ALL' => 'C',
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
    ];

    public function __construct(private readonly HostRunnerSystemAdapter $systemAdapter)
    {
    }

    /** @param array<string,mixed> $launch */
    public function preflightUnit(
        array $launch,
        string $expectedManagerBootId,
        string $managerBootIdBytes,
    ): string {
        DeploymentHostRunnerContractV1::validateSystemdLaunch($launch);
        DeploymentHostRunnerContractV1::parseManagerBootId($managerBootIdBytes);
        if ($expectedManagerBootId . "\n" !== $managerBootIdBytes) {
            return 'unknown';
        }
        try {
            $result = $this->systemAdapter->run(
                DeploymentHostRunnerContractV1::systemctlShowArgv($launch['unit_name']),
                self::CONTROLLER_ENVIRONMENT,
                30,
            );
        } catch (Throwable) {
            return 'unknown';
        }
        if ($result->transportLost || $result->exitCode === null) {
            return 'unknown';
        }

        return DeploymentHostRunnerContractV1::unitPreflightDisposition(
            $result->exitCode,
            $result->stdout,
            $result->stderr,
            $launch,
            $expectedManagerBootId,
            $managerBootIdBytes,
        );
    }

    /**
     * @param array<string,mixed> $launch
     * @param array<string,mixed> $binding
     * @param array<string,mixed> $executionInput
     * @param array<string,mixed> $request
     * @param ?array<string,mixed> $originalDeployRequest
     * @return string
     */
    public function admitReservedUnit(
        array $launch,
        array $binding,
        string $managerBootIdBytes,
        array $executionInput,
        array $request,
        ?array $originalDeployRequest,
        string $deployScriptBytes,
        bool $reservationDurable,
    ): string {
        if (!$reservationDurable) {
            throw new RuntimeException('systemd admission requires the durable reservation boundary');
        }
        $argv = DeploymentHostRunnerContractV1::systemdRunArgv(
            $launch,
            $binding,
            $managerBootIdBytes,
            $executionInput,
            $request,
            $originalDeployRequest,
            $deployScriptBytes,
        );
        $exitCode = null;
        try {
            $result = $this->systemAdapter->run($argv, self::CONTROLLER_ENVIRONMENT, 60);
            if (!$result->transportLost) {
                $exitCode = $result->exitCode;
            }
        } catch (Throwable) {
            // The admission call may have reached PID 1.  Its completion is
            // unknowable, so the durable reservation becomes observe-only.
            $exitCode = null;
        }

        return DeploymentHostRunnerContractV1::systemdAdmissionDisposition($reservationDurable, $exitCode);
    }

    /** @param array<string,mixed> $launch @return array{lookup:array<string,mixed>,pinned_bytes:string} */
    public function observeUnit(array $launch, string $managerBootIdBytes): array
    {
        DeploymentHostRunnerContractV1::validateSystemdLaunch($launch);
        DeploymentHostRunnerContractV1::parseManagerBootId($managerBootIdBytes);
        try {
            $result = $this->systemAdapter->run(
                DeploymentHostRunnerContractV1::systemctlShowArgv($launch['unit_name']),
                self::CONTROLLER_ENVIRONMENT,
                30,
            );
        } catch (Throwable) {
            $lookup = ['kind' => 'transport_error', 'manager_boot_id' => null, 'loaded_observation' => null];
            return ['lookup' => $lookup, 'pinned_bytes' => DeploymentHostRunnerContractV1::encodeFile([
                'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                'kind' => 'transport_error',
                'manager_boot_id' => null,
            ])];
        }
        if ($result->transportLost || $result->exitCode === null) {
            $lookup = ['kind' => 'transport_error', 'manager_boot_id' => null, 'loaded_observation' => null];
            return ['lookup' => $lookup, 'pinned_bytes' => DeploymentHostRunnerContractV1::encodeFile([
                'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                'kind' => 'transport_error',
                'manager_boot_id' => null,
            ])];
        }

        try {
            $lookup = DeploymentHostRunnerContractV1::systemctlLookupObservation(
                $result->exitCode,
                $result->stdout,
                $result->stderr,
                $launch,
                $managerBootIdBytes,
            );
            $pinned = $lookup['kind'] === 'loaded'
                ? [
                    'schema' => DeploymentHostRunnerContractV1::UNIT_LOADED_OBSERVATION_SCHEMA,
                    'manager_boot_id' => $lookup['manager_boot_id'],
                    'systemctl_show' => $result->stdout,
                ]
                : [
                    'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                    'kind' => $lookup['kind'],
                    'manager_boot_id' => $lookup['manager_boot_id'],
                ];
            return ['lookup' => $lookup, 'pinned_bytes' => DeploymentHostRunnerContractV1::encodeFile($pinned)];
        } catch (Throwable) {
            $lookup = ['kind' => 'transport_error', 'manager_boot_id' => null, 'loaded_observation' => null];
            return ['lookup' => $lookup, 'pinned_bytes' => DeploymentHostRunnerContractV1::encodeFile([
                'schema' => DeploymentHostRunnerContractV1::UNIT_ABSENCE_SCHEMA,
                'kind' => 'transport_error',
                'manager_boot_id' => null,
            ])];
        }
    }
}

<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/DeploymentEvidenceAuthorityV1.php';
require_once __DIR__ . '/DeploymentHostRunnerContractV1.php';
require_once __DIR__ . '/ProtectedPredeployObservationProvider.php';
require_once __DIR__ . '/TrafficGateV1.php';
require_once __DIR__ . '/../traffic_gate_v1.php';

interface HostRunnerTrafficHelper
{
    /** @return array{status:string,bytes:?string,sha256:?string,started_epoch:int,finished_epoch:int} */
    public function collect(string $runId, string $mode): array;
}

final class HelperBackedHostRunnerTrafficHelper implements HostRunnerTrafficHelper
{
    private const COMMAND_PREFIX = [
        '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', __DIR__ . '/../libexec/deployment_host_runner_fs_v1.py',
        'collect-traffic', DeploymentHostRunnerContractV1::STATE_ROOT,
    ];

    public function collect(string $runId, string $mode): array
    {
        $pipes = [];
        $process = proc_open(
            [...self::COMMAND_PREFIX, $runId, $mode],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w'], 198 => ['file', '/dev/null', 'r'], 199 => ['file', '/dev/null', 'r']],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('traffic authority helper is unavailable');
        }
        stream_set_blocking($pipes[1], false);
        $stdout = '';
        $deadline = microtime(true) + 125.0;
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            if (strlen($stdout) > 400_000) {
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
        $exit = proc_close($process);
        if ($exit === -1) {
            $exit = $status['exitcode'];
        }
        if ($exit !== 0 || strlen($stdout) > 400_000) {
            throw new RuntimeException('traffic authority helper rejected collection');
        }
        try {
            $decoded = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('traffic authority helper response is invalid');
        }
        if (
            !is_array($decoded) || array_is_list($decoded) ||
            array_keys($decoded) !== ['bytes_base64', 'finished_epoch', 'sha256', 'started_epoch', 'status'] ||
            !in_array($decoded['status'], ['pinned', 'attached', 'not_observed'], true) ||
            !is_int($decoded['started_epoch']) || !is_int($decoded['finished_epoch']) ||
            $decoded['started_epoch'] <= 0 || $decoded['finished_epoch'] < $decoded['started_epoch']
        ) {
            throw new RuntimeException('traffic authority helper response is invalid');
        }
        if ($decoded['status'] === 'not_observed') {
            if ($decoded['bytes_base64'] !== null || $decoded['sha256'] !== null) {
                throw new RuntimeException('traffic authority helper invented missing bytes');
            }
            return [
                'status' => 'not_observed', 'bytes' => null, 'sha256' => null,
                'started_epoch' => $decoded['started_epoch'], 'finished_epoch' => $decoded['finished_epoch'],
            ];
        }
        if (!is_string($decoded['bytes_base64']) || !is_string($decoded['sha256'])) {
            throw new RuntimeException('traffic authority helper response lacks bytes');
        }
        $bytes = base64_decode($decoded['bytes_base64'], true);
        if (!is_string($bytes) || !hash_equals($decoded['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('traffic authority helper response contradicts exact bytes');
        }
        return [
            'status' => $decoded['status'], 'bytes' => $bytes, 'sha256' => $decoded['sha256'],
            'started_epoch' => $decoded['started_epoch'], 'finished_epoch' => $decoded['finished_epoch'],
        ];
    }
}

interface HostRunnerTrafficMetadata
{
    /** @return array{producer_sha256:string,catalog_version:string} */
    public function current(): array;
}

final class SystemHostRunnerTrafficMetadata implements HostRunnerTrafficMetadata
{
    private const CATALOG = __DIR__ . '/../config/traffic_gate_catalog.v1.json';
    private const MONITOR_SOURCES = '/etc/fh/traffic-gate-monitor-sources.v1.json';

    public function current(): array
    {
        $catalog = TrafficGateV1::loadCatalog(self::CATALOG, self::MONITOR_SOURCES);
        return [
            'producer_sha256' => \trafficGateProducerSha256(self::CATALOG, null, self::MONITOR_SOURCES),
            'catalog_version' => $catalog['version'],
        ];
    }
}

final class ProtectedHostTrafficCollector
{
    public function __construct(
        private readonly HostRunnerTrafficHelper $helper = new HelperBackedHostRunnerTrafficHelper(),
        private readonly HostRunnerTrafficMetadata $metadata = new SystemHostRunnerTrafficMetadata(),
    ) {}

    public function collect(string $runId, string $mode): TrafficObservationV1
    {
        $before = $this->metadata->current();
        $result = $this->helper->collect($runId, $mode);
        $after = $this->metadata->current();
        if ($before !== $after) {
            throw new RuntimeException('traffic producer authority changed during collection');
        }
        if ($result['bytes'] === null) {
            return new TrafficObservationV1(
                null, null, $before['producer_sha256'], $before['catalog_version'],
                $result['started_epoch'], $result['finished_epoch'],
            );
        }
        $windowStart = $result['started_epoch'];
        $windowEnd = $result['finished_epoch'];
        try {
            $report = json_decode(substr($result['bytes'], 0, -1), true, 64, JSON_THROW_ON_ERROR);
            if (is_array($report) && !array_is_list($report)) {
                $windowStart = $report['window_start_epoch'] ?? $windowStart;
                $windowEnd = $report['window_end_epoch'] ?? $windowEnd;
            }
        } catch (JsonException) {
            // Exact malformed bytes are retained and normalized as invalid by
            // DeploymentEvidenceAuthorityV1.
        }
        if (
            !is_int($windowStart) || !is_int($windowEnd) || $windowStart <= 0 || $windowEnd <= $windowStart ||
            ($result['status'] === 'pinned' &&
                ($windowStart < $result['started_epoch'] || $windowEnd > $result['finished_epoch'] || $windowEnd - $windowStart < 90))
        ) {
            throw new RuntimeException('traffic report freshness is invalid');
        }
        return new TrafficObservationV1(
            $result['bytes'], $result['sha256'], $before['producer_sha256'], $before['catalog_version'],
            $windowStart, $windowEnd,
        );
    }
}

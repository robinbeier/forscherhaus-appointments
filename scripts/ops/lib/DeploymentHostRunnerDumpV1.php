<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/DeploymentHostRunnerContractV1.php';
require_once __DIR__ . '/DeploymentHostRunnerV1.php';
require_once __DIR__ . '/ProtectedPredeployObservationProvider.php';

interface HostRunnerDumpHelper
{
    /** @return array{status:string,attestation_bytes:?string,attestation_sha256:?string,dump_sha256:string,dump_size_bytes:int,observed_at_utc:string} */
    public function observe(string $runId, string $leaf, string $expectedSha256): array;
}

final class HelperBackedHostRunnerDumpHelper implements HostRunnerDumpHelper
{
    private const COMMAND_PREFIX = [
        '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', __DIR__ . '/../libexec/deployment_host_runner_fs_v1.py',
        'observe-dump', DeploymentHostRunnerContractV1::STATE_ROOT,
    ];

    public function observe(string $runId, string $leaf, string $expectedSha256): array
    {
        $pipes = [];
        $process = proc_open(
            [...self::COMMAND_PREFIX, $runId, $leaf, $expectedSha256],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w'], 198 => ['file', '/dev/null', 'r'], 199 => ['file', '/dev/null', 'r']],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('dump authority helper is unavailable');
        }
        stream_set_blocking($pipes[1], false);
        $stdout = '';
        $deadline = microtime(true) + 1_805.0;
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            if (strlen($stdout) > 16_384) {
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
        if ($exit !== 0 || strlen($stdout) > 16_384) {
            throw new RuntimeException('dump authority helper rejected observation');
        }
        try {
            $decoded = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('dump authority helper response is invalid');
        }
        if (
            !is_array($decoded) || array_is_list($decoded) ||
            array_keys($decoded) !== [
                'attestation_bytes_base64', 'attestation_sha256', 'dump_sha256',
                'dump_size_bytes', 'observed_at_utc', 'status',
            ] ||
            !in_array($decoded['status'], ['observed', 'not_observed'], true) ||
            !is_string($decoded['dump_sha256']) || !hash_equals($decoded['dump_sha256'], $expectedSha256) ||
            !is_int($decoded['dump_size_bytes']) || $decoded['dump_size_bytes'] <= 0 ||
            !is_string($decoded['observed_at_utc'])
        ) {
            throw new RuntimeException('dump authority helper response is invalid');
        }
        if ($decoded['status'] === 'not_observed') {
            if ($decoded['attestation_bytes_base64'] !== null || $decoded['attestation_sha256'] !== null) {
                throw new RuntimeException('dump authority helper invented an attestation');
            }
            return [
                'status' => 'not_observed', 'attestation_bytes' => null, 'attestation_sha256' => null,
                'dump_sha256' => $expectedSha256, 'dump_size_bytes' => $decoded['dump_size_bytes'],
                'observed_at_utc' => $decoded['observed_at_utc'],
            ];
        }
        if (!is_string($decoded['attestation_bytes_base64']) || !is_string($decoded['attestation_sha256'])) {
            throw new RuntimeException('dump authority helper response lacks attestation bytes');
        }
        $bytes = base64_decode($decoded['attestation_bytes_base64'], true);
        if (!is_string($bytes) || !hash_equals($decoded['attestation_sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('dump authority helper response contradicts attestation bytes');
        }
        return [
            'status' => 'observed', 'attestation_bytes' => $bytes,
            'attestation_sha256' => $decoded['attestation_sha256'], 'dump_sha256' => $expectedSha256,
            'dump_size_bytes' => $decoded['dump_size_bytes'], 'observed_at_utc' => $decoded['observed_at_utc'],
        ];
    }
}

final class ProtectedHostDumpCollector
{
    public function __construct(
        private readonly HostRunnerStorage $storage,
        private readonly HostRunnerDumpHelper $helper = new HelperBackedHostRunnerDumpHelper(),
    ) {}

    /** @param array{path:string,sha256:string} $reference */
    public function collect(string $runId, array $reference): DumpObservationV1
    {
        if (
            array_keys($reference) !== ['path', 'sha256'] ||
            !is_string($reference['path']) || !str_starts_with($reference['path'], '/') ||
            (!str_ends_with($reference['path'], '.sql') && !str_ends_with($reference['path'], '.sql.gz')) ||
            !is_string($reference['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $reference['sha256']) !== 1
        ) {
            throw new RuntimeException('dump reference authority is invalid');
        }
        $compressed = str_ends_with($reference['path'], '.sql.gz');
        $leaf = $compressed ? 'deploy-ref-zero-surprise-dump.sql.gz' : 'deploy-ref-zero-surprise-dump.sql';
        $operation = $compressed
            ? 'zero-surprise-dump-sql-gz'
            : 'zero-surprise-dump-sql';
        try {
            $this->storage->pinReference($runId, $operation, $reference['path'], $reference['sha256']);
            $result = $this->helper->observe($runId, $leaf, $reference['sha256']);
        } catch (RuntimeException) {
            return new DumpObservationV1(null, null, null, null, null, $reference['sha256'], null, null, null);
        }
        if ($result['status'] === 'not_observed') {
            return new DumpObservationV1(null, null, null, null, null, $result['dump_sha256'], true, null, null);
        }
        return new DumpObservationV1(
            $result['attestation_bytes'], $result['attestation_sha256'], $result['dump_size_bytes'],
            $result['observed_at_utc'], null, $result['dump_sha256'], null, null, null,
        );
    }
}

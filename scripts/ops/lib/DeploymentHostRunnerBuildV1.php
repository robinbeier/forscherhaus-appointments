<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/DeploymentHostRunnerContractV1.php';
require_once __DIR__ . '/ProtectedPredeployObservationProvider.php';

final readonly class HostRunnerBuildAuthorityV1
{
    public function __construct(
        public ExpectedCommitObservationV1 $expectedCommit,
        public BuildVerifiedSourcesV1 $verifiedSources,
    ) {}
}

interface HostRunnerBuildHelper
{
    /** @return array<string,int|string> */
    public function observe(string $releaseId, string $authorizedSha256): array;
}

final class HelperBackedHostRunnerBuildHelper implements HostRunnerBuildHelper
{
    private const COMMAND_PREFIX = [
        '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', __DIR__ . '/../libexec/deployment_host_runner_fs_v1.py',
        'observe-build', '/root/releases',
    ];

    public function observe(string $releaseId, string $authorizedSha256): array
    {
        $pipes = [];
        $process = proc_open(
            [...self::COMMAND_PREFIX, $releaseId, $authorizedSha256],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w'], 198 => ['file', '/dev/null', 'r'], 199 => ['file', '/dev/null', 'r']],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('build authority helper is unavailable');
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
            throw new RuntimeException('build authority helper rejected observation');
        }
        try {
            $record = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('build authority helper response is invalid');
        }
        $keys = [
            'archive_sha256', 'archive_size_bytes', 'artifact_deploy_script_sha256',
            'host_deploy_script_sha256', 'provenance_bytes_base64', 'provenance_sha256',
            'stage_file_count', 'stage_inode_count', 'stage_unpacked_bytes', 'temp_scratch_bytes',
        ];
        if (!is_array($record) || array_is_list($record) || array_keys($record) !== $keys) {
            throw new RuntimeException('build authority helper response is invalid');
        }
        foreach (['archive_sha256', 'artifact_deploy_script_sha256', 'host_deploy_script_sha256', 'provenance_sha256'] as $field) {
            if (!is_string($record[$field]) || preg_match('/^[0-9a-f]{64}$/D', $record[$field]) !== 1) {
                throw new RuntimeException('build authority helper digest is invalid');
            }
        }
        foreach (['archive_size_bytes', 'stage_file_count', 'stage_inode_count', 'stage_unpacked_bytes', 'temp_scratch_bytes'] as $field) {
            if (!is_int($record[$field]) || $record[$field] <= 0) {
                throw new RuntimeException('build authority helper measurement is invalid');
            }
        }
        if (!hash_equals($record['provenance_sha256'], $authorizedSha256) || !is_string($record['provenance_bytes_base64'])) {
            throw new RuntimeException('build authority helper contradicts authorized provenance');
        }
        $bytes = base64_decode($record['provenance_bytes_base64'], true);
        if (!is_string($bytes) || !hash_equals($authorizedSha256, hash('sha256', $bytes))) {
            throw new RuntimeException('build authority helper provenance bytes are invalid');
        }
        $record['provenance_bytes'] = $bytes;
        unset($record['provenance_bytes_base64']);
        return $record;
    }
}

final class ProtectedHostBuildCollector
{
    public function __construct(private readonly HostRunnerBuildHelper $helper = new HelperBackedHostRunnerBuildHelper()) {}

    public function collect(string $releaseId, string $authorizedSha256): HostRunnerBuildAuthorityV1
    {
        $value = $this->helper->observe($releaseId, $authorizedSha256);
        $sources = new BuildVerifiedSourcesV1(
            $value['provenance_bytes'], $authorizedSha256, $releaseId,
            $value['archive_sha256'], $value['archive_size_bytes'],
            $value['host_deploy_script_sha256'], $value['artifact_deploy_script_sha256'],
            $value['stage_file_count'], $value['stage_inode_count'], $value['stage_unpacked_bytes'],
            $value['temp_scratch_bytes'],
        );
        return new HostRunnerBuildAuthorityV1(
            new ExpectedCommitObservationV1($value['provenance_bytes'], $authorizedSha256),
            $sources,
        );
    }
}

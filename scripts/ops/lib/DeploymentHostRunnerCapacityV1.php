<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/DeploymentEvidenceAuthorityV1.php';
require_once __DIR__ . '/ProtectedPredeployObservationProvider.php';

interface HostRunnerCapacityHelper
{
    /** @return array<string,mixed> */
    public function observe(string $runId, string $releaseId, string $rendererMode): array;
}

final class HelperBackedHostRunnerCapacityHelper implements HostRunnerCapacityHelper
{
    private const COMMAND_PREFIX = [
        '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', __DIR__ . '/../libexec/deployment_host_runner_fs_v1.py',
        'observe-capacity', '/var/lib/fh-deploy-orchestrator',
    ];

    public function observe(string $runId, string $releaseId, string $rendererMode): array
    {
        $pipes = [];
        $process = proc_open(
            [...self::COMMAND_PREFIX, $runId, $releaseId, $rendererMode],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w'], 198 => ['file', '/dev/null', 'r'], 199 => ['file', '/dev/null', 'r']],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('capacity authority helper is unavailable');
        }
        stream_set_blocking($pipes[1], false);
        $stdout = '';
        $deadline = microtime(true) + 305.0;
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
            throw new RuntimeException('capacity authority helper rejected observation');
        }
        try {
            $record = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('capacity authority helper response is invalid');
        }
        $keys = [
            'block_size', 'blocks', 'blocks_available', 'component_devices', 'filesystem_device',
            'inodes', 'inodes_available', 'live_storage_allocated_bytes', 'live_storage_inode_count',
            'live_storage_logical_bytes', 'policy_bytes_base64',
        ];
        if (!is_array($record) || array_is_list($record) || array_keys($record) !== $keys) {
            throw new RuntimeException('capacity authority helper response is invalid');
        }
        foreach ([
            'block_size', 'blocks', 'filesystem_device', 'inodes', 'live_storage_allocated_bytes',
            'live_storage_inode_count', 'live_storage_logical_bytes',
        ] as $field) {
            if (!is_int($record[$field]) || $record[$field] <= 0) {
                throw new RuntimeException('capacity authority helper measurement is invalid');
            }
        }
        foreach (['blocks_available', 'inodes_available'] as $field) {
            if (!is_int($record[$field]) || $record[$field] < 0) {
                throw new RuntimeException('capacity authority helper measurement is invalid');
            }
        }
        $deviceKeys = [
            'artifact', 'dump_pin', 'live_storage', 'release_root', 'renderer_state',
            'restore_scratch', 'stage', 'state_root', 'temp',
        ];
        if (!is_array($record['component_devices']) || array_keys($record['component_devices']) !== $deviceKeys) {
            throw new RuntimeException('capacity authority helper devices are invalid');
        }
        foreach ($record['component_devices'] as $device) {
            if (!is_int($device) || $device !== $record['filesystem_device']) {
                throw new RuntimeException('capacity authority helper devices disagree');
            }
        }
        if (!is_string($record['policy_bytes_base64'])) {
            throw new RuntimeException('capacity authority helper policy is invalid');
        }
        $policy = base64_decode($record['policy_bytes_base64'], true);
        if (!is_string($policy) || strlen($policy) > DeploymentEvidenceAuthorityV1::MAX_FILE_BYTES) {
            throw new RuntimeException('capacity authority helper policy is invalid');
        }
        $record['policy_bytes'] = $policy;
        unset($record['policy_bytes_base64']);
        return $record;
    }
}

final class ProtectedHostCapacityCollector
{
    public function __construct(private readonly HostRunnerCapacityHelper $helper = new HelperBackedHostRunnerCapacityHelper()) {}

    public function collect(
        string $runId,
        string $intentSha256,
        string $releaseId,
        string $expectedCommit,
        string $rendererMode,
        BuildVerifiedSourcesV1 $build,
        DumpObservationV1 $dump,
    ): CapacityObservationV1 {
        if (
            $dump->attestationBytes === null || $dump->pinnedAttestationSha256 === null ||
            $dump->dumpSha256 === null || $dump->stableDumpSizeBytes === null || $dump->observedAtUtc === null
        ) {
            return self::invalidObservation();
        }
        try {
            $raw = $this->helper->observe($runId, $releaseId, $rendererMode);
            $renderer = DeploymentEvidenceAuthorityV1::rendererCapacityBounds($raw['policy_bytes'], $rendererMode);
            $sources = new CapacityVerifiedSourcesV1(
                $raw['filesystem_device'], $raw['block_size'], $raw['blocks'], $raw['blocks_available'],
                $raw['inodes'], $raw['inodes_available'], $build,
                $dump->attestationBytes, $dump->pinnedAttestationSha256, $dump->dumpSha256,
                $dump->stableDumpSizeBytes, $dump->observedAtUtc,
                $raw['live_storage_allocated_bytes'], $raw['live_storage_logical_bytes'],
                $raw['live_storage_inode_count'], $renderer['bytes'], $renderer['inodes'],
                $raw['component_devices'],
            );
            $capacity = DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
                $sources->filesystemDevice, $sources->blockSize, $sources->blocks, $sources->blocksAvailable,
                $sources->inodes, $sources->inodesAvailable,
                $build->provenanceBytes, $build->authorizedProvenanceSha256, $releaseId, $expectedCommit,
                $build->stageFileCount, $build->stageInodeCount, $build->stageUnpackedBytes, $build->tempScratchBytes,
                $sources->attestationBytes, $sources->attestationSha256, $runId, $intentSha256,
                $sources->dumpSha256, $sources->dumpSizeBytes, $sources->observedAtUtc,
                $sources->liveStorageAllocatedBytes, $sources->liveStorageLogicalBytes,
                $sources->liveStorageInodeCount, $sources->rendererInstallBytes,
                $sources->rendererInstallInodeCount, $sources->componentDevices,
            );
        } catch (RuntimeException) {
            return self::invalidObservation();
        }
        return new CapacityObservationV1($sources, null, null, null, null, null, null, null, null, null);
    }

    private static function invalidObservation(): CapacityObservationV1
    {
        return new CapacityObservationV1(null, null, null, null, null, null, null, null, null, null);
    }
}

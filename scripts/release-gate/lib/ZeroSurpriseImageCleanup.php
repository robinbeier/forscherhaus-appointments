<?php

declare(strict_types=1);

namespace ReleaseGate;

use Closure;
use RuntimeException;
use Throwable;

final class ZeroSurpriseImageCleanup
{
    private const PROJECT_LABEL = 'com.docker.compose.project';
    private const SERVICE_LABEL = 'com.docker.compose.service';
    private const ALLOWED_SERVICES = ['pdf-renderer', 'php-fpm'];
    private const FAILURE_REASONS = [
        'container_inspect_failed',
        'container_inspect_invalid',
        'container_inventory_failed',
        'container_inventory_invalid',
        'docker_command_invalid_result',
        'docker_storage_measurement_failed',
        'docker_storage_root_changed',
        'docker_storage_root_invalid',
        'docker_storage_root_unavailable',
        'duplicate_image_identity',
        'image_delete_failed',
        'image_delete_unverified',
        'image_delete_verification_failed',
        'image_digest_mismatch',
        'image_digests_invalid',
        'image_has_container_reference',
        'image_identity_mismatch',
        'image_inspect_failed',
        'image_inspect_invalid',
        'image_inventory_failed',
        'image_inventory_invalid',
        'image_project_mismatch',
        'image_service_mismatch',
        'image_size_invalid',
        'image_size_overflow',
        'image_snapshot_changed',
        'image_tag_mismatch',
        'image_tags_invalid',
        'invalid_compose_project',
        'residual_project_image',
    ];

    private readonly Closure $runner;

    private readonly Closure $freeSpace;

    /**
     * @param callable(array<int, string>, string, int): array<string, mixed> $runner
     * @param callable(string): int $freeSpace
     */
    public function __construct(callable $runner, callable $freeSpace)
    {
        $this->runner = Closure::fromCallable($runner);
        $this->freeSpace = Closure::fromCallable($freeSpace);
    }

    public static function production(): self
    {
        return new self(
            static fn(array $command, string $workingDirectory, int $timeoutSeconds): array => GateProcessRunner::run(
                $command,
                $workingDirectory,
                null,
                $timeoutSeconds,
            ),
            static function (string $path): int {
                $bytes = disk_free_space($path);

                if (!is_float($bytes) && !is_int($bytes)) {
                    throw new RuntimeException('docker_storage_measurement_failed');
                }

                if ($bytes < 0 || $bytes > PHP_INT_MAX) {
                    throw new RuntimeException('docker_storage_measurement_failed');
                }

                return (int) $bytes;
            },
        );
    }

    /**
     * @return array{
     *   status:string,
     *   exit_code:int,
     *   duration_ms:float,
     *   details:array{
     *     candidate_count:int,
     *     deleted_count:int,
     *     candidate_virtual_bytes:int,
     *     free_bytes_before:int|null,
     *     free_bytes_after:int|null,
     *     freed_bytes:int|null,
     *     reason:string|null
     *   }
     * }
     */
    public function cleanup(string $project, string $workingDirectory): array
    {
        $startedAt = microtime(true);
        $details = [
            'candidate_count' => 0,
            'deleted_count' => 0,
            'candidate_virtual_bytes' => 0,
            'free_bytes_before' => null,
            'free_bytes_after' => null,
            'freed_bytes' => null,
            'reason' => null,
        ];

        try {
            $this->assertProject($project);
            $dockerRoot = $this->dockerRoot($workingDirectory);
            $details['free_bytes_before'] = ($this->freeSpace)($dockerRoot);

            $snapshot = $this->snapshot($project, $workingDirectory);
            $details['candidate_count'] = count($snapshot);
            $details['candidate_virtual_bytes'] = $this->sumVirtualBytes($snapshot);

            $remaining = $snapshot;

            foreach (array_keys($snapshot) as $imageId) {
                $fresh = $this->snapshot($project, $workingDirectory);
                $this->assertSameSnapshot($remaining, $fresh);
                $this->assertNoContainerReferences(array_keys($remaining), $workingDirectory);

                $delete = $this->run(['docker', 'image', 'rm', $imageId], $workingDirectory, 120);
                if ((int) ($delete['exit_code'] ?? 1) !== 0 || (bool) ($delete['timed_out'] ?? false)) {
                    throw new RuntimeException('image_delete_failed');
                }

                if ($this->imageExists($imageId, $workingDirectory)) {
                    throw new RuntimeException('image_delete_unverified');
                }

                unset($remaining[$imageId]);
                ++$details['deleted_count'];
            }

            if ($this->candidateIds($project, $workingDirectory) !== []) {
                throw new RuntimeException('residual_project_image');
            }

            $freshDockerRoot = $this->dockerRoot($workingDirectory);
            if (!hash_equals($dockerRoot, $freshDockerRoot)) {
                throw new RuntimeException('docker_storage_root_changed');
            }

            $details['free_bytes_after'] = ($this->freeSpace)($freshDockerRoot);
            $details['freed_bytes'] = max(0, $details['free_bytes_after'] - $details['free_bytes_before']);

            return [
                'status' => ZeroSurpriseReport::STATUS_PASS,
                'exit_code' => 0,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'details' => $details,
            ];
        } catch (Throwable $error) {
            $details['reason'] = $this->reason($error);

            return [
                'status' => ZeroSurpriseReport::STATUS_FAIL,
                'exit_code' => 2,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'details' => $details,
            ];
        }
    }

    private function assertProject(string $project): void
    {
        if (preg_match('/^zs-[a-z0-9][a-z0-9-]{0,58}[a-z0-9]$/D', $project) !== 1) {
            throw new RuntimeException('invalid_compose_project');
        }
    }

    private function dockerRoot(string $workingDirectory): string
    {
        $result = $this->run(['docker', 'info', '--format', '{{json .DockerRootDir}}'], $workingDirectory, 30);

        if ((int) ($result['exit_code'] ?? 1) !== 0 || (bool) ($result['timed_out'] ?? false)) {
            throw new RuntimeException('docker_storage_root_unavailable');
        }

        try {
            $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true, 2, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('docker_storage_root_invalid');
        }

        if (!is_string($decoded) || $decoded === '' || !str_starts_with($decoded, '/')) {
            throw new RuntimeException('docker_storage_root_invalid');
        }

        $resolved = realpath($decoded);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException('docker_storage_root_invalid');
        }

        return $resolved;
    }

    /**
     * @return array<string, array{id:string, service:string, tags:array<int, string>, digests:array<int, string>, size:int}>
     */
    private function snapshot(string $project, string $workingDirectory): array
    {
        $ids = $this->candidateIds($project, $workingDirectory);
        if ($ids === []) {
            return [];
        }

        $result = $this->run(array_merge(['docker', 'image', 'inspect'], $ids), $workingDirectory, 60);
        if ((int) ($result['exit_code'] ?? 1) !== 0 || (bool) ($result['timed_out'] ?? false)) {
            throw new RuntimeException('image_inspect_failed');
        }

        try {
            $records = json_decode((string) ($result['stdout'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('image_inspect_invalid');
        }

        if (!is_array($records) || !array_is_list($records) || count($records) !== count($ids)) {
            throw new RuntimeException('image_inspect_invalid');
        }

        $snapshot = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('image_inspect_invalid');
            }

            $id = $record['Id'] ?? null;
            if (!is_string($id) || !$this->validImageId($id) || !in_array($id, $ids, true)) {
                throw new RuntimeException('image_identity_mismatch');
            }

            if (isset($snapshot[$id])) {
                throw new RuntimeException('duplicate_image_identity');
            }

            $labels = $record['Config']['Labels'] ?? null;
            if (!is_array($labels) || ($labels[self::PROJECT_LABEL] ?? null) !== $project) {
                throw new RuntimeException('image_project_mismatch');
            }

            $service = $labels[self::SERVICE_LABEL] ?? null;
            if (!is_string($service) || !in_array($service, self::ALLOWED_SERVICES, true)) {
                throw new RuntimeException('image_service_mismatch');
            }

            $tags = $this->stringList($record['RepoTags'] ?? null, 'image_tags_invalid');
            $expectedTag = $project . '-' . $service . ':latest';
            if ($tags !== [$expectedTag]) {
                throw new RuntimeException('image_tag_mismatch');
            }

            $digests = $this->stringList($record['RepoDigests'] ?? null, 'image_digests_invalid');
            $expectedRepository = substr($expectedTag, 0, -strlen(':latest'));
            $digestMatches =
                count($digests) === 1 &&
                preg_match('/^' . preg_quote($expectedRepository, '/') . '@sha256:[a-f0-9]{64}$/D', $digests[0]) === 1;
            if ($digests !== [] && !$digestMatches) {
                throw new RuntimeException('image_digest_mismatch');
            }

            $size = $record['Size'] ?? null;
            if (!is_int($size) || $size < 0) {
                throw new RuntimeException('image_size_invalid');
            }

            $snapshot[$id] = [
                'id' => $id,
                'service' => $service,
                'tags' => $tags,
                'digests' => $digests,
                'size' => $size,
            ];
        }

        ksort($snapshot, SORT_STRING);
        if (array_keys($snapshot) !== $ids) {
            throw new RuntimeException('image_identity_mismatch');
        }

        return $snapshot;
    }

    /**
     * @return array<int, string>
     */
    private function candidateIds(string $project, string $workingDirectory): array
    {
        $result = $this->run(
            [
                'docker',
                'image',
                'ls',
                '--filter',
                'label=' . self::PROJECT_LABEL . '=' . $project,
                '--quiet',
                '--no-trunc',
            ],
            $workingDirectory,
            30,
        );

        if ((int) ($result['exit_code'] ?? 1) !== 0 || (bool) ($result['timed_out'] ?? false)) {
            throw new RuntimeException('image_inventory_failed');
        }

        $stdout = trim((string) ($result['stdout'] ?? ''));
        if ($stdout === '') {
            return [];
        }

        $ids = preg_split('/\R/', $stdout);
        if (!is_array($ids)) {
            throw new RuntimeException('image_inventory_invalid');
        }

        $ids = array_values(array_unique(array_map('trim', $ids)));
        foreach ($ids as $id) {
            if (!$this->validImageId($id)) {
                throw new RuntimeException('image_inventory_invalid');
            }
        }

        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @param array<int, string> $candidateIds
     */
    private function assertNoContainerReferences(array $candidateIds, string $workingDirectory): void
    {
        if ($candidateIds === []) {
            return;
        }

        $list = $this->run(['docker', 'container', 'ls', '--all', '--quiet', '--no-trunc'], $workingDirectory, 30);
        if ((int) ($list['exit_code'] ?? 1) !== 0 || (bool) ($list['timed_out'] ?? false)) {
            throw new RuntimeException('container_inventory_failed');
        }

        $stdout = trim((string) ($list['stdout'] ?? ''));
        if ($stdout === '') {
            return;
        }

        $containerIds = preg_split('/\R/', $stdout);
        if (!is_array($containerIds) || $containerIds === []) {
            throw new RuntimeException('container_inventory_invalid');
        }

        $containerIds = array_values(array_unique(array_map('trim', $containerIds)));
        foreach ($containerIds as $containerId) {
            if (preg_match('/^[a-f0-9]{64}$/D', $containerId) !== 1) {
                throw new RuntimeException('container_inventory_invalid');
            }
        }

        $inspect = $this->run(array_merge(['docker', 'container', 'inspect'], $containerIds), $workingDirectory, 60);
        if ((int) ($inspect['exit_code'] ?? 1) !== 0 || (bool) ($inspect['timed_out'] ?? false)) {
            throw new RuntimeException('container_inspect_failed');
        }

        try {
            $records = json_decode((string) ($inspect['stdout'] ?? ''), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('container_inspect_invalid');
        }

        if (!is_array($records) || !array_is_list($records) || count($records) !== count($containerIds)) {
            throw new RuntimeException('container_inspect_invalid');
        }

        foreach ($records as $record) {
            $imageId = is_array($record) ? $record['Image'] ?? null : null;
            if (!is_string($imageId) || !$this->validImageId($imageId)) {
                throw new RuntimeException('container_inspect_invalid');
            }

            if (in_array($imageId, $candidateIds, true)) {
                throw new RuntimeException('image_has_container_reference');
            }
        }
    }

    private function imageExists(string $imageId, string $workingDirectory): bool
    {
        $result = $this->run(['docker', 'image', 'inspect', $imageId], $workingDirectory, 30);

        if ((bool) ($result['timed_out'] ?? false)) {
            throw new RuntimeException('image_delete_verification_failed');
        }

        $exitCode = (int) ($result['exit_code'] ?? 1);
        if ($exitCode === 0) {
            return true;
        }

        return $exitCode === 1 ? false : throw new RuntimeException('image_delete_verification_failed');
    }

    /**
     * @param array<string, array{id:string, service:string, tags:array<int, string>, digests:array<int, string>, size:int}> $expected
     * @param array<string, array{id:string, service:string, tags:array<int, string>, digests:array<int, string>, size:int}> $actual
     */
    private function assertSameSnapshot(array $expected, array $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('image_snapshot_changed');
        }
    }

    /**
     * @param array<string, array{id:string, service:string, tags:array<int, string>, digests:array<int, string>, size:int}> $snapshot
     */
    private function sumVirtualBytes(array $snapshot): int
    {
        $total = 0;
        foreach ($snapshot as $image) {
            if ($image['size'] > PHP_INT_MAX - $total) {
                throw new RuntimeException('image_size_overflow');
            }

            $total += $image['size'];
        }

        return $total;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value, string $reason): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException($reason);
        }

        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new RuntimeException($reason);
            }
        }

        sort($value, SORT_STRING);

        return $value;
    }

    private function validImageId(string $imageId): bool
    {
        return preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId) === 1;
    }

    /**
     * @param array<int, string> $command
     * @return array<string, mixed>
     */
    private function run(array $command, string $workingDirectory, int $timeoutSeconds): array
    {
        $result = ($this->runner)($command, $workingDirectory, $timeoutSeconds);
        if (!is_array($result)) {
            throw new RuntimeException('docker_command_invalid_result');
        }

        return $result;
    }

    private function reason(Throwable $error): string
    {
        $reason = $error->getMessage();

        return in_array($reason, self::FAILURE_REASONS, true) ? $reason : 'cleanup_internal_error';
    }
}

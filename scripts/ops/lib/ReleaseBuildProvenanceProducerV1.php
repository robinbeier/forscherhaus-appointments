<?php

declare(strict_types=1);

namespace Ops;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

require_once __DIR__ . '/DeploymentEvidenceAuthorityV1.php';

final class ReleaseBuildProvenanceProducerV1
{
    private const BLOCK_BYTES = 4096;
    private const FIXED_TEMP_OVERHEAD_BYTES = 67_108_864;

    /** @return array<string,mixed> */
    public static function create(
        string $releaseId,
        string $expectedCommit,
        string $stageRoot,
        string $archivePath,
        string $buildScriptPath,
        string $composerLockPath,
        string $packageLockPath,
        string $deployScriptPath,
    ): array {
        $stage = self::inspectStage($stageRoot);
        $archive = self::inspectArchive($archivePath);
        if (
            $stage['file_count'] !== $archive['entry_count'] ||
            $stage['inode_count'] !== $archive['stage_inode_count'] ||
            $stage['unpacked_bytes'] !== $archive['stage_unpacked_bytes']
        ) {
            throw new RuntimeException('release stage and finalized archive inventory disagree');
        }
        $source = [
            'build_script_sha256' => self::regularFileObservation($buildScriptPath)['sha256'],
            'composer_lock_sha256' => self::regularFileObservation($composerLockPath)['sha256'],
            'package_lock_sha256' => self::regularFileObservation($packageLockPath)['sha256'],
            'deploy_ea_sha256' => self::regularFileObservation($deployScriptPath)['sha256'],
        ];
        // Archive and stage are separate capacity components. This field is
        // only the additional temporary/COW metadata allowance.
        $tempScratch = self::FIXED_TEMP_OVERHEAD_BYTES;
        $record = [
            'schema' => DeploymentEvidenceAuthorityV1::BUILD_PROVENANCE_SCHEMA,
            'release_id' => $releaseId,
            'expected_commit' => $expectedCommit,
            'observed_commit' => $expectedCommit,
            'archive' => [
                'name' => $releaseId . '.tar.gz',
                'size_bytes' => $archive['size_bytes'],
                'sha256' => $archive['sha256'],
            ],
            'capacity_bounds' => [
                'stage_file_count' => $archive['entry_count'],
                'stage_inode_count' => $archive['stage_inode_count'],
                'stage_unpacked_bytes' => $archive['stage_unpacked_bytes'],
                'temp_scratch_bytes' => $tempScratch,
            ],
            'source' => $source,
        ];
        DeploymentEvidenceAuthorityV1::decodeAuthorizedBuildProvenance(
            DeploymentEvidenceAuthorityV1::encodeFile($record),
            hash('sha256', DeploymentEvidenceAuthorityV1::encodeFile($record)),
            $releaseId,
            $expectedCommit,
            $record['archive']['sha256'],
            $record['archive']['size_bytes'],
            $record['source']['deploy_ea_sha256'],
            $record['source']['deploy_ea_sha256'],
            $record['capacity_bounds']['stage_file_count'],
            $record['capacity_bounds']['stage_inode_count'],
            $record['capacity_bounds']['stage_unpacked_bytes'],
            $record['capacity_bounds']['temp_scratch_bytes'],
        );
        return $record;
    }

    /** @return array{sha256:string,size_bytes:int,entry_count:int,stage_inode_count:int,stage_unpacked_bytes:int} */
    private static function inspectArchive(string $path): array
    {
        $command = ['/usr/bin/python3', '-I', '-B', dirname(__DIR__) . '/libexec/inspect_release_archive_v1.py', $path];
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, []);
        if (!is_resource($process)) {
            throw new RuntimeException('archive inspector unavailable');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout) || strlen($stdout) > 4096) {
            throw new RuntimeException('release archive inspection failed');
        }
        $record = json_decode($stdout, true);
        if (
            !is_array($record) ||
            array_keys($record) !== [
                'archive_sha256',
                'archive_size_bytes',
                'entry_count',
                'stage_inode_count',
                'stage_unpacked_bytes',
            ]
        ) {
            throw new RuntimeException('release archive observation is malformed');
        }
        if (
            !is_string($record['archive_sha256']) ||
            preg_match('/^[0-9a-f]{64}$/D', $record['archive_sha256']) !== 1 ||
            !is_int($record['archive_size_bytes']) ||
            $record['archive_size_bytes'] <= 0 ||
            !is_int($record['entry_count']) ||
            $record['entry_count'] <= 0 ||
            !is_int($record['stage_inode_count']) ||
            $record['stage_inode_count'] <= $record['entry_count'] ||
            !is_int($record['stage_unpacked_bytes']) ||
            $record['stage_unpacked_bytes'] <= 0
        ) {
            throw new RuntimeException('release archive observation fields are invalid');
        }
        return [
            'sha256' => $record['archive_sha256'],
            'size_bytes' => $record['archive_size_bytes'],
            'entry_count' => $record['entry_count'],
            'stage_inode_count' => $record['stage_inode_count'],
            'stage_unpacked_bytes' => $record['stage_unpacked_bytes'],
        ];
    }

    /** @return array{file_count:int,inode_count:int,unpacked_bytes:int} */
    private static function inspectStage(string $root): array
    {
        $canonical = realpath($root);
        if ($canonical === false || !is_dir($canonical) || is_link($root)) {
            throw new RuntimeException('release stage root is invalid');
        }
        $root = $canonical;
        $files = 0;
        $inodes = 1;
        $bytes = self::BLOCK_BYTES;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $inodes++;
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new RuntimeException('release stage contains an unsafe entry');
            }
            if ($entry->isDir()) {
                $bytes = self::checkedAdd($bytes, self::BLOCK_BYTES);
                continue;
            }
            $stat = lstat($entry->getPathname());
            if (!is_array($stat) || ($stat['nlink'] ?? null) !== 1 || ($stat['size'] ?? -1) < 0) {
                throw new RuntimeException('release stage file identity is unsafe');
            }
            $files++;
            $allocated = max(
                self::BLOCK_BYTES,
                self::checkedMultiply(self::ceilDivide($stat['size'], self::BLOCK_BYTES), self::BLOCK_BYTES),
            );
            $bytes = self::checkedAdd($bytes, $allocated);
        }
        if ($files === 0) {
            throw new RuntimeException('release stage is empty');
        }
        return ['file_count' => $files, 'inode_count' => $inodes, 'unpacked_bytes' => $bytes];
    }

    /** @return array{size_bytes:int,sha256:string} */
    private static function regularFileObservation(string $path): array
    {
        if ($path === '' || is_link($path) || !is_file($path)) {
            throw new RuntimeException('provenance input is unsafe');
        }
        $before = lstat($path);
        $sha = hash_file('sha256', $path);
        $after = lstat($path);
        if (!is_array($before) || !is_array($after) || !is_string($sha)) {
            throw new RuntimeException('provenance input is unreadable');
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                throw new RuntimeException('provenance input changed while observed');
            }
        }
        if (($before['nlink'] ?? null) !== 1 || ($before['size'] ?? 0) <= 0) {
            throw new RuntimeException('provenance input identity is unsafe');
        }
        return ['size_bytes' => $before['size'], 'sha256' => $sha];
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $right > PHP_INT_MAX - $left) {
            throw new RuntimeException('provenance size overflow');
        }
        return $left + $right;
    }

    private static function checkedMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw new RuntimeException('provenance size overflow');
        }
        return $left * $right;
    }

    private static function ceilDivide(int $value, int $divisor): int
    {
        return intdiv($value, $divisor) + ($value % $divisor === 0 ? 0 : 1);
    }
}

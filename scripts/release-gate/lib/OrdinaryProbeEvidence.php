<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Root-only, non-secret operational receipt; never grants recovery authority. */
final class OrdinaryProbeEvidence
{
    private string $path;

    public function __construct(private readonly string $directory)
    {
        $stat = @lstat($directory);
        if (
            PHP_SAPI !== 'cli' ||
            posix_geteuid() !== 0 ||
            !$stat ||
            is_link($directory) ||
            realpath($directory) !== $directory ||
            !is_dir($directory) ||
            $stat['uid'] !== 0 ||
            ($stat['mode'] & 0777) !== 0700
        ) {
            throw new RuntimeException('Private evidence directory required.');
        }
        $this->path = $directory . '/last-evidence.json';
    }

    public function begin(string $release): void
    {
        if (!preg_match('/\Aea_[a-zA-Z0-9_]+\z/D', $release)) {
            throw new RuntimeException('Invalid evidence release.');
        }
        $this->write([
            'version' => 1,
            'release' => $release,
            'started_at' => time(),
            'events' => [],
            'cleanup' => null,
        ]);
    }

    public function step(string $phase, string $outcome): void
    {
        if (
            !in_array(
                $phase,
                [
                    'activate',
                    'account_snapshot',
                    'login_page',
                    'login_validate',
                    'account_page',
                    'get_save',
                    'post_save',
                    'logout',
                    'post_logout',
                    'method_get',
                    'method_head',
                    'method_put',
                    'method_patch',
                    'method_delete',
                    'method_options',
                    'csrf_missing',
                    'csrf_invalid',
                    'csrf_valid_post',
                    'supplemental_activate',
                    'boundary_login',
                    'boundary_search',
                    'boundary_provider_find',
                    'boundary_provider_update',
                    'boundary_provider_destroy',
                    'boundary_admin_find',
                    'boundary_admin_update',
                    'boundary_admin_destroy',
                    'boundary_customer_find',
                    'boundary_customer_update',
                    'boundary_logout',
                    'race_parent_lock',
                    'race_request_wait',
                    'race_reassignment',
                    'race_response',
                    'session',
                    'waiting',
                    'deactivate',
                    'verify',
                ],
                true,
            ) ||
            !in_array($outcome, ['started', 'passed', 'failed'], true)
        ) {
            throw new RuntimeException('Invalid evidence event.');
        }
        $data = $this->read();
        if (count($data['events']) >= 64) {
            if ($phase !== 'deactivate') {
                throw new RuntimeException('Evidence event limit exceeded.');
            }
            // Keep prior evidence bounded without preventing eventual revocation/cleanup.
            if (($data['cleanup_events_omitted'] ?? false) !== true) {
                $data['cleanup_events_omitted'] = true;
                $this->write($data);
            }
            return;
        }
        $data['events'][] = ['phase' => $phase, 'outcome' => $outcome, 'at' => time()];
        $this->write($data);
    }

    /** Cleanup must attempt revocation even when diagnostic storage is unavailable. */
    public function run(string $phase, callable $operation, bool $alwaysAttempt = false): mixed
    {
        $diagnosticFailure = null;
        try {
            $this->step($phase, 'started');
        } catch (\Throwable $error) {
            if (!$alwaysAttempt) {
                throw $error;
            }
            $diagnosticFailure = $error;
        }
        try {
            $result = $operation();
            if ($diagnosticFailure !== null) {
                throw $diagnosticFailure;
            }
            $this->step($phase, 'passed');
            return $result;
        } catch (\Throwable $error) {
            try {
                $this->step($phase, 'failed');
            } catch (\Throwable) {
                // Preserve the operation failure; the caller retains its recovery marker.
            }
            throw $error;
        }
    }

    /** Called only after exact journaled paths were removed and checked absent. */
    public function cleaned(int $tracked, int $removed, int $alreadyAbsent): void
    {
        if (
            $tracked < 0 ||
            $tracked > 64 ||
            $removed < 0 ||
            $alreadyAbsent < 0 ||
            $removed + $alreadyAbsent !== $tracked
        ) {
            throw new RuntimeException('Invalid cleanup counters.');
        }
        $data = $this->read();
        // Retrying journal retirement must preserve the first completed receipt.
        if ($data['cleanup'] !== null) {
            return;
        }
        $data['cleanup'] = [
            'scope' => 'known_journaled_sessions_and_owned_fixtures',
            'at' => time(),
            'tracked' => $tracked,
            'removed' => $removed,
            'already_absent' => $alreadyAbsent,
            'remaining' => 0,
        ];
        $this->write($data);
    }

    public function read(): array
    {
        $this->assertFile();
        $data = json_decode(file_get_contents($this->path), true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) ||
            ($data['version'] ?? null) !== 1 ||
            !is_array($data['events'] ?? null) ||
            !array_key_exists('cleanup', $data)
        ) {
            throw new RuntimeException('Invalid evidence receipt.');
        }
        return $data;
    }

    private function assertFile(): void
    {
        $stat = @lstat($this->path);
        if (
            !$stat ||
            is_link($this->path) ||
            !is_file($this->path) ||
            $stat['uid'] !== 0 ||
            ($stat['mode'] & 0777) !== 0600 ||
            $stat['nlink'] !== 1
        ) {
            throw new RuntimeException('Invalid evidence file.');
        }
    }

    private function write(array $data): void
    {
        if (file_exists($this->path) || is_link($this->path)) {
            $this->assertFile();
        }
        $tmp = $this->path . '.tmp';
        $file = @fopen($tmp, 'x');
        if (!$file) {
            throw new RuntimeException('Evidence temporary file already exists or cannot be created.');
        }
        $created = fstat($file);
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (!chmod($tmp, 0600) || fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) {
                throw new RuntimeException('Evidence synchronization failed.');
            }
            if (!rename($tmp, $this->path)) {
                throw new RuntimeException('Evidence publication failed.');
            }
        } catch (\Throwable $error) {
            // Only undo this invocation's unpublished inode; stale/replaced files remain recovery evidence.
            clearstatcache(true, $tmp);
            $current = @lstat($tmp);
            if (
                $created &&
                $current &&
                !is_link($tmp) &&
                is_file($tmp) &&
                $current['dev'] === $created['dev'] &&
                $current['ino'] === $created['ino'] &&
                $current['nlink'] === 1 &&
                unlink($tmp)
            ) {
                $this->syncDirectory();
            }
            throw $error;
        } finally {
            fclose($file);
        }
        $this->syncDirectory();
    }

    private function syncDirectory(): void
    {
        $directory = fopen($this->directory, 'r');
        if (!$directory) {
            throw new RuntimeException('Evidence directory unavailable.');
        }
        try {
            if (!fsync($directory)) {
                throw new RuntimeException('Evidence directory synchronization failed.');
            }
        } finally {
            fclose($directory);
        }
    }
}

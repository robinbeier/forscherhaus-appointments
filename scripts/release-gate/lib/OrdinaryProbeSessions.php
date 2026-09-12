<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Private provenance journal for session files created by this probe's HTTP responses. */
final class OrdinaryProbeSessions
{
    private string $journal;

    public function __construct(private readonly string $stateDirectory, private readonly string $sessionDirectory)
    {
        foreach ([$stateDirectory, $sessionDirectory] as $directory) {
            if (is_link($directory) || realpath($directory) !== $directory || !is_dir($directory)) {
                throw new RuntimeException('Probe session directory must be canonical.');
            }
        }
        $stat = lstat($stateDirectory);
        if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || $stat['uid'] !== 0 || ($stat['mode'] & 0777) !== 0700) {
            throw new RuntimeException('Private root probe directory required.');
        }
        $this->journal = $stateDirectory . '/sessions.json';
    }

    public function remember(?string $cookie): void
    {
        if ($cookie === null || $cookie === '') {
            return;
        }
        $path = $this->path($cookie);
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return; // Normal logout/rotation may already have removed this file.
        }
        $stat = @lstat($path);
        if (is_link($path) || !is_file($path) || $stat === false) {
            throw new RuntimeException('Unexpected probe session file.');
        }
        $records = $this->read();
        $identity = [$stat['dev'], $stat['ino']];
        if (isset($records[$cookie]) && $records[$cookie] !== $identity) {
            throw new RuntimeException('Probe session inode changed.');
        }
        $records[$cookie] = $identity;
        $tmp = $this->journal . '.tmp';
        $file = fopen($tmp, 'x');
        if (!$file) {
            throw new RuntimeException('Could not exclusively create session journal.');
        }
        try {
            $json = json_encode($records, JSON_THROW_ON_ERROR);
            if (!chmod($tmp, 0600) || fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) {
                throw new RuntimeException('Could not persist private session journal.');
            }
        } finally {
            fclose($file);
        }
        if (!rename($tmp, $this->journal)) {
            throw new RuntimeException('Could not commit private session journal.');
        }
        $this->syncDirectory($this->stateDirectory);
    }

    /**
     * Refuse a new fixture activation while any previous session journal is
     * present. Recovery is deliberately explicit via cleanup(), so an old
     * timer failure cannot be mistaken for a clean starting point.
     */
    public function assertCleanBeforeActivation(): void
    {
        if (is_link($this->journal) || is_link($this->journal . '.tmp')) {
            throw new RuntimeException('Stale or symlinked probe session journal requires explicit recovery.');
        }
        if (file_exists($this->journal . '.tmp')) {
            throw new RuntimeException('Incomplete session journal requires explicit recovery.');
        }
        if (file_exists($this->journal)) {
            throw new RuntimeException('Existing probe session journal requires explicit recovery.');
        }
    }

    /** Read only the identified probe session; never enumerate the shared session directory. */
    public function ownActivity(string $cookie, array $context): int
    {
        $records = $this->read();
        $path = $this->path($cookie);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!$stat || is_link($path) || ($records[$cookie] ?? null) !== [$stat['dev'], $stat['ino']]) {
            throw new RuntimeException('Owned session file is absent or changed.');
        }
        $file = @fopen($path, 'r');
        if (!$file || !flock($file, LOCK_SH)) {
            throw new RuntimeException('Could not read the owned session.');
        }
        try {
            if (
                ($opened = fstat($file)) === false ||
                [$opened['dev'], $opened['ino']] !== [$stat['dev'], $stat['ino']]
            ) {
                throw new RuntimeException('Owned session changed before read.');
            }
            $contents = stream_get_contents($file, 65537);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
        // Read scalar markers only. Never deserialize objects or expose session contents.
        $user = preg_quote((string) $context['user_id'], '~');
        $username = preg_quote((string) $context['username'], '~');
        if (
            strlen($contents) > 65536 ||
            !preg_match('~(?:^|;)user_id\|(?:i:' . $user . ';|s:\d+:"' . $user . '";)~', $contents) ||
            !preg_match('~(?:^|;)username\|s:\d+:"' . $username . '";~', $contents) ||
            !preg_match('~(?:^|;)__ea_last_activity\|i:(\d+);~', $contents, $match)
        ) {
            throw new RuntimeException('Owned authenticated session markers not confirmed.');
        }
        return (int) $match[1];
    }

    public function cleanup(): void
    {
        if (file_exists($this->journal . '.tmp') || is_link($this->journal . '.tmp')) {
            throw new RuntimeException('Incomplete session journal requires explicit recovery.');
        }
        foreach ($this->read() as $cookie => $identity) {
            $path = $this->path($cookie);
            clearstatcache(true, $path);
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            $stat = @lstat($path);
            if (!$stat || is_link($path) || !is_file($path) || [$stat['dev'], $stat['ino']] !== $identity) {
                throw new RuntimeException('Refusing changed session file during cleanup.');
            }
            if (!unlink($path)) {
                throw new RuntimeException('Owned session cleanup failed.');
            }
        }
        // Persist owned file removals before retiring their recovery journal.
        $this->syncDirectory($this->sessionDirectory);
        if (is_file($this->journal) && !unlink($this->journal)) {
            throw new RuntimeException('Session journal cleanup failed.');
        }
        $this->syncDirectory($this->stateDirectory);
    }

    private function syncDirectory(string $path): void
    {
        $directory = fopen($path, 'r');
        if ($directory === false) {
            throw new RuntimeException('Session journal directory could not be opened for synchronization.');
        }
        try {
            if (!fsync($directory)) {
                throw new RuntimeException('Session journal directory synchronization failed.');
            }
        } finally {
            fclose($directory);
        }
    }

    private function path(string $cookie): string
    {
        if (!preg_match('/\A[a-zA-Z0-9,-]{22,256}\z/D', $cookie)) {
            throw new RuntimeException('Invalid probe session identifier.');
        }
        return $this->sessionDirectory . '/ea_session' . $cookie;
    }

    private function read(): array
    {
        if (!file_exists($this->journal) && !is_link($this->journal)) {
            return [];
        }
        $stat = lstat($this->journal);
        if (
            !$stat ||
            is_link($this->journal) ||
            !is_file($this->journal) ||
            $stat['uid'] !== 0 ||
            ($stat['mode'] & 0777) !== 0600
        ) {
            throw new RuntimeException('Invalid private session journal.');
        }
        $data = json_decode(file_get_contents($this->journal), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || count($data) > 64) {
            throw new RuntimeException('Invalid probe session inventory.');
        }
        foreach ($data as $cookie => $identity) {
            $this->path((string) $cookie);
            if (!is_array($identity) || count($identity) !== 2 || !is_int($identity[0]) || !is_int($identity[1])) {
                throw new RuntimeException('Invalid session provenance.');
            }
        }
        return $data;
    }
}

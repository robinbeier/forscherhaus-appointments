<?php

declare(strict_types=1);

final class GithubPrWriteRuntimePolicy
{
    private const CONTRACT_RELATIVE_PATH = '.codex/contracts/agent-workflow.json';
    private const MAX_CONTRACT_BYTES = 262144;
    private const MAX_CANDIDATES = 8;

    /**
     * @return array<string, array{resolved_path: string, sha256: string}>
     */
    public static function loadCandidates(string $repoRoot): array
    {
        $canonicalRoot = realpath($repoRoot);
        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            throw new RuntimeException('GitHub CLI trust manifest repository is unavailable.');
        }

        $contractPath = $canonicalRoot . '/' . self::CONTRACT_RELATIVE_PATH;
        if (!is_file($contractPath) || is_link($contractPath)) {
            throw new RuntimeException('GitHub CLI trust manifest is unavailable.');
        }
        $contents = file_get_contents($contractPath, false, null, 0, self::MAX_CONTRACT_BYTES + 1);
        if (
            !is_string($contents) ||
            $contents === '' ||
            strlen($contents) > self::MAX_CONTRACT_BYTES ||
            str_contains($contents, "\0") ||
            preg_match('//u', $contents) !== 1
        ) {
            throw new RuntimeException('GitHub CLI trust manifest is invalid.');
        }

        try {
            $contract = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('GitHub CLI trust manifest is invalid.');
        }
        $policy = is_array($contract) ? $contract['publish']['github_pr_write_transport'] ?? null : null;
        $candidates = is_array($policy) ? $policy['gh_executable_manifest'] ?? null : null;
        if (
            !is_array($policy) ||
            ($policy['path'] ?? null) !== 'scripts/agent/github_pr_write_transport.php' ||
            ($policy['authority'] ?? null) !== 'primary_only' ||
            ($policy['target_repository'] ?? null) !== GITHUB_PR_WRITE_REPOSITORY ||
            !is_array($candidates) ||
            array_is_list($candidates) ||
            $candidates === [] ||
            count($candidates) > self::MAX_CANDIDATES
        ) {
            throw new RuntimeException('GitHub CLI trust manifest is invalid.');
        }

        $validated = [];
        foreach ($candidates as $candidate => $record) {
            if (
                !is_string($candidate) ||
                $candidate === '' ||
                !str_starts_with($candidate, '/') ||
                basename($candidate) !== 'gh' ||
                !is_array($record) ||
                array_keys($record) !== ['resolved_path', 'sha256'] ||
                !is_string($record['resolved_path'] ?? null) ||
                !str_starts_with($record['resolved_path'], '/') ||
                basename($record['resolved_path']) !== 'gh' ||
                preg_match('/\A[a-f0-9]{64}\z/D', $record['sha256'] ?? '') !== 1
            ) {
                throw new RuntimeException('GitHub CLI trust manifest is invalid.');
            }
            $validated[$candidate] = [
                'resolved_path' => $record['resolved_path'],
                'sha256' => $record['sha256'],
            ];
        }

        return $validated;
    }
}

final class GithubPrWriteRuntime
{
    /**
     * @param array<string, array{resolved_path: string, sha256: string}> $trustedCandidates
     */
    public function __construct(private readonly array $trustedCandidates)
    {
        if ($trustedCandidates === []) {
            throw new InvalidArgumentException('GitHub CLI trust manifest must not be empty.');
        }
    }

    public static function fromRepository(string $repoRoot): self
    {
        return new self(GithubPrWriteRuntimePolicy::loadCandidates($repoRoot));
    }

    public function resolveBinary(): string
    {
        foreach (array_keys($this->trustedCandidates) as $candidate) {
            if (!file_exists($candidate)) {
                continue;
            }

            return $this->validateBinary($candidate);
        }

        throw new RuntimeException('GitHub CLI is unavailable on the fixed path allowlist.');
    }

    public function expectedDigest(string $resolved): string
    {
        foreach ($this->trustedCandidates as $trusted) {
            if ($trusted['resolved_path'] === $resolved) {
                return $trusted['sha256'];
            }
        }

        throw new RuntimeException('GitHub CLI digest is unavailable from the exact trust manifest.');
    }

    public function validateBinary(string $candidate): string
    {
        if ($candidate === '' || !str_starts_with($candidate, '/') || basename($candidate) !== 'gh') {
            throw new RuntimeException('GitHub CLI path is invalid.');
        }
        $trusted = $this->trustedCandidates[$candidate] ?? null;
        if (
            !is_array($trusted) ||
            preg_match('/\A[a-f0-9]{64}\z/D', $trusted['sha256'] ?? '') !== 1 ||
            !is_string($trusted['resolved_path'] ?? null) ||
            !str_starts_with($trusted['resolved_path'], '/')
        ) {
            throw new RuntimeException('GitHub CLI path is not in the exact trust manifest.');
        }

        $resolved = realpath($candidate);
        if (
            $resolved === false ||
            $resolved !== $trusted['resolved_path'] ||
            !is_file($resolved) ||
            !is_executable($resolved) ||
            is_link($resolved)
        ) {
            throw new RuntimeException('GitHub CLI is unavailable.');
        }
        if (!function_exists('posix_geteuid')) {
            throw new RuntimeException('GitHub CLI ownership cannot be verified.');
        }

        $owner = fileowner($resolved);
        $mode = fileperms($resolved);
        if (
            !is_int($owner) ||
            !in_array($owner, [0, posix_geteuid()], true) ||
            !is_int($mode) ||
            ($mode & 0o022) !== 0 ||
            !hash_equals($trusted['sha256'], hash_file('sha256', $resolved) ?: '')
        ) {
            throw new RuntimeException('GitHub CLI ownership, mode, or digest is unsafe.');
        }

        return $resolved;
    }

    /**
     * @param array{name: string, dir: string, uid: int}|null $accountOverride
     * @return array{environment: array<string, string>, config_dir: string, gh_binary: string}
     */
    public function create(string $ghSource, ?array $accountOverride = null, string $temporaryRoot = '/tmp'): array
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
            throw new RuntimeException('OS account lookup is unavailable.');
        }
        $effectiveUid = posix_geteuid();
        $account = $accountOverride ?? posix_getpwuid($effectiveUid);
        if (
            !is_array($account) ||
            !is_string($account['name'] ?? null) ||
            !is_string($account['dir'] ?? null) ||
            !is_int($account['uid'] ?? null) ||
            $account['uid'] !== $effectiveUid
        ) {
            throw new RuntimeException('OS account lookup failed.');
        }
        $home = realpath($account['dir']);
        if ($home === false || !is_dir($home) || is_link($account['dir']) || fileowner($home) !== $effectiveUid) {
            throw new RuntimeException('OS account home is unsafe.');
        }

        $nativeConfig = $home . '/.config/gh';
        $hostsFile = $nativeConfig . '/hosts.yml';
        if (
            !is_dir($nativeConfig) ||
            is_link($nativeConfig) ||
            fileowner($nativeConfig) !== $effectiveUid ||
            !is_file($hostsFile) ||
            is_link($hostsFile) ||
            fileowner($hostsFile) !== $effectiveUid
        ) {
            throw new RuntimeException('Native GitHub authentication metadata is unsafe.');
        }
        $nativeConfigMode = fileperms($nativeConfig);
        $hostsMode = fileperms($hostsFile);
        if (
            !is_int($nativeConfigMode) ||
            ($nativeConfigMode & 0o022) !== 0 ||
            !is_int($hostsMode) ||
            ($hostsMode & 0o077) !== 0
        ) {
            throw new RuntimeException('Native GitHub authentication metadata is unsafe.');
        }

        $resolvedTemporaryRoot = realpath($temporaryRoot);
        if ($resolvedTemporaryRoot === false || !is_dir($resolvedTemporaryRoot)) {
            throw new RuntimeException('Private GitHub CLI runtime root is unavailable.');
        }
        $configDir = '';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $resolvedTemporaryRoot . '/github-pr-write-gh-' . bin2hex(random_bytes(16));
            if (@mkdir($candidate, 0700)) {
                $configDir = $candidate;
                break;
            }
        }
        if ($configDir === '') {
            throw new RuntimeException('Private GitHub CLI configuration could not be created.');
        }
        if (!@symlink($hostsFile, $configDir . '/hosts.yml')) {
            @rmdir($configDir);
            throw new RuntimeException('Native GitHub authentication metadata could not be isolated.');
        }

        try {
            $resolvedSource = realpath($ghSource);
            if ($resolvedSource === false) {
                throw new RuntimeException('GitHub CLI source could not be resolved safely.');
            }
            $ghBinary = $this->materializeBinary($resolvedSource, $this->expectedDigest($resolvedSource), $configDir);
        } catch (Throwable $exception) {
            self::remove($configDir, $configDir . '/gh');
            throw $exception;
        }

        return [
            'environment' => [
                'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
                'HOME' => $home,
                'USER' => $account['name'],
                'LOGNAME' => $account['name'],
                'TMPDIR' => '/tmp',
                'LANG' => 'C',
                'LC_ALL' => 'C',
                'GH_CONFIG_DIR' => $configDir,
                'GH_PROMPT_DISABLED' => '1',
                'NO_COLOR' => '1',
            ],
            'config_dir' => $configDir,
            'gh_binary' => $ghBinary,
        ];
    }

    public static function remove(string $configDir, string $ghBinary): void
    {
        if (dirname($ghBinary) === $configDir && basename($ghBinary) === 'gh' && !is_link($ghBinary)) {
            @chmod($ghBinary, 0600);
            @unlink($ghBinary);
        }
        $hostsLink = $configDir . '/hosts.yml';
        if (is_link($hostsLink)) {
            @unlink($hostsLink);
        }
        if (is_dir($configDir)) {
            @rmdir($configDir);
        }
    }

    public function materializeBinary(string $source, string $expectedDigest, string $configDir): string
    {
        if (
            $source === '' ||
            !str_starts_with($source, '/') ||
            preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1 ||
            realpath($configDir) !== $configDir ||
            !is_dir($configDir) ||
            is_link($configDir)
        ) {
            throw new RuntimeException('Private GitHub CLI executable input is invalid.');
        }
        if (!function_exists('posix_geteuid')) {
            throw new RuntimeException('GitHub CLI ownership cannot be verified.');
        }

        $sourceHandle = @fopen($source, 'rb');
        if (!is_resource($sourceHandle)) {
            throw new RuntimeException('GitHub CLI source could not be opened safely.');
        }

        $target = $configDir . '/gh';
        $targetHandle = false;
        try {
            $before = fstat($sourceHandle);
            if (
                !is_array($before) ||
                (($before['mode'] ?? 0) & 0o170000) !== 0o100000 ||
                !in_array($before['uid'] ?? null, [0, posix_geteuid()], true) ||
                (($before['mode'] ?? 0) & 0o022) !== 0 ||
                !is_int($before['size'] ?? null) ||
                $before['size'] <= 0
            ) {
                throw new RuntimeException('GitHub CLI source handle is unsafe.');
            }

            $targetHandle = @fopen($target, 'x+b');
            if (!is_resource($targetHandle)) {
                throw new RuntimeException('Private GitHub CLI executable could not be created.');
            }

            $hash = hash_init('sha256');
            $copiedBytes = 0;
            while (!feof($sourceHandle)) {
                $chunk = fread($sourceHandle, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('GitHub CLI source could not be read safely.');
                }
                if ($chunk === '') {
                    if (feof($sourceHandle)) {
                        break;
                    }
                    throw new RuntimeException('GitHub CLI source could not be read safely.');
                }
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($targetHandle, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Private GitHub CLI executable could not be written safely.');
                    }
                    $offset += $written;
                    $copiedBytes += $written;
                }
            }
            if (!fflush($targetHandle)) {
                throw new RuntimeException('Private GitHub CLI executable could not be flushed safely.');
            }

            $after = fstat($sourceHandle);
            foreach (['dev', 'ino', 'uid', 'gid', 'mode', 'size', 'mtime', 'ctime'] as $field) {
                if (!is_array($after) || ($before[$field] ?? null) !== ($after[$field] ?? null)) {
                    throw new RuntimeException('GitHub CLI source changed while it was copied.');
                }
            }
            if ($copiedBytes !== $before['size'] || !hash_equals($expectedDigest, hash_final($hash))) {
                throw new RuntimeException('Private GitHub CLI executable digest is unsafe.');
            }
        } finally {
            fclose($sourceHandle);
            if (is_resource($targetHandle)) {
                fclose($targetHandle);
            }
        }

        if (!@chmod($target, 0500)) {
            @unlink($target);
            throw new RuntimeException('Private GitHub CLI executable mode could not be restricted.');
        }
        clearstatcache(true, $target);
        $targetStat = @lstat($target);
        if (
            !is_array($targetStat) ||
            (($targetStat['mode'] ?? 0) & 0o170000) !== 0o100000 ||
            (($targetStat['mode'] ?? 0) & 0o777) !== 0o500 ||
            ($targetStat['uid'] ?? null) !== posix_geteuid() ||
            realpath($target) !== $target ||
            !is_executable($target) ||
            !hash_equals($expectedDigest, hash_file('sha256', $target) ?: '')
        ) {
            @chmod($target, 0600);
            @unlink($target);
            throw new RuntimeException('Private GitHub CLI executable attestation failed.');
        }

        return $target;
    }
}

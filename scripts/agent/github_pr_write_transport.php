<?php

declare(strict_types=1);

const GITHUB_PR_WRITE_REPOSITORY = 'robinbeier/forscherhaus-appointments';
const GITHUB_PR_WRITE_BASE_REF = 'main';
const GITHUB_PR_WRITE_MAX_BODY_BYTES = 65536;
const GITHUB_PR_WRITE_MAX_TITLE_BYTES = 256;
const GITHUB_PR_WRITE_MAX_INPUT_BYTES = 400000;
const GITHUB_PR_WRITE_MAX_COMMAND_OUTPUT_BYTES = 131072;
const GITHUB_PR_WRITE_TARGET_PROJECTION = '{number: .number, state: .state, base: {ref: .base.ref, repo: {full_name: .base.repo.full_name}}, head: {ref: .head.ref, sha: .head.sha, repo: {full_name: .head.repo.full_name}}}';
const GITHUB_PR_WRITE_COMMENT_ID_PROJECTION = '{id: .id}';
const GITHUB_PR_WRITE_COMMENT_PROJECTION = '{id: .id, url: .url, issue_url: .issue_url, body: .body}';
const GITHUB_PR_WRITE_GH_CANDIDATES = [
    '/opt/homebrew/bin/gh' => [
        'resolved_path' => '/opt/homebrew/Cellar/gh/2.88.0/bin/gh',
        'sha256' => '07661fba523076af4a0bfea9d3862b2855c05561d70e6f7629c60cdade1a9abf',
    ],
];

final class GithubPrWriteTransport
{
    /**
     * @param list<string> $arguments
     * @return array{operation: string, repo: string, number: int}
     */
    private static function parseArguments(array $arguments): array
    {
        $operation = array_shift($arguments);
        if (!in_array($operation, ['update-pr', 'create-comment'], true)) {
            throw new InvalidArgumentException('Operation must be update-pr or create-comment.');
        }

        $values = [];
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!is_string($argument) || !str_starts_with($argument, '--')) {
                throw new InvalidArgumentException('Options must use the --name value form.');
            }

            $name = substr($argument, 2);
            if (!in_array($name, ['repo', 'number'], true) || array_key_exists($name, $values)) {
                throw new InvalidArgumentException('Unknown or duplicate option.');
            }

            $value = array_shift($arguments);
            if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                throw new InvalidArgumentException('Every option requires a non-empty value.');
            }

            $values[$name] = $value;
        }

        if (($values['repo'] ?? '') !== GITHUB_PR_WRITE_REPOSITORY) {
            throw new InvalidArgumentException('Repository must match the canonical repository.');
        }

        $number = filter_var($values['number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($number)) {
            throw new InvalidArgumentException('Number must be a positive integer.');
        }

        return ['operation' => $operation, 'repo' => GITHUB_PR_WRITE_REPOSITORY, 'number' => $number];
    }

    /**
     * @return array{title?: string, body?: string}
     */
    private static function parsePayload(string $operation, string $input): array
    {
        if (strlen($input) > GITHUB_PR_WRITE_MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('JSON input exceeds the bounded request size.');
        }
        if ($input === '' || str_contains($input, "\0") || preg_match('//u', $input) !== 1) {
            throw new InvalidArgumentException('JSON input must be non-empty UTF-8 without NUL bytes.');
        }

        try {
            $payload = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('JSON input is invalid.');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('JSON input must be an object.');
        }

        $allowedFieldSets = $operation === 'update-pr' ? [['body'], ['title'], ['body', 'title']] : [['body']];
        $actualFields = array_keys($payload);
        sort($actualFields);
        if (!in_array($actualFields, $allowedFieldSets, true)) {
            throw new InvalidArgumentException('JSON input fields do not match the operation.');
        }

        foreach ($payload as $name => $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException($name . ' must be a string.');
            }
            $maximumBytes = $name === 'title' ? GITHUB_PR_WRITE_MAX_TITLE_BYTES : GITHUB_PR_WRITE_MAX_BODY_BYTES;
            if (strlen($value) > $maximumBytes) {
                throw new InvalidArgumentException($name . ' exceeds the bounded payload size.');
            }
            if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException($name . ' must be UTF-8 text without NUL bytes.');
            }
        }

        if (
            isset($payload['title']) &&
            ($payload['title'] === '' || str_contains($payload['title'], "\n") || str_contains($payload['title'], "\r"))
        ) {
            throw new InvalidArgumentException('title must contain one non-empty line without a terminator.');
        }
        if ($operation === 'create-comment' && ($payload['body'] ?? '') === '') {
            throw new InvalidArgumentException('comment body must not be empty.');
        }

        /** @var array{title?: string, body?: string} $payload */
        return $payload;
    }

    /**
     * @param array{name: string, dir: string, uid: int}|null $accountOverride
     * @return array{environment: array<string, string>, config_dir: string, gh_binary: string}
     */
    private static function createGhRuntime(
        string $ghSource,
        string $expectedDigest,
        ?array $accountOverride = null,
        string $temporaryRoot = '/tmp',
    ): array {
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
            $ghBinary = self::materializeGhBinary($ghSource, $expectedDigest, $configDir);
        } catch (Throwable $exception) {
            self::removeGhRuntime($configDir, $configDir . '/gh');
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

    private static function removeGhRuntime(string $configDir, string $ghBinary): void
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

    private static function materializeGhBinary(string $source, string $expectedDigest, string $configDir): string
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

    /**
     * @param array<string, array{resolved_path: string, sha256: string}>|null $trustedCandidates
     */
    private static function validateGhBinary(string $candidate, ?array $trustedCandidates = null): string
    {
        if ($candidate === '' || !str_starts_with($candidate, '/') || basename($candidate) !== 'gh') {
            throw new RuntimeException('GitHub CLI path is invalid.');
        }
        $trustedCandidates ??= GITHUB_PR_WRITE_GH_CANDIDATES;
        $trusted = $trustedCandidates[$candidate] ?? null;
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

    private static function resolveGhBinary(): string
    {
        foreach (array_keys(GITHUB_PR_WRITE_GH_CANDIDATES) as $candidate) {
            if (!file_exists($candidate)) {
                continue;
            }

            return self::validateGhBinary($candidate);
        }

        throw new RuntimeException('GitHub CLI is unavailable on the fixed path allowlist.');
    }

    private static function expectedGhDigest(string $resolved): string
    {
        foreach (GITHUB_PR_WRITE_GH_CANDIDATES as $trusted) {
            if (($trusted['resolved_path'] ?? null) === $resolved) {
                return $trusted['sha256'];
            }
        }

        throw new RuntimeException('GitHub CLI digest is unavailable from the exact trust manifest.');
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private static function runCommand(array $command, string $stdin, array $environment): array
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment, [
            'bypass_shell' => true,
        ]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the bounded child process.');
        }

        $offset = 0;
        while ($offset < strlen($stdin)) {
            $written = fwrite($pipes[0], substr($stdin, $offset));
            if ($written === false || $written === 0) {
                fclose($pipes[0]);
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('Unable to deliver bounded child input.');
            }
            $offset += $written;
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1], GITHUB_PR_WRITE_MAX_COMMAND_OUTPUT_BYTES + 1);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2], GITHUB_PR_WRITE_MAX_COMMAND_OUTPUT_BYTES + 1);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';
        if (
            strlen($stdout) > GITHUB_PR_WRITE_MAX_COMMAND_OUTPUT_BYTES ||
            strlen($stderr) > GITHUB_PR_WRITE_MAX_COMMAND_OUTPUT_BYTES
        ) {
            throw new RuntimeException('Child process output exceeded the bounded size.');
        }

        return [
            'exit_code' => is_int($exitCode) ? $exitCode : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /** @return array{sha: string, branch: string} */
    private static function resolveLocalTarget(): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $resolvedRoot = realpath($repoRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new RuntimeException('Canonical repository checkout is unavailable.');
        }

        $environment = [
            'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
            'TMPDIR' => '/tmp',
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_NO_LAZY_FETCH' => '1',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_TERMINAL_PROMPT' => '0',
        ];
        $common = [
            '/usr/bin/git',
            '-c',
            'core.fsmonitor=false',
            '-c',
            'core.hooksPath=/dev/null',
            '-c',
            'credential.helper=',
            '-C',
            $resolvedRoot,
        ];

        $topLevel = self::runCommand([...$common, 'rev-parse', '--show-toplevel'], '', $environment);
        if ($topLevel['exit_code'] !== 0 || realpath(trim($topLevel['stdout'])) !== $resolvedRoot) {
            throw new RuntimeException('Canonical repository checkout could not be verified.');
        }

        $snapshot = self::runCommand(
            [
                ...$common,
                'status',
                '--porcelain=v2',
                '--branch',
                '--untracked-files=no',
                '--ignore-submodules=all',
                '--no-renames',
                '-z',
            ],
            '',
            $environment,
        );
        if ($snapshot['exit_code'] !== 0 || !str_ends_with($snapshot['stdout'], "\0")) {
            throw new RuntimeException('Canonical repository target snapshot could not be verified.');
        }

        $headSha = null;
        $branchName = null;
        $records = explode("\0", $snapshot['stdout']);
        array_pop($records);
        foreach ($records as $record) {
            if (str_starts_with($record, '# branch.oid ')) {
                if ($headSha !== null) {
                    throw new RuntimeException('Canonical repository target snapshot is ambiguous.');
                }
                $headSha = substr($record, strlen('# branch.oid '));
            } elseif (str_starts_with($record, '# branch.head ')) {
                if ($branchName !== null) {
                    throw new RuntimeException('Canonical repository target snapshot is ambiguous.');
                }
                $branchName = substr($record, strlen('# branch.head '));
            }
        }
        if (!is_string($headSha) || preg_match('/\A[a-f0-9]{40}\z/D', $headSha) !== 1) {
            throw new RuntimeException('Canonical repository HEAD could not be verified.');
        }
        if (
            !is_string($branchName) ||
            $branchName === '' ||
            $branchName === '(detached)' ||
            strlen($branchName) > 255 ||
            str_contains($branchName, "\0") ||
            str_contains($branchName, "\n") ||
            str_contains($branchName, "\r") ||
            preg_match('//u', $branchName) !== 1
        ) {
            throw new RuntimeException('Canonical repository branch could not be verified.');
        }
        $branchFormat = self::runCommand([...$common, 'check-ref-format', '--branch', $branchName], '', $environment);
        if ($branchFormat['exit_code'] !== 0 || rtrim($branchFormat['stdout'], "\r\n") !== $branchName) {
            throw new RuntimeException('Canonical repository branch format could not be verified.');
        }

        return ['sha' => $headSha, 'branch' => $branchName];
    }

    /**
     * @param list<string> $requestedFields
     * @param array<string, string> $environment
     * @return array<string, mixed>
     */
    private static function verifyTarget(
        string $ghBinary,
        string $repo,
        int $number,
        string $localHead,
        string $localBranch,
        array $requestedFields,
        array $environment,
    ): array {
        sort($requestedFields);
        $projection = match ($requestedFields) {
            [] => GITHUB_PR_WRITE_TARGET_PROJECTION,
            ['body'] => GITHUB_PR_WRITE_TARGET_PROJECTION . ' + {body: .body}',
            ['title'] => GITHUB_PR_WRITE_TARGET_PROJECTION . ' + {title: .title}',
            ['body', 'title'] => GITHUB_PR_WRITE_TARGET_PROJECTION . ' + {body: .body, title: .title}',
            default => throw new LogicException('GitHub pull request projection fields are invalid.'),
        };
        $endpoint = 'repos/' . $repo . '/pulls/' . $number;
        $result = self::runCommand(
            [$ghBinary, 'api', '--hostname', 'github.com', '--method', 'GET', $endpoint, '--jq', $projection],
            '',
            $environment,
        );
        if ($result['exit_code'] !== 0) {
            throw new UnexpectedValueException('GitHub pull request target could not be verified.');
        }

        try {
            $record = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('GitHub pull request target returned invalid metadata.');
        }
        if (
            !is_array($record) ||
            ($record['number'] ?? null) !== $number ||
            ($record['state'] ?? null) !== 'open' ||
            ($record['base']['ref'] ?? null) !== GITHUB_PR_WRITE_BASE_REF ||
            ($record['base']['repo']['full_name'] ?? null) !== GITHUB_PR_WRITE_REPOSITORY ||
            ($record['head']['repo']['full_name'] ?? null) !== GITHUB_PR_WRITE_REPOSITORY ||
            ($record['head']['ref'] ?? null) !== $localBranch ||
            ($record['head']['sha'] ?? null) !== $localHead
        ) {
            throw new UnexpectedValueException(
                'GitHub pull request target does not match the canonical exact local target.',
            );
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @param array{title?: string, body?: string} $payload
     */
    private static function verifyUpdatedFields(array $record, array $payload): void
    {
        foreach ($payload as $field => $expectedValue) {
            if (!array_key_exists($field, $record) || $record[$field] !== $expectedValue) {
                throw new UnexpectedValueException('GitHub pull request update could not be verified.');
            }
        }
    }

    private static function extractCommentId(string $response): ?int
    {
        try {
            $record = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $commentId = is_array($record) ? $record['id'] ?? null : null;
        return is_int($commentId) && $commentId > 0 ? $commentId : null;
    }

    /** @param array<string, string> $environment */
    private static function verifyCreatedComment(
        string $ghBinary,
        string $repo,
        int $number,
        int $commentId,
        string $expectedBody,
        array $environment,
    ): void {
        $endpoint = 'repos/' . $repo . '/issues/comments/' . $commentId;
        $result = self::runCommand(
            [
                $ghBinary,
                'api',
                '--hostname',
                'github.com',
                '--method',
                'GET',
                $endpoint,
                '--jq',
                GITHUB_PR_WRITE_COMMENT_PROJECTION,
            ],
            '',
            $environment,
        );
        if ($result['exit_code'] !== 0) {
            throw new UnexpectedValueException('GitHub comment state could not be verified.');
        }

        try {
            $record = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('GitHub comment state returned invalid metadata.');
        }

        $expectedCommentUrl = 'https://api.github.com/repos/' . $repo . '/issues/comments/' . $commentId;
        $expectedIssueUrl = 'https://api.github.com/repos/' . $repo . '/issues/' . $number;
        if (
            !is_array($record) ||
            ($record['id'] ?? null) !== $commentId ||
            ($record['url'] ?? null) !== $expectedCommentUrl ||
            ($record['issue_url'] ?? null) !== $expectedIssueUrl ||
            !is_string($record['body'] ?? null) ||
            $record['body'] !== $expectedBody
        ) {
            throw new UnexpectedValueException('GitHub comment state does not match the requested exact target.');
        }
    }

    /** @param array{operation: string, repo: string, number: int} $options
     *  @param array{title?: string, body?: string} $payload
     *  @param (callable(): array{sha: string, branch: string})|null $localTargetResolver
     *  @param (callable(string): array{environment: array<string, string>, config_dir: string, gh_binary: string})|null $ghRuntimeFactory
     */
    private static function execute(
        array $options,
        array $payload,
        string $ghBinary,
        ?callable $localTargetResolver = null,
        ?callable $ghRuntimeFactory = null,
    ): void {
        $ghRuntimeFactory ??= static fn(string $source): array => self::createGhRuntime(
            $source,
            self::expectedGhDigest($source),
        );
        $runtime = $ghRuntimeFactory($ghBinary);
        $environment = $runtime['environment'];
        try {
            self::executeWithEnvironment($options, $payload, $runtime['gh_binary'], $environment, $localTargetResolver);
        } finally {
            self::removeGhRuntime($runtime['config_dir'], $runtime['gh_binary']);
        }
    }

    /** @param array{operation: string, repo: string, number: int} $options
     *  @param array{title?: string, body?: string} $payload
     *  @param array<string, string> $environment
     *  @param (callable(): array{sha: string, branch: string})|null $localTargetResolver
     */
    private static function executeWithEnvironment(
        array $options,
        array $payload,
        string $ghBinary,
        array $environment,
        ?callable $localTargetResolver,
    ): void {
        $auth = self::runCommand([$ghBinary, 'auth', 'status', '--hostname', 'github.com'], '', $environment);
        if ($auth['exit_code'] !== 0) {
            throw new UnexpectedValueException('Native GitHub authentication is unavailable.');
        }

        $localTargetResolver ??= static fn(): array => self::resolveLocalTarget();
        $localTarget = $localTargetResolver();
        self::verifyTarget(
            $ghBinary,
            $options['repo'],
            $options['number'],
            $localTarget['sha'],
            $localTarget['branch'],
            [],
            $environment,
        );

        if ($options['operation'] === 'update-pr') {
            $method = 'PATCH';
            $endpoint = 'repos/' . $options['repo'] . '/pulls/' . $options['number'];
        } else {
            $method = 'POST';
            $endpoint = 'repos/' . $options['repo'] . '/issues/' . $options['number'] . '/comments';
        }

        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new InvalidArgumentException('Payload could not be encoded safely.');
        }

        try {
            $writeCommand = [
                $ghBinary,
                'api',
                '--hostname',
                'github.com',
                '--method',
                $method,
                $endpoint,
                '--input',
                '-',
            ];
            if ($options['operation'] === 'update-pr') {
                $writeCommand[] = '--silent';
            } else {
                $writeCommand[] = '--jq';
                $writeCommand[] = GITHUB_PR_WRITE_COMMENT_ID_PROJECTION;
            }
            $result = self::runCommand($writeCommand, $input, $environment);
        } catch (Throwable) {
            // Once the write child has been invoked, even a local transport
            // failure cannot prove that GitHub did not apply the mutation.
            $result = ['exit_code' => 1, 'stdout' => '', 'stderr' => ''];
        }

        $commentId = $options['operation'] === 'create-comment' ? self::extractCommentId($result['stdout']) : null;
        $status = $result['exit_code'] === 0 ? 'ok' : 'write_completed_target_unverified';
        try {
            $postWriteLocalTarget = $localTargetResolver();
            $remoteTarget = self::verifyTarget(
                $ghBinary,
                $options['repo'],
                $options['number'],
                $postWriteLocalTarget['sha'],
                $postWriteLocalTarget['branch'],
                $options['operation'] === 'update-pr' ? array_keys($payload) : [],
                $environment,
            );
            if ($postWriteLocalTarget !== $localTarget) {
                throw new UnexpectedValueException('Canonical local target changed during the GitHub write.');
            }
            if ($options['operation'] === 'update-pr') {
                self::verifyUpdatedFields($remoteTarget, $payload);
            } elseif ($commentId === null || !isset($payload['body'])) {
                throw new UnexpectedValueException('GitHub comment identifier could not be verified.');
            } else {
                self::verifyCreatedComment(
                    $ghBinary,
                    $options['repo'],
                    $options['number'],
                    $commentId,
                    $payload['body'],
                    $environment,
                );
            }
        } catch (Throwable) {
            // The unsafe REST write has already completed. A non-zero exit here
            // would invite a retry that can duplicate a comment or overwrite PR metadata.
            $status = 'write_completed_target_unverified';
        }

        $response = [
            'status' => $status,
            'operation' => $options['operation'],
            'number' => $options['number'],
        ];
        if ($commentId !== null) {
            $response['comment_id'] = $commentId;
        }

        echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    public static function main(array $arguments): int
    {
        try {
            $options = self::parseArguments($arguments);
            $requestInput = stream_get_contents(STDIN, GITHUB_PR_WRITE_MAX_INPUT_BYTES + 1);
            if (!is_string($requestInput)) {
                throw new InvalidArgumentException('JSON input could not be read.');
            }
            $payload = self::parsePayload($options['operation'], $requestInput);
            self::execute($options, $payload, self::resolveGhBinary());
            return 0;
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, 'Input rejected: ' . $exception->getMessage() . PHP_EOL);
            return 2;
        } catch (UnexpectedValueException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 3;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 4;
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(GithubPrWriteTransport::main(array_slice($argv, 1)));
}

<?php

declare(strict_types=1);

const GITHUB_PR_WRITE_REPOSITORY = 'robinbeier/forscherhaus-appointments';
const GITHUB_PR_WRITE_BASE_REF = 'main';
const GITHUB_PR_WRITE_MAX_BODY_BYTES = 65536;
const GITHUB_PR_WRITE_MAX_TITLE_BYTES = 256;
const GITHUB_PR_WRITE_MAX_INPUT_BYTES = 400000;
const GITHUB_PR_WRITE_MAX_COMMAND_OUTPUT_BYTES = 131072;
const GITHUB_PR_WRITE_GH_CANDIDATES = ['/opt/homebrew/bin/gh', '/usr/local/bin/gh', '/usr/bin/gh'];

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

    /** @return array<string, string> */
    private static function childEnvironment(): array
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
            throw new RuntimeException('OS account lookup is unavailable.');
        }
        $effectiveUid = posix_geteuid();
        $account = posix_getpwuid($effectiveUid);
        if (!is_array($account) || !is_string($account['name'] ?? null) || !is_string($account['dir'] ?? null)) {
            throw new RuntimeException('OS account lookup failed.');
        }
        $home = realpath($account['dir']);
        if ($home === false || !is_dir($home) || is_link($account['dir']) || fileowner($home) !== $effectiveUid) {
            throw new RuntimeException('OS account home is unsafe.');
        }

        return [
            'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
            'HOME' => $home,
            'USER' => $account['name'],
            'LOGNAME' => $account['name'],
            'TMPDIR' => '/tmp',
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'GH_PROMPT_DISABLED' => '1',
            'NO_COLOR' => '1',
        ];
    }

    private static function validateGhBinary(string $candidate): string
    {
        if ($candidate === '' || !str_starts_with($candidate, '/') || basename($candidate) !== 'gh') {
            throw new RuntimeException('GitHub CLI path is invalid.');
        }

        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || !is_executable($resolved) || is_link($resolved)) {
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
            ($mode & 0o022) !== 0
        ) {
            throw new RuntimeException('GitHub CLI ownership or mode is unsafe.');
        }

        return $resolved;
    }

    private static function resolveGhBinary(): string
    {
        foreach (GITHUB_PR_WRITE_GH_CANDIDATES as $candidate) {
            if (!file_exists($candidate)) {
                continue;
            }

            return self::validateGhBinary($candidate);
        }

        throw new RuntimeException('GitHub CLI is unavailable on the fixed path allowlist.');
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

        $head = self::runCommand([...$common, 'rev-parse', '--verify', 'HEAD^{commit}'], '', $environment);
        $headSha = trim($head['stdout']);
        if ($head['exit_code'] !== 0 || preg_match('/\A[a-f0-9]{40}\z/D', $headSha) !== 1) {
            throw new RuntimeException('Canonical repository HEAD could not be verified.');
        }

        $branch = self::runCommand([...$common, 'symbolic-ref', '--quiet', '--short', 'HEAD'], '', $environment);
        $branchName = rtrim($branch['stdout'], "\r\n");
        if (
            $branch['exit_code'] !== 0 ||
            $branchName === '' ||
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

    /** @param array<string, string> $environment */
    private static function verifyTarget(
        string $ghBinary,
        string $repo,
        int $number,
        string $localHead,
        string $localBranch,
        array $environment,
    ): void {
        $endpoint = 'repos/' . $repo . '/pulls/' . $number;
        $result = self::runCommand(
            [$ghBinary, 'api', '--hostname', 'github.com', '--method', 'GET', $endpoint],
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
    }

    /** @param array{operation: string, repo: string, number: int} $options
     *  @param array{title?: string, body?: string} $payload
     */
    private static function execute(array $options, array $payload, string $ghBinary): void
    {
        $environment = self::childEnvironment();
        $auth = self::runCommand([$ghBinary, 'auth', 'status', '--hostname', 'github.com'], '', $environment);
        if ($auth['exit_code'] !== 0) {
            throw new UnexpectedValueException('Native GitHub authentication is unavailable.');
        }

        $localTarget = self::resolveLocalTarget();
        self::verifyTarget(
            $ghBinary,
            $options['repo'],
            $options['number'],
            $localTarget['sha'],
            $localTarget['branch'],
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

        $result = self::runCommand(
            [$ghBinary, 'api', '--hostname', 'github.com', '--method', $method, $endpoint, '--input', '-'],
            $input,
            $environment,
        );
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('GitHub API request failed with exit ' . $result['exit_code'] . '.');
        }

        $status = 'ok';
        try {
            self::verifyTarget(
                $ghBinary,
                $options['repo'],
                $options['number'],
                $localTarget['sha'],
                $localTarget['branch'],
                $environment,
            );
        } catch (Throwable) {
            // The unsafe REST write has already completed. A non-zero exit here
            // would invite a retry that can duplicate a comment or overwrite PR metadata.
            $status = 'write_completed_target_unverified';
        }

        echo json_encode(
            [
                'status' => $status,
                'operation' => $options['operation'],
                'number' => $options['number'],
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL;
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

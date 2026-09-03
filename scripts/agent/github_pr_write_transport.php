<?php

declare(strict_types=1);

const GITHUB_PR_WRITE_MAX_BODY_BYTES = 65536;
const GITHUB_PR_WRITE_MAX_TITLE_BYTES = 256;

/**
 * @param list<string> $arguments
 * @return array{operation: string, repo: string, number: int, title_file?: string, body_file?: string}
 */
function parseGithubPrWriteArguments(array $arguments): array
{
    $operation = array_shift($arguments);
    if (!in_array($operation, ['update-pr', 'create-comment'], true)) {
        throw new InvalidArgumentException('Operation must be update-pr or create-comment.');
    }

    $values = [];
    $allowed = ['repo', 'number', 'title-file', 'body-file'];
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if (!is_string($argument) || !str_starts_with($argument, '--')) {
            throw new InvalidArgumentException('Options must use the --name value form.');
        }

        $name = substr($argument, 2);
        if (!in_array($name, $allowed, true) || array_key_exists($name, $values)) {
            throw new InvalidArgumentException('Unknown or duplicate option.');
        }

        $value = array_shift($arguments);
        if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
            throw new InvalidArgumentException('Every option requires a non-empty value.');
        }

        $values[$name] = $value;
    }

    $repo = $values['repo'] ?? '';
    if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9._-]{1,100}\z/D', $repo) !== 1) {
        throw new InvalidArgumentException('Repository must be an explicit owner/name pair.');
    }

    $number = filter_var($values['number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_int($number)) {
        throw new InvalidArgumentException('Number must be a positive integer.');
    }

    if ($operation === 'update-pr') {
        if (!isset($values['title-file']) && !isset($values['body-file'])) {
            throw new InvalidArgumentException('update-pr requires title-file or body-file.');
        }
    } elseif (!isset($values['body-file']) || isset($values['title-file'])) {
        throw new InvalidArgumentException('create-comment requires body-file and forbids title-file.');
    }

    $parsed = ['operation' => $operation, 'repo' => $repo, 'number' => $number];
    if (isset($values['title-file'])) {
        $parsed['title_file'] = $values['title-file'];
    }
    if (isset($values['body-file'])) {
        $parsed['body_file'] = $values['body-file'];
    }

    return $parsed;
}

function readGithubPrWritePayload(string $path, string $label, int $maximumBytes): string
{
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        throw new InvalidArgumentException($label . ' must reference a readable regular file.');
    }

    $size = filesize($path);
    if (!is_int($size) || $size > $maximumBytes) {
        throw new InvalidArgumentException($label . ' exceeds the bounded payload size.');
    }

    $contents = file_get_contents($path);
    if (!is_string($contents) || strlen($contents) !== $size) {
        throw new InvalidArgumentException($label . ' could not be read completely.');
    }
    if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
        throw new InvalidArgumentException($label . ' must be UTF-8 text without NUL bytes.');
    }

    return $contents;
}

/** @return array<string, string> */
function githubPrWriteChildEnvironment(): array
{
    $environment = [
        'GH_PROMPT_DISABLED' => '1',
        'NO_COLOR' => '1',
    ];
    foreach (['PATH', 'HOME', 'TMPDIR', 'LANG', 'LC_ALL'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            $environment[$name] = $value;
        }
    }

    return $environment;
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runGithubPrWriteCommand(array $command, string $stdin, array $environment): array
{
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment, [
        'bypass_shell' => true,
    ]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the GitHub CLI.');
    }

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => is_int($exitCode) ? $exitCode : 1,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/** @param array{operation: string, repo: string, number: int, title_file?: string, body_file?: string} $options */
function executeGithubPrWrite(array $options): void
{
    $payload = [];
    if (isset($options['title_file'])) {
        $title = readGithubPrWritePayload($options['title_file'], 'title-file', GITHUB_PR_WRITE_MAX_TITLE_BYTES);
        if ($title === '' || str_contains($title, "\n") || str_contains($title, "\r")) {
            throw new InvalidArgumentException('title-file must contain one non-empty line without a terminator.');
        }
        $payload['title'] = $title;
    }
    if (isset($options['body_file'])) {
        $payload['body'] = readGithubPrWritePayload($options['body_file'], 'body-file', GITHUB_PR_WRITE_MAX_BODY_BYTES);
    }

    if ($options['operation'] === 'update-pr') {
        $method = 'PATCH';
        $endpoint = 'repos/' . $options['repo'] . '/pulls/' . $options['number'];
    } else {
        $method = 'POST';
        $endpoint = 'repos/' . $options['repo'] . '/issues/' . $options['number'] . '/comments';
    }

    $environment = githubPrWriteChildEnvironment();
    $auth = runGithubPrWriteCommand(['gh', 'auth', 'status', '--hostname', 'github.com'], '', $environment);
    if ($auth['exit_code'] !== 0) {
        throw new UnexpectedValueException('Native GitHub authentication is unavailable.');
    }

    try {
        $input = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (JsonException) {
        throw new InvalidArgumentException('Payload could not be encoded safely.');
    }

    $result = runGithubPrWriteCommand(
        ['gh', 'api', '--hostname', 'github.com', '--method', $method, $endpoint, '--input', '-'],
        $input,
        $environment,
    );
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('GitHub API request failed with exit ' . $result['exit_code'] . '.');
    }

    echo json_encode(
        [
            'status' => 'ok',
            'operation' => $options['operation'],
            'number' => $options['number'],
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ) . PHP_EOL;
}

function githubPrWriteMain(array $arguments): int
{
    try {
        executeGithubPrWrite(parseGithubPrWriteArguments($arguments));
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

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(githubPrWriteMain(array_slice($argv, 1)));
}

<?php

declare(strict_types=1);

final class GithubPrWriteRequest
{
    /**
     * @param list<string> $arguments
     * @return array{operation: string, repo: string, number: int}
     */
    public static function parseArguments(array $arguments): array
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
    public static function parsePayload(string $operation, string $input): array
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
}

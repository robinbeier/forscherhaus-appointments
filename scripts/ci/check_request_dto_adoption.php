<?php

declare(strict_types=1);

const REQUEST_DTO_ADOPTION_EXIT_SUCCESS = 0;
const REQUEST_DTO_ADOPTION_EXIT_VIOLATION = 1;
const REQUEST_DTO_ADOPTION_EXIT_RUNTIME_ERROR = 2;

$root = dirname(__DIR__, 2);
$scope_file = $root . '/scripts/ci/config/request_dto_adoption_scope.php';

try {
    if (!is_file($scope_file)) {
        throw new RuntimeException('Missing adoption scope config: ' . $scope_file);
    }

    /** @var array<int, array{file:string,methods:array<int,string>}> $scope */
    $scope = require $scope_file;
    $violations = [];

    foreach ($scope as $entry) {
        $relative_path = trim((string) ($entry['file'] ?? ''));
        $methods = $entry['methods'] ?? [];

        if ($relative_path === '' || !is_array($methods) || $methods === []) {
            throw new RuntimeException('Invalid scope entry in config: ' . json_encode($entry));
        }

        $absolute_path = $root . '/' . $relative_path;

        if (!is_file($absolute_path)) {
            $violations[] = sprintf('[MISSING_FILE] %s', $relative_path);
            continue;
        }

        $source = file_get_contents($absolute_path);

        if ($source === false) {
            throw new RuntimeException('Failed to read source file: ' . $relative_path);
        }

        $method_bodies = extractMethodBodies($source);

        foreach ($methods as $method_name) {
            $method_name = (string) $method_name;

            if (!array_key_exists($method_name, $method_bodies)) {
                $violations[] = sprintf('[MISSING_METHOD] %s::%s', $relative_path, $method_name);
                continue;
            }

            $body = $method_bodies[$method_name];

            if (preg_match('/\\brequest\\s*\\(/', $body) === 1) {
                $violations[] = sprintf('[RAW_REQUEST] %s::%s uses request()', $relative_path, $method_name);
            }

            if (preg_match('/\\$_POST\\b|\\$_GET\\b/', $body) === 1) {
                $violations[] = sprintf('[RAW_SUPERGLOBAL] %s::%s uses $_POST/$_GET', $relative_path, $method_name);
            }
        }
    }

    if ($violations !== []) {
        fwrite(STDERR, "[FAIL] request-dto adoption violations detected:\n");
        foreach ($violations as $violation) {
            fwrite(STDERR, ' - ' . $violation . PHP_EOL);
        }
        exit(REQUEST_DTO_ADOPTION_EXIT_VIOLATION);
    }

    fwrite(STDOUT, "[PASS] request-dto adoption check passed.\n");
    exit(REQUEST_DTO_ADOPTION_EXIT_SUCCESS);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] request-dto adoption check failed: ' . $e->getMessage() . PHP_EOL);
    exit(REQUEST_DTO_ADOPTION_EXIT_RUNTIME_ERROR);
}

/**
 * @return array<string, string>
 */
function extractMethodBodies(string $source): array
{
    $tokens = token_get_all($source);
    $methods = [];
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];

        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $name = null;
        $cursor = $index + 1;

        while ($cursor < $count) {
            $candidate = $tokens[$cursor];

            if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $cursor++;
                continue;
            }

            // Anonymous function / closure.
            if ($candidate === '(') {
                break;
            }

            if (is_array($candidate) && $candidate[0] === T_STRING) {
                $name = (string) $candidate[1];
                $cursor++;
                break;
            }

            $cursor++;
        }

        if ($name === null) {
            continue;
        }

        while ($cursor < $count && $tokens[$cursor] !== '{') {
            $cursor++;
        }

        if ($cursor >= $count || $tokens[$cursor] !== '{') {
            continue;
        }

        $cursor++;
        $depth = 1;
        $body = '';

        while ($cursor < $count && $depth > 0) {
            $current = $tokens[$cursor];
            $text = is_array($current) ? $current[1] : $current;

            if ($text === '{') {
                $depth++;
                $body .= $text;
                $cursor++;
                continue;
            }

            if ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    $cursor++;
                    break;
                }

                $body .= $text;
                $cursor++;
                continue;
            }

            $body .= $text;
            $cursor++;
        }

        $methods[$name] = $body;
        $index = $cursor;
    }

    return $methods;
}

<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $options = getopt('', ['workflow:']);
    $workflowPath = $options['workflow'] ?? '.github/workflows/ci.yml';
    if (!is_string($workflowPath)) {
        throw new RuntimeException('Expected a workflow path.');
    }
    $document = Yaml::parseFile($workflowPath);
    $filters = null;
    foreach ($document['jobs']['changes']['steps'] ?? [] as $step) {
        if (is_array($step) && ($step['id'] ?? null) === 'filter') {
            $filters = $step['with']['filters'] ?? null;
            break;
        }
    }
    if (!is_string($filters)) {
        throw new RuntimeException('Workflow filter block is missing or invalid.');
    }
    $patterns = Yaml::parse($filters)['integration_smoke'] ?? null;
    if (!is_array($patterns) || !array_is_list($patterns) || $patterns === []) {
        throw new RuntimeException('Missing or invalid integration_smoke filter.');
    }
    // The canonical filter currently uses only exact paths and directory/**.
    // Refuse new glob syntax rather than silently disagreeing with GitHub.
    foreach ($patterns as $pattern) {
        if (!is_string($pattern) || preg_match('~\A[a-zA-Z0-9_./-]+(?:/\*\*)?\z~', $pattern) !== 1) {
            throw new RuntimeException('Unsupported integration_smoke filter pattern.');
        }
    }
    $input = stream_get_contents(STDIN);
    if ($input === false || ($input !== '' && !str_ends_with($input, "\0"))) {
        throw new RuntimeException('Expected complete NUL-delimited changed paths.');
    }
    $required = false;
    foreach (explode("\0", $input) as $path) {
        if ($path === '') {
            continue;
        }
        foreach ($patterns as $pattern) {
            if (
                $path === $pattern ||
                (str_ends_with($pattern, '/**') && str_starts_with($path, substr($pattern, 0, -2)))
            ) {
                $required = true;
            }
        }
    }
    echo $required ? "true\n" : "false\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '[pre-pr-full] Selection failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

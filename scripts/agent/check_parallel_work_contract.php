<?php

declare(strict_types=1);

use Forscherhaus\AgentHarness\ParallelWorkContract;

require_once __DIR__ . '/lib/ParallelWorkContract.php';

$root = dirname(__DIR__, 2);
$manifestPath = null;
$contractPath = $root . '/.codex/contracts/agent-workflow.json';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifestPath = substr($argument, strlen('--manifest='));
        continue;
    }
    if (str_starts_with($argument, '--contract=')) {
        $contractPath = substr($argument, strlen('--contract='));
        continue;
    }
    fwrite(STDERR, "Unknown option.\n");
    exit(2);
}

if ($manifestPath === null || $manifestPath === '') {
    fwrite(STDERR, "Missing --manifest.\n");
    exit(2);
}

try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Parallel-work input is not valid JSON.\n");
    exit(2);
}

if (!is_array($manifest) || !is_array($contract) || !is_array($contract['parallel_work'] ?? null)) {
    fwrite(STDERR, "Parallel-work input has an invalid shape.\n");
    exit(2);
}

$ownershipMapPath = $contract['parallel_work']['ownership_map'] ?? null;
if (!is_string($ownershipMapPath) || str_starts_with($ownershipMapPath, '/') || str_contains($ownershipMapPath, '..')) {
    fwrite(STDERR, "Parallel-work ownership-map policy is invalid.\n");
    exit(2);
}
try {
    $ownershipMap = json_decode(
        (string) file_get_contents($root . '/' . $ownershipMapPath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable) {
    fwrite(STDERR, "Parallel-work ownership map is not valid JSON.\n");
    exit(2);
}
if (!is_array($ownershipMap)) {
    fwrite(STDERR, "Parallel-work ownership map has an invalid shape.\n");
    exit(2);
}

$errors = ParallelWorkContract::validate($manifest, $contract['parallel_work'], $ownershipMap);
$result = [
    'schema_version' => 1,
    'status' => $errors === [] ? 'pass' : 'fail',
    'errors' => $errors,
];

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($errors === [] ? 0 : 1);

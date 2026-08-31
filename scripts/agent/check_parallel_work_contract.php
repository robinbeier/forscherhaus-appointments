<?php

declare(strict_types=1);

use Forscherhaus\AgentHarness\ParallelWorkContract;
use Forscherhaus\AgentHarness\RepoPath;

require_once __DIR__ . '/lib/RepoPath.php';
require_once __DIR__ . '/lib/ParallelWorkContract.php';

$root = dirname(__DIR__, 2);
$manifestPath = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifestPath = substr($argument, strlen('--manifest='));
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
} catch (Throwable) {
    fwrite(STDERR, "Parallel-work manifest is not valid JSON.\n");
    exit(2);
}

if (!is_array($manifest)) {
    fwrite(STDERR, "Parallel-work input has an invalid shape.\n");
    exit(2);
}

$baseSha = $manifest['base_sha'] ?? null;
if (!is_string($baseSha) || preg_match('/^[a-f0-9]{40}$/D', $baseSha) !== 1) {
    fwrite(STDERR, "Parallel-work input has an invalid shape.\n");
    exit(2);
}

$contractJson = readGitBlob($root, $baseSha, '.codex/contracts/agent-workflow.json');
if ($contractJson === null) {
    fwrite(STDERR, "Parallel-work base contract is unavailable.\n");
    exit(2);
}
try {
    $contract = json_decode($contractJson, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Parallel-work base contract is not valid JSON.\n");
    exit(2);
}
if (!is_array($contract) || !is_array($contract['parallel_work'] ?? null)) {
    fwrite(STDERR, "Parallel-work base contract has an invalid shape.\n");
    exit(2);
}

$ownershipMapPath = $contract['parallel_work']['ownership_map'] ?? null;
if (!is_string($ownershipMapPath) || !RepoPath::isNormalized($ownershipMapPath)) {
    fwrite(STDERR, "Parallel-work ownership-map policy is invalid.\n");
    exit(2);
}
$ownershipMapJson = readGitBlob($root, $baseSha, $ownershipMapPath);
if ($ownershipMapJson === null) {
    fwrite(STDERR, "Parallel-work base ownership map is unavailable.\n");
    exit(2);
}
try {
    $ownershipMap = json_decode($ownershipMapJson, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Parallel-work base ownership map is not valid JSON.\n");
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

function readGitBlob(string $root, string $sha, string $path): ?string
{
    $gitBinary = '/usr/bin/git';
    if (!is_executable($gitBinary)) {
        return null;
    }

    $process = proc_open(
        [
            $gitBinary,
            '-c',
            'core.fsmonitor=false',
            '-c',
            'core.hooksPath=/dev/null',
            '-c',
            'core.untrackedCache=false',
            '-c',
            'diff.external=',
            '-C',
            $root,
            'show',
            $sha . ':' . $path,
        ],
        [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        $root,
        [
            'GIT_ATTR_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_NO_LAZY_FETCH' => '1',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_PAGER' => 'cat',
            'GIT_TERMINAL_PROMPT' => '0',
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
        ],
    );
    if (!is_resource($process)) {
        return null;
    }

    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 && is_string($stdout) ? $stdout : null;
}

<?php

declare(strict_types=1);

use Forscherhaus\AgentHarness\ParallelWorkContract;
use Forscherhaus\AgentHarness\RepoPath;

require_once __DIR__ . '/lib/RepoPath.php';
require_once __DIR__ . '/lib/ParallelWorkContract.php';

$validatorRoot = dirname(__DIR__, 2);
$validatorCheckoutRootInput = getenv('PARALLEL_WORK_VALIDATOR_CHECKOUT_ROOT');
if (!is_string($validatorCheckoutRootInput) || !str_starts_with($validatorCheckoutRootInput, '/')) {
    fwrite(STDERR, "Parallel-work validator checkout is unavailable.\n");
    exit(2);
}
$validatorCheckoutRoot = realpath($validatorCheckoutRootInput);
if ($validatorCheckoutRoot === false || $validatorCheckoutRoot !== $validatorCheckoutRootInput) {
    fwrite(STDERR, "Parallel-work validator checkout is invalid.\n");
    exit(2);
}
$root = $validatorCheckoutRoot;
$manifestPath = null;
$requestedRepoRoot = null;
$verifyLane = null;
$requireClean = false;
$allowDirtyPrecommit = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifestPath = substr($argument, strlen('--manifest='));
        continue;
    }
    if (str_starts_with($argument, '--repo-root=')) {
        $requestedRepoRoot = substr($argument, strlen('--repo-root='));
        continue;
    }
    if (str_starts_with($argument, '--verify-lane=')) {
        $verifyLane = substr($argument, strlen('--verify-lane='));
        continue;
    }
    if ($argument === '--require-clean') {
        $requireClean = true;
        continue;
    }
    if ($argument === '--allow-dirty-precommit') {
        $allowDirtyPrecommit = true;
        continue;
    }
    fwrite(STDERR, "Unknown option.\n");
    exit(2);
}

if ($manifestPath === null || $manifestPath === '') {
    fwrite(STDERR, "Missing --manifest.\n");
    exit(2);
}
if ($verifyLane !== null && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $verifyLane) !== 1) {
    fwrite(STDERR, "Parallel-work lane verification ID is invalid.\n");
    exit(2);
}
if ($verifyLane !== null && ($requestedRepoRoot === null || $requestedRepoRoot === '')) {
    fwrite(STDERR, "Parallel-work lane verification requires --repo-root.\n");
    exit(2);
}
if ($requireClean && $verifyLane === null) {
    fwrite(STDERR, "Parallel-work clean verification requires --verify-lane.\n");
    exit(2);
}
if ($allowDirtyPrecommit && $verifyLane === null) {
    fwrite(STDERR, "Parallel-work pre-commit verification requires --verify-lane.\n");
    exit(2);
}
if ($requireClean && $allowDirtyPrecommit) {
    fwrite(STDERR, "Parallel-work verification modes are mutually exclusive.\n");
    exit(2);
}
if ($verifyLane !== null && !$requireClean && !$allowDirtyPrecommit) {
    fwrite(STDERR, "Parallel-work lane verification requires an explicit evidence mode.\n");
    exit(2);
}

$gitBinary = trustedGitBinary();
if ($gitBinary === null) {
    fwrite(STDERR, "Git is unavailable on the fixed parallel-work validator path.\n");
    exit(2);
}
if ($requestedRepoRoot !== null) {
    if ($requestedRepoRoot === '' || !str_starts_with($requestedRepoRoot, '/')) {
        fwrite(STDERR, "Parallel-work repository root must be absolute.\n");
        exit(2);
    }
    [$rootExitCode, $resolvedRoot] = runTrustedGit($gitBinary, $requestedRepoRoot, ['rev-parse', '--show-toplevel']);
    $requestedRealRoot = realpath($requestedRepoRoot);
    $resolvedRealRoot = $rootExitCode === 0 ? realpath(trim($resolvedRoot)) : false;
    if ($requestedRealRoot === false || $resolvedRealRoot === false || $requestedRealRoot !== $resolvedRealRoot) {
        fwrite(STDERR, "Parallel-work repository root is invalid.\n");
        exit(2);
    }
    $root = $resolvedRealRoot;
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

$validatorErrors = [
    ...verifyValidatorCheckout($gitBinary, $validatorCheckoutRoot, $baseSha),
    ...verifyValidatorSource($gitBinary, $validatorRoot, $validatorCheckoutRoot, $baseSha),
];
if ($validatorErrors !== []) {
    fwrite(
        STDOUT,
        json_encode(
            ['schema_version' => 1, 'status' => 'fail', 'errors' => $validatorErrors],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL,
    );
    exit(1);
}

$contractJson = readGitBlob($gitBinary, $root, $baseSha, '.codex/contracts/agent-workflow.json');
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
$ownershipMapJson = readGitBlob($gitBinary, $root, $baseSha, $ownershipMapPath);
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
$verification = null;
if ($verifyLane !== null && $errors === []) {
    $errors = verifyValidatorSeparation($validatorCheckoutRoot, $root);
    if ($errors === []) {
        [$changedPaths, $localPaths, $verificationErrors, $headSha] = collectLaneChanges($gitBinary, $root, $baseSha);
        $errors = [
            ...$verificationErrors,
            ...$requireClean && $localPaths !== [] ? ['lane_worktree_not_clean'] : [],
            ...ParallelWorkContract::validateLaneChanges($manifest, $verifyLane, $changedPaths),
        ];
        $verification = [
            'lane_id' => $verifyLane,
            'base_sha' => $baseSha,
            'head_sha' => $headSha,
            'working_tree_clean' => $localPaths === [],
            'evidence_level' => $requireClean ? 'integration' : 'pre_commit',
            'integration_ready' => $requireClean && $localPaths === [],
            'changed_paths_sha256' => hash('sha256', implode("\0", $changedPaths)),
        ];
    }
}
$status = $errors === [] ? ($allowDirtyPrecommit ? 'provisional_pass' : 'pass') : 'fail';
$result = [
    'schema_version' => 1,
    'status' => $status,
    'errors' => $errors,
];
if ($verification !== null) {
    $result['verification'] = $verification;
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($errors === [] ? 0 : 1);

function trustedGitBinary(): ?string
{
    foreach (['/usr/bin/git', '/opt/homebrew/bin/git', '/usr/local/bin/git', '/opt/local/bin/git'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @param list<string> $arguments
 * @return array{int, string}
 */
function runTrustedGit(string $gitBinary, string $root, array $arguments): array
{
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
            '-c',
            'core.excludesfile=/dev/null',
            '-C',
            $root,
            ...$arguments,
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
        return [2, ''];
    }

    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), is_string($stdout) ? $stdout : ''];
}

function readGitBlob(string $gitBinary, string $root, string $sha, string $path): ?string
{
    [$exitCode, $stdout] = runTrustedGit($gitBinary, $root, ['show', $sha . ':' . $path]);

    return $exitCode === 0 ? $stdout : null;
}

/** @return list<string> */
function verifyValidatorCheckout(string $gitBinary, string $validatorCheckoutRoot, string $baseSha): array
{
    $validatorRealRoot = realpath($validatorCheckoutRoot);
    if ($validatorRealRoot === false) {
        return ['validator_checkout_invalid'];
    }

    [$rootExitCode, $rootOutput] = runTrustedGit($gitBinary, $validatorRealRoot, ['rev-parse', '--show-toplevel']);
    $resolvedRoot = $rootExitCode === 0 ? realpath(trim($rootOutput)) : false;
    if ($resolvedRoot === false || $resolvedRoot !== $validatorRealRoot) {
        return ['validator_checkout_invalid'];
    }

    [$headExitCode, $headOutput] = runTrustedGit($gitBinary, $validatorRealRoot, ['rev-parse', '--verify', 'HEAD']);
    if ($headExitCode !== 0 || !hash_equals($baseSha, trim($headOutput))) {
        return ['validator_base_mismatch'];
    }

    [$statusExitCode, $statusOutput] = runTrustedGit($gitBinary, $validatorRealRoot, [
        'status',
        '--porcelain',
        '--untracked-files=all',
    ]);
    if ($statusExitCode !== 0 || $statusOutput !== '') {
        return ['validator_worktree_not_clean'];
    }

    return [];
}

/** @return list<string> */
function verifyValidatorSource(string $gitBinary, string $validatorRoot, string $root, string $baseSha): array
{
    $validatorRealRoot = realpath($validatorRoot);
    $rootRealPath = realpath($root);
    if ($validatorRealRoot === false || $rootRealPath === false || $validatorRealRoot === $rootRealPath) {
        return ['untrusted_validator_source'];
    }

    foreach (
        [
            'scripts/agent/check_parallel_work_contract.sh',
            'scripts/agent/check_parallel_work_contract.php',
            'scripts/agent/lib/ParallelWorkContract.php',
            'scripts/agent/lib/RepoPath.php',
        ]
        as $path
    ) {
        $source = file_get_contents($validatorRoot . '/' . $path);
        $trusted = readGitBlob($gitBinary, $root, $baseSha, $path);
        if (!is_string($source) || $trusted === null || !hash_equals($trusted, $source)) {
            return ['untrusted_validator_source:' . $path];
        }
    }

    return [];
}

/** @return list<string> */
function verifyValidatorSeparation(string $validatorCheckoutRoot, string $laneRoot): array
{
    $validatorRealRoot = realpath($validatorCheckoutRoot);
    $laneRealRoot = realpath($laneRoot);
    if ($validatorRealRoot === false || $laneRealRoot === false || $validatorRealRoot === $laneRealRoot) {
        return ['validator_must_run_outside_lane'];
    }

    return [];
}

/** @return array{list<string>, list<string>, list<string>, string} */
function collectLaneChanges(string $gitBinary, string $root, string $baseSha): array
{
    [$headExitCode, $headOutput] = runTrustedGit($gitBinary, $root, ['rev-parse', '--verify', 'HEAD']);
    $headSha = trim($headOutput);
    if ($headExitCode !== 0 || preg_match('/^[a-f0-9]{40}$/D', $headSha) !== 1) {
        return [[], [], ['lane_head_unavailable'], ''];
    }
    [$ancestorExitCode] = runTrustedGit($gitBinary, $root, ['merge-base', '--is-ancestor', $baseSha, $headSha]);
    if ($ancestorExitCode !== 0) {
        return [[], [], ['lane_base_not_ancestor'], $headSha];
    }

    $committedCommands = [
        ['diff', '--name-only', '-z', '--no-renames', '--no-ext-diff', '--no-textconv', $baseSha, $headSha, '--'],
    ];
    $localCommands = [
        ['diff', '--name-only', '-z', '--no-renames', '--no-ext-diff', '--no-textconv', '--'],
        ['diff', '--cached', '--name-only', '-z', '--no-renames', '--no-ext-diff', '--no-textconv', '--'],
        ['ls-files', '--others', '--exclude-standard', '-z', '--'],
    ];
    $committedPaths = [];
    $localPaths = [];
    foreach ($committedCommands as $command) {
        [$exitCode, $stdout] = runTrustedGit($gitBinary, $root, $command);
        if ($exitCode !== 0) {
            return [[], [], ['lane_changes_unavailable'], $headSha];
        }
        foreach (explode("\0", $stdout) as $path) {
            if ($path !== '') {
                $committedPaths[$path] = true;
            }
        }
    }
    foreach ($localCommands as $command) {
        [$exitCode, $stdout] = runTrustedGit($gitBinary, $root, $command);
        if ($exitCode !== 0) {
            return [[], [], ['lane_changes_unavailable'], $headSha];
        }
        foreach (explode("\0", $stdout) as $path) {
            if ($path !== '') {
                $localPaths[$path] = true;
            }
        }
    }

    $changedPaths = array_keys($committedPaths + $localPaths);
    sort($changedPaths, SORT_STRING);
    $changedLocalPaths = array_keys($localPaths);
    sort($changedLocalPaths, SORT_STRING);

    return [$changedPaths, $changedLocalPaths, [], $headSha];
}

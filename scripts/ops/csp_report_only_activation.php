<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
defined('BASEPATH') || define('BASEPATH', $repoRoot . '/system/');
defined('APPPATH') || define('APPPATH', $repoRoot . '/application/');
require_once APPPATH . 'core/Csp_report_only.php';
require_once __DIR__ . '/lib/CspReportOnlyProductionContext.php';

const CSP_ACTIVATION_SCHEMA = 'csp_report_only_activation.v2';
const CSP_ACTIVATION_CANDIDATE = __DIR__ . '/config/csp_report_only.production.v1.json';
const CSP_ACTIVATION_TARGET = Csp_report_only::CONFIG_PATH;
const CSP_ACTIVATION_LOCK = '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock';
const CSP_ACTIVATION_STATE = '/var/lib/fh-deploy-orchestrator/csp-report-only-pilot.state.json';

/** @return array{schema:string,action:string,status:string,result_class:string,candidate_sha256:?string,release_binding:?string,run_id:?string} */
function activationReceipt(
    string $action,
    string $status,
    string $resultClass,
    ?string $hash,
    ?string $releaseBinding = null,
    ?string $runId = null,
): array {
    return [
        'schema' => CSP_ACTIVATION_SCHEMA,
        'action' => $action,
        'status' => $status,
        'result_class' => $resultClass,
        'candidate_sha256' => $hash,
        'release_binding' => $releaseBinding,
        'run_id' => $runId,
    ];
}

/** @param array<string,mixed> $receipt */
function emitActivationReceipt(array $receipt, int $exitCode): never
{
    echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($exitCode);
}

/** @return array{bytes:string,sha256:string}|null */
function readActivationCandidate(string $path): ?array
{
    $config = Csp_report_only::load($path);
    if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
        return null;
    }
    $bytes = @file_get_contents($path, false, null, 0, 8193);
    $hash = @hash_file('sha256', $path);
    if (
        !is_string($bytes) ||
        strlen($bytes) > 8192 ||
        !is_string($hash) ||
        preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1
    ) {
        return null;
    }
    return ['bytes' => $bytes, 'sha256' => $hash];
}

function validateRootDirectory(string $directory, int $mode): bool
{
    $identity = @lstat($directory);
    return is_array($identity) &&
        (($identity['mode'] ?? 0) & 0170000) === 0040000 &&
        (int) ($identity['uid'] ?? -1) === 0 &&
        (int) ($identity['gid'] ?? -1) === 0 &&
        (((int) ($identity['mode'] ?? 0)) & 0777) === $mode &&
        !is_link($directory);
}

/** @return resource|null */
function openProductionLock(string $path)
{
    $directory = dirname($path);
    if (!CspReportOnlyProductionContext::canonicalRootDirectory($directory, 0700)) {
        return null;
    }
    $before = @lstat($path);
    if (
        !is_array($before) ||
        (($before['mode'] ?? 0) & 0170000) !== 0100000 ||
        (int) ($before['uid'] ?? -1) !== 0 ||
        (int) ($before['gid'] ?? -1) !== 0 ||
        (((int) ($before['mode'] ?? 0)) & 0777) !== 0600 ||
        (int) ($before['nlink'] ?? 0) !== 1
    ) {
        return null;
    }
    $handle = @fopen($path, 'r+');
    if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return null;
    }
    $identity = @fstat($handle);
    $current = @lstat($path);
    if (
        !is_array($identity) ||
        !is_array($current) ||
        (($identity['mode'] ?? 0) & 0170000) !== 0100000 ||
        (int) ($identity['uid'] ?? -1) !== 0 ||
        (int) ($identity['gid'] ?? -1) !== 0 ||
        (((int) ($identity['mode'] ?? 0)) & 0777) !== 0600 ||
        (int) ($identity['nlink'] ?? 0) !== 1 ||
        (int) ($identity['ino'] ?? -1) !== (int) ($before['ino'] ?? -2) ||
        (int) ($identity['dev'] ?? -1) !== (int) ($before['dev'] ?? -2) ||
        (int) ($identity['ino'] ?? -1) !== (int) ($current['ino'] ?? -2) ||
        (int) ($identity['dev'] ?? -1) !== (int) ($current['dev'] ?? -2)
    ) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return null;
    }
    return $handle;
}

function validRunId(?string $runId): bool
{
    return is_string($runId) && preg_match('/\A[a-f0-9]{32}\z/', $runId) === 1;
}

function validReleaseBinding(?string $binding): bool
{
    return is_string($binding) && preg_match('/\A[a-f0-9]{64}\z/', $binding) === 1;
}

/** @return array<string,mixed>|null */
function readRunState(string $path): ?array
{
    $opened = CspReportOnlyProductionContext::openRootFile($path, 0600, 1, 4096);
    if ($opened === null) {
        return null;
    }
    [$handle] = $opened;
    try {
        $bytes = stream_get_contents($handle, 4097);
    } finally {
        fclose($handle);
    }
    if (!is_string($bytes) || strlen($bytes) > 4096) {
        return null;
    }
    try {
        $state = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    $keys = is_array($state) ? array_keys($state) : [];
    sort($keys);
    if (
        !is_array($state) ||
        $keys !== ['candidate_sha256', 'release_binding', 'run_id', 'schema'] ||
        ($state['schema'] ?? null) !== CSP_ACTIVATION_SCHEMA ||
        !validRunId($state['run_id'] ?? null) ||
        !validReleaseBinding($state['release_binding'] ?? null) ||
        !is_string($state['candidate_sha256'] ?? null) ||
        preg_match('/\A[a-f0-9]{64}\z/', $state['candidate_sha256']) !== 1
    ) {
        return null;
    }
    return $state;
}

/** @return array{status:string,result_class:string} */
function writeRunState(string $path, string $runId, string $candidateHash, string $releaseBinding): array
{
    $directory = dirname($path);
    if (!CspReportOnlyProductionContext::canonicalRootDirectory($directory, 0700)) {
        return ['status' => 'failed', 'result_class' => 'run_state_directory_invalid'];
    }
    if (@lstat($path) !== false) {
        return ['status' => 'failed', 'result_class' => 'run_state_already_present'];
    }
    $handle = @fopen($path, 'x+b');
    if (!is_resource($handle)) {
        return ['status' => 'failed', 'result_class' => 'run_state_create_failed'];
    }
    $state = json_encode(
        [
            'schema' => CSP_ACTIVATION_SCHEMA,
            'run_id' => $runId,
            'candidate_sha256' => $candidateHash,
            'release_binding' => $releaseBinding,
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    $ok = @chmod($path, 0600) && @chown($path, 0) && @chgrp($path, 0);
    $ok = $ok && @fwrite($handle, $state) === strlen($state) && @fflush($handle);
    if ($ok && function_exists('fsync')) {
        $ok = @fsync($handle);
    }
    $created = @fstat($handle);
    $current = @lstat($path);
    $ok =
        $ok &&
        is_array($created) &&
        is_array($current) &&
        (($current['mode'] ?? 0) & 0170000) === 0100000 &&
        (int) ($current['uid'] ?? -1) === 0 &&
        (int) ($current['gid'] ?? -1) === 0 &&
        (((int) ($current['mode'] ?? 0)) & 0777) === 0600 &&
        (int) ($current['nlink'] ?? 0) === 1 &&
        (int) ($created['ino'] ?? -1) === (int) ($current['ino'] ?? -2) &&
        (int) ($created['dev'] ?? -1) === (int) ($current['dev'] ?? -2);
    fclose($handle);
    if ($ok) {
        $directoryHandle = @fopen($directory, 'rb');
        $synced = is_resource($directoryHandle) && (!function_exists('fsync') || @fsync($directoryHandle));
        if (is_resource($directoryHandle)) {
            fclose($directoryHandle);
        }
        $ok = $synced;
    }
    if (!$ok) {
        @unlink($path);
        return ['status' => 'failed', 'result_class' => 'run_state_write_failed'];
    }
    return ['status' => 'passed', 'result_class' => 'run_state_recorded'];
}

/** @return array{status:string,result_class:string} */
function removeRunState(string $path, array $state): array
{
    $identity = @lstat($path);
    if (
        !is_array($identity) ||
        (int) ($identity['uid'] ?? -1) !== 0 ||
        (int) ($identity['gid'] ?? -1) !== 0 ||
        (((int) ($identity['mode'] ?? 0)) & 0777) !== 0600 ||
        (int) ($identity['nlink'] ?? 0) !== 1
    ) {
        return ['status' => 'failed', 'result_class' => 'run_state_identity_mismatch'];
    }
    $directoryHandle = @fopen(dirname($path), 'rb');
    if (!is_resource($directoryHandle) || !@unlink($path) || @lstat($path) !== false) {
        if (is_resource($directoryHandle)) {
            fclose($directoryHandle);
        }
        return ['status' => 'failed', 'result_class' => 'run_state_remove_failed'];
    }
    $synced = !function_exists('fsync') || @fsync($directoryHandle);
    fclose($directoryHandle);
    return $synced
        ? ['status' => 'passed', 'result_class' => 'run_state_removed']
        : ['status' => 'failed', 'result_class' => 'run_state_remove_sync_failed'];
}

/** @return array{status:string,result_class:string} */
function installActivation(string $target, string $bytes, string $hash): array
{
    $directory = dirname($target);
    if (!validateRootDirectory($directory, 0755)) {
        return ['status' => 'failed', 'result_class' => 'activation_directory_invalid'];
    }
    if (@lstat($target) !== false) {
        return ['status' => 'failed', 'result_class' => 'activation_already_present'];
    }

    $directoryHandle = @fopen($directory, 'rb');
    $handle = @fopen($target, 'x+b');
    if (!is_resource($directoryHandle) || !is_resource($handle)) {
        if (is_resource($handle)) {
            fclose($handle);
            @unlink($target);
        }
        if (is_resource($directoryHandle)) {
            fclose($directoryHandle);
        }
        return ['status' => 'failed', 'result_class' => 'activation_create_failed'];
    }

    $created = @fstat($handle);
    $current = @lstat($target);
    $identityOk =
        is_array($created) &&
        is_array($current) &&
        (($created['mode'] ?? 0) & 0170000) === 0100000 &&
        (int) ($created['nlink'] ?? 0) === 1 &&
        (int) ($created['ino'] ?? -1) === (int) ($current['ino'] ?? -2) &&
        (int) ($created['dev'] ?? -1) === (int) ($current['dev'] ?? -2);
    $written =
        $identityOk &&
        @chmod($target, 0644) &&
        @chown($target, 0) &&
        @chgrp($target, 0) &&
        @fwrite($handle, $bytes) === strlen($bytes) &&
        @fflush($handle) &&
        (!function_exists('fsync') || @fsync($handle));
    fclose($handle);

    $installed = @lstat($target);
    $verified =
        $written &&
        is_array($installed) &&
        (($installed['mode'] ?? 0) & 0170000) === 0100000 &&
        (int) ($installed['uid'] ?? -1) === 0 &&
        (int) ($installed['gid'] ?? -1) === 0 &&
        (((int) ($installed['mode'] ?? 0)) & 0777) === 0644 &&
        (int) ($installed['nlink'] ?? 0) === 1 &&
        @hash_file('sha256', $target) === $hash &&
        is_array(Csp_report_only::load($target));
    $synced = $verified && (!function_exists('fsync') || @fsync($directoryHandle));
    fclose($directoryHandle);
    if ($synced) {
        return ['status' => 'passed', 'result_class' => 'activation_installed'];
    }

    $after = @lstat($target);
    if (
        is_array($after) &&
        is_array($created) &&
        (int) ($after['ino'] ?? -1) === (int) ($created['ino'] ?? -2) &&
        (int) ($after['dev'] ?? -1) === (int) ($created['dev'] ?? -2) &&
        (int) ($after['nlink'] ?? 0) === 1
    ) {
        @unlink($target);
    }
    return ['status' => 'failed', 'result_class' => 'activation_install_failed'];
}

function activationDirectoryEntryPresent(string $directory, string $entry): ?bool
{
    $entries = @scandir($directory, SCANDIR_SORT_NONE);

    return is_array($entries) ? in_array($entry, $entries, true) : null;
}

/** @param null|callable(string,string):?bool $snapshot */
function activationTargetDurablyAbsent(string $target, ?callable $snapshot = null): bool
{
    $directory = dirname($target);
    $entry = basename($target);
    $snapshot ??= activationDirectoryEntryPresent(...);
    if (!validateRootDirectory($directory, 0755) || in_array($entry, ['', '.', '..'], true)) {
        return false;
    }
    $presentBeforeSync = $snapshot($directory, $entry);
    if ($presentBeforeSync !== false) {
        return false;
    }
    $directoryHandle = @fopen($directory, 'rb');
    if (!is_resource($directoryHandle)) {
        return false;
    }
    $synced = !function_exists('fsync') || @fsync($directoryHandle);
    $presentAfterSync = $synced ? $snapshot($directory, $entry) : null;
    fclose($directoryHandle);

    return $synced && $presentAfterSync === false;
}

/** @param array{status:string,result_class:string} $installResult @return array{status:string,result_class:string} */
function cleanupFailedInstallState(string $target, string $statePath, array $installResult): array
{
    if (!activationTargetDurablyAbsent($target)) {
        return ['status' => 'failed', 'result_class' => 'activation_install_cleanup_unverified'];
    }
    $state = readRunState($statePath);
    if (!is_array($state)) {
        return ['status' => 'failed', 'result_class' => 'run_state_identity_mismatch'];
    }
    $stateResult = removeRunState($statePath, $state);

    return $stateResult['status'] === 'passed' ? $installResult : $stateResult;
}

/** @return array{status:string,result_class:string} */
function removeActivation(string $target, string $hash): array
{
    $directory = dirname($target);
    if (!validateRootDirectory($directory, 0755)) {
        return ['status' => 'failed', 'result_class' => 'activation_directory_invalid'];
    }
    $identity = @lstat($target);
    if (!is_array($identity)) {
        return ['status' => 'passed', 'result_class' => 'activation_already_absent'];
    }
    if (
        (($identity['mode'] ?? 0) & 0170000) !== 0100000 ||
        (int) ($identity['uid'] ?? -1) !== 0 ||
        (int) ($identity['gid'] ?? -1) !== 0 ||
        (((int) ($identity['mode'] ?? 0)) & 0777) !== 0644 ||
        (int) ($identity['nlink'] ?? 0) !== 1 ||
        @hash_file('sha256', $target) !== $hash
    ) {
        return ['status' => 'failed', 'result_class' => 'activation_identity_mismatch'];
    }
    $directoryHandle = @fopen($directory, 'rb');
    if (!is_resource($directoryHandle) || !@unlink($target) || @lstat($target) !== false) {
        if (is_resource($directoryHandle)) {
            fclose($directoryHandle);
        }
        return ['status' => 'failed', 'result_class' => 'activation_remove_failed'];
    }
    $synced = !function_exists('fsync') || @fsync($directoryHandle);
    fclose($directoryHandle);
    return $synced
        ? ['status' => 'passed', 'result_class' => 'activation_removed']
        : ['status' => 'failed', 'result_class' => 'activation_remove_sync_failed'];
}

/** @return array{status:string,result_class:string} */
function preflightActivation(string $target, string $statePath): array
{
    if (!validateRootDirectory(dirname($target), 0755)) {
        return ['status' => 'failed', 'result_class' => 'activation_directory_invalid'];
    }
    if (!CspReportOnlyProductionContext::canonicalRootDirectory(dirname($statePath), 0700)) {
        return ['status' => 'failed', 'result_class' => 'run_state_directory_invalid'];
    }
    if (@lstat($target) !== false) {
        return ['status' => 'failed', 'result_class' => 'activation_already_present'];
    }
    if (@lstat($statePath) !== false) {
        return ['status' => 'failed', 'result_class' => 'run_state_already_present'];
    }
    if (!function_exists('curl_init') || !CspReportOnlyProductionContext::healthTokenReady()) {
        return ['status' => 'failed', 'result_class' => 'runtime_precondition_unavailable'];
    }
    return ['status' => 'passed', 'result_class' => 'preflight_ready'];
}

function runActivation(array $argv): never
{
    $action = '';
    $actionSeen = false;
    $runId = null;
    $expectedBinding = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--action=')) {
            if ($actionSeen) {
                emitActivationReceipt(activationReceipt('unknown', 'failed', 'input_invalid', null), 2);
            }
            $actionSeen = true;
            $action = substr($argument, strlen('--action='));
            continue;
        }
        if (str_starts_with($argument, '--run-id=')) {
            if ($runId !== null) {
                emitActivationReceipt(activationReceipt($action ?: 'unknown', 'failed', 'input_invalid', null), 2);
            }
            $runId = substr($argument, strlen('--run-id='));
            continue;
        }
        if (str_starts_with($argument, '--expected-release-binding=')) {
            if ($expectedBinding !== null) {
                emitActivationReceipt(activationReceipt($action ?: 'unknown', 'failed', 'input_invalid', null), 2);
            }
            $expectedBinding = substr($argument, strlen('--expected-release-binding='));
            continue;
        }
        emitActivationReceipt(activationReceipt('unknown', 'failed', 'input_invalid', null), 2);
    }
    if (
        !in_array($action, ['install', 'remove', 'preflight'], true) ||
        !function_exists('posix_geteuid') ||
        posix_geteuid() !== 0
    ) {
        emitActivationReceipt(
            activationReceipt($action ?: 'unknown', 'failed', 'input_invalid', null, null, $runId),
            2,
        );
    }

    if ($action === 'remove') {
        if (!validRunId($runId) || !validReleaseBinding($expectedBinding)) {
            emitActivationReceipt(
                activationReceipt($action, 'failed', 'input_invalid', null, $expectedBinding, $runId),
                2,
            );
        }
        $lock = openProductionLock(CSP_ACTIVATION_LOCK);
        if (!is_resource($lock)) {
            emitActivationReceipt(
                activationReceipt($action, 'failed', 'production_lock_unavailable', null, $expectedBinding, $runId),
                1,
            );
        }
        $state = null;
        try {
            $state = readRunState(CSP_ACTIVATION_STATE);
            if ($state === null) {
                $result = ['status' => 'failed', 'result_class' => 'run_state_missing'];
            } elseif (
                !hash_equals($state['run_id'], $runId) ||
                !hash_equals($state['release_binding'], $expectedBinding)
            ) {
                $result = ['status' => 'failed', 'result_class' => 'run_state_mismatch'];
            } else {
                $result = removeActivation(CSP_ACTIVATION_TARGET, $state['candidate_sha256']);
                if ($result['status'] === 'passed') {
                    $stateResult = removeRunState(CSP_ACTIVATION_STATE, $state);
                    if ($stateResult['status'] !== 'passed') {
                        $result = $stateResult;
                    }
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        emitActivationReceipt(
            activationReceipt(
                $action,
                $result['status'],
                $result['result_class'],
                is_array($state) ? $state['candidate_sha256'] : null,
                $expectedBinding,
                $runId,
            ),
            $result['status'] === 'passed' ? 0 : 1,
        );
    }

    $release = CspReportOnlyProductionContext::releaseBinding($repoRoot);
    if ($release === null) {
        emitActivationReceipt(
            activationReceipt($action, 'failed', 'release_binding_unavailable', null, null, $runId),
            1,
        );
    }
    $candidate = readActivationCandidate(CSP_ACTIVATION_CANDIDATE);
    if ($candidate === null) {
        emitActivationReceipt(
            activationReceipt($action, 'failed', 'candidate_invalid', null, $release['binding'], $runId),
            2,
        );
    }
    if (!validReleaseBinding($expectedBinding) || !hash_equals($release['binding'], $expectedBinding)) {
        emitActivationReceipt(
            activationReceipt(
                $action,
                'failed',
                'release_binding_mismatch',
                $candidate['sha256'],
                $release['binding'],
                $runId,
            ),
            1,
        );
    }
    if ($action === 'preflight') {
        if ($runId !== null || !validReleaseBinding($expectedBinding)) {
            emitActivationReceipt(
                activationReceipt(
                    $action,
                    'failed',
                    'input_invalid',
                    $candidate['sha256'],
                    $release['binding'],
                    $runId,
                ),
                2,
            );
        }
        $lock = openProductionLock(CSP_ACTIVATION_LOCK);
        if (!is_resource($lock)) {
            emitActivationReceipt(
                activationReceipt(
                    $action,
                    'failed',
                    'production_lock_unavailable',
                    $candidate['sha256'],
                    $release['binding'],
                ),
                1,
            );
        }
        $lockedRelease = null;
        try {
            $lockedRelease = CspReportOnlyProductionContext::releaseBinding($repoRoot);
            $lockedCandidate = readActivationCandidate(CSP_ACTIVATION_CANDIDATE);
            if ($lockedRelease === null) {
                $result = ['status' => 'failed', 'result_class' => 'release_binding_unavailable'];
            } elseif (!hash_equals($expectedBinding, $lockedRelease['binding'])) {
                $result = ['status' => 'failed', 'result_class' => 'release_binding_mismatch'];
            } elseif ($lockedCandidate === null || !hash_equals($candidate['sha256'], $lockedCandidate['sha256'])) {
                $result = ['status' => 'failed', 'result_class' => 'candidate_identity_changed'];
            } else {
                $result = preflightActivation(CSP_ACTIVATION_TARGET, CSP_ACTIVATION_STATE);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        emitActivationReceipt(
            activationReceipt(
                $action,
                $result['status'],
                $result['result_class'],
                $candidate['sha256'],
                is_array($lockedRelease) ? $lockedRelease['binding'] : null,
            ),
            $result['status'] === 'passed' ? 0 : 1,
        );
    }
    if (!validRunId($runId) || !validReleaseBinding($expectedBinding)) {
        emitActivationReceipt(
            activationReceipt($action, 'failed', 'input_invalid', $candidate['sha256'], $release['binding'], $runId),
            2,
        );
    }
    $lock = openProductionLock(CSP_ACTIVATION_LOCK);
    if (!is_resource($lock)) {
        emitActivationReceipt(
            activationReceipt(
                $action,
                'failed',
                'production_lock_unavailable',
                $candidate['sha256'],
                $release['binding'],
                $runId,
            ),
            1,
        );
    }
    try {
        $lockedRelease = CspReportOnlyProductionContext::releaseBinding($repoRoot);
        $lockedCandidate = readActivationCandidate(CSP_ACTIVATION_CANDIDATE);
        if ($lockedRelease === null) {
            $result = ['status' => 'failed', 'result_class' => 'release_binding_unavailable'];
        } elseif (!hash_equals($expectedBinding, $lockedRelease['binding'])) {
            $result = ['status' => 'failed', 'result_class' => 'release_binding_mismatch'];
        } elseif ($lockedCandidate === null || !hash_equals($candidate['sha256'], $lockedCandidate['sha256'])) {
            $result = ['status' => 'failed', 'result_class' => 'candidate_identity_changed'];
        } elseif (@lstat(CSP_ACTIVATION_STATE) !== false) {
            $result = ['status' => 'failed', 'result_class' => 'run_state_already_present'];
        } else {
            $result = writeRunState(CSP_ACTIVATION_STATE, $runId, $candidate['sha256'], $release['binding']);
            if ($result['status'] === 'passed') {
                $result = installActivation(CSP_ACTIVATION_TARGET, $candidate['bytes'], $candidate['sha256']);
                if ($result['status'] !== 'passed') {
                    $result = cleanupFailedInstallState(CSP_ACTIVATION_TARGET, CSP_ACTIVATION_STATE, $result);
                }
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    emitActivationReceipt(
        activationReceipt(
            $action,
            $result['status'],
            $result['result_class'],
            $candidate['sha256'],
            is_array($lockedRelease) ? $lockedRelease['binding'] : null,
            $runId,
        ),
        $result['status'] === 'passed' ? 0 : 1,
    );
}

if (!defined('CSP_ACTIVATION_LOAD_ONLY')) {
    runActivation($argv);
}

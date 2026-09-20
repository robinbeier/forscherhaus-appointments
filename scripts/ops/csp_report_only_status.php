<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

defined('BASEPATH') || define('BASEPATH', $repoRoot . '/system/');
defined('APPPATH') || define('APPPATH', $repoRoot . '/application/');

require_once APPPATH . 'core/Csp_report_only.php';

const CSP_STATUS_SCHEMA = 'csp_report_only_status.v1';
const CSP_STATUS_MAX_AGGREGATE_BYTES = Csp_report_only::MAX_AGGREGATE_BYTES;
const CSP_STATUS_RUNTIME_USER = 'www-data';

/** @return array{expect:string,config_path:string,aggregate_path:string} */
function parseOptions(array $argv): array
{
    $options = [
        'expect' => '',
        'config_path' => Csp_report_only::CONFIG_PATH,
        'aggregate_path' => Csp_report_only::aggregatePath(),
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--expect=')) {
            $options['expect'] = substr($argument, strlen('--expect='));
            continue;
        }

        if (str_starts_with($argument, '--config-path=')) {
            $options['config_path'] = substr($argument, strlen('--config-path='));
            continue;
        }

        if (str_starts_with($argument, '--aggregate-path=')) {
            $options['aggregate_path'] = substr($argument, strlen('--aggregate-path='));
            continue;
        }

        throw new InvalidArgumentException('Unknown option.');
    }

    if (!in_array($options['expect'], ['inactive', 'active'], true)) {
        throw new InvalidArgumentException('--expect must be inactive or active.');
    }

    foreach (['config_path', 'aggregate_path'] as $key) {
        if (
            $options[$key] === '' ||
            $options[$key][0] !== '/' ||
            str_contains($options[$key], "\0") ||
            str_contains($options[$key], '//') ||
            in_array('.', explode('/', $options[$key]), true) ||
            in_array('..', explode('/', $options[$key]), true)
        ) {
            throw new InvalidArgumentException('Status paths must be canonical absolute paths.');
        }
    }

    return $options;
}

/** @return string|null */
function readRegularFileSafely(string $path, int $maxBytes): ?string
{
    if (!isSafeAggregateDirectory(dirname($path))) {
        throw new RuntimeException('Aggregate directory is unavailable.');
    }
    $lockPath = $path . '.lock';
    $lockIdentity = @lstat($lockPath);
    if (!is_array($lockIdentity)) {
        // A missing sidecar is only a zero-observation state when the
        // aggregate is missing too. Do not create or open a lock here.
        if (!is_array(@lstat($path))) {
            return null;
        }
        throw new RuntimeException('Aggregate lock is missing.');
    }
    if (($lockIdentity['mode'] & 0170000) !== 0100000 || (int) ($lockIdentity['nlink'] ?? 0) !== 1) {
        throw new RuntimeException('Aggregate lock identity is invalid.');
    }

    $cursor = dirname($path);
    while ($cursor !== '/') {
        if (is_link($cursor)) {
            throw new RuntimeException('Aggregate path contains a symlink.');
        }
        $next = dirname($cursor);
        if ($next === $cursor) {
            throw new RuntimeException('Aggregate path is invalid.');
        }
        $cursor = $next;
    }

    $lock = @fopen($lockPath, 'rb');
    if (!is_resource($lock)) {
        throw new RuntimeException('Aggregate lock cannot be opened.');
    }

    try {
        if (!flock($lock, LOCK_SH)) {
            throw new RuntimeException('Aggregate lock cannot be acquired.');
        }
        $openedLock = fstat($lock);
        if (
            !is_array($openedLock) ||
            (int) ($openedLock['dev'] ?? -1) !== (int) ($lockIdentity['dev'] ?? -2) ||
            (int) ($openedLock['ino'] ?? -1) !== (int) ($lockIdentity['ino'] ?? -2) ||
            (int) ($openedLock['nlink'] ?? 0) !== 1
        ) {
            throw new RuntimeException('Aggregate lock identity changed.');
        }
        clearstatcache(true, $path);
        $identity = @lstat($path);
        if (!is_array($identity)) {
            return null;
        }
        if (
            (($identity['mode'] ?? 0) & 0170000) !== 0100000 ||
            (int) ($identity['nlink'] ?? 0) !== 1 ||
            (int) ($identity['size'] ?? -1) < 0 ||
            (int) ($identity['size'] ?? 0) > $maxBytes
        ) {
            throw new RuntimeException('Aggregate identity is invalid.');
        }
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Aggregate cannot be opened.');
        }
        try {
            $opened = fstat($stream);
            if (
                !is_array($opened) ||
                (int) ($opened['dev'] ?? -1) !== (int) ($identity['dev'] ?? -2) ||
                (int) ($opened['ino'] ?? -1) !== (int) ($identity['ino'] ?? -2) ||
                (int) ($opened['nlink'] ?? 0) !== 1
            ) {
                throw new RuntimeException('Aggregate identity changed.');
            }
            $bytes = stream_get_contents($stream, $maxBytes + 1);
        } finally {
            fclose($stream);
        }
        if (!is_string($bytes) || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('Aggregate exceeds the status limit.');
        }
        return $bytes;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function isSafeAggregateDirectory(string $directory): bool
{
    $cursor = '';
    foreach (explode('/', trim($directory, '/')) as $part) {
        $cursor .= '/' . $part;
        $identity = @lstat($cursor);
        if (
            !is_array($identity) ||
            (($identity['mode'] ?? 0) & 0170000) !== 0040000 ||
            (($identity['mode'] ?? 0) & 0111) === 0
        ) {
            return false;
        }
    }

    return true;
}

/** @return array{uid:int,gids:list<int>}|null */
function runtimeIdentity(string $username): ?array
{
    if (!function_exists('posix_getpwnam')) {
        return null;
    }
    $account = posix_getpwnam($username);
    if (!is_array($account) || !is_int($account['uid'] ?? null) || !is_int($account['gid'] ?? null)) {
        return null;
    }

    $gids = [$account['gid']];
    $groups = @file('/etc/group', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($groups)) {
        foreach ($groups as $group) {
            $fields = explode(':', $group, 4);
            if (count($fields) !== 4 || preg_match('/\A[0-9]+\z/', $fields[2]) !== 1) {
                continue;
            }
            $members = $fields[3] === '' ? [] : explode(',', $fields[3]);
            if (in_array($username, $members, true)) {
                $gids[] = (int) $fields[2];
            }
        }
    }

    return ['uid' => $account['uid'], 'gids' => array_values(array_unique($gids))];
}

/** @param array<string,mixed> $stat @param array{uid:int,gids:list<int>} $identity */
function runtimeModeAllows(array $stat, array $identity, int $required): bool
{
    $mode = (int) ($stat['mode'] ?? 0) & 0777;
    if ((int) ($stat['uid'] ?? -1) === $identity['uid']) {
        $available = ($mode >> 6) & 7;
    } elseif (in_array((int) ($stat['gid'] ?? -1), $identity['gids'], true)) {
        $available = ($mode >> 3) & 7;
    } else {
        $available = $mode & 7;
    }

    return ($available & $required) === $required;
}

function isAggregateStorageUsableByRuntime(string $path, string $username): bool
{
    $identity = runtimeIdentity($username);
    if ($identity === null) {
        return false;
    }

    $directory = dirname($path);
    $cursor = '';
    foreach (explode('/', trim($directory, '/')) as $part) {
        $cursor .= '/' . $part;
        $stat = @lstat($cursor);
        if (
            !is_array($stat) ||
            (($stat['mode'] ?? 0) & 0170000) !== 0040000 ||
            !runtimeModeAllows($stat, $identity, 1)
        ) {
            return false;
        }
    }

    $parent = @lstat($directory);
    if (!is_array($parent) || !runtimeModeAllows($parent, $identity, 3)) {
        return false;
    }

    $leaf = @lstat($path);
    if (!is_array($leaf)) {
        $lock = @lstat($path . '.lock');
        return (!is_array($lock) && !is_link($path . '.lock')) ||
            (is_array($lock) &&
                (($lock['mode'] ?? 0) & 0170000) === 0100000 &&
                (int) ($lock['nlink'] ?? 0) === 1 &&
                runtimeModeAllows($lock, $identity, 6));
    }

    $lock = @lstat($path . '.lock');
    return (($leaf['mode'] ?? 0) & 0170000) === 0100000 &&
        (int) ($leaf['nlink'] ?? 0) === 1 &&
        runtimeModeAllows($leaf, $identity, 6) &&
        is_array($lock) &&
        (($lock['mode'] ?? 0) & 0170000) === 0100000 &&
        (int) ($lock['nlink'] ?? 0) === 1 &&
        runtimeModeAllows($lock, $identity, 6);
}

function performRuntimeWriteProbe(string $directory): bool
{
    if (!isSafeAggregateDirectory($directory)) {
        return false;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $path = $directory . '/.csp-status-probe-' . bin2hex(random_bytes(16));
        } catch (Throwable) {
            return false;
        }
        $stream = @fopen($path, 'x+b');
        if (!is_resource($stream)) {
            continue;
        }
        $ok = false;
        try {
            $identity = fstat($stream);
            $ok =
                is_array($identity) &&
                (($identity['mode'] ?? 0) & 0170000) === 0100000 &&
                (int) ($identity['nlink'] ?? 0) === 1 &&
                fwrite($stream, "probe\n") === 6 &&
                fflush($stream);
            $ok = $ok && function_exists('fsync') && fsync($stream);
        } finally {
            fclose($stream);
            if (is_link($path) || !@unlink($path)) {
                $ok = false;
            }
        }
        return $ok;
    }

    return false;
}

function runtimeWriteProbe(string $directory, string $username): bool
{
    $identity = runtimeIdentity($username);
    if ($identity === null) {
        return false;
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === $identity['uid']) {
        return performRuntimeWriteProbe($directory);
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_executable('/usr/sbin/runuser')) {
        return false;
    }
    $process = @proc_open(
        ['/usr/sbin/runuser', '--user', $username, '--', PHP_BINARY, __FILE__, '--write-probe', $directory, $username],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        return false;
    }
    return proc_close($process) === 0;
}

/** @param array<string,mixed> $receipt */
function emitReceipt(array $receipt, int $exitCode): never
{
    echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($exitCode);
}

if (($argv[1] ?? null) === '--write-probe') {
    $probeIdentity = isset($argv[3]) && is_string($argv[3]) ? runtimeIdentity($argv[3]) : null;
    if (
        count($argv) !== 4 ||
        !isSafeAggregateDirectory($argv[2]) ||
        $probeIdentity === null ||
        !function_exists('posix_geteuid') ||
        posix_geteuid() !== $probeIdentity['uid']
    ) {
        exit(1);
    }
    exit(performRuntimeWriteProbe($argv[2]) ? 0 : 1);
}

try {
    $options = parseOptions($argv);
    $configExists = @lstat($options['config_path']) !== false;
    $config = Csp_report_only::load($options['config_path']);
    $configStatus = !$configExists
        ? 'missing'
        : ($config === null
            ? 'invalid'
            : ($config['enabled'] ?? false
                ? 'active'
                : 'disabled'));

    $aggregateExists = @lstat($options['aggregate_path']) !== false;
    $aggregateBytes = null;
    $aggregateStatus = $aggregateExists ? 'invalid' : 'missing';
    $activeReadinessCheck = $options['expect'] === 'active' && $configStatus === 'active';
    $runtimeStorageUsable =
        !$activeReadinessCheck ||
        (isAggregateStorageUsableByRuntime($options['aggregate_path'], CSP_STATUS_RUNTIME_USER) &&
            runtimeWriteProbe(dirname($options['aggregate_path']), CSP_STATUS_RUNTIME_USER));
    try {
        if (!$runtimeStorageUsable) {
            throw new RuntimeException('Aggregate storage is unavailable to the runtime user.');
        }
        $aggregateBytes = readRegularFileSafely($options['aggregate_path'], CSP_STATUS_MAX_AGGREGATE_BYTES);
    } catch (Throwable) {
        $aggregateStatus = 'unavailable';
    }
    $aggregateSummary = null;

    if (is_string($aggregateBytes)) {
        $aggregateSummary = Csp_report_only::summarizeAggregateJson($aggregateBytes, $config);
        if (is_array($aggregateSummary)) {
            $aggregateStatus = 'valid';
        }
    }

    $expectedConfigStatus = $options['expect'] === 'active' ? 'active' : 'missing';
    $passed = $configStatus === $expectedConfigStatus && in_array($aggregateStatus, ['missing', 'valid'], true);

    $configHash = null;
    if ($configStatus === 'active') {
        $configHash = hash_file('sha256', $options['config_path']);
        if (!is_string($configHash) || preg_match('/\A[a-f0-9]{64}\z/', $configHash) !== 1) {
            throw new RuntimeException('Configuration identity is unavailable.');
        }
    }

    emitReceipt(
        [
            'schema' => CSP_STATUS_SCHEMA,
            'expectation' => $options['expect'],
            'status' => $passed ? 'passed' : 'failed',
            'config' => [
                'status' => $configStatus,
                'sha256' => $configHash,
            ],
            'aggregate' => [
                'status' => $aggregateStatus,
                'summary' => $aggregateSummary,
            ],
        ],
        $passed ? 0 : 1,
    );
} catch (Throwable) {
    emitReceipt(
        [
            'schema' => CSP_STATUS_SCHEMA,
            'expectation' => 'unknown',
            'status' => 'runtime_failed',
            'config' => ['status' => 'unknown', 'sha256' => null],
            'aggregate' => ['status' => 'unknown', 'summary' => null],
        ],
        2,
    );
}

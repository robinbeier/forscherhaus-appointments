<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

defined('BASEPATH') || define('BASEPATH', $repoRoot . '/system/');
defined('APPPATH') || define('APPPATH', $repoRoot . '/application/');

require_once APPPATH . 'core/Csp_report_only.php';

const CSP_STATUS_SCHEMA = 'csp_report_only_status.v1';
const CSP_STATUS_MAX_AGGREGATE_BYTES = Csp_report_only::MAX_AGGREGATE_BYTES;
const CSP_STATUS_RUNTIME_USER = 'www-data';
const CSP_STATUS_FPM_SOCKET = '/run/php/php8.5-fpm.sock';
const CSP_STATUS_AUTH_ROOT = '/run/fh-csp-report-only-status';
const CSP_STATUS_AUTH_SCHEMA = 'csp_report_only_fpm_auth.v1';
const CSP_STATUS_FCGI_REQUEST_ID = 1;
const CSP_STATUS_MAX_FCGI_BYTES = 16384;
const CSP_STATUS_AUTH_TTL_SECONDS = 15;

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

/** @return array{uid:int,gid:int,gids:list<int>}|null */
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

    return ['uid' => $account['uid'], 'gid' => $account['gid'], 'gids' => array_values(array_unique($gids))];
}

/** @param array<string,mixed> $stat @param array{uid:int,gid:int,gids:list<int>} $identity */
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

function canonicalAbsolutePath(string $path): bool
{
    return $path !== '' &&
        $path[0] === '/' &&
        !str_contains($path, "\0") &&
        !str_contains($path, '//') &&
        !in_array('.', explode('/', $path), true) &&
        !in_array('..', explode('/', $path), true);
}

/** @param array<string,mixed> $left @param array<string,mixed> $right */
function sameFileIdentity(array $left, array $right): bool
{
    return (int) ($left['dev'] ?? -1) === (int) ($right['dev'] ?? -2) &&
        (int) ($left['ino'] ?? -1) === (int) ($right['ino'] ?? -2);
}

function secureDirectory(string $path, int $uid, int $gid, int $mode): ?array
{
    $identity = @lstat($path);
    if (
        !is_array($identity) ||
        (($identity['mode'] ?? 0) & 0170000) !== 0040000 ||
        (int) ($identity['uid'] ?? -1) !== $uid ||
        (int) ($identity['gid'] ?? -1) !== $gid ||
        (($identity['mode'] ?? 0) & 0777) !== $mode ||
        is_link($path)
    ) {
        return null;
    }
    return $identity;
}

function secureRegularFile(string $path, int $uid, int $gid, int $mode, int $maxBytes): ?array
{
    $identity = @lstat($path);
    if (
        !is_array($identity) ||
        (($identity['mode'] ?? 0) & 0170000) !== 0100000 ||
        (int) ($identity['uid'] ?? -1) !== $uid ||
        (int) ($identity['gid'] ?? -1) !== $gid ||
        (($identity['mode'] ?? 0) & 0777) !== $mode ||
        (int) ($identity['nlink'] ?? 0) !== 1 ||
        (int) ($identity['size'] ?? -1) < 0 ||
        (int) ($identity['size'] ?? 0) > $maxBytes
    ) {
        return null;
    }
    return $identity;
}

function removeCreatedAuthRoot(bool $created, array $identity): void
{
    if (!$created) {
        return;
    }
    $current = @lstat(CSP_STATUS_AUTH_ROOT);
    $entries = @scandir(CSP_STATUS_AUTH_ROOT);
    if (is_array($current) && sameFileIdentity($current, $identity) && $entries === ['.', '..']) {
        @rmdir(CSP_STATUS_AUTH_ROOT);
    }
}

/** @return array{root_created:bool,root:array<string,mixed>,op_path:string,op:array<string,mixed>,manifest_path:string,manifest:array<string,mixed>,token:string}|null */
function createFpmAuthorization(string $configPath, string $aggregatePath, array $runtime): ?array
{
    if (
        !function_exists('posix_geteuid') ||
        posix_geteuid() !== 0 ||
        !canonicalAbsolutePath($configPath) ||
        !canonicalAbsolutePath($aggregatePath)
    ) {
        return null;
    }
    $run = secureDirectory('/run', 0, 0, 0755);
    if ($run === null) {
        return null;
    }
    $rootCreated = false;
    if (@lstat(CSP_STATUS_AUTH_ROOT) === false) {
        if (
            !@mkdir(CSP_STATUS_AUTH_ROOT, 0700) ||
            !@chown(CSP_STATUS_AUTH_ROOT, 0) ||
            !@chgrp(CSP_STATUS_AUTH_ROOT, $runtime['gid']) ||
            !@chmod(CSP_STATUS_AUTH_ROOT, 0710)
        ) {
            return null;
        }
        $rootCreated = true;
    }
    $rootIdentity = secureDirectory(CSP_STATUS_AUTH_ROOT, 0, $runtime['gid'], 0710);
    $rootEntries = @scandir(CSP_STATUS_AUTH_ROOT);
    if ($rootIdentity === null || $rootEntries !== ['.', '..']) {
        return null;
    }

    try {
        $opId = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
    } catch (Throwable) {
        removeCreatedAuthRoot($rootCreated, $rootIdentity);
        return null;
    }
    $opPath = CSP_STATUS_AUTH_ROOT . '/op-' . $opId;
    if (!@mkdir($opPath, 0700) || !@chown($opPath, 0) || !@chgrp($opPath, $runtime['gid']) || !@chmod($opPath, 0730)) {
        removeCreatedAuthRoot($rootCreated, $rootIdentity);
        return null;
    }
    $opIdentity = secureDirectory($opPath, 0, $runtime['gid'], 0730);
    $manifestPath = $opPath . '/manifest.json';
    $handle = @fopen($manifestPath, 'x+b');
    if ($opIdentity === null || !is_resource($handle)) {
        @rmdir($opPath);
        removeCreatedAuthRoot($rootCreated, $rootIdentity);
        return null;
    }
    $now = time();
    $manifest = [
        'schema' => CSP_STATUS_AUTH_SCHEMA,
        'op_id' => $opId,
        'token_sha256' => hash('sha256', $token),
        'issued_at' => $now,
        'expires_at' => $now + CSP_STATUS_AUTH_TTL_SECONDS,
        'config_path' => $configPath,
        'aggregate_path' => $aggregatePath,
    ];
    try {
        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable) {
        fclose($handle);
        @unlink($manifestPath);
        @rmdir($opPath);
        removeCreatedAuthRoot($rootCreated, $rootIdentity);
        return null;
    }
    $ok =
        @chown($manifestPath, 0) &&
        @chgrp($manifestPath, $runtime['gid']) &&
        @chmod($manifestPath, 0640) &&
        fwrite($handle, $encoded) === strlen($encoded) &&
        fflush($handle) &&
        function_exists('fsync') &&
        @fsync($handle);
    $opened = @fstat($handle);
    fclose($handle);
    $manifestIdentity = secureRegularFile($manifestPath, 0, $runtime['gid'], 0640, 2048);
    if (!$ok || !is_array($opened) || $manifestIdentity === null || !sameFileIdentity($opened, $manifestIdentity)) {
        @unlink($manifestPath);
        @rmdir($opPath);
        removeCreatedAuthRoot($rootCreated, $rootIdentity);
        return null;
    }
    return [
        'root_created' => $rootCreated,
        'root' => $rootIdentity,
        'op_path' => $opPath,
        'op' => $opIdentity,
        'manifest_path' => $manifestPath,
        'manifest' => $manifestIdentity,
        'token' => $token,
    ];
}

function cleanupFpmAuthorization(array $authorization, array $runtime): bool
{
    $ok = true;
    $manifest = @lstat($authorization['manifest_path']);
    if (
        !is_array($manifest) ||
        !sameFileIdentity($manifest, $authorization['manifest']) ||
        !@unlink($authorization['manifest_path'])
    ) {
        $ok = false;
    }
    $consumedPath = $authorization['op_path'] . '/.consumed';
    $consumed = @lstat($consumedPath);
    if (is_array($consumed)) {
        if (
            (($consumed['mode'] ?? 0) & 0170000) !== 0100000 ||
            (int) ($consumed['uid'] ?? -1) !== $runtime['uid'] ||
            (int) ($consumed['gid'] ?? -1) !== $runtime['gid'] ||
            (int) ($consumed['nlink'] ?? 0) !== 1 ||
            !@unlink($consumedPath)
        ) {
            $ok = false;
        }
    }
    $op = @lstat($authorization['op_path']);
    $entries = @scandir($authorization['op_path']);
    if (
        !is_array($op) ||
        !sameFileIdentity($op, $authorization['op']) ||
        $entries !== ['.', '..'] ||
        !@rmdir($authorization['op_path'])
    ) {
        $ok = false;
    }
    if ($authorization['root_created']) {
        $root = @lstat(CSP_STATUS_AUTH_ROOT);
        $rootEntries = @scandir(CSP_STATUS_AUTH_ROOT);
        if (
            !is_array($root) ||
            !sameFileIdentity($root, $authorization['root']) ||
            $rootEntries !== ['.', '..'] ||
            !@rmdir(CSP_STATUS_AUTH_ROOT)
        ) {
            $ok = false;
        }
    }
    return $ok;
}

function fastCgiLength(int $length): string
{
    return $length < 128 ? pack('C', $length) : pack('N', $length | 0x80000000);
}

function fastCgiRecord(int $type, string $content): string
{
    $length = strlen($content);
    $padding = (8 - ($length % 8)) % 8;
    return pack('CCnnCC', 1, $type, CSP_STATUS_FCGI_REQUEST_ID, $length, $padding, 0) .
        $content .
        str_repeat("\0", $padding);
}

function fastCgiWriteAll($stream, string $bytes): bool
{
    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = @fwrite($stream, substr($bytes, $offset));
        if (!is_int($written) || $written < 1) {
            return false;
        }
        $offset += $written;
    }
    return @fflush($stream);
}

function fastCgiReadExact($stream, int $length): ?string
{
    $bytes = '';
    while (strlen($bytes) < $length) {
        $chunk = @fread($stream, $length - strlen($bytes));
        if (!is_string($chunk) || $chunk === '') {
            return null;
        }
        $bytes .= $chunk;
    }
    return $bytes;
}

function validateFpmSocket(array $runtime): ?array
{
    if (secureDirectory('/run/php', 0, 0, 0755) === null) {
        return null;
    }
    $identity = @lstat(CSP_STATUS_FPM_SOCKET);
    if (
        !is_array($identity) ||
        (($identity['mode'] ?? 0) & 0170000) !== 0140000 ||
        !in_array((int) ($identity['uid'] ?? -1), [0, $runtime['uid']], true) ||
        (int) ($identity['gid'] ?? -1) !== $runtime['gid'] ||
        (int) ($identity['nlink'] ?? 0) !== 1 ||
        (($identity['mode'] ?? 0) & 0777) !== 0660
    ) {
        return null;
    }
    return $identity;
}

/** @return array{stdout:string,stderr:string}|null */
function fastCgiExchange(string $authorizationPath, string $token, array $runtime): ?array
{
    $socketIdentity = validateFpmSocket($runtime);
    $scriptIdentity = secureRegularFile(__FILE__, 0, 0, 0644, 131072);
    if ($socketIdentity === null || $scriptIdentity === null) {
        return null;
    }
    $socket = @stream_socket_client(
        'unix://' . CSP_STATUS_FPM_SOCKET,
        $errorCode,
        $errorMessage,
        3,
        STREAM_CLIENT_CONNECT,
    );
    if (!is_resource($socket)) {
        return null;
    }
    stream_set_timeout($socket, 5);
    clearstatcache(true, CSP_STATUS_FPM_SOCKET);
    $afterConnect = @lstat(CSP_STATUS_FPM_SOCKET);
    if (!is_array($afterConnect) || !sameFileIdentity($socketIdentity, $afterConnect)) {
        fclose($socket);
        return null;
    }
    $params = [
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REQUEST_METHOD' => 'POST',
        'SCRIPT_FILENAME' => __FILE__,
        'SCRIPT_NAME' => '/__fh_csp_report_only_status.php',
        'REQUEST_URI' => '/__fh_csp_report_only_status.php',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '443',
        'REMOTE_ADDR' => '127.0.0.1',
        'CONTENT_TYPE' => 'application/octet-stream',
        'CONTENT_LENGTH' => (string) strlen($token),
        'FH_CSP_STATUS_AUTH' => $authorizationPath,
        'REDIRECT_STATUS' => '200',
    ];
    $encodedParams = '';
    foreach ($params as $name => $value) {
        $encodedParams .= fastCgiLength(strlen($name)) . fastCgiLength(strlen($value)) . $name . $value;
    }
    $request =
        fastCgiRecord(1, pack('nC6', 1, 0, 0, 0, 0, 0, 0)) .
        fastCgiRecord(4, $encodedParams) .
        fastCgiRecord(4, '') .
        fastCgiRecord(5, $token) .
        fastCgiRecord(5, '');
    if (!fastCgiWriteAll($socket, $request)) {
        fclose($socket);
        return null;
    }
    $stdout = '';
    $stderr = '';
    $ended = false;
    while (!$ended && strlen($stdout) + strlen($stderr) <= CSP_STATUS_MAX_FCGI_BYTES) {
        $header = fastCgiReadExact($socket, 8);
        if ($header === null) {
            break;
        }
        $record = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', $header);
        if (!is_array($record) || $record['version'] !== 1 || $record['request'] !== CSP_STATUS_FCGI_REQUEST_ID) {
            break;
        }
        $content = fastCgiReadExact($socket, $record['length']);
        $padding = fastCgiReadExact($socket, $record['padding']);
        if ($content === null || $padding === null) {
            break;
        }
        if ($record['type'] === 6) {
            $stdout .= $content;
        } elseif ($record['type'] === 7) {
            $stderr .= $content;
        } elseif ($record['type'] === 3) {
            $end = strlen($content) === 8 ? unpack('Napp/Cprotocol/Creserved1/Creserved2/Creserved3', $content) : false;
            $ended = is_array($end) && $end['app'] === 0 && $end['protocol'] === 0;
        } else {
            break;
        }
    }
    fclose($socket);
    clearstatcache(true, CSP_STATUS_FPM_SOCKET);
    clearstatcache(true, __FILE__);
    $finalSocket = @lstat(CSP_STATUS_FPM_SOCKET);
    $finalScript = @lstat(__FILE__);
    if (
        !$ended ||
        strlen($stdout) + strlen($stderr) > CSP_STATUS_MAX_FCGI_BYTES ||
        !is_array($finalSocket) ||
        !sameFileIdentity($socketIdentity, $finalSocket) ||
        !is_array($finalScript) ||
        !sameFileIdentity($scriptIdentity, $finalScript)
    ) {
        return null;
    }
    return ['stdout' => $stdout, 'stderr' => $stderr];
}

function fastCgiProbe(string $authorizationPath, string $token, array $runtime): ?string
{
    $exchange = fastCgiExchange($authorizationPath, $token, $runtime);
    if ($exchange === null || $exchange['stderr'] !== '') {
        return null;
    }
    $stdout = $exchange['stdout'];
    $separator = strpos($stdout, "\r\n\r\n");
    if ($separator === false) {
        return null;
    }
    $headers = substr($stdout, 0, $separator);
    $body = substr($stdout, $separator + 4);
    $headerLines = preg_split('/\r\n/', $headers);
    if (!is_array($headerLines)) {
        return null;
    }
    $contentType = 0;
    foreach ($headerLines as $line) {
        if (strcasecmp($line, 'Content-Type: application/json') === 0) {
            $contentType++;
            continue;
        }
        if (preg_match('/\AStatus:\s+200(?:\s|\z)/i', $line) === 1) {
            continue;
        }
        return null;
    }
    return $contentType === 1 ? $body : null;
}

/** @return array{status:string,summary:array<string,mixed>|null}|null */
function runtimeFpmAggregateRead(string $configPath, string $aggregatePath): ?array
{
    $runtime = runtimeIdentity(CSP_STATUS_RUNTIME_USER);
    if ($runtime === null || !isAggregateStorageUsableByRuntime($aggregatePath, CSP_STATUS_RUNTIME_USER)) {
        return null;
    }
    $authorization = createFpmAuthorization($configPath, $aggregatePath, $runtime);
    if ($authorization === null) {
        return null;
    }
    $body = null;
    try {
        $body = fastCgiProbe($authorization['manifest_path'], $authorization['token'], $runtime);
    } finally {
        if (!cleanupFpmAuthorization($authorization, $runtime)) {
            $body = null;
        }
    }
    if (!is_string($body)) {
        return null;
    }
    try {
        $result = json_decode($body, true, 12, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (
        !is_array($result) ||
        array_diff(array_keys($result), ['status', 'summary']) !== [] ||
        array_diff(['status', 'summary'], array_keys($result)) !== [] ||
        !in_array($result['status'], ['missing', 'valid', 'invalid'], true) ||
        ($result['status'] === 'valid' && !is_array($result['summary'])) ||
        ($result['status'] !== 'valid' && $result['summary'] !== null)
    ) {
        return null;
    }
    return $result;
}

/** @return array<string,mixed>|null */
function consumeFpmAuthorization(string $path, string $token, array $runtime): ?array
{
    if (
        preg_match(
            '#\A' . preg_quote(CSP_STATUS_AUTH_ROOT, '#') . '/op-([a-f0-9]{32})/manifest\.json\z#',
            $path,
            $match,
        ) !== 1 ||
        preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1 ||
        secureDirectory(CSP_STATUS_AUTH_ROOT, 0, $runtime['gid'], 0710) === null
    ) {
        return null;
    }
    $opPath = dirname($path);
    if (secureDirectory($opPath, 0, $runtime['gid'], 0730) === null) {
        return null;
    }
    $identity = secureRegularFile($path, 0, $runtime['gid'], 0640, 2048);
    $handle = @fopen($path, 'rb');
    if ($identity === null || !is_resource($handle)) {
        return null;
    }
    try {
        if (!flock($handle, LOCK_SH)) {
            return null;
        }
        $opened = @fstat($handle);
        if (!is_array($opened) || !sameFileIdentity($identity, $opened)) {
            return null;
        }
        $bytes = stream_get_contents($handle, 2049);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    try {
        $manifest =
            is_string($bytes) && strlen($bytes) <= 2048 ? json_decode($bytes, true, 8, JSON_THROW_ON_ERROR) : null;
    } catch (Throwable) {
        return null;
    }
    $expectedKeys = ['schema', 'op_id', 'token_sha256', 'issued_at', 'expires_at', 'config_path', 'aggregate_path'];
    $now = time();
    if (
        !is_array($manifest) ||
        array_diff(array_keys($manifest), $expectedKeys) !== [] ||
        array_diff($expectedKeys, array_keys($manifest)) !== [] ||
        $manifest['schema'] !== CSP_STATUS_AUTH_SCHEMA ||
        $manifest['op_id'] !== $match[1] ||
        !is_string($manifest['token_sha256']) ||
        !hash_equals($manifest['token_sha256'], hash('sha256', $token)) ||
        !is_int($manifest['issued_at']) ||
        !is_int($manifest['expires_at']) ||
        $manifest['expires_at'] - $manifest['issued_at'] !== CSP_STATUS_AUTH_TTL_SECONDS ||
        $manifest['issued_at'] > $now ||
        $manifest['expires_at'] < $now ||
        !is_string($manifest['config_path']) ||
        !canonicalAbsolutePath($manifest['config_path']) ||
        !is_string($manifest['aggregate_path']) ||
        !canonicalAbsolutePath($manifest['aggregate_path'])
    ) {
        return null;
    }
    $consumedPath = $opPath . '/.consumed';
    $consumed = @fopen($consumedPath, 'x+b');
    if (!is_resource($consumed)) {
        return null;
    }
    $ok =
        @chmod($consumedPath, 0640) &&
        fwrite($consumed, $manifest['op_id']) === strlen($manifest['op_id']) &&
        fflush($consumed) &&
        function_exists('fsync') &&
        @fsync($consumed);
    $consumedIdentity = @fstat($consumed);
    fclose($consumed);
    if (
        !$ok ||
        !is_array($consumedIdentity) ||
        (($consumedIdentity['mode'] ?? 0) & 0170000) !== 0100000 ||
        (int) ($consumedIdentity['uid'] ?? -1) !== $runtime['uid'] ||
        (int) ($consumedIdentity['gid'] ?? -1) !== $runtime['gid'] ||
        (int) ($consumedIdentity['nlink'] ?? 0) !== 1
    ) {
        return null;
    }
    return $manifest;
}

function handleFpmProbe(): never
{
    $result = ['status' => 'denied', 'summary' => null];
    $httpStatus = 404;
    try {
        $runtime = runtimeIdentity(CSP_STATUS_RUNTIME_USER);
        $path = $_SERVER['FH_CSP_STATUS_AUTH'] ?? null;
        $token = file_get_contents('php://input', false, null, 0, 65);
        $manifest =
            $runtime !== null && is_string($path) && is_string($token) && strlen($token) === 64
                ? consumeFpmAuthorization($path, $token, $runtime)
                : null;
        if (is_array($manifest)) {
            $config = Csp_report_only::load($manifest['config_path']);
            if (
                !is_array($config) ||
                ($config['enabled'] ?? false) !== true ||
                !performRuntimeWriteProbe(dirname($manifest['aggregate_path']))
            ) {
                throw new RuntimeException('Runtime readiness failed.');
            }
            $bytes = readRegularFileSafely($manifest['aggregate_path'], CSP_STATUS_MAX_AGGREGATE_BYTES);
            if ($bytes === null) {
                $result = ['status' => 'missing', 'summary' => null];
            } else {
                $summary = Csp_report_only::summarizeAggregateJson($bytes, $config);
                $result = is_array($summary)
                    ? ['status' => 'valid', 'summary' => $summary]
                    : ['status' => 'invalid', 'summary' => null];
            }
            $httpStatus = 200;
        }
    } catch (Throwable) {
        $result = ['status' => 'denied', 'summary' => null];
        $httpStatus = 404;
    }
    header_remove();
    http_response_code($httpStatus);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit(0);
}

/** @param array<string,mixed> $receipt */
function emitReceipt(array $receipt, int $exitCode): never
{
    echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($exitCode);
}

function runStatusCli(array $argv): never
{
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
        $aggregateStatus = $aggregateExists ? 'invalid' : 'missing';
        $aggregateSummary = null;
        $activeReadinessCheck = $options['expect'] === 'active' && $configStatus === 'active';
        try {
            if ($activeReadinessCheck) {
                $runtimeResult = runtimeFpmAggregateRead($options['config_path'], $options['aggregate_path']);
                if ($runtimeResult === null) {
                    throw new RuntimeException('FPM runtime check is unavailable.');
                }
                $aggregateStatus = $runtimeResult['status'];
                $aggregateSummary = $runtimeResult['summary'];
            } else {
                $aggregateBytes = readRegularFileSafely($options['aggregate_path'], CSP_STATUS_MAX_AGGREGATE_BYTES);
                if (is_string($aggregateBytes)) {
                    $aggregateSummary = Csp_report_only::summarizeAggregateJson($aggregateBytes, $config);
                    if (is_array($aggregateSummary)) {
                        $aggregateStatus = 'valid';
                    }
                }
            }
        } catch (Throwable) {
            $aggregateStatus = 'unavailable';
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
                'config' => ['status' => $configStatus, 'sha256' => $configHash],
                'aggregate' => ['status' => $aggregateStatus, 'summary' => $aggregateSummary],
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
}

if (PHP_SAPI === 'fpm-fcgi') {
    handleFpmProbe();
}
if (!defined('CSP_STATUS_LOAD_ONLY')) {
    runStatusCli($argv);
}

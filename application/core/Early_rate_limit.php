<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Controller-independent file limiter for hooks which run before controllers exist. */
final class Early_rate_limit
{
    public static function enforce(
        string $ip,
        int $maxRequests,
        int $duration,
        string $cachePath,
        string $keyPrefix = 'rate_limit_key_',
        string $remainPrefix = 'rate_limit_tmp_',
    ): void {
        $cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);
        if (!is_dir($cachePath) || is_link($cachePath)) {
            self::failClosed();
        }
        $cacheKey = str_replace(':', '', $keyPrefix . $ip);
        $remainKey = str_replace(':', '', $remainPrefix . $ip);
        $bucket = hexdec(substr(hash('sha256', $cacheKey), 0, 8)) % 64;
        $lockPath = dirname($cachePath) . DIRECTORY_SEPARATOR . sprintf('rate_limit.lock.%02x', $bucket);
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            self::failClosed();
        }
        $deadline = microtime(true) + 2;
        $acquired = @flock($lock, LOCK_EX | LOCK_NB);
        while (!$acquired && microtime(true) < $deadline) {
            usleep(5000);
            $acquired = @flock($lock, LOCK_EX | LOCK_NB);
        }
        if (!$acquired) {
            @fclose($lock);
            self::failClosed();
        }

        try {
            $now = time();
            $entry = self::read($cachePath . DIRECTORY_SEPARATOR . $cacheKey, $now);
            $remain = self::read($cachePath . DIRECTORY_SEPARATOR . $remainKey, $now);
            $entryPath = $cachePath . DIRECTORY_SEPARATOR . $cacheKey;
            if ($entry === null && (file_exists($entryPath) || is_link($entryPath))) {
                self::failClosed();
            }
            if ($entry === null) {
                $count = 1;
                $window = $now + $duration;
            } else {
                if (!is_int($entry) || $entry < 0) {
                    self::failClosed();
                }
                if (is_string($remain)) {
                    $legacy = DateTime::createFromFormat('!Y-m-d H:i:s', $remain);
                    $errors = DateTime::getLastErrors();
                    if (
                        $legacy === false ||
                        ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
                        $legacy->format('Y-m-d H:i:s') !== $remain
                    ) {
                        self::failClosed();
                    }
                    $window = $now + $duration;
                    if (!self::write($cachePath . DIRECTORY_SEPARATOR . $remainKey, $window, $duration * 2)) {
                        self::failClosed();
                    }
                } elseif (!is_int($remain) || $remain <= 0) {
                    self::failClosed();
                } else {
                    $window = $remain;
                }
                if ($now >= $window) {
                    $count = 1;
                    $window = $now + $duration;
                } else {
                    $count = $entry + 1;
                }
            }
            if (
                !self::write($cachePath . DIRECTORY_SEPARATOR . $cacheKey, $count, $duration) ||
                !self::write($cachePath . DIRECTORY_SEPARATOR . $remainKey, $window, $duration * 2)
            ) {
                self::failClosed();
            }
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        if ($count > $maxRequests) {
            header('Retry-After: ' . max(1, $window - time()));
            header('HTTP/1.0 429 Too Many Requests');
            exit();
        }
    }

    private static function read(string $path, int $now): mixed
    {
        if (is_link($path) || is_dir($path)) {
            self::failClosed();
        }
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        $value = $raw === false ? null : @unserialize($raw, ['allowed_classes' => false]);
        if (
            !is_array($value) ||
            !array_key_exists('time', $value) ||
            !array_key_exists('ttl', $value) ||
            !array_key_exists('data', $value) ||
            !is_int($value['time']) ||
            !is_int($value['ttl']) ||
            $value['ttl'] < 0
        ) {
            self::failClosed();
        }
        if ($now >= $value['time'] + $value['ttl']) {
            @unlink($path);
            return null;
        }
        return $value['data'];
    }

    private static function write(string $path, mixed $data, int $ttl): bool
    {
        if (is_link($path) || is_dir($path)) {
            return false;
        }
        $contents = serialize(['time' => time(), 'ttl' => $ttl, 'data' => $data]);
        $written = @file_put_contents($path, $contents, LOCK_EX);
        if ($written === false) {
            return false;
        }
        @chmod($path, 0640);
        return $written === strlen($contents);
    }

    private static function failClosed(): never
    {
        header('HTTP/1.0 503 Service Unavailable');
        exit();
    }
}

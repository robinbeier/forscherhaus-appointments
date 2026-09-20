<?php

declare(strict_types=1);

final class CspReportOnlyProductionContext
{
    public const RELEASE_MARKER = '_RELEASE';
    public const HEALTH_TOKEN_PATH = '/etc/fh/healthz.token';

    /** @return array{release_id:string,binding:string}|null */
    public static function releaseBinding(string $appRoot): ?array
    {
        if (!self::canonicalRootDirectory($appRoot, 0755)) {
            return null;
        }

        $marker = $appRoot . '/' . self::RELEASE_MARKER;
        $opened = self::openRootFile($marker, 0644, 1, 512);
        if ($opened === null) {
            return null;
        }
        [$handle, $identity] = $opened;
        try {
            $bytes = stream_get_contents($handle, 513);
        } finally {
            fclose($handle);
        }
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 512) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($bytes));
        $releaseId = is_array($parts) ? $parts[0] ?? null : null;
        if (!is_string($releaseId) || preg_match('/\Aea_[A-Za-z0-9_]+\z/', $releaseId) !== 1) {
            return null;
        }

        $binding = hash(
            'sha256',
            implode("\0", [
                'csp-report-only-release-binding.v1',
                $releaseId,
                (string) ($identity['dev'] ?? ''),
                (string) ($identity['ino'] ?? ''),
                hash('sha256', $bytes),
            ]),
        );

        return ['release_id' => $releaseId, 'binding' => $binding];
    }

    public static function healthTokenReady(): bool
    {
        return self::readHealthToken() !== null;
    }

    public static function readHealthToken(): ?string
    {
        $opened = self::openRootFile(self::HEALTH_TOKEN_PATH, 0600, 16, 512);
        if ($opened === null) {
            return null;
        }
        [$handle] = $opened;
        try {
            $token = stream_get_contents($handle, 513);
        } finally {
            fclose($handle);
        }
        if (!is_string($token) || strlen($token) > 512) {
            return null;
        }
        $token = trim($token);

        return strlen($token) >= 16 && preg_match('/[\x00-\x20\x7f]/', $token) !== 1 ? $token : null;
    }

    public static function canonicalRootDirectory(string $directory, int $mode): bool
    {
        if ($directory === '' || $directory[0] !== '/' || realpath($directory) !== $directory) {
            return false;
        }
        $cursor = '';
        foreach (explode('/', trim($directory, '/')) as $part) {
            $cursor .= '/' . $part;
            $identity = @lstat($cursor);
            if (
                !is_array($identity) ||
                (($identity['mode'] ?? 0) & 0170000) !== 0040000 ||
                (int) ($identity['uid'] ?? -1) !== 0 ||
                (int) ($identity['gid'] ?? -1) !== 0 ||
                (((int) ($identity['mode'] ?? 0)) & 0022) !== 0 ||
                is_link($cursor)
            ) {
                return false;
            }
        }
        $identity = @lstat($directory);
        $handle = @fopen($directory, 'rb');
        $opened = is_resource($handle) ? @fstat($handle) : false;
        $valid =
            is_array($identity) &&
            is_resource($handle) &&
            is_array($opened) &&
            (((int) ($identity['mode'] ?? 0)) & 0777) === $mode &&
            (int) ($identity['ino'] ?? -1) === (int) ($opened['ino'] ?? -2) &&
            (int) ($identity['dev'] ?? -1) === (int) ($opened['dev'] ?? -2);
        if (is_resource($handle)) {
            fclose($handle);
        }

        return $valid;
    }

    /** @return array{0:resource,1:array<string,int>}|null */
    public static function openRootFile(string $path, int $mode, int $minBytes, int $maxBytes): ?array
    {
        $identity = @lstat($path);
        if (
            !is_array($identity) ||
            (($identity['mode'] ?? 0) & 0170000) !== 0100000 ||
            (int) ($identity['uid'] ?? -1) !== 0 ||
            (int) ($identity['gid'] ?? -1) !== 0 ||
            (((int) ($identity['mode'] ?? 0)) & 0777) !== $mode ||
            (int) ($identity['nlink'] ?? 0) !== 1 ||
            (int) ($identity['size'] ?? -1) < $minBytes ||
            (int) ($identity['size'] ?? 0) > $maxBytes
        ) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        $opened = @fstat($handle);
        $current = @lstat($path);
        if (
            !is_array($opened) ||
            !is_array($current) ||
            (int) ($opened['ino'] ?? -1) !== (int) ($identity['ino'] ?? -2) ||
            (int) ($opened['dev'] ?? -1) !== (int) ($identity['dev'] ?? -2) ||
            (int) ($opened['nlink'] ?? 0) !== 1 ||
            (int) ($current['ino'] ?? -1) !== (int) ($identity['ino'] ?? -2) ||
            (int) ($current['dev'] ?? -1) !== (int) ($identity['dev'] ?? -2) ||
            (int) ($current['nlink'] ?? 0) !== 1
        ) {
            fclose($handle);
            return null;
        }

        return [$handle, $opened];
    }
}

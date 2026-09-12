<?php

declare(strict_types=1);

namespace ReleaseGate;

use Tests\Integration\Support\OrdinaryJournalSyncFault;

/** Test-only fsync adapter; production never loads this file. */
if (!function_exists(__NAMESPACE__ . '\\fsync')) {
    function fsync($stream): bool
    {
        $meta = is_resource($stream) ? stream_get_meta_data($stream) : [];
        $stat = is_resource($stream) ? @fstat($stream) : false;
        $uri = is_array($meta) ? $meta['uri'] ?? '' : '';
        if (
            is_array($stat) &&
            (($stat['mode'] ?? 0) & 0170000) === 0040000 &&
            is_string($uri) &&
            OrdinaryJournalSyncFault::shouldFailDirectory($uri)
        ) {
            return false;
        }
        if (is_string($uri) && OrdinaryJournalSyncFault::shouldFailFile($uri)) {
            return false;
        }
        return \fsync($stream);
    }
}

namespace Tests\Integration\Support;

final class OrdinaryJournalSyncFault
{
    private static ?string $directory = null;
    private static ?string $file = null;
    private static int $directorySyncs = 0;
    private static bool $fail = false;

    public static function failFile(string $file): void
    {
        self::$file = $file;
    }

    public static function shouldFailFile(string $uri): bool
    {
        return self::$file !== null && $uri === self::$file;
    }

    public static function failDirectory(string $directory): void
    {
        self::$directory = realpath($directory);
        self::$directorySyncs = 0;
        self::$fail = true;
    }

    public static function monitorDirectory(string $directory): void
    {
        self::$directory = realpath($directory);
        self::$directorySyncs = 0;
        self::$fail = false;
    }

    public static function disable(): void
    {
        self::$directory = null;
        self::$file = null;
        self::$fail = false;
    }

    public static function shouldFailDirectory(string $uri): bool
    {
        if (self::$directory === null || realpath($uri) !== self::$directory) {
            return false;
        }
        self::$directorySyncs++;
        return self::$fail;
    }

    public static function directorySyncs(): int
    {
        return self::$directorySyncs;
    }
}

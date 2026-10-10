<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use RuntimeException;

/** Reads an isolated PHP session file while honoring the session writer lock. */
final class SessionFileReader
{
    public static function read(string $path): string
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open the session file for reading.');
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException('Could not acquire the session file shared lock.');
            }

            $contents = stream_get_contents($handle);
            if (!is_string($contents)) {
                throw new RuntimeException('Could not read the session file.');
            }

            return $contents;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

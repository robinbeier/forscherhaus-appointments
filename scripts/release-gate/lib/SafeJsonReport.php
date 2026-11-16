<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

final class SafeJsonReport
{
    /**
     * @param array<string, mixed> $report
     */
    public static function write(string $path, array $report): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create release gate report directory: ' . $directory);
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payload = $json . PHP_EOL;
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create release gate report without following an existing path: ' . $path);
        }

        $written = fwrite($handle, $payload);
        $flushed = $written !== false && fflush($handle);
        fclose($handle);
        if (!$flushed || $written !== strlen($payload) || !@chmod($path, 0600)) {
            @unlink($path);
            throw new RuntimeException('Could not write release gate report to: ' . $path);
        }
    }
}

<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

final class ZeroSurpriseStageConfigPreparer
{
    public static function prepare(string $configPath, string $baseUrl): void
    {
        $raw = @file_get_contents($configPath);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Could not read generated stage config.');
        }

        $updated = self::replaceBaseUrl($raw, $baseUrl);
        $updated = self::disableRateLimiting($updated);

        if (@file_put_contents($configPath, $updated) === false) {
            throw new RuntimeException('Could not write generated stage config.');
        }
    }

    private static function replaceBaseUrl(string $raw, string $baseUrl): string
    {
        $replacement = 'const BASE_URL = ' . var_export($baseUrl, true) . ';';
        $updated = preg_replace('/const BASE_URL = [^;]+;/', $replacement, $raw, 1, $count);

        if (!is_string($updated) || $count !== 1) {
            throw new RuntimeException('Could not patch BASE_URL in generated stage config.');
        }

        return $updated;
    }

    private static function disableRateLimiting(string $raw): string
    {
        $replacement = 'const RATE_LIMITING = false;';
        $updated = preg_replace('/const RATE_LIMITING = (true|false);/i', $replacement, $raw, 1, $count);

        if (is_string($updated) && $count === 1) {
            return $updated;
        }

        $updated = preg_replace(
            '/(const DEBUG_MODE = [^;]+;)/',
            '$1' . PHP_EOL . '    ' . $replacement,
            $raw,
            1,
            $count,
        );

        if (!is_string($updated) || $count !== 1) {
            throw new RuntimeException('Could not disable rate limiting in generated stage config.');
        }

        return $updated;
    }
}

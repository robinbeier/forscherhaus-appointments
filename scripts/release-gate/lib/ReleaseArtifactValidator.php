<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

final class ReleaseArtifactValidator
{
    /**
     * Keep this list intentionally narrow and production-critical:
     * booking, dashboard, shared runtime shell, and deploy tooling.
     *
     * @return list<string>
     */
    public static function requiredPaths(): array
    {
        return [
            'application/config/config.php',
            'deploy_ea.sh',
            'scripts/release-gate/dashboard_release_gate.php',
            'scripts/release-gate/prepare_zero_surprise_stage_config.php',
            'scripts/release-gate/zero_surprise_live_canary.php',
            'scripts/release-gate/zero_surprise_replay.php',
            'assets/css/general.min.css',
            'assets/css/layouts/backend_layout.min.css',
            'assets/css/layouts/booking_layout.min.css',
            'assets/js/app.min.js',
            'assets/js/layouts/backend_layout.min.js',
            'assets/js/layouts/booking_layout.min.js',
            'assets/js/pages/booking.min.js',
            'assets/js/pages/dashboard.min.js',
            'assets/vendor/bootstrap/bootstrap.min.js',
            'assets/vendor/chart.js/chart.umd.min.js',
            'assets/vendor/cookieconsent/cookieconsent.min.js',
            'assets/vendor/flatpickr/flatpickr.min.js',
            'assets/vendor/jquery/jquery.min.js',
            'assets/vendor/moment/moment.min.js',
            'assets/vendor/select2/select2.min.js',
            'assets/vendor/tippy.js/tippy-bundle.umd.min.js',
            'assets/vendor/trumbowyg/trumbowyg.min.js',
        ];
    }

    /**
     * @param iterable<string> $entries
     * @return list<string>
     */
    public static function missingArchivePaths(iterable $entries): array
    {
        $normalizedEntries = [];

        foreach ($entries as $entry) {
            $normalizedEntry = self::normalizePath($entry);

            if ($normalizedEntry === '') {
                continue;
            }

            $normalizedEntries[$normalizedEntry] = true;
        }

        $missing = [];

        foreach (self::requiredPaths() as $requiredPath) {
            if (!isset($normalizedEntries[$requiredPath])) {
                $missing[] = $requiredPath;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public static function missingDirectoryPaths(string $root): array
    {
        $missing = [];
        $normalizedRoot = rtrim($root, '/\\');

        foreach (self::requiredPaths() as $requiredPath) {
            $absolutePath =
                $normalizedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $requiredPath);

            if (!is_file($absolutePath)) {
                $missing[] = $requiredPath;
            }
        }

        return $missing;
    }

    public static function assertDirectoryIsValid(string $root): void
    {
        $missing = self::missingDirectoryPaths($root);

        if ($missing !== []) {
            throw new RuntimeException(
                'Release artifact directory is missing required files: ' . implode(', ', $missing),
            );
        }
    }

    /**
     * @param iterable<string> $entries
     */
    public static function assertArchiveEntriesAreValid(iterable $entries): void
    {
        $missing = self::missingArchivePaths($entries);

        if ($missing !== []) {
            throw new RuntimeException(
                'Release artifact archive is missing required files: ' . implode(', ', $missing),
            );
        }
    }

    private static function normalizePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));
        $normalized = ltrim($normalized, './');

        return trim($normalized, '/');
    }
}

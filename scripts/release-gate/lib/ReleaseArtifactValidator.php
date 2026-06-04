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
     * Keep generated/local-only trees out of release artifacts. They can expose
     * duplicate app/vendor surfaces when deployed under the public webroot.
     *
     * @return list<string>
     */
    public static function forbiddenPathPrefixes(): array
    {
        return ['build'];
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
     * @param iterable<string> $entries
     * @return list<string>
     */
    public static function forbiddenArchivePaths(iterable $entries): array
    {
        $forbidden = [];

        foreach ($entries as $entry) {
            $normalizedEntry = self::normalizePath($entry);

            if ($normalizedEntry === '') {
                continue;
            }

            foreach (self::forbiddenPathPrefixes() as $forbiddenPrefix) {
                if (
                    $normalizedEntry === $forbiddenPrefix ||
                    str_starts_with($normalizedEntry, $forbiddenPrefix . '/')
                ) {
                    $forbidden[] = $normalizedEntry;
                    break;
                }
            }
        }

        return array_values(array_unique($forbidden));
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

    /**
     * @return list<string>
     */
    public static function forbiddenDirectoryPaths(string $root): array
    {
        $forbidden = [];
        $normalizedRoot = rtrim($root, '/\\');

        foreach (self::forbiddenPathPrefixes() as $forbiddenPrefix) {
            $absolutePath =
                $normalizedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $forbiddenPrefix);

            if (file_exists($absolutePath)) {
                $forbidden[] = $forbiddenPrefix;
            }
        }

        return $forbidden;
    }

    public static function assertDirectoryIsValid(string $root): void
    {
        $missing = self::missingDirectoryPaths($root);
        $forbidden = self::forbiddenDirectoryPaths($root);

        if ($missing !== []) {
            throw new RuntimeException(
                'Release artifact directory is missing required files: ' . implode(', ', $missing),
            );
        }

        if ($forbidden !== []) {
            throw new RuntimeException(
                'Release artifact directory contains forbidden paths: ' . implode(', ', $forbidden),
            );
        }
    }

    /**
     * @param iterable<string> $entries
     */
    public static function assertArchiveEntriesAreValid(iterable $entries): void
    {
        $entries = is_array($entries) ? $entries : iterator_to_array($entries, false);
        $missing = self::missingArchivePaths($entries);
        $forbidden = self::forbiddenArchivePaths($entries);

        if ($missing !== []) {
            throw new RuntimeException(
                'Release artifact archive is missing required files: ' . implode(', ', $missing),
            );
        }

        if ($forbidden !== []) {
            throw new RuntimeException(
                'Release artifact archive contains forbidden paths: ' . implode(', ', $forbidden),
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

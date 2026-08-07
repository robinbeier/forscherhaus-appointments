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
            'application/controllers/Booking.php',
            'application/controllers/Console.php',
            'application/controllers/Dashboard.php',
            'application/controllers/Dashboard_export.php',
            'application/controllers/Login.php',
            'application/core/EA_Controller.php',
            'application/core/Provider_ui_smoke_access_policy.php',
            'application/libraries/Provider_ui_smoke_fixture.php',
            'application/views/exports/provider_parent_appointments_pdf.php',
            'application/views/exports/provider_preparation_pdf.php',
            'application/views/pages/dashboard_teacher.php',
            'deploy_ea.sh',
            'scripts/ops/lib/prod_common.sh',
            'scripts/ops/prod_provider_ui_smoke.sh',
            'scripts/ops/provider_ui_smoke_principal.sh',
            'scripts/release-gate/dashboard_release_gate.php',
            'scripts/release-gate/lib/GateAssertions.php',
            'scripts/release-gate/lib/GateHttpClient.php',
            'scripts/release-gate/lib/GateProcessRunner.php',
            'scripts/release-gate/lib/PlaywrightBrowserSelection.php',
            'scripts/release-gate/lib/PlaywrightCookieRecords.php',
            'scripts/release-gate/lib/ProviderUiSmokeContract.php',
            'scripts/release-gate/lib/ProviderUiSmokeCredentials.php',
            'scripts/release-gate/lib/ProviderUiSmokePdfInspector.php',
            'scripts/release-gate/lib/ProviderUiSmokeRunCodeResult.php',
            'scripts/release-gate/playwright/playwright_cli.sh',
            'scripts/release-gate/playwright/provider_ui_smoke.js',
            'scripts/release-gate/prepare_zero_surprise_stage_config.php',
            'scripts/release-gate/provider_ui_smoke.php',
            'scripts/release-gate/zero_surprise_live_canary.php',
            'scripts/release-gate/zero_surprise_replay.php',
            'assets/css/general.min.css',
            'assets/css/layouts/backend_layout.min.css',
            'assets/css/layouts/booking_layout.min.css',
            'assets/js/app.min.js',
            'assets/js/http/dashboard_http_client.min.js',
            'assets/js/layouts/backend_layout.min.js',
            'assets/js/layouts/booking_layout.min.js',
            'assets/js/pages/booking.min.js',
            'assets/js/pages/dashboard.min.js',
            'assets/js/pages/dashboard_teacher.min.js',
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
            if (!self::isRequiredDirectoryPathSafe($normalizedRoot, $requiredPath)) {
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
                'Release artifact directory is missing required regular non-symlink files or has symlink ancestors: ' .
                    implode(', ', $missing),
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
    public static function assertArchiveEntriesAreValid(iterable $entries, array $entryTypes): void
    {
        $entries = is_array($entries) ? $entries : iterator_to_array($entries, false);
        $missing = self::missingArchivePaths($entries);
        $forbidden = self::forbiddenArchivePaths($entries);
        $invalidTypes = self::invalidArchivePathTypes($entryTypes);

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

        if ($invalidTypes !== []) {
            throw new RuntimeException(
                'Release artifact archive required paths are not unique regular non-symlink files with safe directory ancestors: ' .
                    implode(', ', $invalidTypes),
            );
        }
    }

    /**
     * Paths whose archive entry types affect a required file's trust boundary.
     * Required files must occur exactly once as regular files. Explicit parent
     * entries, when present, must occur exactly once as directories.
     *
     * @return list<string>
     */
    public static function archiveTypePaths(): array
    {
        $paths = [];

        foreach (self::requiredPaths() as $requiredPath) {
            $paths[$requiredPath] = true;
            $parent = dirname($requiredPath);

            while ($parent !== '.' && $parent !== '') {
                $paths[$parent] = true;
                $parent = dirname($parent);
            }
        }

        return array_keys($paths);
    }

    /**
     * @param array<string,list<string>> $entryTypes
     * @return list<string>
     */
    public static function invalidArchivePathTypes(array $entryTypes): array
    {
        $invalid = [];
        $requiredFiles = array_fill_keys(self::requiredPaths(), true);

        foreach (self::archiveTypePaths() as $path) {
            $types = $entryTypes[$path] ?? [];

            if (isset($requiredFiles[$path])) {
                if (count($types) !== 1 || $types[0] !== '-') {
                    $invalid[] = $path;
                }

                continue;
            }

            if ($types !== [] && (count($types) !== 1 || $types[0] !== 'd')) {
                $invalid[] = $path;
            }
        }

        return $invalid;
    }

    private static function normalizePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));
        $normalized = ltrim($normalized, './');

        return trim($normalized, '/');
    }

    private static function isRequiredDirectoryPathSafe(string $root, string $requiredPath): bool
    {
        if ($root === '' || !is_dir($root) || is_link($root)) {
            return false;
        }

        $components = explode('/', $requiredPath);
        $current = $root;
        $lastIndex = count($components) - 1;

        foreach ($components as $index => $component) {
            $current .= DIRECTORY_SEPARATOR . $component;

            if ($index === $lastIndex) {
                return is_file($current) && !is_link($current);
            }

            if (!is_dir($current) || is_link($current)) {
                return false;
            }
        }

        return false;
    }
}

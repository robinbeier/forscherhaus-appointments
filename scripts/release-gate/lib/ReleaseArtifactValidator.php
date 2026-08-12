<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

final class ReleaseArtifactValidator
{
    /**
     * Generated vendor files emitted by the gulp `vendor` task. JavaScript and
     * stylesheet outputs are derived from the exact committed source tree so a
     * newly added page/component cannot silently disappear from a release.
     *
     * @return list<string>
     */
    public static function generatedVendorPaths(): array
    {
        return [
            'assets/vendor/@fortawesome-fontawesome-free/fontawesome.min.js',
            'assets/vendor/@fortawesome-fontawesome-free/solid.min.js',
            'assets/vendor/@popperjs-core/popper.min.js',
            'assets/vendor/bootstrap/bootstrap.min.css',
            'assets/vendor/bootstrap/bootstrap.min.js',
            'assets/vendor/chart.js/chart.umd.min.js',
            'assets/vendor/chartjs-chart-matrix/chartjs-chart-matrix.min.js',
            'assets/vendor/cookieconsent/cookieconsent.min.css',
            'assets/vendor/cookieconsent/cookieconsent.min.js',
            'assets/vendor/flatpickr/flatpickr.min.css',
            'assets/vendor/flatpickr/flatpickr.min.js',
            'assets/vendor/flatpickr/material_green.min.css',
            'assets/vendor/fullcalendar/index.global.min.js',
            'assets/vendor/fullcalendar-moment/index.global.min.js',
            'assets/vendor/html2canvas/html2canvas.min.js',
            'assets/vendor/jquery/jquery.min.js',
            'assets/vendor/jquery-jeditable/jquery.jeditable.min.js',
            'assets/vendor/jspdf/jspdf.umd.min.js',
            'assets/vendor/moment/moment.min.js',
            'assets/vendor/moment-timezone/moment-timezone-with-data.min.js',
            'assets/vendor/qrcode/qrcode.min.js',
            'assets/vendor/select2/select2.min.css',
            'assets/vendor/select2/select2.min.js',
            'assets/vendor/tippy.js/tippy-bundle.umd.min.js',
            'assets/vendor/trumbowyg/trumbowyg.min.css',
            'assets/vendor/trumbowyg/trumbowyg.min.js',
            'assets/vendor/trumbowyg/ui/icons.svg',
        ];
    }

    /**
     * @param iterable<string> $sourcePaths
     * @return list<string>
     */
    public static function generatedRuntimeAssetPaths(iterable $sourcePaths): array
    {
        $paths = array_fill_keys(self::generatedVendorPaths(), true);

        foreach ($sourcePaths as $sourcePath) {
            $sourcePath = self::normalizePath($sourcePath);

            if (
                str_starts_with($sourcePath, 'assets/js/') &&
                str_ends_with($sourcePath, '.js') &&
                !str_ends_with($sourcePath, '.min.js')
            ) {
                $paths[substr($sourcePath, 0, -3) . '.min.js'] = true;
            }

            if (str_starts_with($sourcePath, 'assets/css/') && str_ends_with($sourcePath, '.scss')) {
                $base = substr($sourcePath, 0, -5);
                $paths[$base . '.css'] = true;
                $paths[$base . '.min.css'] = true;
            }
        }

        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return list<string>
     */
    public static function generatedRuntimeAssetPathsForDirectory(string $root): array
    {
        $sourcePaths = [];
        $normalizedRoot = rtrim($root, '/\\');

        foreach (['assets/js', 'assets/css'] as $relativeRoot) {
            $absoluteRoot = $normalizedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);

            if (!is_dir($absoluteRoot) || is_link($absoluteRoot)) {
                throw new RuntimeException('Release artifact generated-asset source tree is missing or unsafe: ' . $relativeRoot);
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink()) {
                    continue;
                }

                $absolutePath = $entry->getPathname();
                $sourcePaths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($normalizedRoot) + 1));
            }
        }

        return self::generatedRuntimeAssetPaths($sourcePaths);
    }

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
            'application/controllers/Customers.php',
            'application/controllers/Dashboard.php',
            'application/controllers/Dashboard_export.php',
            'application/controllers/Login.php',
            'application/core/EA_Controller.php',
            'application/core/Customers_ui_smoke_access_policy.php',
            'application/core/Provider_ui_smoke_access_policy.php',
            'application/libraries/Provider_ui_smoke_fixture.php',
            'application/libraries/Customers_ui_smoke_fixture.php',
            'application/views/exports/provider_parent_appointments_pdf.php',
            'application/views/exports/provider_preparation_pdf.php',
            'application/views/pages/dashboard_teacher.php',
            'application/views/pages/customers.php',
            'deploy_ea.sh',
            'scripts/ops/config/traffic_gate_catalog.v1.json',
            'scripts/ops/lib/TrafficGateV1.php',
            'scripts/ops/lib/prod_common.sh',
            'scripts/ops/validate_deploy_timing_sample.php',
            'scripts/ops/customers_ui_smoke_principals.sh',
            'scripts/ops/prod_customers_ui_smoke.sh',
            'scripts/ops/prod_traffic_gate.sh',
            'scripts/ops/prod_provider_ui_smoke.sh',
            'scripts/ops/provider_ui_smoke_principal.sh',
            'scripts/ops/traffic_gate_v1.php',
            'scripts/release-gate/dashboard_release_gate.php',
            'scripts/release-gate/lib/GateAssertions.php',
            'scripts/release-gate/lib/GateHttpClient.php',
            'scripts/release-gate/lib/GateProcessRunner.php',
            'scripts/release-gate/lib/CustomersUiSmokeContract.php',
            'scripts/release-gate/lib/CustomersUiSmokeGateRuntime.php',
            'scripts/release-gate/lib/PlaywrightBrowserSelection.php',
            'scripts/release-gate/lib/PlaywrightCookieRecords.php',
            'scripts/release-gate/lib/ProviderUiSmokeContract.php',
            'scripts/release-gate/lib/ProviderUiSmokeCredentials.php',
            'scripts/release-gate/lib/ProviderUiSmokePdfInspector.php',
            'scripts/release-gate/lib/ProviderUiSmokeRunCodeResult.php',
            'scripts/release-gate/playwright/playwright_cli.sh',
            'scripts/release-gate/playwright/customers_ui_smoke.js',
            'scripts/release-gate/playwright/provider_ui_smoke.js',
            'scripts/release-gate/prepare_zero_surprise_stage_config.php',
            'scripts/release-gate/customers_ui_smoke.php',
            'scripts/release-gate/provider_ui_smoke.php',
            'scripts/release-gate/zero_surprise_live_canary.php',
            'scripts/release-gate/zero_surprise_replay.php',
            'assets/css/general.min.css',
            'assets/css/layouts/backend_layout.min.css',
            'assets/css/layouts/booking_layout.min.css',
            'assets/js/app.min.js',
            'assets/js/http/customers_http_client.min.js',
            'assets/js/http/dashboard_http_client.min.js',
            'assets/js/layouts/backend_layout.min.js',
            'assets/js/layouts/booking_layout.min.js',
            'assets/js/pages/booking.min.js',
            'assets/js/pages/customers.min.js',
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
        $entries = is_array($entries) ? $entries : iterator_to_array($entries, false);
        $normalizedEntries = [];

        foreach ($entries as $entry) {
            $normalizedEntry = self::normalizePath($entry);

            if ($normalizedEntry === '') {
                continue;
            }

            $normalizedEntries[$normalizedEntry] = true;
        }

        $missing = [];

        $requiredPaths = array_values(
            array_unique(array_merge(self::requiredPaths(), self::generatedRuntimeAssetPaths($entries))),
        );

        foreach ($requiredPaths as $requiredPath) {
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
        $generatedPaths = self::generatedRuntimeAssetPathsForDirectory($root);

        foreach ($generatedPaths as $generatedPath) {
            if (!self::isRequiredDirectoryPathSafe(rtrim($root, '/\\'), $generatedPath)) {
                $missing[] = $generatedPath;
            }
        }

        $missing = array_values(array_unique($missing));
        $forbidden = self::forbiddenDirectoryPaths($root);

        if ($missing !== []) {
            throw new RuntimeException(
                'Release artifact directory is missing required regular non-symlink files with exactly one hardlink or has symlink ancestors: ' .
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
        $requiredPaths = array_values(
            array_unique(array_merge(self::requiredPaths(), self::generatedRuntimeAssetPaths($entries))),
        );
        $invalidTypes = self::invalidArchivePathTypes($entryTypes, $requiredPaths);

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
                'Release artifact archive required paths are not unique regular non-symlink files without hardlink aliases and with safe directory ancestors: ' .
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
    public static function archiveTypePaths(iterable $entries = []): array
    {
        $paths = [];
        $entries = is_array($entries) ? $entries : iterator_to_array($entries, false);
        $requiredPaths = array_values(
            array_unique(array_merge(self::requiredPaths(), self::generatedRuntimeAssetPaths($entries))),
        );

        foreach ($requiredPaths as $requiredPath) {
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
    public static function invalidArchivePathTypes(array $entryTypes, ?array $requiredPaths = null): array
    {
        $invalid = [];
        $requiredPaths ??= self::requiredPaths();
        $requiredFiles = array_fill_keys($requiredPaths, true);
        $typePaths = [];

        foreach ($requiredPaths as $requiredPath) {
            $typePaths[$requiredPath] = true;
            $parent = dirname($requiredPath);

            while ($parent !== '.' && $parent !== '') {
                $typePaths[$parent] = true;
                $parent = dirname($parent);
            }
        }

        foreach (array_keys($typePaths) as $path) {
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
        $rootMetadata = $root === '' ? false : @lstat($root);

        if (!is_array($rootMetadata) || ($rootMetadata['mode'] & 0170000) !== 0040000) {
            return false;
        }

        $components = explode('/', $requiredPath);
        $current = $root;
        $lastIndex = count($components) - 1;

        foreach ($components as $index => $component) {
            $current .= DIRECTORY_SEPARATOR . $component;
            $metadata = @lstat($current);

            if (!is_array($metadata)) {
                return false;
            }

            if ($index === $lastIndex) {
                return ($metadata['mode'] & 0170000) === 0100000 && (int) $metadata['nlink'] === 1;
            }

            if (($metadata['mode'] & 0170000) !== 0040000) {
                return false;
            }
        }

        return false;
    }
}

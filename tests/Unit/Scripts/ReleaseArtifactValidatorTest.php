<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ReleaseArtifactValidator;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ReleaseArtifactValidator.php';

final class ReleaseArtifactValidatorTest extends TestCase
{
    public function testMissingDirectoryPathsReturnsEmptyForCompleteArtifactTree(): void
    {
        $root = sys_get_temp_dir() . '/release-artifact-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            foreach (ReleaseArtifactValidator::requiredPaths() as $requiredPath) {
                $absolutePath = $root . '/' . $requiredPath;
                $directory = dirname($absolutePath);

                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }

                file_put_contents($absolutePath, 'ok');
            }

            self::assertSame([], ReleaseArtifactValidator::missingDirectoryPaths($root));
            self::assertSame([], ReleaseArtifactValidator::forbiddenDirectoryPaths($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMissingArchivePathsDetectsFrontendAssetGap(): void
    {
        $entries = array_filter(
            ReleaseArtifactValidator::requiredPaths(),
            static fn(string $path): bool => $path !== 'assets/vendor/jquery/jquery.min.js',
        );

        $missing = ReleaseArtifactValidator::missingArchivePaths($entries);

        self::assertSame(['assets/vendor/jquery/jquery.min.js'], $missing);
    }

    public function testRequiredPathsCoverCompleteProviderUiSmokeRuntimeSurface(): void
    {
        $requiredPaths = ReleaseArtifactValidator::requiredPaths();

        $providerUiSmokePaths = [
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
            'scripts/ops/lib/prod_common.sh',
            'scripts/ops/runtime_config_permissions.sh',
            'scripts/ops/runtime_config_rollback.sh',
            'scripts/ops/prod_provider_ui_smoke.sh',
            'scripts/ops/provider_ui_smoke_principal.sh',
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
            'scripts/release-gate/provider_ui_smoke.php',
            'assets/js/http/dashboard_http_client.min.js',
            'assets/js/pages/dashboard_teacher.min.js',
        ];

        foreach ($providerUiSmokePaths as $providerUiSmokePath) {
            self::assertContains($providerUiSmokePath, $requiredPaths);
        }

        $missingPath = 'scripts/release-gate/playwright/provider_ui_smoke.js';
        $entries = array_values(array_filter($requiredPaths, static fn(string $path): bool => $path !== $missingPath));

        self::assertSame([$missingPath], ReleaseArtifactValidator::missingArchivePaths($entries));
    }

    public function testForbiddenDirectoryPathsDetectsLocalBuildTree(): void
    {
        $root = sys_get_temp_dir() . '/release-artifact-forbidden-' . bin2hex(random_bytes(4));
        mkdir($root . '/build/vendor', 0777, true);

        try {
            self::assertSame(['build'], ReleaseArtifactValidator::forbiddenDirectoryPaths($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testForbiddenArchivePathsDetectsLocalBuildTree(): void
    {
        $entries = ['./application/config/config.php', './build/', './build/vendor/autoload.php'];

        self::assertSame(
            ['build', 'build/vendor/autoload.php'],
            ReleaseArtifactValidator::forbiddenArchivePaths($entries),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $childPath = $path . '/' . $entry;

            if (is_dir($childPath)) {
                $this->removeDirectory($childPath);
                continue;
            }

            @unlink($childPath);
        }

        @rmdir($path);
    }
}

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
            $this->createCompleteArtifactTree($root);

            self::assertSame([], ReleaseArtifactValidator::missingDirectoryPaths($root));
            self::assertSame([], ReleaseArtifactValidator::forbiddenDirectoryPaths($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMissingArchivePathsDetectsFrontendAssetGap(): void
    {
        $entries = array_filter(
            $this->completeStaticRequiredPaths(),
            static fn(string $path): bool => $path !== 'assets/vendor/jquery/jquery.min.js',
        );

        $missing = ReleaseArtifactValidator::missingArchivePaths($entries);

        self::assertSame(['assets/vendor/jquery/jquery.min.js'], $missing);
    }

    public function testGeneratedRuntimeManifestCoversEveryCommittedScriptStylesheetAndVendorOutput(): void
    {
        $paths = ReleaseArtifactValidator::generatedRuntimeAssetPaths([
            'assets/js/app.js',
            'assets/js/pages/login.js',
            'assets/css/general.scss',
            'assets/css/themes/default.scss',
        ]);

        foreach (
            [
                'assets/js/app.min.js',
                'assets/js/pages/login.min.js',
                'assets/css/general.css',
                'assets/css/general.min.css',
                'assets/css/themes/default.css',
                'assets/css/themes/default.min.css',
                'assets/vendor/@popperjs-core/popper.min.js',
                'assets/vendor/bootstrap/bootstrap.min.css',
            ] as $expectedPath
        ) {
            self::assertContains($expectedPath, $paths);
        }
    }

    public function testArchiveManifestRejectsMissingGeneratedPageAssetOutsideNarrowSmokeList(): void
    {
        $sourcePaths = ['assets/js/pages/login.js', 'assets/css/themes/default.scss'];
        $generatedPaths = ReleaseArtifactValidator::generatedRuntimeAssetPaths($sourcePaths);
        $entries = array_values(
            array_filter(
                [...$this->completeStaticRequiredPaths(), ...$sourcePaths, ...$generatedPaths],
                static fn(string $path): bool => $path !== 'assets/js/pages/login.min.js',
            ),
        );

        self::assertContains('assets/js/pages/login.min.js', ReleaseArtifactValidator::missingArchivePaths($entries));
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
        $entries = array_values(
            array_filter($this->completeStaticRequiredPaths(), static fn(string $path): bool => $path !== $missingPath),
        );

        self::assertSame([$missingPath], ReleaseArtifactValidator::missingArchivePaths($entries));
    }

    public function testRequiredPathsIncludeDeployTimingValidator(): void
    {
        self::assertContains(
            'scripts/ops/validate_deploy_timing_sample.php',
            ReleaseArtifactValidator::requiredPaths(),
        );
    }

    public function testRequiredPathsCoverCompleteCustomersUiSmokeRuntimeSurface(): void
    {
        $requiredPaths = ReleaseArtifactValidator::requiredPaths();
        $customersUiSmokePaths = [
            'application/controllers/Console.php',
            'application/controllers/Customers.php',
            'application/controllers/Login.php',
            'application/core/EA_Controller.php',
            'application/core/Customers_ui_smoke_access_policy.php',
            'application/libraries/Customers_ui_smoke_fixture.php',
            'application/views/pages/customers.php',
            'assets/js/http/customers_http_client.min.js',
            'assets/js/pages/customers.min.js',
            'scripts/ops/customers_ui_smoke_principals.sh',
            'scripts/ops/config/traffic_gate_catalog.v1.json',
            'scripts/ops/lib/TrafficGateV1.php',
            'scripts/ops/prod_customers_ui_smoke.sh',
            'scripts/ops/prod_traffic_gate.sh',
            'scripts/ops/traffic_gate_v1.php',
            'scripts/release-gate/customers_ui_smoke.php',
            'scripts/release-gate/lib/CustomersUiSmokeContract.php',
            'scripts/release-gate/lib/CustomersUiSmokeGateRuntime.php',
            'scripts/release-gate/playwright/customers_ui_smoke.js',
            'scripts/release-gate/playwright/playwright_cli.sh',
        ];

        foreach ($customersUiSmokePaths as $path) {
            self::assertContains($path, $requiredPaths);
        }

        $missingPath = 'scripts/ops/prod_traffic_gate.sh';
        $entries = array_values(
            array_filter($this->completeStaticRequiredPaths(), static fn(string $path): bool => $path !== $missingPath),
        );

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

    public function testDirectoryValidationRejectsRequiredFileSymlink(): void
    {
        $root = sys_get_temp_dir() . '/release-artifact-symlink-file-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            $this->createCompleteArtifactTree($root);
            $outside = $root . '/outside-deploy.sh';
            file_put_contents($outside, 'outside');
            unlink($root . '/deploy_ea.sh');
            symlink($outside, $root . '/deploy_ea.sh');

            self::assertContains('deploy_ea.sh', ReleaseArtifactValidator::missingDirectoryPaths($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testDirectoryValidationRejectsSymlinkAncestor(): void
    {
        $root = sys_get_temp_dir() . '/release-artifact-symlink-parent-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            $this->createCompleteArtifactTree($root);
            rename($root . '/scripts/ops', $root . '/real-ops');
            symlink($root . '/real-ops', $root . '/scripts/ops');

            $invalid = ReleaseArtifactValidator::missingDirectoryPaths($root);
            self::assertContains('scripts/ops/lib/prod_common.sh', $invalid);
            self::assertContains('scripts/ops/prod_provider_ui_smoke.sh', $invalid);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testDirectoryValidationRejectsUnlistedHardlinkAliasForRequiredFile(): void
    {
        $root = sys_get_temp_dir() . '/release-artifact-hardlink-alias-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            $this->createCompleteArtifactTree($root);
            link($root . '/deploy_ea.sh', $root . '/unlisted-deploy-alias.sh');

            self::assertContains('deploy_ea.sh', ReleaseArtifactValidator::missingDirectoryPaths($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testArchiveTypeValidationRejectsSymlinkAndDuplicateRequiredFiles(): void
    {
        $entryTypes = [];
        $requiredFiles = array_fill_keys($this->completeStaticRequiredPaths(), true);

        foreach (ReleaseArtifactValidator::archiveTypePaths() as $path) {
            $entryTypes[$path] = [isset($requiredFiles[$path]) ? '-' : 'd'];
        }

        $entryTypes['deploy_ea.sh'] = ['l'];
        $entryTypes['application/controllers/Booking.php'] = ['-', '-'];
        $entryTypes['application/config/config.php'] = ['-', 'hardlink-alias'];
        $entryTypes['application'] = ['d', 'd'];

        $invalid = ReleaseArtifactValidator::invalidArchivePathTypes($entryTypes);
        self::assertContains('deploy_ea.sh', $invalid);
        self::assertContains('application/controllers/Booking.php', $invalid);
        self::assertContains('application/config/config.php', $invalid);
        self::assertContains('application', $invalid);
    }

    public function testArchiveCliRejectsRequiredSymlinkEntry(): void
    {
        $workspace = sys_get_temp_dir() . '/release-artifact-archive-symlink-' . bin2hex(random_bytes(4));
        $root = $workspace . '/root';
        $archive = $workspace . '/release.tar.gz';
        mkdir($root, 0777, true);

        try {
            $this->createCompleteArtifactTree($root);
            $outside = $root . '/outside-deploy.sh';
            file_put_contents($outside, 'outside');
            unlink($root . '/deploy_ea.sh');
            symlink('outside-deploy.sh', $root . '/deploy_ea.sh');

            $tar = $this->runCommand(['tar', '-czf', $archive, '-C', $root, '.']);
            self::assertSame(0, $tar['exit_code'], $tar['stderr']);

            $result = $this->runCommand([
                'php',
                dirname(__DIR__, 3) . '/scripts/release-gate/validate_release_artifact.php',
                '--archive=' . $archive,
            ]);

            self::assertNotSame(0, $result['exit_code']);
            self::assertStringContainsString('deploy_ea.sh', $result['stderr']);
            self::assertStringContainsString('regular non-symlink', $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testArchiveCliAcceptsUniqueRegularRequiredFiles(): void
    {
        $workspace = sys_get_temp_dir() . '/release-artifact-archive-regular-' . bin2hex(random_bytes(4));
        $root = $workspace . '/root';
        $archive = $workspace . '/release.tar.gz';
        mkdir($root, 0777, true);

        try {
            $this->createCompleteArtifactTree($root);
            $tar = $this->runCommand(['tar', '-czf', $archive, '-C', $root, '.']);
            self::assertSame(0, $tar['exit_code'], $tar['stderr']);

            $result = $this->runCommand([
                'php',
                dirname(__DIR__, 3) . '/scripts/release-gate/validate_release_artifact.php',
                '--archive=' . $archive,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('Release artifact archive contains required files', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testArchiveCliRejectsUnlistedHardlinkAliasToRequiredRegularEntry(): void
    {
        $workspace = sys_get_temp_dir() . '/release-artifact-archive-hardlink-' . bin2hex(random_bytes(4));
        $root = $workspace . '/root';
        $archive = $workspace . '/release.tar.gz';
        $configMarker = 'SENSITIVE_CONFIG_CONTENT_MUST_NOT_BE_PRINTED';
        mkdir($root, 0777, true);

        try {
            $this->createCompleteArtifactTree($root);
            file_put_contents($root . '/application/config/config.php', $configMarker);
            link($root . '/application/config/config.php', $root . '/unlisted-config-alias.php');
            $tar = $this->runCommand([
                'tar',
                '-czf',
                $archive,
                '-C',
                $root,
                ...$this->completeStaticRequiredPaths(),
                'unlisted-config-alias.php',
            ]);
            self::assertSame(0, $tar['exit_code'], $tar['stderr']);

            $result = $this->runCommand([
                'php',
                dirname(__DIR__, 3) . '/scripts/release-gate/validate_release_artifact.php',
                '--archive=' . $archive,
            ]);

            self::assertNotSame(0, $result['exit_code']);
            self::assertStringContainsString('application/config/config.php', $result['stderr']);
            self::assertStringContainsString('hardlink aliases', $result['stderr']);
            self::assertStringNotContainsString($configMarker, $result['stdout']);
            self::assertStringNotContainsString($configMarker, $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testArchiveCliRejectsHardlinkTargetWithRepeatedSeparator(): void
    {
        $configMarker = 'SENSITIVE_CONFIG_CONTENT_MUST_NOT_BE_PRINTED';
        $result = $this->runArchiveCliWithFakeListing(
            ['./application/config/config.php', './unlisted-config-alias.php'],
            [...$this->validArchiveTypeLines(), 'h unlisted-config-alias.php link to ./application//config/config.php'],
            $configMarker,
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('malformed or non-canonical hardlink target', $result['stderr']);
        self::assertStringNotContainsString($configMarker, $result['stdout']);
        self::assertStringNotContainsString($configMarker, $result['stderr']);
    }

    public function testArchiveCliRejectsHardlinkTargetWithDotSegment(): void
    {
        $configMarker = 'SENSITIVE_CONFIG_CONTENT_MUST_NOT_BE_PRINTED';
        $result = $this->runArchiveCliWithFakeListing(
            ['./application/config/config.php', './unlisted-config-alias.php'],
            [
                ...$this->validArchiveTypeLines(),
                'h unlisted-config-alias.php link to ./application/./config/config.php',
            ],
            $configMarker,
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('malformed or non-canonical hardlink target', $result['stderr']);
        self::assertStringNotContainsString($configMarker, $result['stdout']);
        self::assertStringNotContainsString($configMarker, $result['stderr']);
    }

    public function testArchiveCliRejectsAmbiguousHardlinkAliasWithAbsoluteTarget(): void
    {
        $configMarker = 'SENSITIVE_CONFIG_CONTENT_MUST_NOT_BE_PRINTED';
        $result = $this->runArchiveCliWithFakeListing(
            ['./application/config/config.php', './alias link to marker'],
            [...$this->validArchiveTypeLines(), 'h alias link to marker link to /outside-host-file'],
            $configMarker,
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('malformed or non-canonical hardlink target', $result['stderr']);
        self::assertStringNotContainsString($configMarker, $result['stdout']);
        self::assertStringNotContainsString($configMarker, $result['stderr']);
    }

    public function testArchiveCliRejectsAmbiguousHardlinkAliasWithParentTarget(): void
    {
        $configMarker = 'SENSITIVE_CONFIG_CONTENT_MUST_NOT_BE_PRINTED';
        $result = $this->runArchiveCliWithFakeListing(
            ['./application/config/config.php', './alias link to marker'],
            [...$this->validArchiveTypeLines(), 'h alias link to marker link to ../outside-host-file'],
            $configMarker,
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('malformed or non-canonical hardlink target', $result['stderr']);
        self::assertStringNotContainsString($configMarker, $result['stdout']);
        self::assertStringNotContainsString($configMarker, $result['stderr']);
    }

    public function testArchiveCliRejectsOverlappingHardlinkSeparators(): void
    {
        $configMarker = 'SENSITIVE_CONFIG_CONTENT_MUST_NOT_BE_PRINTED';
        $result = $this->runArchiveCliWithFakeListing(
            ['./application/config/config.php', './alias link to'],
            [...$this->validArchiveTypeLines(), 'h alias link to link to /outside-host-file'],
            $configMarker,
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('malformed or non-canonical hardlink target', $result['stderr']);
        self::assertStringNotContainsString($configMarker, $result['stdout']);
        self::assertStringNotContainsString($configMarker, $result['stderr']);
    }

    public function testArchiveCliRejectsAmbiguousHardlinkTargetName(): void
    {
        $configMarker = 'SENSITIVE_CONFIG_CONTENT_MUST_NOT_BE_PRINTED';
        $result = $this->runArchiveCliWithFakeListing(
            ['./application/config/config.php', './unlisted-config-alias.php'],
            [...$this->validArchiveTypeLines(), 'h unlisted-config-alias.php link to safe link to target'],
            $configMarker,
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('malformed or non-canonical hardlink target', $result['stderr']);
        self::assertStringNotContainsString($configMarker, $result['stdout']);
        self::assertStringNotContainsString($configMarker, $result['stderr']);
    }

    public function testArchiveCliRejectsCanonicalAliasAndDuplicateAncestorTogether(): void
    {
        $result = $this->runArchiveCliWithFakeListing(
            ['./application/config/config.php', './unlisted-config-alias.php'],
            [
                ...$this->validArchiveTypeLines(),
                'h unlisted-config-alias.php link to ./application/config/config.php',
                'd application',
            ],
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('application/config/config.php', $result['stderr']);
        self::assertStringContainsString('application', $result['stderr']);
        self::assertStringContainsString('hardlink aliases', $result['stderr']);
        self::assertStringContainsString('safe directory ancestors', $result['stderr']);
    }

    public function testArchiveCliAcceptsCanonicalHardlinkUnrelatedToRequiredPaths(): void
    {
        $workspace = sys_get_temp_dir() . '/release-artifact-archive-safe-hardlink-' . bin2hex(random_bytes(4));
        $root = $workspace . '/root';
        $archive = $workspace . '/release.tar.gz';
        mkdir($root, 0777, true);

        try {
            $this->createCompleteArtifactTree($root);
            file_put_contents($root . '/unlisted-original.txt', 'not a required file');
            link($root . '/unlisted-original.txt', $root . '/unlisted-alias.txt');
            $tar = $this->runCommand([
                'tar',
                '-czf',
                $archive,
                '-C',
                $root,
                ...$this->completeStaticRequiredPaths(),
                'unlisted-original.txt',
                'unlisted-alias.txt',
            ]);
            self::assertSame(0, $tar['exit_code'], $tar['stderr']);

            $result = $this->runCommand([
                'php',
                dirname(__DIR__, 3) . '/scripts/release-gate/validate_release_artifact.php',
                '--archive=' . $archive,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('Release artifact archive contains required files', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testArchiveCliRejectsDuplicateRequiredAncestor(): void
    {
        $workspace = sys_get_temp_dir() . '/release-artifact-archive-duplicate-parent-' . bin2hex(random_bytes(4));
        $root = $workspace . '/root';
        $archive = $workspace . '/release.tar.gz';
        mkdir($root, 0777, true);

        try {
            $this->createCompleteArtifactTree($root);
            $tar = $this->runCommand([
                'tar',
                '--no-recursion',
                '-czf',
                $archive,
                '-C',
                $root,
                'application',
                'application',
                ...$this->completeStaticRequiredPaths(),
            ]);
            self::assertSame(0, $tar['exit_code'], $tar['stderr']);

            $result = $this->runCommand([
                'php',
                dirname(__DIR__, 3) . '/scripts/release-gate/validate_release_artifact.php',
                '--archive=' . $archive,
            ]);

            self::assertNotSame(0, $result['exit_code']);
            self::assertStringContainsString('application', $result['stderr']);
            self::assertStringContainsString('safe directory ancestors', $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function createCompleteArtifactTree(string $root): void
    {
        foreach ($this->completeStaticRequiredPaths() as $requiredPath) {
            $absolutePath = $root . '/' . $requiredPath;
            $directory = dirname($absolutePath);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($absolutePath, 'ok');
        }
    }

    /**
     * @return list<string>
     */
    private function validArchiveTypeLines(): array
    {
        $requiredFiles = array_fill_keys($this->completeStaticRequiredPaths(), true);

        return array_map(
            static fn(string $path): string => (isset($requiredFiles[$path]) ? '-' : 'd') . ' ' . $path,
            ReleaseArtifactValidator::archiveTypePaths(),
        );
    }

    /**
     * @param list<string> $additionalEntries
     * @param list<string> $typeLines
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runArchiveCliWithFakeListing(
        array $additionalEntries,
        array $typeLines,
        string $archiveContents = 'archive fixture',
    ): array {
        $workspace = sys_get_temp_dir() . '/release-artifact-fake-tar-' . bin2hex(random_bytes(4));
        $binDirectory = $workspace . '/bin';
        $archive = $workspace . '/release.tar.gz';
        mkdir($binDirectory, 0777, true);

        try {
            file_put_contents($archive, $archiveContents);
            file_put_contents(
                $archive . '.entries',
                implode("\n", [...$this->completeStaticRequiredPaths(), ...$additionalEntries]) . "\n",
            );
            file_put_contents($archive . '.types', implode("\n", $typeLines) . "\n");
            file_put_contents(
                $binDirectory . '/tar',
                <<<'SH'
                #!/bin/sh
                case "$1" in
                    -tzf) /bin/cat "${2}.entries" ;;
                    -tvzf) /bin/cat "${2}.types" ;;
                    *) exit 64 ;;
                esac
                SH
                ,
            );
            chmod($binDirectory . '/tar', 0755);

            $environment = getenv();
            self::assertIsArray($environment);
            $environment['PATH'] = $binDirectory . PATH_SEPARATOR . ($environment['PATH'] ?? '');

            return $this->runCommand(
                [
                    'php',
                    dirname(__DIR__, 3) . '/scripts/release-gate/validate_release_artifact.php',
                    '--archive=' . $archive,
                ],
                $environment,
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * @return list<string>
     */
    private function completeStaticRequiredPaths(): array
    {
        return array_values(
            array_unique(array_merge(ReleaseArtifactValidator::requiredPaths(), ReleaseArtifactValidator::generatedVendorPaths())),
        );
    }

    /**
     * @param list<string> $command
     * @param array<string,string>|null $environment
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, ?array $environment = null): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 3), $environment);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

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

            if (is_link($childPath) || is_file($childPath)) {
                @unlink($childPath);
                continue;
            }

            if (is_dir($childPath)) {
                $this->removeDirectory($childPath);
                continue;
            }
        }

        @rmdir($path);
    }
}

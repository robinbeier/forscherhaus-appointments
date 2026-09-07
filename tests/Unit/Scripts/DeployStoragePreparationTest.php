<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeployStoragePreparationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testDeployScriptPreservesStorageSyncOrderingAndFailureGuard(): void
    {
        $source = (string) file_get_contents($this->root . '/deploy_ea.sh');
        self::assertStringContainsString('sync_live_storage_to_stage', $source);
        self::assertStringContainsString('sync_storage_payload ', $source);
        $sync = strrpos($source, "\nsync_live_storage_to_stage \\\n");
        $switch = strrpos($source, "\nperform_atomic_switch");
        self::assertIsInt($sync);
        self::assertIsInt($switch);
        self::assertLessThan($switch, $sync);
    }

    public function testStorageTransferCopiesSpecialNames(): void
    {
        if (trim((string) shell_exec('command -v rsync')) === '') {
            self::markTestSkipped('rsync is required for the storage-transfer regression.');
        }
        $workspace = sys_get_temp_dir() . '/storage-sync-' . bin2hex(random_bytes(6));
        $source = $workspace . '/source storage';
        $target = $workspace . '/target storage';
        mkdir($source, 0700, true);
        mkdir($target, 0700, true);
        file_put_contents($source . "/quote-' space.txt", 'abc');
        file_put_contents($source . "/line\nbreak.txt", 'payload');
        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                'set -Eeuo pipefail; source "$1"; DRYRUN=0; sync_storage_payload "$2" "$3"',
                'bash',
                $this->root . '/deploy_ea.sh',
                $source,
                $target,
            ]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame('abc', file_get_contents($target . "/quote-' space.txt"));
            self::assertSame('payload', file_get_contents($target . "/line\nbreak.txt"));
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testRsyncFailurePropagates(): void
    {
        $workspace = sys_get_temp_dir() . '/storage-sync-fail-' . bin2hex(random_bytes(6));
        $source = $workspace . '/source';
        $target = $workspace . '/target';
        $bin = $workspace . '/bin';
        mkdir($source, 0700, true);
        mkdir($target, 0700, true);
        mkdir($bin, 0700, true);
        file_put_contents($bin . '/rsync', "#!/usr/bin/env bash\nexit 23\n");
        chmod($bin . '/rsync', 0700);
        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                'set -Eeuo pipefail; source "$1"; PATH="$2:$PATH"; DRYRUN=0; sync_storage_payload "$3" "$4"; printf "SWITCH_REACHED\\n"',
                'bash',
                $this->root . '/deploy_ea.sh',
                $bin,
                $source,
                $target,
            ]);
            self::assertSame(23, $result['exit_code']);
            self::assertStringNotContainsString('SWITCH_REACHED', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testStorageTransferDryRunSkipsCopy(): void
    {
        $workspace = sys_get_temp_dir() . '/storage-sync-dry-' . bin2hex(random_bytes(6));
        $source = $workspace . '/source';
        $target = $workspace . '/target';
        mkdir($source, 0700, true);
        mkdir($target, 0700, true);
        file_put_contents($source . '/payload.txt', 'payload');
        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                'set -Eeuo pipefail; source "$1"; DRYRUN=1; sync_storage_payload "$2" "$3"',
                'bash',
                $this->root . '/deploy_ea.sh',
                $source,
                $target,
            ]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertFileDoesNotExist($target . '/payload.txt');
            self::assertStringContainsString('[DRY-RUN] rsync -a --', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testRealMissingStageRuntimeFailureIsReportedBeforePreSwitchAbort(): void
    {
        $workspace = sys_get_temp_dir() . '/rob456-missing-stage-SENSITIVE_MARKER-' . bin2hex(random_bytes(6));
        $missingStage = $workspace . '/missing stage';
        $secretCredentials = $workspace . '/secret credentials.ini';
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        REQUIRE_ZERO_SURPRISE=1
        DRYRUN=0
        STAGE_ROOT="$2"
        ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE="$3"
        WEBUSER=www-data
        prepare_predeploy_stage_permissions
        printf 'SWITCH_REACHED\n'
        BASH;

        $result = $this->runCommand([
            'bash',
            '-c',
            $script,
            'bash',
            dirname(__DIR__, 3) . '/deploy_ea.sh',
            $missingStage,
            $secretCredentials,
        ]);

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringNotContainsString('SWITCH_REACHED', $result['stdout']);
        self::assertStringNotContainsString($workspace, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('secret credentials.ini', $result['stdout'] . $result['stderr']);
    }

    public function testRealInvalidStageCredentialsFailureIsReportedBeforePreSwitchAbort(): void
    {
        $workspace = sys_get_temp_dir() . '/rob456-invalid-credentials-SENSITIVE_MARKER-' . bin2hex(random_bytes(6));
        $stage = $workspace . '/stage';
        $secretCredentials = $workspace . '/secret credentials.ini';
        self::assertTrue(mkdir($stage, 0700, true));
        self::assertNotFalse(file_put_contents($stage . '/config-sample.php', '<?php return [];'));
        self::assertNotFalse(file_put_contents($secretCredentials, "username=secret\n"));

        try {
            $result = $this->runRealStagePermissionsFailure($stage, $secretCredentials);

            $this->assertSanitizedStagePermissionsFailure($result, $workspace);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testRealStageConfigHelperFailureIsReportedBeforePreSwitchAbort(): void
    {
        $workspace = sys_get_temp_dir() . '/rob456-stage-helper-SENSITIVE_MARKER-' . bin2hex(random_bytes(6));
        $stage = $workspace . '/stage';
        $scripts = $stage . '/scripts/release-gate';
        $secretCredentials = $workspace . '/secret credentials.ini';
        self::assertTrue(mkdir($scripts, 0700, true));
        self::assertNotFalse(file_put_contents($stage . '/config-sample.php', '<?php return [];'));
        self::assertNotFalse(file_put_contents($secretCredentials, "base_url=https://secret.example.invalid\n"));
        self::assertNotFalse(
            file_put_contents(
                $scripts . '/prepare_zero_surprise_stage_config.php',
                "<?php fwrite(STDERR, 'SENSITIVE_MARKER /secret/path'); exit(23);\n",
            ),
        );

        try {
            $result = $this->runRealStagePermissionsFailure($stage, $secretCredentials);

            $this->assertSanitizedStagePermissionsFailure($result, $workspace);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testRealStageConfigCopyFailureIsReportedWithoutRawPathBeforePreSwitchAbort(): void
    {
        $workspace = sys_get_temp_dir() . '/rob456-stage-copy-SENSITIVE_MARKER-' . bin2hex(random_bytes(6));
        $stage = $workspace . '/stage';
        $secretCredentials = $workspace . '/secret credentials.ini';
        self::assertTrue(mkdir($stage, 0700, true));
        self::assertNotFalse(file_put_contents($stage . '/config-sample.php', '<?php return [];'));
        self::assertTrue(symlink($workspace . '/missing-parent/config.php', $stage . '/config.php'));
        self::assertNotFalse(file_put_contents($secretCredentials, "base_url=https://secret.example.invalid\n"));

        try {
            $result = $this->runRealStagePermissionsFailure($stage, $secretCredentials);

            $this->assertSanitizedStagePermissionsFailure($result, $workspace);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testRealStageLogDirectoryFailureIsReportedWithoutRawPathBeforePreSwitchAbort(): void
    {
        $workspace = sys_get_temp_dir() . '/rob456-stage-mkdir-SENSITIVE_MARKER-' . bin2hex(random_bytes(6));
        $stage = $workspace . '/stage';
        $secretCredentials = $workspace . '/secret credentials.ini';
        self::assertTrue(mkdir($stage, 0700, true));
        self::assertNotFalse(file_put_contents($stage . '/config-sample.php', '<?php return [];'));
        self::assertNotFalse(file_put_contents($stage . '/storage', 'not-a-directory'));
        self::assertNotFalse(file_put_contents($secretCredentials, "base_url=https://secret.example.invalid\n"));

        try {
            $result = $this->runRealStagePermissionsFailure($stage, $secretCredentials);

            $this->assertSanitizedStagePermissionsFailure($result, $workspace);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testDockerRendererKeepsHostPreparationOutOfDeploymentPath(): void
    {
        $source = (string) file_get_contents($this->root . '/deploy_ea.sh');
        self::assertStringNotContainsString('RENDERER_DEPLOY_MODE', $source);
        self::assertStringNotContainsString('RENDERER_STATE_DIR', $source);
        self::assertStringNotContainsString('prepare_renderer_state_dir', $source);
        self::assertStringNotContainsString('install_renderer_dependencies', $source);
        self::assertStringNotContainsString('require_command node', $source);
        self::assertStringNotContainsString('require_command npm', $source);
        self::assertStringNotContainsString('restart_renderer_service', $source);
        self::assertStringContainsString('probe_renderer_health', $source);
        self::assertStringContainsString('probe_deep_health_contract', $source);

        $sync = strpos($source, "\nsync_live_storage_to_stage \\\n");
        $permissions = strpos($source, "\nnormalize_stage_permissions \\\n");
        $switch = strpos($source, "\nperform_atomic_switch\n");
        self::assertIsInt($sync);
        self::assertIsInt($permissions);
        self::assertIsInt($switch);
        self::assertLessThan($switch, $permissions);
        self::assertLessThan($permissions, $sync);
    }

    private function runRealStagePermissionsFailure(string $stage, string $credentials): array
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        REQUIRE_ZERO_SURPRISE=1
        DRYRUN=0
        STAGE_ROOT="$2"
        ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE="$3"
        WEBUSER=www-data
        prepare_predeploy_stage_permissions
        printf 'SWITCH_REACHED\n'
        BASH;

        return $this->runCommand([
            'bash',
            '-c',
            $script,
            'bash',
            dirname(__DIR__, 3) . '/deploy_ea.sh',
            $stage,
            $credentials,
        ]);
    }

    private function assertSanitizedStagePermissionsFailure(array $result, string $workspace): void
    {
        self::assertNotSame(0, $result['exit_code']);
        self::assertStringNotContainsString('SWITCH_REACHED', $result['stdout']);
        self::assertStringNotContainsString($workspace, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('SENSITIVE_MARKER', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('secret credentials.ini', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('/secret/path', $result['stdout'] . $result['stderr']);
    }

    private function runCommand(array $command): array
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the root-controlled Kuma push runtime bundle.
 *
 * The executable checks deliberately use a root-prefix fixture. They exercise
 * the helper's trust and transaction boundaries without claiming that an
 * unprivileged test process can prove production uid/gid semantics.
 */
final class KumaPushRuntimeBundleTest extends TestCase
{
    private const MANIFEST = 'scripts/ops/config/kuma_push_runtime_bundle_v1.json';
    private const HELPER = 'scripts/ops/libexec/kuma_push_runtime_v1.py';
    private const CRON = '/etc/cron.d/fh-uptime-kuma-push';
    private const INSTALL_ROOT = '/usr/local/libexec/fh-kuma-push-runtime-v1';

    public function testManifestBindsTheCompleteClosureAndNineEntryPoints(): void
    {
        $manifest = $this->manifest();

        self::assertSame('fh_kuma_push_runtime_bundle.v1', $manifest['schema'] ?? null);
        self::assertSame('v1', $manifest['runtime'] ?? null);
        self::assertSame(self::INSTALL_ROOT, $manifest['install_root'] ?? null);
        self::assertSame(self::CRON, $manifest['cron_path'] ?? null);

        $files = $manifest['files'] ?? null;
        self::assertIsArray($files);
        self::assertCount(15, $files);

        $sourcePaths = [];
        foreach ($files as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('source', $entry);
            self::assertArrayHasKey('install', $entry);
            self::assertArrayHasKey('role', $entry);
            self::assertArrayHasKey('sha256', $entry);
            self::assertSame($entry['source'], $entry['install']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $entry['sha256']);
            $sourcePaths[] = $entry['source'];
            self::assertFileExists($this->repoRoot() . '/' . $entry['source']);
            self::assertSame(
                $entry['sha256'],
                hash_file('sha256', $this->repoRoot() . '/' . $entry['source']),
                'Manifest hash drift: ' . $entry['source'],
            );
        }

        $entryPoints = array_values(
            array_filter(
                $sourcePaths,
                static fn(string $path): bool => preg_match('#^scripts/ops/kuma_push_[^/]+\.sh$#', $path) === 1,
            ),
        );
        sort($entryPoints);
        self::assertCount(9, $entryPoints);
        self::assertCount(9, array_unique($entryPoints));
        self::assertContains('scripts/ops/kuma_push_pdf_export.sh', $entryPoints);
        self::assertContains('scripts/ops/lib/kuma_push_common.sh', $sourcePaths);
        self::assertContains('scripts/ops/lib/app_log_classification.sh', $sourcePaths);
        self::assertContains('scripts/release-gate/dashboard_release_gate.php', $sourcePaths);
        self::assertContains('scripts/release-gate/lib/GateAssertions.php', $sourcePaths);
        self::assertContains('scripts/release-gate/lib/GateCliSupport.php', $sourcePaths);
        self::assertContains('scripts/release-gate/lib/GateHttpClient.php', $sourcePaths);
    }

    public function testPdfEntryPointUsesOnlyBundledDashboardGate(): void
    {
        $script = file_get_contents($this->repoRoot() . '/scripts/ops/kuma_push_pdf_export.sh');
        self::assertIsString($script);
        self::assertStringContainsString('dashboard_release_gate.php', $script);
        self::assertStringNotContainsString('/var/www/html/easyappointments/scripts/release-gate', $script);
    }

    public function testProductionWrapperPlanAndCommitBindingDoNotContactSsh(): void
    {
        $fixture = $this->wrapperFixture();

        try {
            $plan = $this->runWrapper($fixture, []);
            self::assertSame(0, $plan['exit_code'], $plan['stderr']);
            self::assertStringContainsString('mode          : plan-only', $plan['stdout']);
            self::assertFileDoesNotExist($fixture['ssh_log']);

            $wrongCommit = $this->runWrapper($fixture, [
                '--execute',
                '--confirm-live-write',
                'ROB-489',
                '--expected-commit',
                str_repeat('b', 40),
            ]);
            self::assertNotSame(0, $wrongCommit['exit_code']);
            self::assertStringContainsString('does not match', $wrongCommit['stderr']);
            self::assertFileDoesNotExist($fixture['ssh_log']);
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testProductionWrapperExecutesPreflightAndCleanupInOrder(): void
    {
        $fixture = $this->wrapperFixture();

        try {
            $result = $this->runWrapper($fixture, [
                '--execute',
                '--confirm-live-write',
                'ROB-489',
                '--expected-commit',
                $fixture['commit'],
            ]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('"status":"pass"', $result['stdout']);
            self::assertStringContainsString('"mutation_performed":false', $result['stdout']);
            self::assertStringContainsString('"bundle_installed":true', $result['stdout']);
            self::assertSame(
                ['mktemp', 'tar', 'inspect', 'execute', 'cleanup'],
                file($fixture['ssh_log'], FILE_IGNORE_NEW_LINES),
            );
            self::assertFileDoesNotExist($fixture['stage_marker']);
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testProductionWrapperRetainsStageAfterReadOnlyPreflightFailure(): void
    {
        $fixture = $this->wrapperFixture(preflightFailure: true);

        try {
            $result = $this->runWrapper($fixture, [
                '--execute',
                '--confirm-live-write',
                'ROB-489',
                '--expected-commit',
                $fixture['commit'],
            ]);
            self::assertNotSame(0, $result['exit_code']);
            self::assertStringContainsString('remote preflight failed', $result['stderr']);
            self::assertSame(['mktemp', 'tar', 'inspect'], file($fixture['ssh_log'], FILE_IGNORE_NEW_LINES));
            self::assertFileExists($fixture['stage_marker']);
            self::assertStringNotContainsString('"bundle_installed":true', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testInstalledPdfRuntimeInvokesBundledGateWithSeparateAppRoot(): void
    {
        $fixture = $this->fixture();
        $workspace = $fixture['workspace'];
        $appRoot = $workspace . '/separate-app-root';
        $stubBin = $workspace . '/bin';
        $phpLog = $workspace . '/php-gate.log';
        $curlLog = $workspace . '/curl.log';
        $envFile = $workspace . '/uptime-kuma-push.env';
        $credentials = $workspace . '/release-gate-admin.env';

        try {
            $install = $this->runHelper($fixture['source'], $fixture['root'], true);
            self::assertSame(0, $install['exit_code'], $install['stderr']);
            mkdir($stubBin, 0755, true);
            mkdir($appRoot . '/storage/logs/ops', 0755, true);
            file_put_contents($credentials, "USERNAME='fixture-user'\nPASSWORD='fixture-password'\n");
            file_put_contents(
                $envFile,
                implode(PHP_EOL, [
                    'KUMA_PUSH_URL_PDF_EXPORT=https://kuma.example/push/pdf-export',
                    'KUMA_PDF_EXPORT_APP_ROOT=' . $appRoot,
                    'KUMA_PDF_EXPORT_CREDENTIALS_FILE=' . $credentials,
                    'KUMA_PUSH_ENV_FILE=' . $envFile,
                    '',
                ]),
            );
            $this->writePdfPhpStub($stubBin . '/php', $phpLog);
            $this->writeCurlStub($stubBin . '/curl', $curlLog);

            $installedPdf = $fixture['root'] . self::INSTALL_ROOT . '/scripts/ops/kuma_push_pdf_export.sh';
            $result = $this->runCommand(['bash', $installedPdf], $this->repoRoot(), [
                'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin'),
                'KUMA_PUSH_ENV_FILE' => $envFile,
            ]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $gateLog = file_get_contents($phpLog);
            self::assertIsString($gateLog);
            self::assertStringContainsString(
                $fixture['root'] . self::INSTALL_ROOT . '/scripts/release-gate/dashboard_release_gate.php',
                $gateLog,
            );
            self::assertStringContainsString('summary=-r', $gateLog);
            self::assertStringContainsString('RELEASE_GATE_REPO_ROOT=' . $appRoot, $gateLog);
            self::assertStringNotContainsString($fixture['source'] . '/scripts/release-gate', $gateLog);
            self::assertStringContainsString(
                'https://kuma.example/push/pdf-export',
                (string) file_get_contents($curlLog),
            );
            self::assertFileExists($appRoot . '/storage/logs/ops/kuma-pdf-export-latest.json');
            self::assertStringContainsString('OK dashboard_pdf_gate=all_checks_passed', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testDryRunReportsNineEntriesAndTenInvocationsWithoutMutation(): void
    {
        $fixture = $this->fixture();

        try {
            $before = $this->snapshot($fixture['root']);
            $result = $this->runHelper($fixture['source'], $fixture['root']);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $json = $this->jsonOutput($result['stdout']);
            self::assertSame('pass', $json['status'] ?? null);
            self::assertTrue($json['execution_ready'] ?? false);
            self::assertFalse($json['mutation_performed'] ?? true);
            self::assertSame(15, $json['bundle_files'] ?? null);
            self::assertSame(9, $json['entrypoints'] ?? null);
            self::assertSame(10, $json['cron_invocations'] ?? null);
            self::assertSame($before, $this->snapshot($fixture['root']));
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testConfirmedExecuteInstallsTrustedFilesAndMigratesOnlyLegacyCalls(): void
    {
        $fixture = $this->fixture();

        try {
            $result = $this->runHelper($fixture['source'], $fixture['root'], true);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $json = $this->jsonOutput($result['stdout']);
            self::assertSame('pass', $json['status'] ?? null);
            self::assertTrue($json['mutation_performed'] ?? false);

            $installed = $fixture['root'] . self::INSTALL_ROOT;
            foreach ($this->manifest()['files'] as $entry) {
                $path = $installed . '/' . $entry['install'];
                self::assertFileExists($path);
                self::assertFalse(is_link($path));
                self::assertSame(1, (int) (stat($path)['nlink'] ?? 0));
                self::assertSame('0555', substr(sprintf('%o', (int) fileperms($path)), -4));
                self::assertSame($entry['sha256'], hash_file('sha256', $path));
            }

            $cron = file_get_contents($fixture['root'] . self::CRON);
            self::assertIsString($cron);
            self::assertStringContainsString('# keep-this-byte', $cron);
            self::assertStringContainsString('KUMA_PUSH_ENV_FILE=/etc/fh/uptime-kuma-push.env', $cron);
            self::assertSame(10, substr_count($cron, self::INSTALL_ROOT . '/'));
            self::assertStringNotContainsString('/var/www/html/easyappointments/scripts/ops/kuma_push_', $cron);

            $recoveryRoot = $fixture['root'] . '/var/lib/fh-kuma-push-runtime-v1';
            $backupPath = $recoveryRoot . '/rob-489-cron.before';
            $recoveryPath = $recoveryRoot . '/rob-489-recovery.json';
            self::assertFileExists($backupPath);
            self::assertFileExists($recoveryPath);
            self::assertSame($fixture['legacy_cron'], file_get_contents($backupPath));

            $recovery = json_decode((string) file_get_contents($recoveryPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($recovery);
            self::assertSame('/etc/cron.d/fh-uptime-kuma-push', $recovery['cron_path'] ?? null);
            self::assertSame('ROB-489', $recovery['issue'] ?? null);
            self::assertSame('fh_kuma_push_runtime_recovery.v1', $recovery['schema'] ?? null);
            self::assertSame(hash('sha256', (string) $fixture['legacy_cron']), $recovery['original_sha256'] ?? null);
            self::assertSame(hash('sha256', (string) $cron), $recovery['desired_sha256'] ?? null);
            self::assertSame(self::INSTALL_ROOT, $recovery['runtime_root'] ?? null);

            $inspect = $this->runHelper($fixture['source'], $fixture['root']);
            self::assertSame(0, $inspect['exit_code'], $inspect['stderr']);
            $inspectJson = $this->jsonOutput($inspect['stdout']);
            self::assertSame('pass', $inspectJson['status'] ?? null);
            self::assertTrue($inspectJson['execution_ready'] ?? false);
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testHashDriftIsFailClosed(): void
    {
        $fixture = $this->fixture();

        try {
            file_put_contents($fixture['source'] . '/scripts/ops/kuma_push_host_resources.sh', "tampered\n");
            $result = $this->runHelper($fixture['source'], $fixture['root']);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('fail', $this->jsonOutput($result['stdout'])['status'] ?? null);
            self::assertFalse(is_dir($fixture['root'] . self::INSTALL_ROOT));
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testSymlinkSourceIsRejectedBeforeMutation(): void
    {
        $fixture = $this->fixture();

        try {
            $target = $fixture['source'] . '/scripts/ops/kuma_push_host_resources.sh';
            unlink($target);
            symlink('kuma_push_host_services.sh', $target);
            $result = $this->runHelper($fixture['source'], $fixture['root']);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('fail', $this->jsonOutput($result['stdout'])['status'] ?? null);
            self::assertFalse(is_dir($fixture['root'] . self::INSTALL_ROOT));
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testSourceHardlinkIsRejectedBeforeDryRun(): void
    {
        $fixture = $this->fixture();

        try {
            $source = $fixture['source'] . '/scripts/ops/kuma_push_host_resources.sh';
            $alias = $fixture['workspace'] . '/source-hardlink-alias';
            if (!link($source, $alias)) {
                self::markTestSkipped('Source hardlinks are unavailable on this filesystem.');
            }
            self::assertSame(2, (int) (stat($source)['nlink'] ?? 0));
            $result = $this->runHelper($fixture['source'], $fixture['root']);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('fail', $this->jsonOutput($result['stdout'])['status'] ?? null);
            self::assertFalse(is_dir($fixture['root'] . self::INSTALL_ROOT));
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testGroupOrWorldWritableSourceIsRejectedBeforeDryRun(): void
    {
        $fixture = $this->fixture();

        try {
            $source = $fixture['source'] . '/scripts/ops/kuma_push_host_resources.sh';
            chmod($source, 0666);
            self::assertSame('0666', substr(sprintf('%o', (int) fileperms($source)), -4));
            $result = $this->runHelper($fixture['source'], $fixture['root']);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('fail', $this->jsonOutput($result['stdout'])['status'] ?? null);
            self::assertFalse(is_dir($fixture['root'] . self::INSTALL_ROOT));
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testExternalHardlinkAfterInstallationBlocksFurtherInspection(): void
    {
        $fixture = $this->fixture();

        try {
            $install = $this->runHelper($fixture['source'], $fixture['root'], true);
            self::assertSame(0, $install['exit_code'], $install['stderr']);
            $installed = $fixture['root'] . self::INSTALL_ROOT . '/scripts/ops/kuma_push_host_resources.sh';
            $alias = $fixture['workspace'] . '/installed-hardlink-alias';
            if (!link($installed, $alias)) {
                self::markTestSkipped('Installed-file hardlinks are unavailable on this filesystem.');
            }
            self::assertSame(2, (int) (stat($installed)['nlink'] ?? 0));
            $cronPath = $fixture['root'] . self::CRON;
            $cronBefore = file_get_contents($cronPath);
            $result = $this->runHelper($fixture['source'], $fixture['root']);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('fail', $this->jsonOutput($result['stdout'])['status'] ?? null);
            self::assertSame($cronBefore, file_get_contents($cronPath));
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testAbnormalCronSetIsRejectedAndExistingTargetIsNotClobbered(): void
    {
        $fixture = $this->fixture();

        try {
            $cronPath = $fixture['root'] . self::CRON;
            file_put_contents(
                $cronPath,
                str_replace('kuma_push_app_logs.sh', 'kuma_push_unknown.sh', file_get_contents($cronPath)),
            );
            $result = $this->runHelper($fixture['source'], $fixture['root']);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('fail', $this->jsonOutput($result['stdout'])['status'] ?? null);

            $this->removeDirectory($fixture['workspace']);
            $fixture = $this->fixture();
            $target = $fixture['root'] . self::INSTALL_ROOT . '/scripts/ops/kuma_push_host_services.sh';
            mkdir(dirname($target), 0755, true);
            file_put_contents($target, 'pre-existing different target');
            $result = $this->runHelper($fixture['source'], $fixture['root'], true);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('fail', $this->jsonOutput($result['stdout'])['status'] ?? null);
            self::assertSame('pre-existing different target', file_get_contents($target));
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    public function testCronWriteFailureRollsBackInstalledBundleAndRedactsSensitiveValues(): void
    {
        $fixture = $this->fixture();

        try {
            $cronPath = $fixture['root'] . self::CRON;
            $beforeCron = file_get_contents($cronPath);
            self::assertIsString($beforeCron);
            $beforeCronHash = hash('sha256', $beforeCron);
            $result = $this->runHelper($fixture['source'], $fixture['root'], true, [
                'FH_KUMA_PUSH_RUNTIME_TEST_FAIL_AFTER_CRON_REPLACE' => '1',
            ]);
            self::assertNotSame(0, $result['exit_code']);
            $json = $this->jsonOutput($result['stdout']);
            self::assertSame('fail', $json['status'] ?? null);
            self::assertFalse(is_dir($fixture['root'] . self::INSTALL_ROOT));
            $afterCron = file_get_contents($cronPath);
            self::assertSame($beforeCron, $afterCron);
            self::assertSame($beforeCronHash, hash('sha256', (string) $afterCron));
            foreach (
                ['https://kuma.example/push/secret-monitor', 'super-secret-token', 'monitor-identity-42']
                as $secret
            ) {
                self::assertStringNotContainsString($secret, $result['stdout'] . $result['stderr']);
            }
        } finally {
            $this->removeDirectory($fixture['workspace']);
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents($this->repoRoot() . '/' . self::MANIFEST);
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /** @return array{workspace:string,source:string,root:string,legacy_cron:string} */
    private function fixture(): array
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-runtime-' . bin2hex(random_bytes(8));
        $source = $workspace . '/source';
        $root = $workspace . '/root';
        mkdir($source, 0755, true);
        mkdir($root . '/etc/cron.d', 0755, true);
        mkdir($root . '/usr/local/libexec', 0755, true);
        mkdir($root . '/var/lib', 0755, true);
        mkdir($root . '/run/lock', 0755, true);

        $manifest = $this->manifest();
        $manifestTarget = $source . '/' . self::MANIFEST;
        if (!is_dir(dirname($manifestTarget))) {
            self::assertTrue(mkdir(dirname($manifestTarget), 0755, true));
        }
        self::assertTrue(copy($this->repoRoot() . '/' . self::MANIFEST, $manifestTarget));

        $cronSource =
            $manifest['cron_source'] ?? ($manifest['cron_template'] ?? 'scripts/ops/uptime-kuma-crontab.example');
        self::assertIsString($cronSource);
        $cronSourceTarget = $source . '/' . $cronSource;
        if (!is_dir(dirname($cronSourceTarget))) {
            self::assertTrue(mkdir(dirname($cronSourceTarget), 0755, true));
        }
        self::assertTrue(copy($this->repoRoot() . '/' . $cronSource, $cronSourceTarget));

        foreach ($manifest['files'] as $entry) {
            $sourcePath = $this->repoRoot() . '/' . $entry['source'];
            $targetPath = $source . '/' . $entry['source'];
            if (!is_dir(dirname($targetPath))) {
                self::assertTrue(mkdir(dirname($targetPath), 0755, true));
            }
            self::assertTrue(copy($sourcePath, $targetPath), 'Unable to copy ' . $entry['source']);
        }
        $helperTarget = $source . '/' . self::HELPER;
        if (!is_dir(dirname($helperTarget))) {
            self::assertTrue(mkdir(dirname($helperTarget), 0755, true));
        }
        self::assertTrue(copy($this->repoRoot() . '/' . self::HELPER, $helperTarget));

        $lines = [
            '# keep-this-byte',
            'SHELL=/bin/sh',
            'KUMA_PUSH_ENV_FILE=/etc/fh/uptime-kuma-push.env',
            '* * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_host_services.sh',
            '* * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_host_resources.sh',
            '*/15 * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_ops_jobs.sh',
            '*/15 * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_backup_creation.sh',
            '* * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_app_logs.sh',
            '* * * * * sleep 30; /var/www/html/easyappointments/scripts/ops/kuma_push_app_logs.sh',
            '* * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_php_fpm_logs.sh',
            '* * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_pdf_renderer_logs.sh',
            '*/15 * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_pdf_export.sh',
            '* * * * * /var/www/html/easyappointments/scripts/ops/kuma_push_apache_scanner_activity.sh',
            '',
        ];
        $legacyCron = implode(PHP_EOL, $lines);
        file_put_contents($root . self::CRON, $legacyCron);

        return [
            'workspace' => $workspace,
            'source' => $source,
            'root' => $root,
            'legacy_cron' => $legacyCron,
        ];
    }

    /** @return array<string, mixed> */
    private function wrapperFixture(bool $preflightFailure = false): array
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-wrapper-' . bin2hex(random_bytes(8));
        $bin = $workspace . '/bin';
        $sshLog = $workspace . '/ssh.log';
        $stageMarker = $workspace . '/stage-retained';
        $stageRoot = $workspace . '/stage';
        $commit = str_repeat('a', 40);
        mkdir($bin, 0755, true);
        file_put_contents($bin . '/uname', "#!/bin/sh\nprintf '%s\\n' Linux\n");
        file_put_contents(
            $bin . '/git',
            <<<'SH'
            #!/bin/sh
            case "$*" in
              *"rev-parse HEAD"*) printf '%s\n' "$FH_WRAPPER_COMMIT" ;;
              *) exit 0 ;;
            esac
            SH
            ,
        );
        file_put_contents(
            $bin . '/ssh',
            <<<'SH'
            #!/bin/sh
            set -eu
            last=''
            for arg in "$@"; do last="$arg"; done
            stage_root="$FH_WRAPPER_STAGE_ROOT"
            if printf '%s\n' "$last" | grep -q 'mktemp'; then
              mkdir -p "$stage_root"
              : > "$FH_WRAPPER_STAGE_MARKER"
              printf '%s\n' mktemp >> "$FH_WRAPPER_SSH_LOG"
              printf '%s\n' '/root/.fh-kuma-push-runtime-v1.ABCDEFGH'
              exit 0
            fi
            if printf '%s\n' "$last" | grep -q 'tar --no-same-owner'; then
              printf '%s\n' tar >> "$FH_WRAPPER_SSH_LOG"
              tar -xf - -C "$stage_root"
              exit 0
            fi
            if printf '%s\n' "$last" | grep -q -- '--source-root'; then
              if printf '%s\n' "$last" | grep -q -- '--execute'; then
                printf '%s\n' execute >> "$FH_WRAPPER_SSH_LOG"
                printf '%s\n' '{"status":"pass","execution_ready":true,"bundle_installed":true,"cron_state":"installed"}'
              else
                printf '%s\n' inspect >> "$FH_WRAPPER_SSH_LOG"
                if [ "${FH_WRAPPER_PREFLIGHT_FAILURE:-0}" = 1 ]; then
                  printf '%s\n' '{"status":"fail","execution_ready":false}'
                  exit 23
                fi
                printf '%s\n' '{"status":"pass","execution_ready":true,"mutation_performed":false}'
              fi
              exit 0
            fi
            if printf '%s\n' "$last" | grep -q 'rm -rf'; then
              printf '%s\n' cleanup >> "$FH_WRAPPER_SSH_LOG"
              rm -rf "$stage_root" "$FH_WRAPPER_STAGE_MARKER"
              exit 0
            fi
            exit 70
            SH
            ,
        );
        chmod($bin . '/uname', 0755);
        chmod($bin . '/git', 0755);
        chmod($bin . '/ssh', 0755);

        return [
            'workspace' => $workspace,
            'bin' => $bin,
            'ssh_log' => $sshLog,
            'stage_marker' => $stageMarker,
            'stage_root' => $stageRoot,
            'commit' => $commit,
            'preflight_failure' => $preflightFailure,
        ];
    }

    /** @param array<string, mixed> $fixture @param list<string> $arguments */
    private function runWrapper(array $fixture, array $arguments): array
    {
        return $this->runCommand(
            array_merge(['bash', $this->repoRoot() . '/scripts/ops/prod_kuma_push_runtime_v1.sh'], $arguments),
            $this->repoRoot(),
            [
                'PATH' => $fixture['bin'] . PATH_SEPARATOR . dirname(PHP_BINARY) . PATH_SEPARATOR . '/usr/bin:/bin',
                'FH_WRAPPER_COMMIT' => $fixture['commit'],
                'FH_WRAPPER_STAGE_ROOT' => $fixture['stage_root'],
                'FH_WRAPPER_STAGE_MARKER' => $fixture['stage_marker'],
                'FH_WRAPPER_SSH_LOG' => $fixture['ssh_log'],
                'FH_WRAPPER_PREFLIGHT_FAILURE' => $fixture['preflight_failure'] ? '1' : '0',
            ],
        );
    }

    private function writePdfPhpStub(string $path, string $logPath): void
    {
        $script =
            "#!/bin/sh\nset -eu\n" .
            "if [ \"\${1:-}\" = '-r' ]; then printf 'summary=-r\\n' >> " .
            escapeshellarg($logPath) .
            '; exec ' .
            escapeshellarg(PHP_BINARY) .
            " \"\$@\"; fi\n" .
            "printf 'gate=%s RELEASE_GATE_REPO_ROOT=%s\\n' \"\$1\" \"\${RELEASE_GATE_REPO_ROOT:-}\" >> " .
            escapeshellarg($logPath) .
            "\n" .
            "output=''\n" .
            "for arg in \"\$@\"; do case \"\$arg\" in --output-json=*) output=\"\${arg#--output-json=}\" ;; esac; done\n" .
            "[ -n \"\$output\" ]\n" .
            "printf '%s\\n' '{\"checks\":[{\"name\":\"fixture_gate\",\"status\":\"pass\"}]}' > \"\$output\"\n";
        file_put_contents($path, $script);
        chmod($path, 0755);
    }

    private function writeCurlStub(string $path, string $logPath): void
    {
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($logPath) . "\n");
        chmod($path, 0755);
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    /** @param array<string, string> $env */
    private function runHelper(string $source, string $root, bool $execute = false, array $env = []): array
    {
        $command = [
            'python3',
            '-I',
            '-B',
            $source . '/' . self::HELPER,
            '--source-root',
            $source,
            '--root-prefix',
            $root,
        ];
        if ($execute) {
            $command[] = '--execute';
            $command[] = '--confirm-live-write';
            $command[] = 'ROB-489';
        }

        return $this->runCommand($command, $this->repoRoot(), $env);
    }

    /** @return array<string, mixed> */
    private function jsonOutput(string $stdout): array
    {
        $json = json_decode(trim($stdout), true);
        self::assertIsArray($json, 'Helper output must be one JSON object: ' . $stdout);

        return $json;
    }

    /** @return array<string, string> */
    private function snapshot(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($root));
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    /** @param list<string> $command @param array<string, string> $env */
    private function runCommand(array $command, string $cwd, array $env = []): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge($_ENV, $env),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}

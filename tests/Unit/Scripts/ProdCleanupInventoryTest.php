<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProdCleanupInventoryTest extends TestCase
{
    public function testInventoryAggregatesCleanupFactsWithoutDiscoveredFilenames(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-cleanup-inventory-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $webRoot = $workspace . '/web';
        $appRoot = $webRoot . '/easyappointments';
        $releasesDir = $workspace . '/releases';
        $backupDir = $workspace . '/backups/easyappointments';
        $restoreInputsDir = $workspace . '/rebuild-restore-inputs';

        mkdir($stubBin, 0777, true);
        mkdir($appRoot . '/storage/sessions', 0777, true);
        mkdir($appRoot . '/storage/cache', 0777, true);
        mkdir($appRoot . '/storage/logs', 0777, true);
        mkdir($appRoot . '/storage/uploads', 0777, true);
        mkdir($webRoot . '/easyappointments_prev_sensitive_old_release', 0777, true);
        mkdir($webRoot . '/easyappointments_prev_current_rollback', 0777, true);
        mkdir($webRoot . '/easyappointments_failed_sensitive_failed_release', 0777, true);
        mkdir($webRoot . '/easyappointments_ea_sensitive_stage_stage', 0777, true);
        mkdir($releasesDir, 0777, true);
        mkdir($backupDir . '/daily', 0777, true);
        mkdir($restoreInputsDir, 0777, true);

        try {
            $this->writeSshStub($stubBin);
            file_put_contents($appRoot . '/_RELEASE', "ea_current 2026-06-04T15:23:36Z\n");
            file_put_contents($appRoot . '/storage/sessions/sess_secret_name', 'session payload');
            file_put_contents($appRoot . '/storage/cache/cache_secret_name', 'cache payload');
            file_put_contents($appRoot . '/storage/logs/log-secret-name.php', 'ERROR - secret raw log');
            file_put_contents($appRoot . '/storage/uploads/upload-secret-name.pdf', 'upload payload');
            file_put_contents($releasesDir . '/ea_sensitive_archive.tar.gz', 'archive payload');
            file_put_contents($releasesDir . '/ea_other_sensitive_archive.tar.gz', 'archive payload');
            file_put_contents($backupDir . '/daily/easyappointments-sensitive-dump.sql.gz', 'dump payload');
            file_put_contents($backupDir . '/last_backup_success.utc', "2026-06-04T02:17:01Z\n");
            file_put_contents($backupDir . '/last_verify_success.utc', "2026-06-04T03:17:01Z\n");
            file_put_contents($restoreInputsDir . '/sensitive-restore-input.tar.gz', 'restore payload');

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_cleanup_inventory.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                [
                    'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'CLEANUP_WEB_ROOT' => $webRoot,
                    'CLEANUP_APP_ROOT' => $appRoot,
                    'CLEANUP_RELEASES_DIR' => $releasesDir,
                    'CLEANUP_BACKUP_DIR' => $backupDir,
                    'CLEANUP_REBUILD_RESTORE_INPUTS_DIR' => $restoreInputsDir,
                ],
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('inventory_mode=read_only', $result['stdout']);
            self::assertStringContainsString('deletion_performed=no', $result['stdout']);
            self::assertStringContainsString('current_release=ea_current', $result['stdout']);
            self::assertStringContainsString('release_dirs.prev.count=2', $result['stdout']);
            self::assertStringContainsString('release_dirs.stage.count=1', $result['stdout']);
            self::assertStringContainsString('release_dirs.failed.count=1', $result['stdout']);
            self::assertStringContainsString('release_archives.count=2', $result['stdout']);
            self::assertStringContainsString('backup_dumps.count=1', $result['stdout']);
            self::assertStringContainsString('dump_producer_admission.status=unavailable', $result['stdout']);
            self::assertStringContainsString(
                'dump_producer_admission.contract=registry_manifest_required',
                $result['stdout'],
            );
            self::assertStringContainsString('restore_inputs.file_count_class=1-100', $result['stdout']);
            self::assertStringContainsString('sessions.file_count_class=1-100', $result['stdout']);
            self::assertStringContainsString('cleanup_candidate.prev_release_dirs=needs_review', $result['stdout']);
            self::assertStringContainsString('cleanup_candidate.stage_dirs=safe_candidate', $result['stdout']);
            self::assertStringContainsString('cleanup_candidate.failed_dirs=safe_candidate', $result['stdout']);
            self::assertStringContainsString('cleanup_requires_live_write_gate=yes', $result['stdout']);

            $combinedOutput = $result['stdout'] . $result['stderr'];
            self::assertStringNotContainsString('sess_secret_name', $combinedOutput);
            self::assertStringNotContainsString('cache_secret_name', $combinedOutput);
            self::assertStringNotContainsString('log-secret-name.php', $combinedOutput);
            self::assertStringNotContainsString('upload-secret-name.pdf', $combinedOutput);
            self::assertStringNotContainsString('ea_sensitive_archive.tar.gz', $combinedOutput);
            self::assertStringNotContainsString('easyappointments-sensitive-dump.sql.gz', $combinedOutput);
            self::assertStringNotContainsString('sensitive-restore-input.tar.gz', $combinedOutput);
            self::assertStringNotContainsString('secret raw log', $combinedOutput);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testInventoryReportsMissingRollbackWhenNoPreviousReleaseExists(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-cleanup-inventory-no-rollback-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $webRoot = $workspace . '/web';
        $appRoot = $webRoot . '/easyappointments';

        mkdir($stubBin, 0777, true);
        mkdir($appRoot, 0777, true);

        try {
            $this->writeSshStub($stubBin);
            file_put_contents($appRoot . '/_RELEASE', "ea_current 2026-06-04T15:23:36Z\n");

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_cleanup_inventory.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                [
                    'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'CLEANUP_WEB_ROOT' => $webRoot,
                    'CLEANUP_APP_ROOT' => $appRoot,
                    'CLEANUP_RELEASES_DIR' => $workspace . '/releases',
                    'CLEANUP_BACKUP_DIR' => $workspace . '/backups/easyappointments',
                    'CLEANUP_REBUILD_RESTORE_INPUTS_DIR' => $workspace . '/rebuild-restore-inputs',
                ],
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('release_dirs.prev.count=0', $result['stdout']);
            self::assertStringContainsString(
                'cleanup_candidate.prev_release_dirs=missing_rollback_directory',
                $result['stdout'],
            );
            self::assertStringNotContainsString(
                'cleanup_candidate.prev_release_dirs=keep_current_rollback',
                $result['stdout'],
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testInventoryMapsEveryAdmissionExitClassWithoutForwardingHelperOutput(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-cleanup-inventory-admission-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $webRoot = $workspace . '/web';
        $appRoot = $webRoot . '/easyappointments';
        $helper = $workspace . '/release-retention-helper';

        mkdir($stubBin, 0777, true);
        mkdir($appRoot, 0777, true);

        try {
            $this->writeSshStub($stubBin);
            file_put_contents($appRoot . '/_RELEASE', "ea_current 2026-06-04T15:23:36Z\n");

            foreach ([0 => 'pass', 70 => 'blocked', 75 => 'retryable', 64 => 'invalid'] as $exit => $status) {
                file_put_contents(
                    $helper,
                    "#!/usr/bin/env bash\nprintf 'sensitive-helper-output\\n'\nprintf 'sensitive-helper-error\\n' >&2\nexit {$exit}\n",
                );
                chmod($helper, 0755);

                $result = $this->runCommand(
                    ['bash', 'scripts/ops/prod_cleanup_inventory.sh', '--prod-ssh-target', 'prod.example'],
                    $this->repoRoot(),
                    [
                        'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                        'CLEANUP_WEB_ROOT' => $webRoot,
                        'CLEANUP_APP_ROOT' => $appRoot,
                        'CLEANUP_RELEASES_DIR' => $workspace . '/releases',
                        'CLEANUP_BACKUP_DIR' => $workspace . '/backups/easyappointments',
                        'CLEANUP_REBUILD_RESTORE_INPUTS_DIR' => $workspace . '/rebuild-restore-inputs',
                        'CLEANUP_RELEASE_RETENTION_HELPER' => $helper,
                    ],
                );

                self::assertSame(0, $result['exit_code'], $result['stderr']);
                self::assertStringContainsString('dump_producer_admission.status=' . $status, $result['stdout']);
                self::assertStringNotContainsString('sensitive-helper', $result['stdout'] . $result['stderr']);
            }
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function writeSshStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            remote_cmd=''
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o)
                        shift 2
                        ;;
                    *)
                        remote_cmd="$1"
                        shift
                        ;;
                esac
            done

            if [[ -z "$remote_cmd" ]]; then
                remote_cmd='bash -s'
            fi

            bash -c "$remote_cmd"
            BASH
            ,
        );
        chmod($stubBin . '/ssh', 0755);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd, array $env = []): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, array_merge($_ENV, $env));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

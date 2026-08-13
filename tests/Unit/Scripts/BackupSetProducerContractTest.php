<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BackupSetProducerContractTest extends TestCase
{
    private string $root;
    private string $helper;
    private string $wrapper;
    private string $attestationWrapper;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $this->helper = (string) file_get_contents($this->root . '/scripts/ops/libexec/backup_set_producer_v1.py');
        $this->wrapper = (string) file_get_contents($this->root . '/scripts/ops/prod_backup_set_producer.sh');
        $this->attestationWrapper = (string) file_get_contents(
            $this->root . '/scripts/ops/prod_verify_latest_deployment_dump.sh',
        );
    }

    public function testHelperHasNoCallerAuthorityAndFreezesDumpInputs(): void
    {
        self::assertStringContainsString("BACKUP_ROOT = '/root/backups/easyappointments'", $this->helper);
        self::assertStringContainsString("CREDENTIALS = '/etc/fh/backup-set-producer.cnf'", $this->helper);
        self::assertStringContainsString("MARIADB_DUMP = '/usr/bin/mariadb-dump'", $this->helper);
        self::assertStringContainsString("DATABASE = 'easyappointments'", $this->helper);
        self::assertStringContainsString("user=fh_backup\\n", $this->helper);
        self::assertStringContainsString("host=127.0.0.1\\n", $this->helper);
        self::assertStringContainsString('if len(sys.argv) != 1', $this->helper);
        self::assertStringContainsString('resource.setrlimit(resource.RLIMIT_CORE, (0, 0))', $this->helper);
        self::assertStringNotContainsString('os.environ.get', $this->helper);
        self::assertStringNotContainsString("'mysqldump'", $this->helper);
        $activity = substr(
            $this->helper,
            (int) strpos($this->helper, 'def activity_count():'),
            (int) strpos($this->helper, 'def assert_activity_gate') -
                (int) strpos($this->helper, 'def activity_count():'),
        );
        self::assertStringNotContainsString('backup_set_producer_v1', $activity);
        self::assertStringContainsString("'--single-transaction'", $this->helper);
        self::assertStringContainsString("'--quick'", $this->helper);
        self::assertStringContainsString("'--skip-triggers'", $this->helper);
        self::assertStringContainsString("'--skip-routines'", $this->helper);
        self::assertStringContainsString("'--skip-events'", $this->helper);
        self::assertStringContainsString("'--no-tablespaces'", $this->helper);
        self::assertStringNotContainsString("'--databases'", $this->helper);
    }

    public function testHelperFreezesLocksDurabilityAndAggregateOutput(): void
    {
        foreach (
            [
                'fh-production-change.lock',
                '.backup-set-producer.lock',
                'LOCK_EX | fcntl.LOCK_NB',
                'os.O_NOFOLLOW',
                'os.O_NONBLOCK',
                'os.fsync',
                'renameat2',
                'RENAME_NOREPLACE',
                'last_backup_success.utc',
                'easyappointments.sql.gz',
                'backup.env',
                'MAX_COMPRESSED',
            ]
            as $needle
        ) {
            self::assertStringContainsString($needle, $this->helper);
        }
        self::assertStringContainsString("SCHEMA = 'production_backup_set_result.v1'", $this->helper);
        self::assertStringNotContainsString("'set_id':", $this->helper);
        self::assertStringNotContainsString("'path':", $this->helper);
        self::assertStringNotContainsString("'sha256':", $this->helper);
    }

    public function testEveryRetentionAndRestoreClassifierRecognizesProducerActivity(): void
    {
        foreach (
            [
                'scripts/ops/libexec/deployment_dump_attestation_v1.py',
                'scripts/ops/libexec/release_archive_dump_retention_v1.py',
                'scripts/ops/libexec/session_retention_v1.py',
                'scripts/ops/libexec/zero_surprise_image_cleanup_v1.py',
                'scripts/ops/prod_build_cache_retention.sh',
            ]
            as $path
        ) {
            $bytes = (string) file_get_contents($this->root . '/' . $path);
            self::assertStringContainsString('backup_set_producer_v1', $bytes, $path);
            self::assertStringContainsString('prod_backup_set_producer', $bytes, $path);
        }
    }

    public function testWrapperDoesNotInvokeSshWithoutExactLiveConfirmation(): void
    {
        $workspace = sys_get_temp_dir() . '/rob466-wrapper-' . bin2hex(random_bytes(8));
        mkdir($workspace . '/bin', 0777, true);
        $log = $workspace . '/ssh.log';
        file_put_contents(
            $workspace . '/bin/ssh',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\n",
        );
        chmod($workspace . '/bin/ssh', 0755);
        try {
            $default = $this->runWrapper([], $workspace . '/bin');
            self::assertSame(0, $default['exit'], $default['stderr']);
            self::assertStringContainsString('mode       : plan-only', $default['stdout']);
            self::assertFileDoesNotExist($log);

            foreach (
                [['--execute'], ['--execute', '--confirm-live-write', 'ROB-465'], ['--confirm-live-write', 'ROB-466']]
                as $arguments
            ) {
                self::assertSame(1, $this->runWrapper($arguments, $workspace . '/bin')['exit']);
                self::assertFileDoesNotExist($log);
            }

            $execute = $this->runWrapper(['--execute', '--confirm-live-write', 'ROB-466'], $workspace . '/bin');
            self::assertSame(0, $execute['exit'], $execute['stderr']);
            self::assertStringContainsString(
                '/usr/bin/python3 -I -B /usr/local/libexec/fh-backup-set-producer-v1',
                (string) file_get_contents($log),
            );
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testProtectedHandoffAttestationWrapperHasIndependentLiveConfirmation(): void
    {
        $workspace = sys_get_temp_dir() . '/rob466-attestation-wrapper-' . bin2hex(random_bytes(8));
        mkdir($workspace . '/bin', 0777, true);
        $log = $workspace . '/ssh.log';
        file_put_contents(
            $workspace . '/bin/ssh',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\n",
        );
        chmod($workspace . '/bin/ssh', 0755);
        try {
            $default = $this->runAttestationWrapper([], $workspace . '/bin');
            self::assertSame(0, $default['exit'], $default['stderr']);
            self::assertStringContainsString('mode       : plan-only', $default['stdout']);
            self::assertFileDoesNotExist($log);

            foreach (
                [
                    ['--execute'],
                    ['--execute', '--confirm-live-restore', 'ROB-466'],
                    ['--confirm-live-restore', 'ROB-461'],
                ] as $arguments
            ) {
                self::assertSame(1, $this->runAttestationWrapper($arguments, $workspace . '/bin')['exit']);
                self::assertFileDoesNotExist($log);
            }

            $execute = $this->runAttestationWrapper(
                ['--execute', '--confirm-live-restore', 'ROB-461'],
                $workspace . '/bin',
            );
            self::assertSame(0, $execute['exit'], $execute['stderr']);
            self::assertSame(
                '-o StrictHostKeyChecking=accept-new prod.example ' .
                '/usr/bin/php -n /usr/local/libexec/fh/verify_deployment_dump_v1.php --latest-handoff' . "\n",
                (string) file_get_contents($log),
            );
            self::assertStringNotContainsString('backup_set_id', $execute['stdout'] . $execute['stderr']);
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testRepositoryShipsNoAutonomousActivationPath(): void
    {
        $docs = (string) file_get_contents($this->root . '/docs/ops/production-backup-set-producer.md');
        self::assertFileDoesNotExist($this->root . '/scripts/ops/systemd/fh-backup-set-producer.service');
        self::assertFileDoesNotExist($this->root . '/scripts/ops/systemd/fh-backup-set-producer.timer');
        self::assertStringNotContainsString('systemctl enable', $this->wrapper . $docs);
        self::assertStringNotContainsString('systemctl start', $this->wrapper . $docs);
        self::assertStringNotContainsString('systemctl enable', $this->attestationWrapper);
        self::assertStringNotContainsString('systemctl start', $this->attestationWrapper);
        self::assertStringContainsString('manual-only', $docs);
    }

    /** @param list<string> $arguments @return array{exit:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments, string $stubBin): array
    {
        $process = proc_open(
            array_merge(
                ['bash', 'scripts/ops/prod_backup_set_producer.sh', '--prod-ssh-target', 'prod.example'],
                $arguments,
            ),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root,
            array_merge($_ENV, ['PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: '')]),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }

    /** @param list<string> $arguments @return array{exit:int,stdout:string,stderr:string} */
    private function runAttestationWrapper(array $arguments, string $stubBin): array
    {
        $process = proc_open(
            array_merge(
                ['bash', 'scripts/ops/prod_verify_latest_deployment_dump.sh', '--prod-ssh-target', 'prod.example'],
                $arguments,
            ),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root,
            array_merge($_ENV, ['PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: '')]),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BackupSetProducerContractTest extends TestCase
{
    private string $root;
    private string $helper;
    private string $attestationHelper;
    private string $wrapper;
    private string $attestationWrapper;
    private string $producerUnit;
    private string $timerUnit;
    private string $restoreUnit;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $this->helper = (string) file_get_contents($this->root . '/scripts/ops/libexec/backup_set_producer_v1.py');
        $this->attestationHelper = (string) file_get_contents(
            $this->root . '/scripts/ops/libexec/deployment_dump_attestation_v1.py',
        );
        $this->wrapper = (string) file_get_contents($this->root . '/scripts/ops/prod_backup_set_producer.sh');
        $this->attestationWrapper = (string) file_get_contents(
            $this->root . '/scripts/ops/prod_verify_latest_deployment_dump.sh',
        );
        $this->producerUnit = (string) file_get_contents(
            $this->root . '/scripts/ops/systemd/fh-backup-set-producer.service',
        );
        $this->timerUnit = (string) file_get_contents(
            $this->root . '/scripts/ops/systemd/fh-backup-set-continuity.timer',
        );
        $this->restoreUnit = (string) file_get_contents(
            $this->root . '/scripts/ops/systemd/fh-backup-set-restore-verify.service',
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
        self::assertStringContainsString("'--no-autocommit'", $this->helper);
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

    public function testEachPathCapturesItsClockOnlyAfterPotentiallyBlockingValidation(): void
    {
        $main = substr($this->helper, (int) strpos($this->helper, 'def main():'));
        $attach = substr(
            $this->helper,
            (int) strpos($this->helper, 'def attach_unmarked_set'),
            (int) strpos($this->helper, 'def main():') - (int) strpos($this->helper, 'def attach_unmarked_set'),
        );
        $privateLock = strpos($main, 'private_lock = open_lock');
        $activityGate = strpos($main, 'assert_activity_gate(orchestrator)');
        $reconcile = strpos($main, 'reconcile_temporary_files(backups)');
        $marker = strpos($main, 'expected_marker = stable_marker(backups)');
        $attachCall = strpos($main, 'attach_unmarked_set(backups, expected_marker, expected_state, nonce)');
        $freshClock = strpos($main, 'observed_at = utc_now()');
        $validated = strpos($attach, 'validate_backup_set(backups, backup_id)');
        $recoveryClock = strpos($attach, 'observed_at = utc_now()');
        $freshness = strpos($attach, 'require_recoverable_candidate_fresh(backup_id, observed_at)');
        $handoff = strpos($attach, 'publish_handoff(backups, backup_id');

        foreach (
            [
                $privateLock,
                $activityGate,
                $reconcile,
                $marker,
                $attachCall,
                $freshClock,
                $validated,
                $recoveryClock,
                $freshness,
                $handoff,
            ]
            as $position
        ) {
            self::assertIsInt($position);
        }
        self::assertLessThan($activityGate, $privateLock);
        self::assertLessThan($reconcile, $activityGate);
        self::assertLessThan($marker, $reconcile);
        self::assertLessThan($attachCall, $marker);
        self::assertLessThan($freshClock, $attachCall);
        self::assertLessThan($recoveryClock, $validated);
        self::assertLessThan($freshness, $recoveryClock);
        self::assertLessThan($handoff, $freshness);
        self::assertSame(1, substr_count($main, 'observed_at = utc_now()'));
        self::assertSame(1, substr_count($attach, 'observed_at = utc_now()'));
        self::assertStringContainsString("backup_id = observed_at.strftime('%Y%m%dT%H%M%SZ')", $main);
        self::assertStringContainsString('mtime=gzip_mtime', $this->helper);
        self::assertStringContainsString('observed_gzip_mtime != expected_gzip_mtime', $this->helper);
    }

    public function testDocumentationFreezesManualTcpOnlyOperation(): void
    {
        $docs = (string) file_get_contents($this->root . '/docs/ops/production-backup-set-producer.md');

        self::assertStringContainsString('127.0.0.1:3306', $docs);
        self::assertStringContainsString('Unix-domain socket is not an', $docs);
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

    public function testEveryBackupSensitiveClassifierRecognizesLegacyCutoverWriters(): void
    {
        $paths = [
            'scripts/ops/libexec/backup_set_producer_v1.py',
            'scripts/ops/libexec/deployment_dump_attestation_v1.py',
            'scripts/ops/libexec/release_archive_dump_retention_v1.py',
            'scripts/ops/libexec/session_retention_v1.py',
            'scripts/ops/libexec/zero_surprise_image_cleanup_v1.py',
            'scripts/ops/prod_build_cache_retention.sh',
        ];
        $program = <<<'PY'
        import ast
        import pathlib
        import re
        import sys

        positives = (
            '/usr/local/bin/backup_ea.sh --scheduled',
            '/usr/local/bin/ea_restore_verify_latest.sh --scheduled',
        )
        negatives = (
            '/usr/local/bin/not_backup_ea.sh --scheduled',
            '/usr/local/bin/backup_ea.sh.disabled --scheduled',
            '/usr/local/bin/not_ea_restore_verify_latest.sh --scheduled',
            '/usr/local/bin/ea_restore_verify_latest.sh.disabled --scheduled',
        )
        for name in sys.argv[1:]:
            text = pathlib.Path(name).read_text(encoding='utf-8')
            literals = re.findall(r"re\.compile\((r(?:'[^'\n]*'|\"[^\"\n]*\"))\)", text)
            patterns = tuple(
                re.compile(ast.literal_eval(value))
                for value in literals
                if 'backup_ea' in value or 'ea_restore_verify_latest' in value
            )
            if not patterns:
                raise SystemExit(f'{name}: no compiled patterns found')
            for command in positives:
                if not any(pattern.search(command) for pattern in patterns):
                    raise SystemExit(f'{name}: did not classify {command}')
            for command in negatives:
                if any(pattern.search(command) for pattern in patterns):
                    raise SystemExit(f'{name}: over-classified {command}')
        PY;
        $process = proc_open(
            array_merge(['/usr/bin/python3', '-I', '-B', '-c', $program], $paths),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root,
            [],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
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
                [
                    ['--execute'],
                    ['--execute', '--confirm-live-write', 'ROB-465', '--confirm-live-restore', 'ROB-461'],
                    ['--execute', '--confirm-live-write', 'ROB-466'],
                    ['--execute', '--confirm-live-restore', 'ROB-461'],
                    ['--confirm-live-write', 'ROB-466', '--confirm-live-restore', 'ROB-461'],
                ]
                as $arguments
            ) {
                self::assertSame(1, $this->runWrapper($arguments, $workspace . '/bin')['exit']);
                self::assertFileDoesNotExist($log);
            }

            $execute = $this->runWrapper(
                ['--execute', '--confirm-live-write', 'ROB-466', '--confirm-live-restore', 'ROB-461'],
                $workspace . '/bin',
            );
            self::assertSame(0, $execute['exit'], $execute['stderr']);
            self::assertSame(
                '-o StrictHostKeyChecking=accept-new prod.example ' .
                    '/usr/bin/python3 -I -B /usr/local/libexec/fh-backup-set-producer-v1 && ' .
                    '/usr/bin/php -n /usr/local/libexec/fh/verify_deployment_dump_v1.php --continuity-state' .
                    "\n",
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
                ]
                as $arguments
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
                    '/usr/bin/php -n /usr/local/libexec/fh/verify_deployment_dump_v1.php --latest-handoff' .
                    "\n",
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
        self::assertFileExists($this->root . '/scripts/ops/systemd/fh-backup-set-producer.service');
        self::assertFileExists($this->root . '/scripts/ops/systemd/fh-backup-set-continuity.timer');
        self::assertFileExists($this->root . '/scripts/ops/systemd/fh-backup-set-restore-verify.service');
        self::assertFileDoesNotExist($this->root . '/scripts/ops/systemd/fh-backup-set-continuity.service');
        self::assertFileDoesNotExist($this->root . '/scripts/ops/systemd/fh-backup-set-producer.timer');
        self::assertStringNotContainsString('systemctl enable', $this->wrapper . $docs);
        self::assertStringNotContainsString('systemctl start', $this->wrapper . $docs);
        self::assertStringNotContainsString('systemctl enable', $this->attestationWrapper);
        self::assertStringNotContainsString('systemctl start', $this->attestationWrapper);
    }

    public function testContinuityStateClosesTheInterServiceHandoffGap(): void
    {
        foreach (
            [
                "CONTINUITY_STATE_LEAF = 'backup_continuity_state.json'",
                "'schema': 'production_backup_continuity_state.v1'",
                "value.get('status') not in {'pending', 'verified'}",
                "publish_continuity_state(backups, 'pending'",
                "expected_state[0]['status'] == 'pending'",
                'resume_pending_continuity(',
            ]
            as $needle
        ) {
            self::assertStringContainsString($needle, $this->helper, $needle);
        }
        foreach (
            [
                "continuity_selector = sys.argv[1] == '--continuity-state'",
                'continuity_state = read_continuity_state(backups)',
                "continuity_state[0]['handoff'] != handoff",
                'mark_continuity_verified(backups, continuity_state, nonce)',
                "value.get('status') != 'pending'",
            ]
            as $needle
        ) {
            self::assertStringContainsString($needle, $this->attestationHelper, $needle);
        }
        $main = substr($this->helper, (int) strpos($this->helper, 'def main():'));
        $state = strpos($main, "publish_continuity_state(backups, 'pending'");
        $handoff = strpos($main, 'publish_handoff(backups, backup_id');
        $marker = strpos($main, 'publish_marker(backups, marker_value');
        foreach ([$state, $handoff, $marker] as $position) {
            self::assertIsInt($position);
        }
        self::assertLessThan($handoff, $state);
        self::assertLessThan($marker, $handoff);
    }

    public function testProducerUnitHasNarrowStageAuthorityAndSuccessOnlyVerifierChain(): void
    {
        self::assertStringContainsString("Type=oneshot\nUser=root\nGroup=root\nUMask=0077", $this->producerUnit);
        self::assertStringContainsString(
            'ExecStart=/usr/bin/python3 -I -B /usr/local/libexec/fh-backup-set-producer-v1',
            $this->producerUnit,
        );
        self::assertStringContainsString('OnSuccess=fh-backup-set-restore-verify.service', $this->producerUnit);
        self::assertSame(1, substr_count($this->producerUnit, 'ExecStart='));
        foreach (
            [
                'NoNewPrivileges=yes',
                'PrivateTmp=yes',
                'PrivateDevices=yes',
                'ProtectSystem=strict',
                'ProtectHome=read-only',
                'ProtectKernelTunables=yes',
                'ProtectKernelModules=yes',
                'ProtectKernelLogs=yes',
                'ProtectControlGroups=yes',
                'ProtectClock=yes',
                'RestrictRealtime=yes',
                'RestrictSUIDSGID=yes',
                'LockPersonality=yes',
                'MemoryDenyWriteExecute=yes',
                'SystemCallArchitectures=native',
                'CapabilityBoundingSet=',
                'AmbientCapabilities=',
            ]
            as $needle
        ) {
            self::assertStringContainsString($needle, $this->producerUnit, $needle);
        }
        self::assertStringContainsString(
            'ReadOnlyPaths=/etc/fh/backup-set-producer.cnf /usr/local/libexec/fh-backup-set-producer-v1 /var/lib/fh-deploy-orchestrator',
            $this->producerUnit,
        );
        self::assertStringContainsString(
            'ReadWritePaths=/root/backups/easyappointments /var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock',
            $this->producerUnit,
        );
        self::assertStringContainsString(
            'InaccessiblePaths=/var/lib/fh-deploy-evidence /var/run/docker.sock',
            $this->producerUnit,
        );
        self::assertStringContainsString('RestrictAddressFamilies=AF_INET AF_INET6', $this->producerUnit);
        foreach (['OnFailure=', 'ExecStartPost=', 'Restart=', '[Install]'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->producerUnit, $forbidden);
        }
    }

    public function testRestoreUnitHasClosedExecAndFilesystemBoundary(): void
    {
        self::assertStringContainsString("Type=oneshot\nUser=root\nGroup=root", $this->restoreUnit);
        self::assertStringContainsString(
            'ExecStart=/usr/bin/php -n /usr/local/libexec/fh/verify_deployment_dump_v1.php --continuity-state',
            $this->restoreUnit,
        );
        self::assertSame(1, substr_count($this->restoreUnit, 'ExecStart='));
        foreach (
            [
                'NoNewPrivileges=yes',
                'PrivateTmp=yes',
                'PrivateDevices=yes',
                'ProtectSystem=strict',
                'ProtectHome=read-only',
                'ProtectKernelTunables=yes',
                'ProtectKernelModules=yes',
                'ProtectKernelLogs=yes',
                'ProtectControlGroups=yes',
                'ProtectClock=yes',
                'RestrictRealtime=yes',
                'RestrictSUIDSGID=yes',
                'LockPersonality=yes',
                'MemoryDenyWriteExecute=yes',
                'SystemCallArchitectures=native',
                'CapabilityBoundingSet=CAP_DAC_OVERRIDE CAP_DAC_READ_SEARCH CAP_SYS_PTRACE',
                'AmbientCapabilities=',
            ]
            as $needle
        ) {
            self::assertStringContainsString($needle, $this->restoreUnit, $needle);
        }
        self::assertStringContainsString(
            'ReadOnlyPaths=/usr/local/libexec/fh /var/lib/fh-deploy-orchestrator',
            $this->restoreUnit,
        );
        self::assertStringContainsString(
            'ReadWritePaths=/root/backups/easyappointments /var/lib/fh-deploy-evidence /var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock /var/run/docker.sock',
            $this->restoreUnit,
        );
        self::assertStringContainsString("StartLimitIntervalSec=4h\nStartLimitBurst=16", $this->restoreUnit);
        self::assertStringContainsString(
            "Restart=on-failure\nRestartSec=15m\nRestartPreventExitStatus=76",
            $this->restoreUnit,
        );
        foreach (['OnSuccess=', 'OnFailure=', 'ExecStartPost=', '[Install]'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->restoreUnit, $forbidden);
        }
    }

    public function testProducerTimerHasExactDailyUtcScheduleWithoutCatchUp(): void
    {
        self::assertStringContainsString(
            "OnCalendar=*-*-* 02:17:00 UTC\nAccuracySec=1m\nPersistent=false\nUnit=fh-backup-set-producer.service",
            $this->timerUnit,
        );
        self::assertSame(1, substr_count($this->timerUnit, 'OnCalendar='));
        foreach (
            ['OnBootSec=', 'OnStartupSec=', 'OnUnitActiveSec=', 'OnUnitInactiveSec=', 'RandomizedDelaySec=']
            as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $this->timerUnit, $forbidden);
        }
        self::assertStringContainsString('[Install]', $this->timerUnit);
        self::assertStringContainsString('WantedBy=timers.target', $this->timerUnit);
    }

    public function testRecurringUnitsPassNativeSystemdVerificationWhenAvailable(): void
    {
        $systemdAnalyze = '/usr/bin/systemd-analyze';
        if (!is_executable($systemdAnalyze) || !is_executable('/usr/bin/php') || !is_executable('/usr/bin/python3')) {
            self::markTestSkipped(
                'Native systemd verification requires systemd-analyze and the production PHP/Python paths.',
            );
        }

        $process = proc_open(
            [
                $systemdAnalyze,
                'verify',
                'scripts/ops/systemd/fh-backup-set-producer.service',
                'scripts/ops/systemd/fh-backup-set-continuity.timer',
                'scripts/ops/systemd/fh-backup-set-restore-verify.service',
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stdout . $stderr);
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

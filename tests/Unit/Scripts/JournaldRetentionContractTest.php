<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class JournaldRetentionContractTest extends TestCase
{
    public function testWrapperIsReadOnlyByDefaultAndSeparatesEveryWriteApproval(): void
    {
        $workspace = $this->workspace('wrapper');
        mkdir($workspace . '/bin', 0777, true);
        $log = $workspace . '/ssh.log';
        file_put_contents(
            $workspace . '/bin/ssh',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " .
                escapeshellarg($log) .
                "\nprintf '%s\\n' '{\"status\":\"pass\"}'\n",
        );
        chmod($workspace . '/bin/ssh', 0755);

        try {
            $inspect = $this->runWrapper([], $workspace . '/bin');
            self::assertSame(0, $inspect['exit'], $inspect['stderr']);
            self::assertStringContainsString('mode       : read-only', $inspect['stdout']);
            self::assertStringContainsString("fh-journald-retention-v1 'inspect'", (string) file_get_contents($log));

            $cases = [
                [['--apply-config'], 1],
                [['--apply-config', '--confirm-live-write', 'ROB-451-VACUUM'], 1],
                [['--vacuum', '--confirm-live-write', 'ROB-451-CONFIG'], 1],
                [['--rollback-config', '--confirm-live-write', 'ROB-451'], 1],
                [['--confirm-live-write', 'ROB-451-CONFIG'], 1],
            ];
            foreach ($cases as [$arguments, $exit]) {
                self::assertSame($exit, $this->runWrapper($arguments, $workspace . '/bin')['exit']);
            }

            foreach (
                [
                    ['--apply-config', '--confirm-live-write', 'ROB-451-CONFIG', 'apply_config'],
                    ['--vacuum', '--confirm-live-write', 'ROB-451-VACUUM', 'vacuum'],
                    ['--rollback-config', '--confirm-live-write', 'ROB-451-ROLLBACK', 'rollback_config'],
                ]
                as [$option, $confirmOption, $confirmation, $remoteMode]
            ) {
                file_put_contents($log, '');
                $result = $this->runWrapper([$option, $confirmOption, $confirmation], $workspace . '/bin');
                self::assertSame(0, $result['exit'], $result['stderr']);
                self::assertStringContainsString('mode       : live-write:' . $remoteMode, $result['stdout']);
                self::assertStringContainsString(
                    "fh-journald-retention-v1 '{$remoteMode}'",
                    (string) file_get_contents($log),
                );
            }
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testHelperClassifiesExactMissingChangedAndConflictingConfiguration(): void
    {
        $fixture = $this->fixture('inspect');
        try {
            $missing = $this->runHelper($fixture, 'inspect');
            self::assertSame(0, $missing['exit'], $missing['stderr']);
            self::assertSame('drift', $missing['json']['status']);
            self::assertSame('managed_dropin_missing', $missing['json']['reason']);
            self::assertSame($fixture['expected_usage'], $missing['json']['disk_usage_bytes']);

            $this->writeManagedConfig($fixture['root']);
            $exact = $this->runHelper($fixture, 'inspect');
            self::assertSame('pass', $exact['json']['status']);
            self::assertSame(1073741824, $exact['json']['system_max_use_bytes']);
            self::assertSame(2592000, $exact['json']['max_retention_seconds']);

            file_put_contents(
                $fixture['systemd_analyze'],
                "#!/usr/bin/env bash\nprintf '%s\\n' '[Journal]' 'SystemMaxUse=2G' 'MaxRetentionSec=30day'\n",
            );
            chmod($fixture['systemd_analyze'], 0755);
            $ineffective = $this->runHelper($fixture, 'inspect');
            self::assertSame('invalid', $ineffective['json']['status']);
            self::assertSame('effective_config_mismatch', $ineffective['json']['reason']);
            file_put_contents(
                $fixture['systemd_analyze'],
                "#!/usr/bin/env bash\ncat " .
                    escapeshellarg($fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf') .
                    "\n",
            );
            chmod($fixture['systemd_analyze'], 0755);

            file_put_contents($fixture['root'] . '/etc/systemd/journald.conf', "[Journal]\nSystemMaxUse=2G\n");
            chmod($fixture['root'] . '/etc/systemd/journald.conf', 0644);
            $conflict = $this->runHelper($fixture, 'inspect');
            self::assertSame('drift', $conflict['json']['status']);
            self::assertSame('conflicting_retention_setting', $conflict['json']['reason']);

            unlink($fixture['root'] . '/etc/systemd/journald.conf');
            file_put_contents(
                $fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf',
                "[Journal]\nSystemMaxUse=2G\nMaxRetentionSec=30day\n",
            );
            chmod($fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf', 0644);
            $changed = $this->runHelper($fixture, 'inspect');
            self::assertSame('drift', $changed['json']['status']);
            self::assertSame('managed_dropin_mismatch', $changed['json']['reason']);
        } finally {
            $this->removeTree($fixture['workspace']);
        }
    }

    public function testConfigActivationVacuumAndRollbackStaySeparateAndAggregate(): void
    {
        $fixture = $this->fixture('lifecycle');
        try {
            $staleTemp = $fixture['root'] . '/etc/systemd/journald.conf.d/.60-fh-journald-retention.conf.tmp';
            file_put_contents($staleTemp, 'partial');
            chmod($staleTemp, 0600);
            $apply = $this->runHelper($fixture, 'apply_config');
            self::assertSame(0, $apply['exit'], $apply['stderr']);
            self::assertSame('applied', $apply['json']['status']);
            self::assertSame(
                file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/systemd/60-fh-journald-retention.conf'),
                file_get_contents($fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf'),
            );
            self::assertSame(
                0644,
                fileperms($fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf') & 0777,
            );
            self::assertFileDoesNotExist($staleTemp);
            self::assertStringContainsString(
                'restart systemd-journald.service',
                (string) file_get_contents($fixture['systemctl_log']),
            );

            $attached = $this->runHelper($fixture, 'apply_config');
            self::assertSame('attached', $attached['json']['status']);

            $vacuum = $this->runHelper($fixture, 'vacuum');
            self::assertSame(0, $vacuum['exit'], $vacuum['stderr']);
            self::assertSame('completed', $vacuum['json']['status']);
            self::assertSame($fixture['expected_usage'], $vacuum['json']['disk_usage_before_bytes']);
            self::assertSame($fixture['expected_usage'], $vacuum['json']['disk_usage_bytes']);
            $journalLog = (string) file_get_contents($fixture['journalctl_log']);
            self::assertStringContainsString('--rotate', $journalLog);
            self::assertStringContainsString('--vacuum-size=1G --vacuum-time=30days', $journalLog);
            self::assertStringNotContainsString('journal entry', $vacuum['stdout']);

            $rollback = $this->runHelper($fixture, 'rollback_config');
            self::assertSame(0, $rollback['exit'], $rollback['stderr']);
            self::assertSame('removed', $rollback['json']['status']);
            self::assertFileDoesNotExist(
                $fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf',
            );

            $again = $this->runHelper($fixture, 'rollback_config');
            self::assertSame('absent', $again['json']['status']);
        } finally {
            $this->removeTree($fixture['workspace']);
        }
    }

    public function testFailedFirstActivationRestoresThePriorAbsence(): void
    {
        $fixture = $this->fixture('restart-failure');
        $failOnce = $fixture['workspace'] . '/fail-once';
        file_put_contents($failOnce, '1');
        file_put_contents(
            $fixture['systemctl'],
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " .
                escapeshellarg($fixture['systemctl_log']) .
                "\nif [[ -e " .
                escapeshellarg($failOnce) .
                ' ]]; then rm -f ' .
                escapeshellarg($failOnce) .
                "; exit 1; fi\n",
        );
        chmod($fixture['systemctl'], 0755);

        try {
            $result = $this->runHelper($fixture, 'apply_config');
            self::assertSame(75, $result['exit']);
            self::assertStringContainsString('command_failed', $result['stderr']);
            self::assertFileDoesNotExist(
                $fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf',
            );
            self::assertSame(
                2,
                substr_count((string) file_get_contents($fixture['systemctl_log']), 'restart systemd-journald.service'),
            );

            $inspect = $this->runHelper($fixture, 'inspect');
            self::assertSame('managed_dropin_missing', $inspect['json']['reason']);
        } finally {
            $this->removeTree($fixture['workspace']);
        }
    }

    public function testUnsafeManagedFileAndConflictingDropinNeverMutate(): void
    {
        $fixture = $this->fixture('unsafe');
        try {
            $foreign = $fixture['root'] . '/etc/systemd/journald.conf.d/50-foreign.conf';
            file_put_contents($foreign, "[Journal]\nMaxRetentionSec=1day\n");
            chmod($foreign, 0644);
            $blocked = $this->runHelper($fixture, 'apply_config');
            self::assertSame(75, $blocked['exit']);
            self::assertStringContainsString('conflicting_retention_setting', $blocked['stderr']);
            self::assertFileDoesNotExist(
                $fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf',
            );
            self::assertSame('', (string) file_get_contents($fixture['systemctl_log']));

            unlink($foreign);
            symlink('/dev/null', $fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf');
            $unsafe = $this->runHelper($fixture, 'apply_config');
            self::assertSame(70, $unsafe['exit']);
            self::assertStringContainsString('file_unsafe', $unsafe['stderr']);
            self::assertTrue(is_link($fixture['root'] . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf'));
        } finally {
            $this->removeTree($fixture['workspace']);
        }
    }

    public function testMonitoringIsAggregateAndDisabledByDefault(): void
    {
        $root = dirname(__DIR__, 3);
        $monitor = (string) file_get_contents($root . '/scripts/ops/kuma_push_host_resources.sh');
        $environment = (string) file_get_contents($root . '/scripts/ops/uptime-kuma-push.env.example');
        $docs = (string) file_get_contents($root . '/docs/ops/production-journald-retention.md');

        self::assertStringContainsString('KUMA_JOURNALD_RETENTION_MONITOR_ENABLED:-0', $monitor);
        self::assertStringContainsString('journald_retention=${journald_retention_status}', $monitor);
        self::assertStringContainsString('KUMA_JOURNALD_RETENTION_MONITOR_ENABLED=0', $environment);
        self::assertStringNotContainsString('journalctl -o cat', $monitor);
        self::assertStringContainsString('No command here grants production authorization.', $docs);
        self::assertStringContainsString(
            'Configuration and one-time vacuum are deliberately separate approvals.',
            $docs,
        );
    }

    /** @return array{workspace:string,root:string,journalctl:string,systemctl:string,systemd_analyze:string,systemctl_log:string,journalctl_log:string,expected_usage:int} */
    private function fixture(string $suffix): array
    {
        $workspace = $this->workspace($suffix);
        $root = $workspace . '/root';
        foreach (
            [
                $root,
                $root . '/etc',
                $root . '/etc/systemd',
                $root . '/etc/systemd/journald.conf.d',
                $root . '/var',
                $root . '/var/lib',
                $root . '/var/lib/fh-deploy-orchestrator',
                $root . '/var/lib/fh-deploy-orchestrator/locks',
                $root . '/var/log',
                $root . '/var/log/journal',
                $root . '/var/log/journal/0123456789abcdef0123456789abcdef',
            ]
            as $directory
        ) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755);
            }
            chmod($directory, 0755);
        }
        touch($root . '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock');
        chmod($root . '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock', 0600);
        $journal = $root . '/var/log/journal/0123456789abcdef0123456789abcdef/system.journal';
        file_put_contents($journal, str_repeat('J', 8192));
        chmod($journal, 0640);
        $journalStat = stat($journal);
        self::assertIsArray($journalStat);
        $expectedUsage = (int) $journalStat['blocks'] * 512;

        mkdir($workspace . '/bin', 0755);
        $journalctlLog = $workspace . '/journalctl.log';
        $systemctlLog = $workspace . '/systemctl.log';
        file_put_contents($journalctlLog, '');
        file_put_contents($systemctlLog, '');
        $journalctl = $workspace . '/bin/journalctl';
        $systemctl = $workspace . '/bin/systemctl';
        $systemdAnalyze = $workspace . '/bin/systemd-analyze';
        file_put_contents(
            $journalctl,
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " .
                escapeshellarg($journalctlLog) .
                "\nif [[ \"\$1\" == '--disk-usage' ]]; then printf '%s\\n' 'Archived and active journals take up 512.0M in the file system.'; fi\n",
        );
        file_put_contents(
            $systemctl,
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($systemctlLog) . "\n",
        );
        file_put_contents(
            $systemdAnalyze,
            "#!/usr/bin/env bash\ncat " .
                escapeshellarg($root . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf') .
                "\n",
        );
        chmod($journalctl, 0755);
        chmod($systemctl, 0755);
        chmod($systemdAnalyze, 0755);

        return [
            'workspace' => $workspace,
            'root' => $root,
            'journalctl' => $journalctl,
            'systemctl' => $systemctl,
            'systemd_analyze' => $systemdAnalyze,
            'journalctl_log' => $journalctlLog,
            'systemctl_log' => $systemctlLog,
            'expected_usage' => $expectedUsage,
        ];
    }

    private function writeManagedConfig(string $root): void
    {
        copy(
            dirname(__DIR__, 3) . '/scripts/ops/systemd/60-fh-journald-retention.conf',
            $root . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf',
        );
        chmod($root . '/etc/systemd/journald.conf.d/60-fh-journald-retention.conf', 0644);
    }

    /** @param list<string> $arguments @return array{exit:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments, string $stubBin): array
    {
        return $this->runProcess(
            array_merge(
                ['bash', 'scripts/ops/prod_journald_retention.sh', '--prod-ssh-target', 'prod.example'],
                $arguments,
            ),
            dirname(__DIR__, 3),
            ['PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: '')],
        );
    }

    /** @param array{root:string,journalctl:string,systemctl:string,systemd_analyze:string} $fixture @return array{exit:int,stdout:string,stderr:string,json:array<string,mixed>} */
    private function runHelper(array $fixture, string $operation): array
    {
        $result = $this->runProcess(
            ['/usr/bin/env', 'python3', '-I', '-B', 'scripts/ops/libexec/journald_retention_v1.py', $operation],
            dirname(__DIR__, 3),
            [
                'FH_JOURNALD_RETENTION_TESTING' => '1',
                'FH_JOURNALD_RETENTION_TEST_ROOT' => $fixture['root'],
                'FH_JOURNALD_RETENTION_JOURNALCTL' => $fixture['journalctl'],
                'FH_JOURNALD_RETENTION_SYSTEMCTL' => $fixture['systemctl'],
                'FH_JOURNALD_RETENTION_SYSTEMD_ANALYZE' => $fixture['systemd_analyze'],
            ],
        );
        $decoded = json_decode(trim($result['stdout']), true);
        $result['json'] = is_array($decoded) ? $decoded : [];
        return $result;
    }

    /** @param list<string> $command @param array<string,string> $environment @return array{exit:int,stdout:string,stderr:string} */
    private function runProcess(array $command, string $cwd, array $environment): array
    {
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge($_ENV, $environment),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }

    private function workspace(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/journald-retention-' . $suffix . '-' . bin2hex(random_bytes(8));
        mkdir($path, 0755, true);
        return $path;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class AppLogRetentionContractTest extends TestCase
{
    public function testWrapperIsReadOnlyByDefaultAndRequiresExactLiveConfirmation(): void
    {
        $workspace = sys_get_temp_dir() . '/app-log-retention-wrapper-' . bin2hex(random_bytes(8));
        mkdir($workspace . '/bin', 0777, true);
        $log = $workspace . '/ssh.log';
        file_put_contents(
            $workspace . '/bin/ssh',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\n",
        );
        chmod($workspace . '/bin/ssh', 0755);

        try {
            $inspect = $this->runWrapper([], $workspace . '/bin');
            self::assertSame(0, $inspect['exit'], $inspect['stderr']);
            self::assertStringContainsString('mode       : read-only', $inspect['stdout']);
            self::assertStringContainsString(
                "/usr/local/libexec/fh-app-log-retention-v1 'dry-run'",
                (string) file_get_contents($log),
            );

            file_put_contents($log, '');
            foreach (
                [['--execute'], ['--execute', '--confirm-live-write', 'ROB-453'], ['--confirm-live-write', 'ROB-452']]
                as $arguments
            ) {
                self::assertSame(1, $this->runWrapper($arguments, $workspace . '/bin')['exit']);
            }
            self::assertSame('', (string) file_get_contents($log));

            $execute = $this->runWrapper(['--execute', '--confirm-live-write', 'ROB-452'], $workspace . '/bin');
            self::assertSame(0, $execute['exit'], $execute['stderr']);
            self::assertStringContainsString('mode       : live-write', $execute['stdout']);
            self::assertStringContainsString(
                "/usr/local/libexec/fh-app-log-retention-v1 'execute'",
                (string) file_get_contents($log),
            );
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testUnitsDocsAndMonitoringFreezeTheClosedBoundary(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string) file_get_contents($root . '/scripts/ops/systemd/fh-app-log-retention.service');
        $timer = (string) file_get_contents($root . '/scripts/ops/systemd/fh-app-log-retention.timer');
        $helper = (string) file_get_contents($root . '/scripts/ops/libexec/app_log_retention_v1.py');
        $docs = (string) file_get_contents($root . '/docs/ops/production-app-log-retention.md');
        $monitor = (string) file_get_contents($root . '/scripts/ops/kuma_push_host_resources.sh');
        $environment = (string) file_get_contents($root . '/scripts/ops/uptime-kuma-push.env.example');
        $inventory = (string) file_get_contents($root . '/scripts/ops/prod_cleanup_inventory.sh');

        self::assertStringContainsString('StateDirectory=fh-app-log-retention', $service);
        self::assertStringContainsString('ProtectSystem=strict', $service);
        self::assertStringContainsString('NoNewPrivileges=yes', $service);
        self::assertStringContainsString('CapabilityBoundingSet=CAP_DAC_OVERRIDE', $service);
        self::assertSame(1, preg_match_all('/^CapabilityBoundingSet=CAP_DAC_OVERRIDE$/m', $service));
        self::assertSame(1, preg_match_all('/\bCAP_[A-Z0-9_]+\b/', $service));
        self::assertStringContainsString('/usr/local/libexec/fh-app-log-retention-v1 execute', $service);
        self::assertStringContainsString('OnCalendar=*-*-* 04:07:00 UTC', $timer);
        self::assertStringContainsString('Persistent=true', $timer);
        self::assertStringNotContainsString('systemctl enable', $service . $timer);
        self::assertStringNotContainsString('systemctl start', $service . $timer);

        self::assertStringContainsString('RETENTION_SECONDS = 60 * 86_400', $helper);
        self::assertStringContainsString("PROTECTED_DIRECTORIES = {'ci', 'ops', 'release-gate'}", $helper);
        self::assertStringContainsString('MAX_DELETE = 1000', $helper);
        self::assertStringContainsString('MAX_DELETE_BYTES = 512 * 1024 * 1024', $helper);
        self::assertStringContainsString('does not clean Journald, Apache logs, backups, sessions, databases', $docs);
        self::assertStringContainsString(
            'Live deletion is a distinct command and remains unauthorized by merge',
            $docs,
        );
        self::assertStringContainsString('KUMA_APP_LOG_RETENTION_MONITOR_ENABLED:-0', $monitor);
        self::assertStringContainsString('app_log_retention=${app_log_marker_status}', $monitor);
        self::assertStringContainsString('KUMA_APP_LOG_RETENTION_MONITOR_ENABLED=0', $environment);
        self::assertStringContainsString('section app_log_retention', $inventory);
        self::assertStringContainsString('app_log_retention.timer_enabled', $inventory);
        self::assertStringContainsString('app_log_retention.marker_status', $inventory);
        self::assertStringNotContainsString('app_log_retention.marker_bytes', $inventory);
    }

    /** @param list<string> $arguments @return array{exit:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments, string $stubBin): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            array_merge(
                ['bash', 'scripts/ops/prod_app_log_retention.sh', '--prod-ssh-target', 'prod.example'],
                $arguments,
            ),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $root,
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

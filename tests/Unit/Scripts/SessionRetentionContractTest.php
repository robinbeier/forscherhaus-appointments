<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class SessionRetentionContractTest extends TestCase
{
    public function testOperatorWrapperIsDryRunByDefaultAndRequiresExactExecuteConfirmation(): void
    {
        $workspace = sys_get_temp_dir() . '/session-retention-wrapper-' . bin2hex(random_bytes(8));
        mkdir($workspace . '/bin', 0777, true);
        $log = $workspace . '/ssh.log';
        file_put_contents(
            $workspace . '/bin/ssh',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\n",
        );
        chmod($workspace . '/bin/ssh', 0755);

        try {
            $dryRun = $this->runWrapper([], $workspace . '/bin');
            self::assertSame(0, $dryRun['exit']);
            self::assertStringContainsString('mode       : read-only', $dryRun['stdout']);
            self::assertStringContainsString(
                "/usr/local/libexec/fh-session-retention-v1 'dry-run'",
                (string) file_get_contents($log),
            );

            file_put_contents($log, '');
            foreach (
                [['--execute'], ['--execute', '--confirm-live-write', 'ROB-450'], ['--confirm-live-write', 'ROB-440']]
                as $args
            ) {
                self::assertSame(1, $this->runWrapper($args, $workspace . '/bin')['exit']);
            }
            self::assertSame('', (string) file_get_contents($log));

            $execute = $this->runWrapper(['--execute', '--confirm-live-write', 'ROB-440'], $workspace . '/bin');
            self::assertSame(0, $execute['exit']);
            self::assertStringContainsString('mode       : live-write', $execute['stdout']);
            self::assertStringContainsString(
                "/usr/local/libexec/fh-session-retention-v1 'execute'",
                (string) file_get_contents($log),
            );
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testSystemdUnitsAreHardenedDailyAndNotEnabledByRepositoryCode(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string) file_get_contents($root . '/scripts/ops/systemd/fh-session-retention.service');
        $timer = (string) file_get_contents($root . '/scripts/ops/systemd/fh-session-retention.timer');
        $all = $service . $timer;

        self::assertStringContainsString('User=root', $service);
        self::assertStringContainsString('StateDirectory=fh-session-retention', $service);
        self::assertStringContainsString('StateDirectoryMode=0700', $service);
        self::assertStringContainsString('IOSchedulingClass=idle', $service);
        self::assertStringContainsString('Nice=10', $service);
        self::assertStringContainsString('ProtectSystem=strict', $service);
        self::assertStringContainsString('NoNewPrivileges=yes', $service);
        self::assertStringContainsString('CapabilityBoundingSet=CAP_DAC_OVERRIDE', $service);
        self::assertStringContainsString('AmbientCapabilities=', $service);
        self::assertSame(1, preg_match_all('/^CapabilityBoundingSet=CAP_DAC_OVERRIDE$/m', $service));
        self::assertSame(1, preg_match_all('/\bCAP_[A-Z0-9_]+\b/', $service));
        self::assertStringContainsString('/usr/local/libexec/fh-session-retention-v1 execute', $service);
        self::assertStringNotContainsString('/var/www/html/easyappointments/scripts/ops/libexec', $service);
        self::assertStringContainsString('OnCalendar=*-*-* 03:37:00 UTC', $timer);
        self::assertStringContainsString('Persistent=true', $timer);
        self::assertStringNotContainsString('systemctl enable', $all);
        self::assertStringNotContainsString('systemctl start', $all);
    }

    public function testHostResourceMonitorKeepsRetentionDisabledUntilExplicitActivation(): void
    {
        $root = dirname(__DIR__, 3);
        $script = (string) file_get_contents($root . '/scripts/ops/kuma_push_host_resources.sh');
        $environment = (string) file_get_contents($root . '/scripts/ops/uptime-kuma-push.env.example');

        self::assertStringContainsString('KUMA_SESSION_RETENTION_MONITOR_ENABLED:-0', $script);
        self::assertStringContainsString('marker-status', $script);
        self::assertStringContainsString('session_retention=${marker_status}', $script);
        self::assertStringContainsString('KUMA_SESSION_RETENTION_MONITOR_ENABLED=0', $environment);
        self::assertStringContainsString('KUMA_SESSION_RETENTION_MARKER_MAX_AGE_SECONDS=129600', $environment);
    }

    public function testCleanupInventoryReportsOnlyAggregateTimerAndMarkerState(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/prod_cleanup_inventory.sh');
        self::assertStringContainsString('section session_retention', $script);
        self::assertStringContainsString('session_retention.timer_enabled', $script);
        self::assertStringContainsString('session_retention.timer_active', $script);
        self::assertStringContainsString('session_retention.marker_status', $script);
        self::assertStringContainsString('session_retention.marker_age_seconds', $script);
        self::assertStringNotContainsString('session_retention.marker_bytes', $script);
    }

    /** @param list<string> $arguments @return array{exit:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments, string $stubBin): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            array_merge(
                ['bash', 'scripts/ops/prod_session_retention.sh', '--prod-ssh-target', 'prod.example'],
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

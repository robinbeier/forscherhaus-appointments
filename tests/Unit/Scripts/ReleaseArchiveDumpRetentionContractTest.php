<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ReleaseArchiveDumpRetentionContractTest extends TestCase
{
    public function testWrapperIsDryRunByDefaultAndRequiresExactConfirmation(): void
    {
        $workspace = sys_get_temp_dir() . '/rob453-wrapper-' . bin2hex(random_bytes(8));
        mkdir($workspace . '/bin', 0777, true);
        $log = $workspace . '/ssh.log';
        file_put_contents(
            $workspace . '/bin/ssh',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\n",
        );
        chmod($workspace . '/bin/ssh', 0755);
        try {
            $dry = $this->runWrapper([], $workspace . '/bin');
            self::assertSame(0, $dry['exit'], $dry['stderr']);
            self::assertStringContainsString('mode       : read-only', $dry['stdout']);
            self::assertStringContainsString(
                "fh-release-archive-dump-retention-v1 'dry-run'",
                (string) file_get_contents($log),
            );

            file_put_contents($log, '');
            foreach (
                [['--execute'], ['--execute', '--confirm-live-write', 'ROB-440'], ['--confirm-live-write', 'ROB-453']]
                as $arguments
            ) {
                self::assertSame(1, $this->runWrapper($arguments, $workspace . '/bin')['exit']);
            }
            self::assertSame('', (string) file_get_contents($log));

            $execute = $this->runWrapper(['--execute', '--confirm-live-write', 'ROB-453'], $workspace . '/bin');
            self::assertSame(0, $execute['exit'], $execute['stderr']);
            self::assertStringContainsString('mode       : live-write', $execute['stdout']);
            self::assertStringContainsString(
                "fh-release-archive-dump-retention-v1 'execute'",
                (string) file_get_contents($log),
            );
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testTimerIsWeeklyPersistentAndRepositoryDoesNotActivateIt(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string) file_get_contents($root . '/scripts/ops/systemd/fh-release-archive-dump-retention.service');
        $timer = (string) file_get_contents($root . '/scripts/ops/systemd/fh-release-archive-dump-retention.timer');
        $wrapper = (string) file_get_contents($root . '/scripts/ops/prod_release_archive_dump_retention.sh');
        $docs = (string) file_get_contents($root . '/docs/ops/production-release-archive-dump-retention.md');
        self::assertStringContainsString('OnCalendar=Sun *-*-* 04:17:00 UTC', $timer);
        self::assertStringContainsString('Persistent=true', $timer);
        self::assertStringContainsString('Unit=fh-release-archive-dump-retention.service', $timer);
        self::assertStringContainsString('StateDirectory=fh-release-retention', $service);
        self::assertStringContainsString('ProtectSystem=strict', $service);
        self::assertStringContainsString('NoNewPrivileges=yes', $service);
        self::assertStringContainsString(
            'CapabilityBoundingSet=CAP_DAC_OVERRIDE CAP_DAC_READ_SEARCH CAP_SYS_PTRACE',
            $service,
        );
        self::assertSame(3, preg_match_all('/\bCAP_[A-Z0-9_]+\b/', $service));
        self::assertSame(1, preg_match_all('/^CapabilityBoundingSet=/m', $service));
        self::assertStringContainsString('AmbientCapabilities=', $service);
        self::assertStringContainsString('/usr/local/libexec/fh-release-archive-dump-retention-v1 execute', $service);
        self::assertStringNotContainsString('/var/www/html/easyappointments/scripts/ops/libexec', $service);
        self::assertStringNotContainsString('systemctl enable', $timer . $wrapper . $docs);
        self::assertStringNotContainsString('systemctl start', $timer . $wrapper . $docs);
        self::assertStringContainsString('does not activate the timer', $docs);
    }

    public function testMonitoringAndInventoryAreAggregateAndDisabledByDefault(): void
    {
        $root = dirname(__DIR__, 3);
        $monitor = (string) file_get_contents($root . '/scripts/ops/kuma_push_host_resources.sh');
        $environment = (string) file_get_contents($root . '/scripts/ops/uptime-kuma-push.env.example');
        $inventory = (string) file_get_contents($root . '/scripts/ops/prod_cleanup_inventory.sh');
        self::assertStringContainsString('KUMA_RELEASE_RETENTION_MONITOR_ENABLED:-0', $monitor);
        self::assertStringContainsString('release_retention=${release_marker_status}', $monitor);
        self::assertStringContainsString('KUMA_RELEASE_RETENTION_MONITOR_ENABLED=0', $environment);
        self::assertStringContainsString('KUMA_RELEASE_RETENTION_MARKER_MAX_AGE_SECONDS=691200', $environment);
        self::assertStringContainsString('section release_archive_dump_retention', $inventory);
        self::assertStringContainsString('release_retention.timer_enabled', $inventory);
        self::assertStringContainsString('release_retention.marker_status', $inventory);
        self::assertStringNotContainsString('release_retention.marker_bytes', $inventory);
    }

    public function testHelperFreezesConservativeClassPoliciesAndAggregateOutput(): void
    {
        $helper = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scripts/ops/libexec/release_archive_dump_retention_v1.py',
        );
        self::assertStringContainsString('RELEASE_DIR_MIN_AGE = 7 * 86_400', $helper);
        self::assertStringContainsString('ARCHIVE_MIN_AGE = 30 * 86_400', $helper);
        self::assertStringContainsString('DUMP_MIN_AGE = 30 * 86_400', $helper);
        self::assertStringContainsString('KEEP_ARCHIVE_PAIRS = 4', $helper);
        self::assertStringContainsString('KEEP_VERIFIED_DUMPS = 2', $helper);
        self::assertStringContainsString("max(storage['allocated'], storage['logical'])", $helper);
        self::assertStringNotContainsString("'release_id': current_release", $helper);
        self::assertStringNotContainsString("'dump_sha':", substr($helper, strpos($helper, 'def result_payload')));
    }

    /** @param list<string> $arguments @return array{exit:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments, string $stubBin): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            array_merge(
                ['bash', 'scripts/ops/prod_release_archive_dump_retention.sh', '--prod-ssh-target', 'prod.example'],
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

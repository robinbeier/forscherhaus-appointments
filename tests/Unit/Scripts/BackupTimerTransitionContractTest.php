<?php
declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('root-deployment')]
final class BackupTimerTransitionContractTest extends TestCase
{
    private string $root;
    private string $script;

    protected function setUp(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Root-owned Linux fixtures are required.');
        }
        $this->root = sys_get_temp_dir() . '/fh-timer-transition-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/locks', 0700, true));
        self::assertTrue(mkdir($this->root . '/proc', 0700));
        self::assertTrue(mkdir($this->root . '/backups', 0700));
        foreach ([$this->root, $this->root . '/locks'] as $path) {
            chmod($path, 0700);
            chown($path, 0);
            chgrp($path, 0);
        }
        $lock = $this->root . '/locks/fh-production-change.lock';
        touch($lock);
        chmod($lock, 0600);
        chown($lock, 0);
        chgrp($lock, 0);
        $fake = $this->root . '/systemctl';
        file_put_contents(
            $fake,
            <<<'SH'
            #!/bin/sh
            set -eu
            printf '%s\n' "$*" >> "$FH_TIMER_LOG"
            state=$(cat "$FH_TIMER_STATE")
            case "$1" in
              is-enabled)
                if [ "$state" = enabled ]; then echo enabled; else echo disabled; exit 1; fi ;;
              is-active)
                if [ "$2" = fh-backup-set-continuity.service ]; then
                  if [ "$FH_TIMER_MODE" = service-active ]; then echo active; else echo inactive; exit 3; fi
                fi
                if [ "$state" = enabled ]; then echo active; else echo inactive; exit 3; fi ;;
              show)
                if [ "$state" = enabled ]; then echo waiting; else echo dead; fi ;;
              disable)
                if [ "$FH_TIMER_MODE" = disable-failed ]; then exit 1; fi
                if [ "$FH_TIMER_MODE" = interrupted ]; then kill -TERM "$PPID"; exit 1; fi
                echo disabled > "$FH_TIMER_STATE" ;;
              enable)
                if [ "$FH_TIMER_MODE" = enable-failed ]; then exit 1; fi
                echo enabled > "$FH_TIMER_STATE" ;;
              *) exit 2 ;;
            esac
            SH
            ,
        );
        chmod($fake, 0755);
        file_put_contents($this->root . '/timer.state', "enabled\n");
        file_put_contents($this->root . '/timer.log', '');
        $handoff = [
            'backup_set_id' => '20260924T000000Z',
            'compressed_size_bytes' => 1024,
            'dump_sha256' => str_repeat('a', 64),
            'schema' => 'production_backup_set_handoff.v1',
            'uncompressed_size_bytes' => 2048,
        ];
        $this->writeContinuityState([
            'handoff' => $handoff,
            'schema' => 'production_backup_continuity_state.v1',
            'status' => 'verified',
        ]);
        $source = file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/libexec/backup_timer_transition_v1.py');
        self::assertIsString($source);
        $source = str_replace(
            [
                "'/var/lib/fh-deploy-orchestrator'",
                "'/usr/bin/systemctl'",
                "'/var/lib/fh-defense-ordinary",
                "'/root/backups/easyappointments'",
                "os.scandir('/proc')",
                "os.path.join('/proc', entry.name,",
            ],
            [
                var_export($this->root, true),
                var_export($fake, true),
                "'" . $this->root . '/ordinary',
                var_export($this->root . '/backups', true),
                'os.scandir(' . var_export($this->root . '/proc', true) . ')',
                'os.path.join(' . var_export($this->root . '/proc', true) . ', entry.name,',
            ],
            $source,
        );
        $this->script = $this->root . '/transition.py';
        file_put_contents($this->script, $source);
        chmod($this->script, 0700);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testPauseAndRestoreBindRunAndReleaseLockBetweenCalls(): void
    {
        $run = str_repeat('a', 32);
        $pause = $this->invokeTransition('pause', $run);
        self::assertSame(0, $pause['exit'], json_encode($pause));
        self::assertSame('paused', $pause['receipt']['result_class']);
        self::assertSame("disabled\n", file_get_contents($this->root . '/timer.state'));
        self::assertFileExists($this->root . '/backup-timer-transition.v1.json');
        $handle = fopen($this->root . '/locks/fh-production-change.lock', 'r+');
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        flock($handle, LOCK_UN);
        fclose($handle);
        $restore = $this->invokeTransition('restore', $run);
        self::assertSame(0, $restore['exit'], json_encode($restore));
        self::assertSame('restored', $restore['receipt']['result_class']);
        self::assertSame("enabled\n", file_get_contents($this->root . '/timer.state'));
        self::assertFileDoesNotExist($this->root . '/backup-timer-transition.v1.json');
        self::assertSame(2, substr_count((string) file_get_contents($this->root . '/timer.log'), '--now'));
    }

    public function testBusyLockRefusesBeforeTimerRead(): void
    {
        $handle = fopen($this->root . '/locks/fh-production-change.lock', 'r+');
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $result = $this->invokeTransition('pause', str_repeat('b', 32));
        flock($handle, LOCK_UN);
        fclose($handle);
        self::assertSame('lock_busy', $result['receipt']['result_class']);
        self::assertSame('', file_get_contents($this->root . '/timer.log'));
    }

    public function testActiveDeploymentProcessRefusesUnderLock(): void
    {
        $process = $this->root . '/proc/123';
        mkdir($process, 0700);
        file_put_contents($process . '/status', "Name:\tdeploy\nUid:\t0\t0\t0\t0\n");
        file_put_contents($process . '/cmdline', "/root/deploy_ea.sh\0--release\0");
        $result = $this->invokeTransition('pause', str_repeat('4', 32));
        self::assertSame('activity_present', $result['receipt']['result_class']);
        file_put_contents($process . '/cmdline', "/usr/local/libexec/fh-backup-set-producer-v1\0");
        $directProducer = $this->invokeTransition('pause', str_repeat('4', 32));
        self::assertSame('activity_present', $directProducer['receipt']['result_class']);
        self::assertStringNotContainsString('disable --now', (string) file_get_contents($this->root . '/timer.log'));
    }

    public function testPendingMalformedAndTemporaryContinuityStateBlockTimerTransition(): void
    {
        $run = str_repeat('5', 32);
        $path = $this->root . '/backups/backup_continuity_state.json';
        $state = json_decode((string) file_get_contents($path), true);
        $state['status'] = 'pending';
        $this->writeContinuityState($state);
        $pending = $this->invokeTransition('pause', $run);
        self::assertSame('backup_continuity_pending', $pending['receipt']['result_class']);

        file_put_contents($path, "{malformed\n");
        $invalid = $this->invokeTransition('pause', $run);
        self::assertSame('backup_continuity_invalid', $invalid['receipt']['result_class']);

        $state['status'] = 'verified';
        $this->writeContinuityState($state);
        file_put_contents($this->root . '/backups/.backup_continuity_state.json.tmp-123', '');
        $temporary = $this->invokeTransition('pause', $run);
        self::assertSame('backup_continuity_unresolved', $temporary['receipt']['result_class']);
        self::assertStringNotContainsString('disable --now', (string) file_get_contents($this->root . '/timer.log'));
    }

    public function testWrongRunAndRecoveryMarkerKeepPausedState(): void
    {
        $run = str_repeat('c', 32);
        self::assertSame(0, $this->invokeTransition('pause', $run)['exit']);
        $wrong = $this->invokeTransition('restore', str_repeat('d', 32));
        self::assertSame('restore_not_authorized', $wrong['receipt']['result_class']);
        file_put_contents($this->root . '/csp-report-only-pilot.state.json', '{}');
        $marker = $this->invokeTransition('restore', $run);
        self::assertSame('recovery_marker_present', $marker['receipt']['result_class']);
        self::assertSame("disabled\n", file_get_contents($this->root . '/timer.state'));
        self::assertFileExists($this->root . '/backup-timer-transition.v1.json');
    }

    public function testOrdinaryPendingMarkerAndStateDriftRejectWithoutTimerMutation(): void
    {
        $run = str_repeat('3', 32);
        mkdir($this->root . '/ordinary', 0700);
        file_put_contents($this->root . '/ordinary/run.pending', '');
        $pending = $this->invokeTransition('pause', $run);
        self::assertSame('recovery_marker_present', $pending['receipt']['result_class']);
        self::assertStringNotContainsString('disable --now', (string) file_get_contents($this->root . '/timer.log'));
        unlink($this->root . '/ordinary/run.pending');
        rmdir($this->root . '/ordinary');

        self::assertSame(0, $this->invokeTransition('pause', $run)['exit']);
        $repeated = $this->invokeTransition('pause', $run);
        self::assertSame('transition_unresolved', $repeated['receipt']['result_class']);
        $statePath = $this->root . '/backup-timer-transition.v1.json';
        $state = json_decode((string) file_get_contents($statePath), true);
        $state['schema'] = 'unexpected';
        file_put_contents($statePath, json_encode($state) . "\n");
        $invalid = $this->invokeTransition('restore', $run);
        self::assertSame('state_invalid', $invalid['receipt']['result_class']);
        self::assertSame("disabled\n", file_get_contents($this->root . '/timer.state'));
    }

    public function testChangedLockIdentityRefusesRestore(): void
    {
        $run = str_repeat('e', 32);
        self::assertSame(0, $this->invokeTransition('pause', $run)['exit']);
        $lock = $this->root . '/locks/fh-production-change.lock';
        rename($lock, $lock . '.old');
        touch($lock);
        chmod($lock, 0600);
        $result = $this->invokeTransition('restore', $run);
        self::assertSame('lock_identity_changed', $result['receipt']['result_class']);
        self::assertSame("disabled\n", file_get_contents($this->root . '/timer.state'));
        self::assertFileExists($this->root . '/backup-timer-transition.v1.json');
    }

    public function testUnexpectedTimerAndActiveServiceRefuseBeforeMutation(): void
    {
        $run = str_repeat('f', 32);
        $active = $this->invokeTransition('pause', $run, 'service-active');
        self::assertSame('backup_service_active', $active['receipt']['result_class']);
        self::assertStringNotContainsString('disable --now', (string) file_get_contents($this->root . '/timer.log'));
        self::assertSame(0, $this->invokeTransition('pause', $run)['exit']);
        file_put_contents($this->root . '/timer.state', "enabled\n");
        $unexpected = $this->invokeTransition('restore', $run);
        self::assertSame('timer_state_unexpected', $unexpected['receipt']['result_class']);
        self::assertFileExists($this->root . '/backup-timer-transition.v1.json');
    }

    public function testFailedPauseAndRestoreRetainRecoveryState(): void
    {
        $run = str_repeat('1', 32);
        $pause = $this->invokeTransition('pause', $run, 'disable-failed');
        self::assertSame('transition_unknown', $pause['receipt']['result_class']);
        self::assertSame("enabled\n", file_get_contents($this->root . '/timer.state'));
        self::assertFileExists($this->root . '/backup-timer-transition.v1.json');
        // Reset only this local synthetic fixture to test the separate restore path.
        unlink($this->root . '/backup-timer-transition.v1.json');
        self::assertSame(0, $this->invokeTransition('pause', $run)['exit']);
        $restore = $this->invokeTransition('restore', $run, 'enable-failed');
        self::assertSame('transition_unknown', $restore['receipt']['result_class']);
        self::assertSame("disabled\n", file_get_contents($this->root . '/timer.state'));
        self::assertFileExists($this->root . '/backup-timer-transition.v1.json');
    }

    public function testInterruptedPauseRetainsStateWithoutSuccessReceipt(): void
    {
        $result = $this->invokeTransition('pause', str_repeat('2', 32), 'interrupted');
        self::assertNotSame(0, $result['exit']);
        self::assertNotSame('passed', $result['receipt']['status'] ?? null);
        self::assertFileExists($this->root . '/backup-timer-transition.v1.json');
    }

    /** @return array{exit:int,receipt:array<string,mixed>} */
    private function invokeTransition(string $action, string $run, string $mode = ''): array
    {
        $command = sprintf(
            'FH_TIMER_STATE=%s FH_TIMER_LOG=%s FH_TIMER_MODE=%s python3 %s %s %s 2>/dev/null',
            escapeshellarg($this->root . '/timer.state'),
            escapeshellarg($this->root . '/timer.log'),
            escapeshellarg($mode),
            escapeshellarg($this->script),
            escapeshellarg($action),
            escapeshellarg($run),
        );
        $output = [];
        exec($command, $output, $exit);
        return ['exit' => $exit, 'receipt' => json_decode(implode('', $output), true) ?: []];
    }

    /** @param array<string,mixed> $state */
    private function writeContinuityState(array $state): void
    {
        $path = $this->root . '/backups/backup_continuity_state.json';
        file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n");
        chmod($path, 0600);
    }
}

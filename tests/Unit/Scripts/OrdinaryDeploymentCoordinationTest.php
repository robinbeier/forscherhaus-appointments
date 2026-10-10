<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class OrdinaryDeploymentCoordinationTest extends TestCase
{
    private string $directory;
    private string $lock;
    private array $holders = [];

    protected function setUp(): void
    {
        if (
            PHP_OS_FAMILY !== 'Linux' ||
            !function_exists('posix_geteuid') ||
            posix_geteuid() !== 0 ||
            (getenv('FH_ROOT_HOST_TESTS_REQUIRED') !== '1' && getenv('FH_DEFENSE_ISOLATED') !== '1')
        ) {
            self::markTestSkipped('Requires the explicitly authorized Linux root coordination test.');
        }
        $this->directory = '/var/lib/fh-ordinary-coordination-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->lock = $this->directory . '/production.lock';
        self::assertSame(0, file_put_contents($this->lock, ''));
        self::assertTrue(chmod($this->lock, 0600));
    }

    protected function tearDown(): void
    {
        foreach ($this->holders as $holder) {
            if (is_resource($holder['process'])) {
                proc_terminate($holder['process'], 9);
                foreach ($holder['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($holder['process']);
            }
        }
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        $this->removeOwnedTree($this->directory);
    }

    public function testDeploymentAndProbeCannotHoldTheSharedLockConcurrently(): void
    {
        foreach (['deploy', 'probe'] as $holderLabel) {
            $holder = $this->startLockHolder($holderLabel);
            self::assertSame('ready', trim((string) fgets($holder['pipes'][1])));
            $contender = $this->runShell('source ./deploy_ea.sh; ordinary_production_change_lock "$1"', [$this->lock]);
            self::assertSame(75, $contender['exit_code'], $holderLabel . ': ' . $contender['stderr']);
            fwrite($holder['pipes'][0], "release\n");
            fclose($holder['pipes'][0]);
            fclose($holder['pipes'][1]);
            fclose($holder['pipes'][2]);
            proc_close($holder['process']);
        }
    }

    public function testKilledLockHolderLeavesPendingRunUntilExplicitSuccessfulFinish(): void
    {
        $state = $this->directory . '/state';
        self::assertTrue(mkdir($state, 0700));
        $holder = $this->startLockHolder('probe', $state);
        self::assertSame('ready', trim((string) fgets($holder['pipes'][1])));
        proc_terminate($holder['process'], 9);
        fclose($holder['pipes'][0]);
        fclose($holder['pipes'][1]);
        fclose($holder['pipes'][2]);
        proc_close($holder['process']);

        $blocked = $this->runShell(
            'source ./deploy_ea.sh; ordinary_production_change_lock "$1"; ordinary_assert_no_pending_probe "$2"',
            [$this->lock, $state],
        );
        self::assertSame(75, $blocked['exit_code']);
        self::assertDirectoryExists($state . '/run.pending');

        $finish = $this->runShell(
            'source ./deploy_ea.sh; ordinary_production_change_lock "$1"; ordinary_probe_finish "$2"',
            [$this->lock, $state],
        );
        self::assertSame(0, $finish['exit_code'], $finish['stderr']);
        self::assertSame(
            0,
            $this->runShell('source ./deploy_ea.sh; ordinary_assert_no_pending_probe "$1"', [$state])['exit_code'],
        );
    }

    public function testDeploymentCannotStageProbeOwnedSessionWhileProbeHoldsLock(): void
    {
        $state = $this->directory . '/state';
        $stage = $this->directory . '/stage';
        self::assertTrue(mkdir($state, 0700));
        self::assertTrue(mkdir($stage, 0700));
        $holder = $this->startLockHolder('probe', $state, true);
        self::assertSame('ready', trim((string) fgets($holder['pipes'][1])));
        $copy = $this->runShell(
            'source ./deploy_ea.sh; ordinary_production_change_lock "$1"; ordinary_assert_no_pending_probe "$2"; cp -a "$2/." "$3/"',
            [$this->lock, $state, $stage],
        );
        self::assertSame(75, $copy['exit_code']);
        self::assertFileDoesNotExist($stage . '/owned-session');

        fwrite($holder['pipes'][0], "release\n");
        fclose($holder['pipes'][0]);
        fclose($holder['pipes'][1]);
        fclose($holder['pipes'][2]);
        self::assertSame(0, proc_close($holder['process']));
        file_put_contents($state . '/owned-release.txt', 'synthetic staged release');
        self::assertSame(
            0,
            $this->runShell(
                'source ./deploy_ea.sh; ordinary_production_change_lock "$1"; ordinary_assert_no_pending_probe "$2"; cp -a "$2/." "$3/"',
                [$this->lock, $state, $stage],
            )['exit_code'],
        );
        self::assertFileExists($stage . '/owned-release.txt');
        self::assertFileDoesNotExist($stage . '/owned-session');
    }

    public function testIncompleteArtifactsRemainAndBlockBothCoordinationEntrances(): void
    {
        $state = $this->directory . '/state';
        self::assertTrue(mkdir($state, 0700));
        foreach (
            [
                'request-unconfirmed',
                'state.json',
                'defense-verification.json',
                'defense-verification.json.tmp',
                'sessions.json.tmp',
            ]
            as $artifact
        ) {
            self::assertSame(
                strlen('synthetic incomplete state'),
                file_put_contents($state . '/' . $artifact, 'synthetic incomplete state'),
            );
            $result = $this->runShell('source ./deploy_ea.sh; ordinary_assert_no_pending_probe "$1"', [$state]);
            self::assertSame(75, $result['exit_code']);
            self::assertFileExists($state . '/' . $artifact);
            unlink($state . '/' . $artifact);
        }
    }

    public function testUnsafeLockIsRejectedWithoutReplacingIt(): void
    {
        chmod($this->lock, 0666);
        self::assertSame(
            1,
            $this->runShell('source ./deploy_ea.sh; ordinary_production_change_lock "$1"', [$this->lock])['exit_code'],
        );
        self::assertSame('', file_get_contents($this->lock));
        chmod($this->lock, 0600);
        $link = $this->directory . '/linked-lock';
        symlink($this->lock, $link);
        self::assertSame(
            1,
            $this->runShell('source ./deploy_ea.sh; ordinary_production_change_lock "$1"', [$link])['exit_code'],
        );
        self::assertTrue(is_link($link));
    }

    public function testLockRemovedAfterPreOpenStatIsRejectedWithoutRecreatingThePath(): void
    {
        $result = $this->runShell(
            'lock_path="$1"; ' .
                'stat() { /usr/bin/stat "$@"; status=$?; ' .
                'if [[ "$2" == "%a:%u:%h:%s:%d:%i" && "$4" == "$lock_path" && ! -e "$lock_path.removed" ]]; then ' .
                'mkdir -- "$lock_path.removed"; rm -- "$lock_path"; fi; ' .
                'return "$status"; }; ' .
                'source ./deploy_ea.sh; ordinary_production_change_lock "$lock_path"',
            [$this->lock],
        );
        self::assertDirectoryExists($this->lock . '.removed', 'The pre-open removal must actually run.');
        self::assertFileDoesNotExist($this->lock);
        self::assertSame(1, $result['exit_code'], $result['stderr']);
    }

    public function testVerifiedInheritedLockSupportsAnExistingMigrationDeploymentWindow(): void
    {
        $result = $this->runShell(
            'source ./deploy_ea.sh; ordinary_production_change_lock "$1"; /bin/bash -c \'source ./deploy_ea.sh; ordinary_production_change_lock "$1"\' bash "$1"',
            [$this->lock],
        );
        self::assertSame(0, $result['exit_code'], $result['stderr']);
    }

    public function testOrdinaryLockDescriptorIsReadWriteForDurableAdmission(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            ordinary_production_change_lock "$1"
            python3 - "$ORDINARY_CHANGE_LOCK_FD" <<'PY'
            import fcntl
            import os
            import sys

            fd = int(sys.argv[1])
            flags = fcntl.fcntl(fd, fcntl.F_GETFL)
            assert (flags & os.O_ACCMODE) == os.O_RDWR
            PY
            BASH
            ,
            [$this->lock],
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testDeployEntryRequiresFixedHashBoundAdmissionBeforeReceiptMutation(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh');
        $entryStart = strpos($source, "if [[ \"\$DRYRUN\" -eq 0 ]]; then\n  ordinary_production_change_lock");
        $entryEnd = strpos($source, "\nARCHIVE=\"\${SRC}/\${REL}.tar.gz\"", $entryStart === false ? 0 : $entryStart);

        self::assertIsInt($entryStart);
        self::assertIsInt($entryEnd);
        $entry = substr($source, $entryStart, $entryEnd - $entryStart);
        self::assertIsString($entry);
        self::assertSame(1, substr_count($entry, 'ordinary_assert_pending_admission'));
        self::assertStringContainsString("local core='/usr/local/libexec/fh/maintenance_pending_v1.py'", $source);
        self::assertStringContainsString(
            "local expected_hash='78da04cc6c66c7e140dcc6f4a924acf92062ea5860c8bb424115129b922c6bb5'",
            $source,
        );
        self::assertStringNotContainsString('spec_from_file_location', $source);
        self::assertStringContainsString('max_core_bytes = 1024 * 1024', $source);
        self::assertStringContainsString('source.extend(chunk)', $source);
        self::assertStringContainsString('hashlib.sha256(source).hexdigest()', $source);
        self::assertStringContainsString("exec(compile(bytes(source), CORE, 'exec'), module_namespace)", $source);
        self::assertStringContainsString("pending_error = module_namespace.get('PendingError')", $source);
        self::assertStringContainsString('exec {ordinary_ro_fd}<"$lock_path"', $source);
        self::assertStringContainsString('exec {ordinary_fd}<>"/proc/$$/fd/$ordinary_ro_fd"', $source);
        self::assertLessThan(
            strpos($entry, 'deploy_result_receipt_prepare'),
            strpos($entry, 'ordinary_assert_pending_admission'),
        );
    }

    public function testDirectDeploymentPassesItsLockThroughPhpProcessRunner(): void
    {
        $probe = $this->directory . '/php-lock-probe.php';
        file_put_contents(
            $probe,
            <<<'PHP'
            <?php
            require getcwd() . '/scripts/release-gate/lib/GateProcessRunner.php';
            $result = \ReleaseGate\GateProcessRunner::run(
                ['bash', '-c', 'fd="${ORDINARY_CHANGE_LOCK_FD:-}"; [[ "$fd" =~ ^[0-9]+$ ]] && [[ -e "/proc/$$/fd/$fd" ]] && [[ "$(stat -Lc %d:%i -- "/proc/$$/fd/$fd")" == "$(stat -c %d:%i -- "$1")" ]]', 'canary-lock-probe', $argv[1]],
                getcwd(),
                null,
                10,
            );
            exit($result['exit_code']);
            PHP
            ,
        );
        $result = $this->runShell('source ./deploy_ea.sh; ordinary_production_change_lock "$1"; php "$2" "$1"', [
            $this->lock,
            $probe,
        ]);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
    }

    public function testActiveCspReportOnlyPilotLeaseBlocksDeploymentWithoutRemovingState(): void
    {
        $lease = $this->directory . '/csp-report-only-pilot.state.json';
        self::assertSame(strlen('synthetic lease'), file_put_contents($lease, 'synthetic lease'));
        self::assertTrue(chmod($lease, 0600));

        $blocked = $this->runShell(
            'source ./deploy_ea.sh; ordinary_production_change_lock "$1"; ordinary_assert_no_active_csp_report_only_pilot "$2"',
            [$this->lock, $lease],
        );

        self::assertSame(75, $blocked['exit_code'], $blocked['stderr']);
        self::assertSame('synthetic lease', file_get_contents($lease));
        unlink($lease);

        $allowed = $this->runShell(
            'source ./deploy_ea.sh; ordinary_production_change_lock "$1"; ordinary_assert_no_active_csp_report_only_pilot "$2"',
            [$this->lock, $lease],
        );
        self::assertSame(0, $allowed['exit_code'], $allowed['stderr']);
    }

    public function testCspPilotAdmissionRunsUnderTheProductionLockBeforeTheAtomicSwitch(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh');
        $lock = strpos($source, "ordinary_production_change_lock \\\n");
        $admission = strpos($source, "ordinary_assert_no_active_csp_report_only_pilot \\\n");
        $switch = strrpos($source, "\nperform_atomic_switch\n");

        self::assertIsInt($lock);
        self::assertIsInt($admission);
        self::assertIsInt($switch);
        self::assertLessThan($admission, $lock);
        self::assertLessThan($switch, $admission);
    }

    private function removeOwnedTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $leaf) {
            if ($leaf === '.' || $leaf === '..') {
                continue;
            }
            $path = $directory . '/' . $leaf;
            if (is_dir($path) && !is_link($path)) {
                $this->removeOwnedTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startLockHolder(string $label, ?string $state = null, bool $session = false): array
    {
        $script = 'source ./deploy_ea.sh; ordinary_production_change_lock "$1"; ';
        if ($state !== null) {
            $script .= 'ordinary_probe_begin "$2"; ';
            if ($session) {
                $script .= 'printf synthetic > "$2/owned-session"; ';
            }
        }
        $script .= 'echo ready; read -r release; ';
        if ($state !== null) {
            if ($session) {
                $script .= 'rm -f "$2/owned-session"; ';
            }
            $script .= 'ordinary_probe_finish "$2"; ';
        }
        $process = proc_open(
            ['/bin/bash', '-c', $script, 'bash', $this->lock, $state ?? '', $label],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 5);
        $this->holders[] = ['process' => $process, 'pipes' => $pipes];
        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param list<string> $arguments @return array{exit_code:int,stdout:string,stderr:string} */
    private function runShell(string $script, array $arguments): array
    {
        $process = proc_open(
            array_merge(['/bin/bash', '-c', $script, 'bash'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $status = proc_close($process);
        return ['exit_code' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}

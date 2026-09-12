<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OrdinaryLiveProbeWrapperTest extends TestCase
{
    private string $sandbox;
    private string $bin;
    private string $log;
    private string $wrapper;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/ordinary-wrapper-test-' . bin2hex(random_bytes(8));
        $this->bin = $this->sandbox . '/bin';
        $this->log = $this->sandbox . '/commands.log';
        $this->wrapper = $this->sandbox . '/scripts/ops/run_ordinary_live_probe.sh';
        mkdir($this->bin, 0700, true);
        mkdir(dirname($this->wrapper), 0700, true);
        mkdir($this->sandbox . '/scripts/release-gate/lib', 0700, true);
        file_put_contents(
            $this->wrapper,
            str_replace(
                '/root/deploy_ea.sh',
                $this->sandbox . '/installed-deploy.sh',
                file_get_contents(__DIR__ . '/../../../scripts/ops/run_ordinary_live_probe.sh'),
            ),
        );
        $coordination = <<<'SH'
        ordinary_production_change_lock() {
            echo coordination-lock >> "$MOCK_LOG"
            [ "${MOCK_LOCK_BUSY:-0}" != 1 ] || return 75
        }
        ordinary_assert_no_pending_probe() {
            echo pending-check >> "$MOCK_LOG"
            [ "${MOCK_PENDING:-0}" != 1 ] || return 75
        }
        ordinary_probe_begin() { echo pending-begin >> "$MOCK_LOG"; }
        ordinary_probe_finish() { echo pending-finish >> "$MOCK_LOG"; }
        SH;
        file_put_contents($this->sandbox . '/deploy_ea.sh', $coordination);
        file_put_contents($this->sandbox . '/installed-deploy.sh', $coordination);
        chmod($this->wrapper, 0700);
        foreach (
            [
                'ordinary_live_probe.php',
                'GateHttpClient.php',
                'OrdinaryLiveFixture.php',
                'OrdinaryProbeSessions.php',
                'OrdinarySessionProbe.php',
                'OrdinaryAccountProbe.php',
            ]
            as $file
        ) {
            $source =
                $file === 'ordinary_live_probe.php'
                    ? __DIR__ . '/../../../scripts/ops/' . $file
                    : __DIR__ . '/../../../scripts/release-gate/lib/' . $file;
            copy(
                $source,
                $this->sandbox .
                    ($file === 'ordinary_live_probe.php' ? '/scripts/ops/' : '/scripts/release-gate/lib/') .
                    $file,
            );
        }
        mkdir($this->sandbox . '/app', 0700);
        file_put_contents($this->sandbox . '/app/original-marker', 'owned');
        file_put_contents($this->log, '');
        $this->writeMock('id', "#!/bin/sh\necho 0\n");
        $this->writeMock(
            'realpath',
            '#!/bin/sh' . PHP_EOL . 'for last do :; done' . PHP_EOL . 'printf "%s\\n" "$last"' . PHP_EOL,
        );
        $this->writeMock(
            'stat',
            <<<'SH'
            #!/bin/sh
            for last do :; done
            case "$2" in
            %u) echo 0;;
            %a) [ "${MOCK_UNSAFE:-0}" = 1 ] && echo 777 || echo 700;;
            %d:%i)
                if [ -f "$last/original-marker" ]; then echo 1:2
                elif [ "${last##*/}" = ordinary_live_probe.php ]; then
                    [ -f "$MOCK_LOG.changed-probe" ] && echo 1:6 || echo 1:5
                else echo 1:3; fi;;
            *) exit 1;;
            esac
            SH
            ,
        );
        $this->writeMock(
            'php',
            "#!/bin/sh\necho \"php \$*\" >> \"\$MOCK_LOG\"\nfor arg in \"\$@\"; do case \"\$arg\" in --action=*) action=\"\${arg#--action=}\";; esac; done\ncase \",\${MOCK_PHP_FAIL_ACTIONS:-},\" in *\",\$action,\"*) exit 42;; esac\nexit 0\n",
        );
        $this->writeMock(
            'systemctl',
            "#!/bin/sh\necho \"systemctl \$*\" >> \"\$MOCK_LOG\"\ncase \"\$1\" in show) [ \"\${MOCK_TIMER_EXISTS:-0}\" = 1 ] && echo active || echo not-found;; esac\n",
        );
        $this->writeMock(
            'systemd-run',
            <<<'SH'
            #!/bin/sh
            printf 'systemd-run armed\n' >> "$MOCK_LOG"
            if [ "${MOCK_CALLBACK_RENAME:-0}" = 1 ]; then
                mv "$APP_ROOT" "$APP_ROOT-renamed"
                mkdir "$APP_ROOT"
                if [ "${MOCK_CHANGE_PROBE:-0}" = 1 ]; then : > "$MOCK_LOG.changed-probe"; fi
                printf 'callback-start\n' >> "$MOCK_LOG"
                shift 4
                "$@"
                status=$?
                printf 'callback-exit:%s\n' "$status" >> "$MOCK_LOG"
            fi
            exit 0
            SH
            ,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->sandbox);
    }

    public function testTimerPrecedesAccountAndSessionIsOptIn(): void
    {
        $result = $this->executeWrapper('account');
        self::assertSame(0, $result['status'], $result['error'] . implode("\\n", $result['lines']));
        self::assertSame(
            ['preflight', 'activate', 'account', 'deactivate', 'verify'],
            $this->actions($result['lines']),
        );
        $timerIndex = array_search('systemd-run', $this->prefixes($result['lines']), true);
        self::assertIsInt($timerIndex);
        $activateLine = null;
        foreach ($result['lines'] as $index => $line) {
            if (str_starts_with($line, 'php ') && str_contains($line, '--action=activate')) {
                $activateLine = $index;
                break;
            }
        }
        self::assertIsInt($activateLine);
        self::assertLessThan($activateLine, $timerIndex);
        self::assertNotContains('session', $this->actions($result['lines']));
    }

    public function testSessionActionRunsOnlyAfterAccount(): void
    {
        $result = $this->executeWrapper('session');
        self::assertSame(0, $result['status']);
        self::assertSame(
            ['preflight', 'activate', 'account', 'session', 'deactivate', 'verify'],
            $this->actions($result['lines']),
        );
    }

    public function testActivationFailureCompensatesAndRetainsTimerOnCompensationFailure(): void
    {
        $result = $this->executeWrapper('account', ['MOCK_PHP_FAIL_ACTIONS' => 'activate,deactivate']);
        self::assertSame(1, $result['status']);
        self::assertSame(['preflight', 'activate', 'deactivate'], $this->actions($result['lines']));
        self::assertContains('systemd-run', $this->prefixes($result['lines']));
        self::assertNotContains('systemctl stop', $result['lines']);
    }

    public function testExistingTimerRefusesMutation(): void
    {
        $result = $this->executeWrapper('account', ['MOCK_TIMER_EXISTS' => '1']);
        self::assertSame(75, $result['status']);
        self::assertNotEmpty($result['lines']);
        self::assertNotContains('php', $this->prefixes($result['lines']));
        self::assertNotContains('systemd-run', $this->prefixes($result['lines']));
    }

    public function testBusySharedLockAndPendingRecoveryPreventAnyProbe(): void
    {
        foreach ([['MOCK_LOCK_BUSY' => '1'], ['MOCK_PENDING' => '1']] as $environment) {
            file_put_contents($this->log, '');
            $result = $this->executeWrapper('account', $environment);
            self::assertSame(75, $result['status']);
            self::assertSame([], $this->actions($result['lines']));
            self::assertNotContains('systemd-run', $this->prefixes($result['lines']));
        }
    }

    public function testFailedProbeRetainsPersistentMarkerDespiteKnownObjectCleanup(): void
    {
        $result = $this->executeWrapper('account', ['MOCK_PHP_FAIL_ACTIONS' => 'account']);
        self::assertSame(42, $result['status']);
        self::assertContains('pending-begin', $result['lines']);
        self::assertContains('deactivate', $this->actions($result['lines']));
        self::assertNotContains('pending-finish', $result['lines']);
    }

    public function testFailedPreflightDoesNotArmTimerOrActivateIdentity(): void
    {
        $result = $this->executeWrapper('account', ['MOCK_PHP_FAIL_ACTIONS' => 'preflight']);
        self::assertSame(42, $result['status']);
        self::assertSame(['preflight'], $this->actions($result['lines']));
        self::assertNotContains('systemd-run', $this->prefixes($result['lines']));
    }

    public function testUnsafeRootGuardStopsBeforePhpOrSystemd(): void
    {
        $result = $this->executeWrapper('account', ['MOCK_UNSAFE' => '1']);
        self::assertSame(77, $result['status']);
        self::assertSame([], $result['lines']);
    }

    public function testCleanupCallbackUsesRenamedOriginalAndPinnedProbeIdentity(): void
    {
        $result = $this->executeWrapper('account', ['MOCK_CALLBACK_RENAME' => '1']);
        self::assertSame(1, $result['status']);
        self::assertNotContains('activate', $this->actions($result['lines']));
        self::assertNotContains('account', $this->actions($result['lines']));
        $deactivations = array_values(
            array_filter(
                $result['lines'],
                static fn(string $line): bool => str_starts_with($line, 'php ') &&
                    str_contains($line, '--action=deactivate'),
            ),
        );
        self::assertContains('callback-exit:0', $result['lines']);
        self::assertNotEmpty($deactivations);
        self::assertStringContainsString($this->sandbox . '/app-renamed', $deactivations[0]);
        self::assertStringContainsString('ordinary_live_probe.php', $deactivations[0]);
    }

    public function testChangedProbeInodePreventsCallbackAndRetainsTimer(): void
    {
        $result = $this->executeWrapper('account', ['MOCK_CALLBACK_RENAME' => '1', 'MOCK_CHANGE_PROBE' => '1']);
        self::assertSame(1, $result['status']);
        self::assertContains('callback-exit:1', $result['lines']);
        self::assertSame(['preflight'], $this->actions($result['lines']));
        self::assertFalse(
            (bool) array_filter(
                $result['lines'],
                static fn(string $line): bool => str_starts_with($line, 'systemctl stop'),
            ),
        );
    }

    /** @param array<string,string> $extra @return array{status:int,lines:list<string>,error:string} */
    private function executeWrapper(string $action, array $extra = []): array
    {
        $env = array_merge(
            getenv(),
            ['PATH' => $this->bin . ':/usr/bin:/bin', 'MOCK_LOG' => $this->log, 'APP_ROOT' => $this->sandbox . '/app'],
            $extra,
        );
        $process = proc_open(
            ['/bin/bash', $this->wrapper, $action, 'ea_test_release'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->sandbox,
            $env,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $lines = file($this->log, FILE_IGNORE_NEW_LINES) ?: [];
        if ($status !== 0 && $error !== '') {
            $lines[] = 'STDERR ' . trim($error);
        }
        return ['status' => $status, 'lines' => $lines, 'error' => $error];
    }

    private function writeMock(string $name, string $body): void
    {
        file_put_contents($this->bin . '/' . $name, $body);
        chmod($this->bin . '/' . $name, 0700);
    }
    /** @param list<string> $lines @return list<string> */
    private function actions(array $lines): array
    {
        return array_values(
            array_filter(
                array_map(static function (string $line): string {
                    if (!str_starts_with($line, 'php ')) {
                        return '';
                    }
                    preg_match('/--action=([^ ]+)/', $line, $match);
                    return $match[1] ?? '';
                }, $lines),
            ),
        );
    }
    /** @param list<string> $lines @return list<string> */
    private function prefixes(array $lines): array
    {
        return array_map(static fn(string $line): string => explode(' ', $line, 2)[0], $lines);
    }
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}

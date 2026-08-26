<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class KumaMonitoringEnvV1Test extends TestCase
{
    private const KEY = 'KUMA_RELEASE_RETENTION_MONITOR_ENABLED';

    private string $root;
    private string $helper;
    private string $envPath;
    private string $state;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rob490-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/root/backups', 0700, true);
        mkdir($this->root . '/var/lib', 0700, true);
        mkdir($this->root . '/run', 0700, true);
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/kuma_monitoring_env_v1.py';
        $this->envPath = $this->root . '/root/backups/uptime-kuma-push.env';
        $this->state = $this->root . '/var/lib/fh-kuma-monitoring-v1';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testDryRunForMissingAndDisabledKeyIsStrictlyMutationFree(): void
    {
        foreach (["OTHER=x\n", self::KEY . "=0\n"] as $env) {
            $this->writeEnv($env);
            $before = $this->snapshot();
            $result = $this->runHelper();

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame($before, $this->snapshot());
            $json = $this->json($result['stdout']);
            self::assertSame('pass', $json['status'] ?? null);
            self::assertSame('would_enable', $json['monitoring_state'] ?? null);
            self::assertSame('absent', $json['recovery_state'] ?? null);
            self::assertFalse($json['mutation_performed'] ?? true);
            unlink($this->envPath);
        }
    }

    public function testKeyAppendThatWouldExceedMaximumFailsBeforeMutation(): void
    {
        $this->writeEnv(str_repeat('A', 4_000_000));
        $before = $this->snapshot();

        $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

        self::assertSame(70, $result['exit_code']);
        self::assertSame($before, $this->snapshot());
        $json = $this->json($result['stdout']);
        self::assertSame('desired_env_too_large', $json['reason'] ?? null);
        self::assertFalse($json['mutation_performed'] ?? true);
        self::assertSame('not_required', $json['rollback_outcome'] ?? null);
    }

    public function testEnabledDryRunWithoutRecoveryFailsWithoutMutation(): void
    {
        $this->writeEnv(self::KEY . "=1\n");
        $before = $this->snapshot();
        $result = $this->runHelper();

        self::assertSame(70, $result['exit_code']);
        self::assertSame($before, $this->snapshot());
        self::assertSame('recovery_missing', $this->json($result['stdout'])['reason'] ?? null);
    }

    public function testExecuteAppendsOnlyTheContractLineAndIsIdempotent(): void
    {
        $original = "SECRET_TOKEN=do-not-print\r\nA=1";
        $expected = $original . "\n" . self::KEY . "=1\n";
        $this->writeEnv($original);

        $run = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $run['exit_code'], $run['stderr']);
        self::assertSame($expected, file_get_contents($this->envPath));
        $json = $this->json($run['stdout']);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame('not_required', $json['rollback_outcome'] ?? null);
        self::assertStringNotContainsString('do-not-print', $run['stdout'] . $run['stderr']);

        $before = $this->snapshot();
        $again = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $again['exit_code'], $again['stderr']);
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->json($again['stdout'])['mutation_performed'] ?? true);
    }

    public function testZeroToOneChangesExactlyOneByteAndPreservesUnrelatedBytes(): void
    {
        $original = "UTF8=grü\r\n" . self::KEY . "=0\nTAIL=yes";
        $expected = "UTF8=grü\r\n" . self::KEY . "=1\nTAIL=yes";
        $this->writeEnv($original);

        $run = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $run['exit_code'], $run['stderr']);
        self::assertSame($expected, file_get_contents($this->envPath));
        $differences = 0;
        for ($index = 0; $index < strlen($original); $index++) {
            $differences += $original[$index] === $expected[$index] ? 0 : 1;
        }
        self::assertSame(1, $differences);
    }

    public function testShellAmbiguousLineBoundariesAndAppendContinuationFailClosed(): void
    {
        foreach (
            [
                "X=prefix\v" . self::KEY . "=0\n",
                "X=prefix\f" . self::KEY . "=0\n",
                "X=prefix\u{0085}" . self::KEY . "=0\n",
                self::KEY . "=0\r\n",
            ]
            as $contents
        ) {
            $this->writeEnv($contents);
            $before = $this->snapshot();
            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code']);
            self::assertSame('definition_ambiguous', $this->json($result['stdout'])['reason'] ?? null);
            self::assertSame($before, $this->snapshot());
        }

        foreach (['X=value\\', "X=value\\\n"] as $contents) {
            $this->writeEnv($contents);
            $before = $this->snapshot();
            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code']);
            self::assertSame('append_context_invalid', $this->json($result['stdout'])['reason'] ?? null);
            self::assertSame($before, $this->snapshot());
        }

        foreach (["true ||\n", "true &&\n", "printf value |\n", "return 0\n"] as $contents) {
            $this->writeEnv($contents);
            $before = $this->snapshot();
            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code'], $contents);
            self::assertSame('env_shell_context_invalid', $this->json($result['stdout'])['reason'] ?? null);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testTargetDefinitionInsideBashCompoundContextFailsClosed(): void
    {
        foreach (
            [
                "X='foo\n" . self::KEY . "=0\nbar'\n",
                'X="foo' . "\n" . self::KEY . '=0' . "\n" . 'bar"' . "\n",
                "cat <<'ENV_VALUE'\n" . self::KEY . "=0\nENV_VALUE\n",
                "cat <<'E'OF\n" . self::KEY . "=0\nEOF\n",
                "configure() {\n" . self::KEY . "=0\n}\n",
                "X=$(\n" . self::KEY . "=0\n)\n",
                "if true; then\n" . self::KEY . "=0\nfi\n",
                "[[\n" . self::KEY . "=0\n]]\n",
            ]
            as $contents
        ) {
            $this->writeEnv($contents);
            $before = $this->snapshot();
            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code'], $contents);
            self::assertSame('definition_ambiguous', $this->json($result['stdout'])['reason'] ?? null);
            self::assertSame($before, $this->snapshot());
        }

        $original = "X='foo\nbar'\n";
        $this->writeEnv($original);
        $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame($original . self::KEY . "=1\n", file_get_contents($this->envPath));
    }

    public function testAssignmentPrefixedEarlyControlTransfersFailClosedBeforeMutation(): void
    {
        foreach (["X=1 return 0\n", "X=1 exit 0\n", "X=1 exec true\n"] as $contents) {
            $this->writeEnv($contents);
            $before = $this->snapshot();
            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code'], $contents);
            self::assertSame('env_shell_context_invalid', $this->json($result['stdout'])['reason'] ?? null);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testQuotedBackslashContinuedEarlyControlsFailClosedBeforeMutation(): void
    {
        foreach (
            [
                '"ret\\' . "\n" . 'urn" 0' . "\n",
                '"ex\\' . "\n" . 'it" 0' . "\n",
                '"ex\\' . "\n" . 'ec" true' . "\n",
                'command "ret\\' . "\n" . 'urn" 0' . "\n",
                'builtin "ret\\' . "\n" . 'urn" 0' . "\n",
            ]
            as $contents
        ) {
            $this->writeEnv($contents);
            $before = $this->snapshot();

            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code'], $contents);
            self::assertSame('env_shell_context_invalid', $this->json($result['stdout'])['reason'] ?? null);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testMultilineLegacyArithmeticContextFailsClosedBeforeMutation(): void
    {
        foreach (['$[1+' . "\n" . self::KEY . "=0\n]\n", 'X=$[array[' . "\n" . self::KEY . "=0\n]]\n"] as $contents) {
            $this->writeEnv($contents);
            $before = $this->snapshot();

            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code'], $contents);
            $json = $this->json($result['stdout']);
            self::assertSame('definition_ambiguous', $json['reason'] ?? null);
            self::assertFalse($json['mutation_performed'] ?? true);
            self::assertSame($before, $this->snapshot());
        }

        $this->writeEnv('X=$[array[0]]' . "\n");
        $before = $this->snapshot();
        $result = $this->runHelper();

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('would_enable', $this->json($result['stdout'])['monitoring_state'] ?? null);
        self::assertSame($before, $this->snapshot());
    }

    public function testKeylessBuiltinAndCommandReturnContextsFailClosedBeforeAppend(): void
    {
        foreach (
            [
                "builtin return 0\n",
                "command return 0\n",
                "builtin 2>/dev/null return 0\n",
                "command -p 2>/dev/null return 0\n",
                "builtin > \"a b\" return 0\n",
                "command -p > 'a b' return 0\n",
                "builtin >$(printf value) return 0\n",
                "command -p >$((1 + 2)) return 0\n",
                "builtin > >(printf value) return 0\n",
                "command -p > >(printf 'a b') return 0\n",
                "builtin >\"a\nb\" return 0\n",
                "command -p >$((1 +\n2)) return 0\n",
                'builtin >a\\' . "\n" . "b return 0\n",
                'command -p >path\\' . "\n" . "withx -p return 0\n",
                'builtin >a\\' . "\n" . "#x return 0\n",
                'command -p >path\\' . "\n" . "#x return 0\n",
                "command -- return 0\n",
                "command 2>/dev/null -- return 0\n",
                "SECRET_TOKEN=do-not-print builtin -- return 0\n",
                "SECRET_TOKEN=do-not-print command -p return 0\n",
                "command builtin return 0\n",
            ]
            as $contents
        ) {
            $this->writeEnv($contents);
            $before = $this->snapshot();

            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code'], $contents);
            $json = $this->json($result['stdout']);
            self::assertSame('env_shell_context_invalid', $json['reason'] ?? null);
            self::assertFalse($json['mutation_performed'] ?? true);
            self::assertSame($before, $this->snapshot());
            self::assertStringNotContainsString('do-not-print', $result['stdout'] . $result['stderr']);
        }
    }

    public function testCommandQueriesAndOrdinaryRedirectionsRemainValidDryRuns(): void
    {
        foreach (
            [
                "command -v return\n",
                "command -V exit\n",
                "command -pv exec\n",
                "command 2>/dev/null -v return\n",
                "command >\"a b\" -V exit\n",
                "command >$(printf value) -V exit\n",
                "command >$((1 + 2)) -v return\n",
                "command > >(printf value) -v return\n",
                'command >path\\' . "\n" . "#x -v return\n",
                "command -v if\n",
                "command -V while\n",
                "command -pv done\n",
                "command 2>/dev/null -v fi\n",
                "command -p case\n",
                "builtin esac\n",
                "command -- -v return\n",
                "command 2>/dev/null -- -V exit\n",
                "printf value 2>/dev/null\n",
            ]
            as $contents
        ) {
            $this->writeEnv($contents);
            $before = $this->snapshot();

            $result = $this->runHelper();

            self::assertSame(0, $result['exit_code'], $contents);
            $json = $this->json($result['stdout']);
            self::assertSame('pass', $json['status'] ?? null);
            self::assertSame('would_enable', $json['monitoring_state'] ?? null);
            self::assertFalse($json['mutation_performed'] ?? true);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testCommandNamesBeginningWithControlWordsRemainOrdinaryDryRuns(): void
    {
        foreach (
            [
                "command return-this\n",
                "command exit.status\n",
                'command "ret\\' . "\n" . 'urn-this"' . "\n",
                '"ret\\urn" || true' . "\n",
                'command "ret\\urn" || true' . "\n",
            ]
            as $contents
        ) {
            $this->writeEnv($contents);
            $before = $this->snapshot();

            $result = $this->runHelper();

            self::assertSame(0, $result['exit_code'], $contents);
            $json = $this->json($result['stdout']);
            self::assertSame('pass', $json['status'] ?? null);
            self::assertSame('would_enable', $json['monitoring_state'] ?? null);
            self::assertFalse($json['mutation_performed'] ?? true);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testUnmatchedShellDelimitersFailClosedBeforeMutation(): void
    {
        foreach (["X=1 )\n", "X=1 }\n", self::KEY . "=0\n)\n", self::KEY . "=0\n}\n"] as $contents) {
            $this->writeEnv($contents);
            $before = $this->snapshot();
            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code'], $contents);
            self::assertSame('env_shell_context_invalid', $this->json($result['stdout'])['reason'] ?? null);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testCanonicalLockHeldByAnotherWriterFailsWithoutMutation(): void
    {
        $this->writeEnv(self::KEY . "=0\n");
        $lock = $this->root . '/run/fh-kuma-monitoring-v1.lock';
        file_put_contents($lock, '');
        chmod($lock, 0600);
        $handle = fopen($lock, 'c');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $before = $this->snapshot();

        $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('lock_busy', $json['reason'] ?? null);
        self::assertFalse($json['mutation_performed'] ?? true);
        self::assertSame($before, $this->snapshot());
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function testCanonicalLockCreationIsReportedAndThenConverges(): void
    {
        $original = self::KEY . "=0\n";
        $enabled = self::KEY . "=1\n";
        $this->writeEnv($enabled);
        $this->writeRecovery($original, $enabled);

        $first = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

        self::assertSame(0, $first['exit_code'], $first['stderr']);
        self::assertTrue($this->json($first['stdout'])['mutation_performed'] ?? false);
        self::assertSame($enabled, file_get_contents($this->envPath));
        self::assertFileExists($this->root . '/run/fh-kuma-monitoring-v1.lock');
        $afterFirst = $this->snapshot();

        $second = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

        self::assertSame(0, $second['exit_code'], $second['stderr']);
        self::assertFalse($this->json($second['stdout'])['mutation_performed'] ?? true);
        self::assertSame($afterFirst, $this->snapshot());
    }

    public function testWriterAuthorityManifestBindsTheCompleteRuntimeContract(): void
    {
        $repository = dirname(__DIR__, 3);
        $manifestPath = $repository . '/scripts/ops/config/kuma_monitoring_env_writer_authority.v1.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('fh_kuma_monitoring_env_writer_authority.v1', $manifest['schema'] ?? null);
        self::assertSame('/root/backups/uptime-kuma-push.env', $manifest['env_path'] ?? null);
        self::assertSame('/run/fh-kuma-monitoring-v1.lock', $manifest['lock_path'] ?? null);
        self::assertSame(
            [
                'coordination' => 'flock-exclusive',
                'file_type' => 'regular',
                'gid' => 0,
                'mode' => '0600',
                'nlink' => 1,
                'uid' => 0,
            ],
            $manifest['lock_contract'] ?? null,
        );
        self::assertSame(
            [
                [
                    'confirmation' => 'ROB-490',
                    'id' => 'rob-490-monitoring-activation',
                    'installed_path' => '/usr/local/libexec/fh-kuma-monitoring-env-v1',
                    'repository_path' => 'scripts/ops/libexec/kuma_monitoring_env_v1.py',
                ],
            ],
            $manifest['supported_post_bootstrap_writers'] ?? null,
        );
        self::assertSame(
            [
                'manual_post_bootstrap_writes_supported' => false,
                'secret_population_phase' => 'pre-authority',
            ],
            $manifest['bootstrap'] ?? null,
        );

        $helper = (string) file_get_contents($repository . '/scripts/ops/libexec/kuma_monitoring_env_v1.py');
        $runbook = (string) file_get_contents($repository . '/docs/ops/production-kuma-monitoring-env.md');
        self::assertStringContainsString("ENV_PATH = '" . $manifest['env_path'] . "'", $helper);
        self::assertStringContainsString("LOCK_PATH = '" . $manifest['lock_path'] . "'", $helper);
        self::assertStringContainsString("CONFIRMATION = 'ROB-490'", $helper);
        self::assertStringContainsString('stat.S_IMODE(opened.st_mode) != 0o600', $helper);
        self::assertStringContainsString('opened.st_nlink != 1', $helper);
        self::assertStringContainsString('kuma_monitoring_env_writer_authority.v1.json', $runbook);
    }

    public function testInvalidCanonicalLockContractFailsWithoutMutation(): void
    {
        $this->writeEnv(self::KEY . "=0\n");
        $lock = $this->root . '/run/fh-kuma-monitoring-v1.lock';
        mkdir($lock, 0700);
        $before = $this->snapshot();

        $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('lock_invalid', $json['reason'] ?? null);
        self::assertFalse($json['mutation_performed'] ?? true);
        self::assertSame($before, $this->snapshot());
    }

    public function testWrongCanonicalLockModeAndOwnerFailWithoutMutation(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            self::markTestSkipped('Owner fixture requires root in the isolated test container.');
        }

        foreach ([0644, 0600] as $mode) {
            $this->writeEnv(self::KEY . "=0\n");
            $lock = $this->root . '/run/fh-kuma-monitoring-v1.lock';
            file_put_contents($lock, '');
            chmod($lock, $mode);
            if ($mode === 0600) {
                chown($lock, 65534);
            }
            $before = $this->snapshot();

            $result = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

            self::assertSame(70, $result['exit_code']);
            self::assertSame('lock_invalid', $this->json($result['stdout'])['reason'] ?? null);
            self::assertSame($before, $this->snapshot());
            unlink($lock);
        }
    }

    public function testCanonicalLockSymlinkAndHardlinkFailWithoutMutation(): void
    {
        $this->writeEnv(self::KEY . "=0\n");
        $lock = $this->root . '/run/fh-kuma-monitoring-v1.lock';
        $target = $this->root . '/run/foreign-lock';
        file_put_contents($target, '');
        chmod($target, 0600);
        symlink($target, $lock);
        $beforeSymlink = $this->snapshot();

        $symlink = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

        self::assertSame(70, $symlink['exit_code']);
        self::assertSame('lock_invalid', $this->json($symlink['stdout'])['reason'] ?? null);
        self::assertSame($beforeSymlink, $this->snapshot());
        unlink($lock);

        link($target, $lock);
        $beforeHardlink = $this->snapshot();
        $hardlink = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);

        self::assertSame(70, $hardlink['exit_code']);
        self::assertSame('lock_invalid', $this->json($hardlink['stdout'])['reason'] ?? null);
        self::assertSame($beforeHardlink, $this->snapshot());
    }

    public function testConfirmationAndAmbiguousDefinitionsFailClosed(): void
    {
        $this->writeEnv("SECRET_TOKEN=do-not-print\n");
        $confirmationOnly = $this->runHelper(['--confirm-live-write', 'ROB-490']);
        self::assertSame(70, $confirmationOnly['exit_code']);
        self::assertSame('confirmation_without_execute', $this->json($confirmationOnly['stdout'])['reason'] ?? null);

        $wrongIssue = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-488']);
        self::assertSame(70, $wrongIssue['exit_code']);
        self::assertSame('confirmation_invalid', $this->json($wrongIssue['stdout'])['reason'] ?? null);

        foreach (
            [
                self::KEY . "=0\n" . self::KEY . "=1\n",
                '# ' . self::KEY . "=0\n",
                self::KEY . "=2\n",
                'PREFIX_' . self::KEY . "=0\n",
                'X=' . self::KEY . "=0\n",
            ]
            as $contents
        ) {
            $this->writeEnv($contents);
            $result = $this->runHelper();
            self::assertSame(70, $result['exit_code'], $contents);
            self::assertFalse($this->json($result['stdout'])['mutation_performed'] ?? true);
        }
        self::assertStringNotContainsString('do-not-print', $confirmationOnly['stdout'] . $confirmationOnly['stderr']);
    }

    public function testInvalidUtf8AndUnsafeFileContractsFailClosed(): void
    {
        $this->writeEnv("A=\xff\n");
        self::assertSame('env_invalid_utf8', $this->failureReason());

        $this->writeEnv("A=1\n");
        chmod($this->envPath, 0644);
        self::assertSame('env_contract_invalid', $this->failureReason());

        chmod($this->envPath, 0600);
        $hardlink = $this->envPath . '.hardlink';
        link($this->envPath, $hardlink);
        self::assertSame('env_contract_invalid', $this->failureReason());
        unlink($hardlink);

        unlink($this->envPath);
        symlink('/dev/null', $this->envPath);
        self::assertSame('env_contract_invalid', $this->failureReason());
    }

    public function testExactLegacyRecoveryIsAdoptedWithoutRewrite(): void
    {
        $original = self::KEY . "=0\nSECRET_TOKEN=never-print\n";
        $desired = self::KEY . "=1\nSECRET_TOKEN=never-print\n";
        $this->writeEnv($original);
        $this->writeRecovery($original, $desired, 'ROB-488', 'legacy-original.env', 'legacy-recovery.json');
        $evidenceBefore = $this->recoverySnapshot();

        $inspect = $this->runHelper();
        self::assertSame(0, $inspect['exit_code'], $inspect['stderr']);
        self::assertSame('intact', $this->json($inspect['stdout'])['recovery_state'] ?? null);
        self::assertSame($evidenceBefore, $this->recoverySnapshot());

        $execute = $this->runHelper(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $execute['exit_code'], $execute['stderr']);
        self::assertSame($desired, file_get_contents($this->envPath));
        self::assertSame($evidenceBefore, $this->recoverySnapshot());
        self::assertStringNotContainsString('never-print', $execute['stdout'] . $execute['stderr']);
    }

    public function testRecoveryMissingExtraTamperedMismatchedAndSymlinkEvidenceFailClosed(): void
    {
        $original = self::KEY . "=0\n";
        $desired = self::KEY . "=1\n";
        $this->writeEnv($original);
        $this->writeRecovery($original, $desired);

        file_put_contents($this->state . '/extra', "extra\n");
        chmod($this->state . '/extra', 0600);
        self::assertSame('recovery_invalid', $this->failureReason());
        unlink($this->state . '/extra');

        file_put_contents($this->state . '/recovery.json', "{}\n");
        chmod($this->state . '/recovery.json', 0600);
        self::assertSame('recovery_invalid', $this->failureReason());

        $this->removeTree($this->state);
        $this->writeRecovery($original, $desired);
        file_put_contents($this->state . '/original.env', "different\n");
        chmod($this->state . '/original.env', 0600);
        self::assertSame('recovery_mismatch', $this->failureReason());

        $this->removeTree($this->state);
        $this->writeRecovery($original, $desired);
        unlink($this->state . '/recovery.json');
        symlink($this->state . '/original.env', $this->state . '/recovery.json');
        self::assertSame('recovery_contract_invalid', $this->failureReason());

        $this->removeTree($this->state);
        $this->writeEnv($desired);
        self::assertSame('recovery_missing', $this->failureReason());
    }

    public function testRecoveryPublicationIsNoClobberUnderExactAndForeignRace(): void
    {
        $original = self::KEY . "=0\n";
        $this->writeEnv($original);
        $exact = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_RECOVERY_PUBLISH_RACE' => 'exact'],
        );
        self::assertSame(0, $exact['exit_code'], $exact['stderr']);
        self::assertSame(self::KEY . "=1\n", file_get_contents($this->envPath));
        self::assertFileExists($this->state . '/legacy.before');
        self::assertFileDoesNotExist($this->state . '/rob-488-env.before');

        $this->removeTree($this->state);
        $this->writeEnv($original);
        $foreign = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_RECOVERY_PUBLISH_RACE' => 'foreign'],
        );
        self::assertSame(70, $foreign['exit_code']);
        self::assertSame($original, file_get_contents($this->envPath));
        self::assertSame("foreign\n", file_get_contents($this->state . '/foreign'));
        self::assertSame([], glob($this->root . '/var/lib/.fh-kuma-monitoring-v1.pending-*'));
    }

    public function testConcurrentWriterBeforeExchangeIsNotOverwrittenOrDeleted(): void
    {
        $this->writeEnv(self::KEY . "=0\n");
        $result = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_CONCURRENT_BEFORE_EXCHANGE' => '1'],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('env_changed', $json['reason'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false, 'Recovery publication is a real mutation.');
        self::assertSame("FOREIGN_PRE_EXCHANGE_WRITER=1\n", file_get_contents($this->envPath));
        self::assertSame([], glob($this->root . '/root/backups/.fh-kuma-monitoring-env-v1.pending-*'));
    }

    public function testPostExchangeFailureRollsBackExactOriginal(): void
    {
        $original = self::KEY . "=0\nSECRET=remain-hidden\n";
        $this->writeEnv($original);
        $result = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_FAIL_AFTER_EXCHANGE' => '1'],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('test_failure_after_exchange', $json['reason'] ?? null);
        self::assertSame('succeeded', $json['rollback_outcome'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame($original, file_get_contents($this->envPath));
        self::assertSame([], glob($this->root . '/root/backups/.fh-kuma-monitoring-env-v1.pending-*'));
        self::assertStringNotContainsString('remain-hidden', $result['stdout'] . $result['stderr']);
    }

    public function testUnexpectedPostExchangeFailureRollsBackExactOriginal(): void
    {
        $original = self::KEY . "=0\nSECRET=remain-hidden\n";
        $this->writeEnv($original);
        $result = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_FAIL_UNEXPECTED_AFTER_EXCHANGE' => '1'],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('execution_failed', $json['reason'] ?? null);
        self::assertSame('succeeded', $json['rollback_outcome'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame($original, file_get_contents($this->envPath));
        self::assertSame([], glob($this->root . '/root/backups/.fh-kuma-monitoring-env-v1.pending-*'));
        self::assertStringNotContainsString('remain-hidden', $result['stdout'] . $result['stderr']);
    }

    public function testConcurrentWriterDuringRestoreCausesFailedRollbackAndPreservesForeignEvidence(): void
    {
        $this->writeEnv(self::KEY . "=0\n");
        $result = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            [
                'FH_KUMA_MONITORING_TEST_FAIL_AFTER_EXCHANGE' => '1',
                'FH_KUMA_MONITORING_TEST_CONCURRENT_DURING_RESTORE' => '1',
            ],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('rollback_failed', $json['reason'] ?? null);
        self::assertSame('failed', $json['rollback_outcome'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame("FOREIGN_RESTORE_WRITER=1\n", file_get_contents($this->envPath));
        $pending = glob($this->root . '/root/backups/.fh-kuma-monitoring-env-v1.pending-*');
        self::assertCount(1, $pending);
        self::assertSame(self::KEY . "=0\n", file_get_contents($pending[0]));
    }

    public function testConcurrentPendingWriterCannotBecomeRollbackAuthority(): void
    {
        $this->writeEnv(self::KEY . "=0\n");
        $result = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_CONCURRENT_PENDING_AFTER_EXCHANGE' => '1'],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('rollback_failed', $json['reason'] ?? null);
        self::assertSame('failed', $json['rollback_outcome'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame(self::KEY . "=1\n", file_get_contents($this->envPath));
        $pending = glob($this->root . '/root/backups/.fh-kuma-monitoring-env-v1.pending-*');
        self::assertCount(1, $pending);
        self::assertSame("FOREIGN_PENDING_WRITER=1\n", file_get_contents($pending[0]));
    }

    public function testExchangeFailureCleansExactPendingBeforeReporting(): void
    {
        $original = self::KEY . "=0\n";
        $desired = self::KEY . "=1\n";
        $this->writeEnv($original);
        $this->writeRecovery($original, $desired);
        $lock = $this->root . '/run/fh-kuma-monitoring-v1.lock';
        file_put_contents($lock, '');
        chmod($lock, 0600);
        $recoveryBefore = $this->recoverySnapshot();
        $result = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_FAIL_EXCHANGE' => '1'],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('execution_failed', $json['reason'] ?? null);
        self::assertFalse($json['mutation_performed'] ?? true);
        self::assertSame($original, file_get_contents($this->envPath));
        self::assertSame($recoveryBefore, $this->recoverySnapshot());
        self::assertSame([], glob($this->root . '/root/backups/.fh-kuma-monitoring-env-v1.pending-*'));
    }

    public function testLockDriftBeforePendingUnlinkFailsClosedAndRetainsEvidence(): void
    {
        $original = self::KEY . "=0\n";
        $desired = self::KEY . "=1\n";
        $this->writeEnv($original);
        $this->writeRecovery($original, $desired);
        $result = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            [
                'FH_KUMA_MONITORING_TEST_FAIL_EXCHANGE' => '1',
                'FH_KUMA_MONITORING_TEST_REPLACE_LOCK_BEFORE_PENDING_UNLINK' => '1',
            ],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('lock_invalid', $json['reason'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame('failed', $json['rollback_outcome'] ?? null);
        self::assertSame($original, file_get_contents($this->envPath));
        $pending = glob($this->root . '/root/backups/.fh-kuma-monitoring-env-v1.pending-*');
        self::assertCount(1, $pending);
        self::assertSame($desired, file_get_contents($pending[0]));
    }

    public function testRecoveryDriftBeforeOriginalUnlinkTriggersGuardedRollback(): void
    {
        $original = self::KEY . "=0\n";
        $this->writeEnv($original);
        $result = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_TAMPER_RECOVERY_BEFORE_UNLINK' => '1'],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('recovery_invalid', $json['reason'] ?? null);
        self::assertSame('succeeded', $json['rollback_outcome'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame($original, file_get_contents($this->envPath));
        self::assertSame("{}\n", file_get_contents($this->state . '/rob-490-recovery.json'));
        self::assertSame([], glob($this->root . '/root/backups/.fh-kuma-monitoring-env-v1.pending-*'));
    }

    public function testDurabilityFailureRollsBackBeforeUnlinkAndFinalFailureIsTruthful(): void
    {
        $original = self::KEY . "=0\n";
        $this->writeEnv($original);
        $beforeUnlink = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_FAIL_ENV_DURABILITY' => '1'],
        );
        self::assertSame(70, $beforeUnlink['exit_code']);
        self::assertSame('succeeded', $this->json($beforeUnlink['stdout'])['rollback_outcome'] ?? null);
        self::assertSame($original, file_get_contents($this->envPath));

        $afterUnlink = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_FAIL_FINAL_DURABILITY' => '1'],
        );
        self::assertSame(70, $afterUnlink['exit_code']);
        $json = $this->json($afterUnlink['stdout']);
        self::assertSame('final_durability_unknown', $json['reason'] ?? null);
        self::assertSame('failed', $json['rollback_outcome'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame(self::KEY . "=1\n", file_get_contents($this->envPath));
    }

    public function testRecoveryAndPostflightUnknownResultsRemainTruthful(): void
    {
        $original = self::KEY . "=0\n";
        $this->writeEnv($original);
        $recoveryFailure = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_FAIL_RECOVERY_DURABILITY' => '1'],
        );
        self::assertSame(70, $recoveryFailure['exit_code']);
        $recoveryJson = $this->json($recoveryFailure['stdout']);
        self::assertSame('recovery_durability_unknown', $recoveryJson['reason'] ?? null);
        self::assertSame('failed', $recoveryJson['rollback_outcome'] ?? null);
        self::assertTrue($recoveryJson['mutation_performed'] ?? false);
        self::assertSame($original, file_get_contents($this->envPath));
        self::assertDirectoryExists($this->state);

        $this->removeTree($this->state);
        $this->writeEnv($original);
        $postflightFailure = $this->runHelper(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_TEST_FAIL_POSTFLIGHT' => '1'],
        );
        self::assertSame(70, $postflightFailure['exit_code']);
        $postflightJson = $this->json($postflightFailure['stdout']);
        self::assertSame('postflight_unknown', $postflightJson['reason'] ?? null);
        self::assertSame('failed', $postflightJson['rollback_outcome'] ?? null);
        self::assertTrue($postflightJson['mutation_performed'] ?? false);
        self::assertSame(self::KEY . "=1\n", file_get_contents($this->envPath));
    }

    public function testLinuxRenameExchangeChangesOnlyCtimeForBothInodes(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Native Linux renameat2 metadata proof runs in Docker and GitHub Linux.');
        }
        $script = <<<'PY'
        import ctypes,json,os,tempfile
        fields=('st_dev','st_ino','st_mode','st_uid','st_gid','st_nlink','st_size','st_mtime_ns','st_ctime_ns')
        with tempfile.TemporaryDirectory() as root:
            left=os.path.join(root,'left'); right=os.path.join(root,'right')
            open(left,'wb').write(b'original'); open(right,'wb').write(b'replacement')
            left_before=os.lstat(left); right_before=os.lstat(right)
            fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
            libc=ctypes.CDLL(None,use_errno=True); result=libc.renameat2(fd,b'left',fd,b'right',2); os.close(fd)
            assert result == 0, ctypes.get_errno()
            changed=lambda before,after:[name for name in fields if getattr(before,name)!=getattr(after,name)]
            print(json.dumps({'original':changed(left_before,os.lstat(right)),'replacement':changed(right_before,os.lstat(left))},sort_keys=True))
        PY;
        $result = $this->runCommand(['python3', '-c', $script]);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $json = $this->json($result['stdout']);
        self::assertSame(['st_ctime_ns'], $json['original'] ?? null);
        self::assertSame(['st_ctime_ns'], $json['replacement'] ?? null);
    }

    private function writeEnv(string $contents): void
    {
        if (is_link($this->envPath)) {
            unlink($this->envPath);
        }
        file_put_contents($this->envPath, $contents);
        chmod($this->envPath, 0600);
    }

    private function writeRecovery(
        string $original,
        string $desired,
        string $issue = 'ROB-490',
        string $originalLeaf = 'original.env',
        string $evidenceLeaf = 'recovery.json',
    ): void {
        mkdir($this->state, 0700, true);
        file_put_contents($this->state . '/' . $originalLeaf, $original);
        chmod($this->state . '/' . $originalLeaf, 0600);
        file_put_contents(
            $this->state . '/' . $evidenceLeaf,
            json_encode(
                [
                    'desired_sha256' => hash('sha256', $desired),
                    'env_path' => '/root/backups/uptime-kuma-push.env',
                    'issue' => $issue,
                    'original_sha256' => hash('sha256', $original),
                    'schema' => 'fh_kuma_monitoring_recovery.v1',
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ) . "\n",
        );
        chmod($this->state . '/' . $evidenceLeaf, 0600);
    }

    /** @return array<string,string> */
    private function recoverySnapshot(): array
    {
        $snapshot = [];
        foreach (glob($this->state . '/*') as $path) {
            $snapshot[basename($path)] = hash_file('sha256', $path);
        }
        ksort($snapshot);
        return $snapshot;
    }

    private function failureReason(): string
    {
        $result = $this->runHelper();
        self::assertSame(70, $result['exit_code'], $result['stderr']);
        return (string) ($this->json($result['stdout'])['reason'] ?? '');
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runHelper(array $arguments = [], array $environment = []): array
    {
        return $this->runCommand(
            array_merge(['python3', $this->helper, '--root-prefix', $this->root], $arguments),
            $environment,
        );
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runCommand(array $command, array $environment = []): array
    {
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            array_merge($_ENV, $environment),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string,mixed> */
    private function json(string $output): array
    {
        $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @return array<string,string> */
    private function snapshot(): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($this->root));
            $snapshot[$relative] = $item->isFile()
                ? hash_file('sha256', $item->getPathname())
                : 'directory:' . substr(sprintf('%o', $item->getPerms()), -4);
        }
        ksort($snapshot);
        return $snapshot;
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
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

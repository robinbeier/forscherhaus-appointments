<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class KumaMonitoringEnvInstallerV1Test extends TestCase
{
    private const TARGET = '/usr/local/libexec/fh-kuma-monitoring-env-v1';

    private string $workspace;
    private string $root;
    private string $source;
    private string $installer;
    private string $sha256;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/rob490-installer-' . bin2hex(random_bytes(8));
        $this->root = $this->workspace . '/root-prefix';
        mkdir($this->root . '/usr/local/libexec', 0755, true);
        mkdir($this->root . '/root/stage', 0700, true);
        mkdir($this->root . '/root/backups', 0700, true);
        mkdir($this->root . '/var/lib', 0700, true);
        mkdir($this->root . '/run', 0755, true);

        $repoRoot = dirname(__DIR__, 3);
        $helper = $repoRoot . '/scripts/ops/libexec/kuma_monitoring_env_v1.py';
        $this->installer = $repoRoot . '/scripts/ops/libexec/kuma_monitoring_env_install_v1.py';
        $this->source = $this->root . '/root/stage/kuma_monitoring_env_v1.py';
        copy($helper, $this->source);
        chmod($this->source, 0555);
        $this->sha256 = (string) hash_file('sha256', $this->source);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testDryRunIsMutationFreeAndReportsAbsentTarget(): void
    {
        $before = $this->snapshot();
        $result = $this->runInstaller();

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame($before, $this->snapshot());
        $json = $this->json($result['stdout']);
        self::assertSame('pass', $json['status'] ?? null);
        self::assertSame('absent', $json['install_state'] ?? null);
        self::assertTrue($json['execution_ready'] ?? false);
        self::assertFalse($json['mutation_performed'] ?? true);
    }

    public function testConfirmedExecutePublishesNoClobberAndIsIdempotent(): void
    {
        $wrong = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-488']);
        self::assertSame(70, $wrong['exit_code']);
        self::assertFileDoesNotExist($this->root . self::TARGET);

        $installed = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $installed['exit_code'], $installed['stderr']);
        $installedJson = $this->json($installed['stdout']);
        self::assertTrue($installedJson['mutation_performed'] ?? false);
        self::assertSame('installed', $installedJson['install_state'] ?? null);
        self::assertSame(file_get_contents($this->source), file_get_contents($this->root . self::TARGET));
        self::assertSame('0555', substr(sprintf('%o', (int) fileperms($this->root . self::TARGET)), -4));
        self::assertSame(1, (int) (stat($this->root . self::TARGET)['nlink'] ?? 0));

        $again = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $again['exit_code'], $again['stderr']);
        self::assertFalse($this->json($again['stdout'])['mutation_performed'] ?? true);
    }

    public function testSourceTrustAndHashDriftFailClosed(): void
    {
        chmod($this->source, 0755);
        $mode = $this->runInstaller();
        self::assertSame(70, $mode['exit_code']);
        self::assertSame('source_contract_invalid', $this->json($mode['stdout'])['reason'] ?? null);

        chmod($this->source, 0555);
        $hash = $this->runInstaller([], [], str_repeat('0', 64));
        self::assertSame(70, $hash['exit_code']);
        self::assertSame('source_hash_invalid', $this->json($hash['stdout'])['reason'] ?? null);

        unlink($this->source);
        symlink($this->installer, $this->source);
        $symlink = $this->runInstaller();
        self::assertSame(70, $symlink['exit_code']);
        self::assertSame('source_contract_invalid', $this->json($symlink['stdout'])['reason'] ?? null);
    }

    public function testExistingForeignTargetAndNoReplaceRaceAreNeverOverwritten(): void
    {
        $target = $this->root . self::TARGET;
        file_put_contents($target, "foreign-existing\n");
        chmod($target, 0555);
        $existing = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(70, $existing['exit_code']);
        self::assertSame("foreign-existing\n", file_get_contents($target));
        self::assertSame('target_conflict', $this->json($existing['stdout'])['reason'] ?? null);

        unlink($target);
        $race = $this->runInstaller(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_INSTALL_TEST_PUBLISH_RACE' => 'foreign'],
        );
        self::assertSame(70, $race['exit_code']);
        self::assertSame("foreign-installer-race\n", file_get_contents($target));
        self::assertSame('target_conflict', $this->json($race['stdout'])['reason'] ?? null);
        self::assertSame([], glob($this->root . '/usr/local/libexec/.fh-kuma-monitoring-env-v1.pending-*'));
    }

    public function testPostPublicationTargetReplacementIsAReportedMutation(): void
    {
        $result = $this->runInstaller(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_INSTALL_TEST_TARGET_REPLACE_AFTER_READ' => '1'],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('target_changed', $json['reason'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame("foreign-target-race\n", file_get_contents($this->root . self::TARGET));
    }

    public function testSymlinkedTargetAncestorFailsBeforePublication(): void
    {
        rmdir($this->root . '/usr/local/libexec');
        rmdir($this->root . '/usr/local');
        mkdir($this->root . '/opt/local/libexec', 0755, true);
        symlink('../opt/local', $this->root . '/usr/local');

        $result = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);

        self::assertSame(70, $result['exit_code']);
        self::assertSame('directory_contract_invalid', $this->json($result['stdout'])['reason'] ?? null);
        self::assertFileDoesNotExist($this->root . '/opt/local/libexec/fh-kuma-monitoring-env-v1');
    }

    public function testExactConcurrentPublicationConvergesWithoutClobbering(): void
    {
        $race = $this->runInstaller(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_INSTALL_TEST_PUBLISH_RACE' => 'exact'],
        );
        self::assertSame(0, $race['exit_code'], $race['stderr']);
        $json = $this->json($race['stdout']);
        self::assertSame('installed', $json['install_state'] ?? null);
        self::assertFalse($json['mutation_performed'] ?? true);
        self::assertSame(file_get_contents($this->source), file_get_contents($this->root . self::TARGET));
    }

    public function testPublishedDurabilityFailureIsReportedAsARealMutation(): void
    {
        $result = $this->runInstaller(
            ['--execute', '--confirm-live-write', 'ROB-490'],
            ['FH_KUMA_MONITORING_INSTALL_TEST_FAIL_DURABILITY' => '1'],
        );

        self::assertSame(70, $result['exit_code']);
        $json = $this->json($result['stdout']);
        self::assertSame('installation_durability_unknown', $json['reason'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame(file_get_contents($this->source), file_get_contents($this->root . self::TARGET));
    }

    public function testInvokeInstalledRejectsTargetTamperedAfterInstallation(): void
    {
        $installed = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $installed['exit_code'], $installed['stderr']);

        chmod($this->root . self::TARGET, 0755);
        file_put_contents($this->root . self::TARGET, "tampered-after-install\n");
        chmod($this->root . self::TARGET, 0555);
        $invoked = $this->runInstaller(['--invoke-installed', 'inspect']);

        self::assertSame(70, $invoked['exit_code']);
        self::assertSame('target_conflict', $this->json($invoked['stdout'])['reason'] ?? null);
    }

    public function testInvokeInstalledRunsTheExactBytesCopiedFromTheOpenHelper(): void
    {
        $env = $this->root . '/root/backups/uptime-kuma-push.env';
        file_put_contents($env, "KUMA_RELEASE_RETENTION_MONITOR_ENABLED=0\n");
        chmod($env, 0600);
        $installed = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $installed['exit_code'], $installed['stderr']);

        $invoked = $this->runInstaller(['--invoke-installed', 'inspect']);
        self::assertSame(0, $invoked['exit_code'], $invoked['stdout'] . $invoked['stderr']);
        $json = $this->json($invoked['stdout']);
        self::assertSame('pass', $json['status'] ?? null);
        self::assertSame('would_enable', $json['monitoring_state'] ?? null);
        self::assertFalse($json['mutation_performed'] ?? true);
    }

    public function testInvokeSourceRunsTheExactStagedBytesWithoutInstallation(): void
    {
        $env = $this->root . '/root/backups/uptime-kuma-push.env';
        file_put_contents($env, "KUMA_RELEASE_RETENTION_MONITOR_ENABLED=0\n");
        chmod($env, 0600);

        $invoked = $this->runInstaller(['--invoke-source', 'inspect']);

        self::assertSame(0, $invoked['exit_code'], $invoked['stdout'] . $invoked['stderr']);
        $json = $this->json($invoked['stdout']);
        self::assertSame('pass', $json['status'] ?? null);
        self::assertSame('would_enable', $json['monitoring_state'] ?? null);
        self::assertFalse($json['mutation_performed'] ?? true);
        self::assertFileDoesNotExist($this->root . self::TARGET);
    }

    public function testInvokeInstalledExecuteMutatesOnceAndThenConverges(): void
    {
        $env = $this->root . '/root/backups/uptime-kuma-push.env';
        file_put_contents($env, "KUMA_RELEASE_RETENTION_MONITOR_ENABLED=0\nUNCHANGED=yes\n");
        chmod($env, 0600);
        $installed = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $installed['exit_code'], $installed['stderr']);

        $executed = $this->runInstaller(['--invoke-installed', 'execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $executed['exit_code'], $executed['stdout'] . $executed['stderr']);
        $json = $this->json($executed['stdout']);
        self::assertSame('pass', $json['status'] ?? null);
        self::assertSame('enabled', $json['monitoring_state'] ?? null);
        self::assertTrue($json['mutation_performed'] ?? false);
        self::assertSame("KUMA_RELEASE_RETENTION_MONITOR_ENABLED=1\nUNCHANGED=yes\n", file_get_contents($env));
        self::assertDirectoryExists($this->root . '/var/lib/fh-kuma-monitoring-v1');

        $again = $this->runInstaller(['--invoke-installed', 'execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $again['exit_code'], $again['stderr']);
        self::assertFalse($this->json($again['stdout'])['mutation_performed'] ?? true);
    }

    public function testInvokeInstalledRejectsSymlinkHardlinkAndModeDrift(): void
    {
        $target = $this->root . self::TARGET;
        $installed = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $installed['exit_code'], $installed['stderr']);

        chmod($target, 0755);
        $mode = $this->runInstaller(['--invoke-installed', 'inspect']);
        self::assertSame(70, $mode['exit_code']);
        self::assertSame('target_contract_invalid', $this->json($mode['stdout'])['reason'] ?? null);
        chmod($target, 0555);

        $hardlink = $target . '.hardlink';
        link($target, $hardlink);
        $linked = $this->runInstaller(['--invoke-installed', 'inspect']);
        self::assertSame(70, $linked['exit_code']);
        self::assertSame('target_contract_invalid', $this->json($linked['stdout'])['reason'] ?? null);
        unlink($hardlink);

        unlink($target);
        symlink($this->source, $target);
        $symlink = $this->runInstaller(['--invoke-installed', 'inspect']);
        self::assertSame(70, $symlink['exit_code']);
        self::assertSame('target_contract_invalid', $this->json($symlink['stdout'])['reason'] ?? null);
    }

    public function testDarwinNoReplaceFallbackUsesRenameExclAbi(): void
    {
        $script = <<<'PY'
        import importlib.util,json,pathlib,sys,tempfile
        spec=importlib.util.spec_from_file_location('rob490_installer',sys.argv[1])
        module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        calls=[]
        class FakeLibc:
            def renameatx_np(self,*args):
                calls.append(args); return 0
        module.ctypes.CDLL=lambda *args,**kwargs: FakeLibc()
        module.sys.platform='darwin'
        with tempfile.TemporaryDirectory() as root:
            module.rename_noreplace(pathlib.Path(root),'source','target')
        args=calls[0]
        print(json.dumps({'argc':len(args),'source':args[1].decode(),'target':args[3].decode(),'flag':args[4]}))
        PY;
        $result = $this->runCommand(['python3', '-c', $script, $this->installer]);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(
            ['argc' => 5, 'source' => 'source', 'target' => 'target', 'flag' => 4],
            $this->json($result['stdout']),
        );
    }

    public function testLinuxInvocationUsesTheKernelBoundCurrentInterpreter(): void
    {
        $script = <<<'PY'
        import importlib.util,json,sys
        spec=importlib.util.spec_from_file_location('rob490_installer',sys.argv[1])
        module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        module.sys.platform='linux'
        module.sys.executable=''
        print(json.dumps({'interpreter':module.current_interpreter()}))
        PY;
        $result = $this->runCommand(['python3', '-c', $script, $this->installer]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('/proc/self/exe', $this->json($result['stdout'])['interpreter'] ?? null);
    }

    public function testInvokeInstalledCompletesThroughKernelInterpreterWithReducedEnvironment(): void
    {
        $env = $this->root . '/root/backups/uptime-kuma-push.env';
        file_put_contents($env, "KUMA_RELEASE_RETENTION_MONITOR_ENABLED=0\n");
        chmod($env, 0600);
        $installed = $this->runInstaller(['--execute', '--confirm-live-write', 'ROB-490']);
        self::assertSame(0, $installed['exit_code'], $installed['stderr']);

        $invoked = $this->runInstaller(
            ['--invoke-installed', 'inspect'],
            [
                'PATH' => (string) getenv('PATH'),
                'FH_KUMA_MONITORING_INSTALL_TEST_EMPTY_SYS_EXECUTABLE' => '1',
            ],
            null,
            false,
        );

        self::assertSame(0, $invoked['exit_code'], $invoked['stdout'] . $invoked['stderr']);
        $json = $this->json($invoked['stdout']);
        self::assertSame('pass', $json['status'] ?? null);
        self::assertSame('would_enable', $json['monitoring_state'] ?? null);
        self::assertFalse($json['mutation_performed'] ?? true);
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runInstaller(
        array $arguments = [],
        array $environment = [],
        ?string $sha256 = null,
        bool $inheritEnvironment = true,
    ): array {
        $command = array_merge(
            [
                'python3',
                $this->installer,
                '--root-prefix',
                $this->root,
                '--source',
                $this->source,
                '--expected-sha256',
                $sha256 ?? $this->sha256,
            ],
            $arguments,
        );
        return $this->runCommand($command, $environment, $inheritEnvironment);
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runCommand(array $command, array $environment = [], bool $inheritEnvironment = true): array
    {
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $inheritEnvironment ? array_merge($_ENV, $environment) : $environment,
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
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($this->root));
            $snapshot[$relative] = $item->isFile() ? hash_file('sha256', $item->getPathname()) : 'directory';
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class MaintenanceActivityIdentityContractTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_dir($path) && !is_link($path)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iterator as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($path);
            } elseif (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        }
        $this->temporaryPaths = [];
    }

    public function testAllPythonActivityScannersBindCmdlineReadsToKernelProcessCredentials(): void
    {
        foreach ($this->scannerSources() as $relative => $path) {
            $source = (string) file_get_contents($path);
            self::assertStringContainsString(
                "def activity_count(proc_root='/proc', trusted_uid=0):",
                $source,
                $relative,
            );
            self::assertStringContainsString('os.O_DIRECTORY', $source, $relative);
            self::assertStringContainsString('os.O_NOFOLLOW', $source, $relative);
            self::assertStringContainsString("line.startswith(b'Uid:')", $source, $relative);
            self::assertStringNotContainsString('st_uid != trusted_uid', $source, $relative);
        }
    }

    public function testBuildCacheProductionTrustUidIsNotEnvironmentControlled(): void
    {
        $shell = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/prod_build_cache_retention.sh');
        self::assertStringNotContainsString('BUILD_CACHE_RETENTION_PROC_ROOT', $shell);
        self::assertSame(1, preg_match("/<<'PY'\n(.*?)\nPY/s", $shell, $matches));
        $source = $matches[1];

        self::assertStringContainsString("def activity_count(proc_root='/proc', trusted_uid=0):", $source);
        self::assertStringNotContainsString('os.environ', $source);
        self::assertStringContainsString('os.O_DIRECTORY', $source);
        self::assertStringContainsString('os.O_NOFOLLOW', $source);
        self::assertStringContainsString("line.startswith(b'Uid:')", $source);
        self::assertStringNotContainsString('st_uid != trusted_uid', $source);
        self::assertStringContainsString('print(activity_count())', $source);
    }

    public function testPythonScannersCountTrustedProcessesAndFailClosedOnTrustedReadErrors(): void
    {
        $fixtureRoot = $this->temporaryDirectory('maintenance-activity-fixtures');
        $trustedUid = $this->trustedUid();
        $trusted = $fixtureRoot . '/trusted/123';
        mkdir($trusted, 0777, true);
        $this->writeStatus($trusted . '/status', $trustedUid);
        file_put_contents($trusted . '/cmdline', "/usr/local/bin/deploy_ea.sh\0");

        $oversized = $fixtureRoot . '/oversized/123';
        mkdir($oversized, 0777, true);
        $this->writeStatus($oversized . '/status', $trustedUid);
        file_put_contents($oversized . '/cmdline', str_repeat('x', 131073));

        $unreadable = $fixtureRoot . '/unreadable/123';
        mkdir($unreadable, 0777, true);
        $this->writeStatus($unreadable . '/status', $trustedUid);
        mkdir($unreadable . '/cmdline');

        $missingStatus = $fixtureRoot . '/missing-status/123';
        mkdir($missingStatus, 0777, true);
        file_put_contents($missingStatus . '/cmdline', "/usr/local/bin/deploy_ea.sh\0");

        $malformedStatus = $fixtureRoot . '/malformed-status/123';
        mkdir($malformedStatus, 0777, true);
        file_put_contents($malformedStatus . '/status', "Name:\ttest\nUid:\t{$trustedUid}\tbroken\n");
        file_put_contents($malformedStatus . '/cmdline', "/usr/local/bin/deploy_ea.sh\0");

        foreach ($this->scannerSources() as $relative => $source) {
            self::assertSame(0, $this->runHarness($source, $fixtureRoot . '/trusted', $trustedUid, 1), $relative);
            self::assertSame(2, $this->runHarness($source, $fixtureRoot . '/oversized', $trustedUid, 1), $relative);
            self::assertSame(2, $this->runHarness($source, $fixtureRoot . '/unreadable', $trustedUid, 1), $relative);
            self::assertSame(
                0,
                $this->runHarness($source, $fixtureRoot . '/missing-status', $trustedUid, 0),
                $relative,
            );
            self::assertSame(
                2,
                $this->runHarness($source, $fixtureRoot . '/malformed-status', $trustedUid, 1),
                $relative,
            );
        }
    }

    public function testPythonScannersIgnoreDifferentOwnerSameNameProcessesWhenChownIsAvailable(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_getpwnam')) {
            self::markTestSkipped('Linux with POSIX account lookup is required.');
        }
        $nobody = posix_getpwnam('nobody');
        if (!is_array($nobody) || !isset($nobody['uid'])) {
            self::markTestSkipped('The nobody account is required.');
        }

        $fixtureRoot = $this->temporaryDirectory('maintenance-activity-untrusted');
        $trustedUid = $this->trustedUid();
        $nobodyUid = (int) $nobody['uid'];
        $cmdline = $fixtureRoot . '/123/cmdline';
        mkdir(dirname($cmdline), 0777, true);
        $this->writeStatus(dirname($cmdline) . '/status', $nobodyUid);
        file_put_contents($cmdline, "/usr/local/bin/deploy_ea.sh\0");
        if (!chown($cmdline, $nobodyUid) || !chown(dirname($cmdline) . '/status', $nobodyUid)) {
            self::markTestSkipped('The test process cannot create an untrusted fixture owner.');
        }

        foreach ($this->scannerSources() as $relative => $source) {
            self::assertSame(0, $this->runHarness($source, $fixtureRoot, $trustedUid, 0), $relative);
        }
    }

    public function testLinuxProcessCredentialsOverrideSpoofedCmdlineOwnership(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || $this->trustedUid() !== 0) {
            self::markTestSkipped('A Linux root test process is required.');
        }

        $process = null;
        try {
            $dropCode =
                'import ctypes,os,time; ctypes.CDLL(None).prctl(4, 0, 0, 0, 0); os.setuid(65534); time.sleep(5)';
            $process = $this->startSpoofedProcess($dropCode);
            usleep(100000);
            foreach ($this->scannerSources() as $relative => $source) {
                self::assertSame(0, $this->runHarness($source, '/proc', 0, 0), $relative);
            }
            proc_terminate($process);
            proc_close($process);
            $process = $this->startSpoofedProcess('import time; time.sleep(5)');
            usleep(100000);
            foreach ($this->scannerSources() as $relative => $source) {
                self::assertSame(0, $this->runHarness($source, '/proc', 0, 1), $relative);
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /** @return array<string, string> */
    private function scannerSources(): array
    {
        $root = dirname(__DIR__, 3);
        $sources = [
            'backup' => $root . '/scripts/ops/libexec/backup_set_producer_v1.py',
            'session' => $root . '/scripts/ops/libexec/session_retention_v1.py',
            'release' => $root . '/scripts/ops/libexec/release_archive_dump_retention_v1.py',
            'deployment' => $root . '/scripts/ops/libexec/deployment_dump_attestation_v1.py',
        ];
        $shell = (string) file_get_contents($root . '/scripts/ops/prod_build_cache_retention.sh');
        self::assertSame(1, preg_match("/<<'PY'\n(.*?)\nPY/s", $shell, $matches));
        $embedded = $this->temporaryDirectory('maintenance-build-cache-source') . '/activity.py';
        file_put_contents($embedded, $matches[1]);
        $sources['build-cache'] = $embedded;
        return $sources;
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
        mkdir($path, 0700, true);
        $this->temporaryPaths[] = $path;
        return $path;
    }

    private function trustedUid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    private function writeStatus(string $path, int $uid): void
    {
        file_put_contents($path, "Name:\ttest\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\n");
    }

    /** @return resource */
    private function startSpoofedProcess(string $pythonCode)
    {
        $command = sprintf(
            'exec -a %s python3 -c %s',
            escapeshellarg('/usr/local/bin/deploy_ea.sh'),
            escapeshellarg($pythonCode),
        );
        $process = proc_open(['bash', '-c', $command], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return $process;
    }

    private function runHarness(string $source, string $procRoot, int $trustedUid, int $expectedCount): int
    {
        $harness = $this->temporaryDirectory('maintenance-activity-harness') . '/check.py';
        file_put_contents(
            $harness,
            <<<'PY'
            import ast
            import os
            import re
            import sys

            class Rejected(Exception):
                pass

            def reject(*args):
                raise Rejected(args)

            source = open(sys.argv[1], encoding='utf-8').read()
            tree = ast.parse(source)
            function = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'activity_count')
            namespace = {'os': os, 're': re, 'reject': reject, 'SUPERVISOR_COMMAND': 'unused'}
            exec(compile(ast.Module([function], type_ignores=[]), sys.argv[1], 'exec'), namespace)
            try:
                result = namespace['activity_count'](sys.argv[2], int(sys.argv[3]))
            except Rejected:
                raise SystemExit(2)
            if result != int(sys.argv[4]):
                raise SystemExit(1)
            PY
            ,
        );

        $process = proc_open(
            ['python3', $harness, $source, $procRoot, (string) $trustedUid, (string) $expectedCount],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit === 127) {
            self::markTestSkipped('python3 is required for the activity scanner harness.');
        }
        return $exit;
    }
}

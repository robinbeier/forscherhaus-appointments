<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/** Regression coverage for the immutable Kuma push runtime bundle. */
final class KumaPushRuntimeBundleTest extends TestCase
{
    private const MANIFEST = 'scripts/ops/config/kuma_push_runtime_bundle_v1.json';
    private const CRON_SOURCE = 'scripts/ops/config/fh-uptime-kuma-push.cron';
    private const INSTALL_ROOT = '/usr/local/libexec/fh-kuma-push-runtime-v1';

    public function testManifestBindsTheCompleteClosureAndCronContract(): void
    {
        $manifest = $this->manifest();
        self::assertSame('fh_kuma_push_runtime_bundle.v1', $manifest['schema'] ?? null);
        self::assertSame('v1', $manifest['runtime'] ?? null);
        self::assertSame(self::INSTALL_ROOT, $manifest['install_root'] ?? null);
        self::assertSame('/etc/cron.d/fh-uptime-kuma-push', $manifest['cron_path'] ?? null);
        self::assertSame(self::CRON_SOURCE, $manifest['cron_source'] ?? null);
        self::assertSame(
            $manifest['cron_sha256'] ?? null,
            hash_file('sha256', $this->repoRoot() . '/' . self::CRON_SOURCE),
        );

        $files = $manifest['files'] ?? null;
        self::assertIsArray($files);
        self::assertCount(12, $files);
        $sourcePaths = [];
        foreach ($files as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('role', $entry);
            self::assertSame($entry['source'] ?? null, $entry['install'] ?? null);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($entry['sha256'] ?? ''));
            $expectedRole = match (true) {
                str_starts_with($entry['source'], 'scripts/ops/lib/') => 'shell_library',
                str_starts_with($entry['source'], 'scripts/ops/kuma_push_') => 'entrypoint',
                str_starts_with($entry['source'], 'scripts/release-gate/lib/') => 'pdf_gate_library',
                $entry['source'] === 'scripts/release-gate/dashboard_release_gate.php' => 'pdf_gate',
                default => null,
            };
            self::assertNotNull($expectedRole);
            self::assertSame($expectedRole, $entry['role']);
            $sourcePaths[] = $entry['source'];
            $source = $this->repoRoot() . '/' . $entry['source'];
            self::assertFileExists($source);
            self::assertSame(
                $entry['sha256'],
                hash_file('sha256', $source),
                'Manifest hash drift: ' . $entry['source'],
            );
        }

        $entryPoints = array_values(
            array_filter(
                $sourcePaths,
                static fn(string $path): bool => preg_match('#^scripts/ops/kuma_push_[^/]+\.sh$#', $path) === 1,
            ),
        );
        sort($entryPoints);
        self::assertCount(5, $entryPoints);
        self::assertCount(5, array_unique($entryPoints));
        $cron = file_get_contents($this->repoRoot() . '/' . self::CRON_SOURCE);
        self::assertIsString($cron);
        $expectedCron = ['SHELL=/bin/bash', 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'];
        $cronEntryPoints = [];
        foreach (
            [
                ['host_resources', '*', ''],
                ['ops_jobs', '*/15', ''],
                ['app_logs', '*', ''],
                ['app_logs', '*', 'sleep 30; '],
                ['pdf_export', '*/15', ''],
                ['backup_creation', '*/15', ''],
            ]
            as [$monitor, $minute, $delay]
        ) {
            $cronEntryPoints[] = 'scripts/ops/kuma_push_' . $monitor . '.sh';
            $expectedCron[] =
                $minute .
                ' * * * * root ' .
                $delay .
                'KUMA_PUSH_ENV_FILE=/root/backups/uptime-kuma-push.env ' .
                self::INSTALL_ROOT .
                '/scripts/ops/kuma_push_' .
                $monitor .
                '.sh' .
                ' >> /var/log/kuma_push_' .
                $monitor .
                '.log 2>&1';
        }
        $cronEntryPoints = array_values(array_unique($cronEntryPoints));
        sort($cronEntryPoints);
        self::assertSame($entryPoints, $cronEntryPoints);
        self::assertSame(implode("\n", $expectedCron) . "\n", $cron);
        self::assertContains('scripts/ops/kuma_push_pdf_export.sh', $entryPoints);
        self::assertContains('scripts/ops/lib/kuma_push_common.sh', $sourcePaths);
        self::assertContains('scripts/ops/lib/app_log_classification.sh', $sourcePaths);
        self::assertContains('scripts/release-gate/dashboard_release_gate.php', $sourcePaths);
        self::assertContains('scripts/release-gate/lib/GateAssertions.php', $sourcePaths);
        self::assertContains('scripts/release-gate/lib/GateCliSupport.php', $sourcePaths);
        self::assertContains('scripts/release-gate/lib/GateHttpClient.php', $sourcePaths);
    }

    public function testPdfEntryPointUsesOnlyBundledDashboardGate(): void
    {
        $script = file_get_contents($this->repoRoot() . '/scripts/ops/kuma_push_pdf_export.sh');
        self::assertIsString($script);
        self::assertStringContainsString('dashboard_release_gate.php', $script);
        self::assertStringNotContainsString('/var/www/html/easyappointments/scripts/release-gate', $script);
    }

    public function testInstalledPdfRuntimeInvokesBundledGateWithSeparateAppRoot(): void
    {
        $fixture = $this->fixture();
        $workspace = $fixture['workspace'];
        $appRoot = $workspace . '/separate-app-root';
        $stateDir = $workspace . '/private-monitor-state';
        $stubBin = $workspace . '/bin';
        $phpLog = $workspace . '/php-gate.log';
        $curlLog = $workspace . '/curl.log';
        $envFile = $workspace . '/uptime-kuma-push.env';
        $credentials = $workspace . '/release-gate-admin.env';
        try {
            mkdir($stubBin, 0755, true);
            mkdir($appRoot . '/storage/logs/ops', 0755, true);
            file_put_contents($credentials, "USERNAME='fixture-user'\nPASSWORD='fixture-password'\n");
            file_put_contents(
                $envFile,
                implode(PHP_EOL, [
                    'KUMA_PUSH_URL_PDF_EXPORT=https://kuma.example/push/pdf-export',
                    'KUMA_PDF_EXPORT_APP_ROOT=' . $appRoot,
                    'KUMA_PUSH_STATE_DIR=' . $stateDir,
                    'KUMA_PDF_EXPORT_CREDENTIALS_FILE=' . $credentials,
                    'KUMA_PUSH_ENV_FILE=' . $envFile,
                    '',
                ]),
            );
            $this->writePdfPhpStub($stubBin . '/php', $phpLog);
            $this->writeCurlStub($stubBin . '/curl', $curlLog);
            $installedPdf = $fixture['root'] . self::INSTALL_ROOT . '/scripts/ops/kuma_push_pdf_export.sh';
            $result = $this->runCommand(['bash', $installedPdf], $this->repoRoot(), [
                'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin'),
                'KUMA_PUSH_ENV_FILE' => $envFile,
            ]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $gateLog = file_get_contents($phpLog);
            self::assertIsString($gateLog);
            self::assertStringContainsString(
                $fixture['root'] . self::INSTALL_ROOT . '/scripts/release-gate/dashboard_release_gate.php',
                $gateLog,
            );
            self::assertStringContainsString('summary=-r', $gateLog);
            self::assertStringContainsString('arg=--password-stdin', $gateLog);
            self::assertStringNotContainsString('fixture-password', $gateLog);
            self::assertStringContainsString('stdin_sha256=' . hash('sha256', 'fixture-password'), $gateLog);
            self::assertStringContainsString('RELEASE_GATE_REPO_ROOT=' . $appRoot, $gateLog);
            self::assertStringNotContainsString($fixture['source'] . '/scripts/release-gate', $gateLog);
            self::assertStringContainsString(
                'https://kuma.example/push/pdf-export',
                (string) file_get_contents($curlLog),
            );
            self::assertFileExists($stateDir . '/kuma-pdf-export-latest.json');
            self::assertSame(0600, fileperms($stateDir . '/kuma-pdf-export-latest.json') & 0777);
            self::assertFileDoesNotExist($appRoot . '/storage/logs/ops/kuma-pdf-export-latest.json');
            self::assertStringContainsString('OK dashboard_pdf_gate=all_checks_passed', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents($this->repoRoot() . '/' . self::MANIFEST);
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        return $manifest;
    }

    /** @return array{workspace:string,source:string,root:string} */
    private function fixture(): array
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-runtime-' . bin2hex(random_bytes(8));
        $source = $workspace . '/source';
        $root = $workspace . '/root';
        mkdir($source, 0755, true);
        mkdir($root . '/etc/cron.d', 0755, true);
        mkdir($root . '/usr/local/libexec', 0755, true);
        $manifest = $this->manifest();
        foreach (array_merge([$manifest['cron_source']], array_column($manifest['files'], 'source')) as $relative) {
            $target = $source . '/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            self::assertTrue(copy($this->repoRoot() . '/' . $relative, $target));
        }
        $installedRoot = $root . self::INSTALL_ROOT;
        foreach ($manifest['files'] as $entry) {
            $target = $installedRoot . '/' . $entry['install'];
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            self::assertTrue(copy($this->repoRoot() . '/' . $entry['source'], $target));
            chmod($target, 0555);
        }
        self::assertTrue(
            copy($this->repoRoot() . '/' . $manifest['cron_source'], $root . '/etc/cron.d/fh-uptime-kuma-push'),
        );
        return ['workspace' => $workspace, 'source' => $source, 'root' => $root];
    }

    private function writePdfPhpStub(string $path, string $logPath): void
    {
        $script =
            "#!/bin/sh\nset -eu\n" .
            "if [ \"\${1:-}\" = '-r' ]; then printf 'summary=-r\\n' >> " .
            escapeshellarg($logPath) .
            '; exec ' .
            escapeshellarg(PHP_BINARY) .
            " \"\$@\"; fi\n" .
            "printf 'gate=%s RELEASE_GATE_REPO_ROOT=%s\\n' \"\$1\" \"\${RELEASE_GATE_REPO_ROOT:-}\" >> " .
            escapeshellarg($logPath) .
            "\n" .
            "printf 'arg=%s\\n' \"\$@\" >> " .
            escapeshellarg($logPath) .
            "\n" .
            escapeshellarg(PHP_BINARY) .
            ' -r ' .
            escapeshellarg('echo "stdin_sha256=" . hash("sha256", stream_get_contents(STDIN)) . "\n";') .
            ' >> ' .
            escapeshellarg($logPath) .
            "\n" .
            "output=''\nfor arg in \"\$@\"; do case \"\$arg\" in --output-json=*) output=\"\${arg#--output-json=}\" ;; esac; done\n" .
            "[ -n \"\$output\" ]\nprintf '%s\\n' '{\"checks\":[{\"name\":\"fixture_gate\",\"status\":\"pass\"}]}' > \"\$output\"\n";
        file_put_contents($path, $script);
        chmod($path, 0755);
    }

    private function writeCurlStub(string $path, string $logPath): void
    {
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($logPath) . "\n");
        chmod($path, 0755);
    }

    /** @param list<string> $command @param array<string, string> $env */
    private function runCommand(array $command, string $cwd, array $env = []): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge($_ENV, $env),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}

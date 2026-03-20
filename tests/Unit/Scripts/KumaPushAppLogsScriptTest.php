<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class KumaPushAppLogsScriptTest extends TestCase
{
    public function testAppLogMonitorLoadsEnvOverridesBeforeResolvingDefaults(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-app-logs-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $repo = $workspace . '/repo';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $stateFile = $stateDir . '/app-logs.state';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir($repo . '/scripts/ops/lib', 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0777, true);

        try {
            $this->copyScript('scripts/ops/kuma_push_app_logs.sh', $repo . '/scripts/ops/kuma_push_app_logs.sh');
            $this->copyScript('scripts/ops/lib/kuma_push_common.sh', $repo . '/scripts/ops/lib/kuma_push_common.sh');

            file_put_contents(
                $stubBin . '/curl',
                "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$@\" >> " . escapeshellarg($capturePath) . "\n",
            );
            chmod($stubBin . '/curl', 0755);

            file_put_contents(
                $envFile,
                "KUMA_PUSH_URL_APP_LOGS='https://kuma.example/push/app-logs'\n"
                . "KUMA_APP_ROOT='" . addslashes($appRoot) . "'\n"
                . "KUMA_APP_LOG_IGNORE_REGEX='ignored-host-noise'\n",
            );

            file_put_contents(
                $logFile,
                "[error] ERROR - ignored-host-noise\n",
            );

            file_put_contents($stateFile, $logFile . '|' . $this->statDevInode($logFile) . "|0\n");

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $repo,
                [
                    'KUMA_PUSH_ENV_FILE' => $envFile,
                    'KUMA_PUSH_STATE_DIR' => $stateDir,
                    'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'TZ' => 'UTC',
                ],
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('OK new_app_errors=0', $result['stdout']);
            self::assertStringContainsString('status=up', $this->readFile($capturePath));
            self::assertStringContainsString('msg=OK new_app_errors=0', $this->readFile($capturePath));
            self::assertStringContainsString('ping=1', $this->readFile($capturePath));
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd, array $env = []): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, array_merge($_ENV, $env));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function copyScript(string $sourceRelativePath, string $destinationPath): void
    {
        $scriptSource = file_get_contents($this->repoRoot() . '/' . $sourceRelativePath);
        self::assertIsString($scriptSource);

        file_put_contents($destinationPath, $scriptSource);
        chmod($destinationPath, 0755);
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function statDevInode(string $path): string
    {
        $stat = stat($path);
        self::assertIsArray($stat);

        return $stat['dev'] . ':' . $stat['ino'];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}

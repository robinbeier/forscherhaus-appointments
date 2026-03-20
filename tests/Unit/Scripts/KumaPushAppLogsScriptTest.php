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
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0777, true);

        try {
            file_put_contents(
                $stubBin . '/curl',
                "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$@\" >> " .
                    escapeshellarg($capturePath) .
                    "\n",
            );
            chmod($stubBin . '/curl', 0755);

            file_put_contents(
                $envFile,
                'KUMA_PUSH_URL_APP_LOGS=' .
                    escapeshellarg('https://kuma.example/push/app-logs') .
                    "\n" .
                    'KUMA_APP_ROOT=' .
                    escapeshellarg($appRoot) .
                    "\n" .
                    'KUMA_APP_LOG_IGNORE_REGEX=' .
                    escapeshellarg('ignored-host-noise') .
                    "\n",
            );

            file_put_contents($logFile, '');

            $primeResult = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $primeResult['exit_code'], $primeResult['stderr']);
            self::assertStringContainsString('OK primed app log monitor', $primeResult['stdout']);

            file_put_contents($logFile, "[error] ERROR - ignored-host-noise\n", FILE_APPEND);

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('OK new_app_errors=0', $result['stdout']);
            $curlCalls = $this->readFile($capturePath);
            self::assertStringContainsString('status=up', $curlCalls);
            self::assertStringContainsString('msg=OK primed app log monitor', $curlCalls);
            self::assertStringContainsString('msg=OK new_app_errors=0', $curlCalls);
            self::assertStringContainsString('ping=1', $curlCalls);
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

    /**
     * @return array<string, string>
     */
    private function commandEnv(string $envFile, string $stateDir, string $stubBin): array
    {
        return [
            'KUMA_PUSH_ENV_FILE' => $envFile,
            'KUMA_PUSH_STATE_DIR' => $stateDir,
            'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
            'TZ' => 'UTC',
        ];
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

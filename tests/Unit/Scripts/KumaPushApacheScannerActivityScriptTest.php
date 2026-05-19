<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class KumaPushApacheScannerActivityScriptTest extends TestCase
{
    public function testScannerMonitorCountsDefaultApacheTimestampWithTimezone(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-apache-scanner-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $logDir = $workspace . '/logs';
        $capturePath = $workspace . '/curl-args.log';
        $envFile = $workspace . '/uptime-kuma-push.env';
        $logFile = $logDir . '/access.log';

        mkdir($stubBin, 0777, true);
        mkdir($logDir, 0777, true);

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
                implode(PHP_EOL, [
                    'KUMA_PUSH_URL_SECURITY_SCANNER=https://kuma.example/push/security-scanner',
                    'KUMA_SECURITY_SCANNER_LOG_GLOB=' . $logFile,
                    'KUMA_SECURITY_SCANNER_WINDOW_MINUTES=5',
                    'KUMA_SECURITY_SCANNER_THRESHOLD=0',
                    'KUMA_SECURITY_SCANNER_TAIL_LINES=100',
                    '',
                ]),
            );

            $timestamp = gmdate('d/M/Y:H:i:s O');
            file_put_contents(
                $logFile,
                '203.0.113.10 - - [' . $timestamp . '] "GET /.env HTTP/1.1" 404 123 "-" "scanner"' . PHP_EOL,
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_apache_scanner_activity.sh'],
                $this->repoRoot(),
                [
                    'KUMA_PUSH_ENV_FILE' => $envFile,
                    'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'TZ' => 'UTC',
                ],
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('WARN scanner_activity=1 window=5m threshold=0', $result['stdout']);

            $curlCalls = $this->readFile($capturePath);
            self::assertStringContainsString('status=down', $curlCalls);
            self::assertStringContainsString('msg=WARN scanner_activity=1 window=5m threshold=0', $curlCalls);
            self::assertStringContainsString('ping=0', $curlCalls);
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class KumaPushApacheScannerActivityScriptTest extends TestCase
{
    public function testScannerMonitorCountsDefaultApacheTimestampWithTimezone(): void
    {
        $timestamp = gmdate('d/M/Y:H:i:s O');
        $result = $this->runScannerScript(
            ['203.0.113.10 - - [' . $timestamp . '] "GET /.env HTTP/1.1" 404 123 "-" "scanner"'],
            threshold: 0,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString(
            'OK scanner_activity=1 window=5m threshold=0 actionable=0 success_2xx=0 direct_success_2xx=0 query_marker_2xx=0 redirect_3xx=0 blocked_4xx=1 other_status=0 sources=1 source_threshold=5',
            $result['stdout'],
        );
        self::assertStringContainsString('status=up', $result['curl_calls']);
        self::assertStringContainsString('ping=1', $result['curl_calls']);
    }

    public function testBlockedScannerBurstAboveThresholdStaysGreen(): void
    {
        $timestamp = gmdate('d/M/Y:H:i:s O');
        $result = $this->runScannerScript(
            [
                '203.0.113.10 - - [' . $timestamp . '] "GET /.env HTTP/1.1" 403 123 "-" "scanner"',
                '203.0.113.10 - - [' . $timestamp . '] "GET /vendor/phpunit HTTP/1.1" 404 123 "-" "scanner"',
            ],
            threshold: 1,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('OK scanner_activity=2 window=5m threshold=1 actionable=0', $result['stdout']);
        self::assertStringContainsString('blocked_4xx=2', $result['stdout']);
        self::assertStringContainsString('status=up', $result['curl_calls']);
        self::assertStringContainsString('ping=1', $result['curl_calls']);
    }

    public function testRedirectAndBlockedScannerBurstAboveThresholdStaysGreenWithoutSuccess(): void
    {
        $timestamp = gmdate('d/M/Y:H:i:s O');
        $result = $this->runScannerScript(
            [
                '203.0.113.10 - - [' . $timestamp . '] "GET /wp-admin/css/ HTTP/1.1" 301 123 "-" "scanner"',
                '203.0.113.10 - - [' . $timestamp . '] "GET /wp-admin/css/ HTTP/1.1" 404 123 "-" "scanner"',
            ],
            threshold: 1,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('OK scanner_activity=2 window=5m threshold=1 actionable=0', $result['stdout']);
        self::assertStringContainsString('redirect_3xx=1 blocked_4xx=1', $result['stdout']);
        self::assertStringContainsString('status=up', $result['curl_calls']);
        self::assertStringContainsString('ping=1', $result['curl_calls']);
    }

    public function testSuccessfulScannerPathAboveThresholdGoesRed(): void
    {
        $timestamp = gmdate('d/M/Y:H:i:s O');
        $result = $this->runScannerScript(
            [
                '203.0.113.10 - - [' . $timestamp . '] "GET /wp-admin/index.php HTTP/1.1" 200 123 "-" "scanner"',
                '203.0.113.10 - - [' . $timestamp . '] "GET /.env HTTP/1.1" 403 123 "-" "scanner"',
            ],
            threshold: 1,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString(
            'WARN scanner_activity=2 window=5m threshold=1 actionable=1',
            $result['stdout'],
        );
        self::assertStringContainsString('success_2xx=1', $result['stdout']);
        self::assertStringContainsString('direct_success_2xx=1', $result['stdout']);
        self::assertStringContainsString('query_marker_2xx=0', $result['stdout']);
        self::assertStringContainsString('status=down', $result['curl_calls']);
        self::assertStringContainsString('ping=0', $result['curl_calls']);
    }

    public function testQueryOnlyScannerSuccessAboveThresholdStaysGreenWithoutManySources(): void
    {
        $timestamp = gmdate('d/M/Y:H:i:s O');
        $result = $this->runScannerScript(
            [
                '203.0.113.10 - - [' . $timestamp . '] "GET /?file=/.env HTTP/1.1" 200 123 "-" "scanner"',
                '203.0.113.10 - - [' . $timestamp . '] "GET /?download=/.env HTTP/1.1" 200 123 "-" "scanner"',
                '203.0.113.10 - - [' . $timestamp . '] "GET /?probe=/.env HTTP/1.1" 200 123 "-" "scanner"',
                '203.0.113.10 - - [' . $timestamp . '] "GET /?debug=/.env HTTP/1.1" 200 123 "-" "scanner"',
                '203.0.113.10 - - [' . $timestamp . '] "GET /?file=/.env HTTP/1.1" 302 123 "-" "scanner"',
                '203.0.113.10 - - [' . $timestamp . '] "GET /.env HTTP/1.1" 403 123 "-" "scanner"',
            ],
            threshold: 5,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('OK scanner_activity=6 window=5m threshold=5 actionable=0', $result['stdout']);
        self::assertStringContainsString('success_2xx=4', $result['stdout']);
        self::assertStringContainsString('direct_success_2xx=0', $result['stdout']);
        self::assertStringContainsString('query_marker_2xx=4', $result['stdout']);
        self::assertStringContainsString('redirect_3xx=1 blocked_4xx=1', $result['stdout']);
        self::assertStringContainsString('sources=1 source_threshold=5', $result['stdout']);
        self::assertStringContainsString('status=up', $result['curl_calls']);
        self::assertStringContainsString('ping=1', $result['curl_calls']);
    }

    public function testManySourceBlockedScannerBurstAboveThresholdGoesRed(): void
    {
        $timestamp = gmdate('d/M/Y:H:i:s O');
        $lines = [];
        for ($source = 1; $source <= 5; $source++) {
            $lines[] = '203.0.113.' . $source . ' - - [' . $timestamp . '] "GET /.env HTTP/1.1" 403 123 "-" "scanner"';
        }

        $result = $this->runScannerScript($lines, threshold: 0, sourceThreshold: 5);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString(
            'WARN scanner_activity=5 window=5m threshold=0 actionable=1',
            $result['stdout'],
        );
        self::assertStringContainsString('sources=5 source_threshold=5', $result['stdout']);
        self::assertStringContainsString('status=down', $result['curl_calls']);
        self::assertStringContainsString('ping=0', $result['curl_calls']);
    }

    /**
     * @param list<string> $logLines
     * @return array{exit_code:int,stdout:string,stderr:string,curl_calls:string}
     */
    private function runScannerScript(array $logLines, int $threshold, int $sourceThreshold = 5): array
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
                    'KUMA_SECURITY_SCANNER_THRESHOLD=' . $threshold,
                    'KUMA_SECURITY_SCANNER_SOURCE_THRESHOLD=' . $sourceThreshold,
                    'KUMA_SECURITY_SCANNER_TAIL_LINES=100',
                    '',
                ]),
            );

            file_put_contents($logFile, implode(PHP_EOL, $logLines) . PHP_EOL);

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_apache_scanner_activity.sh'],
                $this->repoRoot(),
                [
                    'KUMA_PUSH_ENV_FILE' => $envFile,
                    'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'TZ' => 'UTC',
                ],
            );

            return $result + ['curl_calls' => $this->readFile($capturePath)];
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

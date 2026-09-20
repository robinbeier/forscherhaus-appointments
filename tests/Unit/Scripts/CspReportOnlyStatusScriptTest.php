<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Csp_report_only;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'core/Csp_report_only.php';

final class CspReportOnlyStatusScriptTest extends TestCase
{
    public function testInactiveCliReceiptUsesOnlyFixedStatusClasses(): void
    {
        $directory = sys_get_temp_dir() . '/csp-status-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        try {
            $result = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=inactive',
                '--config-path=' . $directory . '/missing-config.json',
                '--aggregate-path=' . $directory . '/missing-aggregate.json',
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $receipt = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('csp_report_only_status.v1', $receipt['schema']);
            self::assertSame('passed', $receipt['status']);
            self::assertSame('missing', $receipt['config']['status']);
            self::assertSame('missing', $receipt['aggregate']['status']);
            self::assertStringNotContainsString($directory, $result['stdout'] . $result['stderr']);
        } finally {
            rmdir($directory);
        }
    }

    public function testInactiveCliCanSummarizeThePreservedAggregateWithoutActivationConfig(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $directory = $temporaryRoot . '/csp-status-preserved-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $aggregatePath = $directory . '/aggregate.json';
        $config = [
            'max_reports_per_minute' => 2,
            'retention_hours' => 48,
        ];
        $now = time();

        try {
            self::assertSame(
                'accepted',
                Csp_report_only::record(
                    [
                        'surface' => 'www',
                        'directive' => 'img-src',
                        'blocked_origin' => 'self',
                        'disposition' => 'report',
                    ],
                    $config,
                    $aggregatePath,
                    $now,
                )['status'],
            );
            $result = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=inactive',
                '--config-path=' . $directory . '/missing-config.json',
                '--aggregate-path=' . $aggregatePath,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $receipt = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('missing', $receipt['config']['status']);
            self::assertSame('valid', $receipt['aggregate']['status']);
            self::assertSame(1, $receipt['aggregate']['summary']['accepted']);
            self::assertSame(1, $receipt['aggregate']['summary']['classes']['surface']['www']);
            self::assertStringNotContainsString($directory, $result['stdout'] . $result['stderr']);
        } finally {
            if (is_file($aggregatePath)) {
                unlink($aggregatePath);
            }
            rmdir($directory);
        }
    }

    public function testWrapperAcceptsOnlyTheExpectedActiveHeaderBoundary(): void
    {
        $fixture = $this->createWrapperFixture(false);

        try {
            $result = $this->runCommand(
                [
                    'bash',
                    'scripts/ops/prod_csp_report_only_status.sh',
                    '--expect',
                    'active',
                    '--prod-ssh-target',
                    'root@example.test',
                ],
                [
                    'PATH' => $fixture . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'CSP_REPORT_ONLY_DOCTOR_SCRIPT' => $fixture . '/doctor.sh',
                ],
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('csp_headers.status=passed', $result['stdout']);
            self::assertStringContainsString('"status":"passed"', $result['stdout']);
            self::assertStringNotContainsString('Content-Security-Policy-Report-Only:', $result['stdout']);
            self::assertStringNotContainsString('secret', strtolower($result['stdout'] . $result['stderr']));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testActiveCliReceiptSummarizesOnlyFixedClasses(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('The root-owned activation-path contract is verified in the CI container.');
        }

        $directory = '/var/lib/fh-csp-status-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);
        $configPath = $directory . '/config.json';
        $aggregatePath = $directory . '/aggregate.json';
        copy($this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json', $configPath);
        chmod($configPath, 0644);

        try {
            $config = Csp_report_only::load($configPath);
            self::assertIsArray($config);
            $result = Csp_report_only::record(
                [
                    'surface' => 'app',
                    'directive' => 'script-src',
                    'blocked_origin' => 'unknown-external',
                    'disposition' => 'report',
                ],
                $config,
                $aggregatePath,
                time(),
            );
            self::assertSame('accepted', $result['status']);

            $receiptResult = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=active',
                '--config-path=' . $configPath,
                '--aggregate-path=' . $aggregatePath,
            ]);

            self::assertSame(0, $receiptResult['exit_code'], $receiptResult['stderr']);
            $receipt = json_decode($receiptResult['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('passed', $receipt['status']);
            self::assertSame('active', $receipt['config']['status']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $receipt['config']['sha256']);
            self::assertSame(1, $receipt['aggregate']['summary']['accepted']);
            self::assertSame(1, $receipt['aggregate']['summary']['classes']['blocked_origin']['unknown-external']);
            self::assertStringNotContainsString($directory, $receiptResult['stdout'] . $receiptResult['stderr']);
        } finally {
            if (is_file($aggregatePath)) {
                unlink($aggregatePath);
            }
            if (is_file($configPath)) {
                unlink($configPath);
            }
            rmdir($directory);
        }
    }

    public function testWrapperFailsWhenEnforcementAppears(): void
    {
        $fixture = $this->createWrapperFixture(true);

        try {
            $result = $this->runCommand(
                [
                    'bash',
                    'scripts/ops/prod_csp_report_only_status.sh',
                    '--expect',
                    'active',
                    '--prod-ssh-target',
                    'root@example.test',
                ],
                [
                    'PATH' => $fixture . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'CSP_REPORT_ONLY_DOCTOR_SCRIPT' => $fixture . '/doctor.sh',
                ],
            );

            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('csp_headers.status=failed', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testWrapperFailsClosedOnEmptySuccessfulSshOutput(): void
    {
        $fixture = $this->createWrapperFixture(false, '', 0);

        try {
            $result = $this->runCommand(
                [
                    'bash',
                    'scripts/ops/prod_csp_report_only_status.sh',
                    '--expect',
                    'active',
                    '--prod-ssh-target',
                    'root@example.test',
                ],
                [
                    'PATH' => $fixture . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'CSP_REPORT_ONLY_DOCTOR_SCRIPT' => $fixture . '/doctor.sh',
                ],
            );

            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('"status":"runtime_failed"', $result['stdout']);
            self::assertStringNotContainsString('"status":"passed"', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testWrapperDoesNotRelayMalformedOrContradictoryReceipts(): void
    {
        $outputs = [
            'not-json private-token=never-relay',
            json_encode(
                [
                    'schema' => 'csp_report_only_status.v1',
                    'expectation' => 'inactive',
                    'status' => 'passed',
                    'config' => ['status' => 'missing', 'sha256' => null],
                    'aggregate' => ['status' => 'missing', 'summary' => null],
                    'private-token' => 'never-relay',
                ],
                JSON_THROW_ON_ERROR,
            ),
        ];

        foreach ($outputs as $output) {
            $fixture = $this->createWrapperFixture(false, $output, 0);
            try {
                $result = $this->runCommand(
                    [
                        'bash',
                        'scripts/ops/prod_csp_report_only_status.sh',
                        '--expect',
                        'active',
                        '--prod-ssh-target',
                        'root@example.test',
                    ],
                    [
                        'PATH' => $fixture . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                        'CSP_REPORT_ONLY_DOCTOR_SCRIPT' => $fixture . '/doctor.sh',
                    ],
                );

                self::assertSame(1, $result['exit_code']);
                self::assertStringContainsString('"status":"runtime_failed"', $result['stdout']);
                self::assertStringNotContainsString('never-relay', $result['stdout'] . $result['stderr']);
            } finally {
                $this->removeDirectory($fixture);
            }
        }
    }

    public function testWrapperCanonicalizesDuplicateKeysWithoutRelayingDiscardedValues(): void
    {
        $duplicateSchema = preg_replace(
            '/\A\{/',
            '{"schema":"private-token=never-relay",',
            $this->validActiveReceipt(),
            1,
        );
        self::assertIsString($duplicateSchema);
        $fixture = $this->createWrapperFixture(false, $duplicateSchema, 0);

        try {
            $result = $this->runCommand(
                [
                    'bash',
                    'scripts/ops/prod_csp_report_only_status.sh',
                    '--expect',
                    'active',
                    '--prod-ssh-target',
                    'root@example.test',
                ],
                [
                    'PATH' => $fixture . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'CSP_REPORT_ONLY_DOCTOR_SCRIPT' => $fixture . '/doctor.sh',
                ],
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('"status":"passed"', $result['stdout']);
            self::assertStringNotContainsString('never-relay', $result['stdout'] . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], '"schema":"csp_report_only_status.v1"'));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    private function createWrapperFixture(
        bool $enforcementPresent,
        ?string $remoteOutput = null,
        int $remoteExit = 0,
    ): string {
        $fixture = sys_get_temp_dir() . '/csp-wrapper-' . bin2hex(random_bytes(6));
        mkdir($fixture . '/bin', 0700, true);

        $enforcement = $enforcementPresent ? 'present' : 'missing';
        file_put_contents(
            $fixture . '/doctor.sh',
            "#!/usr/bin/env bash\n" .
                "printf '%s\\n' \\\n" .
                "  'posture_header.app_https.csp={$enforcement}' \\\n" .
                "  'posture_header.app_https.csp_report_only=present' \\\n" .
                "  'posture_header.www_https.csp=missing' \\\n" .
                "  'posture_header.www_https.csp_report_only=present' \\\n" .
                "  'posture_header.monitor_https.csp=missing' \\\n" .
                "  'posture_header.monitor_https.csp_report_only=missing'\n",
        );

        $remoteOutput ??= $this->validActiveReceipt();
        file_put_contents($fixture . '/ssh-output', $remoteOutput);
        file_put_contents($fixture . '/ssh-exit', (string) $remoteExit);
        file_put_contents(
            $fixture . '/bin/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            fixture="$(cd "$(dirname "$0")/.." && pwd)"
            cat "$fixture/ssh-output"
            exit "$(cat "$fixture/ssh-exit")"
            BASH
            ,
        );
        chmod($fixture . '/bin/ssh', 0755);

        return $fixture;
    }

    private function validActiveReceipt(): string
    {
        return json_encode(
            [
                'schema' => 'csp_report_only_status.v1',
                'expectation' => 'active',
                'status' => 'passed',
                'config' => [
                    'status' => 'active',
                    'sha256' => str_repeat('a', 64),
                ],
                'aggregate' => [
                    'status' => 'valid',
                    'summary' => [
                        'schema' => 'csp_report_only_aggregate.v1',
                        'status' => 'ok',
                        'updated_at_utc' => '2026-09-20T14:00:00+00:00',
                        'age_seconds' => 0,
                        'bucket_count' => 1,
                        'accepted' => 1,
                        'dropped' => ['invalid' => 0, 'rate_limited' => 0, 'storage_failed' => 0],
                        'classes' => [
                            'surface' => ['app' => 1, 'www' => 0],
                            'directive' => ['script-src' => 1],
                            'blocked_origin' => ['self' => 1],
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, array $env = []): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot(),
            array_merge($_ENV, $env),
        );
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

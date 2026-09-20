<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Csp_report_only;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'core/Csp_report_only.php';
defined('CSP_STATUS_LOAD_ONLY') || define('CSP_STATUS_LOAD_ONLY', true);
require_once dirname(__DIR__, 3) . '/scripts/ops/csp_report_only_status.php';

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

    public function testInactiveExpectationNeverRunsWriteProbeWhenConfigUnexpectedlyActive(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('The root-controlled active config contract is verified in the CI container.');
        }
        $directory = '/var/lib/fh-csp-status-active-config-' . bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);
        $configPath = $directory . '/config.json';
        copy($this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json', $configPath);
        chmod($configPath, 0644);
        $aggregatePath = $directory . '/aggregate.json';

        try {
            $result = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=inactive',
                '--config-path=' . $configPath,
                '--aggregate-path=' . $aggregatePath,
            ]);

            self::assertSame(1, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('"status":"failed"', $result['stdout']);
            $receipt = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('active', $receipt['config']['status']);
            self::assertSame('missing', $receipt['aggregate']['status']);
            self::assertSame([], glob($directory . '/.csp-status-probe-*'));
            self::assertFileDoesNotExist($aggregatePath);
            self::assertFileDoesNotExist($aggregatePath . '.lock');
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
            if (is_file($aggregatePath)) {
                unlink($aggregatePath);
            }
            if (is_file($aggregatePath . '.lock')) {
                unlink($aggregatePath . '.lock');
            }
            foreach (glob($directory . '/.csp-status-probe-*') ?: [] as $probe) {
                unlink($probe);
            }
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
            if (is_file($aggregatePath . '.lock')) {
                unlink($aggregatePath . '.lock');
            }
            if (isset($lockHardlink) && is_file($lockHardlink)) {
                unlink($lockHardlink);
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

    public function testWrapperAcceptsActiveConfigBeforeTheFirstAggregateExists(): void
    {
        $fixture = $this->createWrapperFixture(false, $this->validActiveMissingAggregateReceipt());

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
            self::assertStringContainsString('"aggregate":{"status":"missing","summary":null}', $result['stdout']);
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
        $aggregateDirectory = '/tmp/fh-csp-status-runtime-' . bin2hex(random_bytes(6));
        mkdir($aggregateDirectory, 0777, true);
        chmod($aggregateDirectory, 0777);
        $aggregatePath = $aggregateDirectory . '/aggregate.json';
        $rootOnlyDirectory = $directory . '/root-only';
        copy($this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json', $configPath);
        chmod($configPath, 0644);
        $fpm = $this->startIsolatedFpm();

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
            chmod($aggregatePath, 0666);
            chmod($aggregatePath . '.lock', 0666);

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
            self::assertSame([], glob($aggregateDirectory . '/.csp-status-probe-*'));

            chmod($aggregatePath . '.lock', 0000);
            $wrongLockMode = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=active',
                '--config-path=' . $configPath,
                '--aggregate-path=' . $aggregatePath,
            ]);
            self::assertSame(1, $wrongLockMode['exit_code'], $wrongLockMode['stderr']);
            chmod($aggregatePath . '.lock', 0666);

            $lockHardlink = $aggregatePath . '.lock-hardlink';
            link($aggregatePath . '.lock', $lockHardlink);
            $wrongLockIdentity = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=active',
                '--config-path=' . $configPath,
                '--aggregate-path=' . $aggregatePath,
            ]);
            self::assertSame(1, $wrongLockIdentity['exit_code'], $wrongLockIdentity['stderr']);
            unlink($lockHardlink);

            unlink($aggregatePath);
            unlink($aggregatePath . '.lock');
            $missingResult = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=active',
                '--config-path=' . $configPath,
                '--aggregate-path=' . $aggregatePath,
            ]);
            self::assertSame(0, $missingResult['exit_code'], $missingResult['stdout'] . $missingResult['stderr']);
            $missingReceipt = json_decode($missingResult['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('passed', $missingReceipt['status']);
            self::assertSame('missing', $missingReceipt['aggregate']['status']);

            mkdir($rootOnlyDirectory, 0755);
            $wrongRuntimeResult = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=active',
                '--config-path=' . $configPath,
                '--aggregate-path=' . $rootOnlyDirectory . '/aggregate.json',
            ]);
            self::assertSame(1, $wrongRuntimeResult['exit_code'], $wrongRuntimeResult['stderr']);
            $wrongRuntimeReceipt = json_decode($wrongRuntimeResult['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('failed', $wrongRuntimeReceipt['status']);
            self::assertSame('unavailable', $wrongRuntimeReceipt['aggregate']['status']);

            $missingParentResult = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=active',
                '--config-path=' . $configPath,
                '--aggregate-path=' . $directory . '/missing-parent/aggregate.json',
            ]);
            self::assertSame(1, $missingParentResult['exit_code'], $missingParentResult['stderr']);
            $missingParentReceipt = json_decode($missingParentResult['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('failed', $missingParentReceipt['status']);
            self::assertSame('unavailable', $missingParentReceipt['aggregate']['status']);
            self::assertFileDoesNotExist('/run/fh-csp-report-only-status');
        } finally {
            $this->stopIsolatedFpm($fpm);
            if (is_file($aggregatePath)) {
                unlink($aggregatePath);
            }
            if (is_file($aggregatePath . '.lock')) {
                unlink($aggregatePath . '.lock');
            }
            if (is_file($configPath)) {
                unlink($configPath);
            }
            if (is_dir($rootOnlyDirectory)) {
                rmdir($rootOnlyDirectory);
            }
            if (is_dir($aggregateDirectory)) {
                rmdir($aggregateDirectory);
            }
            rmdir($directory);
        }
    }

    public function testFpmProbeRejectsAnUnauthorisedDirectRequest(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('The FPM socket boundary requires a root-owned local harness.');
        }
        $fpm = $this->startIsolatedFpm();
        try {
            $runtime = \runtimeIdentity('www-data');
            self::assertIsArray($runtime);
            self::assertNull(
                \fastCgiProbe(
                    '/run/fh-csp-report-only-status/op-' . str_repeat('0', 32) . '/manifest.json',
                    str_repeat('a', 64),
                    $runtime,
                ),
            );
            self::assertFileDoesNotExist('/run/fh-csp-report-only-status');
        } finally {
            $this->stopIsolatedFpm($fpm);
        }
    }

    public function testFpmAuthorizationIsSingleUseAndExpiresClosed(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('The FPM authorization boundary requires a root-owned local harness.');
        }
        $fpm = $this->startIsolatedFpm();
        $configDirectory = '/var/lib/fh-csp-auth-test-' . bin2hex(random_bytes(6));
        $aggregateDirectory = '/tmp/fh-csp-auth-test-' . bin2hex(random_bytes(6));
        mkdir($configDirectory, 0755, true);
        mkdir($aggregateDirectory, 0777, true);
        chmod($aggregateDirectory, 0777);
        $configPath = $configDirectory . '/config.json';
        $aggregatePath = $aggregateDirectory . '/aggregate.json';
        copy($this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json', $configPath);
        chmod($configPath, 0644);
        $runtime = \runtimeIdentity('www-data');
        self::assertIsArray($runtime);

        try {
            $authorization = \createFpmAuthorization($configPath, $aggregatePath, $runtime);
            self::assertIsArray($authorization);
            try {
                $first = \fastCgiProbe($authorization['manifest_path'], $authorization['token'], $runtime);
                self::assertIsString($first);
                self::assertSame('missing', json_decode($first, true, 8, JSON_THROW_ON_ERROR)['status']);
                self::assertNull(
                    \fastCgiProbe($authorization['manifest_path'], $authorization['token'], $runtime),
                    'A consumed authorization must not be replayable.',
                );
            } finally {
                self::assertTrue(\cleanupFpmAuthorization($authorization, $runtime));
            }

            $expired = \createFpmAuthorization($configPath, $aggregatePath, $runtime);
            self::assertIsArray($expired);
            try {
                $manifest = json_decode(
                    (string) file_get_contents($expired['manifest_path']),
                    true,
                    8,
                    JSON_THROW_ON_ERROR,
                );
                $manifest['issued_at'] = time() - 30;
                $manifest['expires_at'] = $manifest['issued_at'] + 15;
                self::assertNotFalse(
                    file_put_contents(
                        $expired['manifest_path'],
                        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ),
                );
                self::assertNull(\fastCgiProbe($expired['manifest_path'], $expired['token'], $runtime));
            } finally {
                self::assertTrue(\cleanupFpmAuthorization($expired, $runtime));
            }
            self::assertFileDoesNotExist('/run/fh-csp-report-only-status');
        } finally {
            $this->stopIsolatedFpm($fpm);
            if (is_file($configPath)) {
                unlink($configPath);
            }
            if (is_file($aggregatePath)) {
                unlink($aggregatePath);
            }
            if (is_file($aggregatePath . '.lock')) {
                unlink($aggregatePath . '.lock');
            }
            rmdir($aggregateDirectory);
            rmdir($configDirectory);
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

    public function testWrapperRejectsActiveReceiptWithUnboundConfigHash(): void
    {
        $receipt = json_decode($this->validActiveReceipt(), true, 8, JSON_THROW_ON_ERROR);
        $receipt['config']['sha256'] = str_repeat('a', 64);
        $fixture = $this->createWrapperFixture(false, json_encode($receipt, JSON_THROW_ON_ERROR), 0);

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
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testReceiptValidatorFailsClosedWhenCandidateBindingIsUnavailable(): void
    {
        $directory = sys_get_temp_dir() . '/csp-receipt-validator-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $validator = $directory . '/validator.php';
        copy($this->repoRoot() . '/scripts/ops/csp_report_only_validate_receipt.php', $validator);
        $process = proc_open(
            [PHP_BINARY, $validator, '--expect=active'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $this->validActiveReceipt());
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        try {
            self::assertSame(1, $exitCode);
            self::assertSame('', $stdout);
        } finally {
            unlink($validator);
            rmdir($directory);
        }
    }

    /** @return array{process:resource,directory:string,created_run_php:bool} */
    private function startIsolatedFpm(): array
    {
        $runtime = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
        if (!is_array($runtime)) {
            self::markTestSkipped('The www-data runtime identity is unavailable.');
        }
        $binary = null;
        foreach (['/usr/local/sbin/php-fpm', '/usr/sbin/php-fpm8.5'] as $candidate) {
            if (is_executable($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if ($binary === null) {
            self::markTestSkipped('A PHP-FPM binary is unavailable.');
        }
        $socket = '/run/php/php8.5-fpm.sock';
        if (@lstat($socket) !== false) {
            self::fail('The isolated test refuses to reuse an existing production-style FPM socket.');
        }
        $createdRunPhp = false;
        if (!is_dir('/run/php')) {
            self::assertTrue(mkdir('/run/php', 0755));
            $createdRunPhp = true;
        } else {
            $runPhp = lstat('/run/php');
            self::assertIsArray($runPhp);
            self::assertSame(0040000, ($runPhp['mode'] ?? 0) & 0170000);
            self::assertSame(0, $runPhp['uid'] ?? -1);
            self::assertSame(0, $runPhp['gid'] ?? -1);
            self::assertSame(0755, ($runPhp['mode'] ?? 0) & 0777);
        }
        self::assertSame(0, posix_geteuid());
        if ($createdRunPhp) {
            self::assertTrue(chown('/run/php', 0));
            self::assertTrue(chgrp('/run/php', 0));
            self::assertTrue(chmod('/run/php', 0755));
        }

        $directory = '/tmp/csp-fpm-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $config =
            implode("\n", [
                '[global]',
                'daemonize = no',
                'pid = ' . $directory . '/php-fpm.pid',
                'error_log = ' . $directory . '/php-fpm.log',
                '[www]',
                'user = www-data',
                'group = www-data',
                'listen = ' . $socket,
                'listen.owner = www-data',
                'listen.group = www-data',
                'listen.mode = 0660',
                'pm = static',
                'pm.max_children = 1',
                'pm.max_requests = 20',
                'catch_workers_output = yes',
                'clear_env = no',
                'security.limit_extensions = .php',
            ]) . "\n";
        self::assertNotFalse(file_put_contents($directory . '/php-fpm.conf', $config));
        $process = proc_open(
            [$binary, '-F', '-y', $directory . '/php-fpm.conf'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        for ($attempt = 0; $attempt < 50 && @lstat($socket) === false; $attempt++) {
            usleep(100000);
        }
        if (@lstat($socket) === false) {
            proc_terminate($process);
            proc_close($process);
            self::fail('The isolated PHP-FPM socket did not become ready.');
        }
        return ['process' => $process, 'directory' => $directory, 'created_run_php' => $createdRunPhp];
    }

    /** @param array{process:resource,directory:string,created_run_php:bool} $fpm */
    private function stopIsolatedFpm(array $fpm): void
    {
        proc_terminate($fpm['process']);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = proc_get_status($fpm['process']);
            if (!is_array($status) || !$status['running']) {
                break;
            }
            usleep(50000);
        }
        proc_close($fpm['process']);
        foreach (['php8.5-fpm.sock', 'php-fpm.pid'] as $leaf) {
            $path = $leaf === 'php8.5-fpm.sock' ? '/run/php/' . $leaf : $fpm['directory'] . '/' . $leaf;
            if (
                is_file($path) ||
                (($identity = @lstat($path)) !== false && (($identity['mode'] ?? 0) & 0170000) === 0140000)
            ) {
                unlink($path);
            }
        }
        foreach (['php-fpm.conf', 'php-fpm.log'] as $leaf) {
            $path = $fpm['directory'] . '/' . $leaf;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($fpm['directory']);
        if ($fpm['created_run_php']) {
            rmdir('/run/php');
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
                    'sha256' => hash_file(
                        'sha256',
                        $this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json',
                    ),
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

    private function validActiveMissingAggregateReceipt(): string
    {
        return json_encode(
            [
                'schema' => 'csp_report_only_status.v1',
                'expectation' => 'active',
                'status' => 'passed',
                'config' => [
                    'status' => 'active',
                    'sha256' => hash_file(
                        'sha256',
                        $this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json',
                    ),
                ],
                'aggregate' => [
                    'status' => 'missing',
                    'summary' => null,
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

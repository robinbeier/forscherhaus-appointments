<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Csp_report_only;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'core/Csp_report_only.php';
defined('CSP_STATUS_LOAD_ONLY') || define('CSP_STATUS_LOAD_ONLY', true);
require_once dirname(__DIR__, 3) . '/scripts/ops/csp_report_only_status.php';
defined('CSP_RUNTIME_PROBE_LOAD_ONLY') || define('CSP_RUNTIME_PROBE_LOAD_ONLY', true);
require_once dirname(__DIR__, 3) . '/scripts/ops/csp_report_only_runtime_probe.php';

final class CspReportOnlyStatusScriptTest extends TestCase
{
    public function testInactiveCliReceiptIsReadOnlyAndUsesFixedClasses(): void
    {
        $directory = $this->createReleaseRoot('inactive');

        try {
            $result = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=inactive',
                '--config-path=' . $directory . '/missing-config.json',
                '--aggregate-path=' . $directory . '/missing-aggregate.json',
                '--release-root=' . $directory,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $receipt = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('csp_report_only_state.v2', $receipt['schema']);
            self::assertSame('passed', $receipt['status']);
            self::assertSame('state_verified', $receipt['result_class']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $receipt['release_binding']);
            self::assertSame('inactive', $receipt['activation']['status']);
            self::assertSame('missing', $receipt['aggregate']['status']);
            self::assertSame([], glob($directory . '/.csp-runtime-readiness-*'));
            self::assertFileDoesNotExist($directory . '/missing-aggregate.json');
            self::assertFileDoesNotExist($directory . '/missing-aggregate.json.lock');
            self::assertStringNotContainsString($directory, $result['stdout'] . $result['stderr']);
        } finally {
            unlink($directory . '/_RELEASE');
            rmdir($directory);
        }
    }

    public function testInactiveExpectationClassifiesUnexpectedActivationWithoutWriting(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('The root-controlled activation identity is verified in the CI container.');
        }
        $directory = '/var/lib/fh-csp-status-' . bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);
        file_put_contents($directory . '/_RELEASE', "ea_test_unexpected\n");
        chmod($directory . '/_RELEASE', 0644);
        $configPath = $directory . '/config.json';
        copy($this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json', $configPath);
        chmod($configPath, 0644);

        try {
            $result = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=inactive',
                '--config-path=' . $configPath,
                '--aggregate-path=' . $directory . '/aggregate.json',
                '--release-root=' . $directory,
            ]);

            self::assertSame(1, $result['exit_code'], $result['stderr']);
            $receipt = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('activation_unexpected', $receipt['result_class']);
            self::assertSame('active', $receipt['activation']['status']);
            self::assertSame('missing', $receipt['aggregate']['status']);
            self::assertFileDoesNotExist($directory . '/aggregate.json');
            self::assertFileDoesNotExist($directory . '/aggregate.json.lock');
        } finally {
            unlink($configPath);
            unlink($directory . '/_RELEASE');
            rmdir($directory);
        }
    }

    public function testReadOnlyStateSummarizesAClassifiedAggregate(): void
    {
        $directory = $this->createReleaseRoot('aggregate');
        $aggregatePath = $directory . '/aggregate.json';
        $now = time();

        try {
            $recorded = Csp_report_only::record(
                [
                    'surface' => 'www',
                    'directive' => 'img-src',
                    'blocked_origin' => 'self',
                    'disposition' => 'report',
                ],
                ['max_reports_per_minute' => 2, 'retention_hours' => 48],
                $aggregatePath,
                $now,
            );
            self::assertSame('accepted', $recorded['status']);

            $result = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=inactive',
                '--config-path=' . $directory . '/missing-config.json',
                '--aggregate-path=' . $aggregatePath,
                '--release-root=' . $directory,
            ]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $receipt = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('valid', $receipt['aggregate']['status']);
            self::assertSame(1, $receipt['aggregate']['summary']['accepted']);
            self::assertSame(1, $receipt['aggregate']['summary']['classes']['surface']['www']);
        } finally {
            @unlink($aggregatePath);
            @unlink($aggregatePath . '.lock');
            unlink($directory . '/_RELEASE');
            rmdir($directory);
        }
    }

    public function testReadOnlyStateClassifiesMissingLock(): void
    {
        $directory = $this->createReleaseRoot('lock');
        $aggregatePath = $directory . '/aggregate.json';
        file_put_contents($aggregatePath, '{}');

        try {
            $result = $this->runCommand([
                PHP_BINARY,
                'scripts/ops/csp_report_only_status.php',
                '--expect=inactive',
                '--config-path=' . $directory . '/missing-config.json',
                '--aggregate-path=' . $aggregatePath,
                '--release-root=' . $directory,
            ]);
            self::assertSame(1, $result['exit_code']);
            $receipt = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('aggregate_lock_missing', $receipt['result_class']);
            self::assertSame('failed', $receipt['aggregate']['status']);
        } finally {
            unlink($aggregatePath);
            unlink($directory . '/_RELEASE');
            rmdir($directory);
        }
    }

    public function testAggregateSnapshotIsTakenAfterTheWriterLock(): void
    {
        $directory = sys_get_temp_dir() . '/csp-status-race-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $aggregatePath = $directory . '/aggregate.json';
        $replacementPath = $directory . '/replacement.json';
        $markerPath = $directory . '/writer-ready';
        $recorded = Csp_report_only::record(
            [
                'surface' => 'app',
                'directive' => 'script-src',
                'blocked_origin' => 'self',
                'disposition' => 'report',
            ],
            ['max_reports_per_minute' => 2, 'retention_hours' => 48],
            $aggregatePath,
            time(),
        );
        self::assertSame('accepted', $recorded['status']);
        copy($aggregatePath, $replacementPath);

        $child = proc_open(
            [
                PHP_BINARY,
                '-r',
                <<<'PHP'
                $lock = fopen($argv[1] . '.lock', 'r+b');
                flock($lock, LOCK_EX);
                file_put_contents($argv[2], 'ready');
                usleep(200000);
                rename($argv[3], $argv[1]);
                flock($lock, LOCK_UN);
                fclose($lock);
                PHP
                ,
                $aggregatePath,
                $markerPath,
                $replacementPath,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 2;
            while (!is_file($markerPath) && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($markerPath);
            $result = \inspectAggregate($aggregatePath, null);
            self::assertSame('valid', $result['status']);
            self::assertNull($result['result_class']);
        } finally {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($child));
            @unlink($aggregatePath);
            @unlink($replacementPath);
            @unlink($aggregatePath . '.lock');
            @unlink($markerPath);
            rmdir($directory);
        }
    }

    public function testPreflightKeepsRuntimeProbeNotRunAndSeparatesEvidence(): void
    {
        $fixture = $this->createWrapperFixture('preflight');
        try {
            $result = $this->runWrapper($fixture, 'preflight');
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('csp_evidence.public_headers.status=passed', $result['stdout']);
            self::assertStringContainsString('csp_evidence.functional_health.status=passed', $result['stdout']);
            self::assertStringContainsString('csp_evidence.activation.status=passed', $result['stdout']);
            self::assertStringContainsString('csp_evidence.activation_prerequisites.status=passed', $result['stdout']);
            self::assertStringContainsString('csp_evidence.aggregate.status=passed', $result['stdout']);
            self::assertStringContainsString('csp_evidence.runtime_write_readiness.status=not_run', $result['stdout']);
            self::assertStringContainsString('csp_evidence.status=passed', $result['stdout']);
            self::assertSame('2', trim((string) file_get_contents($fixture . '/ssh-count')));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testActiveEvidenceUsesTheHostLocalWebRuntimeProbe(): void
    {
        $fixture = $this->createWrapperFixture('active');
        try {
            $result = $this->runWrapper($fixture, 'active');
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('csp_evidence.runtime_write_readiness.status=passed', $result['stdout']);
            self::assertStringContainsString('"schema":"csp_report_only_runtime_readiness.v1"', $result['stdout']);
            self::assertSame('2', trim((string) file_get_contents($fixture . '/ssh-count')));
            self::assertStringNotContainsString('/run/php', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('secret', strtolower($result['stdout'] . $result['stderr']));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testWrapperUsesDistinctHeaderFailureClass(): void
    {
        $fixture = $this->createWrapperFixture('active', true);
        try {
            $result = $this->runWrapper($fixture, 'active');
            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString(
                'csp_evidence.public_headers.result_class=header_posture_mismatch',
                $result['stdout'],
            );
            self::assertStringContainsString('csp_evidence.result_class=evidence_incomplete', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testWrapperRejectsPassedReceiptWithNonzeroRemoteExit(): void
    {
        $fixture = $this->createWrapperFixture('active', false, 1);
        try {
            $result = $this->runWrapper($fixture, 'active');
            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString(
                'csp_evidence.activation.result_class=state_receipt_contradictory',
                $result['stdout'],
            );
            self::assertStringContainsString('csp_evidence.status=failed', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testValidatorsRejectUnclassifiedOrAdditionalData(): void
    {
        $receipt = json_decode($this->validStateReceipt('active'), true, 8, JSON_THROW_ON_ERROR);
        $receipt['private'] = 'must-not-pass';
        $state = $this->runCommand(
            [PHP_BINARY, 'scripts/ops/csp_report_only_validate_receipt.php', '--expect=active'],
            [],
            json_encode($receipt, JSON_THROW_ON_ERROR),
        );
        self::assertSame(1, $state['exit_code']);
        self::assertSame('', $state['stdout']);

        $bindingMismatch = $this->runCommand(
            [
                PHP_BINARY,
                'scripts/ops/csp_report_only_validate_receipt.php',
                '--expect=active',
                '--expected-release-binding=' . str_repeat('b', 64),
            ],
            [],
            $this->validStateReceipt('active'),
        );
        self::assertSame(1, $bindingMismatch['exit_code']);
        self::assertSame('', $bindingMismatch['stdout']);

        $runtime = $this->runCommand(
            [PHP_BINARY, 'scripts/ops/csp_report_only_runtime_validate_receipt.php'],
            [],
            '{"schema":"csp_report_only_runtime_readiness.v1","status":"failed","result_class":"raw_error"}',
        );
        self::assertSame(1, $runtime['exit_code']);
        self::assertSame('', $runtime['stdout']);
    }

    public function testRuntimePayloadContractAcceptsOnlyFixedClasses(): void
    {
        self::assertTrue(
            \validRuntimePayload([
                'schema' => 'csp_report_only_runtime_readiness.v1',
                'status' => 'passed',
                'result_class' => 'write_ready',
            ]),
        );
        self::assertFalse(
            \validRuntimePayload([
                'schema' => 'csp_report_only_runtime_readiness.v1',
                'status' => 'passed',
                'result_class' => 'probe_write_failed',
            ]),
        );
    }

    public function testRuntimeClientPinsNumericLoopbackAndDisablesProxies(): void
    {
        self::assertSame('http://127.0.0.1/index.php/healthz/csp-report-only-write-readiness', CSP_RUNTIME_URL);
        $options = \runtimeCurlOptions('test-token-value');
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    private function createWrapperFixture(string $phase, bool $headerMismatch = false, int $stateExit = 0): string
    {
        $fixture = sys_get_temp_dir() . '/csp-wrapper-' . bin2hex(random_bytes(6));
        mkdir($fixture . '/bin', 0700, true);
        $reportOnly = $phase === 'active' ? 'present' : 'missing';
        $appCsp = $headerMismatch ? 'present' : 'missing';
        file_put_contents(
            $fixture . '/doctor.sh',
            "#!/usr/bin/env bash\n" .
                "printf '%s\\n' \\\n" .
                "  'app_https=200' 'www_https=200' 'monitor_https=302' 'renderer_http=200' 'deep_health_http=200' \\\n" .
                "  'posture_header.app_https.csp={$appCsp}' 'posture_header.app_https.csp_report_only={$reportOnly}' \\\n" .
                "  'posture_header.www_https.csp=missing' 'posture_header.www_https.csp_report_only={$reportOnly}' \\\n" .
                "  'posture_header.monitor_https.csp=missing' 'posture_header.monitor_https.csp_report_only=missing'\n",
        );
        chmod($fixture . '/doctor.sh', 0755);
        file_put_contents($fixture . '/phase', $phase);
        file_put_contents($fixture . '/state-exit', (string) $stateExit);
        file_put_contents(
            $fixture . '/state-output',
            $this->validStateReceipt($phase === 'active' ? 'active' : 'inactive'),
        );
        file_put_contents(
            $fixture . '/activation-output',
            json_encode(
                [
                    'schema' => 'csp_report_only_activation.v2',
                    'action' => 'preflight',
                    'status' => 'passed',
                    'result_class' => 'preflight_ready',
                    'candidate_sha256' => hash_file(
                        'sha256',
                        $this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json',
                    ),
                    'release_binding' => str_repeat('a', 64),
                    'run_id' => null,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        file_put_contents(
            $fixture . '/runtime-output',
            json_encode(
                [
                    'schema' => 'csp_report_only_runtime_readiness.v1',
                    'status' => 'passed',
                    'result_class' => 'write_ready',
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        file_put_contents($fixture . '/ssh-count', '0');
        file_put_contents(
            $fixture . '/bin/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            fixture="$(cd "$(dirname "$0")/.." && pwd)"
            count="$(cat "$fixture/ssh-count")"
            count=$((count + 1))
            printf '%s' "$count" >"$fixture/ssh-count"
            if [[ "$count" == '1' ]]; then
                cat "$fixture/state-output"
                exit "$(cat "$fixture/state-exit")"
            elif [[ "$(cat "$fixture/phase")" == 'preflight' ]]; then
                cat "$fixture/activation-output"
            else
                cat "$fixture/runtime-output"
            fi
            BASH
            ,
        );
        chmod($fixture . '/bin/ssh', 0755);
        return $fixture;
    }

    private function validStateReceipt(string $expectation): string
    {
        $active = $expectation === 'active';
        return json_encode(
            [
                'schema' => 'csp_report_only_state.v2',
                'expectation' => $expectation,
                'status' => 'passed',
                'result_class' => 'state_verified',
                'release_binding' => str_repeat('a', 64),
                'activation' => [
                    'status' => $active ? 'active' : 'inactive',
                    'sha256' => $active
                        ? hash_file(
                            'sha256',
                            $this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json',
                        )
                        : null,
                ],
                'aggregate' => ['status' => 'missing', 'summary' => null],
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    private function runWrapper(string $fixture, string $phase): array
    {
        return $this->runCommand(
            [
                'bash',
                'scripts/ops/prod_csp_report_only_status.sh',
                '--phase',
                $phase,
                '--prod-ssh-target',
                'root@example.test',
            ],
            [
                'PATH' => $fixture . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                'CSP_REPORT_ONLY_DOCTOR_SCRIPT' => $fixture . '/doctor.sh',
            ],
        );
    }

    /** @param list<string> $command @param array<string,string> $env */
    private function runCommand(array $command, array $env = [], string $stdin = ''): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot(),
            array_merge($_ENV, $env),
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
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

    private function createReleaseRoot(string $suffix): string
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Root-owned release identity is verified in the CI container.');
        }
        $directory = '/var/lib/fh-csp-status-' . $suffix . '-' . bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);
        file_put_contents($directory . '/_RELEASE', 'ea_test_' . $suffix . "\n");
        chmod($directory . '/_RELEASE', 0644);
        return $directory;
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

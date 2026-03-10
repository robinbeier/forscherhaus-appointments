<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/check_pdf_renderer_latency.php';

class PdfRendererLatencyGateTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/pdf-renderer-latency-' . uniqid('', true);

        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for pdf renderer latency tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testEvaluatePdfRendererLatencyPassesWithinWarnThresholds(): void
    {
        $evaluation = evaluatePdfRendererLatency([810.0, 830.0, 845.0, 860.0, 880.0], $this->policy());

        self::assertSame('pass', $evaluation['status']);
        self::assertSame([], $evaluation['messages']);
        self::assertSame(845.0, $evaluation['metrics']['p50_ms']);
        self::assertSame(880.0, $evaluation['metrics']['p95_ms']);
    }

    public function testEvaluatePdfRendererLatencyWarnsWhenP95ExceedsWarnThreshold(): void
    {
        $evaluation = evaluatePdfRendererLatency([900.0, 950.0, 1000.0, 1400.0, 2100.0], $this->policy());

        self::assertSame('warn', $evaluation['status']);
        self::assertStringContainsString('warn threshold', implode(' ', $evaluation['messages']));
    }

    public function testEvaluatePdfRendererLatencyFailsWhenP50ExceedsFailThreshold(): void
    {
        $evaluation = evaluatePdfRendererLatency([2000.0, 2300.0, 2400.0, 2500.0, 2600.0], $this->policy());

        self::assertSame('fail', $evaluation['status']);
        self::assertStringContainsString('fail threshold', implode(' ', $evaluation['messages']));
    }

    public function testMeasurePdfRendererLatencySupportsDeterministicInjectedRequester(): void
    {
        $postCalls = 0;

        $requester = static function (
            string $method,
            string $url,
            ?string $body,
            int $timeoutSeconds,
            array $headers,
        ) use (&$postCalls): array {
            self::assertGreaterThan(0, $timeoutSeconds);
            self::assertNotSame('', $url);
            self::assertNotSame([], $headers);

            if ($method === 'GET') {
                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '{"ok":true}',
                ];
            }

            $postCalls++;
            self::assertNotNull($body);
            self::assertStringContainsString('PDF Renderer Latency Fixture', $body);

            return [
                'status' => 200,
                'headers' => ['content-type' => 'application/pdf'],
                'body' => '%PDF-1.4 fixture',
            ];
        };

        $result = measurePdfRendererLatency(
            [
                'base_url' => 'http://localhost:3003',
                'pdf_endpoint' => '/pdf',
                'health_endpoint' => '/healthz',
                'iterations' => 3,
                'warmup_iterations' => 1,
                'timeout_seconds' => 5,
                'retry_count' => 2,
                'skip_health_check' => false,
            ],
            $requester,
        );

        self::assertCount(4, $result['samples']);
        self::assertCount(3, $result['measured_durations_ms']);
        self::assertSame(4, $postCalls);
    }

    public function testMeasurePdfRendererLatencyRetriesTransientPdfResponseByRetryCount(): void
    {
        $postCalls = 0;

        $requester = static function (
            string $method,
            string $url,
            ?string $body,
            int $timeoutSeconds,
            array $headers,
        ) use (&$postCalls): array {
            self::assertGreaterThan(0, $timeoutSeconds);
            self::assertNotSame('', $url);
            self::assertNotSame([], $headers);

            if ($method === 'GET') {
                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '{"ok":true}',
                ];
            }

            $postCalls++;
            self::assertNotNull($body);

            if ($postCalls === 1) {
                return [
                    'status' => 503,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '{"error":"temporary"}',
                ];
            }

            return [
                'status' => 200,
                'headers' => ['content-type' => 'application/pdf'],
                'body' => '%PDF-1.4 fixture',
            ];
        };

        $result = measurePdfRendererLatency(
            [
                'base_url' => 'http://localhost:3003',
                'pdf_endpoint' => '/pdf',
                'health_endpoint' => '/healthz',
                'iterations' => 1,
                'warmup_iterations' => 0,
                'timeout_seconds' => 5,
                'retry_count' => 1,
                'skip_health_check' => false,
            ],
            $requester,
        );

        self::assertCount(1, $result['samples']);
        self::assertCount(1, $result['measured_durations_ms']);
        self::assertSame(2, $postCalls);
    }

    public function testRunPdfRendererLatencyCliFailsForUnknownOption(): void
    {
        $outputFile = $this->tmpDir . '/pdf-renderer-latency-error.json';
        $customPolicy = $this->tmpDir . '/custom-policy.php';

        file_put_contents(
            $customPolicy,
            <<<'PHP'
            <?php
            return [
                'min_samples' => 1,
                'warn' => ['p50_ms' => 1000.0, 'p95_ms' => 1000.0],
                'fail' => ['p50_ms' => 2000.0, 'p95_ms' => 2000.0],
                'max_stddev_ms' => 1000.0,
            ];
            PHP
            ,
        );

        $exitCode = runPdfRendererLatencyCli([
            'check_pdf_renderer_latency.php',
            '--base-url=http://latency.invalid:8123',
            '--pdf-endpoint=/custom-pdf',
            '--health-endpoint=/custom-health',
            '--policy=' . $customPolicy,
            '--output-json=' . $outputFile,
            '--bogus',
        ]);

        $report = $this->readReport($outputFile);

        self::assertSame(PDF_RENDERER_LATENCY_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertSame('error', $report['status']);
        self::assertStringContainsString('Unknown CLI option', (string) $report['error']['message']);
        self::assertSame('http://latency.invalid:8123', $report['base_url']);
        self::assertSame('/custom-pdf', $report['pdf_endpoint']);
        self::assertSame('/custom-health', $report['health_endpoint']);
        self::assertSame($customPolicy, $report['policy_file']);
    }

    public function testLoadPdfRendererLatencyPolicyUsesRepositoryDefaults(): void
    {
        $policy = loadPdfRendererLatencyPolicy($this->repoPolicyPath());

        self::assertSame(5, $policy['min_samples']);
        self::assertSame(3000.0, $policy['warn']['p50_ms']);
        self::assertSame(4500.0, $policy['warn']['p95_ms']);
        self::assertSame(3500.0, $policy['fail']['p50_ms']);
        self::assertSame(6500.0, $policy['fail']['p95_ms']);
        self::assertSame(1200.0, $policy['max_stddev_ms']);
    }

    /**
     * @return array{
     *   min_samples:int,
     *   warn:array{p50_ms:float,p95_ms:float},
     *   fail:array{p50_ms:float,p95_ms:float},
     *   max_stddev_ms:float
     * }
     */
    private function policy(): array
    {
        return [
            'min_samples' => 5,
            'warn' => [
                'p50_ms' => 1200.0,
                'p95_ms' => 1800.0,
            ],
            'fail' => [
                'p50_ms' => 2200.0,
                'p95_ms' => 2600.0,
            ],
            'max_stddev_ms' => 500.0,
        ];
    }

    private function repoPolicyPath(): string
    {
        return __DIR__ . '/../../../scripts/ci/config/pdf_renderer_latency_policy.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}

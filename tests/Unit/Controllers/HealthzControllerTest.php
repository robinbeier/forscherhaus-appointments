<?php

namespace Tests\Unit\Controllers;

use Healthz;
use RuntimeException;
use Tests\TestCase;

require_once APPPATH . 'controllers/Healthz.php';

class HealthzControllerTest extends TestCase
{
    public function testCspWriteReadinessRouteHasAnExactCsrfExemption(): void
    {
        $excluded = config_item('csrf_exclude_uris');

        self::assertIsArray($excluded);
        self::assertContains('healthz/csp-report-only-write-readiness', $excluded);
        self::assertSame(
            1,
            count(
                array_filter(
                    $excluded,
                    static fn(mixed $uri): bool => $uri === 'healthz/csp-report-only-write-readiness',
                ),
            ),
        );
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_HEALTH_TOKEN'], $_SERVER['REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_ENV['HEALTHZ_TOKEN']);
        get_instance()->output->set_output('');

        parent::tearDown();
    }

    public function testResolvePdfRendererEndpointsKeepsConfiguredEndpointFirstAndUnique(): void
    {
        $hadOriginal = array_key_exists('PDF_RENDERER_URL', $_ENV);
        $original = $_ENV['PDF_RENDERER_URL'] ?? null;

        try {
            $_ENV['PDF_RENDERER_URL'] = ' http://localhost:3003/ ';

            $controller = $this->createController();
            $endpoints = $controller->callResolvePdfRendererEndpoints();

            $this->assertSame(
                ['http://localhost:3003', 'http://pdf-renderer:3000', 'http://127.0.0.1:3003'],
                $endpoints,
            );
        } finally {
            if ($hadOriginal) {
                $_ENV['PDF_RENDERER_URL'] = $original;
            } else {
                unset($_ENV['PDF_RENDERER_URL']);
            }
        }
    }

    public function testRunCheckReturnsSuccessPayloadWithDetails(): void
    {
        $controller = $this->createController();

        $result = $controller->callRunCheck(static function (): array {
            usleep(1000);

            return ['ping' => 1];
        });

        $this->assertTrue($result['ok']);
        $this->assertSame(['ping' => 1], $result['details']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
    }

    public function testRunCheckWrapsExceptionAsFailedPayload(): void
    {
        $controller = $this->createController();

        $result = $controller->callRunCheck(static function (): void {
            throw new RuntimeException('pdf endpoint down');
        });

        $this->assertFalse($result['ok']);
        $this->assertSame('pdf endpoint down', $result['message']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
    }

    public function testCacheControlHeadersAreNoCache(): void
    {
        $controller = $this->createController();

        $this->assertSame(
            ['Cache-Control: no-store, no-cache, must-revalidate', 'Pragma: no-cache'],
            $controller->callCacheControlHeaders(),
        );
    }

    public function testResolvePdfRendererEndpointsKeepsLocalhostFallbackOutsideLocalEnvironment(): void
    {
        $hadPdfRendererUrl = array_key_exists('PDF_RENDERER_URL', $_ENV);
        $pdfRendererUrl = $_ENV['PDF_RENDERER_URL'] ?? null;

        try {
            $_ENV['PDF_RENDERER_URL'] = 'http://example.com:3000/';

            $controller = $this->createController(false);
            $endpoints = $controller->callResolvePdfRendererEndpoints();

            $this->assertSame(
                ['http://example.com:3000', 'http://pdf-renderer:3000', 'http://localhost:3003'],
                $endpoints,
            );
        } finally {
            if ($hadPdfRendererUrl) {
                $_ENV['PDF_RENDERER_URL'] = $pdfRendererUrl;
            } else {
                unset($_ENV['PDF_RENDERER_URL']);
            }
        }
    }

    public function testResolvePdfRendererEndpointsKeepsExplicitLoopbackOutsideLocalEnvironment(): void
    {
        $hadOriginal = array_key_exists('PDF_RENDERER_URL', $_ENV);
        $original = $_ENV['PDF_RENDERER_URL'] ?? null;

        try {
            $_ENV['PDF_RENDERER_URL'] = 'http://localhost:3003/';

            $controller = $this->createController(false);
            $endpoints = $controller->callResolvePdfRendererEndpoints();

            $this->assertSame(['http://localhost:3003', 'http://pdf-renderer:3000'], $endpoints);
        } finally {
            if ($hadOriginal) {
                $_ENV['PDF_RENDERER_URL'] = $original;
            } else {
                unset($_ENV['PDF_RENDERER_URL']);
            }
        }
    }

    public function testResolvePdfTimeoutOptionsUsesShorterMsTimeoutForNonLocalLoopback(): void
    {
        $controller = $this->createController(false);
        $options = $controller->callResolvePdfTimeoutOptions('http://localhost:3003');

        $this->assertSame(
            [
                CURLOPT_CONNECTTIMEOUT_MS => 250,
                CURLOPT_TIMEOUT_MS => 500,
            ],
            $options,
        );
    }

    public function testResolvePdfTimeoutOptionsUsesShorterMsTimeoutForNonLocalLoopbackIp(): void
    {
        $controller = $this->createController(false);
        $options = $controller->callResolvePdfTimeoutOptions('http://127.0.0.1:3003');

        $this->assertSame(
            [
                CURLOPT_CONNECTTIMEOUT_MS => 250,
                CURLOPT_TIMEOUT_MS => 500,
            ],
            $options,
        );
    }

    public function testResolvePdfTimeoutOptionsKeepsDefaultTimeoutsInLocalEnvironment(): void
    {
        $controller = $this->createController(true);
        $options = $controller->callResolvePdfTimeoutOptions('http://localhost:3003');

        $this->assertSame(
            [
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 2,
            ],
            $options,
        );
    }

    public function testResolvePdfTimeoutOptionsUsesDefaultSecondTimeoutsForRendererService(): void
    {
        $controller = $this->createController(false);
        $options = $controller->callResolvePdfTimeoutOptions('http://pdf-renderer:3000');

        $this->assertSame(
            [
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 2,
            ],
            $options,
        );
    }

    public function testCheckPdfRendererReportsCurlInitFailuresPerEndpoint(): void
    {
        $controller = $this->createController(
            true,
            ['http://first.invalid', 'http://second.invalid'],
            [],
            [
                'http://first.invalid/healthz' => false,
                'http://second.invalid/healthz' => false,
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'No healthy PDF renderer endpoint found: http://first.invalid -> curl_init_failed; http://second.invalid -> curl_init_failed',
        );

        $controller->callCheckPdfRenderer();
    }

    public function testCheckPdfRendererReportsNoReachableEndpointWhenListIsEmpty(): void
    {
        $controller = $this->createController(true, [], [], []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No healthy PDF renderer endpoint found: no_reachable_endpoint');

        $controller->callCheckPdfRenderer();
    }

    public function testCheckPdfRendererRejectsNonJsonSuccessOnNonLocalLoopback(): void
    {
        $controller = $this->createController(
            false,
            ['http://localhost:3003'],
            [
                'http://localhost:3003/healthz' => [
                    'body' => 'ok',
                    'status' => 200,
                ],
            ],
            [],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'No healthy PDF renderer endpoint found: http://localhost:3003 -> invalid_health_payload',
        );

        $controller->callCheckPdfRenderer();
    }

    public function testCheckPdfRendererRejectsNonJsonSuccessOnNonLocalLoopbackIp(): void
    {
        $controller = $this->createController(
            false,
            ['http://127.0.0.1:3003'],
            [
                'http://127.0.0.1:3003/healthz' => [
                    'body' => 'ok',
                    'status' => 200,
                ],
            ],
            [],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'No healthy PDF renderer endpoint found: http://127.0.0.1:3003 -> invalid_health_payload',
        );

        $controller->callCheckPdfRenderer();
    }

    public function testCheckPdfRendererAcceptsJsonSuccessOnNonLocalLoopback(): void
    {
        $controller = $this->createController(
            false,
            ['http://localhost:3003'],
            [
                'http://localhost:3003/healthz' => [
                    'body' => '{"ok":true}',
                    'status' => 200,
                ],
            ],
            [],
        );

        $result = $controller->callCheckPdfRenderer();

        $this->assertSame(
            [
                'endpoint' => 'http://localhost:3003',
                'status_code' => 200,
            ],
            $result,
        );
    }

    public function testCspWriteReadinessRequiresPost(): void
    {
        $_ENV['HEALTHZ_TOKEN'] = 'health-token';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_HEALTH_TOKEN'] = 'health-token';
        $controller = $this->createCspReadinessController(
            ['status' => 'passed', 'result_class' => 'write_ready'],
            true,
        );

        $controller->csp_report_only_write_readiness();

        $this->assertSame(
            [
                'schema' => 'csp_report_only_runtime_readiness.v1',
                'status' => 'failed',
                'result_class' => 'method_not_allowed',
            ],
            json_decode(get_instance()->output->get_output(), true, 8, JSON_THROW_ON_ERROR),
        );
        $this->assertFalse($controller->probeCalled);
    }

    public function testCspWriteReadinessRejectsMissingToken(): void
    {
        $_ENV['HEALTHZ_TOKEN'] = 'health-token';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $controller = $this->createCspReadinessController(
            ['status' => 'passed', 'result_class' => 'write_ready'],
            false,
        );

        $controller->csp_report_only_write_readiness();

        $payload = json_decode(get_instance()->output->get_output(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('unauthorized', $payload['result_class']);
        $this->assertFalse($controller->probeCalled);
    }

    public function testCspWriteReadinessRejectsNonLoopbackCaller(): void
    {
        $_ENV['HEALTHZ_TOKEN'] = 'health-token';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_SERVER['HTTP_X_HEALTH_TOKEN'] = 'health-token';
        $controller = $this->createCspReadinessController(
            ['status' => 'passed', 'result_class' => 'write_ready'],
            true,
        );

        $controller->csp_report_only_write_readiness();

        $payload = json_decode(get_instance()->output->get_output(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('non_loopback', $payload['result_class']);
        $this->assertFalse($controller->probeCalled);
    }

    public function testCspWriteReadinessReturnsOnlyTheFixedProbeClass(): void
    {
        $_ENV['HEALTHZ_TOKEN'] = 'health-token';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $_SERVER['HTTP_X_HEALTH_TOKEN'] = 'health-token';
        $controller = $this->createCspReadinessController(
            ['status' => 'passed', 'result_class' => 'write_ready'],
            true,
        );

        $controller->csp_report_only_write_readiness();

        $this->assertSame(
            [
                'schema' => 'csp_report_only_runtime_readiness.v1',
                'status' => 'passed',
                'result_class' => 'write_ready',
            ],
            json_decode(get_instance()->output->get_output(), true, 8, JSON_THROW_ON_ERROR),
        );
        $this->assertTrue($controller->probeCalled);
    }

    public function testCspWriteReadinessRejectsAnUnclassifiedProbeResult(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $controller = $this->createCspReadinessController(
            ['status' => 'failed', 'result_class' => 'raw_runtime_error'],
            true,
        );

        $controller->csp_report_only_write_readiness();

        $this->assertSame(
            [
                'schema' => 'csp_report_only_runtime_readiness.v1',
                'status' => 'failed',
                'result_class' => 'internal_error',
            ],
            json_decode(get_instance()->output->get_output(), true, 8, JSON_THROW_ON_ERROR),
        );
        $this->assertTrue($controller->probeCalled);
    }

    private function createCspReadinessController(array $probeResult, bool $authorized): object
    {
        return new class ($probeResult, $authorized) extends Healthz {
            public object $input;
            public bool $probeCalled = false;
            private array $probeResult;
            private bool $authorized;

            public function __construct(array $probeResult, bool $authorized)
            {
                $this->probeResult = $probeResult;
                $this->authorized = $authorized;
                $this->input = get_instance()->input;
            }

            protected function hasValidHealthToken(): bool
            {
                return $this->authorized;
            }

            protected function probeCspAggregateStorage(): array
            {
                $this->probeCalled = true;
                return $this->probeResult;
            }
        };
    }

    private function createController(
        bool $isLocalEnvironment = true,
        ?array $resolvedEndpoints = null,
        array $curlResponses = [],
        array $curlInitMap = [],
    ): object {
        return new class ($isLocalEnvironment, $resolvedEndpoints, $curlResponses, $curlInitMap) extends Healthz {
            private bool $isLocalEnvironment;
            private ?array $resolvedEndpoints;
            private array $curlResponses;
            private array $curlInitMap;
            private array $curlHandleUrls = [];
            private int $curlHandleCounter = 0;

            public function __construct(
                bool $isLocalEnvironment,
                ?array $resolvedEndpoints,
                array $curlResponses,
                array $curlInitMap,
            ) {
                $this->isLocalEnvironment = $isLocalEnvironment;
                $this->resolvedEndpoints = $resolvedEndpoints;
                $this->curlResponses = $curlResponses;
                $this->curlInitMap = $curlInitMap;
            }

            protected function isLocalEnvironment(): bool
            {
                return $this->isLocalEnvironment;
            }

            protected function resolvePdfRendererEndpoints(): array
            {
                if ($this->resolvedEndpoints !== null) {
                    return $this->resolvedEndpoints;
                }

                return parent::resolvePdfRendererEndpoints();
            }

            protected function initCurl(string $url): mixed
            {
                if (array_key_exists($url, $this->curlInitMap)) {
                    return $this->curlInitMap[$url];
                }

                $handle = 'curl-handle-' . ++$this->curlHandleCounter;
                $this->curlHandleUrls[$handle] = $url;

                return $handle;
            }

            protected function configureCurl(mixed $curl, ?string $endpoint = null): void {}

            protected function executeCurl(mixed $curl): string|bool
            {
                $url = $this->curlHandleUrls[$curl] ?? '';
                $response = $this->curlResponses[$url] ?? null;

                if (is_array($response) && array_key_exists('body', $response)) {
                    return $response['body'];
                }

                return false;
            }

            protected function getCurlError(mixed $curl): string
            {
                $url = $this->curlHandleUrls[$curl] ?? '';
                $response = $this->curlResponses[$url] ?? null;

                if (is_array($response) && array_key_exists('error', $response)) {
                    return (string) $response['error'];
                }

                return '';
            }

            protected function getCurlStatusCode(mixed $curl): int
            {
                $url = $this->curlHandleUrls[$curl] ?? '';
                $response = $this->curlResponses[$url] ?? null;

                if (is_array($response) && array_key_exists('status', $response)) {
                    return (int) $response['status'];
                }

                return 0;
            }

            protected function closeCurl(mixed $curl): void {}

            public function callResolvePdfRendererEndpoints(): array
            {
                return $this->resolvePdfRendererEndpoints();
            }

            public function callRunCheck(callable $callback): array
            {
                return $this->runCheck($callback);
            }

            public function callResolvePdfTimeoutOptions(string $endpoint): array
            {
                return $this->resolvePdfTimeoutOptions($endpoint);
            }

            public function callCacheControlHeaders(): array
            {
                return $this->cacheControlHeaders();
            }

            public function callCheckPdfRenderer(): array
            {
                return $this->checkPdfRenderer();
            }
        };
    }
}

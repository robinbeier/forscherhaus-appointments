<?php

namespace Tests\Unit\Controllers;

use Healthz;
use RuntimeException;
use Tests\TestCase;

require_once APPPATH . 'controllers/Healthz.php';

class HealthzControllerTest extends TestCase
{
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

    public function testResolvePdfRendererEndpointsSkipsImplicitLocalhostFallbackOutsideLocalEnvironment(): void
    {
        $hadPdfRendererUrl = array_key_exists('PDF_RENDERER_URL', $_ENV);
        $pdfRendererUrl = $_ENV['PDF_RENDERER_URL'] ?? null;
        $hadLoopbackFallback = array_key_exists('HEALTHZ_ALLOW_LOOPBACK_FALLBACK', $_ENV);
        $loopbackFallback = $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] ?? null;

        try {
            $_ENV['PDF_RENDERER_URL'] = 'http://example.com:3000/';
            unset($_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK']);

            $controller = $this->createController(false);
            $endpoints = $controller->callResolvePdfRendererEndpoints();

            $this->assertSame(['http://example.com:3000', 'http://pdf-renderer:3000'], $endpoints);
        } finally {
            if ($hadPdfRendererUrl) {
                $_ENV['PDF_RENDERER_URL'] = $pdfRendererUrl;
            } else {
                unset($_ENV['PDF_RENDERER_URL']);
            }

            if ($hadLoopbackFallback) {
                $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] = $loopbackFallback;
            } else {
                unset($_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK']);
            }
        }
    }

    public function testResolvePdfRendererEndpointsAllowsImplicitLocalhostFallbackWhenOptedIn(): void
    {
        $hadPdfRendererUrl = array_key_exists('PDF_RENDERER_URL', $_ENV);
        $pdfRendererUrl = $_ENV['PDF_RENDERER_URL'] ?? null;
        $hadLoopbackFallback = array_key_exists('HEALTHZ_ALLOW_LOOPBACK_FALLBACK', $_ENV);
        $loopbackFallback = $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] ?? null;

        try {
            $_ENV['PDF_RENDERER_URL'] = 'http://example.com:3000/';
            $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] = 'true';

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

            if ($hadLoopbackFallback) {
                $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] = $loopbackFallback;
            } else {
                unset($_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK']);
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

            protected function configureCurl(mixed $curl, ?string $endpoint = null): void
            {
            }

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

            protected function closeCurl(mixed $curl): void
            {
            }

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

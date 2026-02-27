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
                [
                    'http://localhost:3003',
                    'http://pdf-renderer:3000',
                    'http://127.0.0.1:3003',
                ],
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

        $result = $controller->callRunCheck(
            static function (): array {
                usleep(1000);

                return ['ping' => 1];
            },
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(['ping' => 1], $result['details']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
    }

    public function testRunCheckWrapsExceptionAsFailedPayload(): void
    {
        $controller = $this->createController();

        $result = $controller->callRunCheck(
            static function (): void {
                throw new RuntimeException('pdf endpoint down');
            },
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('pdf endpoint down', $result['message']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
    }

    public function testCacheControlHeadersAreNoCache(): void
    {
        $controller = $this->createController();

        $this->assertSame(
            [
                'Cache-Control: no-store, no-cache, must-revalidate',
                'Pragma: no-cache',
            ],
            $controller->callCacheControlHeaders(),
        );
    }

    public function testResolvePdfRendererEndpointsSkipsLoopbackAliasOutsideLocalEnvironment(): void
    {
        $hadOriginal = array_key_exists('PDF_RENDERER_URL', $_ENV);
        $original = $_ENV['PDF_RENDERER_URL'] ?? null;

        try {
            $_ENV['PDF_RENDERER_URL'] = 'http://example.com:3000/';

            $controller = $this->createController(false);
            $endpoints = $controller->callResolvePdfRendererEndpoints();

            $this->assertSame(
                [
                    'http://example.com:3000',
                    'http://pdf-renderer:3000',
                    'http://localhost:3003',
                ],
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

    private function createController(bool $isLocalEnvironment = true): object
    {
        return new class($isLocalEnvironment) extends Healthz {
            private bool $isLocalEnvironment;

            public function __construct(bool $isLocalEnvironment)
            {
                $this->isLocalEnvironment = $isLocalEnvironment;
            }

            protected function isLocalEnvironment(): bool
            {
                return $this->isLocalEnvironment;
            }

            public function callResolvePdfRendererEndpoints(): array
            {
                return $this->resolvePdfRendererEndpoints();
            }

            public function callRunCheck(callable $callback): array
            {
                return $this->runCheck($callback);
            }

            public function callCacheControlHeaders(): array
            {
                return $this->cacheControlHeaders();
            }
        };
    }
}

<?php

namespace Tests\Unit\Libraries;

use Pdf_renderer;
use Tests\TestCase;

require_once APPPATH . 'libraries/Pdf_renderer.php';

class PdfRendererTest extends TestCase
{
    public function testResolveEndpointsSkipsImplicitLocalhostFallbackOutsideLocalEnvironment(): void
    {
        $hadLoopbackFallback = array_key_exists('HEALTHZ_ALLOW_LOOPBACK_FALLBACK', $_ENV);
        $loopbackFallback = $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] ?? null;

        try {
            unset($_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK']);

            $renderer = $this->createRenderer(false);
            $endpoints = $renderer->callResolveEndpoints([
                'base_url' => 'http://example.com:3000/',
                'fallback_urls' => [],
            ]);

            $this->assertSame(['http://example.com:3000', 'http://pdf-renderer:3000'], $endpoints);
        } finally {
            if ($hadLoopbackFallback) {
                $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] = $loopbackFallback;
            } else {
                unset($_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK']);
            }
        }
    }

    public function testResolveEndpointsAllowsImplicitLocalhostFallbackWhenOptedIn(): void
    {
        $hadLoopbackFallback = array_key_exists('HEALTHZ_ALLOW_LOOPBACK_FALLBACK', $_ENV);
        $loopbackFallback = $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] ?? null;

        try {
            $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] = 'true';

            $renderer = $this->createRenderer(false);
            $endpoints = $renderer->callResolveEndpoints([
                'base_url' => 'http://example.com:3000/',
                'fallback_urls' => [],
            ]);

            $this->assertSame(
                ['http://example.com:3000', 'http://pdf-renderer:3000', 'http://localhost:3003'],
                $endpoints,
            );
        } finally {
            if ($hadLoopbackFallback) {
                $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] = $loopbackFallback;
            } else {
                unset($_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK']);
            }
        }
    }

    public function testResolveEndpointsKeepsLocalhostFallbackInLocalEnvironment(): void
    {
        $hadLoopbackFallback = array_key_exists('HEALTHZ_ALLOW_LOOPBACK_FALLBACK', $_ENV);
        $loopbackFallback = $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] ?? null;

        try {
            unset($_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK']);

            $renderer = $this->createRenderer(true);
            $endpoints = $renderer->callResolveEndpoints([
                'base_url' => 'http://example.com:3000/',
                'fallback_urls' => [],
            ]);

            $this->assertSame(
                ['http://example.com:3000', 'http://pdf-renderer:3000', 'http://localhost:3003'],
                $endpoints,
            );
        } finally {
            if ($hadLoopbackFallback) {
                $_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK'] = $loopbackFallback;
            } else {
                unset($_ENV['HEALTHZ_ALLOW_LOOPBACK_FALLBACK']);
            }
        }
    }

    private function createRenderer(bool $isLocalEnvironment): object
    {
        return new class ($isLocalEnvironment) extends Pdf_renderer {
            private bool $isLocalEnvironment;

            public function __construct(bool $isLocalEnvironment)
            {
                $this->isLocalEnvironment = $isLocalEnvironment;
            }

            protected function isLocalEnvironment(): bool
            {
                return $this->isLocalEnvironment;
            }

            public function callResolveEndpoints(array $config): array
            {
                return $this->resolveEndpoints($config);
            }
        };
    }
}

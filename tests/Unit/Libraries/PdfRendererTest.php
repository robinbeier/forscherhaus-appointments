<?php

namespace Tests\Unit\Libraries;

use Pdf_renderer;
use RuntimeException;
use Throwable;
use Tests\TestCase;

require_once APPPATH . 'libraries/Pdf_renderer.php';

class PdfRendererTest extends TestCase
{
    public function testResolveEndpointsUseOnlyHostReachableDefaultsOutsideContainerRuntime(): void
    {
        $renderer = $this->createRenderer(false, false);
        $endpoints = $renderer->callResolveEndpoints([
            'fallback_urls' => [],
        ]);

        $this->assertSame(['http://127.0.0.1:3003', 'http://localhost:3003'], $endpoints);
    }

    public function testResolveEndpointsUseOnlyDockerDnsDefaultInsideContainerRuntime(): void
    {
        $renderer = $this->createRenderer(false, true);
        $endpoints = $renderer->callResolveEndpoints([
            'fallback_urls' => [],
        ]);

        $this->assertSame(['http://pdf-renderer:3000'], $endpoints);
    }

    public function testResolveEndpointsKeepConfiguredEndpointFirstAndRuntimeAwareFallbacksAfterwards(): void
    {
        $renderer = $this->createRenderer(false, false);
        $endpoints = $renderer->callResolveEndpoints([
            'base_url' => 'http://example.com:3000/',
            'fallback_urls' => [],
        ]);

        $this->assertSame(['http://example.com:3000', 'http://127.0.0.1:3003', 'http://localhost:3003'], $endpoints);
    }

    public function testResolveEndpointsUseApacheServerVariableWhenPhpEnvironmentMapIsEmpty(): void
    {
        $hadEnv = array_key_exists('PDF_RENDERER_URL', $_ENV);
        $envValue = $_ENV['PDF_RENDERER_URL'] ?? null;
        $hadServer = array_key_exists('PDF_RENDERER_URL', $_SERVER);
        $serverValue = $_SERVER['PDF_RENDERER_URL'] ?? null;
        $processValue = getenv('PDF_RENDERER_URL');
        putenv('PDF_RENDERER_URL');

        try {
            unset($_ENV['PDF_RENDERER_URL']);
            $_SERVER['PDF_RENDERER_URL'] = 'http://localhost:3003/';

            $renderer = $this->createRenderer(false, false);
            $endpoints = $renderer->callResolveEndpoints([
                'fallback_urls' => [],
            ]);

            $this->assertSame(['http://localhost:3003', 'http://127.0.0.1:3003'], $endpoints);
        } finally {
            if ($hadEnv) {
                $_ENV['PDF_RENDERER_URL'] = $envValue;
            } else {
                unset($_ENV['PDF_RENDERER_URL']);
            }

            if ($hadServer) {
                $_SERVER['PDF_RENDERER_URL'] = $serverValue;
            } else {
                unset($_SERVER['PDF_RENDERER_URL']);
            }

            if ($processValue === false) {
                putenv('PDF_RENDERER_URL');
            } else {
                putenv('PDF_RENDERER_URL=' . $processValue);
            }
        }
    }

    public function testResolveEndpointsDeduplicateExplicitHostFallbacks(): void
    {
        $renderer = $this->createRenderer(false, false);
        $endpoints = $renderer->callResolveEndpoints([
            'fallback_urls' => ['http://127.0.0.1:3003/', 'http://localhost:3003'],
        ]);

        $this->assertSame(['http://127.0.0.1:3003', 'http://localhost:3003'], $endpoints);
    }

    public function testBuildRendererRequestOptionsShortensNonPrimaryLoopbackFallbacks(): void
    {
        $renderer = $this->createRenderer(false, false);
        $options = $renderer->callBuildRendererRequestOptions(
            'http://127.0.0.1:3003',
            ['html' => '<html></html>'],
            false,
        );

        $this->assertSame(0.5, $options['timeout']);
        $this->assertSame(0.25, $options['connect_timeout']);
    }

    public function testBuildRendererRequestOptionsKeepsPrimaryEndpointTimeouts(): void
    {
        $renderer = $this->createRenderer(false, false);
        $options = $renderer->callBuildRendererRequestOptions(
            'http://127.0.0.1:3003',
            ['html' => '<html></html>'],
            true,
        );

        $this->assertSame(30.0, $options['timeout']);
        $this->assertSame(5.0, $options['connect_timeout']);
    }

    public function testIsContainerRuntimeCachesDetectionResult(): void
    {
        $renderer = new class extends Pdf_renderer {
            public int $detectionCalls = 0;

            public function __construct() {}

            protected function detectContainerRuntime(): bool
            {
                $this->detectionCalls++;

                return false;
            }

            public function callIsContainerRuntime(): bool
            {
                return $this->isContainerRuntime();
            }
        };

        $this->assertFalse($renderer->callIsContainerRuntime());
        $this->assertFalse($renderer->callIsContainerRuntime());
        $this->assertSame(1, $renderer->detectionCalls);
    }

    public function testRenderHtmlRecoversFromPrimaryTimeoutThroughReachableFallback(): void
    {
        $renderer = $this->createFlowRenderer([
            'http://primary.example:3000' => new RuntimeException('primary navigation timeout'),
            'http://127.0.0.1:3003' => '%PDF-fallback',
            'http://localhost:3003' => new RuntimeException('must not be reached'),
        ]);

        $this->assertSame('%PDF-fallback', $renderer->render_html('<html><body>test</body></html>'));
        $this->assertSame(['http://primary.example:3000', 'http://127.0.0.1:3003'], $renderer->attemptedEndpoints);
    }

    public function testRenderHtmlReportsCompleteFailureAfterAllReachableEndpointsFail(): void
    {
        $renderer = $this->createFlowRenderer([
            'http://primary.example:3000' => new RuntimeException('primary navigation timeout'),
            'http://127.0.0.1:3003' => new RuntimeException('loopback unavailable'),
        ]);

        try {
            $renderer->render_html('<html><body>test</body></html>');
            $this->fail('Expected renderer failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PDF rendering failed for all configured endpoints.', $exception->getMessage());
            $this->assertSame(['http://primary.example:3000', 'http://127.0.0.1:3003'], $renderer->attemptedEndpoints);
        }
    }

    private function createRenderer(bool $isLocalEnvironment, bool $isContainerRuntime): object
    {
        return new class ($isLocalEnvironment, $isContainerRuntime) extends Pdf_renderer {
            private bool $isLocalEnvironment;
            private bool $isContainerRuntime;

            public function __construct(bool $isLocalEnvironment, bool $isContainerRuntime)
            {
                $this->isLocalEnvironment = $isLocalEnvironment;
                $this->isContainerRuntime = $isContainerRuntime;
            }

            protected function isLocalEnvironment(): bool
            {
                return $this->isLocalEnvironment;
            }

            protected function isContainerRuntime(): bool
            {
                return $this->isContainerRuntime;
            }

            public function callResolveEndpoints(array $config): array
            {
                return $this->resolveEndpoints($config);
            }

            public function callBuildRendererRequestOptions(string $endpoint, array $payload, bool $isPrimary): array
            {
                $this->token = null;
                $this->defaultTimeout = 30.0;
                $this->defaultConnectTimeout = 5.0;

                return $this->buildRendererRequestOptions($endpoint, $payload, $isPrimary);
            }
        };
    }

    private function createFlowRenderer(array $outcomes): object
    {
        return new class ($outcomes) extends Pdf_renderer {
            public array $attemptedEndpoints = [];

            private array $outcomes;

            public function __construct(array $outcomes)
            {
                $this->outcomes = $outcomes;
                $this->endpoints = array_keys($outcomes);
                $this->defaultPaper = 'A4';
                $this->defaultOrientation = 'portrait';
                $this->defaultMargin = [];
                $this->defaultWaitFor = null;
            }

            protected function callRenderer(string $endpoint, array $payload, bool $isPrimary = false): string
            {
                $this->attemptedEndpoints[] = $endpoint;
                $outcome = $this->outcomes[$endpoint];

                if ($outcome instanceof Throwable) {
                    throw $outcome;
                }

                return $outcome;
            }

            protected function logRendererFailure(string $endpoint, Throwable $exception): void {}
        };
    }
}

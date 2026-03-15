<?php

namespace Tests\Unit\Libraries;

use Pdf_renderer;
use RuntimeException;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Throwable;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/application/bootstrap/SentryBootstrap.php';
require_once APPPATH . 'libraries/Pdf_renderer.php';

class PdfRendererTest extends TestCase
{
    public function testResolveEndpointsPreferHostLoopbackOutsideContainerRuntime(): void
    {
        $renderer = $this->createRenderer(false, false);
        $endpoints = $renderer->callResolveEndpoints([
            'fallback_urls' => [],
        ]);

        $this->assertSame(['http://127.0.0.1:3003', 'http://localhost:3003', 'http://pdf-renderer:3000'], $endpoints);
    }

    public function testResolveEndpointsPreferServiceNameInsideContainerRuntime(): void
    {
        $renderer = $this->createRenderer(false, true);
        $endpoints = $renderer->callResolveEndpoints([
            'fallback_urls' => [],
        ]);

        $this->assertSame(['http://pdf-renderer:3000', 'http://localhost:3003'], $endpoints);
    }

    public function testResolveEndpointsKeepConfiguredEndpointFirstAndRuntimeAwareFallbacksAfterwards(): void
    {
        $renderer = $this->createRenderer(false, false);
        $endpoints = $renderer->callResolveEndpoints([
            'base_url' => 'http://example.com:3000/',
            'fallback_urls' => [],
        ]);

        $this->assertSame(
            ['http://example.com:3000', 'http://127.0.0.1:3003', 'http://localhost:3003', 'http://pdf-renderer:3000'],
            $endpoints,
        );
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

            $this->assertSame(
                ['http://localhost:3003', 'http://127.0.0.1:3003', 'http://pdf-renderer:3000'],
                $endpoints,
            );
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

    public function testRenderHtmlCapturesSentryEventWhenAllEndpointsFail(): void
    {
        $transport = new class implements TransportInterface {
            public ?Event $event = null;

            public function send(Event $event): Result
            {
                $this->event = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success(), $this->event);
            }
        };

        \Sentry\init([
            'dsn' => 'https://examplePublicKey@o0.ingest.sentry.io/1',
            'default_integrations' => false,
            'transport' => $transport,
        ]);

        $renderer = new class extends Pdf_renderer {
            public function __construct()
            {
                $this->endpoints = ['http://127.0.0.1:3003', 'http://localhost:3003'];
                $this->defaultPaper = 'A4';
                $this->defaultOrientation = 'portrait';
                $this->defaultMargin = [];
                $this->defaultWaitFor = null;
            }

            protected function callRenderer(string $endpoint, array $payload): string
            {
                throw new RuntimeException('renderer down at ' . $endpoint);
            }

            protected function isContainerRuntime(): bool
            {
                return false;
            }

            protected function isLocalEnvironment(): bool
            {
                return false;
            }

            protected function logRendererFailure(string $endpoint, Throwable $exception): void {}
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PDF rendering failed for all configured endpoints.');

        try {
            $renderer->render_html('<html><body>test</body></html>');
        } finally {
            \Sentry\flush();

            $this->assertNotNull($transport->event);
            $this->assertSame('pdf_renderer', $transport->event->getTags()['area'] ?? null);
            $this->assertSame('render_html', $transport->event->getTags()['operation'] ?? null);
            $this->assertSame(
                ['http://127.0.0.1:3003', 'http://localhost:3003'],
                $transport->event->getExtra()['endpoints'] ?? null,
            );
            $this->assertSame('http://127.0.0.1:3003', $transport->event->getExtra()['primary_endpoint'] ?? null);
            $this->assertSame(2, $transport->event->getExtra()['endpoint_count'] ?? null);
            $this->assertFalse($transport->event->getExtra()['container_runtime'] ?? true);
            $this->assertFalse($transport->event->getExtra()['local_environment'] ?? true);

            SentrySdk::setCurrentHub(new Hub());
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
        };
    }
}

<?php

namespace Tests\Unit\Helper;

use RuntimeException;
use Tests\TestCase;
use Throwable;

class HttpHelperTest extends TestCase
{
    private string|false $original_exception_ignore_args = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original_exception_ignore_args = ini_get('zend.exception_ignore_args');
        @ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->original_exception_ignore_args !== false) {
            @ini_set('zend.exception_ignore_args', (string) $this->original_exception_ignore_args);
        }

        parent::tearDown();
    }

    public function testTraceReturnsJsonSummaryForCircularArguments(): void
    {
        $payload = [];
        $payload['self'] = &$payload;

        $exception = $this->captureException($payload, 'super-secret-token');

        $trace = trace($exception);
        $decoded = json_decode($trace, true);

        $this->assertIsArray($decoded);
        $this->assertSame(RuntimeException::class, $decoded['exception_class']);
        $this->assertArrayHasKey('frames', $decoded);
        $this->assertNotEmpty($decoded['frames']);
        $this->assertStringNotContainsString('super-secret-token', $trace);
    }

    public function testTraceLimitsFrameCountToConfiguredMaximum(): void
    {
        $exception = $this->captureDeepException(35);
        $decoded = json_decode(trace($exception), true);

        $this->assertIsArray($decoded);
        $this->assertLessThanOrEqual(25, count($decoded['frames']));
        $this->assertTrue((bool) ($decoded['truncated'] ?? false));
    }

    public function testTraceSummarizesArgumentsAsMetadataOnly(): void
    {
        if ((string) ini_get('zend.exception_ignore_args') === '1') {
            $this->markTestSkipped('Runtime is configured to ignore exception args.');
        }

        $exception = $this->captureException(['key' => 'value'], 'sensitive-payload');
        $decoded = json_decode(trace($exception), true);

        $this->assertIsArray($decoded);

        $frame_with_args = null;

        foreach ($decoded['frames'] as $frame) {
            if (($frame['arg_count'] ?? 0) > 0) {
                $frame_with_args = $frame;
                break;
            }
        }

        if ($frame_with_args === null) {
            $this->markTestSkipped('No frame arguments captured by runtime.');
        }

        $this->assertArrayHasKey('args', $frame_with_args);
        $this->assertNotEmpty($frame_with_args['args']);
        $this->assertArrayHasKey('type', $frame_with_args['args'][0]);
        $this->assertArrayNotHasKey('value', $frame_with_args['args'][0]);
    }

    public function testTraceRemainsValidJsonWhenSizeLimited(): void
    {
        $exception = $this->captureOversizedTraceException(40);
        $trace = trace($exception);
        $decoded = json_decode($trace, true);

        $this->assertIsArray($decoded);
        $this->assertTrue((bool) ($decoded['size_truncated'] ?? false));
        $this->assertLessThanOrEqual(16000, strlen($trace));
    }

    private function captureException(array $payload, string $token): Throwable
    {
        try {
            $this->throwWithPayload($payload, $token);
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('Expected exception was not thrown.');
    }

    private function throwWithPayload(array $payload, string $token): void
    {
        throw new RuntimeException('Trace test exception');
    }

    private function captureDeepException(int $depth): Throwable
    {
        try {
            $this->callDeep($depth);
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('Expected deep exception was not thrown.');
    }

    private function callDeep(int $depth): void
    {
        if ($depth <= 0) {
            throw new RuntimeException('Deep trace exception');
        }

        $this->callDeep($depth - 1);
    }

    private function captureOversizedTraceException(int $depth): Throwable
    {
        $function_name = 'trace_size_test_' . str_repeat('x', 900) . '_' . substr(md5((string) microtime(true)), 0, 12);

        eval(
            'function ' .
                $function_name .
                '($depth) { ' .
                'if ($depth <= 0) { throw new \RuntimeException("Oversized trace exception"); } ' .
                $function_name .
                '($depth - 1); ' .
                '}'
        );

        try {
            $function_name($depth);
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('Expected oversized trace exception was not thrown.');
    }
}

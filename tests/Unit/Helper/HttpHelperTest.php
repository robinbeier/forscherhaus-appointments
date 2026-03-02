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

    public function testTraceSamplesOnlyFirstThreeArrayKeys(): void
    {
        if ((string) ini_get('zend.exception_ignore_args') === '1') {
            $this->markTestSkipped('Runtime is configured to ignore exception args.');
        }

        $exception = $this->captureException(
            [
                'first' => 'value-1',
                'second' => 'value-2',
                'third' => 'value-3',
                'fourth' => 'value-4',
            ],
            'sensitive-payload',
        );
        $decoded = json_decode(trace($exception), true);

        $this->assertIsArray($decoded);

        $sampled_array_arg = null;

        foreach ($decoded['frames'] as $frame) {
            foreach ($frame['args'] ?? [] as $arg_summary) {
                if (($arg_summary['type'] ?? null) !== 'array') {
                    continue;
                }

                if (($arg_summary['count'] ?? 0) !== 4) {
                    continue;
                }

                $sampled_array_arg = $arg_summary;
                break 2;
            }
        }

        if ($sampled_array_arg === null) {
            $this->markTestSkipped('No matching array arguments captured by runtime.');
        }

        $this->assertSame(['first', 'second', 'third'], $sampled_array_arg['sample_keys'] ?? []);
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

    public function testHttpHelperDeclaresResponseFunctionExactlyOnce(): void
    {
        $source = file_get_contents(APPPATH . 'helpers/http_helper.php');

        $this->assertIsString($source);

        $tokens = token_get_all($source);
        $response_declarations = 0;
        $token_count = count($tokens);

        for ($index = 0; $index < $token_count; $index++) {
            $token = $tokens[$index];

            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name_index = $index + 1;

            while ($name_index < $token_count) {
                $candidate = $tokens[$name_index];

                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $name_index++;
                    continue;
                }

                if ($candidate === '&' || (is_array($candidate) && trim($candidate[1]) === '&')) {
                    $name_index++;
                    continue;
                }

                break;
            }

            if ($name_index >= $token_count || !is_array($tokens[$name_index])) {
                continue;
            }

            if ($tokens[$name_index][0] === T_STRING && strtolower((string) $tokens[$name_index][1]) === 'response') {
                $response_declarations++;
            }
        }

        $this->assertSame(1, $response_declarations);
    }

    public function testHttpHelperContainsSingleResponseGuardBlock(): void
    {
        $source = file_get_contents(APPPATH . 'helpers/http_helper.php');

        $this->assertIsString($source);

        $guard_count = preg_match_all(
            "/if\\s*\\(\\s*!function_exists\\s*\\(\\s*['\\\"]response['\\\"]\\s*\\)\\s*\\)/",
            $source,
        );

        $this->assertSame(1, $guard_count);
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

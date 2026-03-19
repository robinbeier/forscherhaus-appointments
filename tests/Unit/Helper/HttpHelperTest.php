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

    public function testJsonExceptionLogSummarySanitizesAndCompactsExceptionData(): void
    {
        $message = "json-exception-log-test\n\t" . chr(0) . str_repeat('x', 600);
        $exception = $this->captureStandaloneException($message);

        $summary = json_exception_log_summary($exception);

        $this->assertStringContainsString('JSON exception: class=RuntimeException', $summary);
        $this->assertStringContainsString('message=json-exception-log-test ', $summary);
        $this->assertStringContainsString('frame_count=', $summary);
        $this->assertStringContainsString(
            'trace_summary=Tests\Unit\Helper\HttpHelperTest->captureStandaloneException@',
            $summary,
        );
        $this->assertStringNotContainsString("\n", $summary);
        $this->assertStringNotContainsString("\t", $summary);
        $this->assertStringNotContainsString(chr(0), $summary);
        $this->assertLessThanOrEqual(1200, strlen($summary));
    }

    public function testJsonExceptionLogSummaryKeepsMultipleTraceFramesBounded(): void
    {
        $summary = json_exception_log_summary($this->captureDeepException(3));

        $this->assertStringContainsString('trace_summary=', $summary);
        $this->assertGreaterThanOrEqual(1, substr_count($summary, ' <= '));
        $this->assertGreaterThanOrEqual(2, substr_count($summary, 'Tests\Unit\Helper\HttpHelperTest->callDeep@'));
        $this->assertLessThanOrEqual(1200, strlen($summary));
    }

    public function testJsonExceptionKeepsResponseShapeAndWritesCompactErrorLog(): void
    {
        get_instance()->output->set_output('');
        get_instance()->output->headers = [];

        $message = 'json-exception-log-test-' . substr(md5((string) microtime(true)), 0, 12);
        $exception = $this->captureStandaloneException($message);

        json_exception($exception);

        $response = json_decode(get_instance()->output->get_output(), true);
        $log_line = $this->findLogLineForMessage($message);

        $this->assertIsArray($response);
        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame($message, $response['message'] ?? null);
        $this->assertArrayNotHasKey('trace', $response);
        $this->assertSame('application/json', get_instance()->output->get_content_type());

        $this->assertStringContainsString('JSON exception: class=RuntimeException', $log_line);
        $this->assertStringContainsString('message=' . $message, $log_line);
        $this->assertStringContainsString('frame_count=', $log_line);
        $this->assertStringContainsString(
            'trace_summary=Tests\Unit\Helper\HttpHelperTest->captureStandaloneException@',
            $log_line,
        );
        $this->assertStringNotContainsString('"trace":"', $log_line);
        $this->assertStringNotContainsString('"frames":[', $log_line);
        $this->assertStringNotContainsString(' Trace: array (', $log_line);
    }

    public function testJsonExceptionEmits500StatusAndJsonContentTypeOverHttp(): void
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'json-exception-http-');
        $this->assertIsString($scriptPath);
        $repoRoot = dirname(__DIR__, 3);

        try {
            file_put_contents(
                $scriptPath,
                <<<PHP
                <?php
                declare(strict_types=1);

                define('BASEPATH', '$repoRoot/system/');

                final class TestOutput
                {
                    public array \$headers = [];
                    private string \$contentType = 'text/html';
                    private string \$output = '';

                    public function set_header(string \$header, bool \$replace = true): self
                    {
                        \$this->headers[] = [\$header, \$replace];
                        header(\$header, \$replace);

                        return \$this;
                    }

                    public function set_status_header(int \$status): self
                    {
                        http_response_code(\$status);

                        return \$this;
                    }

                    public function set_content_type(string \$mimeType): self
                    {
                        \$this->contentType = \$mimeType;

                        return \$this->set_header('Content-Type: ' . \$mimeType . '; charset=UTF-8');
                    }

                    public function set_output(string \$output): self
                    {
                        \$this->output = \$output;

                        return \$this;
                    }

                    public function get_output(): string
                    {
                        return \$this->output;
                    }

                    public function get_content_type(): string
                    {
                        return \$this->contentType;
                    }
                }

                final class TestCiInstance
                {
                    public TestOutput \$output;

                    public function __construct()
                    {
                        \$this->output = new TestOutput();
                    }
                }

                final class TestLog
                {
                    public function write_compact_error(string \$message): bool
                    {
                        return true;
                    }

                    public function write_log(string \$level, string \$message): bool
                    {
                        return true;
                    }
                }

                function &get_instance(): TestCiInstance
                {
                    static \$instance;

                    if (!\$instance instanceof TestCiInstance) {
                        \$instance = new TestCiInstance();
                    }

                    return \$instance;
                }

                function load_class(string \$class, string \$directory): object
                {
                    static \$logger;

                    if (\$class === 'Log' && \$directory === 'core') {
                        if (!\$logger instanceof TestLog) {
                            \$logger = new TestLog();
                        }

                        return \$logger;
                    }

                    throw new RuntimeException('Unexpected load_class request: ' . \$directory . '/' . \$class);
                }

                require '$repoRoot/application/helpers/http_helper.php';

                try {
                    throw new RuntimeException('http-json-exception-test');
                } catch (Throwable \$e) {
                    json_exception(\$e);
                }

                echo get_instance()->output->get_output();
                PHP
                ,
            );

            $result = $this->runHttpScript($scriptPath);

            $this->assertSame(0, $result['exit_code'], $result['stderr']);
            $this->assertStringContainsString('HTTP/1.1 500 Internal Server Error', $result['headers']);
            $this->assertStringContainsString('Content-Type: application/json; charset=UTF-8', $result['headers']);
            $this->assertStringContainsString(
                '{"success":false,"message":"http-json-exception-test"}',
                $result['body'],
            );
        } finally {
            if (is_file($scriptPath)) {
                unlink($scriptPath);
            }
        }
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

    private function captureStandaloneException(string $message): Throwable
    {
        try {
            throw new RuntimeException($message);
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('Expected standalone exception was not thrown.');
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

    private function currentLogPath(): string
    {
        $logPath = (string) config_item('log_path');
        $configuredExtension = (string) config_item('log_file_extension');
        $extension = $configuredExtension !== '' ? ltrim($configuredExtension, '.') : 'php';

        return rtrim($logPath, '/\\') . '/log-' . date('Y-m-d') . '.' . $extension;
    }

    private function findLogLineForMessage(string $message): string
    {
        $log_path = $this->currentLogPath();
        clearstatcache(true, $log_path);

        $contents = file_get_contents($log_path);

        $this->assertIsString($contents);
        $this->assertNotFalse(strpos($contents, $message));

        $position = strrpos($contents, $message);
        $this->assertNotFalse($position);
        $line_start = strrpos(substr($contents, 0, (int) $position), PHP_EOL);
        $line_start = $line_start === false ? 0 : $line_start + strlen(PHP_EOL);
        $line_end = strpos($contents, PHP_EOL, (int) $position);
        $line_end = $line_end === false ? strlen($contents) : $line_end;

        return substr($contents, $line_start, $line_end - $line_start);
    }

    /**
     * @return array{exit_code:int,headers:string,body:string,stderr:string}
     */
    private function runHttpScript(string $scriptPath): array
    {
        $serverLog = tempnam(sys_get_temp_dir(), 'json-exception-http-log-');
        $this->assertIsString($serverLog);

        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $serverLog, 'w'],
            2 => ['file', $serverLog, 'a'],
        ];

        for ($serverAttempt = 0; $serverAttempt < 10; $serverAttempt++) {
            $port = random_int(20000, 45000);
            $server = proc_open(
                ['php', '-S', '127.0.0.1:' . $port, $scriptPath],
                $descriptorSpec,
                $pipes,
                dirname($scriptPath),
                $_ENV,
            );
            $this->assertIsResource($server);

            try {
                $requestHeaders = '';
                $requestBody = '';
                $requestSucceeded = false;

                for ($requestAttempt = 0; $requestAttempt < 50; $requestAttempt++) {
                    $context = stream_context_create([
                        'http' => [
                            'ignore_errors' => true,
                            'timeout' => 1,
                        ],
                    ]);

                    $body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $context);
                    $headers = function_exists('http_get_last_response_headers')
                        ? http_get_last_response_headers()
                        : $http_response_header ?? [];

                    if (is_string($body) && is_array($headers) && $headers !== []) {
                        $requestHeaders = implode("\r\n", $headers);
                        $requestBody = $body;
                        $requestSucceeded = true;
                        break;
                    }

                    $serverStatus = proc_get_status($server);
                    $this->assertIsArray($serverStatus);

                    if (!(bool) ($serverStatus['running'] ?? false)) {
                        break;
                    }

                    usleep(100000);
                }

                if ($requestSucceeded) {
                    $serverStatus = proc_get_status($server);
                    $this->assertIsArray($serverStatus);
                    $this->assertTrue((bool) ($serverStatus['running'] ?? false));

                    return [
                        'exit_code' => 0,
                        'headers' => $requestHeaders,
                        'body' => $requestBody,
                        'stderr' => (string) file_get_contents($serverLog),
                    ];
                }
            } finally {
                proc_terminate($server);
                proc_close($server);
            }
        }

        $this->fail((string) file_get_contents($serverLog));
    }
}

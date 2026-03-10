<?php

declare(strict_types=1);

const PDF_RENDERER_LATENCY_EXIT_SUCCESS = 0;
const PDF_RENDERER_LATENCY_EXIT_WARN = 1;
const PDF_RENDERER_LATENCY_EXIT_FAIL = 2;
const PDF_RENDERER_LATENCY_EXIT_RUNTIME_ERROR = 3;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runPdfRendererLatencyCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runPdfRendererLatencyCli(array $argv): int
{
    $config = pdfRendererLatencyDefaultConfig();
    $report = [
        'schema_version' => 1,
        'status' => 'error',
        'generated_at_utc' => gmdate('c'),
        'base_url' => $config['base_url'],
        'pdf_endpoint' => $config['pdf_endpoint'],
        'health_endpoint' => $config['health_endpoint'],
        'policy_file' => $config['policy'],
    ];
    $exitCode = PDF_RENDERER_LATENCY_EXIT_RUNTIME_ERROR;

    try {
        parsePdfRendererLatencyCliOptions($argv, $config);

        if ($config['help'] === true) {
            fwrite(STDOUT, pdfRendererLatencyUsage());

            return PDF_RENDERER_LATENCY_EXIT_SUCCESS;
        }

        $policy = loadPdfRendererLatencyPolicy($config['policy']);
        $measurement = measurePdfRendererLatency($config);
        $evaluation = evaluatePdfRendererLatency($measurement['measured_durations_ms'], $policy);

        $report = array_merge($report, [
            'status' => $evaluation['status'],
            'generated_at_utc' => gmdate('c'),
            'base_url' => $config['base_url'],
            'pdf_endpoint' => $config['pdf_endpoint'],
            'health_endpoint' => $config['health_endpoint'],
            'policy_file' => $config['policy'],
            'skip_health_check' => $config['skip_health_check'],
            'iterations' => $config['iterations'],
            'warmup_iterations' => $config['warmup_iterations'],
            'timeout_seconds' => $config['timeout_seconds'],
            'retry_count' => $config['retry_count'],
            'sample_count' => count($measurement['measured_durations_ms']),
            'warmup_sample_count' => $config['warmup_iterations'],
            'samples' => $measurement['samples'],
            'metrics' => $evaluation['metrics'],
            'policy' => $policy,
            'messages' => $evaluation['messages'],
        ]);

        if ($evaluation['status'] === 'pass') {
            $exitCode = PDF_RENDERER_LATENCY_EXIT_SUCCESS;
            fwrite(
                STDOUT,
                sprintf(
                    '[PASS] pdf-renderer-latency p50=%.2fms p95=%.2fms',
                    $evaluation['metrics']['p50_ms'],
                    $evaluation['metrics']['p95_ms'],
                ) . PHP_EOL,
            );
        } elseif ($evaluation['status'] === 'warn') {
            $exitCode = PDF_RENDERER_LATENCY_EXIT_WARN;
            fwrite(
                STDERR,
                sprintf(
                    '[WARN] pdf-renderer-latency p50=%.2fms p95=%.2fms',
                    $evaluation['metrics']['p50_ms'],
                    $evaluation['metrics']['p95_ms'],
                ) . PHP_EOL,
            );

            foreach ($evaluation['messages'] as $message) {
                fwrite(STDERR, ' - ' . $message . PHP_EOL);
            }
        } else {
            $exitCode = PDF_RENDERER_LATENCY_EXIT_FAIL;
            fwrite(
                STDERR,
                sprintf(
                    '[FAIL] pdf-renderer-latency p50=%.2fms p95=%.2fms',
                    $evaluation['metrics']['p50_ms'],
                    $evaluation['metrics']['p95_ms'],
                ) . PHP_EOL,
            );

            foreach ($evaluation['messages'] as $message) {
                fwrite(STDERR, ' - ' . $message . PHP_EOL);
            }
        }
    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ];
        fwrite(STDERR, '[ERROR] pdf-renderer-latency failed: ' . $e->getMessage() . PHP_EOL);
        $exitCode = PDF_RENDERER_LATENCY_EXIT_RUNTIME_ERROR;
    }

    try {
        writePdfRendererLatencyReport($config['output_json'], $report);
        fwrite(STDOUT, '[INFO] Report: ' . $config['output_json'] . PHP_EOL);
    } catch (Throwable $e) {
        fwrite(STDERR, '[WARN] Failed to write pdf-renderer-latency report: ' . $e->getMessage() . PHP_EOL);

        if ($exitCode === PDF_RENDERER_LATENCY_EXIT_SUCCESS) {
            $exitCode = PDF_RENDERER_LATENCY_EXIT_RUNTIME_ERROR;
        }
    }

    return $exitCode;
}

function pdfRendererLatencyUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/check_pdf_renderer_latency.php [options]',
        '',
        'Options:',
        '  --base-url=URL          PDF renderer base URL (default: PDF_RENDERER_URL or http://localhost:3003).',
        '  --pdf-endpoint=PATH     PDF endpoint path (default: /pdf).',
        '  --health-endpoint=PATH  Health endpoint path (default: /healthz).',
        '  --iterations=N          Number of measured samples after warmup.',
        '  --warmup-iterations=N   Warmup requests to exclude from metrics.',
        '  --timeout-seconds=N     Per-request timeout in seconds.',
        '  --retry-count=N         Retry count for transient 5xx PDF responses.',
        '  --skip-health-check     Skip the pre-flight /healthz request.',
        '  --policy=PATH           Policy config PHP file path.',
        '  --output-json=PATH      JSON report output path.',
        '  --help                  Show this help text.',
        '',
    ]);
}

/**
 * @return array{
 *     base_url:string,
 *     pdf_endpoint:string,
 *     health_endpoint:string,
 *     iterations:int,
 *     warmup_iterations:int,
 *     timeout_seconds:int,
 *     retry_count:int,
 *     skip_health_check:bool,
 *     policy:string,
 *     output_json:string,
 *     help:bool
 * }
 */
function pdfRendererLatencyDefaultConfig(): array
{
    $root = dirname(__DIR__, 2);
    $baseUrl = getenv('PDF_RENDERER_URL');

    return [
        'base_url' => $baseUrl !== false && $baseUrl !== '' ? (string) $baseUrl : 'http://localhost:3003',
        'pdf_endpoint' => '/pdf',
        'health_endpoint' => '/healthz',
        'iterations' => 7,
        'warmup_iterations' => 2,
        'timeout_seconds' => 30,
        'retry_count' => 2,
        'skip_health_check' => false,
        'policy' => $root . '/scripts/ci/config/pdf_renderer_latency_policy.php',
        'output_json' => $root . '/storage/logs/ci/pdf-renderer-latency-latest.json',
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array{
 *     base_url:string,
 *     pdf_endpoint:string,
 *     health_endpoint:string,
 *     iterations:int,
 *     warmup_iterations:int,
 *     timeout_seconds:int,
 *     retry_count:int,
 *     skip_health_check:bool,
 *     policy:string,
 *     output_json:string,
 *     help:bool
 * } $config
 */
function parsePdfRendererLatencyCliOptions(array $argv, array &$config): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if ($arg === '--skip-health-check') {
            $config['skip_health_check'] = true;
            continue;
        }

        if (str_starts_with($arg, '--base-url=')) {
            $config['base_url'] = requirePdfRendererLatencyNonEmptyCliValue($arg, '--base-url=');
            continue;
        }

        if (str_starts_with($arg, '--pdf-endpoint=')) {
            $config['pdf_endpoint'] = requirePdfRendererLatencyNonEmptyCliValue($arg, '--pdf-endpoint=');
            continue;
        }

        if (str_starts_with($arg, '--health-endpoint=')) {
            $config['health_endpoint'] = requirePdfRendererLatencyNonEmptyCliValue($arg, '--health-endpoint=');
            continue;
        }

        if (str_starts_with($arg, '--iterations=')) {
            $config['iterations'] = normalizePdfRendererLatencyPositiveInt(
                requirePdfRendererLatencyNonEmptyCliValue($arg, '--iterations='),
                '--iterations',
            );
            continue;
        }

        if (str_starts_with($arg, '--warmup-iterations=')) {
            $config['warmup_iterations'] = normalizePdfRendererLatencyNonNegativeInt(
                requirePdfRendererLatencyNonEmptyCliValue($arg, '--warmup-iterations='),
                '--warmup-iterations',
            );
            continue;
        }

        if (str_starts_with($arg, '--timeout-seconds=')) {
            $config['timeout_seconds'] = normalizePdfRendererLatencyPositiveInt(
                requirePdfRendererLatencyNonEmptyCliValue($arg, '--timeout-seconds='),
                '--timeout-seconds',
            );
            continue;
        }

        if (str_starts_with($arg, '--retry-count=')) {
            $config['retry_count'] = normalizePdfRendererLatencyPositiveInt(
                requirePdfRendererLatencyNonEmptyCliValue($arg, '--retry-count='),
                '--retry-count',
            );
            continue;
        }

        if (str_starts_with($arg, '--policy=')) {
            $config['policy'] = requirePdfRendererLatencyNonEmptyCliValue($arg, '--policy=');
            continue;
        }

        if (str_starts_with($arg, '--output-json=')) {
            $config['output_json'] = requirePdfRendererLatencyNonEmptyCliValue($arg, '--output-json=');
            continue;
        }

        throw new RuntimeException('Unknown CLI option: ' . $arg);
    }

    if ($config['warmup_iterations'] >= $config['iterations']) {
        throw new RuntimeException('--warmup-iterations must be smaller than --iterations.');
    }

    $config['base_url'] = rtrim($config['base_url'], '/');
    $config['pdf_endpoint'] = normalizePdfRendererLatencyEndpointPath($config['pdf_endpoint'], '--pdf-endpoint');
    $config['health_endpoint'] = normalizePdfRendererLatencyEndpointPath(
        $config['health_endpoint'],
        '--health-endpoint',
    );
}

function requirePdfRendererLatencyNonEmptyCliValue(string $arg, string $prefix): string
{
    $value = substr($arg, strlen($prefix));

    if ($value === '') {
        throw new RuntimeException(sprintf('CLI option %s requires a non-empty value.', rtrim($prefix, '=')));
    }

    return $value;
}

function normalizePdfRendererLatencyPositiveInt(string $value, string $option): int
{
    if (!preg_match('/^[0-9]+$/', $value)) {
        throw new RuntimeException(sprintf('CLI option %s expects a positive integer.', $option));
    }

    $intValue = (int) $value;

    if ($intValue <= 0) {
        throw new RuntimeException(sprintf('CLI option %s expects a positive integer.', $option));
    }

    return $intValue;
}

function normalizePdfRendererLatencyNonNegativeInt(string $value, string $option): int
{
    if (!preg_match('/^[0-9]+$/', $value)) {
        throw new RuntimeException(sprintf('CLI option %s expects a non-negative integer.', $option));
    }

    return (int) $value;
}

function normalizePdfRendererLatencyEndpointPath(string $path, string $option): string
{
    if (!str_starts_with($path, '/')) {
        throw new RuntimeException(sprintf('CLI option %s must start with "/".', $option));
    }

    return $path;
}

/**
 * @param array{
 *     base_url:string,
 *     pdf_endpoint:string,
 *     health_endpoint:string,
 *     iterations:int,
 *     warmup_iterations:int,
 *     timeout_seconds:int,
 *     retry_count:int,
 *     skip_health_check:bool
 * } $config
 * @param null|callable(string, string, ?string, int, array<int, string>):array{status:int,headers:array<string,string>,body:string} $requester
 * @return array{
 *     samples:array<int, array{iteration:int,warmup:bool,duration_ms:float}>,
 *     measured_durations_ms:array<int, float>
 * }
 */
function measurePdfRendererLatency(array $config, ?callable $requester = null): array
{
    $request = $requester ?? buildPdfRendererLatencyHttpRequester();
    $headers = ['Content-Type: application/json', 'Accept: application/pdf'];
    $payload = json_encode(buildPdfRendererLatencyFixturePayload(), JSON_THROW_ON_ERROR);

    if ($config['skip_health_check'] === false) {
        $healthResponse = $request(
            'GET',
            $config['base_url'] . $config['health_endpoint'],
            null,
            $config['timeout_seconds'],
            ['Accept: application/json'],
        );

        if ($healthResponse['status'] !== 200) {
            throw new RuntimeException(sprintf('Health check failed with HTTP %d.', $healthResponse['status']));
        }
    }

    $totalRequests = $config['iterations'] + $config['warmup_iterations'];
    $samples = [];
    $measuredDurations = [];

    for ($index = 0; $index < $totalRequests; $index++) {
        $response = null;
        $durationMs = 0.0;

        for ($attempt = 1; $attempt <= $config['retry_count']; $attempt++) {
            $start = microtime(true);
            $response = $request(
                'POST',
                $config['base_url'] . $config['pdf_endpoint'],
                $payload,
                $config['timeout_seconds'],
                $headers,
            );
            $durationMs = round((microtime(true) - $start) * 1000.0, 3);

            if ($response['status'] === 200) {
                break;
            }

            if ($attempt < $config['retry_count'] && $response['status'] >= 500) {
                usleep(200000);
                continue;
            }

            break;
        }

        if ($response === null) {
            throw new RuntimeException(sprintf('PDF request #%d produced no response.', $index + 1));
        }

        $isWarmup = $index < $config['warmup_iterations'];

        if ($response['status'] !== 200) {
            $snippet = summarizePdfRendererLatencyBodySnippet($response['body']);
            throw new RuntimeException(
                sprintf(
                    'PDF request #%d failed with HTTP %d.%s',
                    $index + 1,
                    $response['status'],
                    $snippet !== '' ? ' Response: ' . $snippet : '',
                ),
            );
        }

        $contentType = strtolower($response['headers']['content-type'] ?? '');

        if (!str_contains($contentType, 'application/pdf')) {
            throw new RuntimeException(
                sprintf('PDF request #%d returned unexpected content-type "%s".', $index + 1, $contentType),
            );
        }

        if ($response['body'] === '') {
            throw new RuntimeException(sprintf('PDF request #%d returned an empty body.', $index + 1));
        }

        $samples[] = [
            'iteration' => $index + 1,
            'warmup' => $isWarmup,
            'duration_ms' => $durationMs,
        ];

        if (!$isWarmup) {
            $measuredDurations[] = $durationMs;
        }
    }

    return [
        'samples' => $samples,
        'measured_durations_ms' => $measuredDurations,
    ];
}

/**
 * @return callable(string, string, ?string, int, array<int, string>):array{status:int,headers:array<string,string>,body:string}
 */
function buildPdfRendererLatencyHttpRequester(): callable
{
    if (function_exists('curl_init')) {
        return static function (
            string $method,
            string $url,
            ?string $body,
            int $timeoutSeconds,
            array $headers,
        ): array {
            $ch = curl_init($url);

            if ($ch === false) {
                throw new RuntimeException('Failed to initialize curl.');
            }

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeoutSeconds));
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $rawResponse = curl_exec($ch);

            if ($rawResponse === false) {
                $message = curl_error($ch);
                throw new RuntimeException('HTTP request failed: ' . $message);
            }

            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            $rawHeaders = substr($rawResponse, 0, $headerSize);
            $responseBody = substr($rawResponse, $headerSize);

            return [
                'status' => $status,
                'headers' => parsePdfRendererLatencyHeaders($rawHeaders),
                'body' => $responseBody,
            ];
        };
    }

    return static function (string $method, string $url, ?string $body, int $timeoutSeconds, array $headers): array {
        $contextOptions = [
            'http' => [
                'method' => $method,
                'ignore_errors' => true,
                'timeout' => $timeoutSeconds,
                'header' => implode("\r\n", $headers),
            ],
        ];

        if ($body !== null) {
            $contextOptions['http']['content'] = $body;
        }

        $context = stream_context_create($contextOptions);
        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false && empty($http_response_header)) {
            throw new RuntimeException('HTTP request failed via stream context.');
        }

        $responseHeaders = $http_response_header ?? [];
        $status = extractPdfRendererLatencyStatusCode($responseHeaders);

        return [
            'status' => $status,
            'headers' => parsePdfRendererLatencyHeaderLines($responseHeaders),
            'body' => $responseBody !== false ? $responseBody : '',
        ];
    };
}

/**
 * @param array<int, string> $lines
 */
function extractPdfRendererLatencyStatusCode(array $lines): int
{
    foreach ($lines as $line) {
        if (preg_match('/^HTTP\/\S+\s+([0-9]{3})/', $line, $matches) === 1) {
            return (int) $matches[1];
        }
    }

    throw new RuntimeException('Unable to parse HTTP status code from response headers.');
}

function parsePdfRendererLatencyHeaders(string $rawHeaders): array
{
    $lines = preg_split('/\r\n|\n|\r/', trim($rawHeaders));

    if ($lines === false) {
        return [];
    }

    return parsePdfRendererLatencyHeaderLines($lines);
}

/**
 * @param array<int, string> $lines
 * @return array<string, string>
 */
function parsePdfRendererLatencyHeaderLines(array $lines): array
{
    $headers = [];

    foreach ($lines as $line) {
        $separatorPos = strpos($line, ':');

        if ($separatorPos === false) {
            continue;
        }

        $key = strtolower(trim(substr($line, 0, $separatorPos)));
        $value = trim(substr($line, $separatorPos + 1));

        if ($key === '') {
            continue;
        }

        $headers[$key] = $value;
    }

    return $headers;
}

function summarizePdfRendererLatencyBodySnippet(string $body): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', $body) ?? '');

    if ($normalized === '') {
        return '';
    }

    if (strlen($normalized) > 240) {
        return substr($normalized, 0, 240) . '...';
    }

    return $normalized;
}

/**
 * @return array{html:string,waitFor:string,format:string}
 */
function buildPdfRendererLatencyFixturePayload(): array
{
    return [
        'html' =>
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>PDF Latency Fixture</title>' .
            '<style>body{font-family:Arial,sans-serif;margin:0;padding:16px;}' .
            'h1{font-size:20px;margin-bottom:8px;}table{width:100%;border-collapse:collapse;font-size:12px;}' .
            'th,td{border:1px solid #666;padding:6px;}th{background:#f1f1f1;text-align:left;}' .
            '.meta{margin-top:12px;color:#555;}</style></head><body>' .
            '<h1>PDF Renderer Latency Fixture</h1><p>Deterministic static fixture for CI latency checks.</p><table>' .
            '<thead><tr><th>ID</th><th>Name</th><th>Value</th></tr></thead><tbody>' .
            '<tr><td>1</td><td>Alpha</td><td>42</td></tr>' .
            '<tr><td>2</td><td>Beta</td><td>1337</td></tr>' .
            '<tr><td>3</td><td>Gamma</td><td>9001</td></tr>' .
            '</tbody></table><p class="meta">Generated for reproducible p50/p95 checks.</p></body></html>',
        'waitFor' => 'networkidle',
        'format' => 'A4',
    ];
}

/**
 * @param array<int, float> $durationsMs
 * @param array{
 *   min_samples:int,
 *   warn:array{p50_ms:float,p95_ms:float},
 *   fail:array{p50_ms:float,p95_ms:float},
 *   max_stddev_ms:float
 * } $policy
 * @return array{
 *   status:string,
 *   metrics:array{p50_ms:float,p95_ms:float,mean_ms:float,stddev_ms:float,min_ms:float,max_ms:float},
 *   messages:array<int, string>
 * }
 */
function evaluatePdfRendererLatency(array $durationsMs, array $policy): array
{
    if (count($durationsMs) < $policy['min_samples']) {
        throw new RuntimeException(
            sprintf(
                'Not enough measured samples: got %d, expected at least %d.',
                count($durationsMs),
                $policy['min_samples'],
            ),
        );
    }

    sort($durationsMs);

    $count = count($durationsMs);
    $sum = array_sum($durationsMs);
    $mean = $sum / $count;
    $variance = 0.0;

    foreach ($durationsMs as $value) {
        $variance += ($value - $mean) ** 2;
    }

    $stddev = sqrt($variance / $count);
    $p50 = percentilePdfRendererLatencyNearestRank($durationsMs, 50.0);
    $p95 = percentilePdfRendererLatencyNearestRank($durationsMs, 95.0);

    $messages = [];
    $status = 'pass';

    if ($p50 > $policy['fail']['p50_ms']) {
        $messages[] = sprintf('p50 %.2fms exceeds fail threshold %.2fms.', $p50, $policy['fail']['p50_ms']);
    }

    if ($p95 > $policy['fail']['p95_ms']) {
        $messages[] = sprintf('p95 %.2fms exceeds fail threshold %.2fms.', $p95, $policy['fail']['p95_ms']);
    }

    if ($messages !== []) {
        $status = 'fail';
    } else {
        if ($p50 > $policy['warn']['p50_ms']) {
            $messages[] = sprintf('p50 %.2fms exceeds warn threshold %.2fms.', $p50, $policy['warn']['p50_ms']);
        }

        if ($p95 > $policy['warn']['p95_ms']) {
            $messages[] = sprintf('p95 %.2fms exceeds warn threshold %.2fms.', $p95, $policy['warn']['p95_ms']);
        }

        if ($stddev > $policy['max_stddev_ms']) {
            $messages[] = sprintf(
                'stddev %.2fms exceeds stability threshold %.2fms.',
                $stddev,
                $policy['max_stddev_ms'],
            );
        }

        if ($messages !== []) {
            $status = 'warn';
        }
    }

    return [
        'status' => $status,
        'metrics' => [
            'p50_ms' => round($p50, 3),
            'p95_ms' => round($p95, 3),
            'mean_ms' => round($mean, 3),
            'stddev_ms' => round($stddev, 3),
            'min_ms' => round((float) $durationsMs[0], 3),
            'max_ms' => round((float) $durationsMs[$count - 1], 3),
        ],
        'messages' => $messages,
    ];
}

/**
 * @param array<int, float> $values
 */
function percentilePdfRendererLatencyNearestRank(array $values, float $percentile): float
{
    if ($percentile < 0.0 || $percentile > 100.0) {
        throw new RuntimeException('Percentile must be between 0 and 100.');
    }

    $count = count($values);

    if ($count === 0) {
        throw new RuntimeException('Cannot compute percentile for empty sample set.');
    }

    $rank = (int) ceil(($percentile / 100.0) * $count);
    $rank = max(1, min($count, $rank));

    return (float) $values[$rank - 1];
}

/**
 * @return array{
 *   min_samples:int,
 *   warn:array{p50_ms:float,p95_ms:float},
 *   fail:array{p50_ms:float,p95_ms:float},
 *   max_stddev_ms:float
 * }
 */
function loadPdfRendererLatencyPolicy(string $policyFile): array
{
    if (!is_file($policyFile)) {
        throw new RuntimeException('PDF renderer latency policy file not found: ' . $policyFile);
    }

    $policy = require $policyFile;

    if (!is_array($policy)) {
        throw new RuntimeException('PDF renderer latency policy must return an array.');
    }

    $requiredTopLevel = ['min_samples', 'warn', 'fail', 'max_stddev_ms'];

    foreach ($requiredTopLevel as $key) {
        if (!array_key_exists($key, $policy)) {
            throw new RuntimeException('Missing PDF renderer latency policy key: ' . $key);
        }
    }

    $minSamples = filter_var($policy['min_samples'], FILTER_VALIDATE_INT);

    if ($minSamples === false || $minSamples < 1) {
        throw new RuntimeException('Policy key min_samples must be an integer >= 1.');
    }

    if (!is_array($policy['warn']) || !is_array($policy['fail'])) {
        throw new RuntimeException('Policy keys warn/fail must be arrays.');
    }

    foreach (['warn', 'fail'] as $bucket) {
        foreach (['p50_ms', 'p95_ms'] as $metric) {
            if (!array_key_exists($metric, $policy[$bucket]) || !is_numeric($policy[$bucket][$metric])) {
                throw new RuntimeException(sprintf('Policy key %s.%s must be numeric.', $bucket, $metric));
            }
        }
    }

    if (!is_numeric($policy['max_stddev_ms'])) {
        throw new RuntimeException('Policy key max_stddev_ms must be numeric.');
    }

    $warnP50 = (float) $policy['warn']['p50_ms'];
    $warnP95 = (float) $policy['warn']['p95_ms'];
    $failP50 = (float) $policy['fail']['p50_ms'];
    $failP95 = (float) $policy['fail']['p95_ms'];
    $maxStddev = (float) $policy['max_stddev_ms'];

    if ($warnP50 <= 0.0 || $warnP95 <= 0.0 || $failP50 <= 0.0 || $failP95 <= 0.0) {
        throw new RuntimeException('Policy p50/p95 thresholds must be > 0.');
    }

    if ($warnP50 > $failP50 || $warnP95 > $failP95) {
        throw new RuntimeException('Policy warn thresholds must be <= fail thresholds.');
    }

    if ($maxStddev <= 0.0) {
        throw new RuntimeException('Policy max_stddev_ms must be > 0.');
    }

    return [
        'min_samples' => $minSamples,
        'warn' => [
            'p50_ms' => $warnP50,
            'p95_ms' => $warnP95,
        ],
        'fail' => [
            'p50_ms' => $failP50,
            'p95_ms' => $failP95,
        ],
        'max_stddev_ms' => $maxStddev,
    ];
}

/**
 * @param array<string, mixed> $report
 */
function writePdfRendererLatencyReport(string $path, array $report): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create report directory: ' . $directory);
    }

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Failed to encode PDF renderer latency report to JSON.');
    }

    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write PDF renderer latency report: ' . $path);
    }
}

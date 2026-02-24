#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/GateAssertions.php';
require_once __DIR__ . '/lib/GateHttpClient.php';

use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateHttpClient;

const RELEASE_GATE_EXIT_SUCCESS = 0;
const RELEASE_GATE_EXIT_ASSERTION_FAILURE = 1;
const RELEASE_GATE_EXIT_RUNTIME_ERROR = 2;

$startedAtUtc = gmdate('c');
$startedAt = microtime(true);
$checks = [];
$failure = null;
$exitCode = RELEASE_GATE_EXIT_SUCCESS;

$repoRoot = dirname(__DIR__, 2);
$defaultOutputPath = $repoRoot . '/storage/logs/release-gate/dashboard-gate-' . gmdate('Ymd\THis\Z') . '.json';

try {
    $config = parseCliOptions($defaultOutputPath);

    if ($config['help'] === true) {
        printUsage();
        exit(RELEASE_GATE_EXIT_SUCCESS);
    }

    $client = new GateHttpClient($config['base_url'], $config['index_page'], $config['http_timeout']);

    $runCheck = static function (string $name, callable $callback) use (&$checks): void {
        $started = microtime(true);

        try {
            $details = $callback();
            $durationMs = round((microtime(true) - $started) * 1000, 2);

            if (!is_array($details)) {
                $details = ['detail' => (string) $details];
            }

            $checks[] = array_merge(
                [
                    'name' => $name,
                    'status' => 'pass',
                    'duration_ms' => $durationMs,
                ],
                $details,
            );

            fwrite(STDOUT, '[PASS] ' . $name . PHP_EOL);
        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $started) * 1000, 2);
            $checks[] = [
                'name' => $name,
                'status' => 'fail',
                'duration_ms' => $durationMs,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ];

            fwrite(STDERR, '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL);
            throw $e;
        }
    };

    $runCheck('readiness_login_page', static function () use ($client, $config): array {
        $response = $client->get('login', [], $config['http_timeout']);
        GateAssertions::assertStatus($response->statusCode, 200, 'GET /login');

        $csrfCookie = $client->getCookie('csrf_cookie');
        if ($csrfCookie === null || $csrfCookie === '') {
            throw new GateAssertionException('GET /login did not set csrf_cookie.');
        }

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'content_type' => $response->header('content-type'),
            'csrf_cookie_present' => true,
        ];
    });

    if ($config['pdf_health_url'] !== null) {
        $runCheck('readiness_pdf_health', static function () use ($client, $config): array {
            $response = $client->getAbsolute($config['pdf_health_url'], $config['http_timeout']);
            GateAssertions::assertStatus($response->statusCode, 200, 'GET pdf health URL');

            $payload = GateAssertions::decodeJson($response->body, 'GET pdf health URL');

            if (!is_array($payload) || !array_key_exists('ok', $payload) || $payload['ok'] !== true) {
                throw new GateAssertionException('PDF health endpoint did not return {"ok": true}.');
            }

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'content_type' => $response->header('content-type'),
            ];
        });
    }

    $runCheck('auth_login_validate', static function () use ($client, $config): array {
        $response = $client->post(
            'login/validate',
            [
                'username' => $config['username'],
                'password' => $config['password'],
            ],
            $config['http_timeout'],
            true,
        );

        GateAssertions::assertStatus($response->statusCode, 200, 'POST /login/validate');
        $payload = GateAssertions::decodeJson($response->body, 'POST /login/validate');
        GateAssertions::assertLoginPayload($payload);

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'content_type' => $response->header('content-type'),
        ];
    });

    $runCheck('dashboard_metrics', static function () use ($client, $config): array {
        $payload = buildFilterPayload($config);
        $response = $client->post('dashboard/metrics', $payload, $config['http_timeout'], true);

        GateAssertions::assertStatus($response->statusCode, 200, 'POST /dashboard/metrics');
        $decoded = GateAssertions::decodeJson($response->body, 'POST /dashboard/metrics');
        $summary = GateAssertions::assertMetricsPayload($decoded, $config['require_nonempty_metrics']);

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'content_type' => $response->header('content-type'),
            'providers' => $summary['providers'],
            'booked_total' => $summary['booked_total'],
        ];
    });

    $runCheck('dashboard_heatmap', static function () use ($client, $config): array {
        $payload = buildFilterPayload($config);
        $response = $client->post('dashboard/heatmap', $payload, $config['http_timeout'], true);

        GateAssertions::assertStatus($response->statusCode, 200, 'POST /dashboard/heatmap');
        $decoded = GateAssertions::decodeJson($response->body, 'POST /dashboard/heatmap');
        $summary = GateAssertions::assertHeatmapPayload($decoded);

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'content_type' => $response->header('content-type'),
            'slots' => $summary['slots'],
            'total' => $summary['total'],
        ];
    });

    $runCheck('export_principal_pdf', static function () use ($client, $config): array {
        $query = buildFilterQuery($config);
        $response = $client->get('dashboard/export/principal.pdf', $query, $config['export_timeout']);

        GateAssertions::assertStatus($response->statusCode, 200, 'GET /dashboard/export/principal.pdf');
        GateAssertions::assertPdfBinary($response->body, $response->header('content-type'));

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'content_type' => $response->header('content-type'),
            'bytes' => strlen($response->body),
        ];
    });

    $runCheck('export_teacher_zip', static function () use ($client, $config): array {
        $query = buildFilterQuery($config);
        $response = $client->get('dashboard/export/teacher.zip', $query, $config['export_timeout']);

        GateAssertions::assertStatus($response->statusCode, 200, 'GET /dashboard/export/teacher.zip');
        GateAssertions::assertZipBinary($response->body, $response->header('content-type'));

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'content_type' => $response->header('content-type'),
            'bytes' => strlen($response->body),
        ];
    });

    $runCheck('export_teacher_pdf', static function () use ($client, $config): array {
        $query = buildFilterQuery($config);
        $response = $client->get('dashboard/export/teacher.pdf', $query, $config['export_timeout']);

        GateAssertions::assertStatus($response->statusCode, 200, 'GET /dashboard/export/teacher.pdf');
        GateAssertions::assertPdfBinary($response->body, $response->header('content-type'));

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'content_type' => $response->header('content-type'),
            'bytes' => strlen($response->body),
        ];
    });
} catch (GateAssertionException $e) {
    $exitCode = RELEASE_GATE_EXIT_ASSERTION_FAILURE;
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ];
} catch (Throwable $e) {
    $exitCode = RELEASE_GATE_EXIT_RUNTIME_ERROR;
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ];
}

$finishedAtUtc = gmdate('c');
$durationMs = round((microtime(true) - $startedAt) * 1000, 2);
$passedChecks = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? null) === 'pass'));
$failedChecks = count($checks) - $passedChecks;

$reportConfig = [];
if (isset($config) && is_array($config)) {
    $reportConfig = [
        'base_url' => $config['base_url'],
        'index_page' => $config['index_page'],
        'username' => $config['username'],
        'start_date' => $config['start_date'],
        'end_date' => $config['end_date'],
        'statuses' => $config['statuses'],
        'service_id' => $config['service_id'],
        'provider_ids' => $config['provider_ids'],
        'pdf_health_url' => $config['pdf_health_url'],
        'http_timeout' => $config['http_timeout'],
        'export_timeout' => $config['export_timeout'],
        'require_nonempty_metrics' => $config['require_nonempty_metrics'],
        'output_json' => $config['output_json'],
    ];
}

$outputPath = $config['output_json'] ?? $defaultOutputPath;
$report = [
    'meta' => [
        'started_at_utc' => $startedAtUtc,
        'finished_at_utc' => $finishedAtUtc,
        'duration_ms' => $durationMs,
    ],
    'config' => $reportConfig,
    'checks' => $checks,
    'summary' => [
        'passed' => $passedChecks,
        'failed' => $failedChecks,
        'exit_code' => $exitCode,
    ],
];

if ($failure !== null) {
    $report['failure'] = $failure;
}

try {
    writeJsonReport($outputPath, $report);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Could not write JSON report: ' . $e->getMessage() . PHP_EOL);

    if ($exitCode === RELEASE_GATE_EXIT_SUCCESS) {
        $exitCode = RELEASE_GATE_EXIT_RUNTIME_ERROR;
    }
}

if ($exitCode === RELEASE_GATE_EXIT_SUCCESS) {
    fwrite(
        STDOUT,
        sprintf('[PASS] Dashboard release gate passed (%d checks) -> %s%s', $passedChecks, $outputPath, PHP_EOL),
    );
} else {
    fwrite(STDERR, sprintf('[FAIL] Dashboard release gate failed (exit %d) -> %s%s', $exitCode, $outputPath, PHP_EOL));
}

exit($exitCode);

/**
 * @return array{
 *   help:bool,
 *   base_url:string,
 *   index_page:string,
 *   username:string,
 *   password:string,
 *   start_date:string,
 *   end_date:string,
 *   statuses:array<int, string>,
 *   service_id:int|null,
 *   provider_ids:array<int, int>,
 *   pdf_health_url:string|null,
 *   http_timeout:int,
 *   export_timeout:int,
 *   require_nonempty_metrics:bool,
 *   output_json:string
 * }
 */
function parseCliOptions(string $defaultOutputPath): array
{
    $options = getopt('', [
        'help',
        'base-url:',
        'index-page::',
        'username:',
        'password:',
        'start-date:',
        'end-date:',
        'statuses::',
        'service-id::',
        'provider-ids::',
        'pdf-health-url::',
        'http-timeout::',
        'export-timeout::',
        'require-nonempty-metrics::',
        'output-json::',
    ]);

    if (!is_array($options)) {
        throw new InvalidArgumentException('Failed to parse CLI options.');
    }

    $help = array_key_exists('help', $options);

    if ($help) {
        return [
            'help' => true,
            'base_url' => '',
            'index_page' => 'index.php',
            'username' => '',
            'password' => '',
            'start_date' => '',
            'end_date' => '',
            'statuses' => ['Booked'],
            'service_id' => null,
            'provider_ids' => [],
            'pdf_health_url' => null,
            'http_timeout' => 15,
            'export_timeout' => 60,
            'require_nonempty_metrics' => false,
            'output_json' => $defaultOutputPath,
        ];
    }

    $baseUrl = trim(getRequiredOption($options, 'base-url'));
    $username = trim(getRequiredOption($options, 'username'));
    $password = getRequiredOption($options, 'password');
    $startDate = trim(getRequiredOption($options, 'start-date'));
    $endDate = trim(getRequiredOption($options, 'end-date'));

    $indexPageRaw = getOptionalOption(
        $options,
        'index-page',
        hasExplicitEmptyLongOption('index-page') ? '' : 'index.php',
    );
    $indexPage = $indexPageRaw === null ? 'index.php' : trim((string) $indexPageRaw);

    $pdfHealthUrlRaw = getOptionalOption($options, 'pdf-health-url', null);
    $pdfHealthUrl = $pdfHealthUrlRaw === null ? null : trim((string) $pdfHealthUrlRaw);
    if ($pdfHealthUrl === '') {
        $pdfHealthUrl = null;
    }

    $statuses = parseStringList(getOptionalOption($options, 'statuses', null), ['Booked']);
    $serviceId = parseOptionalPositiveInt(getOptionalOption($options, 'service-id', null), 'service-id');
    $providerIds = parseIntList(getOptionalOption($options, 'provider-ids', null));

    $httpTimeout = parsePositiveInt(getOptionalOption($options, 'http-timeout', 15), 'http-timeout');
    $exportTimeout = parsePositiveInt(getOptionalOption($options, 'export-timeout', 60), 'export-timeout');
    $requireNonEmptyMetrics = parseBooleanOption(getOptionalOption($options, 'require-nonempty-metrics', null));

    $outputRaw = getOptionalOption($options, 'output-json', $defaultOutputPath);
    $outputJson = trim((string) $outputRaw);

    if ($outputJson === '') {
        throw new InvalidArgumentException('Option --output-json must not be empty.');
    }

    validateDate($startDate, 'start-date');
    validateDate($endDate, 'end-date');

    if ($startDate > $endDate) {
        throw new InvalidArgumentException('start-date must be <= end-date.');
    }

    if ($baseUrl === '') {
        throw new InvalidArgumentException('Option --base-url must not be empty.');
    }

    return [
        'help' => $help,
        'base_url' => $baseUrl,
        'index_page' => $indexPage,
        'username' => $username,
        'password' => $password,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'statuses' => $statuses,
        'service_id' => $serviceId,
        'provider_ids' => $providerIds,
        'pdf_health_url' => $pdfHealthUrl,
        'http_timeout' => $httpTimeout,
        'export_timeout' => $exportTimeout,
        'require_nonempty_metrics' => $requireNonEmptyMetrics,
        'output_json' => $outputJson,
    ];
}

/**
 * @param array<string, mixed> $config
 *
 * @return array<string, mixed>
 */
function buildFilterPayload(array $config): array
{
    $payload = [
        'start_date' => $config['start_date'],
        'end_date' => $config['end_date'],
        'statuses' => $config['statuses'],
    ];

    if ($config['service_id'] !== null) {
        $payload['service_id'] = $config['service_id'];
    }

    if ($config['provider_ids'] !== []) {
        $payload['provider_ids'] = $config['provider_ids'];
    }

    return $payload;
}

/**
 * @param array<string, mixed> $config
 *
 * @return array<string, mixed>
 */
function buildFilterQuery(array $config): array
{
    return buildFilterPayload($config);
}

/**
 * @param array<string, mixed> $options
 */
function getRequiredOption(array $options, string $key): string
{
    $value = getOptionalOption($options, $key, null);

    if ($value === null) {
        throw new InvalidArgumentException('Missing required option --' . $key . '.');
    }

    $stringValue = is_array($value) ? (string) end($value) : (string) $value;

    if (trim($stringValue) === '') {
        throw new InvalidArgumentException('Option --' . $key . ' must not be empty.');
    }

    return $stringValue;
}

/**
 * @param array<string, mixed> $options
 */
function getOptionalOption(array $options, string $key, mixed $default): mixed
{
    if (!array_key_exists($key, $options)) {
        return $default;
    }

    return $options[$key];
}

function hasExplicitEmptyLongOption(string $name): bool
{
    $normalized = ltrim(trim($name), '-');
    if ($normalized === '') {
        return false;
    }

    $needle = '--' . $normalized . '=';
    $argv = $_SERVER['argv'] ?? [];

    if (!is_array($argv)) {
        return false;
    }

    foreach ($argv as $arg) {
        if (is_string($arg) && $arg === $needle) {
            return true;
        }
    }

    return false;
}

/**
 * @param mixed $raw
 * @param string[] $fallback
 *
 * @return string[]
 */
function parseStringList(mixed $raw, array $fallback): array
{
    if ($raw === null || $raw === false || $raw === '') {
        return $fallback;
    }

    $parts = [];
    $values = is_array($raw) ? $raw : [$raw];

    foreach ($values as $value) {
        foreach (explode(',', (string) $value) as $piece) {
            $normalized = trim($piece);

            if ($normalized !== '') {
                $parts[] = $normalized;
            }
        }
    }

    if ($parts === []) {
        return $fallback;
    }

    return array_values(array_unique($parts));
}

/**
 * @return int[]
 */
function parseIntList(mixed $raw): array
{
    if ($raw === null || $raw === false || $raw === '') {
        return [];
    }

    $ids = [];
    $values = is_array($raw) ? $raw : [$raw];

    foreach ($values as $value) {
        foreach (explode(',', (string) $value) as $piece) {
            $trimmed = trim($piece);

            if ($trimmed === '') {
                continue;
            }

            if (!ctype_digit($trimmed)) {
                throw new InvalidArgumentException('provider-ids must contain only positive integers.');
            }

            $id = (int) $trimmed;

            if ($id <= 0) {
                throw new InvalidArgumentException('provider-ids must contain only positive integers.');
            }

            $ids[] = $id;
        }
    }

    $ids = array_values(array_unique($ids));

    return $ids;
}

function parseOptionalPositiveInt(mixed $raw, string $optionName): ?int
{
    if ($raw === null || $raw === false || $raw === '') {
        return null;
    }

    return parsePositiveInt($raw, $optionName);
}

function parsePositiveInt(mixed $raw, string $optionName): int
{
    $value = is_array($raw) ? end($raw) : $raw;
    $string = trim((string) $value);

    if ($string === '' || !ctype_digit($string)) {
        throw new InvalidArgumentException('Option --' . $optionName . ' must be a positive integer.');
    }

    $number = (int) $string;

    if ($number <= 0) {
        throw new InvalidArgumentException('Option --' . $optionName . ' must be > 0.');
    }

    return $number;
}

function parseBooleanOption(mixed $raw): bool
{
    if ($raw === null) {
        return false;
    }

    if ($raw === false) {
        return true;
    }

    if (is_bool($raw)) {
        return $raw;
    }

    $value = is_array($raw) ? end($raw) : $raw;
    $normalized = strtolower(trim((string) $value));

    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    throw new InvalidArgumentException('Option --require-nonempty-metrics must be boolean-like.');
}

function validateDate(string $value, string $optionName): void
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Option --' . $optionName . ' must use format YYYY-MM-DD.');
    }
}

/**
 * @param array<string, mixed> $report
 */
function writeJsonReport(string $path, array $report): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create report directory: ' . $directory);
    }

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if ($json === false) {
        throw new RuntimeException('Could not encode release gate report as JSON.');
    }

    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Could not write release gate report to: ' . $path);
    }
}

function printUsage(): void
{
    $lines = [
        'Dashboard Release Gate (MVP)',
        '',
        'Usage:',
        '  php scripts/release-gate/dashboard_release_gate.php [options]',
        '',
        'Required:',
        '  --base-url=URL                 App base URL (example: http://localhost)',
        '  --username=NAME                Admin username for login',
        '  --password=PASS                Admin password for login',
        '  --start-date=YYYY-MM-DD        Filter start date (inclusive)',
        '  --end-date=YYYY-MM-DD          Filter end date (inclusive)',
        '',
        'Optional:',
        '  --index-page=VALUE             URL index page segment (default: index.php, use empty for rewrite mode)',
        '  --statuses=CSV                 Appointment statuses (default: Booked)',
        '  --service-id=ID                Service filter',
        '  --provider-ids=CSV             Provider IDs filter',
        '  --pdf-health-url=URL           Optional PDF renderer health URL',
        '  --http-timeout=SECONDS         HTTP timeout for JSON checks (default: 15)',
        '  --export-timeout=SECONDS       HTTP timeout for exports (default: 60)',
        '  --require-nonempty-metrics     Fail if metrics payload is empty',
        '  --require-nonempty-metrics=0|1 Explicitly set non-empty requirement',
        '  --output-json=PATH             JSON report output path',
        '  --help                         Show this help',
        '',
        'Exit codes:',
        '  0  Success',
        '  1  Assertion failure',
        '  2  Runtime/configuration error',
    ];

    fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
}

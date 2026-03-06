<?php

declare(strict_types=1);

const DEEP_RUNTIME_SUITE_EXIT_SUCCESS = 0;
const DEEP_RUNTIME_SUITE_EXIT_RUNTIME_ERROR = 1;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runDeepRuntimeSuiteCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runDeepRuntimeSuiteCli(array $argv): int
{
    $config = deepRuntimeSuiteDefaultConfig();

    try {
        parseDeepRuntimeSuiteCliOptions($argv, $config);

        if ($config['help'] === true) {
            fwrite(STDOUT, deepRuntimeSuiteUsage());

            return DEEP_RUNTIME_SUITE_EXIT_SUCCESS;
        }

        prepareDeepRuntimeReportDirectory((string) $config['report_dir']);
        $suiteDefinitions = buildDeepRuntimeSuiteDefinitions($config);
        $manifest = runConfiguredDeepRuntimeSuites(
            $suiteDefinitions,
            static fn(array $suite): int => executeDeepRuntimeSuiteCommand($suite),
        );
        writeDeepRuntimeSuiteManifest($config['manifest_path'], $manifest);

        fwrite(
            STDOUT,
            sprintf(
                '[PASS] deep-runtime-suite wrote manifest for %d suite(s).%s',
                count($manifest['requested_suites']),
                PHP_EOL,
            ),
        );
        fwrite(STDOUT, '[INFO] Manifest: ' . $config['manifest_path'] . PHP_EOL);

        return DEEP_RUNTIME_SUITE_EXIT_SUCCESS;
    } catch (Throwable $e) {
        fwrite(STDERR, '[ERROR] deep-runtime-suite failed: ' . $e->getMessage() . PHP_EOL);

        return DEEP_RUNTIME_SUITE_EXIT_RUNTIME_ERROR;
    }
}

function deepRuntimeSuiteUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/run_deep_runtime_suite.php [options]',
        '',
        'Options:',
        '  --suites=LIST          Comma-separated suite IDs to execute (required).',
        '  --base-url=URL         Base URL used by HTTP deep suites.',
        '  --index-page=VALUE     Index page segment (default: index.php).',
        '  --openapi-spec=PATH    OpenAPI spec path for API contract suites.',
        '  --username=VALUE       Smoke login username.',
        '  --password=VALUE       Smoke login password.',
        '  --booking-search-days=N  Booking search horizon (default: 14).',
        '  --retry-count=N        Write-contract retry count (default: 1).',
        '  --start-date=DATE      Dashboard/integration smoke start date.',
        '  --end-date=DATE        Dashboard/integration smoke end date.',
        '  --report-dir=PATH      Output directory for suite manifest/reports.',
        '  --help                 Show this help text.',
        '',
    ]);
}

/**
 * @return array{
 *   suites_raw:string,
 *   base_url:string,
 *   index_page:string,
 *   openapi_spec:string,
 *   username:string,
 *   password:string,
 *   booking_search_days:int,
 *   retry_count:int,
 *   start_date:string,
 *   end_date:string,
 *   report_dir:string,
 *   manifest_path:string,
 *   help:bool
 * }
 */
function deepRuntimeSuiteDefaultConfig(): array
{
    $root = dirname(__DIR__, 2);
    $reportDir = $root . '/storage/logs/ci/deep-runtime-suite';

    return [
        'suites_raw' => '',
        'base_url' => 'http://nginx',
        'index_page' => 'index.php',
        'openapi_spec' => '/var/www/html/openapi.yml',
        'username' => 'administrator',
        'password' => 'administrator',
        'booking_search_days' => 14,
        'retry_count' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'report_dir' => $reportDir,
        'manifest_path' => $reportDir . '/manifest.json',
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array<string, mixed> $config
 */
function parseDeepRuntimeSuiteCliOptions(array $argv, array &$config): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--suites=')) {
            $config['suites_raw'] = requireNonEmptyCliValue($arg, '--suites');
            continue;
        }

        if (str_starts_with($arg, '--base-url=')) {
            $config['base_url'] = requireNonEmptyCliValue($arg, '--base-url');
            continue;
        }

        if (str_starts_with($arg, '--index-page=')) {
            $config['index_page'] = requireNonEmptyCliValue($arg, '--index-page');
            continue;
        }

        if (str_starts_with($arg, '--openapi-spec=')) {
            $config['openapi_spec'] = requireNonEmptyCliValue($arg, '--openapi-spec');
            continue;
        }

        if (str_starts_with($arg, '--username=')) {
            $config['username'] = requireNonEmptyCliValue($arg, '--username');
            continue;
        }

        if (str_starts_with($arg, '--password=')) {
            $config['password'] = requireNonEmptyCliValue($arg, '--password');
            continue;
        }

        if (str_starts_with($arg, '--booking-search-days=')) {
            $config['booking_search_days'] = parsePositiveIntCliOption($arg, '--booking-search-days');
            continue;
        }

        if (str_starts_with($arg, '--retry-count=')) {
            $config['retry_count'] = parseNonNegativeIntCliOption($arg, '--retry-count');
            continue;
        }

        if (str_starts_with($arg, '--start-date=')) {
            $config['start_date'] = requireNonEmptyCliValue($arg, '--start-date');
            continue;
        }

        if (str_starts_with($arg, '--end-date=')) {
            $config['end_date'] = requireNonEmptyCliValue($arg, '--end-date');
            continue;
        }

        if (str_starts_with($arg, '--report-dir=')) {
            $config['report_dir'] = requireNonEmptyCliValue($arg, '--report-dir');
            $config['manifest_path'] = rtrim($config['report_dir'], '/') . '/manifest.json';
            continue;
        }

        throw new RuntimeException('Unknown CLI option: ' . $arg);
    }
}

function requireNonEmptyCliValue(string $arg, string $option): string
{
    $value = substr($arg, strlen($option . '='));

    if ($value === '') {
        throw new RuntimeException('CLI option ' . $option . ' requires a non-empty value.');
    }

    return $value;
}

function parsePositiveIntCliOption(string $arg, string $option): int
{
    $value = requireNonEmptyCliValue($arg, $option);

    if (!ctype_digit($value) || (int) $value <= 0) {
        throw new RuntimeException('CLI option ' . $option . ' requires a positive integer.');
    }

    return (int) $value;
}

function parseNonNegativeIntCliOption(string $arg, string $option): int
{
    $value = requireNonEmptyCliValue($arg, $option);

    if (!ctype_digit($value)) {
        throw new RuntimeException('CLI option ' . $option . ' requires a non-negative integer.');
    }

    return (int) $value;
}

/**
 * @param array<string, mixed> $config
 * @return array<int, array<string, mixed>>
 */
function buildDeepRuntimeSuiteDefinitions(array $config): array
{
    $requestedSuites = resolveRequestedDeepRuntimeSuites((string) $config['suites_raw']);
    $reportDir = rtrim((string) $config['report_dir'], '/');
    deepRuntimeEnsureParentDirectoryExists($reportDir . '/.keep');

    $definitions = [];

    foreach ($requestedSuites as $suiteId) {
        $logPath = $reportDir . '/' . $suiteId . '.log';
        $definitions[] = match ($suiteId) {
            'api-contract-openapi' => [
                'id' => $suiteId,
                'command' => sprintf(
                    'php scripts/ci/api_openapi_contract_smoke.php --base-url=%s --index-page=%s --openapi-spec=%s --username=%s --password=%s --output-json=%s',
                    escapeshellarg((string) $config['base_url']),
                    escapeshellarg((string) $config['index_page']),
                    escapeshellarg((string) $config['openapi_spec']),
                    escapeshellarg((string) $config['username']),
                    escapeshellarg((string) $config['password']),
                    escapeshellarg($reportDir . '/api-contract-openapi.json'),
                ),
                'log_path' => $logPath,
                'report_path' => $reportDir . '/api-contract-openapi.json',
                'failure_status' => 'contract_failure',
            ],
            'write-contract-booking' => [
                'id' => $suiteId,
                'command' => sprintf(
                    'php scripts/ci/booking_write_contract_smoke.php --base-url=%s --index-page=%s --username=%s --password=%s --booking-search-days=%d --retry-count=%d --checks=%s --output-json=%s',
                    escapeshellarg((string) $config['base_url']),
                    escapeshellarg((string) $config['index_page']),
                    escapeshellarg((string) $config['username']),
                    escapeshellarg((string) $config['password']),
                    (int) $config['booking_search_days'],
                    (int) $config['retry_count'],
                    escapeshellarg(
                        'booking_register_success_contract,booking_register_manage_update_contract,booking_register_unavailable_contract,booking_reschedule_manage_mode_contract,booking_cancel_success_contract,booking_cancel_unknown_hash_contract',
                    ),
                    escapeshellarg($reportDir . '/write-contract-booking.json'),
                ),
                'log_path' => $logPath,
                'report_path' => $reportDir . '/write-contract-booking.json',
                'failure_status' => 'contract_failure',
            ],
            'write-contract-api' => [
                'id' => $suiteId,
                'command' => sprintf(
                    'php scripts/ci/api_openapi_write_contract_smoke.php --base-url=%s --index-page=%s --openapi-spec=%s --username=%s --password=%s --retry-count=%d --booking-search-days=%d --checks=%s --output-json=%s',
                    escapeshellarg((string) $config['base_url']),
                    escapeshellarg((string) $config['index_page']),
                    escapeshellarg((string) $config['openapi_spec']),
                    escapeshellarg((string) $config['username']),
                    escapeshellarg((string) $config['password']),
                    (int) $config['retry_count'],
                    (int) $config['booking_search_days'],
                    escapeshellarg(
                        'appointments_write_unauthorized_guard,customers_store_contract,appointments_store_contract,appointments_update_contract,appointments_destroy_contract,customers_destroy_contract',
                    ),
                    escapeshellarg($reportDir . '/write-contract-api.json'),
                ),
                'log_path' => $logPath,
                'report_path' => $reportDir . '/write-contract-api.json',
                'failure_status' => 'contract_failure',
            ],
            'booking-controller-flows' => [
                'id' => $suiteId,
                'command' => 'composer test:booking-controller-flows',
                'log_path' => $logPath,
                'report_path' => null,
                'failure_status' => 'runtime_error',
            ],
            'integration-smoke' => [
                'id' => $suiteId,
                'command' => sprintf(
                    'php scripts/ci/dashboard_integration_smoke.php --base-url=%s --index-page=%s --username=%s --password=%s --start-date=%s --end-date=%s --checks=%s --output-json=%s',
                    escapeshellarg((string) $config['base_url']),
                    escapeshellarg((string) $config['index_page']),
                    escapeshellarg((string) $config['username']),
                    escapeshellarg((string) $config['password']),
                    escapeshellarg((string) $config['start_date']),
                    escapeshellarg((string) $config['end_date']),
                    escapeshellarg(
                        'readiness_login_page,auth_login_validate,dashboard_metrics,booking_page_readiness,booking_extract_bootstrap,booking_available_hours,booking_unavailable_dates,api_unauthorized_guard,api_appointments_index,api_availabilities',
                    ),
                    escapeshellarg($reportDir . '/integration-smoke.json'),
                ),
                'log_path' => $logPath,
                'report_path' => $reportDir . '/integration-smoke.json',
                'failure_status' => 'contract_failure',
            ],
            default => throw new RuntimeException('Unsupported deep runtime suite: ' . $suiteId),
        };
    }

    return $definitions;
}

/**
 * @return array<int, string>
 */
function resolveRequestedDeepRuntimeSuites(string $suitesRaw): array
{
    $allowedSuites = deepRuntimeSuiteOrder();
    $allowedLookup = array_fill_keys($allowedSuites, true);
    $requestedLookup = [];

    foreach (explode(',', $suitesRaw) as $suiteId) {
        $suiteId = trim($suiteId);

        if ($suiteId === '') {
            continue;
        }

        if (!isset($allowedLookup[$suiteId])) {
            throw new RuntimeException(
                'Unknown deep runtime suite: ' . $suiteId . '. Allowed: ' . implode(', ', $allowedSuites),
            );
        }

        $requestedLookup[$suiteId] = true;
    }

    if ($requestedLookup === []) {
        throw new RuntimeException('At least one deep runtime suite must be requested via --suites=');
    }

    $resolved = [];

    foreach ($allowedSuites as $suiteId) {
        if (isset($requestedLookup[$suiteId])) {
            $resolved[] = $suiteId;
        }
    }

    return $resolved;
}

/**
 * @return array<int, string>
 */
function deepRuntimeSuiteOrder(): array
{
    return [
        'api-contract-openapi',
        'write-contract-booking',
        'write-contract-api',
        'booking-controller-flows',
        'integration-smoke',
    ];
}

/**
 * @param array<int, array<string, mixed>> $suiteDefinitions
 * @param callable(array<string, mixed>):int $runner
 * @return array<string, mixed>
 */
function runConfiguredDeepRuntimeSuites(array $suiteDefinitions, callable $runner): array
{
    $manifest = [
        'schema_version' => 1,
        'requested_suites' => array_map(static fn(array $suite): string => (string) $suite['id'], $suiteDefinitions),
        'completed_at_utc' => '',
        'suites' => [],
    ];

    foreach ($suiteDefinitions as $suite) {
        $suiteId = (string) $suite['id'];
        $startedAt = microtime(true);
        fwrite(STDOUT, '[INFO] Running deep runtime suite: ' . $suiteId . PHP_EOL);

        $exitCode = $runner($suite);
        $durationSeconds = (int) round(microtime(true) - $startedAt);
        $status = $exitCode === 0 ? 'pass' : (string) $suite['failure_status'];

        $manifest['suites'][$suiteId] = [
            'status' => $status,
            'exit_code' => $exitCode,
            'duration_seconds' => $durationSeconds,
            'report_path' => $suite['report_path'],
            'log_path' => $suite['log_path'],
        ];

        $stream = $status === 'pass' ? STDOUT : STDERR;
        fwrite(
            $stream,
            sprintf(
                '[%s] deep-runtime-suite %s (%ds)%s',
                strtoupper($status === 'pass' ? 'pass' : 'fail'),
                $suiteId,
                $durationSeconds,
                PHP_EOL,
            ),
        );
    }

    $manifest['completed_at_utc'] = gmdate('c');

    return $manifest;
}

/**
 * @param array<string, mixed> $suite
 */
function executeDeepRuntimeSuiteCommand(array $suite): int
{
    $logPath = (string) $suite['log_path'];
    deepRuntimeEnsureParentDirectoryExists($logPath);
    $command =
        '/bin/bash -lc ' .
        escapeshellarg('set -o pipefail; ' . $suite['command'] . ' 2>&1 | tee ' . escapeshellarg($logPath));

    passthru($command, $exitCode);

    return $exitCode;
}

/**
 * @param array<string, mixed> $manifest
 */
function writeDeepRuntimeSuiteManifest(string $path, array $manifest): void
{
    deepRuntimeEnsureParentDirectoryExists($path);
    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        throw new RuntimeException('Failed to encode deep runtime suite manifest as JSON.');
    }

    if (file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write deep runtime suite manifest: ' . $path);
    }
}

function deepRuntimeEnsureParentDirectoryExists(string $path): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create directory: ' . $directory);
    }
}

function prepareDeepRuntimeReportDirectory(string $reportDir): void
{
    deepRuntimeEnsureParentDirectoryExists($reportDir . '/.keep');

    $staleFiles = ['manifest.json'];

    foreach (deepRuntimeSuiteOrder() as $suiteId) {
        $staleFiles[] = $suiteId . '.json';
        $staleFiles[] = $suiteId . '.log';
    }

    foreach ($staleFiles as $fileName) {
        $path = rtrim($reportDir, '/') . '/' . $fileName;

        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Failed to remove stale deep runtime artifact: ' . $path);
        }
    }
}

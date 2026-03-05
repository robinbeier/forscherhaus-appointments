#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/GateProcessRunner.php';
require_once __DIR__ . '/lib/ZeroSurpriseReport.php';

use ReleaseGate\GateProcessRunner;
use ReleaseGate\ZeroSurpriseReport;

const ZERO_SURPRISE_EXIT_SUCCESS = 0;
const ZERO_SURPRISE_EXIT_ASSERTION_FAILURE = 1;
const ZERO_SURPRISE_EXIT_RUNTIME_ERROR = 2;

$repoRoot = dirname(__DIR__, 2);
$timestamp = gmdate('Ymd\THis\Z');
$defaultOutputPath = $repoRoot . '/storage/logs/release-gate/zero-surprise-' . $timestamp . '.json';
$defaultTimezone = 'Europe/Berlin';

$report = null;
$exitCode = ZERO_SURPRISE_EXIT_RUNTIME_ERROR;
$composeProject = null;
$config = [];

try {
    $config = parseCliOptions($defaultOutputPath, $defaultTimezone);
    $composeProject = buildComposeProjectName($config['release_id']);

    $bookingReportPath = 'storage/logs/release-gate/zero-surprise-booking-' . $timestamp . '.json';
    $dashboardReportPath = 'storage/logs/release-gate/zero-surprise-dashboard-' . $timestamp . '.json';

    $report = new ZeroSurpriseReport(
        $config['release_id'],
        $composeProject,
        [
            'dump_file' => $config['dump_file'],
            'base_url' => $config['base_url'],
            'index_page' => $config['index_page'],
            'start_date' => $config['start_date'],
            'end_date' => $config['end_date'],
            'booking_search_days' => $config['booking_search_days'],
            'retry_count' => $config['retry_count'],
            'max_pdf_duration_ms' => $config['max_pdf_duration_ms'],
            'timezone' => $config['timezone'],
            'output_json' => $config['output_json'],
        ],
        $config['output_json'],
    );

    $composePrefix = composePrefix($composeProject);

    $restoreStep = runRestoreDumpStep($repoRoot, $composePrefix, $config);
    $report->addStep('restore_dump', $restoreStep['status'], $restoreStep['exit_code'], $restoreStep['duration_ms'], [
        'details' => $restoreStep['details'],
    ]);

    $bookingReport = null;
    $dashboardReport = null;

    if ($restoreStep['status'] === ZeroSurpriseReport::STATUS_PASS) {
        $bookingCommand = composeCommand($composePrefix, [
            'exec',
            '-T',
            'php-fpm',
            'php',
            'scripts/ci/booking_write_contract_smoke.php',
            '--base-url=' . $config['base_url'],
            '--index-page=' . $config['index_page'],
            '--username=' . $config['username'],
            '--password=' . $config['password'],
            '--booking-search-days=' . $config['booking_search_days'],
            '--retry-count=' . $config['retry_count'],
            '--run-id=' . buildRunId($config['release_id']),
            '--timezone=' . $config['timezone'],
            '--output-json=' . $bookingReportPath,
        ]);

        $bookingStep = runExternalStep($bookingCommand, $repoRoot, 900);
        $report->addStep(
            'booking_write_replay',
            $bookingStep['status'],
            $bookingStep['exit_code'],
            $bookingStep['duration_ms'],
            [
                'child_report' => $bookingReportPath,
                'command' => $bookingStep['command'],
                'timed_out' => $bookingStep['timed_out'],
                'stdout_tail' => $bookingStep['stdout_tail'],
                'stderr_tail' => $bookingStep['stderr_tail'],
            ],
        );

        $dashboardCommand = composeCommand($composePrefix, [
            'exec',
            '-T',
            'php-fpm',
            'php',
            'scripts/release-gate/dashboard_release_gate.php',
            '--base-url=' . $config['base_url'],
            '--index-page=' . $config['index_page'],
            '--username=' . $config['username'],
            '--password=' . $config['password'],
            '--start-date=' . $config['start_date'],
            '--end-date=' . $config['end_date'],
            '--max-pdf-duration-ms=' . $config['max_pdf_duration_ms'],
            '--output-json=' . $dashboardReportPath,
        ]);

        $dashboardStep = runExternalStep($dashboardCommand, $repoRoot, 900);
        $report->addStep(
            'dashboard_replay',
            $dashboardStep['status'],
            $dashboardStep['exit_code'],
            $dashboardStep['duration_ms'],
            [
                'child_report' => $dashboardReportPath,
                'command' => $dashboardStep['command'],
                'timed_out' => $dashboardStep['timed_out'],
                'stdout_tail' => $dashboardStep['stdout_tail'],
                'stderr_tail' => $dashboardStep['stderr_tail'],
            ],
        );

        $bookingReport = readJsonFile($repoRoot . '/' . $bookingReportPath);
        $dashboardReport = readJsonFile($repoRoot . '/' . $dashboardReportPath);
    } else {
        $report->setFailure(
            'restore_dump failed, subsequent replay steps were skipped.',
            RuntimeException::class,
            'runtime_error',
        );
    }

    foreach (collectInvariants($bookingReport, $dashboardReport) as $name => $invariant) {
        $report->addInvariant($name, $invariant['status'], $invariant['details']);
    }

    $exitCode = $report->determineExitCode();
} catch (Throwable $e) {
    if ($report === null) {
        $fallbackProject = $composeProject ?? 'zs-uninitialized';
        $fallbackConfig = [
            'output_json' => $defaultOutputPath,
        ];

        if ($config !== []) {
            $fallbackConfig = array_merge($fallbackConfig, [
                'dump_file' => $config['dump_file'] ?? null,
                'base_url' => $config['base_url'] ?? null,
                'index_page' => $config['index_page'] ?? null,
                'start_date' => $config['start_date'] ?? null,
                'end_date' => $config['end_date'] ?? null,
            ]);
        }

        $report = new ZeroSurpriseReport(
            (string) ($config['release_id'] ?? 'unknown-release'),
            $fallbackProject,
            $fallbackConfig,
            (string) ($config['output_json'] ?? $defaultOutputPath),
        );
    }

    $report->setFailure($e->getMessage(), get_class($e), 'runtime_error');
    $exitCode = ZERO_SURPRISE_EXIT_RUNTIME_ERROR;
}

if ($composeProject !== null) {
    $downCommand = composeCommand(composePrefix($composeProject), ['down', '-v', '--remove-orphans']);

    $downResult = GateProcessRunner::run($downCommand, $repoRoot, null, 180);

    if ((int) $downResult['exit_code'] !== 0) {
        $message = 'Failed to cleanup zero-surprise compose stack: ' . trim((string) $downResult['stderr']);

        if ($report !== null) {
            $report->setFailure(
                $message !== '' ? $message : 'Failed to cleanup zero-surprise compose stack.',
                RuntimeException::class,
                'runtime_error',
            );
        }

        $exitCode = ZERO_SURPRISE_EXIT_RUNTIME_ERROR;
    }
}

if ($report === null) {
    fwrite(STDERR, '[FAIL] Zero-surprise replay failed before report initialization.' . PHP_EOL);
    exit(ZERO_SURPRISE_EXIT_RUNTIME_ERROR);
}

$path = '';
try {
    $path = $report->write();
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Could not write zero-surprise report: ' . $e->getMessage() . PHP_EOL);
    exit(ZERO_SURPRISE_EXIT_RUNTIME_ERROR);
}

$exitCode = $report->determineExitCode();

if ($exitCode === ZERO_SURPRISE_EXIT_SUCCESS) {
    fwrite(STDOUT, '[PASS] Zero-surprise replay passed -> ' . $path . PHP_EOL);
} else {
    fwrite(STDERR, '[FAIL] Zero-surprise replay failed (exit ' . $exitCode . ') -> ' . $path . PHP_EOL);
}

exit($exitCode);

/**
 * @return array<string, mixed>
 */
function parseCliOptions(string $defaultOutputPath, string $defaultTimezone): array
{
    $options = getopt('', [
        'release-id:',
        'dump-file:',
        'base-url:',
        'index-page:',
        'username:',
        'password:',
        'start-date:',
        'end-date:',
        'booking-search-days::',
        'retry-count::',
        'max-pdf-duration-ms::',
        'timezone::',
        'output-json::',
    ]);

    if (!is_array($options)) {
        throw new InvalidArgumentException('Failed to parse CLI options.');
    }

    $releaseId = trim(getRequiredOption($options, 'release-id'));
    $dumpFile = trim(getRequiredOption($options, 'dump-file'));
    $baseUrl = trim(getRequiredOption($options, 'base-url'));
    $indexPage = getRequiredOptionAllowEmpty($options, 'index-page');
    $username = trim(getRequiredOption($options, 'username'));
    $password = getRequiredOption($options, 'password');
    $startDate = trim(getRequiredOption($options, 'start-date'));
    $endDate = trim(getRequiredOption($options, 'end-date'));

    $bookingSearchDays = parsePositiveInt(
        getOptionalOption($options, 'booking-search-days', 14),
        'booking-search-days',
    );
    $retryCount = parseNonNegativeInt(getOptionalOption($options, 'retry-count', 1), 'retry-count');
    $maxPdfDurationMs = parsePositiveInt(
        getOptionalOption($options, 'max-pdf-duration-ms', 30000),
        'max-pdf-duration-ms',
    );

    $timezone = trim((string) getOptionalOption($options, 'timezone', $defaultTimezone));
    if ($timezone === '') {
        throw new InvalidArgumentException('Option --timezone must not be empty.');
    }
    try {
        new DateTimeZone($timezone);
    } catch (Exception $exception) {
        throw new InvalidArgumentException('Option --timezone is invalid: ' . $timezone, 0, $exception);
    }

    $outputJson = trim((string) getOptionalOption($options, 'output-json', $defaultOutputPath));
    if ($outputJson === '') {
        throw new InvalidArgumentException('Option --output-json must not be empty.');
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $releaseId)) {
        throw new InvalidArgumentException('Option --release-id contains unsupported characters.');
    }

    if ($baseUrl === '') {
        throw new InvalidArgumentException('Option --base-url must not be empty.');
    }

    validateDate($startDate, 'start-date');
    validateDate($endDate, 'end-date');

    if ($startDate > $endDate) {
        throw new InvalidArgumentException('Option --start-date must be <= --end-date.');
    }

    if (!is_file($dumpFile) || !is_readable($dumpFile)) {
        throw new InvalidArgumentException('Option --dump-file is not readable: ' . $dumpFile);
    }

    if (!str_ends_with($dumpFile, '.sql') && !str_ends_with($dumpFile, '.sql.gz')) {
        throw new InvalidArgumentException('Option --dump-file must end with .sql or .sql.gz.');
    }

    return [
        'release_id' => $releaseId,
        'dump_file' => $dumpFile,
        'base_url' => $baseUrl,
        'index_page' => $indexPage,
        'username' => $username,
        'password' => $password,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'booking_search_days' => $bookingSearchDays,
        'retry_count' => $retryCount,
        'max_pdf_duration_ms' => $maxPdfDurationMs,
        'timezone' => $timezone,
        'output_json' => $outputJson,
    ];
}

/**
 * @param array<string, mixed> $options
 */
function getRequiredOption(array $options, string $name): string
{
    if (!array_key_exists($name, $options)) {
        throw new InvalidArgumentException('Missing required option --' . $name . '.');
    }

    $value = $options[$name];
    $resolved = is_array($value) ? (string) end($value) : (string) $value;

    if (trim($resolved) === '') {
        throw new InvalidArgumentException('Option --' . $name . ' must not be empty.');
    }

    return $resolved;
}

/**
 * @param array<string, mixed> $options
 */
function getRequiredOptionAllowEmpty(array $options, string $name): string
{
    if (!array_key_exists($name, $options)) {
        throw new InvalidArgumentException('Missing required option --' . $name . '.');
    }

    $value = $options[$name];
    if ($value === false || $value === null) {
        throw new InvalidArgumentException(
            'Option --' . $name . ' requires an explicit value (empty allowed as --' . $name . '=).',
        );
    }

    return is_array($value) ? (string) end($value) : (string) $value;
}

/**
 * @param array<string, mixed> $options
 */
function getOptionalOption(array $options, string $name, mixed $default): mixed
{
    if (!array_key_exists($name, $options)) {
        return $default;
    }

    $value = $options[$name];
    if (is_array($value)) {
        return end($value);
    }

    return $value;
}

function parsePositiveInt(mixed $raw, string $name): int
{
    if (is_int($raw)) {
        $value = $raw;
    } elseif (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1) {
        $value = (int) trim($raw);
    } else {
        throw new InvalidArgumentException('Option --' . $name . ' must be a positive integer.');
    }

    if ($value <= 0) {
        throw new InvalidArgumentException('Option --' . $name . ' must be a positive integer.');
    }

    return $value;
}

function parseNonNegativeInt(mixed $raw, string $name): int
{
    if (is_int($raw)) {
        $value = $raw;
    } elseif (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1) {
        $value = (int) trim($raw);
    } else {
        throw new InvalidArgumentException('Option --' . $name . ' must be a non-negative integer.');
    }

    if ($value < 0) {
        throw new InvalidArgumentException('Option --' . $name . ' must be a non-negative integer.');
    }

    return $value;
}

function validateDate(string $value, string $name): void
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Option --' . $name . ' must use format YYYY-MM-DD.');
    }
}

function buildComposeProjectName(string $releaseId): string
{
    $sanitized = strtolower(preg_replace('/[^a-z0-9]+/', '-', $releaseId) ?? 'release');
    $sanitized = trim($sanitized, '-');

    if ($sanitized === '') {
        $sanitized = 'release';
    }

    $suffix = strtolower(gmdate('Ymd\THis\Z')) . '-' . bin2hex(random_bytes(2));
    $prefix = 'zs-';
    $maxLength = 63;
    $reserved = strlen($prefix) + 1 + strlen($suffix);
    $maxSanitizedLength = max(1, $maxLength - $reserved);

    if (strlen($sanitized) > $maxSanitizedLength) {
        $sanitized = substr($sanitized, 0, $maxSanitizedLength);
        $sanitized = rtrim($sanitized, '-');
    }

    if ($sanitized === '') {
        $sanitized = 'release';
    }

    return $prefix . $sanitized . '-' . $suffix;
}

function buildRunId(string $releaseId): string
{
    return 'zero-surprise-' . $releaseId . '-' . gmdate('Ymd\THis\Z');
}

/**
 * @return array<int, string>
 */
function composePrefix(string $project): array
{
    return ['docker', 'compose', '-p', $project, '-f', 'docker-compose.yml', '-f', 'docker/compose.zero-surprise.yml'];
}

/**
 * @param array<int, string> $prefix
 * @param array<int, string> $arguments
 * @return array<int, string>
 */
function composeCommand(array $prefix, array $arguments): array
{
    return array_merge($prefix, $arguments);
}

/**
 * @param array<int, string> $command
 * @return array<string, mixed>
 */
function runExternalStep(array $command, string $repoRoot, int $timeoutSeconds): array
{
    $result = GateProcessRunner::run($command, $repoRoot, null, $timeoutSeconds);

    $exitCode = (int) ($result['exit_code'] ?? 1);
    $timedOut = (bool) ($result['timed_out'] ?? false);
    $status = $exitCode === 0 && !$timedOut ? ZeroSurpriseReport::STATUS_PASS : ZeroSurpriseReport::STATUS_FAIL;

    $normalizedExitCode =
        $status === ZeroSurpriseReport::STATUS_PASS
            ? ZERO_SURPRISE_EXIT_SUCCESS
            : ($exitCode === ZERO_SURPRISE_EXIT_ASSERTION_FAILURE
                ? ZERO_SURPRISE_EXIT_ASSERTION_FAILURE
                : ZERO_SURPRISE_EXIT_RUNTIME_ERROR);

    return [
        'status' => $status,
        'exit_code' => $normalizedExitCode,
        'duration_ms' => (float) ($result['duration_ms'] ?? 0.0),
        'timed_out' => $timedOut,
        'command' => redactCommandSecrets((string) ($result['command'] ?? '')),
        'stdout_tail' => tailText((string) ($result['stdout'] ?? ''), 600),
        'stderr_tail' => tailText((string) ($result['stderr'] ?? ''), 600),
    ];
}

/**
 * @param array<int, string> $composePrefix
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function runRestoreDumpStep(string $repoRoot, array $composePrefix, array $config): array
{
    $stepStartedAt = microtime(true);
    $substeps = [];

    $upResult = GateProcessRunner::run(
        composeCommand($composePrefix, ['up', '-d', 'mysql', 'php-fpm', 'nginx', 'pdf-renderer']),
        $repoRoot,
        null,
        300,
    );
    $substeps[] = summarizeSubstep('compose_up', $upResult);

    if ((int) $upResult['exit_code'] !== 0) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
            'details' => [
                'failed_substep' => 'compose_up',
                'substeps' => $substeps,
            ],
        ];
    }

    $rootReady = waitForMySqlReadiness(
        composeCommand($composePrefix, [
            'exec',
            '-T',
            'mysql',
            'mysqladmin',
            'ping',
            '-h',
            'localhost',
            '-uroot',
            '-psecret',
            '--silent',
        ]),
        $repoRoot,
    );
    $substeps[] = $rootReady;

    if ($rootReady['status'] === ZeroSurpriseReport::STATUS_FAIL) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
            'details' => [
                'failed_substep' => 'mysql_root_readiness',
                'substeps' => $substeps,
            ],
        ];
    }

    $appReady = waitForMySqlReadiness(
        composeCommand($composePrefix, [
            'exec',
            '-T',
            'mysql',
            'mysql',
            '-uuser',
            '-ppassword',
            '-e',
            'USE easyappointments; SELECT 1;',
        ]),
        $repoRoot,
        'mysql_app_user_readiness',
    );
    $substeps[] = $appReady;

    if ($appReady['status'] === ZeroSurpriseReport::STATUS_FAIL) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
            'details' => [
                'failed_substep' => 'mysql_app_user_readiness',
                'substeps' => $substeps,
            ],
        ];
    }

    $importResult = runDumpImport($composePrefix, $repoRoot, (string) $config['dump_file']);
    $substeps[] = summarizeSubstep('dump_import', $importResult);

    if ((int) $importResult['exit_code'] !== 0) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
            'details' => [
                'failed_substep' => 'dump_import',
                'substeps' => $substeps,
            ],
        ];
    }

    $migrateResult = GateProcessRunner::run(
        composeCommand($composePrefix, ['exec', '-T', 'php-fpm', 'php', 'index.php', 'console', 'migrate']),
        $repoRoot,
        null,
        300,
    );
    $substeps[] = summarizeSubstep('migrate', $migrateResult);

    if ((int) $migrateResult['exit_code'] !== 0) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
            'details' => [
                'failed_substep' => 'migrate',
                'substeps' => $substeps,
            ],
        ];
    }

    return [
        'status' => ZeroSurpriseReport::STATUS_PASS,
        'exit_code' => ZERO_SURPRISE_EXIT_SUCCESS,
        'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
        'details' => [
            'substeps' => $substeps,
        ],
    ];
}

/**
 * @param array<int, string> $composePrefix
 */
function runDumpImport(array $composePrefix, string $repoRoot, string $dumpFile): array
{
    $composeShell = toShellCommand($composePrefix);
    $mysqlImportShell = $composeShell . ' exec -T mysql mysql -uroot -psecret';

    if (str_ends_with($dumpFile, '.sql.gz')) {
        $command = [
            'bash',
            '-lc',
            'set -euo pipefail; gunzip -c ' . escapeshellarg($dumpFile) . ' | ' . $mysqlImportShell,
        ];

        return GateProcessRunner::run($command, $repoRoot, null, 900);
    }

    $command = ['bash', '-lc', 'set -euo pipefail; cat ' . escapeshellarg($dumpFile) . ' | ' . $mysqlImportShell];

    return GateProcessRunner::run($command, $repoRoot, null, 900);
}

/**
 * @param array<int, string> $command
 * @return array<string, mixed>
 */
function waitForMySqlReadiness(array $command, string $repoRoot, string $name = 'mysql_root_readiness'): array
{
    $maxAttempts = 60;
    $startedAt = microtime(true);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = GateProcessRunner::run($command, $repoRoot, null, 15);

        if ((int) $result['exit_code'] === 0) {
            return [
                'name' => $name,
                'status' => ZeroSurpriseReport::STATUS_PASS,
                'attempts' => $attempt,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'stderr_tail' => tailText((string) ($result['stderr'] ?? ''), 300),
            ];
        }

        sleep(2);
    }

    return [
        'name' => $name,
        'status' => ZeroSurpriseReport::STATUS_FAIL,
        'attempts' => $maxAttempts,
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ];
}

/**
 * @param array<string, mixed>|null $bookingReport
 * @param array<string, mixed>|null $dashboardReport
 * @return array<string, array{status:string,details:array<string, mixed>}>
 */
function collectInvariants(?array $bookingReport, ?array $dashboardReport): array
{
    return [
        'unexpected_5xx' => evaluateUnexpected5xxInvariant($bookingReport, $dashboardReport),
        'overbooking' => evaluateOverbookingInvariant($bookingReport),
        'fill_rate_math' => evaluateFillRateInvariant($dashboardReport),
        'pdf_exports' => evaluatePdfExportsInvariant($dashboardReport),
    ];
}

/**
 * @param array<string, mixed>|null $bookingReport
 * @param array<string, mixed>|null $dashboardReport
 * @return array{status:string,details:array<string, mixed>}
 */
function evaluateUnexpected5xxInvariant(?array $bookingReport, ?array $dashboardReport): array
{
    $allowlistedChecks = ['booking_register_unavailable_contract'];
    $occurrences = [];

    foreach (
        [
            'booking' => $bookingReport,
            'dashboard' => $dashboardReport,
        ]
        as $source => $report
    ) {
        if (!is_array($report)) {
            continue;
        }

        $checks = $report['checks'] ?? null;
        if (!is_array($checks)) {
            continue;
        }

        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }

            $name = (string) ($check['name'] ?? 'unknown');
            if (in_array($name, $allowlistedChecks, true)) {
                continue;
            }

            $statusCode = null;
            if (isset($check['http_status']) && is_numeric($check['http_status'])) {
                $statusCode = (int) $check['http_status'];
            } else {
                $error = (string) ($check['error'] ?? '');
                if (preg_match('/got\s+(\d{3})\./i', $error, $matches) === 1) {
                    $statusCode = (int) $matches[1];
                }
            }

            if ($statusCode === null || $statusCode < 500) {
                continue;
            }

            $occurrences[] = [
                'source' => $source,
                'check' => $name,
                'http_status' => $statusCode,
            ];
        }
    }

    return [
        'status' => $occurrences === [] ? ZeroSurpriseReport::STATUS_PASS : ZeroSurpriseReport::STATUS_FAIL,
        'details' => [
            'count' => count($occurrences),
            'allowlisted' => $allowlistedChecks,
            'occurrences' => $occurrences,
        ],
    ];
}

/**
 * @param array<string, mixed>|null $bookingReport
 * @return array{status:string,details:array<string, mixed>}
 */
function evaluateOverbookingInvariant(?array $bookingReport): array
{
    if (!is_array($bookingReport)) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'details' => [
                'reason' => 'booking write report missing.',
            ],
        ];
    }

    $check = findCheck($bookingReport, 'booking_register_unavailable_contract');

    if ($check === null) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'details' => [
                'reason' => 'booking_register_unavailable_contract check missing.',
            ],
        ];
    }

    $slotCount = is_numeric($check['slot_appointments_count'] ?? null) ? (int) $check['slot_appointments_count'] : null;

    $status = ZeroSurpriseReport::STATUS_FAIL;
    if (($check['status'] ?? null) === ZeroSurpriseReport::STATUS_PASS && $slotCount === 1) {
        $status = ZeroSurpriseReport::STATUS_PASS;
    }

    return [
        'status' => $status,
        'details' => [
            'check_status' => $check['status'] ?? null,
            'slot_appointments_count' => $slotCount,
            'slot_provider_id' => $check['slot_provider_id'] ?? null,
            'slot_service_id' => $check['slot_service_id'] ?? null,
            'slot_start' => $check['slot_start'] ?? null,
            'slot_end' => $check['slot_end'] ?? null,
        ],
    ];
}

/**
 * @param array<string, mixed>|null $dashboardReport
 * @return array{status:string,details:array<string, mixed>}
 */
function evaluateFillRateInvariant(?array $dashboardReport): array
{
    if (!is_array($dashboardReport)) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'details' => [
                'source_check' => 'dashboard_metrics',
                'reason' => 'dashboard report missing.',
            ],
        ];
    }

    $check = findCheck($dashboardReport, 'dashboard_metrics');
    if ($check === null) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'details' => [
                'source_check' => 'dashboard_metrics',
                'reason' => 'dashboard_metrics check missing.',
            ],
        ];
    }

    return [
        'status' =>
            ($check['status'] ?? null) === ZeroSurpriseReport::STATUS_PASS
                ? ZeroSurpriseReport::STATUS_PASS
                : ZeroSurpriseReport::STATUS_FAIL,
        'details' => [
            'source_check' => 'dashboard_metrics',
            'check_status' => $check['status'] ?? null,
        ],
    ];
}

/**
 * @param array<string, mixed>|null $dashboardReport
 * @return array{status:string,details:array<string, mixed>}
 */
function evaluatePdfExportsInvariant(?array $dashboardReport): array
{
    if (!is_array($dashboardReport)) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'details' => [
                'reason' => 'dashboard report missing.',
            ],
        ];
    }

    $principal = findCheck($dashboardReport, 'export_principal_pdf');
    $teacher = findCheck($dashboardReport, 'export_teacher_pdf');

    $principalStatus = $principal['status'] ?? 'missing';
    $teacherStatus = $teacher['status'] ?? 'missing';

    $status =
        $principalStatus === ZeroSurpriseReport::STATUS_PASS && $teacherStatus === ZeroSurpriseReport::STATUS_PASS
            ? ZeroSurpriseReport::STATUS_PASS
            : ZeroSurpriseReport::STATUS_FAIL;

    return [
        'status' => $status,
        'details' => [
            'principal_pdf' => $principalStatus,
            'teacher_pdf' => $teacherStatus,
            'principal_duration_ms' => $principal['duration_ms'] ?? null,
            'teacher_duration_ms' => $teacher['duration_ms'] ?? null,
        ],
    ];
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>|null
 */
function findCheck(array $report, string $checkName): ?array
{
    $checks = $report['checks'] ?? null;
    if (!is_array($checks)) {
        return null;
    }

    foreach ($checks as $check) {
        if (!is_array($check)) {
            continue;
        }

        if (($check['name'] ?? null) === $checkName) {
            return $check;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function readJsonFile(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function summarizeSubstep(string $name, array $result): array
{
    return [
        'name' => $name,
        'status' =>
            (int) ($result['exit_code'] ?? 1) === 0 ? ZeroSurpriseReport::STATUS_PASS : ZeroSurpriseReport::STATUS_FAIL,
        'exit_code' => (int) ($result['exit_code'] ?? 1),
        'duration_ms' => (float) ($result['duration_ms'] ?? 0.0),
        'command' => (string) ($result['command'] ?? ''),
        'timed_out' => (bool) ($result['timed_out'] ?? false),
        'stdout_tail' => tailText((string) ($result['stdout'] ?? ''), 400),
        'stderr_tail' => tailText((string) ($result['stderr'] ?? ''), 400),
    ];
}

/**
 * @param array<int, string> $tokens
 */
function toShellCommand(array $tokens): string
{
    $escaped = array_map(static fn(string $token): string => escapeshellarg($token), $tokens);

    return implode(' ', $escaped);
}

function tailText(string $text, int $limit): string
{
    $trimmed = trim($text);

    if ($trimmed === '' || strlen($trimmed) <= $limit) {
        return $trimmed;
    }

    return substr($trimmed, -$limit);
}

function redactCommandSecrets(string $command): string
{
    $redacted = preg_replace('/--password=[^\\s]+/i', '--password=[redacted]', $command);

    return is_string($redacted) ? $redacted : $command;
}

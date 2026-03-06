#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/GateProcessRunner.php';
require_once __DIR__ . '/lib/ZeroSurpriseReport.php';
require_once __DIR__ . '/lib/ZeroSurpriseCredentials.php';

use ReleaseGate\GateProcessRunner;
use ReleaseGate\ZeroSurpriseCredentials;
use ReleaseGate\ZeroSurpriseReport;

const ZERO_SURPRISE_CANARY_EXIT_SUCCESS = 0;
const ZERO_SURPRISE_CANARY_EXIT_ASSERTION_FAILURE = 1;
const ZERO_SURPRISE_CANARY_EXIT_RUNTIME_ERROR = 2;

$repoRoot = dirname(__DIR__, 2);
$timestamp = gmdate('Ymd\\THis\\Z');
$defaultOutputPath = $repoRoot . '/storage/logs/release-gate/zero-surprise-live-canary-' . $timestamp . '.json';
$defaultProfile = 'school-day-default';

$report = null;
$exitCode = ZERO_SURPRISE_CANARY_EXIT_RUNTIME_ERROR;
$config = [];

try {
    $config = parseCliOptions($defaultOutputPath, $defaultProfile);

    if (($config['help'] ?? false) === true) {
        printUsage();
        exit(ZERO_SURPRISE_CANARY_EXIT_SUCCESS);
    }

    $credentials = ZeroSurpriseCredentials::resolve($config['credentials_file'], $config['profile_name']);

    $bookingReportPath = 'storage/logs/release-gate/zero-surprise-live-canary-booking-' . $timestamp . '.json';
    $dashboardReportPath = 'storage/logs/release-gate/zero-surprise-live-canary-dashboard-' . $timestamp . '.json';

    $report = new ZeroSurpriseReport(
        $config['release_id'],
        'live-canary',
        [
            'credentials_file' => $config['credentials_file'],
            'profile_name' => $credentials['profile_name'],
            'profile_window' => $credentials['profile_window'],
            'base_url' => $credentials['base_url'],
            'index_page' => $credentials['index_page'],
            'start_date' => $credentials['start_date'],
            'end_date' => $credentials['end_date'],
            'booking_search_days' => $credentials['booking_search_days'],
            'retry_count' => $credentials['retry_count'],
            'max_pdf_duration_ms' => $credentials['max_pdf_duration_ms'],
            'timezone' => $credentials['timezone'],
            'pdf_health_url' => $credentials['pdf_health_url'],
            'timeout_seconds' => $config['timeout_seconds'],
            'output_json' => $config['output_json'],
        ],
        $config['output_json'],
        'postdeploy_canary',
    );

    $runId = buildCanaryRunId($config['release_id']);
    $deadlineAt = microtime(true) + $config['timeout_seconds'];

    $bookingReport = null;
    $dashboardReport = null;

    $bookingStep = runCanaryStep(
        'booking_write_replay',
        [
            'php',
            'scripts/ci/booking_write_contract_smoke.php',
            '--base-url=' . $credentials['base_url'],
            '--index-page=' . $credentials['index_page'],
            '--username=' . $credentials['username'],
            '--password=' . $credentials['password'],
            '--booking-search-days=' . $credentials['booking_search_days'],
            '--retry-count=' . $credentials['retry_count'],
            '--timezone=' . $credentials['timezone'],
            '--run-id=' . $runId,
            '--output-json=' . $bookingReportPath,
        ],
        $repoRoot,
        $deadlineAt,
    );

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

    $bookingReport = readJsonFile($repoRoot . '/' . $bookingReportPath);

    if ($bookingStep['status'] === ZeroSurpriseReport::STATUS_PASS) {
        $dashboardCommand = [
            'php',
            'scripts/release-gate/dashboard_release_gate.php',
            '--base-url=' . $credentials['base_url'],
            '--index-page=' . $credentials['index_page'],
            '--username=' . $credentials['username'],
            '--password=' . $credentials['password'],
            '--start-date=' . $credentials['start_date'],
            '--end-date=' . $credentials['end_date'],
            '--max-pdf-duration-ms=' . $credentials['max_pdf_duration_ms'],
            '--output-json=' . $dashboardReportPath,
        ];

        if ($credentials['pdf_health_url'] !== null) {
            $dashboardCommand[] = '--pdf-health-url=' . $credentials['pdf_health_url'];
        }

        $dashboardStep = runCanaryStep('dashboard_replay', $dashboardCommand, $repoRoot, $deadlineAt);

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

        $dashboardReport = readJsonFile($repoRoot . '/' . $dashboardReportPath);

        if ($dashboardStep['status'] === ZeroSurpriseReport::STATUS_FAIL) {
            $report->setFailure(
                'dashboard_replay failed.',
                RuntimeException::class,
                classifyFailureFromExitCode($dashboardStep['exit_code']),
            );
        }
    } else {
        $report->setFailure(
            'booking_write_replay failed, dashboard replay was skipped.',
            RuntimeException::class,
            classifyFailureFromExitCode($bookingStep['exit_code']),
        );
    }

    foreach (collectInvariants($bookingReport, $dashboardReport) as $name => $invariant) {
        $report->addInvariant($name, $invariant['status'], $invariant['details']);
    }

    $exitCode = $report->determineExitCode();
} catch (Throwable $exception) {
    if ($report === null) {
        $report = new ZeroSurpriseReport(
            (string) ($config['release_id'] ?? 'unknown-release'),
            'live-canary',
            [
                'credentials_file' => $config['credentials_file'] ?? null,
                'output_json' => $config['output_json'] ?? $defaultOutputPath,
            ],
            (string) ($config['output_json'] ?? $defaultOutputPath),
            'postdeploy_canary',
        );
    }

    $report->setFailure($exception->getMessage(), get_class($exception), 'runtime_error');
    $exitCode = ZERO_SURPRISE_CANARY_EXIT_RUNTIME_ERROR;
}

if ($report === null) {
    fwrite(STDERR, '[FAIL] Zero-surprise live canary failed before report initialization.' . PHP_EOL);
    exit(ZERO_SURPRISE_CANARY_EXIT_RUNTIME_ERROR);
}

try {
    $path = $report->write();
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] Could not write zero-surprise live canary report: ' . $exception->getMessage() . PHP_EOL);
    exit(ZERO_SURPRISE_CANARY_EXIT_RUNTIME_ERROR);
}

$exitCode = $report->determineExitCode();

if ($exitCode === ZERO_SURPRISE_CANARY_EXIT_SUCCESS) {
    fwrite(STDOUT, '[PASS] Zero-surprise live canary passed -> ' . $path . PHP_EOL);
} else {
    fwrite(STDERR, '[FAIL] Zero-surprise live canary failed (exit ' . $exitCode . ') -> ' . $path . PHP_EOL);
}

exit($exitCode);

/**
 * @return array<string, mixed>
 */
function parseCliOptions(string $defaultOutputPath, string $defaultProfile): array
{
    $options = getopt('', [
        'help',
        'release-id:',
        'credentials-file:',
        'profile::',
        'timeout-seconds:',
        'output-json::',
    ]);

    if (!is_array($options)) {
        throw new InvalidArgumentException('Failed to parse CLI options.');
    }

    if (array_key_exists('help', $options)) {
        return [
            'help' => true,
            'output_json' => $defaultOutputPath,
        ];
    }

    $releaseId = trim(getRequiredOption($options, 'release-id'));
    $credentialsFile = trim(getRequiredOption($options, 'credentials-file'));
    $profileName = trim((string) getOptionalOption($options, 'profile', $defaultProfile));
    $timeoutSeconds = parsePositiveInt(getRequiredOption($options, 'timeout-seconds'), 'timeout-seconds');
    $outputJson = trim((string) getOptionalOption($options, 'output-json', $defaultOutputPath));

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $releaseId)) {
        throw new InvalidArgumentException('Option --release-id contains unsupported characters.');
    }

    if (!is_file($credentialsFile) || !is_readable($credentialsFile)) {
        throw new InvalidArgumentException('Option --credentials-file is not readable: ' . $credentialsFile);
    }

    if ($profileName === '') {
        throw new InvalidArgumentException('Option --profile must not be empty.');
    }

    if ($outputJson === '') {
        throw new InvalidArgumentException('Option --output-json must not be empty.');
    }

    return [
        'help' => false,
        'release_id' => $releaseId,
        'credentials_file' => $credentialsFile,
        'profile_name' => $profileName,
        'timeout_seconds' => $timeoutSeconds,
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
function getOptionalOption(array $options, string $name, mixed $default): mixed
{
    if (!array_key_exists($name, $options)) {
        return $default;
    }

    $value = $options[$name];

    return is_array($value) ? end($value) : $value;
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
        throw new InvalidArgumentException($name . ' must be a non-negative integer.');
    }

    if ($value < 0) {
        throw new InvalidArgumentException($name . ' must be a non-negative integer.');
    }

    return $value;
}

function validateDate(string $value, string $name): void
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($name . ' must use format YYYY-MM-DD.');
    }
}

/**
 * @param array<int, string> $command
 * @return array<string, mixed>
 */
function runCanaryStep(string $stepName, array $command, string $repoRoot, float $deadlineAt): array
{
    $remainingSeconds = (int) ceil($deadlineAt - microtime(true));

    if ($remainingSeconds <= 0) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_CANARY_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
            'timed_out' => true,
            'command' => redactCommandSecrets(implode(' ', $command)),
            'stdout_tail' => '',
            'stderr_tail' => 'Global canary timeout exceeded before step "' . $stepName . '".',
        ];
    }

    $result = GateProcessRunner::run($command, $repoRoot, null, $remainingSeconds);

    $exitCode = (int) ($result['exit_code'] ?? 1);
    $timedOut = (bool) ($result['timed_out'] ?? false);
    $status = $exitCode === 0 && !$timedOut ? ZeroSurpriseReport::STATUS_PASS : ZeroSurpriseReport::STATUS_FAIL;

    $normalizedExitCode =
        $status === ZeroSurpriseReport::STATUS_PASS
            ? ZERO_SURPRISE_CANARY_EXIT_SUCCESS
            : ($exitCode === ZERO_SURPRISE_CANARY_EXIT_ASSERTION_FAILURE
                ? ZERO_SURPRISE_CANARY_EXIT_ASSERTION_FAILURE
                : ZERO_SURPRISE_CANARY_EXIT_RUNTIME_ERROR);

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

function classifyFailureFromExitCode(int $exitCode): string
{
    return $exitCode === ZERO_SURPRISE_CANARY_EXIT_ASSERTION_FAILURE ? 'assertion_failure' : 'runtime_error';
}

function buildCanaryRunId(string $releaseId): string
{
    $hash = substr(hash('sha256', $releaseId . '|' . microtime(true) . '|' . random_int(1000, 9999)), 0, 8);

    return 'zslc-' . gmdate('YmdHis') . '-' . $hash;
}

function readJsonFile(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);

    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

function tailText(string $text, int $maxChars): string
{
    if ($maxChars <= 0 || $text === '') {
        return '';
    }

    if (strlen($text) <= $maxChars) {
        return $text;
    }

    return substr($text, -$maxChars);
}

function redactCommandSecrets(string $command): string
{
    $patterns = ['/(--password=)([^\s]+)/i', '/(--password\s+)([^\s]+)/i'];

    foreach ($patterns as $pattern) {
        $command = preg_replace($pattern, '$1<redacted>', $command) ?? $command;
    }

    return $command;
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
    $allowlistedChecks = [];
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

function printUsage(): void
{
    $usage = <<<'TXT'
    Usage:
      php scripts/release-gate/zero_surprise_live_canary.php \
        --release-id=ea_YYYYMMDD_HHMM \
        --credentials-file=/absolute/path/zero-surprise-canary.ini \
        --timeout-seconds=300 [--profile=school-day-default] [--output-json=/absolute/path/report.json]

    Required:
      --release-id                 Release identifier used for correlation
      --credentials-file           INI file with base_url/index_page/username/password
      --timeout-seconds            Global timeout budget in seconds (>0)

    Optional:
      --profile                    Named digital-twin profile (default: school-day-default)
      --output-json                Report output path
      --help                       Show this usage text
    TXT;

    fwrite(STDOUT, $usage . PHP_EOL);
}


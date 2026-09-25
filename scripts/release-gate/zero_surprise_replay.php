#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/GateProcessRunner.php';
require_once __DIR__ . '/lib/ZeroSurpriseReport.php';
require_once __DIR__ . '/lib/ZeroSurpriseCredentials.php';
require_once __DIR__ . '/lib/ZeroSurpriseImageCleanup.php';

use ReleaseGate\GateProcessRunner;
use ReleaseGate\ZeroSurpriseCredentials;
use ReleaseGate\ZeroSurpriseImageCleanup;
use ReleaseGate\ZeroSurpriseReport;

const ZERO_SURPRISE_EXIT_SUCCESS = 0;
const ZERO_SURPRISE_EXIT_ASSERTION_FAILURE = 1;
const ZERO_SURPRISE_EXIT_RUNTIME_ERROR = 2;

$repoRoot = dirname(__DIR__, 2);
$timestamp = gmdate('Ymd\THis\Z');
$defaultOutputPath = $repoRoot . '/storage/logs/release-gate/zero-surprise-' . $timestamp . '.json';
$defaultProfile = 'school-day-default';

if (!defined('ZERO_SURPRISE_REPLAY_TEST_MODE')) {
    $report = null;
    $exitCode = ZERO_SURPRISE_EXIT_RUNTIME_ERROR;
    $composeProject = null;
    $config = [];

    try {
        $config = parseCliOptions($defaultOutputPath, $defaultProfile);

        if (($config['help'] ?? false) === true) {
            printUsage();
            exit(ZERO_SURPRISE_EXIT_SUCCESS);
        }

        $composeProject = buildComposeProjectName($config['release_id']);
        $config['booking_start_date'] = resolveBoundBookingStartDate($config);

        $initialBookingReportPath = 'storage/logs/release-gate/zero-surprise-booking-initial-' . $timestamp . '.json';
        $hypotheticalBookingReportPath =
            'storage/logs/release-gate/zero-surprise-booking-hypothetical-unblocked-' . $timestamp . '.json';
        $dashboardReportPath = 'storage/logs/release-gate/zero-surprise-dashboard-' . $timestamp . '.json';

        $report = new ZeroSurpriseReport(
            $config['release_id'],
            $composeProject,
            [
                'dump_file' => $config['dump_file'],
                'credentials_file' => $config['credentials_file'],
                'profile_name' => $config['profile_name'],
                'profile_window' => $config['profile_window'],
                'base_url' => $config['base_url'],
                'index_page' => $config['index_page'],
                'start_date' => $config['start_date'],
                'end_date' => $config['end_date'],
                'booking_search_days' => $config['booking_search_days'],
                'booking_start_date' => $config['booking_start_date'],
                'retry_count' => $config['retry_count'],
                'max_pdf_duration_ms' => $config['max_pdf_duration_ms'],
                'timezone' => $config['timezone'],
                'output_json' => $config['output_json'],
            ],
            $config['output_json'],
        );

        $composePrefix = composePrefix($composeProject);

        $restoreStep = runRestoreDumpStep($repoRoot, $composePrefix, $config);
        $report->addStep(
            'restore_dump',
            $restoreStep['status'],
            $restoreStep['exit_code'],
            $restoreStep['duration_ms'],
            [
                'details' => $restoreStep['details'],
            ],
        );
        $baselineBlockers = $restoreStep['baseline_blockers'] ?? null;

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
                '--password-stdin',
                '--booking-search-days=' . $config['booking_search_days'],
                '--booking-start-date=' . $config['booking_start_date'],
                '--retry-count=' . $config['retry_count'],
                '--run-id=' . buildRunId($config['release_id']),
                '--timezone=' . $config['timezone'],
                '--output-json=' . $initialBookingReportPath,
            ]);

            $bookingStep = runExternalStep($bookingCommand, $repoRoot, 900, $config['password']);
            $bookingReport = readJsonFile($repoRoot . '/' . $initialBookingReportPath);

            if ($bookingStep['status'] === ZeroSurpriseReport::STATUS_PASS) {
                if ($baselineBlockers !== null && $baselineBlockers !== []) {
                    $report->addStep(
                        'isolated_full_window_blocker_consistency',
                        ZeroSurpriseReport::STATUS_FAIL,
                        ZERO_SURPRISE_EXIT_ASSERTION_FAILURE,
                        0.0,
                        ['category' => 'baseline_blocker_with_booking_success'],
                    );
                    $report->setFailure(
                        'A full-window blocker was present in the restored baseline but booking unexpectedly succeeded.',
                        RuntimeException::class,
                        'assertion_failure',
                    );
                }
                $report->addStep(
                    'booking_write_replay',
                    $bookingStep['status'],
                    $bookingStep['exit_code'],
                    $bookingStep['duration_ms'],
                    [
                        'child_report' => $initialBookingReportPath,
                        'command' => $bookingStep['command'],
                        'timed_out' => $bookingStep['timed_out'],
                    ],
                );
            } elseif (isNoLiveSlotFailure($bookingReport, $config, $bookingStep)) {
                $coverage = probeNoSlotBlockedWindow($composePrefix, $repoRoot, $config);
                $report->addStep(
                    'isolated_no_slot_coverage',
                    $coverage['status'],
                    $coverage['exit_code'],
                    $coverage['duration_ms'],
                    [
                        'category' => $coverage['category'],
                        'window_days' => $config['booking_search_days'],
                        'source' => 'isolated_restored_database',
                    ],
                );

                if ($coverage['category'] !== 'full' || $coverage['status'] !== ZeroSurpriseReport::STATUS_PASS) {
                    $report->setFailure(
                        'Hypothetical unblocked clone requires a verified full blocked-period window.',
                        RuntimeException::class,
                        $coverage['category'] === 'unknown' ? 'runtime_error' : 'assertion_failure',
                    );
                } else {
                    $hypotheticalReady = false;
                    $baselineMatch = compareCurrentBlockedPeriodSnapshot(
                        $composePrefix,
                        $repoRoot,
                        $config,
                        $baselineBlockers,
                    );
                    $report->addStep(
                        'isolated_full_window_blocker_baseline_match',
                        $baselineMatch['status'],
                        $baselineMatch['exit_code'],
                        $baselineMatch['duration_ms'],
                        ['category' => $baselineMatch['category']],
                    );
                    if ($baselineMatch['status'] !== ZeroSurpriseReport::STATUS_PASS) {
                        $report->setFailure(
                            'Restored blocked-period baseline changed before isolated clone deletion.',
                            RuntimeException::class,
                            $baselineMatch['exit_code'] === ZERO_SURPRISE_EXIT_ASSERTION_FAILURE
                                ? 'assertion_failure'
                                : 'runtime_error',
                        );
                    }
                    $removal =
                        $baselineMatch['status'] === ZeroSurpriseReport::STATUS_PASS
                            ? removeFullWindowBlockedPeriods($composePrefix, $repoRoot, $config)
                            : [
                                'status' => ZeroSurpriseReport::STATUS_FAIL,
                                'exit_code' => ZERO_SURPRISE_EXIT_ASSERTION_FAILURE,
                                'duration_ms' => 0.0,
                                'affected_count' => 0,
                            ];
                    $report->addStep(
                        'isolated_full_window_blocker_removal',
                        $removal['status'],
                        $removal['exit_code'],
                        $removal['duration_ms'],
                        [
                            'source' => 'isolated_restored_database',
                            'category' =>
                                $removal['status'] === ZeroSurpriseReport::STATUS_PASS
                                    ? 'full_window_rows_removed'
                                    : 'full_window_rows_not_removed',
                        ],
                    );
                    if ($removal['status'] !== ZeroSurpriseReport::STATUS_PASS) {
                        $report->setFailure(
                            'Full-window blocked-period removal failed closed.',
                            RuntimeException::class,
                            $removal['exit_code'] === ZERO_SURPRISE_EXIT_ASSERTION_FAILURE
                                ? 'assertion_failure'
                                : 'runtime_error',
                        );
                    } else {
                        $postcondition = probeNoSlotBlockedWindow($composePrefix, $repoRoot, $config);
                        $report->addStep(
                            'isolated_no_slot_coverage_postcondition',
                            $postcondition['status'],
                            $postcondition['exit_code'],
                            $postcondition['duration_ms'],
                            [
                                'category' => $postcondition['category'],
                                'window_days' => $config['booking_search_days'],
                                'source' => 'isolated_restored_database',
                            ],
                        );
                        if ($postcondition['category'] === 'unknown' || $postcondition['category'] === 'full') {
                            $report->setFailure(
                                'Full-window blocked-period removal postcondition failed closed.',
                                RuntimeException::class,
                                $postcondition['category'] === 'unknown' ? 'runtime_error' : 'assertion_failure',
                            );
                        } elseif ($postcondition['status'] === ZeroSurpriseReport::STATUS_PASS) {
                            $hypotheticalReady = true;
                        }
                    }
                }

                $hypotheticalBookingCommand = composeCommand($composePrefix, [
                    'exec',
                    '-T',
                    'php-fpm',
                    'php',
                    'scripts/ci/booking_write_contract_smoke.php',
                    '--base-url=' . $config['base_url'],
                    '--index-page=' . $config['index_page'],
                    '--booking-search-days=' . $config['booking_search_days'],
                    '--booking-start-date=' . $config['booking_start_date'],
                    '--retry-count=' . $config['retry_count'],
                    '--timezone=' . $config['timezone'],
                    '--run-id=' . buildRunId($config['release_id']) . '-hypothetical-unblocked',
                    '--username=' . $config['username'],
                    '--password-stdin',
                    '--output-json=' . $hypotheticalBookingReportPath,
                ]);
                if (
                    $coverage['category'] === 'full' &&
                    $coverage['status'] === ZeroSurpriseReport::STATUS_PASS &&
                    ($hypotheticalReady ?? false)
                ) {
                    $hypotheticalBookingStep = runExternalStep(
                        $hypotheticalBookingCommand,
                        $repoRoot,
                        900,
                        $config['password'],
                    );
                    $bookingReport = readJsonFile($repoRoot . '/' . $hypotheticalBookingReportPath);
                    $report->addStep(
                        'booking_write_replay',
                        $hypotheticalBookingStep['status'],
                        $hypotheticalBookingStep['exit_code'],
                        $hypotheticalBookingStep['duration_ms'],
                        [
                            'child_report' => $hypotheticalBookingReportPath,
                            'command' => $hypotheticalBookingStep['command'],
                            'timed_out' => $hypotheticalBookingStep['timed_out'],
                            'source' => 'hypothetical_unblocked_isolated_clone',
                        ],
                    );
                    if ($hypotheticalBookingStep['status'] !== ZeroSurpriseReport::STATUS_PASS) {
                        $report->setFailure(
                            'Hypothetical unblocked isolated booking replay failed.',
                            RuntimeException::class,
                            $hypotheticalBookingStep['exit_code'] === ZERO_SURPRISE_EXIT_ASSERTION_FAILURE
                                ? 'assertion_failure'
                                : 'runtime_error',
                        );
                    }
                }
            } else {
                $report->addStep(
                    'booking_write_replay',
                    $bookingStep['status'],
                    $bookingStep['exit_code'],
                    $bookingStep['duration_ms'],
                    [
                        'child_report' => $initialBookingReportPath,
                        'command' => $bookingStep['command'],
                        'timed_out' => $bookingStep['timed_out'],
                    ],
                );
            }

            $dashboardCommand = composeCommand($composePrefix, [
                'exec',
                '-T',
                'php-fpm',
                'php',
                'scripts/release-gate/dashboard_release_gate.php',
                '--base-url=' . $config['base_url'],
                '--index-page=' . $config['index_page'],
                '--username=' . $config['username'],
                '--password-stdin',
                '--start-date=' . $config['start_date'],
                '--end-date=' . $config['end_date'],
                '--max-pdf-duration-ms=' . $config['max_pdf_duration_ms'],
                '--output-json=' . $dashboardReportPath,
            ]);

            $dashboardStep = runExternalStep($dashboardCommand, $repoRoot, 900, $config['password']);
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
        $teardown = runReplayTeardown($repoRoot, $composeProject, $report);
        if ($teardown['runtime_failed']) {
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
}

/**
 * @return array<string, mixed>
 */
function parseCliOptions(string $defaultOutputPath, string $defaultProfile): array
{
    $options = getopt('', [
        'help',
        'release-id:',
        'dump-file:',
        'credentials-file::',
        'profile::',
        'base-url::',
        'index-page::',
        'username::',
        'password::',
        'start-date::',
        'end-date::',
        'booking-search-days::',
        'retry-count::',
        'max-pdf-duration-ms::',
        'timezone::',
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
    $dumpFile = trim(getRequiredOption($options, 'dump-file'));
    $credentialsFile = trim((string) getOptionalOption($options, 'credentials-file', ''));
    $profileName = trim((string) getOptionalOption($options, 'profile', $defaultProfile));
    $outputJson = trim((string) getOptionalOption($options, 'output-json', $defaultOutputPath));
    if ($outputJson === '') {
        throw new InvalidArgumentException('Option --output-json must not be empty.');
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $releaseId)) {
        throw new InvalidArgumentException('Option --release-id contains unsupported characters.');
    }

    if (!is_file($dumpFile) || !is_readable($dumpFile)) {
        throw new InvalidArgumentException('Option --dump-file is not readable: ' . $dumpFile);
    }

    if (!str_ends_with($dumpFile, '.sql') && !str_ends_with($dumpFile, '.sql.gz')) {
        throw new InvalidArgumentException('Option --dump-file must end with .sql or .sql.gz.');
    }

    if ($profileName === '') {
        throw new InvalidArgumentException('Option --profile must not be empty.');
    }

    $cliOverrides = [];
    appendStringOverride($cliOverrides, $options, 'base-url', 'base_url');
    appendAllowEmptyOverride($cliOverrides, $options, 'index-page', 'index_page');
    appendStringOverride($cliOverrides, $options, 'username', 'username');
    appendStringOverride($cliOverrides, $options, 'password', 'password', false);
    appendStringOverride($cliOverrides, $options, 'start-date', 'start_date');
    appendStringOverride($cliOverrides, $options, 'end-date', 'end_date');
    appendNumericOverride($cliOverrides, $options, 'booking-search-days', 'booking_search_days');
    appendNumericOverride($cliOverrides, $options, 'retry-count', 'retry_count');
    appendNumericOverride($cliOverrides, $options, 'max-pdf-duration-ms', 'max_pdf_duration_ms');
    appendStringOverride($cliOverrides, $options, 'timezone', 'timezone');

    $resolved = ZeroSurpriseCredentials::resolve(
        $credentialsFile !== '' ? $credentialsFile : null,
        $profileName,
        $cliOverrides,
    );

    return [
        'help' => false,
        'release_id' => $releaseId,
        'dump_file' => $dumpFile,
        'credentials_file' => $resolved['credentials_file'],
        'profile_name' => $resolved['profile_name'],
        'profile_window' => $resolved['profile_window'],
        'base_url' => $resolved['base_url'],
        'index_page' => $resolved['index_page'],
        'username' => $resolved['username'],
        'password' => $resolved['password'],
        'start_date' => $resolved['start_date'],
        'end_date' => $resolved['end_date'],
        'booking_search_days' => $resolved['booking_search_days'],
        'retry_count' => $resolved['retry_count'],
        'max_pdf_duration_ms' => $resolved['max_pdf_duration_ms'],
        'timezone' => $resolved['timezone'],
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
    if (is_array($value)) {
        return end($value);
    }

    return $value;
}

/**
 * @param array<string, mixed> $target
 * @param array<string, mixed> $options
 */
function appendStringOverride(
    array &$target,
    array $options,
    string $optionName,
    string $targetKey,
    bool $trim = true,
): void {
    if (!array_key_exists($optionName, $options)) {
        return;
    }

    $value = $options[$optionName];
    $resolved = is_array($value) ? (string) end($value) : (string) $value;
    $target[$targetKey] = $trim ? trim($resolved) : $resolved;
}

/**
 * @param array<string, mixed> $target
 * @param array<string, mixed> $options
 */
function appendAllowEmptyOverride(array &$target, array $options, string $optionName, string $targetKey): void
{
    if (!array_key_exists($optionName, $options)) {
        return;
    }

    $value = $options[$optionName];
    if ($value === false || $value === null) {
        throw new InvalidArgumentException(
            'Option --' . $optionName . ' requires an explicit value (empty allowed as --' . $optionName . '=).',
        );
    }

    $target[$targetKey] = is_array($value) ? (string) end($value) : (string) $value;
}

/**
 * @param array<string, mixed> $target
 * @param array<string, mixed> $options
 */
function appendNumericOverride(array &$target, array $options, string $optionName, string $targetKey): void
{
    if (!array_key_exists($optionName, $options)) {
        return;
    }

    $value = $options[$optionName];
    $target[$targetKey] = is_array($value) ? end($value) : $value;
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
 * @param callable(array<int, string>, string, int): array<string, mixed>|null $runner
 * @return array{runtime_failed:bool}
 */
function runReplayTeardown(
    string $repoRoot,
    string $composeProject,
    ?ZeroSurpriseReport $report,
    ?callable $runner = null,
    ?ZeroSurpriseImageCleanup $imageCleaner = null,
): array {
    $runner ??= static fn(
        array $command,
        string $workingDirectory,
        int $timeoutSeconds,
    ): array => GateProcessRunner::run($command, $workingDirectory, null, $timeoutSeconds);
    $imageCleaner ??= ZeroSurpriseImageCleanup::production();
    try {
        $downResult = $runner(
            composeCommand(composePrefix($composeProject), ['down', '-v', '--remove-orphans']),
            $repoRoot,
            180,
        );
    } catch (Throwable) {
        $downResult = [
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
            'timed_out' => false,
        ];
    }
    $downExitCode = is_array($downResult) ? (int) ($downResult['exit_code'] ?? 1) : 1;
    $downTimedOut = is_array($downResult) && (bool) ($downResult['timed_out'] ?? false);
    $downPassed = $downExitCode === 0 && !$downTimedOut;

    if ($report !== null) {
        $report->addStep(
            'compose_cleanup',
            $downPassed ? ZeroSurpriseReport::STATUS_PASS : ZeroSurpriseReport::STATUS_FAIL,
            $downPassed ? ZERO_SURPRISE_EXIT_SUCCESS : ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            is_array($downResult) ? (float) ($downResult['duration_ms'] ?? 0.0) : 0.0,
            [
                'timed_out' => $downTimedOut,
            ],
        );
    }

    $imageCleanup = $imageCleaner->cleanup($composeProject, $repoRoot);
    if ($report !== null) {
        $report->addStep(
            'image_cleanup',
            $imageCleanup['status'],
            $imageCleanup['exit_code'],
            $imageCleanup['duration_ms'],
            [
                'details' => $imageCleanup['details'],
            ],
        );
    }

    $imagePassed = $imageCleanup['status'] === ZeroSurpriseReport::STATUS_PASS;
    $runtimeFailed = !$downPassed || !$imagePassed;

    if ($runtimeFailed && $report !== null) {
        $report->setFailure(
            !$downPassed
                ? 'Zero-surprise compose cleanup failed closed.'
                : 'Zero-surprise image cleanup failed closed.',
            RuntimeException::class,
            'runtime_error',
        );
    }

    return [
        'runtime_failed' => $runtimeFailed,
    ];
}

/**
 * @param array<int, string> $command
 * @return array<string, mixed>
 */
function runExternalStep(array $command, string $repoRoot, int $timeoutSeconds, ?string $stdinPayload = null): array
{
    $result = GateProcessRunner::run($command, $repoRoot, null, $timeoutSeconds, $stdinPayload);

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
 * Prove the no-slot condition from the restored database without exposing
 * identifiers. The query accepts only a single row covering the full window;
 * partial coverage and absence remain hard failures.
 *
 * @param array<int,string> $composePrefix
 * @param array<string,mixed> $config
 * @return array{status:string,exit_code:int,duration_ms:float,category:string}
 */
function probeNoSlotBlockedWindow(array $composePrefix, string $repoRoot, array $config): array
{
    $days = (int) ($config['booking_search_days'] ?? 0);
    $startDate = trim((string) ($config['booking_start_date'] ?? ''));
    if ($days <= 0 || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $startDate)) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
            'category' => 'unknown',
        ];
    }

    $start = new DateTimeImmutable(
        $startDate . ' 00:00:00',
        new DateTimeZone((string) ($config['timezone'] ?? 'Europe/Berlin')),
    );
    $end = $start->modify('+' . $days . ' days');
    $sql = buildBlockedWindowCoverageSql($start, $end);

    try {
        $result = GateProcessRunner::run(
            composeCommand($composePrefix, [
                'exec',
                '-T',
                'mysql',
                'mysql',
                '-N',
                '-B',
                '-uroot',
                '-psecret',
                'easyappointments',
                '-e',
                $sql,
            ]),
            $repoRoot,
            null,
            120,
        );
    } catch (Throwable) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
            'category' => 'unknown',
        ];
    }

    $category = classifyBlockedWindowCoverage(
        (string) ($result['stdout'] ?? ''),
        (int) ($result['exit_code'] ?? 1),
        (bool) ($result['timed_out'] ?? false),
    );
    $pass = in_array($category, ['full', 'partial', 'absent'], true);

    return [
        'status' => $pass ? ZeroSurpriseReport::STATUS_PASS : ZeroSurpriseReport::STATUS_FAIL,
        'exit_code' =>
            $category === 'unknown'
                ? ZERO_SURPRISE_EXIT_RUNTIME_ERROR
                : ($pass
                    ? ZERO_SURPRISE_EXIT_SUCCESS
                    : ZERO_SURPRISE_EXIT_ASSERTION_FAILURE),
        'duration_ms' => (float) ($result['duration_ms'] ?? 0.0),
        'category' => $category,
    ];
}

function buildBlockedWindowCoverageSql(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    $startSql = mysqlQuote($start->format('Y-m-d H:i:s'));
    $endSql = mysqlQuote($end->format('Y-m-d H:i:s'));

    return 'SELECT CASE ' .
        "WHEN EXISTS (SELECT 1 FROM ea_blocked_periods WHERE start_datetime <= {$startSql} AND end_datetime >= {$endSql}) THEN 'full' " .
        "WHEN EXISTS (SELECT 1 FROM ea_blocked_periods WHERE end_datetime > {$startSql} AND start_datetime < {$endSql}) THEN 'partial' " .
        "ELSE 'absent' END";
}

/**
 * Remove only rows covering the complete window from the isolated clone.
 * The command returns a fixed count pair and never exposes row identities.
 *
 * @param array<int,string> $composePrefix
 * @param array<string,mixed> $config
 * @return array{status:string,exit_code:int,duration_ms:float,affected_count:int}
 */
function removeFullWindowBlockedPeriods(array $composePrefix, string $repoRoot, array $config): array
{
    $days = (int) ($config['booking_search_days'] ?? 0);
    $startDate = trim((string) ($config['booking_start_date'] ?? ''));
    if ($days <= 0 || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $startDate)) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
            'affected_count' => 0,
        ];
    }
    $start = new DateTimeImmutable(
        $startDate . ' 00:00:00',
        new DateTimeZone((string) ($config['timezone'] ?? 'Europe/Berlin')),
    );
    $end = $start->modify('+' . $days . ' days');
    $startSql = mysqlQuote($start->format('Y-m-d H:i:s'));
    $endSql = mysqlQuote($end->format('Y-m-d H:i:s'));
    $where = "start_datetime <= {$startSql} AND end_datetime >= {$endSql}";
    $sql =
        "SELECT CONCAT('full_count=', COUNT(*)) FROM ea_blocked_periods WHERE {$where}; " .
        "START TRANSACTION; DELETE FROM ea_blocked_periods WHERE {$where}; " .
        "SELECT CONCAT('deleted_count=', ROW_COUNT()); COMMIT;";

    try {
        $result = GateProcessRunner::run(
            composeCommand($composePrefix, [
                'exec',
                '-T',
                'mysql',
                'mysql',
                '-N',
                '-B',
                '-uroot',
                '-psecret',
                'easyappointments',
                '-e',
                $sql,
            ]),
            $repoRoot,
            null,
            120,
        );
    } catch (Throwable) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
            'affected_count' => 0,
        ];
    }
    $counts = parseBlockedWindowRemovalCounts(
        (string) ($result['stdout'] ?? ''),
        (int) ($result['exit_code'] ?? 1),
        (bool) ($result['timed_out'] ?? false),
    );
    $valid = validateBlockedWindowRemovalCounts($counts);

    return [
        'status' => $valid ? ZeroSurpriseReport::STATUS_PASS : ZeroSurpriseReport::STATUS_FAIL,
        'exit_code' => $valid
            ? ZERO_SURPRISE_EXIT_SUCCESS
            : ($counts === null
                ? ZERO_SURPRISE_EXIT_RUNTIME_ERROR
                : ZERO_SURPRISE_EXIT_ASSERTION_FAILURE),
        'duration_ms' => (float) ($result['duration_ms'] ?? 0.0),
        'affected_count' => $counts['deleted_count'] ?? 0,
    ];
}

/**
 * Bind one booking-window start for the whole restored replay. This prevents
 * a midnight or DST boundary from making the SQL probe and child HTTP runs
 * inspect different windows.
 */
function resolveBoundBookingStartDate(array $config): string
{
    $timezone = new DateTimeZone((string) ($config['timezone'] ?? 'Europe/Berlin'));

    return (new DateTimeImmutable('tomorrow', $timezone))->setTime(0, 0)->format('Y-m-d');
}

/**
 * Read full-window blocker identities into process memory only. The caller
 * must never add the returned IDs or timestamps to a report.
 *
 * @return array{status:string,category:string,exit_code:int,duration_ms:float,snapshot:array<int,array{id:int,start:string,end:string}>|null}
 */
function snapshotFullWindowBlockedPeriods(array $composePrefix, string $repoRoot, array $config): array
{
    $days = (int) ($config['booking_search_days'] ?? 0);
    $startDate = trim((string) ($config['booking_start_date'] ?? ''));
    if ($days <= 0 || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $startDate)) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'category' => 'baseline_unavailable',
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
            'snapshot' => null,
        ];
    }
    $timezone = new DateTimeZone((string) ($config['timezone'] ?? 'Europe/Berlin'));
    $start = new DateTimeImmutable($startDate . ' 00:00:00', $timezone);
    $end = $start->modify('+' . $days . ' days');
    $sql = buildFullWindowBlockedPeriodSnapshotSql($start, $end);

    try {
        $result = GateProcessRunner::run(
            composeCommand($composePrefix, [
                'exec',
                '-T',
                'mysql',
                'mysql',
                '-N',
                '-B',
                '-uroot',
                '-psecret',
                'easyappointments',
                '-e',
                $sql,
            ]),
            $repoRoot,
            null,
            120,
        );
    } catch (Throwable) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'category' => 'baseline_unavailable',
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
            'snapshot' => null,
        ];
    }
    $snapshot = parseFullWindowBlockedPeriodSnapshot(
        (string) ($result['stdout'] ?? ''),
        (int) ($result['exit_code'] ?? 1),
        (bool) ($result['timed_out'] ?? false),
    );
    if ($snapshot === null) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'category' => 'baseline_unavailable',
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => (float) ($result['duration_ms'] ?? 0.0),
            'snapshot' => null,
        ];
    }

    return [
        'status' => ZeroSurpriseReport::STATUS_PASS,
        'category' => $snapshot === [] ? 'baseline_absent' : 'baseline_full',
        'exit_code' => ZERO_SURPRISE_EXIT_SUCCESS,
        'duration_ms' => (float) ($result['duration_ms'] ?? 0.0),
        'snapshot' => $snapshot,
    ];
}

function buildFullWindowBlockedPeriodSnapshotSql(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    return 'SELECT id, start_datetime, end_datetime FROM ea_blocked_periods WHERE start_datetime <= ' .
        mysqlQuote($start->format('Y-m-d H:i:s')) .
        ' AND end_datetime >= ' .
        mysqlQuote($end->format('Y-m-d H:i:s')) .
        ' ORDER BY id, start_datetime, end_datetime';
}

/** @return array<int,array{id:int,start:string,end:string}>|null */
function parseFullWindowBlockedPeriodSnapshot(string $output, int $exitCode, bool $timedOut): ?array
{
    if ($exitCode !== 0 || $timedOut) {
        return null;
    }
    $output = trim($output);
    if ($output === '') {
        return [];
    }
    $rows = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $parts = explode("\t", trim($line));
        if (
            count($parts) !== 3 ||
            !ctype_digit($parts[0]) ||
            (int) $parts[0] <= 0 ||
            !isValidSqlDateTime($parts[1]) ||
            !isValidSqlDateTime($parts[2]) ||
            $parts[1] > $parts[2]
        ) {
            return null;
        }
        $rows[] = ['id' => (int) $parts[0], 'start' => $parts[1], 'end' => $parts[2]];
    }
    return $rows;
}

function isValidSqlDateTime(string $value): bool
{
    if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) !== 1) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

/** @param array<int,array{id:int,start:string,end:string}>|null $baseline */
function compareCurrentBlockedPeriodSnapshot(
    array $composePrefix,
    string $repoRoot,
    array $config,
    ?array $baseline,
): array {
    if ($baseline === null) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'category' => 'baseline_unavailable',
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => 0.0,
        ];
    }
    $current = snapshotFullWindowBlockedPeriods($composePrefix, $repoRoot, $config);
    if ($current['status'] !== ZeroSurpriseReport::STATUS_PASS) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'category' => 'baseline_unavailable',
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => $current['duration_ms'],
        ];
    }
    $category = classifyBlockedPeriodSnapshotComparison($baseline, $current['snapshot']);
    $matches = $category === 'baseline_match';
    return [
        'status' => $matches ? ZeroSurpriseReport::STATUS_PASS : ZeroSurpriseReport::STATUS_FAIL,
        'category' => $category,
        'exit_code' => $matches ? ZERO_SURPRISE_EXIT_SUCCESS : ZERO_SURPRISE_EXIT_ASSERTION_FAILURE,
        'duration_ms' => $current['duration_ms'],
    ];
}

/** @param array<int,array{id:int,start:string,end:string}>|null $baseline @param array<int,array{id:int,start:string,end:string}>|null $current */
function classifyBlockedPeriodSnapshotComparison(?array $baseline, ?array $current): string
{
    if ($baseline === null || $current === null) {
        return 'baseline_unavailable';
    }

    return $baseline === $current ? 'baseline_match' : 'baseline_drift';
}

/** @return array{full_count:int,deleted_count:int}|null */
function parseBlockedWindowRemovalCounts(string $output, int $exitCode, bool $timedOut): ?array
{
    if ($exitCode !== 0 || $timedOut) {
        return null;
    }
    $lines = preg_split('/\R/', trim($output));
    if ($lines === false || count($lines) !== 2) {
        return null;
    }
    $matches = [];
    foreach ($lines as $line) {
        if (preg_match('/\A(full_count|deleted_count)=(\d+)\z/', trim($line), $match) !== 1) {
            return null;
        }
        $matches[$match[1]] = (int) $match[2];
    }
    if (!isset($matches['full_count'], $matches['deleted_count'])) {
        return null;
    }

    return ['full_count' => $matches['full_count'], 'deleted_count' => $matches['deleted_count']];
}

/** @param array{full_count:int,deleted_count:int}|null $counts */
function validateBlockedWindowRemovalCounts(?array $counts): bool
{
    return $counts !== null && $counts['full_count'] > 0 && $counts['deleted_count'] === $counts['full_count'];
}

function classifyBlockedWindowCoverage(string $output, int $exitCode, bool $timedOut): string
{
    if ($exitCode !== 0 || $timedOut) {
        return 'unknown';
    }

    $category = trim($output);

    return in_array($category, ['full', 'partial', 'absent'], true) ? $category : 'unknown';
}

/**
 * Select the hypothetical isolated-clone path only for the exact no-slot failure.
 * Other assertion, HTTP, cleanup and runtime failures remain failures.
 *
 * @param array<string, mixed>|null $bookingReport
 * @param array<string, mixed> $config
 * @param array<string, mixed> $initialStep
 */
function isNoLiveSlotFailure(?array $bookingReport, array $config, array $initialStep): bool
{
    if (
        ($initialStep['status'] ?? null) !== ZeroSurpriseReport::STATUS_FAIL ||
        (int) ($initialStep['exit_code'] ?? 0) !== ZERO_SURPRISE_EXIT_ASSERTION_FAILURE ||
        ($initialStep['timed_out'] ?? true) !== false
    ) {
        return false;
    }
    if (!is_array($bookingReport)) {
        return false;
    }

    $failure = $bookingReport['failure'] ?? null;
    if (!is_array($failure) || ($failure['classification'] ?? null) !== 'contract_mismatch') {
        return false;
    }

    $message = trim((string) ($failure['message'] ?? ''));
    if (
        preg_match(
            '/^No booking hours available across (\d+) provider\/service pairs in (\d+)-day window\.$/',
            $message,
            $matches,
        ) !== 1
    ) {
        return false;
    }

    $expectedDays = (int) ($config['booking_search_days'] ?? 0);
    $reportedDays = (int) ($matches[2] ?? 0);
    $cleanup = $bookingReport['state']['cleanup'] ?? null;
    if (
        !is_array($cleanup) ||
        !is_array($cleanup['created'] ?? null) ||
        !is_array($cleanup['deleted'] ?? null) ||
        !is_array($cleanup['failures'] ?? null) ||
        $cleanup['created'] !== [] ||
        $cleanup['deleted'] !== [] ||
        $cleanup['failures'] !== []
    ) {
        return false;
    }

    return $expectedDays > 0 && $reportedDays === $expectedDays && (int) ($matches[1] ?? 0) > 0;
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
    $baselineBlockers = null;

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

    // Capture the exact full-window identities immediately after import. The
    // values stay in memory and are deliberately excluded from report details.
    $baseline = snapshotFullWindowBlockedPeriods($composePrefix, $repoRoot, $config);
    $substeps[] = [
        'name' => 'blocked_period_baseline_snapshot',
        'status' => $baseline['status'],
        'category' => $baseline['category'],
        'exit_code' => $baseline['exit_code'],
        'duration_ms' => $baseline['duration_ms'],
    ];
    if ($baseline['status'] !== ZeroSurpriseReport::STATUS_PASS || !is_array($baseline['snapshot'])) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => $baseline['exit_code'],
            'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
            'baseline_blockers' => null,
            'details' => [
                'failed_substep' => 'blocked_period_baseline_snapshot',
                'substeps' => $substeps,
            ],
        ];
    }
    $baselineBlockers = $baseline['snapshot'];

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

    $gateAccountResult = ensureReplayGateAccount($composePrefix, $repoRoot, $config);
    $substeps[] = summarizeSubstep('ensure_gate_account', $gateAccountResult);

    if ((int) $gateAccountResult['exit_code'] !== 0) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
            'details' => [
                'failed_substep' => 'ensure_gate_account',
                'substeps' => $substeps,
            ],
        ];
    }

    $httpReady = waitForHttpReadiness(
        composeCommand($composePrefix, [
            'exec',
            '-T',
            'php-fpm',
            'curl',
            '-fsS',
            '-o',
            '/dev/null',
            '-w',
            '%{http_code}',
            buildAppReadinessUrl($config),
        ]),
        $repoRoot,
    );
    $substeps[] = $httpReady;

    if ($httpReady['status'] === ZeroSurpriseReport::STATUS_FAIL) {
        return [
            'status' => ZeroSurpriseReport::STATUS_FAIL,
            'exit_code' => ZERO_SURPRISE_EXIT_RUNTIME_ERROR,
            'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
            'details' => [
                'failed_substep' => 'app_http_readiness',
                'substeps' => $substeps,
            ],
        ];
    }

    return [
        'status' => ZeroSurpriseReport::STATUS_PASS,
        'exit_code' => ZERO_SURPRISE_EXIT_SUCCESS,
        'duration_ms' => round((microtime(true) - $stepStartedAt) * 1000, 2),
        'baseline_blockers' => $baselineBlockers,
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
    $mysqlImportShell = $composeShell . ' exec -T mysql mysql -uroot -psecret easyappointments';

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
 * @param array<int, string> $composePrefix
 * @param array<string, mixed> $config
 */
function ensureReplayGateAccount(array $composePrefix, string $repoRoot, array $config): array
{
    $command = composeCommand($composePrefix, [
        'exec',
        '-T',
        'mysql',
        'mysql',
        '-uroot',
        '-psecret',
        'easyappointments',
        '-e',
        buildReplayGateSeedSql($config),
    ]);

    return GateProcessRunner::run($command, $repoRoot, null, 120);
}

/**
 * @param array<string, mixed> $config
 */
function buildReplayGateSeedSql(array $config): string
{
    $username = trim((string) ($config['username'] ?? ''));
    $password = (string) ($config['password'] ?? '');

    if ($username === '' || $password === '') {
        throw new InvalidArgumentException('Replay gate account sync requires non-empty username and password.');
    }

    $releaseId = trim((string) ($config['release_id'] ?? 'zero-surprise'));
    $timezone = trim((string) ($config['timezone'] ?? 'Europe/Berlin'));
    $language = 'german';
    $emailLocalPart = preg_replace('/[^a-z0-9._+-]+/i', '-', $username) ?: 'release-gate';
    $email = sprintf('zs+%s@gate.invalid', strtolower($emailLocalPart));
    $salt = buildReplayGateSalt($releaseId, $username);
    $passwordHash = hashReplayGatePassword($salt, $password);

    $quotedUsername = mysqlQuote($username);
    $quotedPasswordHash = mysqlQuote($passwordHash);
    $quotedSalt = mysqlQuote($salt);
    $quotedTimezone = mysqlQuote($timezone);
    $quotedLanguage = mysqlQuote($language);
    $quotedEmail = mysqlQuote($email);

    return <<<SQL
    SET @zs_now = UTC_TIMESTAMP();
    SET @zs_username = {$quotedUsername};
    SET @zs_password_hash = {$quotedPasswordHash};
    SET @zs_salt = {$quotedSalt};
    SET @zs_timezone = {$quotedTimezone};
    SET @zs_language = {$quotedLanguage};
    SET @zs_email = {$quotedEmail};
    SET @zs_user_id = (SELECT id_users FROM ea_user_settings WHERE username = @zs_username LIMIT 1);
    INSERT INTO ea_users (
        create_datetime,
        update_datetime,
        first_name,
        last_name,
        email,
        id_roles,
        timezone,
        language
    )
    SELECT
        @zs_now,
        @zs_now,
        'Release',
        'Gate',
        @zs_email,
        1,
        @zs_timezone,
        @zs_language
    WHERE @zs_user_id IS NULL;
    SET @zs_user_id = COALESCE(@zs_user_id, LAST_INSERT_ID());
    UPDATE ea_users
    SET
        update_datetime = @zs_now,
        id_roles = 1,
        timezone = COALESCE(NULLIF(timezone, ''), @zs_timezone),
        language = COALESCE(NULLIF(language, ''), @zs_language)
    WHERE id = @zs_user_id;
    INSERT INTO ea_user_settings (
        id_users,
        username,
        password,
        salt,
        notifications,
        calendar_view
    )
    VALUES (
        @zs_user_id,
        @zs_username,
        @zs_password_hash,
        @zs_salt,
        0,
        'default'
    )
    ON DUPLICATE KEY UPDATE
        id_users = VALUES(id_users),
        password = VALUES(password),
        salt = VALUES(salt),
        calendar_view = COALESCE(NULLIF(calendar_view, ''), VALUES(calendar_view));
    SQL;
}

function buildReplayGateSalt(string $releaseId, string $username): string
{
    return substr(hash('sha256', $releaseId . '|' . $username . '|zero-surprise-replay-gate'), 0, 64);
}

function hashReplayGatePassword(string $salt, string $password): string
{
    $half = (int) (strlen($salt) / 2);
    $hash = hash('sha256', substr($salt, 0, $half) . $password . substr($salt, $half));

    for ($i = 0; $i < 100000; $i++) {
        $hash = hash('sha256', $hash);
    }

    return $hash;
}

function mysqlQuote(string $value): string
{
    return "'" .
        str_replace(['\\', "\0", "\n", "\r", "\x1a", "'"], ['\\\\', "\\0", "\\n", "\\r", '\\Z', "\\'"], $value) .
        "'";
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
 * @param array<int, string> $command
 * @return array<string, mixed>
 */
function waitForHttpReadiness(array $command, string $repoRoot, string $name = 'app_http_readiness'): array
{
    $maxAttempts = 60;
    $startedAt = microtime(true);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = GateProcessRunner::run($command, $repoRoot, null, 15);
        $statusCode = trim((string) ($result['stdout'] ?? ''));

        if ((int) ($result['exit_code'] ?? 1) === 0 && $statusCode === '200') {
            return [
                'name' => $name,
                'status' => ZeroSurpriseReport::STATUS_PASS,
                'attempts' => $attempt,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'stdout_tail' => tailText($statusCode, 50),
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
 * @param array<string, mixed> $config
 */
function buildAppReadinessUrl(array $config): string
{
    $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    $indexPage = trim((string) ($config['index_page'] ?? ''), '/');

    if ($indexPage === '') {
        return $baseUrl . '/login';
    }

    return $baseUrl . '/' . $indexPage . '/login';
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

function printUsage(): void
{
    $lines = [
        'Zero-Surprise Restore-Dump Replay (Shadow Gate)',
        '',
        'Usage:',
        '  php scripts/release-gate/zero_surprise_replay.php [options]',
        '',
        'Required:',
        '  --release-id=VALUE             Release identifier (safe chars: letters, digits, ._-)',
        '  --dump-file=PATH               Absolute/relative path to .sql or .sql.gz dump file',
        '  One credential source:',
        '    --credentials-file=PATH      INI file with base_url/index_page/username/password',
        '    or explicit --base-url/--index-page/--username/--password flags',
        '',
        'Optional:',
        '  --profile=NAME                 Named digital-twin profile (default: school-day-default)',
        '  --base-url=URL                 App base URL override',
        '  --index-page=VALUE             URL index page override (use --index-page= for rewrite mode)',
        '  --username=NAME                Admin username override',
        '  --password=PASS                Admin password override',
        '  --start-date=YYYY-MM-DD        Dashboard filter start date override',
        '  --end-date=YYYY-MM-DD          Dashboard filter end date override',
        '  --booking-search-days=N        Booking slot search window override',
        '  --retry-count=N                Retry count for flaky write checks override',
        '  --max-pdf-duration-ms=N        Max allowed PDF export duration override',
        '  --timezone=TZID                Time zone override (default profile: Europe/Berlin)',
        '  --output-json=PATH             Consolidated report path',
        '                                (default: storage/logs/release-gate/zero-surprise-<UTC>.json)',
        '  --help                         Show this help',
        '',
        'Exit codes:',
        '  0  Success',
        '  1  Assertion/invariant failure',
        '  2  Runtime/configuration failure',
    ];

    fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
}


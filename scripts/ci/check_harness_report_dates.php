<?php

declare(strict_types=1);

const HARNESS_REPORT_DATE_SANITY_EXIT_SUCCESS = 0;
const HARNESS_REPORT_DATE_SANITY_EXIT_POLICY_FAILURE = 1;
const HARNESS_REPORT_DATE_SANITY_EXIT_RUNTIME_ERROR = 2;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runHarnessReportDateSanityCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runHarnessReportDateSanityCli(array $argv): int
{
    $config = harnessReportDateSanityDefaultConfig();
    $report = [
        'schema_version' => 1,
        'status' => 'error',
        'generated_at_utc' => gmdate('c'),
        'today_utc' => $config['today']->format('Y-m-d'),
        'max_future_days' => $config['max_future_days'],
        'paths' => $config['paths'],
        'violations' => [],
        'files' => [],
    ];
    $exitCode = HARNESS_REPORT_DATE_SANITY_EXIT_RUNTIME_ERROR;

    try {
        parseHarnessReportDateSanityCliOptions($argv, $config);

        if ($config['help'] === true) {
            fwrite(STDOUT, harnessReportDateSanityUsage());

            return HARNESS_REPORT_DATE_SANITY_EXIT_SUCCESS;
        }

        $evaluation = evaluateHarnessReportDateSanity(
            $config['root'],
            $config['today'],
            $config['max_future_days'],
            $config['paths'],
        );

        $report = array_merge($report, $evaluation, [
            'generated_at_utc' => gmdate('c'),
            'today_utc' => $config['today']->format('Y-m-d'),
            'max_future_days' => $config['max_future_days'],
            'paths' => $config['paths'],
        ]);

        if ($evaluation['status'] === 'pass') {
            fwrite(
                STDOUT,
                '[PASS] harness-report-date-sanity found no future-dated or mismatched report headers.' . PHP_EOL,
            );
            $exitCode = HARNESS_REPORT_DATE_SANITY_EXIT_SUCCESS;
        } else {
            fwrite(STDERR, '[FAIL] harness-report-date-sanity detected report date violations.' . PHP_EOL);
            $exitCode = HARNESS_REPORT_DATE_SANITY_EXIT_POLICY_FAILURE;
        }
    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ];

        fwrite(STDERR, '[ERROR] harness-report-date-sanity failed: ' . $e->getMessage() . PHP_EOL);
        $exitCode = HARNESS_REPORT_DATE_SANITY_EXIT_RUNTIME_ERROR;
    }

    try {
        harnessReportDateSanityWriteJsonFile($config['output_json'], $report);
        fwrite(STDOUT, '[INFO] Report: ' . $config['output_json'] . PHP_EOL);
    } catch (Throwable $e) {
        fwrite(STDERR, '[WARN] Failed to write harness-report-date-sanity report: ' . $e->getMessage() . PHP_EOL);

        if ($exitCode === HARNESS_REPORT_DATE_SANITY_EXIT_SUCCESS) {
            $exitCode = HARNESS_REPORT_DATE_SANITY_EXIT_RUNTIME_ERROR;
        }
    }

    return $exitCode;
}

function harnessReportDateSanityUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/check_harness_report_dates.php [options]',
        '',
        'Options:',
        '  --path=PATH             Restrict the scan to the given file or directory (repeatable).',
        '  --max-future-days=N     Allow dates up to N days in the future (default: 0).',
        '  --output-json=PATH      JSON report path.',
        '  --today=YYYY-MM-DD      Override today for deterministic testing.',
        '  --help                  Show this help text.',
        '',
    ]);
}

/**
 * @return array{
 *   root:string,
 *   paths:array<int, string>,
 *   max_future_days:int,
 *   output_json:string,
 *   today:DateTimeImmutable,
 *   help:bool
 * }
 */
function harnessReportDateSanityDefaultConfig(): array
{
    $root = dirname(__DIR__, 2);

    return [
        'root' => $root,
        'paths' => [],
        'max_future_days' => 0,
        'output_json' => $root . '/storage/logs/ci/harness-report-date-sanity-latest.json',
        'today' => new DateTimeImmutable('today', new DateTimeZone('UTC')),
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array{
 *   root:string,
 *   paths:array<int, string>,
 *   max_future_days:int,
 *   output_json:string,
 *   today:DateTimeImmutable,
 *   help:bool
 * } $config
 */
function parseHarnessReportDateSanityCliOptions(array $argv, array &$config): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--path=')) {
            $config['paths'][] = harnessReportDateSanityRequireNonEmptyCliValue($arg, '--path=');
            continue;
        }

        if (str_starts_with($arg, '--max-future-days=')) {
            $config['max_future_days'] = harnessReportDateSanityNormalizeNonNegativeInt(
                harnessReportDateSanityRequireNonEmptyCliValue($arg, '--max-future-days='),
                '--max-future-days',
            );
            continue;
        }

        if (str_starts_with($arg, '--output-json=')) {
            $config['output_json'] = harnessReportDateSanityRequireNonEmptyCliValue($arg, '--output-json=');
            continue;
        }

        if (str_starts_with($arg, '--today=')) {
            $dateValue = harnessReportDateSanityRequireNonEmptyCliValue($arg, '--today=');
            $config['today'] = harnessReportDateSanityParseIsoDate($dateValue, '--today');
            continue;
        }

        throw new InvalidArgumentException('Unknown CLI option: ' . $arg);
    }
}

/**
 * @param array<int, string> $paths
 * @return array{
 *   status:string,
 *   files:array<int, array<string, mixed>>,
 *   violations:array<int, array<string, mixed>>,
 *   messages:array<int, string>
 * }
 */
function evaluateHarnessReportDateSanity(
    string $root,
    DateTimeImmutable $today,
    int $maxFutureDays,
    array $paths = [],
): array {
    $files = harnessReportDateSanityResolveFiles($root, $paths);
    if ($files === []) {
        return [
            'status' => 'fail',
            'files' => [],
            'violations' => [
                [
                    'file' => null,
                    'source' => 'discovery',
                    'message' => 'No dated readiness or audit reports were found for validation.',
                ],
            ],
            'messages' => ['Add or restore dated readiness/audit reports before relying on date-sanity checks.'],
        ];
    }

    $violations = [];
    $fileReports = [];
    $messages = [];
    $allowedUntil = $today->modify('+' . $maxFutureDays . ' days');

    foreach ($files as $path) {
        $absolutePath = harnessReportDateSanityToAbsolutePath($root, $path);
        $content = @file_get_contents($absolutePath);

        if ($content === false) {
            $violations[] = [
                'file' => $path,
                'source' => 'filesystem',
                'message' => 'Failed to read report file.',
            ];
            continue;
        }

        $openingDates = harnessReportDateSanityExtractOpeningDates($content);
        $filenameDate = harnessReportDateSanityExtractFilenameDate($path);
        $fileViolations = [];

        if (($filenameDate['error'] ?? null) !== null) {
            $fileViolations[] = [
                'file' => $path,
                'source' => 'filename_date_invalid',
                'date' => $filenameDate['value'],
                'message' => $filenameDate['error'],
            ];
        }

        if (($filenameDate['date'] ?? null) instanceof DateTimeImmutable && $filenameDate['date'] > $allowedUntil) {
            $fileViolations[] = [
                'file' => $path,
                'source' => 'filename',
                'date' => $filenameDate['date']->format('Y-m-d'),
                'message' => sprintf(
                    'Filename date %s exceeds allowed future window ending %s.',
                    $filenameDate['date']->format('Y-m-d'),
                    $allowedUntil->format('Y-m-d'),
                ),
            ];
        }

        $validOpeningDates = [];

        foreach ($openingDates as $openingDate) {
            if (($openingDate['error'] ?? null) !== null) {
                $fileViolations[] = [
                    'file' => $path,
                    'source' => 'opening_date_invalid',
                    'date' => $openingDate['value'],
                    'line' => $openingDate['line'],
                    'message' => $openingDate['error'],
                ];
                continue;
            }

            $validOpeningDates[] = $openingDate;
        }

        if (($filenameDate['date'] ?? null) instanceof DateTimeImmutable && $validOpeningDates !== []) {
            $firstOpeningDate = $validOpeningDates[0]['date'];
            if ($firstOpeningDate->format('Y-m-d') !== $filenameDate['date']->format('Y-m-d')) {
                $fileViolations[] = [
                    'file' => $path,
                    'source' => 'header_mismatch',
                    'date' => $firstOpeningDate->format('Y-m-d'),
                    'line' => $validOpeningDates[0]['line'],
                    'message' => sprintf(
                        'Opening header date %s does not match filename date %s.',
                        $firstOpeningDate->format('Y-m-d'),
                        $filenameDate['date']->format('Y-m-d'),
                    ),
                ];
            }
        }

        foreach ($validOpeningDates as $openingDate) {
            if ($openingDate['date'] > $allowedUntil) {
                $fileViolations[] = [
                    'file' => $path,
                    'source' => 'opening_lines',
                    'date' => $openingDate['date']->format('Y-m-d'),
                    'line' => $openingDate['line'],
                    'message' => sprintf(
                        'Opening-line date %s exceeds allowed future window ending %s.',
                        $openingDate['date']->format('Y-m-d'),
                        $allowedUntil->format('Y-m-d'),
                    ),
                ];
            }
        }

        $violations = array_merge($violations, $fileViolations);
        $fileReports[] = [
            'file' => $path,
            'filename_date' => $filenameDate['date']?->format('Y-m-d'),
            'opening_dates' => array_map(
                static fn(array $entry): array => [
                    'date' => $entry['date']->format('Y-m-d'),
                    'line' => $entry['line'],
                ],
                $validOpeningDates,
            ),
            'status' => $fileViolations === [] ? 'pass' : 'fail',
        ];
    }

    if ($violations === []) {
        $messages[] = 'Report dates are plausible for the current repository date window.';
    } else {
        $messages[] = 'Fix future-dated or mismatched report headers before trusting readiness/audit snapshots.';
    }

    return [
        'status' => $violations === [] ? 'pass' : 'fail',
        'files' => $fileReports,
        'violations' => $violations,
        'messages' => $messages,
    ];
}

/**
 * @param array<int, string> $paths
 * @return array<int, string>
 */
function harnessReportDateSanityResolveFiles(string $root, array $paths): array
{
    if ($paths !== []) {
        $resolved = [];

        foreach ($paths as $path) {
            $absolutePath = harnessReportDateSanityToAbsolutePath($root, $path);
            if (is_dir($absolutePath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'md') {
                        continue;
                    }

                    $resolved[] = harnessReportDateSanityNormalizeRelativePath($root, $fileInfo->getPathname());
                }
                continue;
            }

            if (is_file($absolutePath)) {
                $resolved[] = harnessReportDateSanityNormalizeRelativePath($root, $absolutePath);
            }
        }

        $resolved = array_values(array_unique($resolved));
        sort($resolved);

        return $resolved;
    }

    $defaultPatterns = [$root . '/docs/agent-readiness*.md', $root . '/docs/reports/*.md'];
    $files = [];

    foreach ($defaultPatterns as $pattern) {
        foreach (glob($pattern) ?: [] as $filePath) {
            if (is_file($filePath)) {
                $files[] = harnessReportDateSanityNormalizeRelativePath($root, $filePath);
            }
        }
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/**
 * @return array<int, array{date:?DateTimeImmutable,error:?string,line:int,value:string}>
 */
function harnessReportDateSanityExtractOpeningDates(string $content): array
{
    $lines = preg_split("/\r\n|\n|\r/", $content);
    if (!is_array($lines)) {
        return [];
    }

    $results = [];

    foreach (array_slice($lines, 0, 12, true) as $index => $line) {
        if (!preg_match_all('/\b20\d{2}-\d{2}-\d{2}\b/', $line, $matches)) {
            continue;
        }

        foreach ($matches[0] as $match) {
            $results[] = harnessReportDateSanityBuildDateProbe($match, 'opening date', $index + 1);
        }
    }

    return $results;
}

/**
 * @return array{date:?DateTimeImmutable,error:?string,value:?string}
 */
function harnessReportDateSanityExtractFilenameDate(string $path): array
{
    if (!preg_match('/(20\d{2}-\d{2}-\d{2})/', basename($path), $matches)) {
        return [
            'date' => null,
            'error' => null,
            'value' => null,
        ];
    }

    return harnessReportDateSanityBuildDateProbe($matches[1], 'filename date');
}

function harnessReportDateSanityParseIsoDate(string $value, string $fieldName): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException(sprintf('%s must be a valid ISO date, got %s.', $fieldName, $value));
    }

    return $date;
}

function harnessReportDateSanityRequireNonEmptyCliValue(string $arg, string $prefix): string
{
    $value = substr($arg, strlen($prefix));
    if ($value === false || trim($value) === '') {
        throw new InvalidArgumentException(sprintf('Option %s requires a non-empty value.', rtrim($prefix, '=')));
    }

    return $value;
}

function harnessReportDateSanityNormalizeNonNegativeInt(string $value, string $optionName): int
{
    if (!preg_match('/^\d+$/', $value)) {
        throw new InvalidArgumentException(
            sprintf('Option %s expects a non-negative integer, got %s.', $optionName, $value),
        );
    }

    return (int) $value;
}

function harnessReportDateSanityToAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, '/')) {
        return $path;
    }

    return $root . '/' . ltrim($path, '/');
}

function harnessReportDateSanityNormalizeRelativePath(string $root, string $path): string
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $normalizedPath = str_replace('\\', '/', $path);

    if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
        return substr($normalizedPath, strlen($normalizedRoot) + 1);
    }

    if (str_starts_with($normalizedPath, '/')) {
        return $normalizedPath;
    }

    return ltrim($normalizedPath, './');
}

/**
 * @return array{date:?DateTimeImmutable,error:?string,line?:int,value:string}
 */
function harnessReportDateSanityBuildDateProbe(string $value, string $fieldName, ?int $line = null): array
{
    try {
        $probe = [
            'date' => harnessReportDateSanityParseIsoDate($value, $fieldName),
            'error' => null,
            'value' => $value,
        ];
    } catch (InvalidArgumentException $e) {
        $probe = [
            'date' => null,
            'error' => $e->getMessage(),
            'value' => $value,
        ];
    }

    if ($line !== null) {
        $probe['line'] = $line;
    }

    return $probe;
}

/**
 * @param array<string, mixed> $report
 */
function harnessReportDateSanityWriteJsonFile(string $path, array $report): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create directory for report: ' . $directory);
    }

    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Failed to encode report JSON.');
    }

    if (file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write report: ' . $path);
    }
}

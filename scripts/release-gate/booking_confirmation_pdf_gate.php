#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/GateAssertions.php';
require_once __DIR__ . '/lib/GateProcessRunner.php';

use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateProcessRunner;

const RELEASE_GATE_EXIT_SUCCESS = 0;
const RELEASE_GATE_EXIT_ASSERTION_FAILURE = 1;
const RELEASE_GATE_EXIT_RUNTIME_ERROR = 2;

$startedAtUtc = gmdate('c');
$startedAt = microtime(true);
$checks = [];
$failure = null;
$exitCode = RELEASE_GATE_EXIT_SUCCESS;
$cleanupWarnings = [];

$repoRoot = dirname(__DIR__, 2);
$timestamp = gmdate('Ymd\THis\Z');
$defaultOutputPath = $repoRoot . '/storage/logs/release-gate/booking-confirmation-pdf-' . $timestamp . '.json';
$defaultArtifactsDir = $repoRoot . '/output/playwright/booking-confirmation-pdf/' . $timestamp;
$defaultPwcliPath = resolveDefaultPwcliPath();
$downloadSnippetPath = __DIR__ . '/playwright/booking_confirmation_download.js';

$sessionId = null;
$config = null;

try {
    $config = parseCliOptions($repoRoot, $defaultOutputPath, $defaultArtifactsDir, $defaultPwcliPath);

    if ($config['help'] === true) {
        printUsage();
        exit(RELEASE_GATE_EXIT_SUCCESS);
    }

    $sessionId = buildSessionId();
    $confirmationUrl = resolveConfirmationUrl($config);
    $downloadPath = $config['artifacts_dir'] . '/booking-confirmation.pdf';
    $beforeScreenshotPath = $config['artifacts_dir'] . '/before-click.png';
    $afterScreenshotPath = $config['artifacts_dir'] . '/after-click.png';
    $networkLogPath = $config['artifacts_dir'] . '/network.log';

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

    $runCheck('readiness_dependencies', static function () use ($config, $downloadSnippetPath, $repoRoot): array {
        ensureFileReadable($config['pwcli_path'], 'pwcli wrapper');
        ensureFileExecutable($config['pwcli_path'], 'pwcli wrapper');
        ensureFileReadable($downloadSnippetPath, 'Playwright download snippet');

        $npxProbe = GateProcessRunner::run(['bash', '-lc', 'command -v npx >/dev/null 2>&1'], $repoRoot, null, 10);

        if (($npxProbe['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException('npx was not found on PATH.');
        }

        ensureDirectory($config['artifacts_dir']);

        return [
            'pwcli_path' => $config['pwcli_path'],
            'download_snippet' => $downloadSnippetPath,
            'artifacts_dir' => $config['artifacts_dir'],
        ];
    });

    $runCheck('open_confirmation_page', static function () use (
        $config,
        $repoRoot,
        $sessionId,
        $confirmationUrl,
    ): array {
        $arguments = ['open', $confirmationUrl];

        if ($config['headed']) {
            $arguments[] = '--headed';
        }

        $openResult = runPwcliCommand($config, $sessionId, $arguments, $repoRoot, $config['open_timeout']);
        assertProcessSucceeded($openResult, 'Open confirmation page');

        $snapshotResult = runPwcliCommand($config, $sessionId, ['snapshot'], $repoRoot, $config['open_timeout']);
        assertProcessSucceeded($snapshotResult, 'Snapshot confirmation page');

        return [
            'confirmation_url' => $confirmationUrl,
            'open_duration_ms' => $openResult['duration_ms'],
            'snapshot_duration_ms' => $snapshotResult['duration_ms'],
        ];
    });

    $runCheck('screenshot_before_pdf_click', static function () use (
        $config,
        $repoRoot,
        $sessionId,
        $beforeScreenshotPath,
    ): array {
        $result = runPwcliCommand(
            $config,
            $sessionId,
            ['screenshot', '--filename', $beforeScreenshotPath],
            $repoRoot,
            $config['open_timeout'],
        );
        assertProcessSucceeded($result, 'Capture screenshot before PDF click');

        ensureFileReadable($beforeScreenshotPath, 'Before screenshot');

        return [
            'screenshot_path' => $beforeScreenshotPath,
            'bytes' => filesize($beforeScreenshotPath) ?: 0,
        ];
    });

    $runCheck('download_confirmation_pdf', static function () use (
        $config,
        $repoRoot,
        $sessionId,
        $downloadSnippetPath,
        $downloadPath,
    ): array {
        $snippet = file_get_contents($downloadSnippetPath);

        if (!is_string($snippet) || trim($snippet) === '') {
            throw new RuntimeException('Could not read Playwright download snippet.');
        }

        $snippet = injectSnippetPlaceholders(
            $snippet,
            '[data-generate-pdf]',
            $config['download_timeout'] * 1000,
            $downloadPath,
        );

        if (is_file($downloadPath) && !unlink($downloadPath)) {
            throw new RuntimeException('Could not remove stale download file: ' . $downloadPath);
        }

        $runCodeResult = runPwcliCommand(
            $config,
            $sessionId,
            ['run-code', $snippet],
            $repoRoot,
            $config['download_timeout'] + 15,
            null,
        );
        assertProcessSucceeded($runCodeResult, 'Trigger booking confirmation PDF download');

        $marker = parseRunCodeResult($runCodeResult);

        $ok = (bool) ($marker['ok'] ?? false);
        if (!$ok) {
            $error = trim((string) ($marker['error'] ?? 'Unknown PDF generation failure.'));
            throw new GateAssertionException($error !== '' ? $error : 'Unknown PDF generation failure.');
        }

        ensureFileReadable($downloadPath, 'Downloaded booking confirmation PDF');

        $pdf = file_get_contents($downloadPath);
        if (!is_string($pdf)) {
            throw new GateAssertionException('Could not read downloaded PDF file.');
        }

        GateAssertions::assertPdfBinary($pdf, 'application/pdf', $config['min_pdf_bytes']);

        return [
            'download_path' => $downloadPath,
            'download_bytes' => strlen($pdf),
            'suggested_filename' => $marker['download_suggested_filename'] ?? null,
            'page_errors' => $marker['page_errors'] ?? [],
            'console_errors' => $marker['console_errors'] ?? [],
        ];
    });

    $runCheck('screenshot_after_pdf_click', static function () use (
        $config,
        $repoRoot,
        $sessionId,
        $afterScreenshotPath,
    ): array {
        $result = runPwcliCommand(
            $config,
            $sessionId,
            ['screenshot', '--filename', $afterScreenshotPath],
            $repoRoot,
            $config['open_timeout'],
        );
        assertProcessSucceeded($result, 'Capture screenshot after PDF click');

        ensureFileReadable($afterScreenshotPath, 'After screenshot');

        return [
            'screenshot_path' => $afterScreenshotPath,
            'bytes' => filesize($afterScreenshotPath) ?: 0,
        ];
    });

    $runCheck('collect_network_log', static function () use ($config, $repoRoot, $sessionId, $networkLogPath): array {
        $result = runPwcliCommand($config, $sessionId, ['network'], $repoRoot, $config['open_timeout']);
        assertProcessSucceeded($result, 'Collect network log');

        writeTextFile($networkLogPath, (string) ($result['stdout'] ?? ''));
        $lineCount = count(array_filter(explode("\n", (string) ($result['stdout'] ?? '')), 'strlen'));

        return [
            'network_log_path' => $networkLogPath,
            'lines' => $lineCount,
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

function injectSnippetPlaceholders(string $snippet, string $selector, int $timeoutMs, string $downloadPath): string
{
    $replacements = [
        '__BOOKING_GATE_SELECTOR__' => json_encode($selector, JSON_THROW_ON_ERROR),
        '__BOOKING_GATE_TIMEOUT_MS__' => (string) $timeoutMs,
        '__BOOKING_GATE_DOWNLOAD_PATH__' => json_encode($downloadPath, JSON_THROW_ON_ERROR),
    ];

    $resolved = strtr($snippet, $replacements);

    foreach (array_keys($replacements) as $placeholder) {
        if (str_contains($resolved, $placeholder)) {
            throw new RuntimeException('Could not resolve snippet placeholder: ' . $placeholder);
        }
    }

    return $resolved;
}

if ($sessionId !== null && is_array($config) && !empty($config['pwcli_path'])) {
    try {
        $closeResult = runPwcliCommand($config, $sessionId, ['close'], $repoRoot, 10);

        if (($closeResult['exit_code'] ?? 1) !== 0) {
            $cleanupWarnings[] = trim((string) ($closeResult['stderr'] ?? 'Failed to close Playwright session.'));
        }
    } catch (Throwable $e) {
        $cleanupWarnings[] = 'Failed to close Playwright session: ' . $e->getMessage();
    }
}

$finishedAtUtc = gmdate('c');
$durationMs = round((microtime(true) - $startedAt) * 1000, 2);
$passedChecks = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? null) === 'pass'));
$failedChecks = count($checks) - $passedChecks;

$reportConfig = [];
if (is_array($config)) {
    $reportConfig = [
        'base_url' => $config['base_url'],
        'index_page' => $config['index_page'],
        'confirmation_hash' => $config['confirmation_hash'],
        'confirmation_url' => $config['confirmation_url'],
        'pwcli_path' => $config['pwcli_path'],
        'download_timeout' => $config['download_timeout'],
        'min_pdf_bytes' => $config['min_pdf_bytes'],
        'artifacts_dir' => $config['artifacts_dir'],
        'headed' => $config['headed'],
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

if ($cleanupWarnings !== []) {
    $report['cleanup_warnings'] = $cleanupWarnings;
}

if ($failure !== null) {
    $report['failure'] = $failure;
}

try {
    writeJsonReport($outputPath, $report);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Could not write JSON report: ' . $e->getMessage() . PHP_EOL);
    $exitCode = RELEASE_GATE_EXIT_RUNTIME_ERROR;
}

if ($exitCode === RELEASE_GATE_EXIT_SUCCESS) {
    fwrite(
        STDOUT,
        sprintf('[PASS] Booking confirmation PDF gate passed (%d checks) -> %s%s', $passedChecks, $outputPath, PHP_EOL),
    );
} else {
    fwrite(
        STDERR,
        sprintf('[FAIL] Booking confirmation PDF gate failed (exit %d) -> %s%s', $exitCode, $outputPath, PHP_EOL),
    );
}

exit($exitCode);

/**
 * @return array{
 *   help:bool,
 *   base_url:string,
 *   index_page:string,
 *   confirmation_hash:string|null,
 *   confirmation_url:string|null,
 *   pwcli_path:string,
 *   open_timeout:int,
 *   download_timeout:int,
 *   min_pdf_bytes:int,
 *   artifacts_dir:string,
 *   headed:bool,
 *   output_json:string
 * }
 */
function parseCliOptions(
    string $repoRoot,
    string $defaultOutputPath,
    string $defaultArtifactsDir,
    string $defaultPwcliPath,
): array {
    $options = getopt('', [
        'help',
        'base-url:',
        'index-page::',
        'confirmation-hash:',
        'confirmation-url:',
        'pwcli-path::',
        'open-timeout::',
        'download-timeout::',
        'min-pdf-bytes::',
        'artifacts-dir::',
        'headed::',
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
            'confirmation_hash' => null,
            'confirmation_url' => null,
            'pwcli_path' => $defaultPwcliPath,
            'open_timeout' => 20,
            'download_timeout' => 20,
            'min_pdf_bytes' => 1024,
            'artifacts_dir' => $defaultArtifactsDir,
            'headed' => false,
            'output_json' => $defaultOutputPath,
        ];
    }

    $baseUrl = trim(getRequiredOption($options, 'base-url'));
    validateAbsoluteHttpUrl($baseUrl, 'base-url');

    $indexPageRaw = getOptionalOption(
        $options,
        'index-page',
        hasExplicitEmptyLongOption('index-page') ? '' : 'index.php',
    );
    $indexPage = trim((string) ($indexPageRaw ?? 'index.php'));

    $confirmationHashRaw = getOptionalOption($options, 'confirmation-hash', null);
    $confirmationUrlRaw = getOptionalOption($options, 'confirmation-url', null);
    $confirmationHash = normalizeOptionalString($confirmationHashRaw);
    $confirmationUrl = normalizeOptionalString($confirmationUrlRaw);

    if (
        ($confirmationHash === null && $confirmationUrl === null) ||
        ($confirmationHash !== null && $confirmationUrl !== null)
    ) {
        throw new InvalidArgumentException('Provide exactly one of --confirmation-hash or --confirmation-url.');
    }

    if ($confirmationHash !== null && preg_match('/^[A-Za-z0-9_-]+$/', $confirmationHash) !== 1) {
        throw new InvalidArgumentException('--confirmation-hash contains unsupported characters.');
    }

    if ($confirmationUrl !== null) {
        validateAbsoluteHttpUrl($confirmationUrl, 'confirmation-url');
    }

    $pwcliPath = resolvePath(trim((string) getOptionalOption($options, 'pwcli-path', $defaultPwcliPath)), $repoRoot);

    $openTimeout = parsePositiveInt(getOptionalOption($options, 'open-timeout', 20), 'open-timeout');
    $downloadTimeout = parsePositiveInt(getOptionalOption($options, 'download-timeout', 20), 'download-timeout');
    $minPdfBytes = parsePositiveInt(getOptionalOption($options, 'min-pdf-bytes', 1024), 'min-pdf-bytes');
    $artifactsDir = resolvePath(
        trim((string) getOptionalOption($options, 'artifacts-dir', $defaultArtifactsDir)),
        $repoRoot,
    );

    $headed = parseBooleanOption(getOptionalOption($options, 'headed', null));

    $outputJson = resolvePath(trim((string) getOptionalOption($options, 'output-json', $defaultOutputPath)), $repoRoot);

    if ($outputJson === '') {
        throw new InvalidArgumentException('--output-json must not be empty.');
    }

    return [
        'help' => false,
        'base_url' => $baseUrl,
        'index_page' => $indexPage,
        'confirmation_hash' => $confirmationHash,
        'confirmation_url' => $confirmationUrl,
        'pwcli_path' => $pwcliPath,
        'open_timeout' => $openTimeout,
        'download_timeout' => $downloadTimeout,
        'min_pdf_bytes' => $minPdfBytes,
        'artifacts_dir' => $artifactsDir,
        'headed' => $headed,
        'output_json' => $outputJson,
    ];
}

/**
 * @param array{
 *   base_url:string,
 *   index_page:string,
 *   confirmation_hash:string|null,
 *   confirmation_url:string|null
 * } $config
 */
function resolveConfirmationUrl(array $config): string
{
    if (!empty($config['confirmation_url'])) {
        return (string) $config['confirmation_url'];
    }

    $hash = (string) ($config['confirmation_hash'] ?? '');
    if ($hash === '') {
        throw new InvalidArgumentException('Missing confirmation hash.');
    }

    $base = rtrim((string) $config['base_url'], '/');
    $indexPage = trim((string) $config['index_page'], '/');
    $path = 'booking_confirmation/of/' . rawurlencode($hash);

    if ($indexPage !== '') {
        $path = $indexPage . '/' . $path;
    }

    return $base . '/' . $path;
}

/**
 * @param array<string, mixed> $config
 * @param array<int, string> $arguments
 * @param array<string, string>|null $environment
 *
 * @return array{
 *   command:string,
 *   exit_code:int,
 *   stdout:string,
 *   stderr:string,
 *   duration_ms:float,
 *   timed_out:bool
 * }
 */
function runPwcliCommand(
    array $config,
    string $sessionId,
    array $arguments,
    string $repoRoot,
    int $timeoutSeconds,
    ?array $environment = null,
): array {
    $command = [(string) $config['pwcli_path'], '--session', $sessionId, ...$arguments];
    $effectiveEnvironment = $environment === null ? null : array_merge(getCurrentEnvironment(), $environment);

    return GateProcessRunner::run($command, $repoRoot, $effectiveEnvironment, $timeoutSeconds);
}

/**
 * @param array<string, mixed> $result
 */
function assertProcessSucceeded(array $result, string $context): void
{
    $exitCode = (int) ($result['exit_code'] ?? 1);
    $timedOut = (bool) ($result['timed_out'] ?? false);

    if ($exitCode === 0 && !$timedOut) {
        return;
    }

    $stderr = trim((string) ($result['stderr'] ?? ''));
    $stdout = trim((string) ($result['stdout'] ?? ''));
    $excerpt = $stderr !== '' ? $stderr : $stdout;
    $excerpt = substr($excerpt, 0, 1200);

    if ($timedOut) {
        throw new RuntimeException($context . ' timed out. ' . $excerpt);
    }

    throw new RuntimeException(sprintf('%s failed with exit code %d. %s', $context, $exitCode, $excerpt));
}

/**
 * @param array<string, mixed> $runCodeResult
 *
 * @return array<string, mixed>
 */
function parseRunCodeResult(array $runCodeResult): array
{
    $output = (string) ($runCodeResult['stdout'] ?? '');

    if ($output === '') {
        throw new GateAssertionException('Playwright run-code produced no output.');
    }

    $errorMatches = [];
    if (preg_match('/### Error\s*\R(.+?)(?:\R###|\z)/s', $output, $errorMatches) === 1) {
        $errorText = trim((string) ($errorMatches[1] ?? 'Unknown Playwright run-code error.'));
        throw new GateAssertionException($errorText !== '' ? $errorText : 'Unknown Playwright run-code error.');
    }

    $rawJson = null;
    $matches = [];
    if (preg_match('/### Result\s*\R(.+?)(?:\R###\s+[^\r\n]+|\z)/s', $output, $matches) === 1) {
        $rawJson = trim((string) ($matches[1] ?? ''));
    }

    if ($rawJson === null || $rawJson === '') {
        throw new GateAssertionException(
            'Could not parse run-code result payload from Playwright output: ' . substr(trim($output), 0, 500),
        );
    }

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        throw new GateAssertionException('Playwright run-code result payload is not valid JSON.');
    }

    return $decoded;
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

function normalizeOptionalString(mixed $value): ?string
{
    if ($value === null || $value === false) {
        return null;
    }

    $normalized = trim(is_array($value) ? (string) end($value) : (string) $value);

    return $normalized === '' ? null : $normalized;
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

    throw new InvalidArgumentException('Boolean option value is invalid: ' . $normalized);
}

function validateAbsoluteHttpUrl(string $value, string $optionName): void
{
    if (filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('Option --' . $optionName . ' must be a valid URL.');
    }

    $scheme = (string) parse_url($value, PHP_URL_SCHEME);
    if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
        throw new InvalidArgumentException('Option --' . $optionName . ' must use HTTP or HTTPS.');
    }
}

function resolvePath(string $path, string $repoRoot): string
{
    if ($path === '') {
        throw new InvalidArgumentException('Path option must not be empty.');
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    return rtrim($repoRoot, '/') . '/' . ltrim($path, '/');
}

/**
 * @return array<string, string>
 */
function getCurrentEnvironment(): array
{
    $environment = getenv();

    if (!is_array($environment)) {
        return [];
    }

    $normalized = [];

    foreach ($environment as $key => $value) {
        if (!is_string($key) || !is_string($value)) {
            continue;
        }

        $normalized[$key] = $value;
    }

    return $normalized;
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create directory: ' . $path);
    }
}

function ensureFileReadable(string $path, string $label): void
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException($label . ' is missing or not readable: ' . $path);
    }
}

function ensureFileExecutable(string $path, string $label): void
{
    if (!is_executable($path)) {
        throw new RuntimeException($label . ' is not executable: ' . $path);
    }
}

function writeTextFile(string $path, string $content): void
{
    $directory = dirname($path);
    ensureDirectory($directory);

    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Could not write file: ' . $path);
    }
}

/**
 * @param array<string, mixed> $report
 */
function writeJsonReport(string $path, array $report): void
{
    $directory = dirname($path);
    ensureDirectory($directory);

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if ($json === false) {
        throw new RuntimeException('Could not encode release gate report as JSON.');
    }

    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Could not write release gate report to: ' . $path);
    }
}

function resolveDefaultPwcliPath(): string
{
    $codexHome = getenv('CODEX_HOME');

    if (!is_string($codexHome) || trim($codexHome) === '') {
        $home = getenv('HOME');
        $codexHome = is_string($home) && $home !== '' ? rtrim($home, '/') . '/.codex' : '.codex';
    }

    return rtrim($codexHome, '/') . '/skills/playwright/scripts/playwright_cli.sh';
}

function buildSessionId(): string
{
    $suffix = bin2hex(random_bytes(2));

    return 'bcpdf-' . gmdate('His') . '-' . $suffix;
}

function printUsage(): void
{
    $lines = [
        'Booking Confirmation PDF Gate',
        '',
        'Usage:',
        '  php scripts/release-gate/booking_confirmation_pdf_gate.php [options]',
        '',
        'Required:',
        '  --base-url=URL                 App base URL (example: http://localhost)',
        '  --confirmation-hash=HASH       Existing appointment hash for /booking_confirmation/of/HASH',
        '    OR',
        '  --confirmation-url=URL         Absolute confirmation URL (mutually exclusive with hash)',
        '',
        'Optional:',
        '  --index-page=VALUE             URL index page segment (default: index.php, use empty for rewrite mode)',
        '  --pwcli-path=PATH              Playwright wrapper path (default: $CODEX_HOME/skills/playwright/scripts/playwright_cli.sh)',
        '  --open-timeout=SECONDS         Timeout for open/snapshot/screenshots (default: 20)',
        '  --download-timeout=SECONDS     Timeout for PDF click/download (default: 20)',
        '  --min-pdf-bytes=BYTES          Minimum PDF size assertion (default: 1024)',
        '  --artifacts-dir=PATH           Output directory for screenshots/network/PDF artifact',
        '  --headed                       Run browser headed (debug mode)',
        '  --headed=0|1                   Explicitly disable/enable headed mode',
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

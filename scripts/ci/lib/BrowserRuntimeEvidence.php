<?php

declare(strict_types=1);

namespace CiRuntimeEvidence;

require_once __DIR__ . '/../../release-gate/lib/GateProcessRunner.php';
require_once __DIR__ . '/../../release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../../release-gate/lib/PlaywrightBrowserSelection.php';
require_once __DIR__ . '/DashboardSummaryBrowserCheck.php';

use ReleaseGate\GateAssertionException;
use ReleaseGate\GateProcessRunner;
use function ReleaseGate\buildPlaywrightSessionArguments;
use function ReleaseGate\prepareConfiguredPlaywrightCommandArguments;
use RuntimeException;
use Throwable;

/**
 * @param array{
 *   repo_root:string,
 *   base_url:string,
 *   index_page:string,
 *   artifacts_dir:string,
 *   pwcli_path:string,
 *   bootstrap_timeout:int,
 *   open_timeout:int,
 *   headed:bool,
 *   mode:string
 * } $config
 * @return array{
 *   status:string,
 *   mode:string,
 *   target_url:string,
 *   artifacts_dir:string,
 *   summary_path:string,
 *   steps:array<int, array<string, mixed>>,
 *   artifacts:array<string, mixed>,
 *   failure:array<string, string>|null,
 *   cleanup_warnings:array<int, string>
 * }
 */
function collectBookingPageBrowserEvidence(array $config): array
{
    $targetUrl = resolveBookingPageTargetUrl($config['base_url'], $config['index_page']);
    $artifactsDir = rtrim($config['artifacts_dir'], '/');
    $summaryPath = $artifactsDir . '/summary.json';
    $snapshotPath = $artifactsDir . '/snapshot.txt';
    $screenshotPath = $artifactsDir . '/page.png';
    $failureScreenshotPath = $artifactsDir . '/failure.png';
    $tracePath = $artifactsDir . '/trace.trace';
    $networkLogPath = $artifactsDir . '/network.log';
    $startedAt = microtime(true);
    $sessionId = null;
    $traceStarted = false;
    $failure = null;
    $steps = [];
    $cleanupWarnings = [];
    $artifacts = [
        'snapshot_path' => null,
        'screenshot_path' => null,
        'failure_screenshot_path' => null,
        'trace_path' => null,
        'network_log_path' => null,
    ];

    ensureDirectory($artifactsDir);

    $runStep = static function (string $name, callable $callback, bool $runWhenFailed = false) use (
        &$steps,
        &$failure,
    ): bool {
        $existingFailure = $failure;

        if ($existingFailure !== null && !$runWhenFailed) {
            return false;
        }

        $started = microtime(true);

        try {
            $details = $callback();

            if (!is_array($details)) {
                $details = ['detail' => (string) $details];
            }

            $steps[] = array_merge(
                [
                    'name' => $name,
                    'status' => 'pass',
                    'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                ],
                $details,
            );

            return true;
        } catch (Throwable $e) {
            if ($existingFailure === null) {
                $failure = [
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                ];
            }
            $steps[] = [
                'name' => $name,
                'status' => 'fail',
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ];

            return false;
        }
    };

    $runStep('readiness_dependencies', static function () use ($config, $artifactsDir): array {
        ensureFileReadable($config['pwcli_path'], 'Playwright wrapper');

        $probe = GateProcessRunner::run(
            ['bash', '-lc', 'command -v npx >/dev/null 2>&1'],
            $config['repo_root'],
            null,
            10,
        );

        if (($probe['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException('npx was not found on PATH.');
        }

        $bootstrap = GateProcessRunner::run(
            ['bash', $config['pwcli_path'], 'install-browser'],
            $config['repo_root'],
            null,
            $config['bootstrap_timeout'],
        );
        assertPlaywrightCommandSucceeded($bootstrap, 'Bootstrap Playwright CLI');

        return [
            'pwcli_path' => $config['pwcli_path'],
            'artifacts_dir' => $artifactsDir,
            'bootstrap_duration_ms' => $bootstrap['duration_ms'],
        ];
    });

    $runStep('open_booking_page', static function () use ($config, $targetUrl, &$sessionId): array {
        $sessionId = buildBrowserRuntimeEvidenceSessionId();
        $arguments = ['open', $targetUrl];

        if ($config['headed']) {
            $arguments[] = '--headed';
        }

        $result = runPwcliCommand($config, $sessionId, $arguments, $config['open_timeout']);
        assertPlaywrightCommandSucceeded($result, 'Open booking page');

        return [
            'target_url' => $targetUrl,
            'session_id' => $sessionId,
            'open_duration_ms' => $result['duration_ms'],
        ];
    });

    $runStep('start_trace', static function () use ($config, &$sessionId, &$traceStarted): array {
        if (!is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Playwright session is missing for tracing.');
        }

        $result = runPwcliCommand($config, $sessionId, ['tracing-start'], $config['open_timeout']);
        assertPlaywrightCommandSucceeded($result, 'Start Playwright trace');
        $traceStarted = true;

        return [
            'duration_ms' => $result['duration_ms'],
        ];
    });

    $runStep('reload_booking_page', static function () use ($config, &$sessionId): array {
        if (!is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Playwright session is missing for reload.');
        }

        $result = runPwcliCommand($config, $sessionId, ['reload'], $config['open_timeout']);
        assertPlaywrightCommandSucceeded($result, 'Reload booking page');

        return [
            'duration_ms' => $result['duration_ms'],
        ];
    });

    $runStep('capture_snapshot', static function () use ($config, &$sessionId, $snapshotPath, &$artifacts): array {
        if (!is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Playwright session is missing for snapshot capture.');
        }

        $result = runPwcliCommand($config, $sessionId, ['snapshot'], $config['open_timeout']);
        assertPlaywrightCommandSucceeded($result, 'Capture booking snapshot');
        writeTextFile($snapshotPath, (string) ($result['stdout'] ?? ''));
        $artifacts['snapshot_path'] = $snapshotPath;

        return [
            'snapshot_path' => $snapshotPath,
            'bytes' => filesize($snapshotPath) ?: 0,
        ];
    });

    $runStep('capture_screenshot', static function () use ($config, &$sessionId, $screenshotPath, &$artifacts): array {
        if (!is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Playwright session is missing for screenshot capture.');
        }

        $result = runPwcliCommand(
            $config,
            $sessionId,
            ['screenshot', '--filename', $screenshotPath],
            $config['open_timeout'],
        );
        assertPlaywrightCommandSucceeded($result, 'Capture booking screenshot');
        ensureFileReadable($screenshotPath, 'Booking screenshot');
        $artifacts['screenshot_path'] = $screenshotPath;

        return [
            'screenshot_path' => $screenshotPath,
            'bytes' => filesize($screenshotPath) ?: 0,
        ];
    });

    if ($failure !== null && is_string($sessionId) && $sessionId !== '') {
        $captureFailureScreenshot = static function () use (
            $config,
            $sessionId,
            $failureScreenshotPath,
            &$artifacts,
            &$cleanupWarnings,
        ): void {
            try {
                $result = runPwcliCommand(
                    $config,
                    $sessionId,
                    ['screenshot', '--filename', $failureScreenshotPath],
                    $config['open_timeout'],
                );
                assertPlaywrightCommandSucceeded($result, 'Capture failure screenshot');
                ensureFileReadable($failureScreenshotPath, 'Failure screenshot');
                $artifacts['failure_screenshot_path'] = $failureScreenshotPath;
            } catch (Throwable $e) {
                $cleanupWarnings[] = 'Failed to capture failure screenshot: ' . $e->getMessage();
            }
        };

        $captureFailureScreenshot();
    }

    if ($traceStarted && is_string($sessionId) && $sessionId !== '') {
        $runStep(
            'stop_trace',
            static function () use ($config, $sessionId, $tracePath, $networkLogPath, &$artifacts): array {
                $result = runPwcliCommand($config, $sessionId, ['tracing-stop'], $config['open_timeout']);
                assertPlaywrightCommandSucceeded($result, 'Stop Playwright trace');

                $traceSource = resolvePlaywrightArtifactPath(
                    (string) ($result['stdout'] ?? ''),
                    'Trace',
                    $config['repo_root'],
                );
                $networkSource = resolvePlaywrightArtifactPath(
                    (string) ($result['stdout'] ?? ''),
                    'Network log',
                    $config['repo_root'],
                );

                if ($traceSource === null) {
                    throw new RuntimeException('Playwright trace output did not expose a trace artifact.');
                }

                if ($networkSource === null) {
                    throw new RuntimeException('Playwright trace output did not expose a network log artifact.');
                }

                if ($traceSource !== null) {
                    ensureFileReadable($traceSource, 'Playwright trace artifact');
                    copyFile($traceSource, $tracePath);
                    $artifacts['trace_path'] = $tracePath;
                }

                ensureFileReadable($networkSource, 'Playwright network log');
                copyFile($networkSource, $networkLogPath);
                $artifacts['network_log_path'] = $networkLogPath;

                return [
                    'trace_path' => $artifacts['trace_path'],
                    'network_log_path' => $artifacts['network_log_path'],
                ];
            },
            true,
        );
    }

    if (is_string($sessionId) && $sessionId !== '') {
        try {
            $closeResult = runPwcliCommand($config, $sessionId, ['close'], 10);

            if (($closeResult['exit_code'] ?? 1) !== 0) {
                $cleanupWarnings[] = trim((string) ($closeResult['stderr'] ?? 'Failed to close Playwright session.'));
            }
        } catch (Throwable $e) {
            $cleanupWarnings[] = 'Failed to close Playwright session: ' . $e->getMessage();
        }
    }

    $report = [
        'meta' => [
            'started_at_utc' => gmdate('c', (int) $startedAt),
            'finished_at_utc' => gmdate('c'),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ],
        'mode' => $config['mode'],
        'target_url' => $targetUrl,
        'artifacts_dir' => $artifactsDir,
        'steps' => $steps,
        'artifacts' => $artifacts,
        'failure' => $failure,
    ];

    if ($cleanupWarnings !== []) {
        $report['cleanup_warnings'] = $cleanupWarnings;
    }

    writeJsonFile($summaryPath, $report);

    return [
        'status' => $failure === null ? 'pass' : 'fail',
        'mode' => $config['mode'],
        'target_url' => $targetUrl,
        'artifacts_dir' => $artifactsDir,
        'summary_path' => $summaryPath,
        'steps' => $steps,
        'artifacts' => $artifacts,
        'failure' => $failure,
        'cleanup_warnings' => $cleanupWarnings,
    ];
}

/**
 * @param array{
 *   repo_root:string,
 *   target_url:string,
 *   artifacts_dir:string,
 *   username:string,
 *   password:string,
 *   start_date:string,
 *   end_date:string,
 *   expected_summary:array{
 *     target_total:int|float|string,
 *     booked_total:int|float|string,
 *     open_total:int|float|string,
 *     fill_rate:int|float|string
 *   },
 *   pwcli_path:string,
 *   bootstrap_timeout:int,
 *   open_timeout:int,
 *   headed:bool
 * } $config
 * @return array<string, mixed>
 */
function runDashboardSummaryBrowserCheck(array $config): array
{
    $artifactsDir = rtrim($config['artifacts_dir'], '/');
    $sessionId = buildBrowserRuntimeEvidenceSessionId();
    $runCodeSnippet = \dashboardSummaryBrowserBuildRunCodeSnippet([
        'username' => $config['username'],
        'password' => $config['password'],
        'target_url' => $config['target_url'],
        'start_date' => $config['start_date'],
        'end_date' => $config['end_date'],
        'expected_summary' => $config['expected_summary'],
    ]);

    ensureDirectory($artifactsDir);
    ensureFileReadable($config['pwcli_path'], 'Playwright wrapper');

    $probe = GateProcessRunner::run(['bash', '-lc', 'command -v npx >/dev/null 2>&1'], $config['repo_root'], null, 10);

    if (($probe['exit_code'] ?? 1) !== 0) {
        throw new RuntimeException('npx was not found on PATH.');
    }

    $bootstrap = GateProcessRunner::run(
        ['bash', $config['pwcli_path'], 'install-browser'],
        $config['repo_root'],
        null,
        $config['bootstrap_timeout'],
    );
    assertPlaywrightCommandSucceeded($bootstrap, 'Bootstrap Playwright CLI');

    try {
        $result = runPwcliCommand($config, $sessionId, ['open', 'about:blank'], $config['open_timeout']);
        assertPlaywrightCommandSucceeded($result, 'Open browser for dashboard summary render');

        $result = runPwcliCommand($config, $sessionId, ['goto', $config['target_url']], $config['open_timeout']);
        assertPlaywrightCommandSucceeded($result, 'Open dashboard page');

        $result = runPwcliCommand($config, $sessionId, ['run-code', $runCodeSnippet], $config['open_timeout'] + 15);
        assertPlaywrightCommandSucceeded($result, 'Render dashboard summary in browser');

        return \dashboardSummaryBrowserAssertPayload(\dashboardSummaryBrowserParseRunCodeResult($result));
    } finally {
        try {
            runPwcliCommand($config, $sessionId, ['close'], 10);
        } catch (Throwable) {
        }
    }
}

function shouldCollectBrowserRuntimeEvidence(string $mode, bool $suiteFailed): bool
{
    return match ($mode) {
        'off' => false,
        'always' => true,
        'on-failure' => $suiteFailed,
        default => throw new RuntimeException('Unsupported browser evidence mode: ' . $mode),
    };
}

/**
 * @param array<int, string> $failedCheckIds
 * @param array<int, string> $onFailureCheckIds
 */
function shouldCollectBrowserRuntimeEvidenceForChecks(
    string $mode,
    bool $suiteFailed,
    array $failedCheckIds,
    array $onFailureCheckIds,
): bool {
    if (!shouldCollectBrowserRuntimeEvidence($mode, $suiteFailed)) {
        return false;
    }

    if ($mode !== 'on-failure') {
        return true;
    }

    if ($onFailureCheckIds === []) {
        return true;
    }

    $failedLookup = [];
    foreach ($failedCheckIds as $checkId) {
        $normalizedCheckId = trim((string) $checkId);
        if ($normalizedCheckId === '') {
            continue;
        }

        $failedLookup[$normalizedCheckId] = true;
    }

    if ($failedLookup === []) {
        return false;
    }

    foreach ($onFailureCheckIds as $checkId) {
        $normalizedCheckId = trim((string) $checkId);
        if ($normalizedCheckId === '') {
            continue;
        }

        if (isset($failedLookup[$normalizedCheckId])) {
            return true;
        }
    }

    return false;
}

function parseBrowserRuntimeEvidenceMode(mixed $raw): string
{
    if ($raw === null) {
        return 'off';
    }

    if ($raw === false) {
        return 'always';
    }

    $value = is_array($raw) ? end($raw) : $raw;
    $normalized = strtolower(trim((string) $value));

    return match ($normalized) {
        '1', 'true', 'yes', 'on', 'always' => 'always',
        '0', 'false', 'no', 'off' => 'off',
        'on-failure', 'failure', 'fail', 'failed' => 'on-failure',
        default => throw new RuntimeException(
            'Unsupported browser evidence mode "' . $normalized . '". Use off, on-failure, or always.',
        ),
    };
}

function buildDefaultBrowserRuntimeEvidenceArtifactsDir(string $repoRoot): string
{
    return rtrim($repoRoot, '/') . '/storage/logs/ci/dashboard-integration-smoke-browser-' . gmdate('Ymd\THis\Z');
}

function resolveBookingPageTargetUrl(string $baseUrl, string $indexPage): string
{
    $base = rtrim($baseUrl, '/');
    $path = 'booking';
    $indexPage = trim($indexPage, '/');

    if ($indexPage !== '') {
        $path = $indexPage . '/' . $path;
    }

    return $base . '/' . $path;
}

/**
 * @param array{
 *   repo_root:string,
 *   pwcli_path:string,
 *   headed:bool
 * } $config
 * @param array<int, string> $arguments
 * @return array{
 *   command:string,
 *   exit_code:int,
 *   stdout:string,
 *   stderr:string,
 *   duration_ms:float,
 *   timed_out:bool
 * }
 */
function runPwcliCommand(array $config, string $sessionId, array $arguments, int $timeoutSeconds): array
{
    $arguments = prepareConfiguredPlaywrightCommandArguments($arguments, (bool) $config['headed']);
    $command = ['bash', $config['pwcli_path'], ...buildPlaywrightSessionArguments($sessionId), ...$arguments];

    return GateProcessRunner::run($command, $config['repo_root'], null, $timeoutSeconds);
}

/**
 * @param array<string, mixed> $result
 */
function assertPlaywrightCommandSucceeded(array $result, string $context): void
{
    $exitCode = (int) ($result['exit_code'] ?? 1);
    $timedOut = (bool) ($result['timed_out'] ?? false);
    $stdout = trim((string) ($result['stdout'] ?? ''));
    $stderr = trim((string) ($result['stderr'] ?? ''));
    $playwrightError = extractPlaywrightErrorSection($stdout);

    if ($exitCode === 0 && !$timedOut && $playwrightError === null) {
        return;
    }

    if ($playwrightError !== null) {
        throw new GateAssertionException(sprintf('%s reported a Playwright error: %s', $context, $playwrightError));
    }

    if ($timedOut) {
        throw new GateAssertionException(
            $context . ' timed out. ' . substr($stderr !== '' ? $stderr : $stdout, 0, 1200),
        );
    }

    throw new GateAssertionException(
        sprintf(
            '%s failed with exit code %d. %s',
            $context,
            $exitCode,
            substr($stderr !== '' ? $stderr : $stdout, 0, 1200),
        ),
    );
}

function extractPlaywrightErrorSection(string $output): ?string
{
    if (trim($output) === '') {
        return null;
    }

    $matches = [];
    if (preg_match('/(?:^|\R)### Error\s*\R(.+?)(?:\R###\s+[^\r\n]+|\z)/s', $output, $matches) !== 1) {
        return null;
    }

    $message = trim((string) ($matches[1] ?? ''));

    return $message !== '' ? $message : 'Unknown Playwright error.';
}

function resolvePlaywrightArtifactPath(string $output, string $label, string $repoRoot): ?string
{
    if (trim($output) === '') {
        return null;
    }

    $pattern = sprintf('/^\s*-\s+\[%s\]\(([^)]+)\)\s*$/m', preg_quote($label, '/'));
    $matches = [];

    if (preg_match($pattern, $output, $matches) !== 1) {
        return null;
    }

    $path = trim((string) ($matches[1] ?? ''));

    if ($path === '') {
        return null;
    }

    if (preg_match('#^(?:/|[A-Za-z]:[\\\\/])#', $path) === 1) {
        return $path;
    }

    return rtrim($repoRoot, '/') . '/' . ltrim($path, '/');
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

function copyFile(string $source, string $target): void
{
    ensureDirectory(dirname($target));

    if (!copy($source, $target)) {
        throw new RuntimeException(sprintf('Failed to copy artifact from %s to %s.', $source, $target));
    }
}

function writeTextFile(string $path, string $content): void
{
    ensureDirectory(dirname($path));

    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Could not write file: ' . $path);
    }
}

/**
 * @param array<string, mixed> $report
 */
function writeJsonFile(string $path, array $report): void
{
    ensureDirectory(dirname($path));
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Could not write JSON report: ' . $path);
    }
}

function buildBrowserRuntimeEvidenceSessionId(): string
{
    return 'bri-' . gmdate('His') . '-' . bin2hex(random_bytes(2));
}

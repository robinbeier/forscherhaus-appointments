#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/GateAssertions.php';
require_once __DIR__ . '/lib/GateHttpClient.php';
require_once __DIR__ . '/lib/GateProcessRunner.php';
require_once __DIR__ . '/lib/PlaywrightBrowserSelection.php';
require_once __DIR__ . '/lib/PlaywrightCookieRecords.php';
require_once __DIR__ . '/lib/ProviderUiSmokeContract.php';
require_once __DIR__ . '/lib/ProviderUiSmokeCredentials.php';
require_once __DIR__ . '/lib/ProviderUiSmokePdfInspector.php';
require_once __DIR__ . '/lib/ProviderUiSmokeRunCodeResult.php';

use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateProcessRunner;
use ReleaseGate\ProviderUiSmokeContract;
use function ReleaseGate\assertProviderUiSmokeDeployedPreparationView;
use function ReleaseGate\buildPlaywrightSessionArguments;
use function ReleaseGate\inspectProviderUiSmokePdf;
use function ReleaseGate\normalizeProviderUiSmokeSha256;
use function ReleaseGate\normalizeCookieRecordsForPlaywright;
use function ReleaseGate\parseProviderUiSmokeRunCodeResult;
use function ReleaseGate\prepareConfiguredPlaywrightCommandArguments;
use function ReleaseGate\readProviderUiSmokeCredentials;
use function ReleaseGate\assertProviderUiSmokePdfText;
use function ReleaseGate\assertProviderUiSmokePdfTextAlternatives;
use function ReleaseGate\countProviderUiSmokeAppointmentRows;
use function ReleaseGate\countProviderUiSmokePdfFragment;

const PROVIDER_UI_SMOKE_EXIT_SUCCESS = 0;
const PROVIDER_UI_SMOKE_EXIT_ASSERTION_FAILURE = 1;
const PROVIDER_UI_SMOKE_EXIT_RUNTIME_ERROR = 2;

final class ProviderUiSmokeBrowserFlowException extends RuntimeException
{
    /**
     * @param array<string, mixed> $safeDetails
     */
    public function __construct(public readonly array $safeDetails)
    {
        parent::__construct('Provider UI smoke browser flow assertions failed.');
    }
}
$repoRoot = dirname(__DIR__, 2);
$startedAt = microtime(true);
$startedAtUtc = gmdate('c');
$timestamp = gmdate('Ymd\THis\Z');
$defaultOutputPath = $repoRoot . '/storage/logs/release-gate/provider-ui-smoke-' . $timestamp . '.json';
$defaultPwcliPath = $repoRoot . '/scripts/release-gate/playwright/playwright_cli.sh';
$snippetPath = __DIR__ . '/playwright/provider_ui_smoke.js';

$config = null;
$credentials = null;
$httpClient = null;
$sessionId = null;
$tempDirectory = null;
$tempFiles = [];
$checks = [];
$failure = null;
$exitCode = PROVIDER_UI_SMOKE_EXIT_SUCCESS;
$currentCheck = 'configuration';
$cleanupOk = true;

try {
    $config = providerUiSmokeParseOptions($repoRoot, $defaultOutputPath, $defaultPwcliPath);

    if ($config['help']) {
        providerUiSmokePrintUsage();
        exit(PROVIDER_UI_SMOKE_EXIT_SUCCESS);
    }

    if (!putenv('PLAYWRIGHT_MCP_BROWSER=' . $config['browser'])) {
        throw new RuntimeException('Provider UI smoke browser selection could not be applied.');
    }

    $runCheck = static function (string $name, callable $callback) use (&$checks, &$currentCheck): void {
        $currentCheck = $name;
        $started = microtime(true);

        try {
            $details = $callback();
            $checks[] = [
                'name' => $name,
                'status' => 'pass',
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                'details' => providerUiSmokeSafeDetails(is_array($details) ? $details : []),
            ];
            fwrite(STDOUT, '[PASS] ' . $name . PHP_EOL);
        } catch (Throwable $exception) {
            $failedCheck = [
                'name' => $name,
                'status' => 'fail',
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                'error_code' => providerUiSmokeErrorCode($exception),
            ];

            if ($exception instanceof ProviderUiSmokeBrowserFlowException) {
                $failedCheck['details'] = providerUiSmokeSafeDetails($exception->safeDetails);
            }

            $checks[] = $failedCheck;
            fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
            throw $exception;
        }
    };

    $runCheck('readiness_dependencies', static function () use ($config, $snippetPath, $repoRoot): array {
        foreach ([$config['pwcli_path'], $snippetPath] as $requiredFile) {
            if (!is_file($requiredFile) || !is_readable($requiredFile)) {
                throw new RuntimeException('A required provider UI smoke file is missing.');
            }
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Provider UI smoke requires ext-curl.');
        }

        foreach (['npx', 'pdfinfo', 'pdftotext'] as $binary) {
            $probe = GateProcessRunner::run(
                ['bash', '-lc', 'command -v ' . $binary . ' >/dev/null 2>&1'],
                $repoRoot,
                null,
                10,
            );
            providerUiSmokeAssertProcessSucceeded($probe, 'dependency probe');
        }

        $bootstrap = GateProcessRunner::run(
            ['bash', $config['pwcli_path'], 'install-browser'],
            $repoRoot,
            null,
            $config['bootstrap_timeout'],
        );
        providerUiSmokeAssertProcessSucceeded($bootstrap, 'Playwright bootstrap');

        return [
            'dependencies_ready' => true,
            'bootstrap_duration_ms' => (int) round((float) ($bootstrap['duration_ms'] ?? 0)),
        ];
    });

    $runCheck('credentials_contract', static function () use (&$credentials, $config): array {
        $credentials = readProviderUiSmokeCredentials($config['credentials_file']);

        return [
            'credentials_loaded' => true,
            'reserved_username_matched' => true,
        ];
    });

    $runCheck(
        'preparation_four_note_lines_active_deployment',
        static fn(): array => assertProviderUiSmokeDeployedPreparationView(
            $repoRoot . '/application/views/exports/provider_preparation_pdf.php',
            $config['deployed_view_sha256'],
        ),
    );

    $runCheck('auth_login_validate', static function () use (&$httpClient, $config, &$credentials): array {
        if (!is_array($credentials)) {
            throw new RuntimeException('Provider UI smoke credentials were not loaded.');
        }

        $httpClient = new GateHttpClient(
            $config['base_url'],
            $config['index_page'],
            $config['http_timeout'],
            'provider-ui-smoke-gate/1.0',
        );
        $loginPage = $httpClient->get('login');
        GateAssertions::assertStatus($loginPage->statusCode, 200, 'Provider UI smoke login page');

        $loginResponse = $httpClient->post('login/validate', [
            'username' => $credentials['username'],
            'password' => $credentials['password'],
        ]);
        GateAssertions::assertStatus($loginResponse->statusCode, 200, 'Provider UI smoke login validate');
        GateAssertions::assertLoginPayload(
            GateAssertions::decodeJson($loginResponse->body, 'Provider UI smoke login validate'),
        );

        if ($httpClient->cookieRecords() === []) {
            throw new GateAssertionException('Provider UI smoke login did not establish browser cookies.');
        }

        return [
            'login_page_status' => $loginPage->statusCode,
            'login_validate_status' => $loginResponse->statusCode,
            'authenticated' => true,
        ];
    });

    $runCheck('rob_434_customers_containment', static function () use (&$httpClient): array {
        if (!$httpClient instanceof GateHttpClient) {
            throw new RuntimeException('Provider UI smoke HTTP client is not authenticated.');
        }

        $customers = $httpClient->get('customers');
        GateAssertions::assertStatus($customers->statusCode, 403, 'Reserved provider smoke customers access');

        return [
            'customers_status' => $customers->statusCode,
            'customers_access_denied' => true,
        ];
    });

    $runCheck('rob_434_dashboard_response_containment', static function () use (&$httpClient): array {
        if (!$httpClient instanceof GateHttpClient) {
            throw new RuntimeException('Provider UI smoke HTTP client is not authenticated.');
        }

        $dashboard = $httpClient->get('dashboard');
        GateAssertions::assertStatus($dashboard->statusCode, 200, 'Reserved provider smoke dashboard');
        $scriptVars = ProviderUiSmokeContract::extractScriptVars($dashboard->body);

        foreach (ProviderUiSmokeContract::FORBIDDEN_SCRIPT_VAR_KEYS as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $scriptVars)) {
                throw new GateAssertionException('Provider dashboard response exposes a forbidden integration marker.');
            }
        }

        return [
            'dashboard_status' => $dashboard->statusCode,
            'forbidden_markers_absent' => true,
        ];
    });

    $runCheck('browser_session_prepare', static function () use (
        &$httpClient,
        &$sessionId,
        &$tempDirectory,
        &$tempFiles,
        $config,
        $repoRoot,
    ): array {
        if (!$httpClient instanceof GateHttpClient) {
            throw new RuntimeException('Provider UI smoke HTTP client is not authenticated.');
        }

        $tempDirectory = providerUiSmokeCreateTempDirectory();

        if (!putenv('PLAYWRIGHT_MCP_OUTPUT_DIR=' . $tempDirectory)) {
            throw new RuntimeException('Provider UI smoke browser output directory could not be isolated.');
        }

        $statePath = $tempDirectory . '/storage-state.json';
        $tempFiles[] = $statePath;
        $dashboardUrl = providerUiSmokeBuildAppUrl($config['base_url'], $config['index_page'], 'dashboard');
        $cookies = normalizeCookieRecordsForPlaywright($httpClient->cookieRecords(), $dashboardUrl);

        if ($cookies === []) {
            throw new GateAssertionException('Provider UI smoke browser storage state has no cookies.');
        }

        $state = json_encode(
            [
                'cookies' => $cookies,
                'origins' => [],
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($statePath, $state) === false || !chmod($statePath, 0600)) {
            throw new RuntimeException('Provider UI smoke browser storage state could not be written safely.');
        }

        if ((((int) fileperms($statePath)) & 0777) !== 0600) {
            throw new RuntimeException('Provider UI smoke browser storage state permissions are unsafe.');
        }

        $sessionId = ProviderUiSmokeContract::buildBrowserSessionId();
        $open = providerUiSmokeRunPwcli(
            $config,
            $sessionId,
            ['open', 'about:blank'],
            $repoRoot,
            $config['open_timeout'],
        );
        providerUiSmokeAssertProcessSucceeded($open, 'Open provider UI smoke browser');

        try {
            $stateLoad = providerUiSmokeRunPwcli(
                $config,
                $sessionId,
                ['state-load', $statePath],
                $repoRoot,
                $config['open_timeout'],
            );
            providerUiSmokeAssertProcessSucceeded($stateLoad, 'Load provider UI smoke browser storage state');
        } finally {
            if (is_file($statePath) && !unlink($statePath)) {
                throw new RuntimeException('Provider UI smoke browser storage state could not be removed.');
            }
        }

        return [
            'temporary_directory_private' => true,
            'storage_state_private' => true,
            'storage_state_removed' => !is_file($statePath),
            'browser_opened' => true,
        ];
    });

    $browserResult = null;
    $runCheck('provider_dashboard_browser_flow', static function () use (
        &$browserResult,
        &$sessionId,
        &$tempDirectory,
        &$tempFiles,
        $config,
        $snippetPath,
        $repoRoot,
    ): array {
        if (!is_string($sessionId) || !is_string($tempDirectory)) {
            throw new RuntimeException('Provider UI smoke browser session was not prepared.');
        }

        $preparationPdfPath = $tempDirectory . '/preparation.pdf';
        $parentPdfPath = $tempDirectory . '/parent.pdf';
        $emptyPreparationPdfPath = $tempDirectory . '/empty-preparation.pdf';
        array_push($tempFiles, $preparationPdfPath, $parentPdfPath, $emptyPreparationPdfPath);

        $dashboardUrl = providerUiSmokeBuildAppUrl($config['base_url'], $config['index_page'], 'dashboard');
        $providerMetricsUrl = providerUiSmokeBuildAppUrl(
            $config['base_url'],
            $config['index_page'],
            'dashboard/provider_metrics',
        );
        $preparationPdfUrl = providerUiSmokeBuildAppUrl(
            $config['base_url'],
            $config['index_page'],
            'dashboard/export/provider-preparation.pdf',
        );
        $parentPdfUrl = providerUiSmokeBuildAppUrl(
            $config['base_url'],
            $config['index_page'],
            'dashboard/export/provider-parent-appointments.pdf',
        );
        $snippetConfig = [
            'dashboard_url' => $dashboardUrl,
            'provider_metrics_url' => $providerMetricsUrl,
            'preparation_pdf_url' => $preparationPdfUrl,
            'parent_pdf_url' => $parentPdfUrl,
            'allowed_origin' => providerUiSmokeBuildOrigin($config['base_url']),
            'dashboard_route_path' => providerUiSmokeUrlPath($dashboardUrl),
            'metrics_route_path' => providerUiSmokeUrlPath($providerMetricsUrl),
            'preparation_pdf_route_path' => providerUiSmokeUrlPath($preparationPdfUrl),
            'parent_pdf_route_path' => providerUiSmokeUrlPath($parentPdfUrl),
            'asset_path_prefix' => providerUiSmokeBuildBasePath($config['base_url']) . '/assets/',
            'favicon_path' => providerUiSmokeBuildBasePath($config['base_url']) . '/assets/img/favicon.ico',
            'preparation_pdf_path' => $preparationPdfPath,
            'parent_pdf_path' => $parentPdfPath,
            'empty_preparation_pdf_path' => $emptyPreparationPdfPath,
            'open_timeout_ms' => $config['open_timeout'] * 1000,
            'download_timeout_ms' => $config['download_timeout'] * 1000,
            'forbidden_script_var_keys' => ProviderUiSmokeContract::FORBIDDEN_SCRIPT_VAR_KEYS,
            'customer_last_name' => ProviderUiSmokeContract::CUSTOMER_LAST_NAME,
            'primary_start_date' => ProviderUiSmokeContract::PRIMARY_START_DATE,
            'primary_end_date' => ProviderUiSmokeContract::PRIMARY_END_DATE,
            'booked_start_time' => ProviderUiSmokeContract::BOOKED_START_TIME,
            'booked_end_time' => ProviderUiSmokeContract::BOOKED_END_TIME,
            'empty_start_date' => ProviderUiSmokeContract::EMPTY_START_DATE,
            'empty_end_date' => ProviderUiSmokeContract::EMPTY_END_DATE,
            'restore_start_date' => ProviderUiSmokeContract::RESTORE_START_DATE,
            'restore_end_date' => ProviderUiSmokeContract::RESTORE_END_DATE,
        ];
        $snippet = providerUiSmokeResolveSnippet($snippetPath, $snippetConfig);
        $runCode = providerUiSmokeRunPwcli(
            $config,
            $sessionId,
            ['run-code', $snippet],
            $repoRoot,
            max($config['open_timeout'] * 3 + $config['download_timeout'] * 3, 120),
        );
        providerUiSmokeAssertProcessSucceeded($runCode, 'Provider UI smoke browser flow');
        $browserResult = parseProviderUiSmokeRunCodeResult($runCode);

        if (($browserResult['ok'] ?? false) !== true) {
            throw new ProviderUiSmokeBrowserFlowException($browserResult);
        }

        $close = providerUiSmokeRunPwcli(
            $config,
            $sessionId,
            ['close'],
            $repoRoot,
            max(15, (int) $config['open_timeout']),
        );
        providerUiSmokeAssertProcessSucceeded($close, 'Close provider UI smoke browser');
        $sessionId = null;

        foreach ([$preparationPdfPath, $parentPdfPath, $emptyPreparationPdfPath] as $pdfPath) {
            if (!is_file($pdfPath) || !chmod($pdfPath, 0600) || (((int) fileperms($pdfPath)) & 0777) !== 0600) {
                throw new RuntimeException('Provider UI smoke PDF permissions could not be secured.');
            }
        }

        return [...$browserResult, 'download_permissions_private' => true, 'browser_closed' => true];
    });

    $runCheck('preparation_pdf_live_content', static function () use (&$tempDirectory, &$tempFiles, $config): array {
        if (!is_string($tempDirectory)) {
            throw new RuntimeException('Provider UI smoke temporary directory is unavailable.');
        }

        $textPath = $tempDirectory . '/preparation.txt';
        $tempFiles[] = $textPath;
        $inspection = inspectProviderUiSmokePdf(
            $tempDirectory . '/preparation.pdf',
            $textPath,
            $config['min_pdf_bytes'],
            $config['pdf_tool_timeout'],
        );

        if (!$inspection['landscape']) {
            throw new GateAssertionException('Provider preparation PDF is not landscape.');
        }

        $parentName = ProviderUiSmokeContract::CUSTOMER_FIRST_NAME . ' ' . ProviderUiSmokeContract::CUSTOMER_LAST_NAME;
        $appointmentRows = countProviderUiSmokeAppointmentRows($inspection['text']);
        if (
            $inspection['pages'] !== 1 ||
            $appointmentRows !== 1 ||
            countProviderUiSmokePdfFragment($inspection['text'], $parentName) !== 1
        ) {
            throw new GateAssertionException('Provider preparation PDF is not limited to one synthetic appointment.');
        }

        assertProviderUiSmokePdfText(
            $inspection['text'],
            ['Vorbereitung Klassenleitungsgespräche', $parentName],
            ProviderUiSmokeContract::forbiddenPdfFragments(),
            'Provider preparation PDF',
        );
        assertProviderUiSmokePdfTextAlternatives(
            $inspection['text'],
            [
                ProviderUiSmokeContract::expectedDateVariants(ProviderUiSmokeContract::PRIMARY_START_DATE),
                ProviderUiSmokeContract::expectedTimeVariants(ProviderUiSmokeContract::BOOKED_START_TIME),
                ProviderUiSmokeContract::expectedTimeVariants(ProviderUiSmokeContract::BOOKED_END_TIME),
            ],
            'Provider preparation PDF',
        );

        return [
            'pdf_magic_valid' => true,
            'pdf_bytes' => $inspection['bytes'],
            'pdf_pages' => $inspection['pages'],
            'appointment_rows' => $appointmentRows,
            'landscape' => true,
            'required_content_present' => true,
            'forbidden_content_absent' => true,
        ];
    });

    $runCheck('parent_pdf_live_regression', static function () use (&$tempDirectory, &$tempFiles, $config): array {
        if (!is_string($tempDirectory)) {
            throw new RuntimeException('Provider UI smoke temporary directory is unavailable.');
        }

        $textPath = $tempDirectory . '/parent.txt';
        $tempFiles[] = $textPath;
        $inspection = inspectProviderUiSmokePdf(
            $tempDirectory . '/parent.pdf',
            $textPath,
            $config['min_pdf_bytes'],
            $config['pdf_tool_timeout'],
        );
        $parentName = ProviderUiSmokeContract::CUSTOMER_FIRST_NAME . ' ' . ProviderUiSmokeContract::CUSTOMER_LAST_NAME;
        $appointmentRows = countProviderUiSmokeAppointmentRows($inspection['text']);
        if (
            $inspection['pages'] !== 1 ||
            $inspection['landscape'] ||
            $appointmentRows !== 1 ||
            countProviderUiSmokePdfFragment($inspection['text'], $parentName) !== 1
        ) {
            throw new GateAssertionException(
                'Provider parent PDF layout or synthetic appointment containment regressed.',
            );
        }

        assertProviderUiSmokePdfText(
            $inspection['text'],
            ['Terminübersicht für Eltern', $parentName],
            ProviderUiSmokeContract::forbiddenPdfFragments(),
            'Provider parent PDF',
        );
        assertProviderUiSmokePdfTextAlternatives(
            $inspection['text'],
            [
                ProviderUiSmokeContract::expectedDateVariants(ProviderUiSmokeContract::PRIMARY_START_DATE),
                ProviderUiSmokeContract::expectedTimeVariants(ProviderUiSmokeContract::BOOKED_START_TIME),
                ProviderUiSmokeContract::expectedTimeVariants(ProviderUiSmokeContract::BOOKED_END_TIME),
            ],
            'Provider parent PDF',
        );

        return [
            'pdf_magic_valid' => true,
            'pdf_bytes' => $inspection['bytes'],
            'pdf_pages' => $inspection['pages'],
            'appointment_rows' => $appointmentRows,
            'portrait' => true,
            'required_content_present' => true,
            'forbidden_content_absent' => true,
        ];
    });

    $runCheck('empty_preparation_pdf_live_content', static function () use (
        &$tempDirectory,
        &$tempFiles,
        $config,
    ): array {
        if (!is_string($tempDirectory)) {
            throw new RuntimeException('Provider UI smoke temporary directory is unavailable.');
        }

        $textPath = $tempDirectory . '/empty-preparation.txt';
        $tempFiles[] = $textPath;
        $inspection = inspectProviderUiSmokePdf(
            $tempDirectory . '/empty-preparation.pdf',
            $textPath,
            $config['min_pdf_bytes'],
            $config['pdf_tool_timeout'],
        );
        $parentName = ProviderUiSmokeContract::CUSTOMER_FIRST_NAME . ' ' . ProviderUiSmokeContract::CUSTOMER_LAST_NAME;

        if (!$inspection['landscape']) {
            throw new GateAssertionException('Empty provider preparation PDF is not landscape.');
        }

        $appointmentRows = countProviderUiSmokeAppointmentRows($inspection['text']);
        if ($inspection['pages'] !== 1 || $appointmentRows !== 0) {
            throw new GateAssertionException('Empty provider preparation PDF contains unexpected appointment rows.');
        }

        assertProviderUiSmokePdfText(
            $inspection['text'],
            ['Vorbereitung Klassenleitungsgespräche', 'Keine gebuchten Termine im Zeitraum.'],
            [$parentName, ...ProviderUiSmokeContract::forbiddenPdfFragments()],
            'Empty provider preparation PDF',
        );

        return [
            'pdf_magic_valid' => true,
            'pdf_bytes' => $inspection['bytes'],
            'pdf_pages' => $inspection['pages'],
            'appointment_rows' => $appointmentRows,
            'landscape' => true,
            'empty_state_present' => true,
            'fixture_parent_absent' => true,
            'forbidden_content_absent' => true,
        ];
    });
} catch (Throwable $exception) {
    $exitCode =
        $exception instanceof GateAssertionException || $exception instanceof ProviderUiSmokeBrowserFlowException
            ? PROVIDER_UI_SMOKE_EXIT_ASSERTION_FAILURE
            : PROVIDER_UI_SMOKE_EXIT_RUNTIME_ERROR;
    $failure = [
        'check' => $currentCheck,
        'error_code' => providerUiSmokeErrorCode($exception),
    ];
}

if (is_string($sessionId) && is_array($config)) {
    try {
        $close = providerUiSmokeRunPwcli(
            $config,
            $sessionId,
            ['close'],
            $repoRoot,
            max(15, (int) $config['open_timeout']),
        );
        if ((int) ($close['exit_code'] ?? 1) !== 0 || (bool) ($close['timed_out'] ?? false)) {
            $cleanupOk = false;
        }
    } catch (Throwable) {
        $cleanupOk = false;
    }
}

foreach (array_unique($tempFiles) as $tempFile) {
    if (is_string($tempFile) && is_file($tempFile) && !@unlink($tempFile)) {
        $cleanupOk = false;
    }
}

if (is_string($tempDirectory) && is_dir($tempDirectory) && !providerUiSmokeRemovePrivateTempDirectory($tempDirectory)) {
    $cleanupOk = false;
}

if (!$cleanupOk && $exitCode === PROVIDER_UI_SMOKE_EXIT_SUCCESS) {
    $exitCode = PROVIDER_UI_SMOKE_EXIT_RUNTIME_ERROR;
    $failure = [
        'check' => 'cleanup',
        'error_code' => 'cleanup_failed',
    ];
}

$finishedAtUtc = gmdate('c');
$passed = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'pass'));
$failed = count($checks) - $passed;
$report = [
    'gate' => 'provider_ui_smoke',
    'meta' => [
        'started_at_utc' => $startedAtUtc,
        'finished_at_utc' => $finishedAtUtc,
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ],
    'checks' => $checks,
    'cleanup' => [
        'status' => $cleanupOk ? 'pass' : 'fail',
        'temporary_artifacts_removed' => $cleanupOk,
    ],
    'summary' => [
        'passed' => $passed,
        'failed' => $failed,
        'exit_code' => $exitCode,
    ],
];

if (is_array($failure)) {
    $report['failure'] = $failure;
}

$outputPath = is_array($config) ? $config['output_json'] : $defaultOutputPath;

try {
    providerUiSmokeWriteSafeReport($outputPath, $report);
} catch (Throwable) {
    fwrite(STDERR, '[FAIL] provider_ui_smoke_report' . PHP_EOL);
    $exitCode = PROVIDER_UI_SMOKE_EXIT_RUNTIME_ERROR;
}

if ($exitCode === PROVIDER_UI_SMOKE_EXIT_SUCCESS) {
    fwrite(STDOUT, sprintf('[PASS] Provider UI smoke passed (%d checks).%s', $passed, PHP_EOL));
} else {
    fwrite(STDERR, sprintf('[FAIL] Provider UI smoke failed (exit %d).%s', $exitCode, PHP_EOL));
}

exit($exitCode);

/**
 * @return array<string, mixed>
 */
function providerUiSmokeParseOptions(string $repoRoot, string $defaultOutputPath, string $defaultPwcliPath): array
{
    $options = getopt('', [
        'help',
        'base-url:',
        'index-page::',
        'credentials-file:',
        'deployed-view-sha256:',
        'pwcli-path::',
        'browser::',
        'bootstrap-timeout::',
        'http-timeout::',
        'open-timeout::',
        'download-timeout::',
        'pdf-tool-timeout::',
        'min-pdf-bytes::',
        'headed::',
        'output-json::',
    ]);

    if (!is_array($options)) {
        throw new InvalidArgumentException('Provider UI smoke CLI options could not be parsed.');
    }

    if (array_key_exists('help', $options)) {
        return ['help' => true];
    }

    $baseUrl = providerUiSmokeRequiredOption($options, 'base-url');
    providerUiSmokeValidateBaseUrl($baseUrl);
    $indexPage = providerUiSmokeOptionalValue(
        $options,
        'index-page',
        providerUiSmokeHasExplicitEmptyOption('index-page') ? '' : 'index.php',
    );
    $credentialsFile = providerUiSmokeRequiredOption($options, 'credentials-file');
    $deployedViewSha256 = normalizeProviderUiSmokeSha256(
        providerUiSmokeRequiredOption($options, 'deployed-view-sha256'),
    );
    $pwcliPath = providerUiSmokeResolvePath(
        providerUiSmokeOptionalValue($options, 'pwcli-path', $defaultPwcliPath),
        $repoRoot,
    );
    $outputJson = providerUiSmokeResolvePath(
        providerUiSmokeOptionalValue($options, 'output-json', $defaultOutputPath),
        $repoRoot,
    );
    $configuredBrowser = trim((string) getenv('PLAYWRIGHT_MCP_BROWSER'));
    $defaultBrowser =
        $configuredBrowser !== '' ? $configuredBrowser : (PHP_OS_FAMILY === 'Darwin' ? 'chrome' : 'firefox');
    $browser = strtolower(providerUiSmokeOptionalValue($options, 'browser', $defaultBrowser));

    if (!in_array($browser, ['chrome', 'firefox', 'webkit', 'msedge'], true)) {
        throw new InvalidArgumentException('Provider UI smoke browser option is invalid.');
    }

    return [
        'help' => false,
        'base_url' => rtrim($baseUrl, '/'),
        'index_page' => trim($indexPage, '/'),
        'credentials_file' => $credentialsFile,
        'deployed_view_sha256' => $deployedViewSha256,
        'pwcli_path' => $pwcliPath,
        'browser' => $browser,
        'bootstrap_timeout' => providerUiSmokePositiveInt($options, 'bootstrap-timeout', 90),
        'http_timeout' => providerUiSmokePositiveInt($options, 'http-timeout', 20),
        'open_timeout' => providerUiSmokePositiveInt($options, 'open-timeout', 30),
        'download_timeout' => providerUiSmokePositiveInt($options, 'download-timeout', 30),
        'pdf_tool_timeout' => providerUiSmokePositiveInt($options, 'pdf-tool-timeout', 20),
        'min_pdf_bytes' => providerUiSmokePositiveInt($options, 'min-pdf-bytes', 1024),
        'headed' => providerUiSmokeBooleanOption($options['headed'] ?? null),
        'output_json' => $outputJson,
    ];
}

/**
 * @param array<string, mixed> $options
 */
function providerUiSmokeRequiredOption(array $options, string $name): string
{
    if (!array_key_exists($name, $options)) {
        throw new InvalidArgumentException('A required provider UI smoke option is missing.');
    }

    $value = trim((string) (is_array($options[$name]) ? end($options[$name]) : $options[$name]));
    if ($value === '') {
        throw new InvalidArgumentException('A required provider UI smoke option is empty.');
    }

    return $value;
}

/**
 * @param array<string, mixed> $options
 */
function providerUiSmokeOptionalValue(array $options, string $name, string $default): string
{
    if (!array_key_exists($name, $options)) {
        return $default;
    }

    $value = $options[$name];
    if ($value === false) {
        return '';
    }

    return trim((string) (is_array($value) ? end($value) : $value));
}

/**
 * @param array<string, mixed> $options
 */
function providerUiSmokePositiveInt(array $options, string $name, int $default): int
{
    $raw = array_key_exists($name, $options)
        ? (is_array($options[$name])
            ? end($options[$name])
            : $options[$name])
        : $default;
    $value = trim((string) $raw);

    if ($value === '' || !ctype_digit($value) || (int) $value <= 0) {
        throw new InvalidArgumentException('A provider UI smoke timeout/size option is invalid.');
    }

    return (int) $value;
}

function providerUiSmokeBooleanOption(mixed $raw): bool
{
    if ($raw === null) {
        return false;
    }
    if ($raw === false) {
        return true;
    }

    $normalized = strtolower(trim((string) (is_array($raw) ? end($raw) : $raw)));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    throw new InvalidArgumentException('Provider UI smoke headed option is invalid.');
}

function providerUiSmokeHasExplicitEmptyOption(string $name): bool
{
    foreach ($_SERVER['argv'] ?? [] as $argument) {
        if ($argument === '--' . $name . '=') {
            return true;
        }
    }

    return false;
}

function providerUiSmokeValidateBaseUrl(string $baseUrl): void
{
    $parts = parse_url($baseUrl);
    if (
        filter_var($baseUrl, FILTER_VALIDATE_URL) === false ||
        !is_array($parts) ||
        !isset($parts['scheme'], $parts['host']) ||
        !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true) ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        isset($parts['query']) ||
        isset($parts['fragment'])
    ) {
        throw new InvalidArgumentException('Provider UI smoke base URL is invalid.');
    }
}

function providerUiSmokeResolvePath(string $path, string $repoRoot): string
{
    if ($path === '') {
        throw new InvalidArgumentException('Provider UI smoke path option is empty.');
    }

    return str_starts_with($path, '/') ? $path : rtrim($repoRoot, '/') . '/' . ltrim($path, '/');
}

function providerUiSmokeBuildAppUrl(string $baseUrl, string $indexPage, string $route): string
{
    $segments = [rtrim($baseUrl, '/')];
    if (trim($indexPage, '/') !== '') {
        $segments[] = trim($indexPage, '/');
    }
    $segments[] = ltrim($route, '/');

    return implode('/', $segments);
}

function providerUiSmokeBuildBasePath(string $baseUrl): string
{
    $path = trim((string) (parse_url($baseUrl, PHP_URL_PATH) ?? ''));

    return $path === '' || $path === '/' ? '' : '/' . trim($path, '/');
}

function providerUiSmokeBuildOrigin(string $url): string
{
    $parts = parse_url($url);

    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        throw new InvalidArgumentException('Provider UI smoke URL origin is invalid.');
    }

    $origin = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];

    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }

    return $origin;
}

function providerUiSmokeUrlPath(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);

    if (!is_string($path) || $path === '' || $path[0] !== '/') {
        throw new InvalidArgumentException('Provider UI smoke URL path is invalid.');
    }

    return $path;
}

function providerUiSmokeCreateTempDirectory(): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = rtrim(sys_get_temp_dir(), '/') . '/provider-ui-smoke-' . bin2hex(random_bytes(8));

        if (@mkdir($path, 0700) && chmod($path, 0700)) {
            if ((((int) fileperms($path)) & 0777) !== 0700) {
                @rmdir($path);
                break;
            }

            return $path;
        }
    }

    throw new RuntimeException('Provider UI smoke private temporary directory could not be created.');
}

function providerUiSmokeRemovePrivateTempDirectory(string $path, bool $requirePrivateRoot = true): bool
{
    if (!is_dir($path) || is_link($path) || ($requirePrivateRoot && (((int) fileperms($path)) & 0777) !== 0700)) {
        return false;
    }

    $entries = scandir($path);

    if (!is_array($entries)) {
        return false;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = $path . '/' . $entry;

        if (is_dir($entryPath) && !is_link($entryPath)) {
            if (!providerUiSmokeRemovePrivateTempDirectory($entryPath, false)) {
                return false;
            }

            continue;
        }

        if (!@unlink($entryPath)) {
            return false;
        }
    }

    return @rmdir($path);
}

/**
 * @param array<string, mixed> $config
 * @param list<string> $arguments
 * @return array<string, mixed>
 */
function providerUiSmokeRunPwcli(
    array $config,
    string $sessionId,
    array $arguments,
    string $repoRoot,
    int $timeoutSeconds,
): array {
    $arguments = prepareConfiguredPlaywrightCommandArguments($arguments, (bool) $config['headed']);

    return GateProcessRunner::run(
        ['bash', (string) $config['pwcli_path'], ...buildPlaywrightSessionArguments($sessionId), ...$arguments],
        $repoRoot,
        null,
        $timeoutSeconds,
    );
}

/**
 * @param array<string, mixed> $result
 */
function providerUiSmokeAssertProcessSucceeded(array $result, string $context): void
{
    if (
        (int) ($result['exit_code'] ?? 1) !== 0 ||
        (bool) ($result['timed_out'] ?? false) ||
        preg_match('/(?:^|\R)### Error(?:\R|\z)/', (string) ($result['stdout'] ?? '')) === 1
    ) {
        throw new RuntimeException($context . ' failed.');
    }
}

/**
 * @param array<string, mixed> $snippetConfig
 */
function providerUiSmokeResolveSnippet(string $snippetPath, array $snippetConfig): string
{
    $snippet = file_get_contents($snippetPath);
    if (!is_string($snippet) || trim($snippet) === '') {
        throw new RuntimeException('Provider UI smoke Playwright snippet is empty.');
    }

    $placeholder = '__PROVIDER_UI_SMOKE_CONFIG__';
    if (substr_count($snippet, $placeholder) !== 1) {
        throw new RuntimeException('Provider UI smoke Playwright snippet placeholder is invalid.');
    }

    $resolved = str_replace(
        $placeholder,
        json_encode($snippetConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $snippet,
    );
    $resolved = preg_replace('/;\s*\z/', '', trim($resolved)) ?? trim($resolved);

    if ($resolved === '' || str_contains($resolved, $placeholder)) {
        throw new RuntimeException('Provider UI smoke Playwright snippet could not be resolved.');
    }

    return $resolved;
}

/**
 * @param array<string, mixed> $details
 * @return array<string, bool|int|float>
 */
function providerUiSmokeSafeDetails(array $details): array
{
    $safe = [];

    foreach ($details as $key => $value) {
        if (!is_string($key) || !preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new RuntimeException('Provider UI smoke check details contain an unsafe key.');
        }

        if (!is_bool($value) && !is_int($value) && !is_float($value)) {
            throw new RuntimeException('Provider UI smoke check details contain an unsafe value.');
        }

        $safe[$key] = $value;
    }

    return $safe;
}

function providerUiSmokeErrorCode(Throwable $exception): string
{
    return match (true) {
        $exception instanceof GateAssertionException,
        $exception instanceof ProviderUiSmokeBrowserFlowException
            => 'assertion_failed',
        $exception instanceof InvalidArgumentException => 'invalid_configuration',
        default => 'runtime_error',
    };
}

/**
 * @param array<string, mixed> $report
 */
function providerUiSmokeWriteSafeReport(string $path, array $report): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Provider UI smoke report directory could not be created.');
    }

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Provider UI smoke report could not be written.');
    }

    @chmod($path, 0640);
}

function providerUiSmokePrintUsage(): void
{
    $lines = [
        'Provider UI Smoke Gate',
        '',
        'Usage:',
        '  php scripts/release-gate/provider_ui_smoke.php --base-url=URL --credentials-file=- \\',
        '    --deployed-view-sha256=HEX [options]',
        '',
        'Required:',
        '  --base-url=URL',
        '  --credentials-file=-|PATH      INI on stdin, or a root-owned regular file without group/other access',
        '  --deployed-view-sha256=HEX     Active production preparation-view SHA-256 from the ops wrapper',
        '',
        'Credential INI (exactly these two keys):',
        '  PROVIDER_UI_SMOKE_USERNAME=reserved-account',
        '  PROVIDER_UI_SMOKE_PASSWORD=strong-random-secret',
        '',
        'Optional:',
        '  --index-page=VALUE             Default: index.php; use --index-page= for rewrite mode',
        '  --pwcli-path=PATH',
        '  --browser=NAME                 Default: chrome on macOS, firefox elsewhere',
        '  --bootstrap-timeout=SECONDS    Default: 90',
        '  --http-timeout=SECONDS         Default: 20',
        '  --open-timeout=SECONDS         Default: 30',
        '  --download-timeout=SECONDS     Default: 30',
        '  --pdf-tool-timeout=SECONDS     Default: 20',
        '  --min-pdf-bytes=BYTES          Default: 1024',
        '  --headed[=0|1]',
        '  --output-json=PATH',
        '  --help',
        '',
        'Exit codes: 0 success, 1 assertion failure, 2 runtime/configuration error',
    ];

    fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
}


<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CustomersUiSmokeContract.php';
require_once __DIR__ . '/lib/CustomersUiSmokeGateRuntime.php';
require_once __DIR__ . '/lib/GateAssertions.php';
require_once __DIR__ . '/lib/GateHttpClient.php';
require_once __DIR__ . '/lib/GateProcessRunner.php';
require_once __DIR__ . '/lib/PlaywrightBrowserSelection.php';
require_once __DIR__ . '/lib/PlaywrightCookieRecords.php';
require_once __DIR__ . '/lib/PlaywrightRunCodePayload.php';

use ReleaseGate\CustomersUiSmokeContract;
use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateProcessRunner;
use function ReleaseGate\buildPlaywrightSessionArguments;
use function ReleaseGate\customersUiSmokeFinalizeCleanup;
use function ReleaseGate\customersUiSmokeStorageStatesRemoved;
use function ReleaseGate\customersUiSmokeTemporaryArtifactsRemoved;
use function ReleaseGate\customersUiSmokeWithStorageState;
use function ReleaseGate\normalizeCookieRecordsForPlaywright;
use function ReleaseGate\parsePlaywrightRunCodeJsonPayload;
use function ReleaseGate\prepareConfiguredPlaywrightCommandArguments;

const CUSTOMERS_UI_SMOKE_SUCCESS = 0;
const CUSTOMERS_UI_SMOKE_ASSERTION_FAILURE = 1;
const CUSTOMERS_UI_SMOKE_RUNTIME_FAILURE = 2;

$repoRoot = dirname(__DIR__, 2);
$snippetPath = __DIR__ . '/playwright/customers_ui_smoke.js';
try {
    $config = customersUiSmokeParseOptions($repoRoot);
} catch (Throwable) {
    fwrite(STDERR, 'customers_ui_smoke status=fail failure_code=runtime_error cleanup=pass' . PHP_EOL);
    exit(CUSTOMERS_UI_SMOKE_RUNTIME_FAILURE);
}
$startedAt = microtime(true);
$checks = [];
$sessions = [];
$tempDirectory = null;
$cleanupOk = true;
$storageStatesRemoved = true;
$temporaryArtifactsRemoved = true;
$exitCode = CUSTOMERS_UI_SMOKE_SUCCESS;
$failureCode = null;
$credentials = null;

$runCheck = static function (string $name, callable $callback) use (&$checks): array {
    $started = microtime(true);

    try {
        $details = $callback();
    } catch (Throwable $exception) {
        $checks[] = [
            'name' => $name,
            'status' => 'fail',
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'details' => ['assertion_failure' => $exception instanceof GateAssertionException],
        ];

        throw $exception;
    }

    $safeDetails = [];

    foreach ($details as $key => $value) {
        if (!is_string($key) || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
            throw new RuntimeException('Customers UI smoke check detail key is unsafe.');
        }

        if (!is_bool($value) && !is_int($value) && !is_float($value)) {
            throw new RuntimeException('Customers UI smoke check detail value is unsafe.');
        }

        $safeDetails[$key] = $value;
    }

    $checks[] = [
        'name' => $name,
        'status' => 'pass',
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'details' => $safeDetails,
    ];

    return $details;
};

try {
    $runCheck('runtime_preflight', static function () use ($config, $repoRoot, $snippetPath): array {
        foreach ([$config['pwcli_path'], $snippetPath] as $path) {
            if (!is_file($path) || is_link($path) || !is_readable($path)) {
                throw new RuntimeException('Customers UI smoke runtime file is unavailable or unsafe.');
            }
        }

        $bootstrap = GateProcessRunner::run(
            ['bash', $config['pwcli_path'], 'install-browser'],
            $repoRoot,
            null,
            $config['bootstrap_timeout'],
        );
        customersUiSmokeAssertProcessSucceeded($bootstrap, 'Playwright bootstrap');

        return ['dependencies_ready' => true, 'browser_bootstrap_ok' => true];
    });

    $runCheck('credentials_contract', static function () use (&$credentials, $config): array {
        $credentials = CustomersUiSmokeContract::readCredentials($config['credentials_file']);

        return ['credential_schema_valid' => true, 'reserved_role_count' => count($credentials['usernames'])];
    });

    /** @var array<string, GateHttpClient> $clients */
    $clients = [];

    foreach (CustomersUiSmokeContract::USERNAMES_BY_ROLE as $role => $_username) {
        $runCheck('http_role_' . $role, static function () use (&$clients, &$credentials, $config, $role): array {
            if (!is_array($credentials)) {
                throw new RuntimeException('Customers UI smoke credentials were not loaded.');
            }

            $client = new GateHttpClient(
                $config['base_url'],
                $config['index_page'],
                $config['http_timeout'],
                'customers-ui-smoke-gate/1.0',
            );
            $loginPage = $client->get('login');
            GateAssertions::assertStatus($loginPage->statusCode, 200, 'Customers UI smoke login page');
            $login = $client->post('login/validate', [
                'username' => $credentials['usernames'][$role],
                'password' => $credentials['password'],
            ]);
            GateAssertions::assertStatus($login->statusCode, 200, 'Customers UI smoke login validate');
            customersUiSmokeAssertForbiddenAbsent([], $login->body, $credentials['password']);
            GateAssertions::assertLoginPayload(GateAssertions::decodeJson($login->body, 'Customers UI smoke login'));

            if ($client->cookieRecords() === []) {
                throw new GateAssertionException('Customers UI smoke login did not establish cookies.');
            }

            $customers = $client->get('customers');
            $authorized = in_array($role, CustomersUiSmokeContract::AUTHORIZED_ROLES, true);
            GateAssertions::assertStatus(
                $customers->statusCode,
                $authorized ? 200 : 403,
                'Customers UI smoke Customers view role contract',
            );

            if (!$authorized) {
                $search = $client->post('customers/search', [
                    'csrf_token' => 'reserved-negative-role',
                    'keyword' => CustomersUiSmokeContract::SEARCH_MARKER,
                    'limit' => 20,
                    'offset' => null,
                ]);
                GateAssertions::assertStatus(
                    $search->statusCode,
                    403,
                    'Customers UI smoke denied search role contract',
                );

                return [
                    'login_status_ok' => true,
                    'view_denied' => true,
                    'search_denied' => true,
                ];
            }

            $nonAllowlisted = $client->get('dashboard');
            GateAssertions::assertStatus(
                $nonAllowlisted->statusCode,
                403,
                'Customers UI smoke non-allowlisted route boundary',
            );

            $scriptVars = CustomersUiSmokeContract::extractScriptVars($customers->body);
            customersUiSmokeAssertForbiddenAbsent($scriptVars, $customers->body, $credentials['password']);
            $csrfToken = $scriptVars['csrf_token'] ?? null;

            if (!is_string($csrfToken) || $csrfToken === '') {
                throw new GateAssertionException('Customers UI smoke response has no CSRF token.');
            }

            $unsafeSearch = $client->post('customers/search', [
                'csrf_token' => $csrfToken,
                'keyword' => CustomersUiSmokeContract::SEARCH_MARKER . '-not-allowed',
                'limit' => 20,
                'offset' => null,
            ]);
            GateAssertions::assertStatus($unsafeSearch->statusCode, 403, 'Customers UI smoke unsafe search boundary');

            $customers = $client->get('customers');
            GateAssertions::assertStatus($customers->statusCode, 200, 'Customers UI smoke Customers refresh');
            $scriptVars = CustomersUiSmokeContract::extractScriptVars($customers->body);
            customersUiSmokeAssertForbiddenAbsent($scriptVars, $customers->body, $credentials['password']);
            $csrfToken = $scriptVars['csrf_token'] ?? null;

            if (!is_string($csrfToken) || $csrfToken === '') {
                throw new GateAssertionException('Customers UI smoke refreshed response has no CSRF token.');
            }

            $search = $client->post('customers/search', [
                'csrf_token' => $csrfToken,
                'keyword' => CustomersUiSmokeContract::SEARCH_MARKER,
                'limit' => 20,
                'offset' => null,
            ]);
            GateAssertions::assertStatus($search->statusCode, 200, 'Customers UI smoke synthetic search');

            if (trim($search->body) !== '[]') {
                throw new GateAssertionException('Customers UI smoke synthetic search was not exactly empty.');
            }

            foreach (CustomersUiSmokeContract::FORBIDDEN_RESPONSE_MARKERS as $forbiddenKey) {
                if (str_contains($search->body, $forbiddenKey)) {
                    throw new GateAssertionException('Customers UI smoke search response exposes a forbidden key.');
                }
            }

            $clients[$role] = $client;

            return [
                'login_status_ok' => true,
                'view_status_ok' => true,
                'search_status_ok' => true,
                'search_exactly_empty' => true,
                'unsafe_search_denied' => true,
                'non_allowlisted_route_denied' => true,
                'response_containment_ok' => true,
            ];
        });
    }

    $tempDirectory = CustomersUiSmokeContract::createPrivateTempDirectory();

    if (!putenv('PLAYWRIGHT_MCP_OUTPUT_DIR=' . $tempDirectory)) {
        throw new RuntimeException('Customers UI smoke browser output directory could not be isolated.');
    }

    foreach (CustomersUiSmokeContract::AUTHORIZED_ROLES as $role) {
        $runCheck('browser_role_' . $role, static function () use (
            &$clients,
            &$sessions,
            $config,
            $repoRoot,
            $snippetPath,
            $tempDirectory,
            $role,
        ): array {
            $client = $clients[$role] ?? null;

            if (!$client instanceof GateHttpClient || !is_string($tempDirectory)) {
                throw new RuntimeException('Customers UI smoke authenticated browser session is unavailable.');
            }

            $customersUrl = customersUiSmokeBuildUrl($config['base_url'], $config['index_page'], 'customers');
            $searchUrl = customersUiSmokeBuildUrl($config['base_url'], $config['index_page'], 'customers/search');
            $cookies = normalizeCookieRecordsForPlaywright($client->cookieRecords(), $customersUrl);

            if ($cookies === []) {
                throw new GateAssertionException('Customers UI smoke browser storage state has no cookies.');
            }

            $sessionId = CustomersUiSmokeContract::buildBrowserSessionId($role);
            $sessions[$role] = $sessionId;
            $open = customersUiSmokeRunPwcli(
                $config,
                $sessionId,
                ['open', 'about:blank'],
                $repoRoot,
                $config['open_timeout'],
            );
            customersUiSmokeAssertProcessSucceeded($open, 'Open Customers UI smoke browser');

            return customersUiSmokeWithStorageState($tempDirectory, $role, $cookies, static function (
                string $statePath,
            ) use (&$sessions, $role, $sessionId, $config, $repoRoot, $snippetPath, $customersUrl, $searchUrl): array {
                $stateLoad = customersUiSmokeRunPwcli(
                    $config,
                    $sessionId,
                    ['state-load', $statePath],
                    $repoRoot,
                    $config['open_timeout'],
                );
                customersUiSmokeAssertProcessSucceeded($stateLoad, 'Load Customers UI smoke browser storage state');

                $snippet = customersUiSmokeResolveSnippet($snippetPath, [
                    'customers_url' => $customersUrl,
                    'allowed_origin' => customersUiSmokeOrigin($config['base_url']),
                    'customers_route_path' => customersUiSmokeUrlPath($customersUrl),
                    'search_route_path' => customersUiSmokeUrlPath($searchUrl),
                    'asset_path_prefix' => customersUiSmokeBasePath($config['base_url']) . '/assets/',
                    'favicon_path' => customersUiSmokeBasePath($config['base_url']) . '/assets/img/favicon.ico',
                    'search_marker' => CustomersUiSmokeContract::SEARCH_MARKER,
                    'forbidden_keys' => CustomersUiSmokeContract::FORBIDDEN_KEYS,
                    'forbidden_response_markers' => CustomersUiSmokeContract::FORBIDDEN_RESPONSE_MARKERS,
                    'open_timeout_ms' => $config['open_timeout'] * 1000,
                ]);
                $runCode = customersUiSmokeRunPwcli(
                    $config,
                    $sessionId,
                    ['run-code', $snippet],
                    $repoRoot,
                    max(90, $config['open_timeout'] * 3),
                );
                customersUiSmokeAssertProcessSucceeded($runCode, 'Customers UI smoke browser flow');
                $result = parsePlaywrightRunCodeJsonPayload(
                    (string) ($runCode['stdout'] ?? ''),
                    '__CUSTOMERS_UI_SMOKE_GATE__',
                    'Customers UI smoke',
                );

                if (($result['ok'] ?? false) !== true) {
                    throw new GateAssertionException('Customers UI smoke browser assertions failed.');
                }

                $close = customersUiSmokeRunPwcli(
                    $config,
                    $sessionId,
                    ['close'],
                    $repoRoot,
                    max(15, $config['open_timeout']),
                );
                customersUiSmokeAssertProcessSucceeded($close, 'Close Customers UI smoke browser');
                unset($sessions[$role]);

                return [
                    'page_loaded' => (bool) ($result['page_loaded'] ?? false),
                    'initial_search_empty' => (bool) ($result['initial_search_empty'] ?? false),
                    'synthetic_search_empty' => (bool) ($result['synthetic_search_empty'] ?? false),
                    'containment_ok' =>
                        (bool) ($result['script_vars_safe'] ?? false) &&
                        (bool) ($result['dom_safe'] ?? false) &&
                        (bool) ($result['response_bodies_safe'] ?? false),
                    'browser_closed' => true,
                    'blocked_request_count' => (int) ($result['blocked_request_count'] ?? -1),
                ];
            });
        });
    }
} catch (Throwable $exception) {
    $failureCode = $exception instanceof GateAssertionException ? 'assertion_failed' : 'runtime_error';
    $exitCode =
        $exception instanceof GateAssertionException
            ? CUSTOMERS_UI_SMOKE_ASSERTION_FAILURE
            : CUSTOMERS_UI_SMOKE_RUNTIME_FAILURE;
    $checks[] = [
        'name' => 'gate_failure',
        'status' => 'fail',
        'duration_ms' => 0,
        'details' => ['assertion_failure' => $exception instanceof GateAssertionException],
    ];
} finally {
    $cleanupOk = customersUiSmokeFinalizeCleanup($sessions, $tempDirectory, static function (string $sessionId) use (
        $config,
        $repoRoot,
    ): void {
        $close = customersUiSmokeRunPwcli($config, $sessionId, ['close'], $repoRoot, max(15, $config['open_timeout']));
        customersUiSmokeAssertProcessSucceeded($close, 'Close Customers UI smoke browser during cleanup');
    });
    $storageStatesRemoved = customersUiSmokeStorageStatesRemoved($tempDirectory);
    $temporaryArtifactsRemoved = customersUiSmokeTemporaryArtifactsRemoved($tempDirectory);
    $cleanupOk = $cleanupOk && $storageStatesRemoved && $temporaryArtifactsRemoved;

    putenv('PLAYWRIGHT_MCP_OUTPUT_DIR');
}
if (!$cleanupOk) {
    $exitCode = CUSTOMERS_UI_SMOKE_RUNTIME_FAILURE;
    $failureCode = 'cleanup_failed';
}

$report = [
    'schema_version' => 1,
    'gate' => 'customers-ui-smoke',
    'status' => $exitCode === CUSTOMERS_UI_SMOKE_SUCCESS ? 'pass' : 'fail',
    'failure_code' => $failureCode,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'role_counts' => ['authorized' => 3, 'denied' => 1],
    'checks' => $checks,
    'cleanup' => [
        'status' => $cleanupOk ? 'pass' : 'fail',
        'temporary_artifacts_removed' => $temporaryArtifactsRemoved,
        'storage_state_removed' => $storageStatesRemoved,
    ],
];

if ($config['output_json'] !== null) {
    try {
        customersUiSmokeWriteReport($config['output_json'], $report);
    } catch (Throwable) {
        $exitCode = CUSTOMERS_UI_SMOKE_RUNTIME_FAILURE;
        $failureCode = 'report_write_failed';
        $report['status'] = 'fail';
        $report['failure_code'] = $failureCode;
    }
}

printf(
    "customers_ui_smoke status=%s authorized_roles=3 denied_roles=1 cleanup=%s\n",
    $report['status'],
    $cleanupOk ? 'pass' : 'fail',
);

/**
 * @return array<string, mixed>
 */
function customersUiSmokeParseOptions(string $repoRoot): array
{
    $options = getopt('', [
        'base-url:',
        'index-page::',
        'credentials-file:',
        'pwcli-path::',
        'browser::',
        'bootstrap-timeout::',
        'http-timeout::',
        'open-timeout::',
        'headed::',
        'output-json::',
        'help',
    ]);

    if (isset($options['help'])) {
        customersUiSmokePrintUsage();
        exit(0);
    }

    $baseUrl = trim((string) ($options['base-url'] ?? ''));
    $credentialsFile = trim((string) ($options['credentials-file'] ?? ''));

    if (
        $baseUrl === '' ||
        $credentialsFile === '' ||
        preg_match('#^https?://[^\s/]+(?::\d+)?(?:/[^\s]*)?$#', $baseUrl) !== 1
    ) {
        throw new InvalidArgumentException('Customers UI smoke required options are invalid.');
    }

    $browser = strtolower(trim((string) ($options['browser'] ?? (PHP_OS_FAMILY === 'Darwin' ? 'chrome' : 'firefox'))));

    if (!in_array($browser, ['chrome', 'firefox', 'webkit', 'msedge'], true)) {
        throw new InvalidArgumentException('Customers UI smoke browser is invalid.');
    }

    putenv('PLAYWRIGHT_MCP_BROWSER=' . $browser);
    $output =
        isset($options['output-json']) && $options['output-json'] !== false
            ? customersUiSmokeResolvePath((string) $options['output-json'], $repoRoot)
            : null;

    return [
        'base_url' => rtrim($baseUrl, '/'),
        'index_page' => array_key_exists('index-page', $options)
            ? trim((string) $options['index-page'], '/')
            : 'index.php',
        'credentials_file' => $credentialsFile,
        'pwcli_path' => customersUiSmokeResolvePath(
            (string) ($options['pwcli-path'] ?? 'scripts/release-gate/playwright/playwright_cli.sh'),
            $repoRoot,
        ),
        'browser' => $browser,
        'bootstrap_timeout' => customersUiSmokePositiveInt($options['bootstrap-timeout'] ?? 90),
        'http_timeout' => customersUiSmokePositiveInt($options['http-timeout'] ?? 20),
        'open_timeout' => customersUiSmokePositiveInt($options['open-timeout'] ?? 30),
        'headed' => filter_var($options['headed'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'output_json' => $output,
    ];
}

function customersUiSmokePositiveInt(mixed $value): int
{
    if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]) === false) {
        throw new InvalidArgumentException('Customers UI smoke timeout is invalid.');
    }

    return (int) $value;
}

function customersUiSmokeResolvePath(string $path, string $repoRoot): string
{
    if ($path === '' || str_contains($path, "\0")) {
        throw new InvalidArgumentException('Customers UI smoke path is invalid.');
    }

    return str_starts_with($path, '/') ? $path : $repoRoot . '/' . ltrim($path, '/');
}

/**
 * @param array<string, mixed> $scriptVars
 */
function customersUiSmokeAssertForbiddenAbsent(array $scriptVars, string $html, string $password): void
{
    foreach (CustomersUiSmokeContract::FORBIDDEN_KEYS as $forbiddenKey) {
        if (array_key_exists($forbiddenKey, $scriptVars)) {
            throw new GateAssertionException('Customers UI smoke response exposes a forbidden integration key.');
        }
    }

    foreach (CustomersUiSmokeContract::FORBIDDEN_RESPONSE_MARKERS as $forbiddenMarker) {
        if (str_contains($html, $forbiddenMarker)) {
            throw new GateAssertionException('Customers UI smoke response exposes a forbidden integration marker.');
        }
    }

    if ($password !== '' && str_contains($html, $password)) {
        throw new GateAssertionException('Customers UI smoke response exposes credential material.');
    }
}

/**
 * @param list<string> $arguments
 * @return array<string, mixed>
 */
function customersUiSmokeRunPwcli(
    array $config,
    string $sessionId,
    array $arguments,
    string $repoRoot,
    int $timeout,
): array {
    $arguments = prepareConfiguredPlaywrightCommandArguments($arguments, (bool) $config['headed']);

    return GateProcessRunner::run(
        ['bash', $config['pwcli_path'], ...buildPlaywrightSessionArguments($sessionId), ...$arguments],
        $repoRoot,
        null,
        $timeout,
    );
}

/**
 * @param array<string, mixed> $result
 */
function customersUiSmokeAssertProcessSucceeded(array $result, string $context): void
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
function customersUiSmokeResolveSnippet(string $path, array $snippetConfig): string
{
    $snippet = file_get_contents($path);
    $placeholder = '__CUSTOMERS_UI_SMOKE_CONFIG__';

    if (!is_string($snippet) || trim($snippet) === '' || substr_count($snippet, $placeholder) !== 1) {
        throw new RuntimeException('Customers UI smoke Playwright snippet is invalid.');
    }

    $resolved = str_replace(
        $placeholder,
        json_encode($snippetConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $snippet,
    );

    return preg_replace('/;\s*\z/', '', trim($resolved)) ?? trim($resolved);
}

function customersUiSmokeBuildUrl(string $baseUrl, string $indexPage, string $route): string
{
    $segments = [rtrim($baseUrl, '/')];

    if ($indexPage !== '') {
        $segments[] = trim($indexPage, '/');
    }

    $segments[] = ltrim($route, '/');

    return implode('/', $segments);
}

function customersUiSmokeOrigin(string $baseUrl): string
{
    $parts = parse_url($baseUrl);
    $origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');

    if (isset($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }

    return $origin;
}

function customersUiSmokeBasePath(string $baseUrl): string
{
    $path = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');

    return rtrim($path, '/');
}

function customersUiSmokeUrlPath(string $url): string
{
    return (string) (parse_url($url, PHP_URL_PATH) ?? '/');
}

/**
 * @param array<string, mixed> $report
 */
function customersUiSmokeWriteReport(string $path, array $report): void
{
    if (is_link($path)) {
        throw new RuntimeException('Customers UI smoke report path is unsafe.');
    }

    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Customers UI smoke report directory could not be created.');
    }

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Customers UI smoke report could not be written.');
    }

    chmod($path, 0640);
}

function customersUiSmokePrintUsage(): void
{
    fwrite(
        STDOUT,
        <<<'USAGE'
        Customers UI Smoke Gate

        Usage:
          php scripts/release-gate/customers_ui_smoke.php --base-url=URL --credentials-file=- [options]

        The credential INI is accepted on stdin or from a root-owned non-shared file.
        No credential, cookie, response body, customer value, screenshot, trace, or network log is reported.

        Exit codes: 0 success, 1 assertion failure, 2 runtime/configuration error
        USAGE. PHP_EOL,
    );
}

exit($exitCode);

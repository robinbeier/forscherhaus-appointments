<?php
declare(strict_types=1);

require_once __DIR__ . '/../release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../release-gate/lib/GateCliSupport.php';
require_once __DIR__ . '/../release-gate/lib/GateHttpClient.php';
require_once __DIR__ . '/lib/BrowserRuntimeEvidence.php';
require_once __DIR__ . '/lib/CheckSelection.php';
require_once __DIR__ . '/lib/DashboardSummaryBrowserCheck.php';

use function CiRuntimeEvidence\buildDefaultBrowserRuntimeEvidenceArtifactsDir;
use function CiRuntimeEvidence\collectBookingPageBrowserEvidence;
use function CiRuntimeEvidence\parseBrowserRuntimeEvidenceMode;
use function CiRuntimeEvidence\runDashboardSummaryBrowserCheck;
use function CiRuntimeEvidence\shouldCollectBrowserRuntimeEvidenceForChecks;
use CiContract\CheckSelection;
use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateCliSupport;
use ReleaseGate\GateHttpClient;

const INTEGRATION_SMOKE_EXIT_SUCCESS = 0;
const INTEGRATION_SMOKE_EXIT_ASSERTION_FAILURE = 1;
const INTEGRATION_SMOKE_EXIT_RUNTIME_ERROR = 2;

$checks = [];
$failure = null;
$exitCode = INTEGRATION_SMOKE_EXIT_SUCCESS;
$reportPath = null;
$browserEvidence = null;

$repoRoot = dirname(__DIR__, 2);
$csrfDefaults = GateCliSupport::resolveCsrfNamesFromConfig($repoRoot . '/application/config/config.php');

try {
    $config = parseCliOptions($csrfDefaults, $repoRoot);

    if (dashboardIntegrationSmokeRequiresLdapFixture($config)) {
        $config = array_merge($config, dashboardIntegrationSmokePrepareLdapAppGuardrailFixture($repoRoot));
    }

    $client = dashboardIntegrationSmokeCreateClient($config);

    $bookingPageHtml = null;
    $bookingBootstrap = null;
    $providerServicePairs = null;
    $providerServicePair = null;
    $resolvedBooking = null;

    $runCheck = static function (string $name, callable $callback) use (&$checks, $config): void {
        try {
            $details = $callback();

            if (!is_array($details)) {
                $details = ['detail' => (string) $details];
            }

            $checks[] = array_merge(
                [
                    'name' => $name,
                    'status' => 'pass',
                    'selection_reason' => selectionReasonForConfiguredCheck($config, $name),
                ],
                $details,
            );

            fwrite(STDOUT, '[PASS] ' . $name . PHP_EOL);
        } catch (Throwable $e) {
            $checks[] = [
                'name' => $name,
                'status' => 'fail',
                'selection_reason' => selectionReasonForConfiguredCheck($config, $name),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ];

            fwrite(STDERR, '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL);
            throw $e;
        }
    };

    if (shouldRunConfiguredCheck($config, 'readiness_login_page')) {
        $runCheck('readiness_login_page', static function () use ($client, $config): array {
            $response = $client->get('login', [], $config['http_timeout']);
            GateAssertions::assertStatus($response->statusCode, 200, 'GET /login');

            $csrfCookie = $client->getCookie($config['csrf_cookie_name']);
            if ($csrfCookie === null || $csrfCookie === '') {
                throw new GateAssertionException(
                    'GET /login did not set cookie "' . $config['csrf_cookie_name'] . '".',
                );
            }

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'csrf_cookie_present' => true,
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'auth_login_validate')) {
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
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'ldap_settings_search')) {
        $runCheck('ldap_settings_search', static function () use ($client, $config): array {
            $response = $client->post(
                'ldap_settings/search',
                ['keyword' => $config['ldap_search_keyword']],
                $config['http_timeout'],
                true,
            );

            GateAssertions::assertStatus($response->statusCode, 200, 'POST /ldap_settings/search');
            $payload = GateAssertions::decodeJson($response->body, 'POST /ldap_settings/search');

            if (!is_array($payload) || !array_is_list($payload)) {
                throw new GateAssertionException('POST /ldap_settings/search payload must be a JSON array.');
            }

            $entry = dashboardIntegrationSmokeFindLdapEntryByDn($payload, $config['ldap_expected_dn']);
            dashboardIntegrationSmokeAssertLdapGuardrailEntry($entry, $config);

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'results' => count($payload),
                'matched_dn' => $entry['dn'],
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'ldap_settings_search_missing_keyword')) {
        $runCheck('ldap_settings_search_missing_keyword', static function () use ($client, $config): array {
            $response = $client->post(
                'ldap_settings/search',
                ['keyword' => $config['ldap_missing_keyword']],
                $config['http_timeout'],
                true,
            );

            GateAssertions::assertStatus($response->statusCode, 200, 'POST /ldap_settings/search (missing keyword)');
            $payload = GateAssertions::decodeJson($response->body, 'POST /ldap_settings/search (missing keyword)');

            if (!is_array($payload) || !array_is_list($payload)) {
                throw new GateAssertionException(
                    'POST /ldap_settings/search (missing keyword) payload must be a JSON array.',
                );
            }

            if ($payload !== []) {
                throw new GateAssertionException(
                    'POST /ldap_settings/search (missing keyword) returned unexpected LDAP entries.',
                );
            }

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'results' => 0,
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'ldap_sso_success')) {
        $runCheck('ldap_sso_success', static function () use ($config): array {
            $ldapClient = dashboardIntegrationSmokeCreateClient($config);
            dashboardIntegrationSmokeWarmLoginCsrf($ldapClient, $config);

            $response = $ldapClient->post(
                'login/validate',
                [
                    'username' => $config['ldap_guardrail_username'],
                    'password' => $config['ldap_guardrail_directory_password'],
                ],
                $config['http_timeout'],
                true,
            );

            GateAssertions::assertStatus($response->statusCode, 200, 'POST /login/validate (LDAP SSO)');
            $payload = GateAssertions::decodeJson($response->body, 'POST /login/validate (LDAP SSO)');
            GateAssertions::assertLoginPayload($payload);

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'username' => $config['ldap_guardrail_username'],
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'ldap_sso_wrong_password')) {
        $runCheck('ldap_sso_wrong_password', static function () use ($config): array {
            $ldapClient = dashboardIntegrationSmokeCreateClient($config);
            dashboardIntegrationSmokeWarmLoginCsrf($ldapClient, $config);

            $response = $ldapClient->post(
                'login/validate',
                [
                    'username' => $config['ldap_guardrail_username'],
                    'password' => $config['ldap_guardrail_wrong_password'],
                ],
                $config['http_timeout'],
                true,
            );

            GateAssertions::assertStatus($response->statusCode, 500, 'POST /login/validate (LDAP SSO wrong password)');
            $payload = GateAssertions::decodeJson($response->body, 'POST /login/validate (LDAP SSO wrong password)');

            if (!is_array($payload)) {
                throw new GateAssertionException('LDAP wrong-password login payload must be an object.');
            }

            if (($payload['success'] ?? null) !== false) {
                throw new GateAssertionException('LDAP wrong-password login must return {"success": false}.');
            }

            $message = trim((string) ($payload['message'] ?? ''));

            if ($message === '') {
                throw new GateAssertionException('LDAP wrong-password login response must include a message.');
            }

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'message' => $message,
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'dashboard_metrics')) {
        $runCheck('dashboard_metrics', static function () use ($client, $config): array {
            $response = $client->post('dashboard/metrics', buildMetricsPayload($config), $config['http_timeout'], true);

            GateAssertions::assertStatus($response->statusCode, 200, 'POST /dashboard/metrics');
            $decoded = GateAssertions::decodeJson($response->body, 'POST /dashboard/metrics');
            $summary = GateAssertions::assertMetricsPayload($decoded, true);

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'providers' => $summary['providers'],
                'booked_total' => $summary['booked_total'],
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'dashboard_page_readiness')) {
        $runCheck('dashboard_page_readiness', static function () use ($client, $config): array {
            $response = $client->get('dashboard', [], $config['http_timeout']);
            GateAssertions::assertStatus($response->statusCode, 200, 'GET /dashboard');

            $html = (string) $response->body;

            if (trim($html) === '') {
                throw new GateAssertionException('GET /dashboard returned an empty response body.');
            }

            foreach (
                [
                    'id="dashboard-page"',
                    'id="dashboard-summary-progress-track"',
                    'id="dashboard-summary-threshold-badge"',
                ]
                as $needle
            ) {
                if (!str_contains($html, $needle)) {
                    throw new GateAssertionException('GET /dashboard is missing expected markup: ' . $needle);
                }
            }

            if (
                !str_contains($html, 'assets/js/pages/dashboard.js') &&
                !str_contains($html, 'assets/js/pages/dashboard.min.js')
            ) {
                throw new GateAssertionException('GET /dashboard is missing expected dashboard page script include.');
            }

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'bytes' => strlen($html),
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'dashboard_summary_browser_render')) {
        $runCheck('dashboard_summary_browser_render', static function () use ($config, $repoRoot): array {
            return dashboardIntegrationSmokeAssertDashboardSummaryBrowserRender($config, $repoRoot);
        });
    }

    if (shouldRunConfiguredCheck($config, 'booking_page_readiness')) {
        $runCheck('booking_page_readiness', static function () use ($client, $config, &$bookingPageHtml): array {
            $response = $client->get('booking', [], $config['http_timeout']);
            GateAssertions::assertStatus($response->statusCode, 200, 'GET /booking');

            if (trim($response->body) === '') {
                throw new GateAssertionException('GET /booking returned an empty response body.');
            }

            $bookingPageHtml = $response->body;

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'bytes' => strlen($response->body),
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'booking_extract_bootstrap')) {
        $runCheck('booking_extract_bootstrap', static function () use (
            &$bookingPageHtml,
            &$bookingBootstrap,
            &$providerServicePairs,
            &$providerServicePair,
        ): array {
            if (!is_string($bookingPageHtml) || $bookingPageHtml === '') {
                throw new GateAssertionException('Booking page markup is missing for bootstrap extraction.');
            }

            $bookingBootstrap = extractScriptVarsJson($bookingPageHtml);
            $services = normalizeServices($bookingBootstrap['available_services'] ?? null);
            $providers = normalizeProviders($bookingBootstrap['available_providers'] ?? null);
            $providerServicePairs = selectProviderServicePairs($services, $providers);
            $providerServicePair = $providerServicePairs[0];

            return [
                'services' => count($services),
                'providers' => count($providers),
                'candidate_pairs' => count($providerServicePairs),
                'service_id' => $providerServicePair['service_id'],
                'provider_id' => $providerServicePair['provider_id'],
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'booking_available_hours')) {
        $runCheck('booking_available_hours', static function () use (
            $client,
            $config,
            &$providerServicePairs,
            &$providerServicePair,
            &$resolvedBooking,
        ): array {
            if (!is_array($providerServicePairs) || $providerServicePairs === []) {
                throw new GateAssertionException(
                    'Provider/service pairs were not resolved from booking bootstrap data.',
                );
            }

            $resolvedBooking = resolveBookablePairAndDate($client, $config, $providerServicePairs);
            $providerServicePair = [
                'provider_id' => $resolvedBooking['provider_id'],
                'service_id' => $resolvedBooking['service_id'],
            ];

            return [
                'provider_id' => $providerServicePair['provider_id'],
                'service_id' => $providerServicePair['service_id'],
                'date' => $resolvedBooking['date'],
                'hours_count' => count($resolvedBooking['hours']),
                'search_mode' => $resolvedBooking['mode'],
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'booking_unavailable_dates')) {
        $runCheck('booking_unavailable_dates', static function () use (
            $client,
            $config,
            &$providerServicePair,
            &$resolvedBooking,
        ): array {
            if (!is_array($providerServicePair) || !is_array($resolvedBooking)) {
                throw new GateAssertionException(
                    'booking_unavailable_dates requires a resolved provider/service pair and booking date.',
                );
            }

            $response = $client->post(
                'booking/get_unavailable_dates',
                [
                    'provider_id' => $providerServicePair['provider_id'],
                    'service_id' => $providerServicePair['service_id'],
                    'selected_date' => $resolvedBooking['date'],
                    'manage_mode' => 0,
                ],
                $config['http_timeout'],
                true,
            );

            GateAssertions::assertStatus($response->statusCode, 200, 'POST /booking/get_unavailable_dates');
            $decoded = GateAssertions::decodeJson($response->body, 'POST /booking/get_unavailable_dates');
            $summary = assertUnavailableDatesPayload($decoded);

            return array_merge(
                [
                    'http_status' => $response->statusCode,
                    'url' => $response->url,
                ],
                $summary,
            );
        });
    }

    if (shouldRunConfiguredCheck($config, 'api_unauthorized_guard')) {
        $runCheck('api_unauthorized_guard', static function () use ($config): array {
            $response = apiGetWithBasicAuth($config, 'api/v1/appointments', ['length' => 1], null, null);

            GateAssertions::assertStatus($response['status_code'], 401, 'GET /api/v1/appointments (without auth)');

            return [
                'http_status' => $response['status_code'],
                'url' => $response['url'],
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'api_appointments_index')) {
        $runCheck('api_appointments_index', static function () use ($config): array {
            $response = apiGetWithBasicAuth(
                $config,
                'api/v1/appointments',
                ['length' => 1, 'page' => 1],
                $config['api_username'],
                $config['api_password'],
            );

            GateAssertions::assertStatus($response['status_code'], 200, 'GET /api/v1/appointments');
            $decoded = GateAssertions::decodeJson($response['body'], 'GET /api/v1/appointments');

            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new GateAssertionException('GET /api/v1/appointments payload must be a JSON array.');
            }

            return [
                'http_status' => $response['status_code'],
                'url' => $response['url'],
                'items' => count($decoded),
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'api_availabilities')) {
        $runCheck('api_availabilities', static function () use (
            $config,
            &$providerServicePair,
            &$resolvedBooking,
        ): array {
            if (!is_array($providerServicePair) || !is_array($resolvedBooking)) {
                throw new GateAssertionException(
                    'api_availabilities requires resolved provider/service and booking date.',
                );
            }

            $response = apiGetWithBasicAuth(
                $config,
                'api/v1/availabilities',
                [
                    'providerId' => $providerServicePair['provider_id'],
                    'serviceId' => $providerServicePair['service_id'],
                    'date' => $resolvedBooking['date'],
                ],
                $config['api_username'],
                $config['api_password'],
            );

            GateAssertions::assertStatus($response['status_code'], 200, 'GET /api/v1/availabilities');
            $decoded = GateAssertions::decodeJson($response['body'], 'GET /api/v1/availabilities');
            $hours = assertHoursPayload($decoded, 'GET /api/v1/availabilities');

            if ($hours === []) {
                throw new GateAssertionException(
                    sprintf(
                        'GET /api/v1/availabilities returned no slots for provider %d, service %d on %s.',
                        $providerServicePair['provider_id'],
                        $providerServicePair['service_id'],
                        $resolvedBooking['date'],
                    ),
                );
            }

            return [
                'http_status' => $response['status_code'],
                'url' => $response['url'],
                'hours_count' => count($hours),
            ];
        });
    }
} catch (GateAssertionException $e) {
    $exitCode = GateCliSupport::classifyAssertionExitCode($checks);
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ];
} catch (Throwable $e) {
    $exitCode = INTEGRATION_SMOKE_EXIT_RUNTIME_ERROR;
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ];
}

if (
    isset($config) &&
    is_array($config) &&
    shouldCollectBrowserRuntimeEvidenceForChecks(
        (string) ($config['browser_evidence_mode'] ?? 'off'),
        $exitCode !== INTEGRATION_SMOKE_EXIT_SUCCESS,
        dashboardIntegrationSmokeFailedCheckIds($checks),
        (array) ($config['browser_evidence_on_failure_checks'] ?? []),
    )
) {
    try {
        $browserEvidence = collectBookingPageBrowserEvidence([
            'repo_root' => $repoRoot,
            'base_url' => (string) $config['base_url'],
            'index_page' => (string) $config['index_page'],
            'artifacts_dir' => (string) $config['browser_evidence_dir'],
            'pwcli_path' => (string) $config['browser_pwcli_path'],
            'bootstrap_timeout' => (int) $config['browser_bootstrap_timeout'],
            'open_timeout' => (int) $config['browser_open_timeout'],
            'headed' => (bool) $config['browser_headed'],
            'mode' => (string) $config['browser_evidence_mode'],
        ]);
    } catch (Throwable $e) {
        $browserEvidence = [
            'status' => 'runtime_error',
            'mode' => (string) ($config['browser_evidence_mode'] ?? 'off'),
            'target_url' => null,
            'artifacts_dir' => (string) ($config['browser_evidence_dir'] ?? ''),
            'summary_path' => null,
            'steps' => [],
            'artifacts' => [],
            'failure' => [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ],
            'cleanup_warnings' => [],
        ];
    }
}

try {
    $reportPath = writeReport($config ?? [], $checks, $failure, $browserEvidence);
} catch (Throwable $e) {
    fwrite(STDERR, '[WARN] Failed to write integration smoke report: ' . $e->getMessage() . PHP_EOL);
}

$passedChecks = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? null) === 'pass'));
$totalChecks = count($checks);

if ($exitCode === INTEGRATION_SMOKE_EXIT_SUCCESS) {
    fwrite(STDOUT, sprintf('[PASS] Integration smoke passed (%d/%d checks).%s', $passedChecks, $totalChecks, PHP_EOL));
} else {
    $message = $failure['message'] ?? 'unknown failure';
    fwrite(STDERR, sprintf('[FAIL] Integration smoke failed (exit %d): %s%s', $exitCode, $message, PHP_EOL));
}

if ($reportPath !== null) {
    fwrite(STDOUT, '[INFO] Report: ' . $reportPath . PHP_EOL);
}

if (is_array($browserEvidence) && !empty($browserEvidence['summary_path'])) {
    fwrite(STDOUT, '[INFO] Browser evidence: ' . $browserEvidence['summary_path'] . PHP_EOL);
}

exit($exitCode);

/**
 * @return array{
 *   base_url:string,
 *   index_page:string,
 *   username:string,
 *   password:string,
 *   api_username:string,
 *   api_password:string,
 *   start_date:string,
 *   end_date:string,
 *   booking_date:?string,
 *   booking_search_days:int,
 *   http_timeout:int,
 *   csrf_token_name:string,
 *   csrf_cookie_name:string,
 *   output_json:string,
 *   browser_evidence_mode:string,
 *   browser_evidence_dir:string,
 *   browser_pwcli_path:string,
 *   browser_bootstrap_timeout:int,
 *   browser_open_timeout:int,
 *   browser_headed:bool,
 *   browser_evidence_on_failure_checks:array<int, string>,
 *   requested_checks:array<int, string>,
 *   effective_checks:array<int, string>,
 *   selection_reason_by_check:array<string, string>,
 *   effective_check_lookup:array<string, bool>
 * }
 */
function parseCliOptions(array $csrfDefaults, string $repoRoot): array
{
    $options = getopt('', [
        'base-url:',
        'index-page::',
        'username:',
        'password:',
        'api-username::',
        'api-password::',
        'start-date:',
        'end-date:',
        'booking-date::',
        'booking-search-days::',
        'http-timeout::',
        'output-json::',
        'browser-evidence::',
        'browser-evidence-dir::',
        'browser-evidence-on-failure-checks::',
        'browser-pwcli-path::',
        'browser-bootstrap-timeout::',
        'browser-open-timeout::',
        'browser-headed::',
        'checks::',
        'help::',
    ]);

    if (!is_array($options)) {
        throw new InvalidArgumentException('Failed to parse CLI options.');
    }

    if (array_key_exists('help', $options)) {
        printHelpAndExit();
    }

    $baseUrl = trim(getRequiredOption($options, 'base-url'));
    $username = trim(getRequiredOption($options, 'username'));
    $password = getRequiredOption($options, 'password');
    $startDate = trim(getRequiredOption($options, 'start-date'));
    $endDate = trim(getRequiredOption($options, 'end-date'));

    $indexPageRaw = getOptionalOption($options, 'index-page', 'index.php');
    $indexPage = $indexPageRaw === null ? 'index.php' : trim((string) $indexPageRaw);
    $httpTimeout = parsePositiveInt(getOptionalOption($options, 'http-timeout', 15), 'http-timeout');
    $bookingSearchDays = parsePositiveInt(
        getOptionalOption($options, 'booking-search-days', 14),
        'booking-search-days',
    );
    $browserEvidenceMode = parseBrowserRuntimeEvidenceMode(getOptionalOption($options, 'browser-evidence', null));
    $browserEvidenceDir = resolvePath(
        trim(
            (string) getOptionalOption(
                $options,
                'browser-evidence-dir',
                buildDefaultBrowserRuntimeEvidenceArtifactsDir($repoRoot),
            ),
        ),
        $repoRoot,
    );
    $browserPwcliPath = resolvePath(
        trim(
            (string) getOptionalOption(
                $options,
                'browser-pwcli-path',
                $repoRoot . '/scripts/release-gate/playwright/playwright_cli.sh',
            ),
        ),
        $repoRoot,
    );
    $browserBootstrapTimeout = parsePositiveInt(
        getOptionalOption($options, 'browser-bootstrap-timeout', 90),
        'browser-bootstrap-timeout',
    );
    $browserOpenTimeout = parsePositiveInt(
        getOptionalOption($options, 'browser-open-timeout', 20),
        'browser-open-timeout',
    );
    $browserHeaded = parseBooleanOption(getOptionalOption($options, 'browser-headed', null));
    $browserEvidenceOnFailureChecks = parseBrowserEvidenceOnFailureChecks(
        getOptionalOption($options, 'browser-evidence-on-failure-checks', null),
        integrationSmokeSupportedCheckIds(),
    );

    if ($baseUrl === '') {
        throw new InvalidArgumentException('Option --base-url must not be empty.');
    }

    validateDate($startDate, 'start-date');
    validateDate($endDate, 'end-date');

    if ($startDate > $endDate) {
        throw new InvalidArgumentException('start-date must be <= end-date.');
    }

    $bookingDateRaw = getOptionalOption($options, 'booking-date', null);
    $bookingDate = null;

    if (is_string($bookingDateRaw) && trim($bookingDateRaw) !== '') {
        $bookingDate = trim($bookingDateRaw);
        validateDate($bookingDate, 'booking-date');
    }

    $apiUsernameRaw = getOptionalOption($options, 'api-username', $username);
    $apiUsername = is_string($apiUsernameRaw) && trim($apiUsernameRaw) !== '' ? trim($apiUsernameRaw) : $username;

    $apiPasswordRaw = getOptionalOption($options, 'api-password', $password);
    $apiPassword = is_string($apiPasswordRaw) && $apiPasswordRaw !== '' ? $apiPasswordRaw : $password;

    $selection = CheckSelection::resolve(
        array_key_exists('checks', $options) ? $options['checks'] : null,
        integrationSmokeSupportedCheckIds(),
        integrationSmokeCheckDependencies(),
    );

    return [
        'base_url' => $baseUrl,
        'index_page' => $indexPage,
        'username' => $username,
        'password' => $password,
        'api_username' => $apiUsername,
        'api_password' => $apiPassword,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'booking_date' => $bookingDate,
        'booking_search_days' => $bookingSearchDays,
        'http_timeout' => $httpTimeout,
        'csrf_token_name' => $csrfDefaults['csrf_token_name'],
        'csrf_cookie_name' => $csrfDefaults['csrf_cookie_name'],
        'output_json' => (string) getOptionalOption($options, 'output-json', ''),
        'browser_evidence_mode' => $browserEvidenceMode,
        'browser_evidence_dir' => $browserEvidenceDir,
        'browser_pwcli_path' => $browserPwcliPath,
        'browser_bootstrap_timeout' => $browserBootstrapTimeout,
        'browser_open_timeout' => $browserOpenTimeout,
        'browser_headed' => $browserHeaded,
        'browser_evidence_on_failure_checks' => $browserEvidenceOnFailureChecks,
        'requested_checks' => $selection['requested_checks'],
        'effective_checks' => $selection['effective_checks'],
        'selection_reason_by_check' => $selection['selection_reason_by_check'],
        'effective_check_lookup' => array_fill_keys($selection['effective_checks'], true),
    ];
}

function printHelpAndExit(): void
{
    $supportedChecks = implode(', ', integrationSmokeSupportedCheckIds());
    $help = <<<'TXT'
    Usage:
      php scripts/ci/dashboard_integration_smoke.php \
        --base-url=http://nginx \
        --index-page=index.php \
        --username=administrator \
        --password=administrator \
        --start-date=2026-01-01 \
        --end-date=2026-01-31

    Optional:
      --api-username=administrator
      --api-password=administrator
      --booking-date=2026-01-15
      --booking-search-days=14
      --http-timeout=15
      --output-json=PATH
      --browser-evidence=off|on-failure|always
      --browser-evidence-dir=PATH
      --browser-evidence-on-failure-checks=id1,id2
      --browser-pwcli-path=scripts/release-gate/playwright/playwright_cli.sh
      --browser-bootstrap-timeout=90
      --browser-open-timeout=20
      --browser-headed
      --checks=id1,id2
    TXT;

    fwrite(STDOUT, $help . PHP_EOL . '    Supported check IDs: ' . $supportedChecks . PHP_EOL);
    exit(INTEGRATION_SMOKE_EXIT_SUCCESS);
}

/**
 * @param array<string, mixed> $config
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed>|null $failure
 * @param array<string, mixed>|null $browserEvidence
 */
function writeReport(array $config, array $checks, ?array $failure, ?array $browserEvidence): string
{
    $outputPath = trim((string) ($config['output_json'] ?? ''));
    if ($outputPath === '') {
        $timestamp = gmdate('Ymd\THis\Z');
        $outputPath = dirname(__DIR__, 2) . '/storage/logs/ci/dashboard-integration-smoke-' . $timestamp . '.json';
    }

    $directory = dirname($outputPath);
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create report directory: ' . $directory);
        }
    }

    $passed = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? null) === 'pass'));
    $failed = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? null) === 'fail'));

    $report = [
        'generated_at_utc' => gmdate('c'),
        'base_url' => $config['base_url'] ?? null,
        'requested_checks' => $config['requested_checks'] ?? [],
        'effective_checks' => $config['effective_checks'] ?? [],
        'summary' => [
            'total' => count($checks),
            'passed' => $passed,
            'failed' => $failed,
        ],
        'checks' => $checks,
        'failure' => $failure,
        'browser_evidence' => $browserEvidence,
    ];

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($outputPath, $json . PHP_EOL);

    return $outputPath;
}

/**
 * @return array<int, string>
 */
function integrationSmokeSupportedCheckIds(): array
{
    return [
        'readiness_login_page',
        'auth_login_validate',
        'ldap_settings_search',
        'ldap_settings_search_missing_keyword',
        'ldap_sso_success',
        'ldap_sso_wrong_password',
        'dashboard_metrics',
        'dashboard_page_readiness',
        'dashboard_summary_browser_render',
        'booking_page_readiness',
        'booking_extract_bootstrap',
        'booking_available_hours',
        'booking_unavailable_dates',
        'api_unauthorized_guard',
        'api_appointments_index',
        'api_availabilities',
    ];
}

/**
 * @return array<int, string>
 */
function integrationSmokeBrowserEvidenceOnFailureCheckIds(): array
{
    return ['booking_page_readiness', 'booking_extract_bootstrap'];
}

/**
 * @return array<string, array<int, string>>
 */
function integrationSmokeCheckDependencies(): array
{
    return [
        'readiness_login_page' => [],
        'auth_login_validate' => ['readiness_login_page'],
        'ldap_settings_search' => ['auth_login_validate'],
        'ldap_settings_search_missing_keyword' => ['auth_login_validate'],
        'ldap_sso_success' => [],
        'ldap_sso_wrong_password' => [],
        'dashboard_metrics' => ['auth_login_validate'],
        'dashboard_page_readiness' => ['auth_login_validate'],
        'dashboard_summary_browser_render' => ['auth_login_validate'],
        'booking_page_readiness' => [],
        'booking_extract_bootstrap' => ['booking_page_readiness'],
        'booking_available_hours' => ['booking_extract_bootstrap'],
        'booking_unavailable_dates' => ['booking_available_hours'],
        'api_unauthorized_guard' => [],
        'api_appointments_index' => [],
        'api_availabilities' => ['booking_available_hours'],
    ];
}

/**
 * @param array<string, mixed> $config
 */
function shouldRunConfiguredCheck(array $config, string $checkId): bool
{
    return isset($config['effective_check_lookup'][$checkId]);
}

/**
 * @param array<string, mixed> $config
 */
function selectionReasonForConfiguredCheck(array $config, string $checkId): string
{
    return (string) ($config['selection_reason_by_check'][$checkId] ?? 'requested');
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @return array<int, string>
 */
function dashboardIntegrationSmokeFailedCheckIds(array $checks): array
{
    $failedCheckIds = [];

    foreach ($checks as $check) {
        if (($check['status'] ?? null) !== 'fail') {
            continue;
        }

        $checkId = trim((string) ($check['name'] ?? ''));
        if ($checkId === '') {
            continue;
        }

        $failedCheckIds[] = $checkId;
    }

    return $failedCheckIds;
}

/**
 * @param array<string, mixed> $config
 */
function dashboardIntegrationSmokeRequiresLdapFixture(array $config): bool
{
    foreach (dashboardIntegrationSmokeLdapGuardrailCheckIds() as $checkId) {
        if (shouldRunConfiguredCheck($config, $checkId)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int, string>
 */
function dashboardIntegrationSmokeLdapGuardrailCheckIds(): array
{
    return [
        'ldap_settings_search',
        'ldap_settings_search_missing_keyword',
        'ldap_sso_success',
        'ldap_sso_wrong_password',
    ];
}

/**
 * @param array<string, mixed> $config
 */
function dashboardIntegrationSmokeCreateClient(array $config): GateHttpClient
{
    return new GateHttpClient(
        $config['base_url'],
        $config['index_page'],
        $config['http_timeout'],
        'dashboard-booking-api-integration-smoke/1.0',
        $config['csrf_cookie_name'],
        $config['csrf_token_name'],
    );
}

/**
 * @param array<string, mixed> $config
 */
function dashboardIntegrationSmokeWarmLoginCsrf(GateHttpClient $client, array $config): void
{
    $response = $client->get('login', [], $config['http_timeout']);
    GateAssertions::assertStatus($response->statusCode, 200, 'GET /login (LDAP guardrail)');

    $csrfCookie = $client->getCookie($config['csrf_cookie_name']);

    if ($csrfCookie === null || $csrfCookie === '') {
        throw new GateAssertionException(
            'GET /login (LDAP guardrail) did not set cookie "' . $config['csrf_cookie_name'] . '".',
        );
    }
}

/**
 * @param array<string, mixed> $config
 */
function dashboardIntegrationSmokeAssertDashboardSummaryBrowserRender(array $config, string $repoRoot): array
{
    $artifactsDir = rtrim((string) $config['browser_evidence_dir'], '/') . '/dashboard-summary-browser-render';
    dashboardIntegrationSmokeEnsureDirectory($artifactsDir);

    $client = dashboardIntegrationSmokeCreateClient($config);
    dashboardIntegrationSmokeWarmLoginCsrf($client, $config);

    $loginResponse = $client->post(
        'login/validate',
        [
            'username' => $config['username'],
            'password' => $config['password'],
        ],
        $config['http_timeout'],
        true,
    );
    GateAssertions::assertStatus(
        $loginResponse->statusCode,
        200,
        'POST /login/validate (dashboard summary browser render)',
    );
    GateAssertions::assertLoginPayload(
        GateAssertions::decodeJson($loginResponse->body, 'POST /login/validate (dashboard summary browser render)'),
    );

    $metricsResponse = $client->post('dashboard/metrics', buildMetricsPayload($config), $config['http_timeout'], true);
    GateAssertions::assertStatus(
        $metricsResponse->statusCode,
        200,
        'POST /dashboard/metrics (dashboard summary browser render)',
    );
    $metricsPayload = GateAssertions::decodeJson(
        $metricsResponse->body,
        'POST /dashboard/metrics (dashboard summary browser render)',
    );

    if (!is_array($metricsPayload) || !is_array($metricsPayload['summary'] ?? null)) {
        throw new GateAssertionException(
            'POST /dashboard/metrics (dashboard summary browser render) did not return a summary payload.',
        );
    }

    $summary = $metricsPayload['summary'];
    $targetUrl = dashboardIntegrationSmokeBuildAppUrl($config, 'dashboard');
    $payload = runDashboardSummaryBrowserCheck([
        'repo_root' => $repoRoot,
        'target_url' => $targetUrl,
        'artifacts_dir' => $artifactsDir,
        'username' => (string) $config['username'],
        'password' => (string) $config['password'],
        'start_date' => (string) $config['start_date'],
        'end_date' => (string) $config['end_date'],
        'expected_summary' => [
            'target_total' => $summary['target_total'] ?? 0,
            'booked_total' => $summary['booked_total'] ?? 0,
            'open_total' => $summary['open_total'] ?? 0,
            'fill_rate' => $summary['fill_rate'] ?? 0,
            'threshold' => $summary['threshold'] ?? 0,
        ],
        'pwcli_path' => (string) $config['browser_pwcli_path'],
        'bootstrap_timeout' => (int) $config['browser_bootstrap_timeout'],
        'open_timeout' => (int) $config['browser_open_timeout'],
        'headed' => (bool) $config['browser_headed'],
    ]);

    return [
        'target_url' => $targetUrl,
        'fill_rate' => (string) ($payload['fill_rate_before'] ?? ''),
        'open_total_before' => (int) ($payload['open_total_before'] ?? 0),
        'open_total_after' => (int) ($payload['open_total_after'] ?? 0),
        'threshold_badge_before' => (string) ($payload['threshold_badge_before'] ?? ''),
        'threshold_badge_after' => (string) ($payload['threshold_badge_after'] ?? ''),
        'marker_left_before' => (string) ($payload['marker_left_before'] ?? ''),
        'marker_left_after' => (string) ($payload['marker_left_after'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $config
 */
function dashboardIntegrationSmokeBuildAppUrl(array $config, string $path): string
{
    $segments = [rtrim((string) $config['base_url'], '/')];
    $indexPage = trim((string) ($config['index_page'] ?? 'index.php'), '/');

    if ($indexPage !== '') {
        $segments[] = $indexPage;
    }

    $normalizedPath = trim($path, '/');

    if ($normalizedPath !== '') {
        $segments[] = $normalizedPath;
    }

    return implode('/', $segments);
}

function dashboardIntegrationSmokeEnsureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create directory: ' . $directory);
    }
}

/**
 * @param array<int, mixed> $entries
 * @return array<string, mixed>
 */
function dashboardIntegrationSmokeFindLdapEntryByDn(array $entries, string $expectedDn): array
{
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (($entry['dn'] ?? null) === $expectedDn) {
            return $entry;
        }
    }

    throw new GateAssertionException('LDAP search did not return the expected guardrail DN: ' . $expectedDn);
}

/**
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $config
 */
function dashboardIntegrationSmokeAssertLdapGuardrailEntry(array $entry, array $config): void
{
    $expectedFields = [
        'dn' => $config['ldap_expected_dn'],
        'cn' => $config['ldap_expected_cn'],
        'givenname' => $config['ldap_expected_given_name'],
        'sn' => $config['ldap_expected_sn'],
        'mail' => $config['ldap_expected_mail'],
        'telephonenumber' => $config['ldap_expected_phone'],
    ];

    foreach ($expectedFields as $field => $expectedValue) {
        $actualValue = trim((string) ($entry[$field] ?? ''));

        if ($actualValue === '') {
            throw new GateAssertionException('LDAP guardrail entry misses field "' . $field . '".');
        }

        if ($actualValue !== $expectedValue) {
            throw new GateAssertionException(
                sprintf(
                    'LDAP guardrail entry field "%s" mismatch: expected "%s", got "%s".',
                    $field,
                    $expectedValue,
                    $actualValue,
                ),
            );
        }
    }
}

/**
 * @return array<string, mixed>
 */
function dashboardIntegrationSmokePrepareLdapAppGuardrailFixture(string $repoRoot): array
{
    $CI = dashboardIntegrationSmokeBootstrapApplication($repoRoot);

    $CI->load->helper('setting');
    $CI->load->model('admins_model');

    $fieldMapping = json_encode(
        LDAP_DEFAULT_FIELD_MAPPING,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );

    setting([
        'ldap_is_active' => '1',
        'ldap_host' => 'openldap',
        'ldap_port' => '389',
        'ldap_user_dn' => 'cn=admin,dc=example,dc=org',
        'ldap_password' => 'admin',
        'ldap_base_dn' => 'dc=example,dc=org',
        'ldap_filter' => LDAP_DEFAULT_FILTER,
        'ldap_field_mapping' => $fieldMapping,
    ]);

    $guardrailUsername = 'ada-ldap-guardrail';
    $guardrailLocalPassword = 'guardrail-local-password';
    $guardrailExpectedDn = 'uid=ada,ou=people,dc=example,dc=org';
    $guardrailExpectedMail = 'ada.lovelace@example.org';

    $existingUser = $CI->db
        ->select('users.id, roles.slug AS role_slug')
        ->from('user_settings')
        ->join('users', 'users.id = user_settings.id_users', 'inner')
        ->join('roles', 'roles.id = users.id_roles', 'inner')
        ->where('user_settings.username', $guardrailUsername)
        ->get()
        ->row_array();

    if (!empty($existingUser) && ($existingUser['role_slug'] ?? null) !== DB_SLUG_ADMIN) {
        throw new RuntimeException(
            'LDAP guardrail username "' . $guardrailUsername . '" is already used by a non-admin account.',
        );
    }

    $admin = [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => $guardrailExpectedMail,
        'phone_number' => '+49 30 1234567',
        'mobile_number' => '+49 30 1234567',
        'ldap_dn' => $guardrailExpectedDn,
        'settings' => [
            'username' => $guardrailUsername,
            'password' => $guardrailLocalPassword,
        ],
    ];

    if (!empty($existingUser)) {
        $admin['id'] = (int) $existingUser['id'];
    }

    $CI->admins_model->save($admin);

    return [
        'ldap_search_keyword' => 'ada',
        'ldap_missing_keyword' => 'missing-ldap-guardrail-user',
        'ldap_guardrail_username' => $guardrailUsername,
        'ldap_guardrail_directory_password' => 'ada-local-pass',
        'ldap_guardrail_wrong_password' => 'definitely-wrong-password',
        'ldap_expected_dn' => $guardrailExpectedDn,
        'ldap_expected_cn' => 'ada',
        'ldap_expected_given_name' => 'Ada',
        'ldap_expected_sn' => 'Lovelace',
        'ldap_expected_mail' => $guardrailExpectedMail,
        'ldap_expected_phone' => '+49 30 1234567',
    ];
}

function dashboardIntegrationSmokeBootstrapApplication(string $repoRoot): CI_Controller
{
    static $bootstrappedController = null;

    if ($bootstrappedController !== null) {
        return $bootstrappedController;
    }

    $serverKeys = ['argv', 'argc', 'REQUEST_METHOD', 'REQUEST_URI', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'HTTP_HOST'];
    $originalServer = [];

    foreach ($serverKeys as $key) {
        $originalServer[$key] = $_SERVER[$key] ?? null;
    }

    $originalGet = $_GET ?? [];
    $originalPost = $_POST ?? [];
    $originalCookie = $_COOKIE ?? [];
    $originalRequest = $_REQUEST ?? [];

    $_SERVER['argv'] = [$_SERVER['argv'][0] ?? 'dashboard_integration_smoke.php', 'healthz'];
    $_SERVER['argc'] = count($_SERVER['argv']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/healthz';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $repoRoot . '/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_REQUEST = [];

    ob_start();
    require_once $repoRoot . '/index.php';
    $bootstrapOutput = ob_get_clean();

    foreach ($serverKeys as $key) {
        if ($originalServer[$key] === null) {
            unset($_SERVER[$key]);
            continue;
        }

        $_SERVER[$key] = $originalServer[$key];
    }

    $_GET = $originalGet;
    $_POST = $originalPost;
    $_COOKIE = $originalCookie;
    $_REQUEST = $originalRequest;

    $bootstrappedController = &get_instance();

    if (!($bootstrappedController instanceof CI_Controller)) {
        throw new RuntimeException('Failed to bootstrap CodeIgniter for LDAP guardrail preparation.');
    }

    if (isset($bootstrappedController->output)) {
        $bootstrappedController->output->set_output('');
    }

    unset($bootstrapOutput);

    return $bootstrappedController;
}

/**
 * @param array<string, mixed> $options
 */
function getRequiredOption(array $options, string $name): string
{
    $value = $options[$name] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException('Missing required option --' . $name . '.');
    }

    return $value;
}

/**
 * @param array<string, mixed> $options
 */
function getOptionalOption(array $options, string $name, mixed $default): mixed
{
    $value = $options[$name] ?? $default;

    if (is_array($value)) {
        $value = end($value);
    }

    if ($value === false) {
        return $default;
    }

    return $value;
}

function parsePositiveInt(mixed $value, string $name): int
{
    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        $parsed = (int) trim($value);
    } else {
        throw new InvalidArgumentException('Option --' . $name . ' must be a positive integer.');
    }

    if ($parsed <= 0) {
        throw new InvalidArgumentException('Option --' . $name . ' must be a positive integer.');
    }

    return $parsed;
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

/**
 * @param array<int, string> $supportedCheckIds
 * @return array<int, string>
 */
function parseBrowserEvidenceOnFailureChecks(mixed $raw, array $supportedCheckIds): array
{
    if ($raw === null || $raw === false) {
        return integrationSmokeBrowserEvidenceOnFailureCheckIds();
    }

    $value = is_array($raw) ? end($raw) : $raw;
    $parsedValue = trim((string) $value);

    if ($parsedValue === '') {
        return integrationSmokeBrowserEvidenceOnFailureCheckIds();
    }

    $supportedLookup = array_fill_keys($supportedCheckIds, true);
    $resolved = [];

    foreach (explode(',', $parsedValue) as $candidate) {
        $checkId = trim($candidate);

        if ($checkId === '') {
            continue;
        }

        if (!isset($supportedLookup[$checkId])) {
            throw new InvalidArgumentException('Unsupported browser evidence on-failure check ID: ' . $checkId . '.');
        }

        $resolved[$checkId] = true;
    }

    return array_keys($resolved);
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

function validateDate(string $value, string $name): void
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Option --' . $name . ' must be in YYYY-MM-DD format.');
    }
}

/**
 * @param array{
 *   start_date:string,
 *   end_date:string
 * } $config
 *
 * @return array{
 *   start_date:string,
 *   end_date:string,
 *   statuses:array<int, string>
 * }
 */
function buildMetricsPayload(array $config): array
{
    return [
        'start_date' => $config['start_date'],
        'end_date' => $config['end_date'],
        'statuses' => ['Booked'],
    ];
}

/**
 * @return array<string, mixed>
 */
function extractScriptVarsJson(string $html): array
{
    $marker = 'const vars =';
    $markerPosition = strpos($html, $marker);

    if ($markerPosition === false) {
        throw new GateAssertionException('Could not locate booking bootstrap marker "const vars =".');
    }

    $braceStart = strpos($html, '{', $markerPosition);

    if ($braceStart === false) {
        throw new GateAssertionException('Could not locate opening JSON brace after booking bootstrap marker.');
    }

    $depth = 0;
    $inString = false;
    $escaped = false;
    $length = strlen($html);

    for ($index = $braceStart; $index < $length; $index++) {
        $char = $html[$index];

        if ($inString) {
            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = false;
            }

            continue;
        }

        if ($char === '"') {
            $inString = true;
            continue;
        }

        if ($char === '{') {
            $depth++;
            continue;
        }

        if ($char === '}') {
            $depth--;

            if ($depth === 0) {
                $json = substr($html, $braceStart, $index - $braceStart + 1);
                $decoded = GateAssertions::decodeJson($json, 'booking bootstrap vars');

                if (!is_array($decoded)) {
                    throw new GateAssertionException('Booking bootstrap vars payload must decode to a JSON object.');
                }

                return $decoded;
            }
        }
    }

    throw new GateAssertionException('Could not parse booking bootstrap vars JSON block.');
}

/**
 * @return array<int, array{id:int}>
 */
function normalizeServices(mixed $services): array
{
    if (!is_array($services) || $services === []) {
        throw new GateAssertionException('booking bootstrap "available_services" must be a non-empty array.');
    }

    $normalized = [];

    foreach ($services as $index => $service) {
        if (!is_array($service)) {
            throw new GateAssertionException('available_services[' . $index . '] must be an object.');
        }

        $serviceId = toPositiveInt($service['id'] ?? null, 'available_services[' . $index . '].id');
        $normalized[] = ['id' => $serviceId];
    }

    return $normalized;
}

/**
 * @return array<int, array{id:int,services:array<int, int>}>
 */
function normalizeProviders(mixed $providers): array
{
    if (!is_array($providers) || $providers === []) {
        throw new GateAssertionException('booking bootstrap "available_providers" must be a non-empty array.');
    }

    $normalized = [];

    foreach ($providers as $index => $provider) {
        if (!is_array($provider)) {
            throw new GateAssertionException('available_providers[' . $index . '] must be an object.');
        }

        $providerId = toPositiveInt($provider['id'] ?? null, 'available_providers[' . $index . '].id');
        $services = $provider['services'] ?? null;

        if (!is_array($services) || $services === []) {
            continue;
        }

        $serviceIds = [];

        foreach ($services as $serviceIndex => $serviceIdRaw) {
            $serviceIds[] = toPositiveInt(
                $serviceIdRaw,
                'available_providers[' . $index . '].services[' . $serviceIndex . ']',
            );
        }

        $serviceIds = array_values(array_unique($serviceIds));

        if ($serviceIds === []) {
            continue;
        }

        $normalized[] = [
            'id' => $providerId,
            'services' => $serviceIds,
        ];
    }

    if ($normalized === []) {
        throw new GateAssertionException('No available provider with at least one service was found.');
    }

    return $normalized;
}

/**
 * @param array<int, array{id:int}> $services
 * @param array<int, array{id:int,services:array<int, int>}> $providers
 *
 * @return array<int, array{provider_id:int,service_id:int}>
 */
function selectProviderServicePairs(array $services, array $providers): array
{
    $serviceMap = [];
    foreach ($services as $service) {
        $serviceMap[$service['id']] = true;
    }

    $pairs = [];
    $seen = [];

    foreach ($providers as $provider) {
        foreach ($provider['services'] as $serviceId) {
            if (isset($serviceMap[$serviceId])) {
                $pairKey = $provider['id'] . ':' . $serviceId;

                if (isset($seen[$pairKey])) {
                    continue;
                }

                $pairs[] = [
                    'provider_id' => $provider['id'],
                    'service_id' => $serviceId,
                ];
                $seen[$pairKey] = true;
            }
        }
    }

    if ($pairs === []) {
        throw new GateAssertionException('Could not resolve any provider/service pair from booking bootstrap data.');
    }

    return $pairs;
}

/**
 * @param array{
 *   booking_date:?string,
 *   booking_search_days:int
 * } $config
 * @param array<int, array{provider_id:int,service_id:int}> $providerServicePairs
 *
 * @return array{
 *   provider_id:int,
 *   service_id:int,
 *   date:string,
 *   hours:array<int, string>,
 *   mode:string
 * }
 */
function resolveBookablePairAndDate(GateHttpClient $client, array $config, array $providerServicePairs): array
{
    if ($providerServicePairs === []) {
        throw new GateAssertionException('Provider/service pairs list is empty.');
    }

    if (is_string($config['booking_date']) && $config['booking_date'] !== '') {
        foreach ($providerServicePairs as $pair) {
            $hoursResult = fetchBookingAvailableHours(
                $client,
                $config,
                $pair['provider_id'],
                $pair['service_id'],
                $config['booking_date'],
            );

            if ($hoursResult['hours'] !== []) {
                return [
                    'provider_id' => $pair['provider_id'],
                    'service_id' => $pair['service_id'],
                    'date' => $config['booking_date'],
                    'hours' => $hoursResult['hours'],
                    'mode' => 'configured_date',
                ];
            }
        }

        throw new GateAssertionException(
            sprintf(
                'Configured booking date "%s" has no available hours across %d provider/service pairs.',
                $config['booking_date'],
                count($providerServicePairs),
            ),
        );
    }

    $startDate = new DateTimeImmutable('tomorrow', new DateTimeZone(date_default_timezone_get()));
    $attemptedDates = [];

    for ($offset = 0; $offset < $config['booking_search_days']; $offset++) {
        $candidateDate = $startDate->modify('+' . $offset . ' day')->format('Y-m-d');
        $attemptedDates[] = $candidateDate;

        foreach ($providerServicePairs as $pair) {
            $hoursResult = fetchBookingAvailableHours(
                $client,
                $config,
                $pair['provider_id'],
                $pair['service_id'],
                $candidateDate,
            );

            if ($hoursResult['hours'] !== []) {
                return [
                    'provider_id' => $pair['provider_id'],
                    'service_id' => $pair['service_id'],
                    'date' => $candidateDate,
                    'hours' => $hoursResult['hours'],
                    'mode' => 'searched_window',
                ];
            }
        }
    }

    $firstDate = $attemptedDates[0] ?? 'n/a';
    $lastDate = $attemptedDates[count($attemptedDates) - 1] ?? 'n/a';

    throw new GateAssertionException(
        sprintf(
            'No booking hours available across %d provider/service pairs in search window [%s .. %s].',
            count($providerServicePairs),
            $firstDate,
            $lastDate,
        ),
    );
}

/**
 * @param array{
 *   http_timeout:int
 * } $config
 *
 * @return array{
 *   response:object,
 *   hours:array<int, string>
 * }
 */
function fetchBookingAvailableHours(
    GateHttpClient $client,
    array $config,
    int $providerId,
    int $serviceId,
    string $date,
): array {
    $response = $client->post(
        'booking/get_available_hours',
        [
            'provider_id' => $providerId,
            'service_id' => $serviceId,
            'selected_date' => $date,
            'manage_mode' => 0,
        ],
        $config['http_timeout'],
        true,
    );

    GateAssertions::assertStatus($response->statusCode, 200, 'POST /booking/get_available_hours');
    $decoded = GateAssertions::decodeJson($response->body, 'POST /booking/get_available_hours');
    $hours = assertHoursPayload($decoded, 'POST /booking/get_available_hours');

    return [
        'response' => $response,
        'hours' => $hours,
    ];
}

/**
 * @return array<int, string>
 */
function assertHoursPayload(mixed $payload, string $context): array
{
    if (!is_array($payload) || !array_is_list($payload)) {
        throw new GateAssertionException($context . ' payload must be a JSON array.');
    }

    $hours = [];

    foreach ($payload as $index => $hour) {
        if (!is_string($hour) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hour) !== 1) {
            throw new GateAssertionException($context . ' contains invalid hour at index ' . $index . '.');
        }

        $hours[] = $hour;
    }

    return $hours;
}

/**
 * @return array<string, int|string|bool>
 */
function assertUnavailableDatesPayload(mixed $payload): array
{
    if (!is_array($payload)) {
        throw new GateAssertionException('POST /booking/get_unavailable_dates payload must be a JSON array/object.');
    }

    if (array_is_list($payload)) {
        foreach ($payload as $index => $date) {
            if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new GateAssertionException(
                    'POST /booking/get_unavailable_dates has invalid date at index ' . $index . '.',
                );
            }
        }

        return [
            'payload_type' => 'date_list',
            'dates_count' => count($payload),
        ];
    }

    if (!array_key_exists('is_month_unavailable', $payload)) {
        throw new GateAssertionException(
            'POST /booking/get_unavailable_dates object payload must include "is_month_unavailable".',
        );
    }

    $flag = $payload['is_month_unavailable'];

    if (!is_bool($flag)) {
        $coerced = filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($coerced === null) {
            throw new GateAssertionException('"is_month_unavailable" must be boolean-compatible.');
        }
        $flag = $coerced;
    }

    return [
        'payload_type' => 'month_flag',
        'is_month_unavailable' => $flag,
    ];
}

/**
 * @param array{
 *   base_url:string,
 *   index_page:string,
 *   http_timeout:int
 * } $config
 * @param array<string, int|string> $query
 *
 * @return array{
 *   status_code:int,
 *   body:string,
 *   url:string,
 *   duration_ms:float,
 *   content_type:?string
 * }
 */
function apiGetWithBasicAuth(
    array $config,
    string $path,
    array $query = [],
    ?string $username = null,
    ?string $password = null,
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('ext-curl is required for API smoke requests.');
    }

    $url = buildAppUrl($config['base_url'], $config['index_page'], $path, $query);
    $headers = [];

    $headerFn = static function ($curlHandle, string $headerLine) use (&$headers): int {
        $trimmed = trim($headerLine);

        if ($trimmed === '') {
            return strlen($headerLine);
        }

        if (str_starts_with($trimmed, 'HTTP/')) {
            $headers = [];
            return strlen($headerLine);
        }

        $parts = explode(':', $trimmed, 2);

        if (count($parts) !== 2) {
            return strlen($headerLine);
        }

        $name = strtolower(trim($parts[0]));
        $value = trim($parts[1]);

        $headers[$name] ??= [];
        $headers[$name][] = $value;

        return strlen($headerLine);
    };

    $curl = curl_init();

    if ($curl === false) {
        throw new RuntimeException('Failed to initialize cURL for API smoke request.');
    }

    $timeoutSeconds = max(1, $config['http_timeout']);
    $connectTimeout = min(5, $timeoutSeconds);

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_HEADERFUNCTION => $headerFn,
        CURLOPT_USERAGENT => 'dashboard-booking-api-integration-smoke/1.0',
    ]);

    if ($username !== null && $password !== null) {
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $username . ':' . $password);
    }

    $startedAt = microtime(true);
    $body = curl_exec($curl);
    $durationMs = (microtime(true) - $startedAt) * 1000;

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('API smoke HTTP request failed for "' . $url . '": ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    curl_close($curl);

    $contentType = null;

    if (isset($headers['content-type']) && is_array($headers['content-type']) && $headers['content-type'] !== []) {
        $contentType = (string) $headers['content-type'][0];
    }

    return [
        'status_code' => $statusCode,
        'body' => (string) $body,
        'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        'duration_ms' => round($durationMs, 2),
        'content_type' => $contentType,
    ];
}

/**
 * @param array<string, int|string> $query
 */
function buildAppUrl(string $baseUrl, string $indexPage, string $path, array $query = []): string
{
    $segments = [rtrim($baseUrl, '/')];

    $normalizedIndexPage = trim($indexPage, '/');
    if ($normalizedIndexPage !== '') {
        $segments[] = $normalizedIndexPage;
    }

    $normalizedPath = trim($path, '/');
    if ($normalizedPath !== '') {
        $segments[] = $normalizedPath;
    }

    $url = implode('/', $segments);

    if ($query !== []) {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
    }

    return $url;
}

function toPositiveInt(mixed $value, string $context): int
{
    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        $parsed = (int) trim($value);
    } else {
        throw new GateAssertionException($context . ' must be a positive integer.');
    }

    if ($parsed <= 0) {
        throw new GateAssertionException($context . ' must be a positive integer.');
    }

    return $parsed;
}

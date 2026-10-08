<?php
declare(strict_types=1);

require_once __DIR__ . '/../release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../release-gate/lib/GateCliSupport.php';
require_once __DIR__ . '/../release-gate/lib/GateHttpClient.php';
require_once __DIR__ . '/../release-gate/lib/PlaywrightCookieRecords.php';
require_once __DIR__ . '/lib/BrowserRuntimeEvidence.php';
require_once __DIR__ . '/lib/CheckSelection.php';
require_once __DIR__ . '/lib/DashboardSummaryBrowserCheck.php';
require_once __DIR__ . '/lib/LdapFixtureCleanup.php';

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
use ReleaseGate\GateProcessRunner;
const INTEGRATION_SMOKE_EXIT_SUCCESS = 0;
const INTEGRATION_SMOKE_EXIT_ASSERTION_FAILURE = 1;
const INTEGRATION_SMOKE_EXIT_RUNTIME_ERROR = 2;

$checks = [];
$failure = null;
$exitCode = INTEGRATION_SMOKE_EXIT_SUCCESS;
$reportPath = null;
$browserEvidence = null;
$ldapFixtureCleanup = null;

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

            GateAssertions::assertStatus($response->statusCode, 200, 'POST /login/validate (LDAP SSO wrong password)');
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

            $dashboardResponse = $ldapClient->get('dashboard', [], $config['http_timeout']);
            GateAssertions::assertStatus(
                $dashboardResponse->statusCode,
                200,
                'GET /dashboard after LDAP SSO wrong password',
            );

            if (!str_contains($dashboardResponse->url, '/login')) {
                throw new GateAssertionException(
                    'LDAP wrong-password login must leave the client anonymous; dashboard did not redirect to login.',
                );
            }

            if (!str_contains($dashboardResponse->body, 'id="login-form"')) {
                throw new GateAssertionException(
                    'LDAP wrong-password login must leave the client anonymous; dashboard response was not the login page.',
                );
            }

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'message' => $message,
                'anonymous_dashboard_redirect' => true,
            ];
        });
    }

    if (shouldRunConfiguredCheck($config, 'ldap_sso_operational_failure')) {
        $runCheck('ldap_sso_operational_failure', static function () use ($config, $repoRoot): array {
            $ldapClient = dashboardIntegrationSmokeCreateClient($config);
            dashboardIntegrationSmokeWarmLoginCsrf($ldapClient, $config);
            $logMarker = 'LDAP authentication unavailable; login rejected.';
            $markerCountBefore = dashboardIntegrationSmokeCountLogMarker($repoRoot, $logMarker);

            $response = dashboardIntegrationSmokeWithLdapSettings(
                $repoRoot,
                [
                    'ldap_host' => '127.0.0.1',
                    'ldap_port' => '1',
                ],
                static fn() => $ldapClient->post(
                    'login/validate',
                    [
                        'username' => $config['ldap_guardrail_username'],
                        'password' => $config['ldap_guardrail_wrong_password'],
                    ],
                    $config['http_timeout'],
                    true,
                ),
            );

            GateAssertions::assertStatus($response->statusCode, 200, 'POST /login/validate (LDAP operational failure)');
            $payload = GateAssertions::decodeJson($response->body, 'POST /login/validate (LDAP operational failure)');
            if (
                $payload !== [
                    'success' => false,
                    'message' => lang('invalid_credentials_provided'),
                ]
            ) {
                throw new GateAssertionException(
                    'LDAP operational failure must return the generic invalid-credentials payload.',
                );
            }

            $markerCountAfter = dashboardIntegrationSmokeCountLogMarker($repoRoot, $logMarker);
            if ($markerCountAfter <= $markerCountBefore) {
                throw new GateAssertionException('LDAP operational failure did not write the fixed App Log marker.');
            }

            return [
                'http_status' => $response->statusCode,
                'url' => $response->url,
                'generic_response' => true,
                'app_log_marker_written' => true,
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

    if (shouldRunConfiguredCheck($config, 'calendar_unavailability_dialog_browser')) {
        $runCheck('calendar_unavailability_dialog_browser', static function () use (
            $client,
            $config,
            $repoRoot,
        ): array {
            return dashboardIntegrationSmokeAssertCalendarDialogBrowser($client, $config, $repoRoot);
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

    if (shouldRunConfiguredCheck($config, 'booking_checkout_browser')) {
        $runCheck('booking_checkout_browser', static function () use (
            $config,
            $repoRoot,
            &$providerServicePair,
            &$resolvedBooking,
        ): array {
            return dashboardIntegrationSmokeAssertBookingCheckoutBrowser(
                $config,
                $repoRoot,
                $providerServicePair,
                $resolvedBooking,
            );
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

dashboardIntegrationSmokeRunLdapFixtureCleanup($ldapFixtureCleanup, $exitCode, $failure);

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
        getOptionalOption($options, 'browser-bootstrap-timeout', 180),
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
        --start-date=2026-01-12 \
        --end-date=2026-01-16

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
      --browser-bootstrap-timeout=180
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

    $json = json_encode(jsonSafeValue($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($outputPath, $json . PHP_EOL);

    return $outputPath;
}

function jsonSafeValue(mixed $value): mixed
{
    if (is_array($value)) {
        $safe = [];
        foreach ($value as $key => $item) {
            $safe[$key] = jsonSafeValue($item);
        }

        return $safe;
    }

    if (!is_string($value)) {
        return $value;
    }

    if (preg_match('//u', $value) === 1) {
        return $value;
    }

    return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
}

/**
 * @return array<int, string>
 */
function integrationSmokeSupportedCheckIds(): array
{
    return [
        'readiness_login_page',
        'auth_login_validate',
        'ldap_sso_success',
        'ldap_sso_wrong_password',
        'ldap_sso_operational_failure',
        'dashboard_metrics',
        'dashboard_page_readiness',
        'dashboard_summary_browser_render',
        'calendar_unavailability_dialog_browser',
        'booking_page_readiness',
        'booking_extract_bootstrap',
        'booking_available_hours',
        'booking_checkout_browser',
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
        'ldap_sso_success' => [],
        'ldap_sso_wrong_password' => [],
        'ldap_sso_operational_failure' => [],
        'dashboard_metrics' => ['auth_login_validate'],
        'dashboard_page_readiness' => ['auth_login_validate'],
        'dashboard_summary_browser_render' => ['auth_login_validate'],
        'calendar_unavailability_dialog_browser' => ['dashboard_summary_browser_render'],
        'booking_page_readiness' => [],
        'booking_extract_bootstrap' => ['booking_page_readiness'],
        'booking_available_hours' => ['booking_extract_bootstrap'],
        'booking_checkout_browser' => ['booking_available_hours'],
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
    return ['ldap_sso_success', 'ldap_sso_wrong_password', 'ldap_sso_operational_failure'];
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

function dashboardIntegrationSmokeCountLogMarker(string $repoRoot, string $marker): int
{
    $count = 0;
    foreach (glob(rtrim($repoRoot, '/') . '/storage/logs/log-*.php') ?: [] as $path) {
        $contents = file_get_contents($path);
        if ($contents !== false) {
            $count += substr_count($contents, $marker);
        }
    }

    return $count;
}

/**
 * Run a bounded LDAP check with temporary settings and restore them afterward.
 *
 * @param array<string, string> $overrides
 */
function dashboardIntegrationSmokeWithLdapSettings(string $repoRoot, array $overrides, callable $callback): mixed
{
    $CI = dashboardIntegrationSmokeBootstrapApplication($repoRoot);
    $names = ['ldap_is_active', 'ldap_host', 'ldap_port'];
    $snapshot = $CI->db->where_in('name', $names)->get('settings')->result_array();

    try {
        setting($overrides);

        return $callback();
    } finally {
        $snapshotByName = [];
        foreach ($snapshot as $row) {
            $snapshotByName[(string) $row['name']] = $row;
        }

        foreach ($names as $name) {
            if (isset($snapshotByName[$name])) {
                $CI->db->update('settings', ['value' => $snapshotByName[$name]['value']], ['name' => $name]);
            } else {
                $CI->db->delete('settings', ['name' => $name]);
            }
        }
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
    $sessionCookies = \ReleaseGate\normalizeCookieRecordsForPlaywright($client->cookieRecords(), $targetUrl);

    if ($sessionCookies === []) {
        throw new GateAssertionException(
            'POST /login/validate (dashboard summary browser render) did not yield reusable session cookies.',
        );
    }
    $payload = runDashboardSummaryBrowserCheck([
        'repo_root' => $repoRoot,
        'target_url' => $targetUrl,
        'artifacts_dir' => $artifactsDir,
        'session_cookies' => $sessionCookies,
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
 * Exercise the real calendar dialog in the existing local browser runtime.
 * The browser intercepts its save request; ROB-767 covers the server and DB path.
 *
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function dashboardIntegrationSmokeAssertCalendarDialogBrowser(
    GateHttpClient $client,
    array $config,
    string $repoRoot,
): array {
    $targetUrl = dashboardIntegrationSmokeBuildAppUrl($config, 'calendar');
    $cookies = \ReleaseGate\normalizeCookieRecordsForPlaywright($client->cookieRecords(), $targetUrl);

    if ($cookies === []) {
        throw new GateAssertionException('Calendar dialog browser check has no authenticated session cookies.');
    }

    $script = $repoRoot . '/scripts/ci/calendar_unavailability_dialog_browser.js';
    if (!is_file($script) || !is_readable($script)) {
        throw new GateAssertionException('Calendar dialog browser check script is unavailable.');
    }

    $input = json_encode(
        [
            'base_url' => dashboardIntegrationSmokeBuildAppUrl($config, ''),
            'target_url' => $targetUrl,
            'session_cookies' => $cookies,
            'browser' => \ReleaseGate\resolveConfiguredPlaywrightBrowser(),
            'browser_executable_path' => (string) (getenv('PLAYWRIGHT_MCP_EXECUTABLE_PATH') ?: ''),
            'browser_open_timeout' => (int) $config['browser_open_timeout'],
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $result = GateProcessRunner::run(
        ['node', $script],
        $repoRoot,
        null,
        max(115, (int) $config['browser_open_timeout'] * 3 + 55),
        $input,
    );

    return dashboardIntegrationSmokeParseCalendarDialogBrowserResult($result);
}

/**
 * Exercise the public booking wizard through name-only checkout. The browser
 * submits the real payload to booking/register, which is fulfilled with a
 * synthetic confirmation hash so this CI smoke leaves no appointment behind.
 *
 * @param array<string, mixed> $config
 * @param array<string, mixed>|null $providerServicePair
 * @param array<string, mixed>|null $resolvedBooking
 * @return array<string, mixed>
 */
function dashboardIntegrationSmokeAssertBookingCheckoutBrowser(
    array $config,
    string $repoRoot,
    ?array $providerServicePair,
    ?array $resolvedBooking,
): array {
    if (!is_array($providerServicePair) || !is_array($resolvedBooking)) {
        throw new GateAssertionException(
            'Booking checkout browser check requires the resolved fixture provider, service, and date.',
        );
    }
    $script = $repoRoot . '/scripts/ci/booking_checkout_browser.js';
    if (!is_file($script) || !is_readable($script)) {
        throw new GateAssertionException('Booking checkout browser check script is unavailable.');
    }

    $input = json_encode(
        [
            'base_url' => dashboardIntegrationSmokeBuildAppUrl($config, ''),
            'target_url' => dashboardIntegrationSmokeBuildAppUrl($config, 'booking'),
            'browser' => \ReleaseGate\resolveConfiguredPlaywrightBrowser(),
            'executable_path' => (string) (getenv('PLAYWRIGHT_MCP_EXECUTABLE_PATH') ?: ''),
            'open_timeout' => (int) $config['browser_open_timeout'],
            'first_name' => 'Browser',
            'last_name' => 'Checkout',
            'expected_provider_id' => (int) $providerServicePair['provider_id'],
            'expected_service_id' => (int) $providerServicePair['service_id'],
            'expected_date' => (string) $resolvedBooking['date'],
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $browserStartedAt = hrtime(true);
    $result = dashboardIntegrationSmokeWithNameOnlyBookingSettings(
        $config,
        $repoRoot,
        static fn(): array => GateProcessRunner::run(
            ['node', $script],
            $repoRoot,
            null,
            max(115, (int) $config['browser_open_timeout'] * 4 + 55),
            $input,
        ),
    );
    $browserDurationMs = (int) ((hrtime(true) - $browserStartedAt) / 1_000_000);

    if (($result['timed_out'] ?? false) || ($result['exit_code'] ?? 1) !== 0) {
        throw new GateAssertionException(
            'Booking checkout browser check failed: ' . trim((string) ($result['stderr'] ?? 'runtime')),
        );
    }

    $payload = json_decode(trim((string) ($result['stdout'] ?? '')), true);
    if (!is_array($payload)) {
        throw new GateAssertionException('Booking checkout browser check returned no valid result.');
    }
    foreach (
        [
            'ok',
            'public_service_options_verified',
            'fixture_pair_verified',
            'selected_provider_verified',
            'selected_slot_verified',
            'name_only_payload_verified',
            'blank_name_rejection_verified',
            'register_payload_verified',
            'confirmation_navigation_verified',
            'no_persistence_verified',
        ]
        as $property
    ) {
        if (($payload[$property] ?? null) !== true) {
            throw new GateAssertionException('Booking checkout browser check did not verify ' . $property . '.');
        }
    }

    return [
        'public_service_options_verified' => true,
        'fixture_pair_verified' => true,
        'selected_provider_verified' => true,
        'selected_slot_verified' => true,
        'name_only_payload_verified' => true,
        'blank_name_rejection_verified' => true,
        'register_payload_verified' => true,
        'confirmation_navigation_verified' => true,
        'no_persistence_verified' => true,
        'register_requests' => (int) ($payload['register_requests'] ?? 0),
        'confirmation_requests' => (int) ($payload['confirmation_requests'] ?? 0),
        'browser_duration_ms' => $browserDurationMs,
    ];
}

/**
 * Temporarily disable non-name customer fields for the isolated browser
 * checkout. This mutation is permitted only against the isolated Docker-local
 * smoke stack or the exact loopback GitHub Actions smoke stack. Restore the
 * original setting rows before returning.
 *
 * @param array<string, mixed> $config
 * @param callable():array<string, mixed> $callback
 * @return array<string, mixed>
 */
function dashboardIntegrationSmokeWithNameOnlyBookingSettings(
    array $config,
    string $repoRoot,
    callable $callback,
): array {
    $CI = dashboardIntegrationSmokeBootstrapApplication($repoRoot);
    $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    $dbHost = defined('Config::DB_HOST') ? Config::DB_HOST : '';
    $dockerLocal = $baseUrl === 'http://nginx' && $dbHost === 'mysql';
    $actionsLocal =
        getenv('GITHUB_ACTIONS') === 'true' &&
        getenv('CI') === 'true' &&
        $baseUrl === 'http://127.0.0.1:8080' &&
        $dbHost === '127.0.0.1';
    if (!$dockerLocal && !$actionsLocal) {
        throw new GateAssertionException('Name-only booking fixture requires an isolated local smoke stack.');
    }

    $names = [
        'display_first_name',
        'require_first_name',
        'display_last_name',
        'require_last_name',
        'require_email',
        'display_email',
        'require_phone_number',
        'display_phone_number',
        'require_address',
        'display_address',
        'require_city',
        'display_city',
        'require_zip_code',
        'display_zip_code',
        'require_notes',
        'display_notes',
        'require_captcha',
        'display_custom_field_1',
        'require_custom_field_1',
        'display_custom_field_2',
        'require_custom_field_2',
        'display_custom_field_3',
        'require_custom_field_3',
        'display_custom_field_4',
        'require_custom_field_4',
        'display_custom_field_5',
        'require_custom_field_5',
    ];
    $snapshot = $CI->db->where_in('name', $names)->get('settings')->result_array();
    $snapshotByName = [];
    foreach ($snapshot as $row) {
        $snapshotByName[(string) $row['name']] = $row;
    }
    ksort($snapshotByName);
    $mutationTables = ['appointments', 'users', 'consents'];
    $beforeCounts = [];
    foreach ($mutationTables as $table) {
        $beforeCounts[$table] = (int) $CI->db->count_all_results($table);
    }

    try {
        setting([
            'display_first_name' => '1',
            'require_first_name' => '1',
            'display_last_name' => '1',
            'require_last_name' => '1',
            'require_email' => '0',
            'display_email' => '0',
            'require_phone_number' => '0',
            'display_phone_number' => '0',
            'require_address' => '0',
            'display_address' => '0',
            'require_city' => '0',
            'display_city' => '0',
            'require_zip_code' => '0',
            'display_zip_code' => '0',
            'require_notes' => '0',
            'display_notes' => '0',
            'require_captcha' => '0',
            'display_custom_field_1' => '0',
            'require_custom_field_1' => '0',
            'display_custom_field_2' => '0',
            'require_custom_field_2' => '0',
            'display_custom_field_3' => '0',
            'require_custom_field_3' => '0',
            'display_custom_field_4' => '0',
            'require_custom_field_4' => '0',
            'display_custom_field_5' => '0',
            'require_custom_field_5' => '0',
        ]);

        return $callback();
    } finally {
        foreach ($names as $name) {
            if (isset($snapshotByName[$name])) {
                $restoreRow = $snapshotByName[$name];
                unset($restoreRow['id'], $restoreRow['name']);
                $CI->db->update('settings', $restoreRow, ['name' => $name]);
            } else {
                $CI->db->delete('settings', ['name' => $name]);
            }
        }

        $restoredRows = $CI->db->where_in('name', $names)->get('settings')->result_array();
        $restoredByName = [];
        foreach ($restoredRows as $row) {
            $restoredByName[(string) $row['name']] = $row;
        }
        ksort($restoredByName);

        if ($restoredByName !== $snapshotByName) {
            throw new GateAssertionException('Name-only booking fixture did not restore customer settings exactly.');
        }
        foreach ($mutationTables as $table) {
            if ((int) $CI->db->count_all_results($table) !== $beforeCounts[$table]) {
                throw new GateAssertionException('Name-only booking browser persisted unexpected ' . $table . ' rows.');
            }
        }
    }
}

/** @param array<string, mixed> $result */
function dashboardIntegrationSmokeParseCalendarDialogBrowserResult(array $result): array
{
    if (($result['timed_out'] ?? false) || ($result['exit_code'] ?? 1) !== 0) {
        $failureClass = trim((string) ($result['stderr'] ?? ''));
        if (
            !in_array(
                $failureClass,
                [
                    'input',
                    'launch',
                    'auth',
                    'create',
                    'dialog',
                    'failure',
                    'success',
                    'delete',
                    'working_plan_exception',
                    'payload',
                    'cleanup',
                ],
                true,
            )
        ) {
            $failureClass = 'runtime';
        }
        throw new GateAssertionException('Calendar dialog browser check failed: ' . $failureClass . '.');
    }

    $payload = json_decode(trim((string) ($result['stdout'] ?? '')), true);
    if (!is_array($payload)) {
        throw new GateAssertionException('Calendar dialog browser check returned no valid result.');
    }
    foreach (
        [
            'ok',
            'request_payload_verified',
            'failure_state_verified',
            'success_state_verified',
            'existing_event_prepopulation_verified',
            'edit_failure_state_verified',
            'edit_success_reload_verified',
            'delete_failure_state_verified',
            'delete_success_reload_verified',
            'working_plan_exception_create_verified',
            'working_plan_exception_edit_verified',
            'working_plan_exception_delete_verified',
            'table_view_literal_name_verified',
            'table_view_write_boundary_verified',
            'cleanup_verified',
        ]
        as $property
    ) {
        if (($payload[$property] ?? null) !== true) {
            throw new GateAssertionException('Calendar dialog browser check did not verify ' . $property . '.');
        }
    }
    if (!is_int($payload['duration_ms'] ?? null) || $payload['duration_ms'] < 0) {
        throw new GateAssertionException('Calendar dialog browser check returned no duration.');
    }
    if (!is_int($payload['working_plan_duration_ms'] ?? null) || $payload['working_plan_duration_ms'] < 0) {
        throw new GateAssertionException('Calendar dialog browser check returned no working-plan duration.');
    }

    return [
        'request_payload_verified' => true,
        'failure_state_verified' => true,
        'success_state_verified' => true,
        'existing_event_prepopulation_verified' => true,
        'edit_failure_state_verified' => true,
        'edit_success_reload_verified' => true,
        'delete_failure_state_verified' => true,
        'delete_success_reload_verified' => true,
        'working_plan_exception_create_verified' => true,
        'working_plan_exception_edit_verified' => true,
        'working_plan_exception_delete_verified' => true,
        'table_view_literal_name_verified' => true,
        'table_view_write_boundary_verified' => true,
        'cleanup_verified' => true,
        'browser_duration_ms' => $payload['duration_ms'],
        'working_plan_browser_duration_ms' => $payload['working_plan_duration_ms'],
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
 * @return array<string, mixed>
 */
function dashboardIntegrationSmokePrepareLdapAppGuardrailFixture(string $repoRoot): array
{
    global $ldapFixtureCleanup;

    $CI = dashboardIntegrationSmokeBootstrapApplication($repoRoot);

    $CI->load->helper('setting');
    $CI->load->model('admins_model');

    $guardrailUsername = 'ada-ldap-guardrail';
    $guardrailLocalPassword = 'guardrail-local-password';
    $guardrailExpectedDn = 'uid=ada,ou=people,dc=example,dc=org';
    $guardrailExpectedMail = 'ada.lovelace@example.org';

    $settingNames = ['ldap_is_active', 'ldap_host', 'ldap_port'];
    $settingSnapshot = $CI->db->where_in('name', $settingNames)->get('settings')->result_array();

    $existingUser = $CI->db
        ->select('users.*, roles.slug AS role_slug')
        ->from('user_settings')
        ->join('users', 'users.id = user_settings.id_users', 'inner')
        ->join('roles', 'roles.id = users.id_roles', 'inner')
        ->where('user_settings.username', $guardrailUsername)
        ->get()
        ->row_array();

    $existingUserSettings = [];
    if (!empty($existingUser['id'])) {
        $existingUserSettings = $CI->db
            ->get_where('user_settings', ['id_users' => (int) $existingUser['id']])
            ->row_array();
    }

    $ldapFixtureCleanup = static function () use (
        $CI,
        $settingNames,
        $settingSnapshot,
        $existingUser,
        $existingUserSettings,
        $guardrailUsername,
        &$ldapFixtureCleanup,
    ): void {
        $snapshotByName = [];
        foreach ($settingSnapshot as $row) {
            $snapshotByName[(string) $row['name']] = $row;
        }

        foreach ($settingNames as $name) {
            if (isset($snapshotByName[$name])) {
                $CI->db->update('settings', ['value' => $snapshotByName[$name]['value']], ['name' => $name]);
            } else {
                $CI->db->delete('settings', ['name' => $name]);
            }
        }

        if (!empty($existingUser['id'])) {
            $userId = (int) $existingUser['id'];
            $userRow = $existingUser;
            unset($userRow['role_slug'], $userRow['username'], $userRow['password'], $userRow['salt']);
            $CI->db->update('users', $userRow, ['id' => $userId]);

            if ($existingUserSettings !== []) {
                $settingsRow = $existingUserSettings;
                unset($settingsRow['id']);
                $CI->db->update('user_settings', $settingsRow, ['id_users' => $userId]);
            }
        } else {
            $newUser = $CI->db
                ->select('id_users')
                ->get_where('user_settings', ['username' => $guardrailUsername])
                ->row_array();
            if (!empty($newUser['id_users'])) {
                $CI->db->delete('user_settings', ['id_users' => (int) $newUser['id_users']]);
                $CI->db->delete('users', ['id' => (int) $newUser['id_users']]);
            }
        }

        $ldapFixtureCleanup = null;
    };

    if (!empty($existingUser) && ($existingUser['role_slug'] ?? null) !== DB_SLUG_ADMIN) {
        throw new RuntimeException(
            'LDAP guardrail username "' . $guardrailUsername . '" is already used by a non-admin account.',
        );
    }

    setting([
        'ldap_is_active' => '1',
        'ldap_host' => 'openldap',
        'ldap_port' => '389',
    ]);

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

    try {
        $CI->admins_model->save($admin);
    } catch (Throwable $exception) {
        $ldapFixtureCleanup();
        throw $exception;
    }

    return [
        'ldap_guardrail_username' => $guardrailUsername,
        'ldap_guardrail_directory_password' => 'ada-local-pass',
        'ldap_guardrail_wrong_password' => 'definitely-wrong-password',
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

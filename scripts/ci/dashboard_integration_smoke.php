<?php
declare(strict_types=1);

require_once __DIR__ . '/../release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../release-gate/lib/GateCliSupport.php';
require_once __DIR__ . '/../release-gate/lib/GateHttpClient.php';

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

$repoRoot = dirname(__DIR__, 2);
$csrfDefaults = GateCliSupport::resolveCsrfNamesFromConfig($repoRoot . '/application/config/config.php');

try {
    $config = parseCliOptions($csrfDefaults);

    $client = new GateHttpClient(
        $config['base_url'],
        $config['index_page'],
        $config['http_timeout'],
        'dashboard-booking-api-integration-smoke/1.0',
        $config['csrf_cookie_name'],
        $config['csrf_token_name'],
    );

    $bookingPageHtml = null;
    $bookingBootstrap = null;
    $providerServicePairs = null;
    $providerServicePair = null;
    $resolvedBooking = null;

    $runCheck = static function (string $name, callable $callback) use (&$checks): void {
        try {
            $details = $callback();

            if (!is_array($details)) {
                $details = ['detail' => (string) $details];
            }

            $checks[] = array_merge(
                [
                    'name' => $name,
                    'status' => 'pass',
                ],
                $details,
            );

            fwrite(STDOUT, '[PASS] ' . $name . PHP_EOL);
        } catch (Throwable $e) {
            $checks[] = [
                'name' => $name,
                'status' => 'fail',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ];

            fwrite(STDERR, '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL);
            throw $e;
        }
    };

    $runCheck('readiness_login_page', static function () use ($client, $config): array {
        $response = $client->get('login', [], $config['http_timeout']);
        GateAssertions::assertStatus($response->statusCode, 200, 'GET /login');

        $csrfCookie = $client->getCookie($config['csrf_cookie_name']);
        if ($csrfCookie === null || $csrfCookie === '') {
            throw new GateAssertionException('GET /login did not set cookie "' . $config['csrf_cookie_name'] . '".');
        }

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'csrf_cookie_present' => true,
        ];
    });

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

    $runCheck('booking_available_hours', static function () use (
        $client,
        $config,
        &$providerServicePairs,
        &$providerServicePair,
        &$resolvedBooking,
    ): array {
        if (!is_array($providerServicePairs) || $providerServicePairs === []) {
            throw new GateAssertionException('Provider/service pairs were not resolved from booking bootstrap data.');
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

    $runCheck('api_unauthorized_guard', static function () use ($config): array {
        $response = apiGetWithBasicAuth($config, 'api/v1/appointments', ['length' => 1], null, null);

        GateAssertions::assertStatus($response['status_code'], 401, 'GET /api/v1/appointments (without auth)');

        return [
            'http_status' => $response['status_code'],
            'url' => $response['url'],
        ];
    });

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

    $runCheck('api_availabilities', static function () use ($config, &$providerServicePair, &$resolvedBooking): array {
        if (!is_array($providerServicePair) || !is_array($resolvedBooking)) {
            throw new GateAssertionException('api_availabilities requires resolved provider/service and booking date.');
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

$passedChecks = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? null) === 'pass'));

if ($exitCode === INTEGRATION_SMOKE_EXIT_SUCCESS) {
    fwrite(STDOUT, sprintf('[PASS] Integration smoke passed (%d checks).%s', $passedChecks, PHP_EOL));
} else {
    $message = $failure['message'] ?? 'unknown failure';
    fwrite(STDERR, sprintf('[FAIL] Integration smoke failed (exit %d): %s%s', $exitCode, $message, PHP_EOL));
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
 *   csrf_cookie_name:string
 * }
 */
function parseCliOptions(array $csrfDefaults): array
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
    ]);

    if (!is_array($options)) {
        throw new InvalidArgumentException('Failed to parse CLI options.');
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
    ];
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

    $startDate = new DateTimeImmutable('tomorrow', new DateTimeZone('UTC'));
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

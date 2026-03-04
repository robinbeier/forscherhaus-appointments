<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../release-gate/lib/GateHttpClient.php';
require_once __DIR__ . '/lib/OpenApiContractValidator.php';
require_once __DIR__ . '/lib/DeterministicFixtureFactory.php';
require_once __DIR__ . '/lib/WriteContractCleanupRegistry.php';
require_once __DIR__ . '/lib/FlakeRetry.php';

use CiContract\ContractAssertionException;
use CiContract\DeterministicFixtureFactory;
use CiContract\FlakeRetry;
use CiContract\OpenApiContractValidator;
use CiContract\WriteContractCleanupRegistry;
use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateHttpClient;

const API_OPENAPI_WRITE_CONTRACT_EXIT_SUCCESS = 0;
const API_OPENAPI_WRITE_CONTRACT_EXIT_ASSERTION_FAILURE = 1;
const API_OPENAPI_WRITE_CONTRACT_EXIT_RUNTIME_ERROR = 2;

$checks = [];
$state = [];
$failure = null;
$exitCode = API_OPENAPI_WRITE_CONTRACT_EXIT_SUCCESS;
$reportPath = null;
$retryMetadata = [
    'max_retries' => 0,
    'attempts' => 0,
    'retry_events' => [],
];

try {
    $config = parseCliOptions();
    $matrix = loadContractMatrix($config['matrix_file']);
    $validator = OpenApiContractValidator::fromFile($config['openapi_spec']);

    if (($matrix['read_only'] ?? null) !== false) {
        throw new ContractAssertionException('Write contract matrix must explicitly set "read_only" to false.');
    }

    if (!isset($matrix['checks']) || !is_array($matrix['checks']) || $matrix['checks'] === []) {
        throw new ContractAssertionException('Write contract matrix must contain a non-empty "checks" array.');
    }

    $retryMetadata['max_retries'] = $config['retry_count'];

    for ($attempt = 1; ; $attempt++) {
        $retryMetadata['attempts'] = $attempt;

        $attemptChecks = [];
        $attemptState = [];
        $attemptCleanup = [
            'created' => [],
            'deleted' => [],
            'failures' => [],
        ];

        try {
            runApiWriteContractsAttempt(
                $config,
                $validator,
                $matrix['checks'],
                $attempt,
                $attemptChecks,
                $attemptState,
                $attemptCleanup,
            );

            $checks = $attemptChecks;
            $state = $attemptState;
            break;
        } catch (Throwable $e) {
            $checks = $attemptChecks;
            $state = $attemptState;
            $state['cleanup'] = $attemptCleanup;

            $decision = FlakeRetry::decide(
                $e,
                $attempt,
                $config['retry_count'],
                static fn(Throwable $error): bool => $error instanceof ContractAssertionException,
            );

            if ($decision['retry']) {
                $retryMetadata['retry_events'][] = [
                    'attempt' => $attempt,
                    'classification' => $decision['classification'],
                    'reason' => $decision['reason'],
                    'exception' => get_class($e),
                ];

                fwrite(
                    STDERR,
                    sprintf(
                        '[WARN] Attempt %d failed with transient runtime error; retrying once.%s',
                        $attempt,
                        PHP_EOL,
                    ),
                );
                continue;
            }

            $failure = [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'classification' => $decision['classification'],
            ];
            $exitCode =
                $e instanceof ContractAssertionException
                    ? API_OPENAPI_WRITE_CONTRACT_EXIT_ASSERTION_FAILURE
                    : API_OPENAPI_WRITE_CONTRACT_EXIT_RUNTIME_ERROR;
            break;
        }
    }
} catch (ContractAssertionException $e) {
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
        'classification' => 'contract_mismatch',
    ];
    $exitCode = API_OPENAPI_WRITE_CONTRACT_EXIT_ASSERTION_FAILURE;
} catch (Throwable $e) {
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
        'classification' => 'runtime_error',
    ];
    $exitCode = API_OPENAPI_WRITE_CONTRACT_EXIT_RUNTIME_ERROR;
}

try {
    $reportPath = writeReport($config ?? [], $checks, $state, $retryMetadata, $failure);
} catch (Throwable $e) {
    fwrite(STDERR, '[WARN] Failed to write API write contract report: ' . $e->getMessage() . PHP_EOL);
}

$passedChecks = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'pass'));
$totalChecks = count($checks);

if ($exitCode === API_OPENAPI_WRITE_CONTRACT_EXIT_SUCCESS) {
    fwrite(
        STDOUT,
        sprintf(
            '[PASS] API OpenAPI write contract smoke passed (%d/%d checks).%s',
            $passedChecks,
            $totalChecks,
            PHP_EOL,
        ),
    );
} else {
    fwrite(
        STDERR,
        sprintf(
            '[FAIL] API OpenAPI write contract smoke failed: %s%s',
            $failure['message'] ?? 'unknown failure',
            PHP_EOL,
        ),
    );
}

if ($reportPath !== null) {
    fwrite(STDOUT, '[INFO] Report: ' . $reportPath . PHP_EOL);
}

exit($exitCode);

/**
 * @param array<string, mixed> $config
 * @param array<int, array<string, mixed>> $matrixChecks
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $state
 * @param array<string, mixed> $cleanupSummary
 */
function runApiWriteContractsAttempt(
    array $config,
    OpenApiContractValidator $validator,
    array $matrixChecks,
    int $attempt,
    array &$checks,
    array &$state,
    array &$cleanupSummary,
): void {
    $factory = new DeterministicFixtureFactory($config['run_id'], $config['booking_search_days'], $config['timezone']);

    $state = [
        'run_id' => $factory->runId(),
        'attempt' => $attempt,
    ];

    $cleanup = new WriteContractCleanupRegistry();
    $cleanup->addFallbackSweeper(static fn(): array => sweepRunMarkerResources($config, $factory));

    $bookingClient = new GateHttpClient(
        $config['base_url'],
        $config['index_page'],
        $config['http_timeout'],
        'api-openapi-write-contract-smoke/1.0',
        $config['csrf_cookie_name'],
        $config['csrf_token_name'],
    );

    try {
        $bookingPage = $bookingClient->get('booking', [], $config['http_timeout']);
        GateAssertions::assertStatus($bookingPage->statusCode, 200, 'GET /booking (fixture bootstrap)');
        $bootstrap = $factory->extractBookingBootstrap($bookingPage->body);
        $pairs = $factory->resolveProviderServicePairs($bootstrap);
        $slot = $factory->resolveBookableSlot($bookingClient, $config['http_timeout'], $pairs);

        $state['provider_id'] = $slot['provider_id'];
        $state['service_id'] = $slot['service_id'];
        $state['slot_start'] = $slot['start_datetime'];
        $state['slot_end'] = $slot['end_datetime'];
        $state['slot_date'] = $slot['date'];
        $state['slot_hour'] = $slot['hour'];

        foreach ($matrixChecks as $check) {
            if (!is_array($check)) {
                throw new ContractAssertionException('Each write contract check definition must be an object.');
            }

            runCheck(
                requiredString($check, 'id'),
                static function () use ($check, $config, $validator, $factory, &$state, $cleanup, $slot): array {
                    return runApiWriteContractCheck($check, $config, $validator, $factory, $state, $cleanup, $slot);
                },
                $checks,
            );
        }
    } finally {
        $cleanupSummary = $cleanup->cleanup();
        $state['cleanup'] = $cleanupSummary;
    }
}

/**
 * @param array<string, mixed> $check
 * @param array<string, mixed> $config
 * @param array<string, mixed> $state
 * @param array<string, mixed> $slot
 * @return array<string, mixed>
 */
function runApiWriteContractCheck(
    array $check,
    array $config,
    OpenApiContractValidator $validator,
    DeterministicFixtureFactory $factory,
    array &$state,
    WriteContractCleanupRegistry $cleanup,
    array $slot,
): array {
    $id = requiredString($check, 'id');
    $method = strtoupper(requiredString($check, 'method'));
    $openapiPath = requiredString($check, 'openapi_path');
    $expectedStatus = requiredInt($check, 'expected_status');
    $authMode = (string) ($check['auth'] ?? 'basic');

    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        throw new ContractAssertionException($id . ' has unsupported method: ' . $method);
    }

    if (!in_array($authMode, ['none', 'basic'], true)) {
        throw new ContractAssertionException($id . ' has unsupported auth mode: ' . $authMode);
    }

    $skipIfStateMissing = array_values(array_filter($check['skip_if_state_missing'] ?? [], 'is_string'));
    if ($skipIfStateMissing !== []) {
        $missingKeys = [];
        foreach ($skipIfStateMissing as $stateKey) {
            if (!array_key_exists($stateKey, $state) || $state[$stateKey] === null || $state[$stateKey] === '') {
                $missingKeys[] = $stateKey;
            }
        }

        if ($missingKeys !== []) {
            return [
                'skipped' => true,
                'reason' => 'Missing runtime fixture state: ' . implode(', ', $missingKeys),
            ];
        }
    }

    $validator->getOperation($method, $openapiPath);
    $validator->assertOperationHasResponse($method, $openapiPath, $expectedStatus);

    if ($authMode === 'basic') {
        $validator->assertOperationSupportsKnownAuth($method, $openapiPath);
    }

    $requestPath = resolveRequestPath($check, $state);
    $payload = resolvePayload($check, $factory, $state, $slot);

    if ($payload !== null) {
        $requestSchema = $validator->getRequestSchema($method, $openapiPath);
        $validator->assertValueMatchesSchema($payload, $requestSchema, $id . ' request payload');

        $requestSchemaRef = $check['request_schema_ref'] ?? null;
        if (is_string($requestSchemaRef) && $requestSchemaRef !== '') {
            $requiredFields = array_values(array_filter($check['request_required_fields'] ?? [], 'is_string'));
            $validator->assertObjectFieldsMatchSchema($payload, $requestSchemaRef, $requiredFields, $id . '.request');
        }
    }

    $response = apiJsonRequest($method, $config, $requestPath, $payload, $authMode === 'basic', [$expectedStatus]);

    if (
        ($check['require_www_authenticate'] ?? false) === true &&
        headerValue($response['headers'], 'www-authenticate') === null
    ) {
        throw new ContractAssertionException($id . ' expected WWW-Authenticate header on unauthorized response.');
    }

    $details = [
        'http_status' => $response['status_code'],
        'url' => $response['url'],
        'duration_ms' => $response['duration_ms'],
    ];

    $expectNoContent = ($check['expect_no_content'] ?? false) === true || $expectedStatus === 204;

    if ($expectNoContent) {
        if (trim($response['body']) !== '') {
            throw new ContractAssertionException($id . ' expected empty response body for no-content response.');
        }
    } else {
        $responseSchema = $validator->getResponseSchemaOrNull($method, $openapiPath, $expectedStatus);

        if ($responseSchema !== null) {
            $decoded = decodeJsonWithRaw($response['body'], $id . ' response');

            $validator->assertRawJsonValueMatchesSchemaType($decoded['raw'], $responseSchema, $id . ' response');

            $allowEmptyTopLevelObject =
                is_object($decoded['raw']) && is_array($decoded['value']) && $decoded['value'] === [];
            $validator->assertValueMatchesSchema(
                $decoded['value'],
                $responseSchema,
                $id . ' response',
                $allowEmptyTopLevelObject,
                $decoded['raw'],
            );

            $responseSchemaRef = $check['response_schema_ref'] ?? null;
            if (is_string($responseSchemaRef) && $responseSchemaRef !== '') {
                if (!is_array($decoded['value']) || array_is_list($decoded['value'])) {
                    throw new ContractAssertionException($id . ' expected object response payload.');
                }

                $requiredFields = array_values(array_filter($check['response_required_fields'] ?? [], 'is_string'));
                $validator->assertObjectFieldsMatchSchema(
                    $decoded['value'],
                    $responseSchemaRef,
                    $requiredFields,
                    $id . '.response',
                    $decoded['raw'],
                );
            }

            if (isset($check['capture_id_to'])) {
                $captureKey = (string) $check['capture_id_to'];
                $capturedId = $decoded['value']['id'] ?? null;
                if (!is_int($capturedId) || $capturedId <= 0) {
                    throw new ContractAssertionException(
                        $id . ' could not capture positive integer "id" from response.',
                    );
                }

                $state[$captureKey] = $capturedId;
                $details['captured'] = [$captureKey => $capturedId];
            }
        } elseif ($expectedStatus >= 200 && $expectedStatus < 300) {
            throw new ContractAssertionException(
                sprintf('%s expected JSON response schema for HTTP %d.', $id, $expectedStatus),
            );
        }
    }

    if ($id === 'customers_store_contract') {
        $customerId = toPositiveInt($state['customer_id'] ?? null, 'customer_id');
        $cleanup->register(
            'customers',
            $customerId,
            static fn(int|string $resourceId): bool => apiDeleteById($config, 'customers', (int) $resourceId, [
                204,
                404,
            ]),
        );
    }

    if ($id === 'appointments_store_contract') {
        $appointmentId = toPositiveInt($state['appointment_id'] ?? null, 'appointment_id');
        $cleanup->register(
            'appointments',
            $appointmentId,
            static fn(int|string $resourceId): bool => apiDeleteById($config, 'appointments', (int) $resourceId, [
                204,
                404,
            ]),
        );
    }

    return $details;
}

/**
 * @param array<string, mixed> $check
 * @param array<string, mixed> $state
 */
function resolveRequestPath(array $check, array $state): string
{
    if (isset($check['request_path']) && is_string($check['request_path']) && $check['request_path'] !== '') {
        return trim($check['request_path'], '/');
    }

    $template = isset($check['request_path_template']) ? (string) $check['request_path_template'] : '';
    if ($template === '') {
        throw new ContractAssertionException(
            requiredString($check, 'id') . ' must define request_path or request_path_template.',
        );
    }

    $params = $check['path_params'] ?? [];
    if (!is_array($params)) {
        throw new ContractAssertionException(requiredString($check, 'id') . ' path_params must be an object.');
    }

    foreach ($params as $name => $rawValue) {
        if (!is_string($name) || $name === '') {
            continue;
        }

        $resolved = resolveDynamicValue($rawValue, $state);
        if (!is_scalar($resolved)) {
            throw new ContractAssertionException(
                requiredString($check, 'id') . ' path parameter "' . $name . '" must resolve to scalar value.',
            );
        }

        $template = str_replace('{' . $name . '}', rawurlencode((string) $resolved), $template);
    }

    if (preg_match('/\{[^}]+\}/', $template) === 1) {
        throw new ContractAssertionException(
            requiredString($check, 'id') . ' has unresolved path template placeholders: ' . $template,
        );
    }

    return trim($template, '/');
}

/**
 * @param array<string, mixed> $check
 * @param array<string, mixed> $state
 * @param array<string, mixed> $slot
 * @return array<string, mixed>|null
 */
function resolvePayload(array $check, DeterministicFixtureFactory $factory, array $state, array $slot): ?array
{
    $builder = $check['payload_builder'] ?? null;
    if (!is_string($builder) || $builder === '') {
        return null;
    }

    return match ($builder) {
        'customer' => $factory->createApiCustomerPayload(),
        'appointment_create' => $factory->createApiAppointmentPayload(
            toPositiveInt($state['customer_id'] ?? null, 'appointment_create.customer_id'),
            (int) $slot['provider_id'],
            (int) $slot['service_id'],
            (string) $slot['start_datetime'],
            (string) $slot['end_datetime'],
            [
                'notes' => 'run:' . $state['run_id'] . ':appointment-create',
            ],
        ),
        'appointment_update' => $factory->createApiAppointmentPayload(
            toPositiveInt($state['customer_id'] ?? null, 'appointment_update.customer_id'),
            (int) $slot['provider_id'],
            (int) $slot['service_id'],
            (string) $slot['start_datetime'],
            (string) $slot['end_datetime'],
            [
                'notes' => 'run:' . $state['run_id'] . ':appointment-update',
            ],
        ),
        default => throw new ContractAssertionException(
            requiredString($check, 'id') . ' has unsupported payload_builder "' . $builder . '".',
        ),
    };
}

/**
 * @param mixed $value
 * @param array<string, mixed> $state
 * @return mixed
 */
function resolveDynamicValue(mixed $value, array $state): mixed
{
    if (!is_string($value) || $value === '') {
        return $value;
    }

    $statePrefix = '@state.';
    if (str_starts_with($value, $statePrefix)) {
        $stateKey = substr($value, strlen($statePrefix));
        if ($stateKey === '' || !array_key_exists($stateKey, $state)) {
            throw new ContractAssertionException('Missing state value for token "' . $value . '".');
        }

        return $state[$stateKey];
    }

    return $value;
}

/**
 * @param array<int, array<string, mixed>> $checks
 */
function runCheck(string $name, callable $callback, array &$checks): void
{
    $startedAt = microtime(true);

    try {
        $details = $callback();
        if (!is_array($details)) {
            $details = ['detail' => (string) $details];
        }

        $checks[] = array_merge(
            [
                'name' => $name,
                'status' => 'pass',
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ],
            $details,
        );

        fwrite(STDOUT, '[PASS] ' . $name . PHP_EOL);
    } catch (Throwable $e) {
        $checks[] = [
            'name' => $name,
            'status' => 'fail',
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ];

        fwrite(STDERR, '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL);
        throw $e;
    }
}

/**
 * @param array<string, mixed> $config
 * @return array<int, array{resource:string,id:int|string}>
 */
function sweepRunMarkerResources(array $config, DeterministicFixtureFactory $factory): array
{
    $deleted = [];
    $customers = apiListCustomersByQuery($config, $factory->runId());

    foreach ($customers as $customer) {
        $customerId = $customer['id'] ?? null;
        if (!is_int($customerId) || $customerId <= 0) {
            continue;
        }

        $appointments = apiListAppointmentsByCustomerId($config, $customerId);
        foreach ($appointments as $appointment) {
            $appointmentId = $appointment['id'] ?? null;
            if (!is_int($appointmentId) || $appointmentId <= 0) {
                continue;
            }

            if (apiDeleteById($config, 'appointments', $appointmentId, [204, 404])) {
                $deleted[] = [
                    'resource' => 'appointments',
                    'id' => $appointmentId,
                ];
            }
        }

        if (apiDeleteById($config, 'customers', $customerId, [204, 404])) {
            $deleted[] = [
                'resource' => 'customers',
                'id' => $customerId,
            ];
        }
    }

    return $deleted;
}

/**
 * @param array<string, mixed> $config
 * @return array<int, array<string, mixed>>
 */
function apiListCustomersByQuery(array $config, string $query): array
{
    $response = apiJsonRequest(
        'GET',
        $config,
        'api/v1/customers',
        null,
        true,
        [200],
        [
            'q' => $query,
            'length' => 100,
            'page' => 1,
        ],
    );

    $decoded = decodeJsonArray($response['body'], 'GET /api/v1/customers');
    if (!array_is_list($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, 'is_array'));
}

/**
 * @param array<string, mixed> $config
 * @return array<int, array<string, mixed>>
 */
function apiListAppointmentsByCustomerId(array $config, int $customerId): array
{
    $response = apiJsonRequest(
        'GET',
        $config,
        'api/v1/appointments',
        null,
        true,
        [200],
        [
            'customerId' => $customerId,
            'length' => 100,
            'page' => 1,
        ],
    );

    $decoded = decodeJsonArray($response['body'], 'GET /api/v1/appointments?customerId=' . $customerId);
    if (!array_is_list($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, 'is_array'));
}

/**
 * @param array<string, mixed> $config
 * @param int[] $expectedStatuses
 */
function apiDeleteById(array $config, string $resource, int $id, array $expectedStatuses): bool
{
    $response = apiJsonRequest(
        'DELETE',
        $config,
        'api/v1/' . trim($resource, '/') . '/' . $id,
        null,
        true,
        $expectedStatuses,
    );

    return in_array($response['status_code'], $expectedStatuses, true);
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed>|null $payload
 * @param int[] $expectedStatuses
 * @param array<string, scalar> $query
 * @return array{
 *   status_code:int,
 *   headers:array<string, string[]>,
 *   body:string,
 *   url:string,
 *   duration_ms:float
 * }
 */
function apiJsonRequest(
    string $method,
    array $config,
    string $path,
    ?array $payload,
    bool $withBasicAuth,
    array $expectedStatuses,
    array $query = [],
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('ext-curl is required for API OpenAPI write contract smoke.');
    }

    $url = buildAppUrl($config['base_url'], $config['index_page'], $path, $query);
    $curl = curl_init();

    if ($curl === false) {
        throw new RuntimeException('Failed to initialize cURL.');
    }

    $headers = [];
    $headerFn = static function ($ch, string $headerLine) use (&$headers): int {
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

    $timeout = max(1, (int) $config['http_timeout']);
    $connectTimeout = min(5, $timeout);

    $requestHeaders = ['Accept: application/json'];
    if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HEADERFUNCTION => $headerFn,
        CURLOPT_USERAGENT => 'api-openapi-write-contract-smoke/1.0',
        CURLOPT_HTTPHEADER => $requestHeaders,
    ];

    if ($withBasicAuth) {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD] = $config['username'] . ':' . $config['password'];
    }

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($curl, $options);

    $startedAt = microtime(true);
    $body = curl_exec($curl);
    $durationMs = (microtime(true) - $startedAt) * 1000;

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed for "' . $url . '": ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    curl_close($curl);

    if (!in_array($statusCode, $expectedStatuses, true)) {
        throw new ContractAssertionException(
            sprintf(
                'Expected HTTP %s, got %d for %s %s.',
                implode('|', array_map(static fn(int $code): string => (string) $code, $expectedStatuses)),
                $statusCode,
                strtoupper($method),
                $url,
            ),
        );
    }

    return [
        'status_code' => $statusCode,
        'headers' => $headers,
        'body' => (string) $body,
        'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        'duration_ms' => round($durationMs, 2),
    ];
}

/**
 * @param array<string, string[]> $headers
 */
function headerValue(array $headers, string $name): ?string
{
    $key = strtolower($name);
    if (!array_key_exists($key, $headers) || !is_array($headers[$key]) || $headers[$key] === []) {
        return null;
    }

    return (string) $headers[$key][0];
}

/**
 * @param array<string, scalar> $query
 */
function buildAppUrl(string $baseUrl, string $indexPage, string $path, array $query = []): string
{
    $segments = [rtrim($baseUrl, '/')];

    $normalizedIndex = trim($indexPage, '/');
    if ($normalizedIndex !== '') {
        $segments[] = $normalizedIndex;
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

/**
 * @return array{raw:mixed,value:mixed}
 */
function decodeJsonWithRaw(string $body, string $context): array
{
    try {
        $raw = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ContractAssertionException($context . ' returned invalid JSON: ' . $e->getMessage(), 0, $e);
    }

    return [
        'raw' => $raw,
        'value' => $value,
    ];
}

/**
 * @return array<string, mixed>
 */
function decodeJsonArray(string $body, string $context): array
{
    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ContractAssertionException($context . ' returned invalid JSON: ' . $e->getMessage(), 0, $e);
    }

    if (!is_array($decoded)) {
        throw new ContractAssertionException($context . ' returned non-object JSON payload.');
    }

    return $decoded;
}

function toPositiveInt(mixed $value, string $context): int
{
    if (!is_int($value)) {
        throw new ContractAssertionException($context . ' must be a positive integer.');
    }

    if ($value <= 0) {
        throw new ContractAssertionException($context . ' must be a positive integer.');
    }

    return $value;
}

/**
 * @param array<string, mixed> $source
 */
function requiredString(array $source, string $key): string
{
    $value = $source[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new ContractAssertionException('Missing required string field "' . $key . '".');
    }

    return $value;
}

/**
 * @param array<string, mixed> $source
 */
function requiredInt(array $source, string $key): int
{
    $value = $source[$key] ?? null;
    if (!is_int($value)) {
        throw new ContractAssertionException('Missing required integer field "' . $key . '".');
    }

    return $value;
}

/**
 * @return array<string, mixed>
 */
function loadContractMatrix(string $matrixFile): array
{
    if (!is_file($matrixFile)) {
        throw new ContractAssertionException('Write contract matrix file not found: ' . $matrixFile);
    }

    $matrix = require $matrixFile;
    if (!is_array($matrix)) {
        throw new ContractAssertionException('Write contract matrix must return an array: ' . $matrixFile);
    }

    return $matrix;
}

/**
 * @return array<string, mixed>
 */
function parseCliOptions(): array
{
    $options = getopt('', [
        'base-url:',
        'index-page::',
        'openapi-spec::',
        'matrix-file::',
        'username:',
        'password:',
        'http-timeout::',
        'booking-search-days::',
        'retry-count::',
        'output-json::',
        'run-id::',
        'timezone::',
        'csrf-cookie-name::',
        'csrf-token-name::',
        'help::',
    ]);

    if (array_key_exists('help', $options)) {
        printHelpAndExit();
    }

    $baseUrl = trim((string) ($options['base-url'] ?? ''));
    $username = trim((string) ($options['username'] ?? ''));
    $password = (string) ($options['password'] ?? '');

    if ($baseUrl === '' || $username === '' || $password === '') {
        throw new ContractAssertionException('Missing required arguments. Use --help for usage.');
    }

    $repoRoot = dirname(__DIR__, 2);
    $openapiSpec = (string) ($options['openapi-spec'] ?? $repoRoot . '/openapi.yml');
    $matrixFile = (string) ($options['matrix-file'] ?? __DIR__ . '/config/api_openapi_write_contract_matrix.php');
    $searchDays = (int) ($options['booking-search-days'] ?? 14);
    $retryCount = (int) ($options['retry-count'] ?? 1);
    $timeout = (int) ($options['http-timeout'] ?? 15);

    $runId = trim((string) ($options['run-id'] ?? ''));
    if ($runId === '') {
        $runId = DeterministicFixtureFactory::generateRunId();
    }

    return [
        'base_url' => $baseUrl,
        'index_page' => (string) ($options['index-page'] ?? 'index.php'),
        'openapi_spec' => $openapiSpec,
        'matrix_file' => $matrixFile,
        'username' => $username,
        'password' => $password,
        'http_timeout' => max(1, $timeout),
        'booking_search_days' => max(1, $searchDays),
        'retry_count' => max(0, $retryCount),
        'output_json' => (string) ($options['output-json'] ?? ''),
        'run_id' => $runId,
        'timezone' => (string) ($options['timezone'] ?? (date_default_timezone_get() ?: 'UTC')),
        'csrf_cookie_name' => (string) ($options['csrf-cookie-name'] ?? 'csrf_cookie'),
        'csrf_token_name' => (string) ($options['csrf-token-name'] ?? 'csrf_token'),
    ];
}

/**
 * @param array<string, mixed> $config
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $state
 * @param array<string, mixed> $retryMetadata
 * @param array<string, mixed>|null $failure
 */
function writeReport(array $config, array $checks, array $state, array $retryMetadata, ?array $failure): string
{
    $outputPath = trim((string) ($config['output_json'] ?? ''));
    if ($outputPath === '') {
        $timestamp = gmdate('Ymd\THis\Z');
        $outputPath = dirname(__DIR__, 2) . '/storage/logs/ci/api-openapi-write-contract-' . $timestamp . '.json';
    }

    $directory = dirname($outputPath);
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create report directory: ' . $directory);
        }
    }

    $passed = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'pass'));
    $failed = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'fail'));

    $report = [
        'generated_at_utc' => gmdate('c'),
        'run_id' => $state['run_id'] ?? null,
        'openapi_spec' => $config['openapi_spec'] ?? null,
        'base_url' => $config['base_url'] ?? null,
        'summary' => [
            'total' => count($checks),
            'passed' => $passed,
            'failed' => $failed,
        ],
        'retry' => $retryMetadata,
        'state' => $state,
        'checks' => $checks,
        'cleanup' => $state['cleanup'] ?? null,
        'failure' => $failure,
    ];

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($outputPath, $json . PHP_EOL);

    return $outputPath;
}

function printHelpAndExit(): void
{
    $help = <<<'TXT'
    Usage:
      php scripts/ci/api_openapi_write_contract_smoke.php \
        --base-url=http://nginx \
        --index-page=index.php \
        --openapi-spec=/var/www/html/openapi.yml \
        --username=administrator \
        --password=administrator

    Optional:
      --matrix-file=PATH
      --booking-search-days=14
      --retry-count=1
      --http-timeout=15
      --output-json=PATH
      --run-id=STRING
      --timezone=Europe/Berlin
      --csrf-cookie-name=csrf_cookie
      --csrf-token-name=csrf_token
    TXT;

    fwrite(STDOUT, $help . PHP_EOL);
    exit(API_OPENAPI_WRITE_CONTRACT_EXIT_SUCCESS);
}

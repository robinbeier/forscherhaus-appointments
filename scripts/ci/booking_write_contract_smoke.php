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
use CiContract\WriteContractCleanupRegistry;
use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateHttpClient;

const BOOKING_WRITE_CONTRACT_EXIT_SUCCESS = 0;
const BOOKING_WRITE_CONTRACT_EXIT_ASSERTION_FAILURE = 1;
const BOOKING_WRITE_CONTRACT_EXIT_RUNTIME_ERROR = 2;

$checks = [];
$state = [];
$failure = null;
$exitCode = BOOKING_WRITE_CONTRACT_EXIT_SUCCESS;
$reportPath = null;

$retryMetadata = [
    'max_retries' => 0,
    'attempts' => 0,
    'retry_events' => [],
];

try {
    $config = parseCliOptions();
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
            runBookingContractsAttempt($config, $attempt, $attemptChecks, $attemptState, $attemptCleanup);
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
                static fn(Throwable $error): bool => isContractMismatch($error),
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
            $exitCode = isContractMismatch($e)
                ? BOOKING_WRITE_CONTRACT_EXIT_ASSERTION_FAILURE
                : BOOKING_WRITE_CONTRACT_EXIT_RUNTIME_ERROR;
            break;
        }
    }
} catch (ContractAssertionException | GateAssertionException $e) {
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
        'classification' => 'contract_mismatch',
    ];
    $exitCode = BOOKING_WRITE_CONTRACT_EXIT_ASSERTION_FAILURE;
} catch (Throwable $e) {
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
        'classification' => 'runtime_error',
    ];
    $exitCode = BOOKING_WRITE_CONTRACT_EXIT_RUNTIME_ERROR;
}

try {
    $reportPath = writeReport($config ?? [], $checks, $state, $retryMetadata, $failure);
} catch (Throwable $e) {
    fwrite(STDERR, '[WARN] Failed to write booking write contract report: ' . $e->getMessage() . PHP_EOL);
}

$passedChecks = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'pass'));
$totalChecks = count($checks);

if ($exitCode === BOOKING_WRITE_CONTRACT_EXIT_SUCCESS) {
    fwrite(
        STDOUT,
        sprintf('[PASS] Booking write contract smoke passed (%d/%d checks).%s', $passedChecks, $totalChecks, PHP_EOL),
    );
} else {
    fwrite(
        STDERR,
        sprintf('[FAIL] Booking write contract smoke failed: %s%s', $failure['message'] ?? 'unknown failure', PHP_EOL),
    );
}

if ($reportPath !== null) {
    fwrite(STDOUT, '[INFO] Report: ' . $reportPath . PHP_EOL);
}

exit($exitCode);

/**
 * @param array<string, mixed> $config
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $state
 * @param array<string, mixed> $cleanupSummary
 */
function runBookingContractsAttempt(
    array $config,
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

    $client = new GateHttpClient(
        $config['base_url'],
        $config['index_page'],
        $config['http_timeout'],
        'booking-write-contract-smoke/1.0',
        $config['csrf_cookie_name'],
        $config['csrf_token_name'],
    );

    $cleanup = new WriteContractCleanupRegistry();
    $cleanup->addFallbackSweeper(static fn(): array => sweepRunMarkerResources($config, $factory));

    try {
        $bookingPage = $client->get('booking', [], $config['http_timeout']);
        GateAssertions::assertStatus($bookingPage->statusCode, 200, 'GET /booking');
        $bootstrap = $factory->extractBookingBootstrap($bookingPage->body);
        $pairs = $factory->resolveProviderServicePairs($bootstrap);
        $slot = $factory->resolveBookableSlot($client, $config['http_timeout'], $pairs);

        $state['provider_id'] = $slot['provider_id'];
        $state['service_id'] = $slot['service_id'];
        $state['slot_date'] = $slot['date'];
        $state['slot_hour'] = $slot['hour'];
        $state['slot_start'] = $slot['start_datetime'];
        $state['slot_end'] = $slot['end_datetime'];

        runCheck(
            'booking_register_success_contract',
            static function () use ($client, $config, $factory, &$state, $slot, $cleanup): array {
                $customerPayload = $factory->createBookingCustomerPayload();
                $appointmentPayload = $factory->createBookingAppointmentPayload(
                    $slot['provider_id'],
                    $slot['service_id'],
                    $slot['start_datetime'],
                    $slot['end_datetime'],
                    [
                        'notes' => 'run:' . $state['run_id'] . ':register-success',
                    ],
                );

                $response = $client->post(
                    'booking/register',
                    [
                        'post_data' => [
                            'appointment' => $appointmentPayload,
                            'customer' => $customerPayload,
                            'manage_mode' => false,
                        ],
                    ],
                    $config['http_timeout'],
                    true,
                );

                GateAssertions::assertStatus($response->statusCode, 200, 'POST /booking/register (success)');
                $decoded = decodeJsonArray($response->body, 'POST /booking/register (success)');

                $appointmentId = toPositiveInt($decoded['appointment_id'] ?? null, 'register_success.appointment_id');
                $appointmentHash = trim((string) ($decoded['appointment_hash'] ?? ''));
                if ($appointmentHash === '') {
                    throw new ContractAssertionException(
                        'register_success.appointment_hash must be a non-empty string.',
                    );
                }

                $appointment = apiGetById($config, 'appointments', $appointmentId, [200]);
                $customerId = toPositiveInt($appointment['customerId'] ?? null, 'register_success.customer_id');

                $cleanup->register(
                    'appointments',
                    $appointmentId,
                    static fn(int|string $id): bool => apiDeleteById($config, 'appointments', (int) $id, [204, 404]),
                );
                $cleanup->register(
                    'customers',
                    $customerId,
                    static fn(int|string $id): bool => apiDeleteById($config, 'customers', (int) $id, [204, 404]),
                );

                $state['primary_appointment_id'] = $appointmentId;
                $state['primary_appointment_hash'] = $appointmentHash;
                $state['primary_customer_id'] = $customerId;
                $state['primary_customer'] = $customerPayload;

                return [
                    'http_status' => $response->statusCode,
                    'url' => $response->url,
                    'appointment_id' => $appointmentId,
                    'appointment_hash' => $appointmentHash,
                    'customer_id' => $customerId,
                ];
            },
            $checks,
        );

        runCheck(
            'booking_register_manage_update_contract',
            static function () use ($client, $config, $factory, &$state, $slot): array {
                $appointmentId = toPositiveInt(
                    $state['primary_appointment_id'] ?? null,
                    'manage_update.appointment_id',
                );
                $appointmentHash = trim((string) ($state['primary_appointment_hash'] ?? ''));
                if ($appointmentHash === '') {
                    throw new ContractAssertionException('manage_update requires primary appointment hash.');
                }

                $customerPayload = $state['primary_customer'] ?? null;
                if (!is_array($customerPayload)) {
                    throw new ContractAssertionException('manage_update requires primary customer payload state.');
                }

                $updatedNotes = 'run:' . $state['run_id'] . ':manage-update';
                $appointmentPayload = $factory->createBookingAppointmentPayload(
                    $slot['provider_id'],
                    $slot['service_id'],
                    $slot['start_datetime'],
                    $slot['end_datetime'],
                    [
                        'id' => $appointmentId,
                        'notes' => $updatedNotes,
                    ],
                );

                $response = $client->post(
                    'booking/register',
                    [
                        'post_data' => [
                            'appointment' => $appointmentPayload,
                            'customer' => $customerPayload,
                            'manage_mode' => true,
                        ],
                    ],
                    $config['http_timeout'],
                    true,
                );

                GateAssertions::assertStatus($response->statusCode, 200, 'POST /booking/register (manage_mode)');
                $decoded = decodeJsonArray($response->body, 'POST /booking/register (manage_mode)');
                $returnedId = toPositiveInt(
                    $decoded['appointment_id'] ?? null,
                    'manage_update.response.appointment_id',
                );

                if ($returnedId !== $appointmentId) {
                    throw new ContractAssertionException(
                        sprintf('manage_update must keep appointment id %d, got %d.', $appointmentId, $returnedId),
                    );
                }

                $updatedAppointment = apiGetById($config, 'appointments', $appointmentId, [200]);
                $updatedHash = trim((string) ($updatedAppointment['hash'] ?? ''));

                if ($updatedHash !== $appointmentHash) {
                    throw new ContractAssertionException('manage_update changed appointment hash unexpectedly.');
                }

                if (trim((string) ($updatedAppointment['notes'] ?? '')) !== $updatedNotes) {
                    throw new ContractAssertionException('manage_update did not persist updated notes.');
                }

                return [
                    'http_status' => $response->statusCode,
                    'url' => $response->url,
                    'appointment_id' => $appointmentId,
                    'notes' => $updatedNotes,
                ];
            },
            $checks,
        );

        runCheck(
            'booking_register_unavailable_contract',
            static function () use ($client, $config, $factory, &$state, $slot): array {
                $customerPayload = $factory->createBookingCustomerPayload();
                $appointmentPayload = $factory->createBookingAppointmentPayload(
                    $slot['provider_id'],
                    $slot['service_id'],
                    $slot['start_datetime'],
                    $slot['end_datetime'],
                    [
                        'notes' => 'run:' . $state['run_id'] . ':unavailable',
                    ],
                );

                $response = $client->post(
                    'booking/register',
                    [
                        'post_data' => [
                            'appointment' => $appointmentPayload,
                            'customer' => $customerPayload,
                            'manage_mode' => false,
                        ],
                    ],
                    $config['http_timeout'],
                    true,
                );

                GateAssertions::assertStatus($response->statusCode, 500, 'POST /booking/register (unavailable)');
                $decoded = decodeJsonArray($response->body, 'POST /booking/register (unavailable)');

                if (($decoded['success'] ?? null) !== false) {
                    throw new ContractAssertionException('unavailable register path must return {"success": false}.');
                }

                $message = trim((string) ($decoded['message'] ?? ''));
                if ($message === '') {
                    throw new ContractAssertionException(
                        'unavailable register path must return a non-empty "message".',
                    );
                }

                $slotAppointmentsCount = countBookedAppointmentsForSlot(
                    $config,
                    (int) $slot['provider_id'],
                    (int) $slot['service_id'],
                    (string) $slot['start_datetime'],
                    (string) $slot['end_datetime'],
                );

                if ($slotAppointmentsCount !== 1) {
                    throw new ContractAssertionException(
                        sprintf(
                            'overbooking invariant failed: expected exactly 1 appointment for slot, got %d.',
                            $slotAppointmentsCount,
                        ),
                    );
                }

                return [
                    'http_status' => $response->statusCode,
                    'url' => $response->url,
                    'message' => $message,
                    'slot_appointments_count' => $slotAppointmentsCount,
                    'slot_provider_id' => (int) $slot['provider_id'],
                    'slot_service_id' => (int) $slot['service_id'],
                    'slot_start' => (string) $slot['start_datetime'],
                    'slot_end' => (string) $slot['end_datetime'],
                ];
            },
            $checks,
        );

        runCheck(
            'booking_reschedule_manage_mode_contract',
            static function () use ($client, $config, $factory, &$state): array {
                $appointmentHash = trim((string) ($state['primary_appointment_hash'] ?? ''));
                if ($appointmentHash === '') {
                    throw new ContractAssertionException('reschedule_manage_mode requires primary appointment hash.');
                }

                $response = $client->get(
                    'booking/reschedule/' . rawurlencode($appointmentHash),
                    [],
                    $config['http_timeout'],
                );
                GateAssertions::assertStatus($response->statusCode, 200, 'GET /booking/reschedule/{hash}');

                $bootstrap = $factory->extractBookingBootstrap($response->body);
                $manageMode = $bootstrap['manage_mode'] ?? null;

                if ($manageMode !== true) {
                    throw new ContractAssertionException('reschedule view must expose manage_mode=true.');
                }

                $appointmentData = $bootstrap['appointment_data'] ?? null;
                if (!is_array($appointmentData)) {
                    throw new ContractAssertionException('reschedule view must include appointment_data object.');
                }

                $appointmentId = toPositiveInt(
                    $state['primary_appointment_id'] ?? null,
                    'reschedule.primary_appointment_id',
                );
                $resolvedId = toPositiveInt($appointmentData['id'] ?? null, 'reschedule.appointment_data.id');
                if ($resolvedId !== $appointmentId) {
                    throw new ContractAssertionException(
                        sprintf(
                            'reschedule appointment id mismatch: expected %d, got %d.',
                            $appointmentId,
                            $resolvedId,
                        ),
                    );
                }

                return [
                    'http_status' => $response->statusCode,
                    'url' => $response->url,
                    'appointment_id' => $resolvedId,
                    'manage_mode' => true,
                ];
            },
            $checks,
        );

        runCheck(
            'booking_cancel_success_contract',
            static function () use ($client, $config, &$state): array {
                $appointmentId = toPositiveInt(
                    $state['primary_appointment_id'] ?? null,
                    'cancel_success.appointment_id',
                );
                $appointmentHash = trim((string) ($state['primary_appointment_hash'] ?? ''));
                if ($appointmentHash === '') {
                    throw new ContractAssertionException('cancel_success requires primary appointment hash.');
                }

                $response = $client->post(
                    'booking_cancellation/of/' . rawurlencode($appointmentHash),
                    ['cancellation_reason' => 'run:' . $state['run_id'] . ':cancel-success'],
                    $config['http_timeout'],
                    true,
                );

                GateAssertions::assertStatus($response->statusCode, 200, 'POST /booking_cancellation/of/{hash}');

                $afterDelete = apiGetById($config, 'appointments', $appointmentId, [404]);
                if ($afterDelete !== null) {
                    throw new ContractAssertionException('cancel_success must delete the appointment record.');
                }

                return [
                    'http_status' => $response->statusCode,
                    'url' => $response->url,
                    'appointment_id' => $appointmentId,
                    'deleted' => true,
                ];
            },
            $checks,
        );

        runCheck(
            'booking_cancel_unknown_hash_contract',
            static function () use ($client, $config, $factory, &$state, $slot, $cleanup): array {
                $customerId = toPositiveInt($state['primary_customer_id'] ?? null, 'cancel_unknown.customer_id');

                $protectPayload = $factory->createApiAppointmentPayload(
                    $customerId,
                    (int) $slot['provider_id'],
                    (int) $slot['service_id'],
                    $slot['start_datetime'],
                    $slot['end_datetime'],
                    [
                        'notes' => 'run:' . $state['run_id'] . ':unknown-hash-protection',
                    ],
                );

                $protectedAppointment = apiJsonRequest('POST', $config, 'api/v1/appointments', $protectPayload, true, [
                    201,
                ]);

                $protectedBody = decodeJsonArray(
                    $protectedAppointment['body'],
                    'POST /api/v1/appointments (unknown hash protection)',
                );
                $protectedAppointmentId = toPositiveInt(
                    $protectedBody['id'] ?? null,
                    'cancel_unknown.protected_appointment_id',
                );

                $cleanup->register(
                    'appointments',
                    $protectedAppointmentId,
                    static fn(int|string $id): bool => apiDeleteById($config, 'appointments', (int) $id, [204, 404]),
                );

                $unknownHash = 'missing-' . $state['run_id'];
                $response = $client->post(
                    'booking_cancellation/of/' . rawurlencode($unknownHash),
                    ['cancellation_reason' => 'run:' . $state['run_id'] . ':cancel-unknown'],
                    $config['http_timeout'],
                    true,
                );

                GateAssertions::assertStatus(
                    $response->statusCode,
                    200,
                    'POST /booking_cancellation/of/{unknown_hash}',
                );

                $existing = apiGetById($config, 'appointments', $protectedAppointmentId, [200]);
                if (!is_array($existing)) {
                    throw new ContractAssertionException('cancel_unknown_hash path deleted unrelated appointments.');
                }

                if (trim($response->body) === '') {
                    throw new ContractAssertionException(
                        'cancel_unknown_hash response must render a non-empty HTML page.',
                    );
                }

                return [
                    'http_status' => $response->statusCode,
                    'url' => $response->url,
                    'protected_appointment_id' => $protectedAppointmentId,
                ];
            },
            $checks,
        );
    } finally {
        $cleanupSummary = $cleanup->cleanup();
        $state['cleanup'] = $cleanupSummary;
    }
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
 */
function countBookedAppointmentsForSlot(
    array $config,
    int $providerId,
    int $serviceId,
    string $startDateTime,
    string $endDateTime,
): int {
    $length = 100;
    $page = 1;
    $count = 0;

    while (true) {
        if ($page > 50) {
            throw new ContractAssertionException('slot appointment lookup exceeded paging safety limit.');
        }

        $response = apiJsonRequest(
            'GET',
            $config,
            'api/v1/appointments',
            null,
            true,
            [200],
            [
                'providerId' => $providerId,
                'serviceId' => $serviceId,
                'length' => $length,
                'page' => $page,
            ],
        );

        $decoded = decodeJsonArray($response['body'], 'GET /api/v1/appointments (slot scan)');
        if (!array_is_list($decoded) || $decoded === []) {
            break;
        }

        foreach ($decoded as $appointment) {
            if (!is_array($appointment)) {
                continue;
            }

            $start = trim((string) ($appointment['start'] ?? ''));
            $end = trim((string) ($appointment['end'] ?? ''));
            $status = trim((string) ($appointment['status'] ?? ''));
            $appointmentProviderId = resolveOptionalPositiveInt(
                $appointment['providerId'] ?? ($appointment['id_users_provider'] ?? null),
            );
            $appointmentServiceId = resolveOptionalPositiveInt(
                $appointment['serviceId'] ?? ($appointment['id_services'] ?? null),
            );
            $isUnavailabilityRaw = $appointment['isUnavailability'] ?? false;
            $isUnavailability = $isUnavailabilityRaw === true || (int) $isUnavailabilityRaw === 1;

            if ($start !== $startDateTime || $end !== $endDateTime) {
                continue;
            }

            if ($appointmentProviderId !== $providerId || $appointmentServiceId !== $serviceId) {
                continue;
            }

            if ($status !== 'Booked') {
                continue;
            }

            if ($isUnavailability) {
                continue;
            }

            $count++;
        }

        if (count($decoded) < $length) {
            break;
        }

        $page++;
    }

    return $count;
}

function resolveOptionalPositiveInt(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (!is_string($value)) {
        return null;
    }

    $normalized = trim($value);
    if ($normalized === '' || preg_match('/^\\d+$/', $normalized) !== 1) {
        return null;
    }

    $parsed = (int) $normalized;

    return $parsed > 0 ? $parsed : null;
}

/**
 * @param array<string, mixed> $config
 * @param int[] $expectedStatuses
 * @return array<string, mixed>|null
 */
function apiGetById(array $config, string $resource, int $id, array $expectedStatuses): ?array
{
    $response = apiJsonRequest(
        'GET',
        $config,
        'api/v1/' . trim($resource, '/') . '/' . $id,
        null,
        true,
        $expectedStatuses,
    );

    if ($response['status_code'] === 404) {
        return null;
    }

    $decoded = decodeJsonArray($response['body'], sprintf('GET /api/v1/%s/%d', $resource, $id));

    return $decoded;
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
        throw new RuntimeException('ext-curl is required for booking write contract smoke.');
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
        CURLOPT_USERAGENT => 'booking-write-contract-smoke/1.0',
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

function isContractMismatch(Throwable $error): bool
{
    if ($error instanceof ContractAssertionException) {
        return true;
    }

    if (!$error instanceof GateAssertionException) {
        return false;
    }

    return !FlakeRetry::isTransientRuntimeError($error->getMessage());
}

/**
 * @return array<string, mixed>
 */
function parseCliOptions(): array
{
    $options = getopt('', [
        'base-url:',
        'index-page::',
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
        $outputPath = dirname(__DIR__, 2) . '/storage/logs/ci/booking-write-contract-' . $timestamp . '.json';
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
      php scripts/ci/booking_write_contract_smoke.php \
        --base-url=http://nginx \
        --index-page=index.php \
        --username=administrator \
        --password=administrator

    Optional:
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
    exit(BOOKING_WRITE_CONTRACT_EXIT_SUCCESS);
}

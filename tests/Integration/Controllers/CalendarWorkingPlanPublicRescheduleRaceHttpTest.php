<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP race coverage for working-plan exception invalidation of rescheduling authority. */
final class CalendarWorkingPlanPublicRescheduleRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $calendarServer = null;
    private ?DefenseCycleHttpServer $bookingServer = null;
    private ?array $settings = null;
    private ?array $providerSettings = null;
    private bool $resourcesCleaned = false;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->providerSettings = $db
                ->get_where('user_settings', ['id_users' => $this->fixture->providerId])
                ->row_array();
            self::assertNotEmpty($this->providerSettings);
            foreach (['display_email', 'require_email', 'display_terms_and_conditions'] as $name) {
                $row = $db->get_where('settings', ['name' => $name])->row_array() ?: null;
                self::assertNotNull($row);
                $this->settings[$name] = $row;
            }
            foreach (
                ['display_email' => '0', 'require_email' => '0', 'display_terms_and_conditions' => '1']
                as $name => $value
            ) {
                $id = (int) $this->settings[$name]['id'];
                self::assertTrue($db->update('settings', ['value' => $value], ['id' => $id]));
                $stored = $db->get_where('settings', ['id' => $id])->row_array();
                self::assertSame($value, (string) ($stored['value'] ?? null));
            }
            $this->calendarServer = new DefenseCycleHttpServer();
            $this->bookingServer = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->rethrowAfterCleanup($error);
        }
    }

    protected function tearDown(): void
    {
        $this->closeAndClean();
    }

    private function closeAndClean(): void
    {
        if ($this->resourcesCleaned) {
            return;
        }
        try {
            $this->calendarServer?->close();
        } finally {
            try {
                $this->bookingServer?->close();
            } finally {
                try {
                    $this->cleanupOwned();
                } finally {
                    try {
                        $this->restoreSettings();
                    } finally {
                        $this->fixture?->cleanup();
                    }
                }
            }
        }
        $this->resourcesCleaned = true;
    }

    private function rethrowAfterCleanup(Throwable $original): never
    {
        try {
            $this->closeAndClean();
        } catch (Throwable $cleanupError) {
            throw new RuntimeException(
                'Test/setup failed: ' .
                    $original->getMessage() .
                    '; cleanup also failed: ' .
                    $cleanupError->getMessage(),
                0,
                $original,
            );
        }
        throw $original;
    }

    public function testWorkingPlanExceptionInvalidatesRescheduleAuthorityAfterCommit(): void
    {
        try {
            $this->runCalendarRescheduleRace();
        } catch (Throwable $error) {
            $this->rethrowAfterCleanup($error);
        }
    }

    private function runCalendarRescheduleRace(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->calendarServer);
        self::assertNotNull($this->bookingServer);
        $db = get_instance()->db;
        $appointment = $fixture->appointment();
        $appointmentId = (int) $appointment['id'];
        $beforeAppointment = $fixture->row('appointments', $appointmentId);
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $beforeService = $fixture->row('services', $fixture->serviceId);
        $consentIdentity = [
            'first_name' => (string) $beforeCustomer['first_name'],
            'last_name' => (string) $beforeCustomer['last_name'],
            'email' => (string) $beforeCustomer['email'],
            'type' => 'terms-and-conditions',
        ];
        $beforeConsentCount = $db->get_where('consents', $consentIdentity)->num_rows();
        $start = (new DateTimeImmutable('today'))->modify('+15 days')->setTime(10, 0);
        $date = $start->format('Y-m-d');
        self::assertNotSame(
            $beforeAppointment['start_datetime'],
            $start->format('Y-m-d H:i:s'),
            'The target must differ from the original appointment.',
        );
        $before = $this->mutationCounts();
        $observer = $this->observer($db);
        $owner = $this->connection($db);
        $ownerId = mysqli_thread_id($owner->conn_id);
        $transactionOpen = false;
        $calendarHandle = null;
        $rescheduleHandle = null;
        $multi = null;

        try {
            // Complete all session and CSRF setup before taking the provider lock.
            $calendarClient = $this->loginAdmin($this->calendarServer, $fixture);
            $bookingClient = $this->bookingServer->client();
            self::assertSame(
                200,
                $bookingClient->get('booking/reschedule/' . rawurlencode((string) $appointment['hash']))->statusCode,
            );
            $hours = $bookingClient->post('booking/get_available_hours', [
                'provider_id' => $fixture->providerId,
                'service_id' => $fixture->serviceId,
                'selected_date' => $start->format('Y-m-d'),
                'manage_mode' => '1',
                'appointment_id' => (string) $appointmentId,
            ]);
            self::assertSame(200, $hours->statusCode, $hours->body);
            self::assertContains('10:00', json_decode($hours->body, true, 512, JSON_THROW_ON_ERROR));
            $providerSettings = $db->get_where('user_settings', ['id_users' => $fixture->providerId])->row_array();
            $exceptions = json_decode((string) ($providerSettings['working_plan_exceptions'] ?? '{}'), true) ?: [];
            self::assertArrayNotHasKey($date, $exceptions);
            $authority = $db->get_where('reschedule_authorities', ['appointment_id' => $appointmentId])->row_array();
            self::assertNotEmpty($authority);
            self::assertNull($authority['consumed_at']);
            self::assertTrue($owner->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $owner->query('SELECT `id` FROM `' . $db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE', [
                    $fixture->providerId,
                ]),
            );

            $multi = curl_multi_init();
            $calendarHandle = $this->startRequest(
                $this->calendarServer,
                $calendarClient,
                'calendar/save_working_plan_exception',
                [
                    'provider_id' => $fixture->providerId,
                    'date' => $date,
                    'working_plan_exception' => [],
                ],
                $multi,
            );
            $calendarWaiters = $this->waitForProviderWaiters($multi, $observer, $ownerId, $db->dbprefix('users'), 1);
            self::assertCount(1, $calendarWaiters, 'Calendar request must be queued behind the held provider row.');

            $rescheduleHandle = $this->startRequest(
                $this->bookingServer,
                $bookingClient,
                'booking/register',
                ['post_data' => $this->reschedulePayload($beforeAppointment, $beforeCustomer, $fixture, $start)],
                $multi,
            );
            $bothWaiters = $this->waitForProviderWaiters($multi, $observer, $ownerId, $db->dbprefix('users'), 2);
            self::assertCount(
                2,
                $bothWaiters,
                'Calendar and booking must be distinct queued requesters; booking status=' .
                    (string) curl_getinfo($rescheduleHandle, CURLINFO_RESPONSE_CODE) .
                    ' curl_errno=' .
                    curl_errno($rescheduleHandle),
            );
            self::assertArrayHasKey(
                array_key_first($calendarWaiters),
                $bothWaiters,
                'The calendar request must remain first in the wait set when booking joins it.',
            );

            self::assertTrue($owner->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi));

            self::assertSame(200, (int) curl_getinfo($calendarHandle, CURLINFO_RESPONSE_CODE));
            $calendarBody = json_decode(
                (string) curl_multi_getcontent($calendarHandle),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertTrue((bool) ($calendarBody['success'] ?? false));
            // Read the committed synthetic exception through an independent connection.
            $storedSettings = $observer->get_where('user_settings', ['id_users' => $fixture->providerId])->row_array();
            $storedExceptions = json_decode((string) ($storedSettings['working_plan_exceptions'] ?? '{}'), true) ?: [];
            self::assertArrayHasKey($date, $storedExceptions);
            self::assertNull($storedExceptions[$date]);

            self::assertSame(
                403,
                (int) curl_getinfo($rescheduleHandle, CURLINFO_RESPONSE_CODE),
                (string) curl_multi_getcontent($rescheduleHandle),
            );
            $rescheduleResponse = json_decode(
                (string) curl_multi_getcontent($rescheduleHandle),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame(['success' => false, 'message' => lang('appointment_not_found')], $rescheduleResponse);
            self::assertSame($beforeAppointment, $fixture->row('appointments', $appointmentId));
            self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
            self::assertSame($beforeService, $fixture->row('services', $fixture->serviceId));
            self::assertSame($beforeConsentCount, $db->get_where('consents', $consentIdentity)->num_rows());
            $storedAuthority = $db
                ->get_where('reschedule_authorities', ['appointment_id' => $appointmentId])
                ->row_array();
            self::assertNotNull($storedAuthority['consumed_at'] ?? null);
            $replay = $bookingClient->post('booking/register', [
                'post_data' => $this->reschedulePayload($beforeAppointment, $beforeCustomer, $fixture, $start),
            ]);
            self::assertSame(403, $replay->statusCode);
            self::assertStringContainsString(lang('appointment_not_found'), $replay->body);
            self::assertSame($beforeAppointment, $fixture->row('appointments', $appointmentId));
            $after = $this->mutationCounts();
            self::assertSame($before['appointments'], $after['appointments']);
            self::assertSame($before['users'], $after['users']);
            self::assertSame($before['consents'], $after['consents']);
        } finally {
            if ($transactionOpen && $owner->trans_active()) {
                $owner->trans_rollback();
            }
            foreach ([$calendarHandle, $rescheduleHandle] as $handle) {
                if ($handle instanceof CurlHandle && $multi instanceof CurlMultiHandle) {
                    $this->drain($multi);
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                }
            }
            if ($multi instanceof CurlMultiHandle) {
                curl_multi_close($multi);
            }
            $owner->close();
            $observer->close();
        }
    }

    private function loginAdmin(DefenseCycleHttpServer $server, DefenseCycleFixtures $fixture): GateHttpClient
    {
        $credentials = $server->client();
        self::assertSame(200, $credentials->get('login')->statusCode);
        $response = $credentials->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $credentials;
    }

    private function startRequest(
        DefenseCycleHttpServer $server,
        GateHttpClient $client,
        string $path,
        array $fields,
        CurlMultiHandle $multi,
    ): CurlHandle {
        $token = $client->getCookie('csrf_cookie');
        self::assertNotSame('', (string) $token);
        $fields['csrf_token'] = $token;
        $cookies = array_map(
            static fn(array $record): string => $record['name'] . '=' . $record['value'],
            $client->cookieRecords(),
        );
        $handle = curl_init($server->baseUrl . '/index.php/' . $path);
        self::assertInstanceOf(CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Cookie: ' . implode('; ', $cookies),
            ],
        ]);
        self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
        return $handle;
    }

    /** @return array<int, int> requester thread IDs */
    private function waitForProviderWaiters(
        CurlMultiHandle $multi,
        object $observer,
        int $ownerId,
        string $table,
        int $minimum,
    ): array {
        $deadline = microtime(true) + 8;
        do {
            self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
            $result = $observer->query(
                'SELECT DISTINCT r.THREAD_ID AS requester_thread_id ' .
                    'FROM performance_schema.data_lock_waits w ' .
                    'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID ' .
                    'JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID ' .
                    'JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID ' .
                    'WHERE b.PROCESSLIST_ID = ' .
                    $ownerId .
                    ' AND l.OBJECT_SCHEMA = ' .
                    $observer->escape(Config::DB_NAME) .
                    ' AND l.OBJECT_NAME = ' .
                    $observer->escape($table),
            );
            self::assertNotFalse($result, 'Independent lock instrumentation must be available.');
            $waiters = [];
            foreach ($result->result_array() as $row) {
                $waiters[(int) $row['requester_thread_id']] = (int) $row['requester_thread_id'];
            }
            if (count($waiters) >= $minimum) {
                return $waiters;
            }
            if ($running === 0) {
                return $waiters;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return $waiters;
    }

    private function drain(CurlMultiHandle $multi): bool
    {
        $deadline = microtime(true) + 15;
        do {
            if (curl_multi_exec($multi, $running) !== CURLM_OK) {
                return false;
            }
            if ($running === 0) {
                return true;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function observer(object $db): object
    {
        $observer = get_instance()->load->database(
            [
                'hostname' => 'mysql',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'easyappointments',
                'dbdriver' => 'mysqli',
                'dbprefix' => $db->dbprefix,
                'pconnect' => false,
                'db_debug' => false,
                'char_set' => 'utf8mb4',
                'dbcollat' => 'utf8mb4_general_ci',
            ],
            true,
        );
        self::assertTrue($observer->query('SET SESSION TRANSACTION READ ONLY'));
        self::assertNotSame(mysqli_thread_id(get_instance()->db->conn_id), mysqli_thread_id($observer->conn_id));
        return $observer;
    }

    private function connection(object $db): object
    {
        $connection = get_instance()->load->database(
            [
                'hostname' => 'mysql',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'easyappointments',
                'dbdriver' => 'mysqli',
                'dbprefix' => $db->dbprefix,
                'pconnect' => false,
                'db_debug' => false,
                'char_set' => 'utf8mb4',
                'dbcollat' => 'utf8mb4_general_ci',
            ],
            true,
        );
        self::assertNotSame(mysqli_thread_id(get_instance()->db->conn_id), mysqli_thread_id($connection->conn_id));
        return $connection;
    }

    private function reschedulePayload(
        array $appointment,
        array $customer,
        DefenseCycleFixtures $fixture,
        DateTimeImmutable $start,
    ): array {
        return [
            'appointment' => [
                'id' => (int) $appointment['id'],
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => (int) $appointment['id_services'],
                'id_users_provider' => (int) $appointment['id_users_provider'],
                'location' => '',
                'notes' => $fixture->run . '_reschedule_race',
                'color' => '',
            ],
            'customer' => [
                'id' => (int) $customer['id'],
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'email' => $customer['email'],
                'phone_number' => $customer['phone_number'] ?: '+49123456789',
                'address' => $customer['address'] ?: 'Teststrasse 1',
                'city' => $customer['city'] ?: 'Berlin',
                'zip_code' => $customer['zip_code'] ?: '10115',
                'timezone' => $customer['timezone'] ?: 'UTC',
                'notes' => $customer['notes'] ?? '',
            ],
            'manage_mode' => true,
        ];
    }

    /** @return array{appointments:int,users:int,consents:int} */
    private function mutationCounts(): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'consents' => $db->count_all('consents'),
        ];
    }

    private function cleanupOwned(): void
    {
        $fixture = $this->fixture;
        if ($fixture === null || !isset($fixture->providerId)) {
            return;
        }
        $db = get_instance()->db;
        if ($this->providerSettings !== null) {
            self::assertTrue(
                $db->update('user_settings', $this->providerSettings, ['id_users' => $fixture->providerId]),
            );
            self::assertSame(
                $this->providerSettings['working_plan_exceptions'] ?? '',
                (string) ($db->get_where('user_settings', ['id_users' => $fixture->providerId])->row_array()[
                    'working_plan_exceptions'
                ] ?? ''),
            );
        }
    }

    private function restoreSettings(): void
    {
        if ($this->settings === null) {
            return;
        }
        $db = get_instance()->db;
        foreach ($this->settings as $row) {
            self::assertTrue($db->update('settings', ['value' => $row['value']], ['id' => $row['id']]));
            $restored = $db->get_where('settings', ['id' => $row['id']])->row_array();
            self::assertSame((string) $row['value'], (string) ($restored['value'] ?? null));
        }
    }
}

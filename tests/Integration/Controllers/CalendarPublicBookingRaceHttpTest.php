<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP race coverage for a calendar block versus public booking. */
final class CalendarPublicBookingRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $calendarServer = null;
    private ?DefenseCycleHttpServer $bookingServer = null;
    private ?array $settings = null;
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

    public function testCalendarBlockIsCommittedBeforePublicBookingContinues(): void
    {
        try {
            $this->runCalendarBookingRace();
        } catch (Throwable $error) {
            $this->rethrowAfterCleanup($error);
        }
    }

    private function runCalendarBookingRace(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->calendarServer);
        self::assertNotNull($this->bookingServer);
        $db = get_instance()->db;
        $start = (new DateTimeImmutable('today'))->modify('+14 days')->setTime(10, 0);
        $blockStart = $start->format('Y-m-d H:i:s');
        $blockEnd = $start->modify('+30 minutes')->format('Y-m-d H:i:s');
        $before = $this->mutationCounts();
        $observer = $this->observer($db);
        $owner = $this->connection($db);
        $ownerId = mysqli_thread_id($owner->conn_id);
        $transactionOpen = false;
        $calendarHandle = null;
        $bookingHandle = null;
        $multi = null;

        try {
            // Complete all session and CSRF setup before taking the provider lock;
            // login and the public booking page may touch provider state.
            $calendarClient = $this->loginProvider($this->calendarServer, $fixture);
            $bookingClient = $this->bookingServer->client();
            self::assertSame(200, $bookingClient->get('booking')->statusCode);
            $availableHours = $bookingClient->post('booking/get_available_hours', [
                'provider_id' => $fixture->providerId,
                'service_id' => $fixture->serviceId,
                'selected_date' => $start->format('Y-m-d'),
                'manage_mode' => '0',
                'appointment_id' => '',
            ]);
            self::assertSame(200, $availableHours->statusCode, $availableHours->body);
            self::assertContains(
                '10:00',
                json_decode($availableHours->body, true, 512, JSON_THROW_ON_ERROR),
                'The owned provider/service slot must be publicly available before the race starts.',
            );
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
                'calendar/save_unavailability',
                [
                    'unavailability' => [
                        'start_datetime' => $blockStart,
                        'end_datetime' => $blockEnd,
                        'notes' => $fixture->run . '_calendar_race',
                        'id_users_provider' => $fixture->providerId,
                    ],
                ],
                $multi,
            );
            $calendarWaiters = $this->waitForProviderWaiters($multi, $observer, $ownerId, $db->dbprefix('users'), 1);
            self::assertCount(1, $calendarWaiters, 'Calendar request must be queued behind the held provider row.');

            $bookingHandle = $this->startRequest(
                $this->bookingServer,
                $bookingClient,
                'booking/register',
                ['post_data' => $this->bookingPayload($fixture, $start)],
                $multi,
            );
            $bothWaiters = $this->waitForProviderWaiters($multi, $observer, $ownerId, $db->dbprefix('users'), 2);
            self::assertCount(2, $bothWaiters, 'Calendar and booking must be distinct queued requesters.');
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
            $blocks = $db
                ->get_where('appointments', [
                    'id_users_provider' => $fixture->providerId,
                    'is_unavailability' => 1,
                    'start_datetime' => $blockStart,
                    'end_datetime' => $blockEnd,
                    'notes' => $fixture->run . '_calendar_race',
                ])
                ->result_array();
            self::assertCount(1, $blocks);
            self::assertGreaterThan(0, (int) $blocks[0]['id']);
            self::assertSame(0, (int) $blocks[0]['id_services']);
            self::assertSame(0, (int) $blocks[0]['id_users_customer']);

            self::assertSame(409, (int) curl_getinfo($bookingHandle, CURLINFO_RESPONSE_CODE));
            self::assertSame(
                ['success' => false, 'message' => lang('requested_hour_is_unavailable')],
                json_decode((string) curl_multi_getcontent($bookingHandle), true, 512, JSON_THROW_ON_ERROR),
            );
            $after = $this->mutationCounts();
            self::assertSame($before['appointments'] + 1, $after['appointments']);
            self::assertSame($before['users'], $after['users']);
            self::assertSame($before['consents'], $after['consents']);
            self::assertSame(
                0,
                $db->where('notes', $fixture->run . '_booking_race')->count_all_results('appointments'),
            );
            self::assertSame(0, $db->where('last_name', 'Race ' . $fixture->run)->count_all_results('users'));
            self::assertSame(0, $db->where('last_name', 'Race ' . $fixture->run)->count_all_results('consents'));
        } finally {
            if ($transactionOpen && $owner->trans_active()) {
                $owner->trans_rollback();
            }
            foreach ([$calendarHandle, $bookingHandle] as $handle) {
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

    private function loginProvider(DefenseCycleHttpServer $server, DefenseCycleFixtures $fixture): GateHttpClient
    {
        $credentials = $server->client();
        self::assertSame(200, $credentials->get('login')->statusCode);
        $response = $credentials->post('login/validate', [
            'username' => $fixture->run . '_provider',
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
                'SELECT DISTINCT r.THREAD_ID AS requester_thread_id, ' .
                    'COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text ' .
                    'FROM performance_schema.data_lock_waits w ' .
                    'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID ' .
                    'JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID ' .
                    'JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID ' .
                    'LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID ' .
                    'WHERE b.PROCESSLIST_ID = ' .
                    $ownerId .
                    ' AND l.OBJECT_SCHEMA = ' .
                    $observer->escape(Config::DB_NAME) .
                    ' AND l.OBJECT_NAME = ' .
                    $observer->escape($table),
            );
            self::assertNotFalse($result, 'Independent lock instrumentation must be available.');
            $waiters = [];
            $expectedSql = strtoupper('SELECT `id` FROM `' . $table . '` WHERE `id` IN (');
            foreach ($result->result_array() as $row) {
                $statement = strtoupper((string) ($row['statement_text'] ?? ''));
                if (
                    str_contains($statement, $expectedSql) &&
                    preg_match('/ORDER BY `ID`(?: ASC)? FOR UPDATE/', $statement) === 1
                ) {
                    $waiters[(int) $row['requester_thread_id']] = (int) $row['requester_thread_id'];
                }
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

    private function bookingPayload(DefenseCycleFixtures $fixture, DateTimeImmutable $start): array
    {
        return [
            'appointment' => [
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => $fixture->serviceId,
                'id_users_provider' => $fixture->providerId,
                'location' => '',
                'notes' => $fixture->run . '_booking_race',
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Synthetic',
                'last_name' => 'Race ' . $fixture->run,
                'phone_number' => '000000000',
                'address' => '',
                'city' => '',
                'zip_code' => '',
                'timezone' => 'UTC',
                'notes' => $fixture->run,
            ],
            'manage_mode' => false,
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
        $blockNotes = $fixture->run . '_calendar_race';
        $bookingNotes = $fixture->run . '_booking_race';
        $customerLastName = 'Race ' . $fixture->run;
        foreach ($db->get_where('appointments', ['notes' => $blockNotes])->result_array() as $row) {
            self::assertSame($fixture->providerId, (int) $row['id_users_provider']);
            self::assertSame(1, (int) $row['is_unavailability']);
            self::assertTrue(
                $db->delete('appointments', [
                    'id' => (int) $row['id'],
                    'id_users_provider' => $fixture->providerId,
                    'is_unavailability' => 1,
                    'notes' => $blockNotes,
                ]),
            );
            self::assertSame([], $fixture->row('appointments', (int) $row['id']));
        }
        foreach ($db->get_where('appointments', ['notes' => $bookingNotes])->result_array() as $row) {
            self::assertSame($fixture->providerId, (int) $row['id_users_provider']);
            self::assertSame($fixture->serviceId, (int) $row['id_services']);
            $appointmentId = (int) $row['id'];
            self::assertTrue($db->delete('reschedule_authorities', ['appointment_id' => $appointmentId]));
            self::assertTrue($db->delete('appointments', ['id' => $appointmentId, 'notes' => $bookingNotes]));
            self::assertSame([], $fixture->row('appointments', $appointmentId));
        }
        self::assertTrue($db->delete('consents', ['first_name' => 'Synthetic', 'last_name' => $customerLastName]));
        self::assertSame(
            0,
            $db->where('first_name', 'Synthetic')->where('last_name', $customerLastName)->count_all_results('consents'),
        );
        self::assertTrue(
            $db->delete('users', [
                'first_name' => 'Synthetic',
                'last_name' => $customerLastName,
                'notes' => $fixture->run,
            ]),
        );
        self::assertSame(
            0,
            $db
                ->where('first_name', 'Synthetic')
                ->where('last_name', $customerLastName)
                ->where('notes', $fixture->run)
                ->count_all_results('users'),
        );
        self::assertSame(0, $db->where('notes', $blockNotes)->count_all_results('appointments'));
        self::assertSame(0, $db->where('notes', $bookingNotes)->count_all_results('appointments'));
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

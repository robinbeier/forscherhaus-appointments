<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP coverage for the public provider-slot booking race. */
final class PublicBookingProviderRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $firstServer = null;
    private ?DefenseCycleHttpServer $secondServer = null;
    /** @var array<string, ?array<string, mixed>> */
    private array $settings = [];
    /** @var list<string> */
    private array $ownedEmails = [];
    /** @var list<string> */
    private array $ownedNotes = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            foreach (['display_email', 'require_email', 'display_privacy_policy', 'display_terms_and_conditions'] as $name) {
                $this->settings[$name] = $db->get_where('settings', ['name' => $name])->row_array() ?: null;
            }
            $db->update('settings', ['value' => '1'], ['name' => 'display_email']);
            $db->update('settings', ['value' => '1'], ['name' => 'require_email']);
            $db->update('settings', ['value' => '1'], ['name' => 'display_privacy_policy']);
            $db->update('settings', ['value' => '1'], ['name' => 'display_terms_and_conditions']);
            $this->firstServer = new DefenseCycleHttpServer();
            $this->secondServer = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->firstServer?->close();
            $this->secondServer?->close();
            $this->restoreSettings();
            $this->cleanupOwnedWrites();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->firstServer?->close();
            $this->secondServer?->close();
        } finally {
            $this->restoreSettings();
            $this->cleanupOwnedWrites();
            $this->fixture?->cleanup();
        }
    }

    public function testTwoPublicPostsSerializeAtProviderLockAndLoseWithoutPartialWrites(): void
    {
        $fixture = $this->fixture;
        $firstServer = $this->firstServer;
        $secondServer = $this->secondServer;
        self::assertNotNull($fixture);
        self::assertNotNull($firstServer);
        self::assertNotNull($secondServer);
        $db = get_instance()->db;
        $start = new DateTimeImmutable('+14 days 10:00:00');
        $payloads = [
            $this->payload($fixture, $start, 'first'),
            $this->payload($fixture, $start, 'second'),
        ];
        $clients = [$firstServer->client(), $secondServer->client()];
        foreach ($clients as $client) {
            self::assertSame(200, $client->get('booking')->statusCode);
        }
        $csrf = array_map(static fn($client): string => (string) $client->getCookie('csrf_cookie'), $clients);
        self::assertNotSame('', $csrf[0]);
        self::assertNotSame('', $csrf[1]);

        $observer = $this->observerConnection();
        $ownerId = mysqli_thread_id($db->conn_id);
        $transactionOpen = false;
        $handles = [];
        $multi = null;
        try {
            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $db->query(
                    'SELECT `id` FROM `' . $db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE',
                    [$fixture->providerId],
                ),
            );

            $multi = curl_multi_init();
            foreach ($payloads as $index => $payload) {
                $handle = curl_init($index === 0
                    ? $firstServer->baseUrl . '/index.php/booking/register'
                    : $secondServer->baseUrl . '/index.php/booking/register');
                self::assertInstanceOf(CurlHandle::class, $handle);
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query(
                        ['post_data' => $payload, 'csrf_token' => $csrf[$index]],
                        '',
                        '&',
                        PHP_QUERY_RFC3986,
                    ),
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'Content-Type: application/x-www-form-urlencoded',
                        'Cookie: csrf_cookie=' . $csrf[$index],
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 20,
                ]);
                self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
                $handles[] = $handle;
            }

            $waiting = $this->waitForProviderWait($observer, $ownerId, $db->dbprefix('users'), 2, $multi);
            self::assertSame(2, $waiting, 'Both public requests must reach the provider parent lock with the expected SQL.');
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi), 'Both HTTP requests must finish after the owner releases the lock.');

            $responses = array_map(
                static fn(CurlHandle $handle): array => [
                    'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => (string) curl_multi_getcontent($handle),
                ],
                $handles,
            );
            $statuses = array_column($responses, 'status');
            sort($statuses);
            self::assertSame([200, 409], $statuses);

            $success = null;
            foreach ($responses as $response) {
                $decoded = json_decode($response['body'], true);
                if ($response['status'] === 200) {
                    self::assertIsArray($decoded);
                    self::assertArrayHasKey('appointment_id', $decoded);
                    $success = $decoded;
                } else {
                    self::assertSame(['success' => false, 'message' => lang('requested_hour_is_unavailable')], $decoded);
                }
            }
            self::assertIsArray($success);
            $winnerAppointment = $db->get_where('appointments', ['id' => (int) $success['appointment_id']])->row_array();
            self::assertSame($fixture->providerId, (int) ($winnerAppointment['id_users_provider'] ?? 0));
            self::assertSame($fixture->serviceId, (int) ($winnerAppointment['id_services'] ?? 0));

            $winnerEmails = [
                $payloads[0]['customer']['email'],
                $payloads[1]['customer']['email'],
            ];
            $customers = $db->where_in('email', $winnerEmails)->get('users')->result_array();
            self::assertCount(1, $customers, 'The losing request must not leave a customer row.');
            self::assertSame($winnerAppointment['id_users_customer'], $customers[0]['id']);
            $appointments = $db->where_in('notes', $this->ownedNotes)->get('appointments')->result_array();
            self::assertCount(1, $appointments, 'The losing request must not leave an appointment row.');
            self::assertSame((int) $winnerAppointment['id'], (int) $appointments[0]['id']);
            $winningEmail = (string) $customers[0]['email'];
            self::assertSame(2, $db->where('email', $winningEmail)->where_in('type', ['privacy-policy', 'terms-and-conditions'])->count_all_results('consents'));
            $losingEmail = $winningEmail === $winnerEmails[0] ? $winnerEmails[1] : $winnerEmails[0];
            self::assertSame(0, $db->where('email', $losingEmail)->count_all_results('consents'));
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            if ($multi instanceof CurlMultiHandle) {
                foreach ($handles as $handle) {
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                }
                curl_multi_close($multi);
            }
            $observer->close();
        }
    }

    public function testSinglePublicPostIsPositiveControl(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->firstServer?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('booking')->statusCode);
        $payload = $this->payload($fixture, new DateTimeImmutable('+15 days 10:00:00'), 'positive');
        $response = $client->post('booking/register', ['post_data' => $payload]);
        self::assertSame(200, $response->statusCode, $response->body);
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('appointment_id', $decoded);
        self::assertSame(
            1,
            get_instance()->db->where('notes', 'ROB-717 ' . $fixture->run . ' positive')->count_all_results('appointments'),
        );
    }

    /** @return array<string, mixed> */
    private function payload(DefenseCycleFixtures $fixture, DateTimeImmutable $start, string $suffix): array
    {
        $email = 'rob717-' . $fixture->run . '-' . $suffix . '@synthetic.invalid';
        $notes = 'ROB-717 ' . $fixture->run . ' ' . $suffix;
        $this->ownedEmails[] = $email;
        $this->ownedNotes[] = $notes;
        return [
            'appointment' => [
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => $fixture->serviceId,
                'id_users_provider' => $fixture->providerId,
                'location' => '',
                'notes' => $notes,
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'ROB-717',
                'last_name' => ucfirst($suffix),
                'email' => $email,
                'phone_number' => '+49123456789',
                'address' => 'Synthetic Street 1',
                'city' => 'Bielefeld',
                'zip_code' => '33602',
                'timezone' => 'UTC',
            ],
            'manage_mode' => false,
        ];
    }

    private function observerConnection(): object
    {
        $observer = get_instance()->load->database([
            'hostname' => 'mysql', 'username' => 'root', 'password' => 'secret', 'database' => 'easyappointments',
            'dbdriver' => 'mysqli', 'dbprefix' => get_instance()->db->dbprefix, 'pconnect' => false,
            'db_debug' => false, 'char_set' => 'utf8mb4', 'dbcollat' => 'utf8mb4_general_ci',
        ], true);
        self::assertNotSame(mysqli_thread_id(get_instance()->db->conn_id), mysqli_thread_id($observer->conn_id));
        self::assertTrue($observer->query('SET SESSION TRANSACTION READ ONLY'));

        return $observer;
    }

    private function waitForProviderWait(object $observer, int $ownerId, string $table, int $expected, CurlMultiHandle $multi): int
    {
        $deadline = microtime(true) + 8;
        do {
            curl_multi_exec($multi, $running);
            $result = $observer->query(
                'SELECT COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text ' .
                'FROM performance_schema.data_lock_waits w ' .
                'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID ' .
                'JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID ' .
                'JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID ' .
                'LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID ' .
                'WHERE b.PROCESSLIST_ID = ' . $ownerId . ' AND l.OBJECT_SCHEMA = ' . $observer->escape(Config::DB_NAME) .
                ' AND l.OBJECT_NAME = ' . $observer->escape($table),
            );
            self::assertNotFalse($result, 'Independent lock instrumentation must be available.');
            $expectedSql = strtoupper('SELECT `id` FROM `' . $table . '` WHERE `id` IN (');
            $count = 0;
            foreach ($result->result_array() as $row) {
                $statement = strtoupper((string) ($row['statement_text'] ?? ''));
                if (str_contains($statement, $expectedSql) && str_contains($statement, 'ORDER BY `ID` FOR UPDATE')) {
                    $count++;
                }
            }
            if ($count >= $expected) {
                return $count;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return $count;
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

    private function restoreSettings(): void
    {
        if ($this->settings === []) {
            return;
        }
        $db = get_instance()->db;
        foreach ($this->settings as $name => $row) {
            if ($row === null) {
                $db->delete('settings', ['name' => $name]);
            } else {
                $db->update('settings', ['value' => $row['value']], ['name' => $name]);
            }
        }
        $this->settings = [];
    }

    private function cleanupOwnedWrites(): void
    {
        if ($this->ownedEmails === [] && $this->ownedNotes === []) {
            return;
        }
        $db = get_instance()->db;
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $customerIds = [];
        if ($this->ownedEmails !== []) {
            $customers = $db->where_in('email', $this->ownedEmails)->get('users')->result_array();
            foreach ($customers as $customer) {
                self::assertSame('ROB-717', $customer['first_name']);
                self::assertStringStartsWith('rob717-' . $fixture->run . '-', $customer['email']);
                $customerIds[] = (int) $customer['id'];
            }
            $db->where_in('email', $this->ownedEmails)->delete('consents');
        }
        $appointmentIds = [];
        if ($this->ownedNotes !== []) {
            $appointmentIds = array_map(
                'intval',
                array_column($db->where_in('notes', $this->ownedNotes)->get('appointments')->result_array(), 'id'),
            );
        }
        if ($customerIds !== []) {
            $appointmentIds = array_merge(
                $appointmentIds,
                array_map(
                    'intval',
                    array_column($db->where_in('id_users_customer', $customerIds)->get('appointments')->result_array(), 'id'),
                ),
            );
        }
        $appointmentIds = array_values(array_unique($appointmentIds));
        if ($appointmentIds !== []) {
            foreach ($db->where_in('id', $appointmentIds)->get('appointments')->result_array() as $appointment) {
                self::assertSame($fixture->providerId, (int) $appointment['id_users_provider']);
                self::assertSame($fixture->serviceId, (int) $appointment['id_services']);
                self::assertContains($appointment['notes'], $this->ownedNotes);
            }
            $db->where_in('appointment_id', $appointmentIds)->delete('reschedule_authorities');
            $db->where_in('id', $appointmentIds)->delete('appointments');
            self::assertSame(0, $db->where_in('id', $appointmentIds)->count_all_results('appointments'));
        }
        if ($customerIds !== []) {
            $db->where_in('id', $customerIds)->delete('users');
            self::assertSame(0, $db->where_in('id', $customerIds)->count_all_results('users'));
        }
        if ($this->ownedEmails !== []) {
            self::assertSame(0, $db->where_in('email', $this->ownedEmails)->count_all_results('consents'));
        }
        $this->ownedEmails = [];
        $this->ownedNotes = [];
    }
}

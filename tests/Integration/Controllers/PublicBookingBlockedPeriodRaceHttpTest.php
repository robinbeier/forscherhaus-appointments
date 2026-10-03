<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP/DB coverage for a global block committed during public booking. */
final class PublicBookingBlockedPeriodRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?GateHttpClient $client = null;
    private ?array $displayEmailSetting = null;
    private ?array $requireEmailSetting = null;
    private ?int $blockedPeriodId = null;
    private ?int $ownedAppointmentId = null;
    private ?int $ownedCustomerId = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->displayEmailSetting = $db->get_where('settings', ['name' => 'display_email'])->row_array() ?: null;
            $this->requireEmailSetting = $db->get_where('settings', ['name' => 'require_email'])->row_array() ?: null;
            $db->update('settings', ['value' => '0'], ['name' => 'display_email']);
            $db->update('settings', ['value' => '0'], ['name' => 'require_email']);
            $this->server = new DefenseCycleHttpServer();
            $this->client = $this->server->client();
            self::assertSame(200, $this->client->get('booking')->statusCode);
        } catch (Throwable $error) {
            $this->server?->close();
            $this->restoreSettings();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $db = get_instance()->db;
                $this->cleanupOwnedRaceRows($db);
                if ($this->blockedPeriodId !== null) {
                    $db->delete('blocked_periods', ['id' => $this->blockedPeriodId]);
                    self::assertSame([], $this->fixture?->blockedPeriodRow($this->blockedPeriodId));
                }
                if ($this->ownedAppointmentId !== null) {
                    $db->delete('reschedule_authorities', ['appointment_id' => $this->ownedAppointmentId]);
                    $db->delete('appointments', ['id' => $this->ownedAppointmentId]);
                    self::assertSame([], $this->fixture?->row('appointments', $this->ownedAppointmentId));
                }
                if ($this->ownedCustomerId !== null) {
                    $db->delete('users', ['id' => $this->ownedCustomerId]);
                    self::assertSame([], $this->fixture?->row('users', $this->ownedCustomerId));
                }
            } finally {
                try {
                    $this->restoreSettings();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
        }
    }

    public function testBookingRechecksGlobalBlockCommittedWhileWaitingForProviderLock(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $db = get_instance()->db;
        $start = (new DateTimeImmutable('today'))->modify('+14 days')->setTime(10, 0);
        $payload = $this->bookingPayload($fixture, $start);
        $beforeCounts = $this->mutationCounts();
        $observer = $this->independentConnection();
        $writer = $this->independentConnection();
        $ownerId = mysqli_thread_id($db->conn_id);
        $transactionOpen = false;
        $handle = null;
        $multi = null;

        try {
            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            $usersTable = $db->dbprefix('users');
            self::assertNotFalse(
                $db->query('SELECT `id` FROM `' . $usersTable . '` WHERE `id` = ? FOR UPDATE', [$fixture->providerId]),
            );

            $csrf = (string) $client->getCookie('csrf_cookie');
            self::assertNotSame('', $csrf);
            $handle = curl_init($this->server?->baseUrl . '/index.php/booking/register');
            self::assertInstanceOf(CurlHandle::class, $handle);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(
                    ['post_data' => $payload, 'csrf_token' => $csrf],
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                ),
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                    'Cookie: csrf_cookie=' . $csrf,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 20,
            ]);
            $multi = curl_multi_init();
            self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));

            self::assertSame(
                1,
                $this->waitForProviderWait($observer, $ownerId, $usersTable, $multi),
                'The public request must be waiting at the provider parent lock before the block commits.',
            );

            $blockStart = $start->format('Y-m-d H:i:s');
            $blockEnd = $start->modify('+30 minutes')->format('Y-m-d H:i:s');
            self::assertTrue($writer->trans_begin());
            self::assertTrue(
                $writer->insert('blocked_periods', [
                    'name' => $fixture->run . '_race_block',
                    'start_datetime' => $blockStart,
                    'end_datetime' => $blockEnd,
                    'notes' => $fixture->run,
                ]),
            );
            $this->blockedPeriodId = (int) $writer->insert_id();
            self::assertGreaterThan(0, $this->blockedPeriodId);
            self::assertTrue($writer->trans_commit());
            self::assertSame(
                $blockStart,
                $observer->get_where('blocked_periods', ['id' => $this->blockedPeriodId])->row_array()[
                    'start_datetime'
                ] ?? null,
            );

            // The independent writer commit is complete before the owner releases
            // the provider lock; the HTTP request can therefore only continue into
            // its post-lock availability read after the block is durable. The
            // ordinary create path starts its transaction after the first
            // availability read, and its intervening provider/service lock reads
            // are current reads, so this schedule does not rely on an old
            // consistent-read snapshot having been established in that transaction.
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi));
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $body = (string) curl_multi_getcontent($handle);
            self::assertSame(409, $status, $body);
            self::assertSame(
                ['success' => false, 'message' => lang('requested_hour_is_unavailable')],
                json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            );
            self::assertSame($beforeCounts, $this->mutationCounts());
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            if ($handle instanceof CurlHandle && $multi instanceof CurlMultiHandle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                curl_multi_close($multi);
            }
            $writer->close();
            $observer->close();
        }
    }

    public function testSameSlotBooksAfterOwnCommittedBlockIsRemoved(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $db = get_instance()->db;
        $start = (new DateTimeImmutable('today'))->modify('+15 days')->setTime(10, 0);
        $blockName = $fixture->run . '_positive_block';

        self::assertTrue($db->trans_begin());
        self::assertTrue(
            $db->insert('blocked_periods', [
                'name' => $blockName,
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'notes' => $fixture->run,
            ]),
        );
        $this->blockedPeriodId = (int) $db->insert_id();
        self::assertGreaterThan(0, $this->blockedPeriodId);
        self::assertTrue($db->trans_commit());
        self::assertSame(
            $blockName,
            $db->get_where('blocked_periods', ['id' => $this->blockedPeriodId])->row_array()['name'] ?? null,
        );

        self::assertTrue($db->delete('blocked_periods', ['id' => $this->blockedPeriodId]));
        self::assertSame([], $fixture->blockedPeriodRow($this->blockedPeriodId));
        $this->blockedPeriodId = null;

        $beforeConsentCount = $db->count_all('consents');
        try {
            $response = $client->post('booking/register', [
                'post_data' => $this->bookingPayload($fixture, $start, 'positive'),
            ]);
            self::assertSame(200, $response->statusCode, $response->body);
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('appointment_id', $decoded);
            $this->ownedAppointmentId = (int) $decoded['appointment_id'];
            self::assertGreaterThan(0, $this->ownedAppointmentId);
            $appointment = $fixture->row('appointments', $this->ownedAppointmentId);
            self::assertSame($fixture->serviceId, (int) $appointment['id_services']);
            self::assertSame($fixture->providerId, (int) $appointment['id_users_provider']);
            self::assertSame($fixture->run . '_positive', $appointment['notes']);
            $this->ownedCustomerId = (int) $appointment['id_users_customer'];
            $customer = $fixture->row('users', $this->ownedCustomerId);
            self::assertSame('Positive ' . $fixture->run, $customer['last_name']);
            self::assertSame(1, $db->where('notes', $fixture->run . '_positive')->count_all_results('appointments'));
            self::assertSame(1, $db->where('id', $this->ownedCustomerId)->count_all_results('users'));
            self::assertSame($beforeConsentCount, $db->count_all('consents'));
        } finally {
            if ($this->ownedAppointmentId !== null) {
                $db->delete('reschedule_authorities', ['appointment_id' => $this->ownedAppointmentId]);
                $db->delete('appointments', ['id' => $this->ownedAppointmentId]);
                self::assertSame([], $fixture->row('appointments', $this->ownedAppointmentId));
            }
            if ($this->ownedCustomerId !== null) {
                $db->delete('users', ['id' => $this->ownedCustomerId]);
                self::assertSame([], $fixture->row('users', $this->ownedCustomerId));
            }
            $this->ownedAppointmentId = null;
            $this->ownedCustomerId = null;
        }
    }

    /** @return object */
    private function independentConnection(): object
    {
        $connection = get_instance()->load->database(
            [
                'hostname' => 'mysql',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'easyappointments',
                'dbdriver' => 'mysqli',
                'dbprefix' => get_instance()->db->dbprefix,
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

    private function waitForProviderWait(object $observer, int $ownerId, string $table, CurlMultiHandle $multi): int
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
                    'WHERE b.PROCESSLIST_ID = ' .
                    $ownerId .
                    ' AND l.OBJECT_SCHEMA = ' .
                    $observer->escape(Config::DB_NAME) .
                    ' AND l.OBJECT_NAME = ' .
                    $observer->escape($table),
            );
            self::assertNotFalse($result, 'Independent lock instrumentation must be available.');
            foreach ($result->result_array() as $row) {
                $statement = strtoupper((string) ($row['statement_text'] ?? ''));
                if (
                    str_contains($statement, strtoupper('SELECT `ID` FROM `' . $table . '` WHERE `ID` IN (')) &&
                    str_contains($statement, 'ORDER BY `ID` FOR UPDATE')
                ) {
                    return 1;
                }
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return 0;
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

    /** @return array<string,mixed> */
    private function bookingPayload(
        DefenseCycleFixtures $fixture,
        DateTimeImmutable $start,
        string $case = 'race',
    ): array {
        return [
            'appointment' => [
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => $fixture->serviceId,
                'id_users_provider' => $fixture->providerId,
                'location' => '',
                'notes' => $fixture->run . '_' . $case,
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Synthetic',
                'last_name' => ucfirst($case) . ' ' . $fixture->run,
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

    private function restoreSettings(): void
    {
        $db = get_instance()->db;
        foreach (
            ['display_email' => $this->displayEmailSetting, 'require_email' => $this->requireEmailSetting]
            as $name => $row
        ) {
            if ($row !== null) {
                $db->update('settings', ['value' => $row['value']], ['id' => $row['id']]);
                self::assertSame(
                    $row['value'],
                    $db->get_where('settings', ['id' => $row['id']])->row_array()['value'] ?? null,
                );
            }
        }
    }

    private function cleanupOwnedRaceRows(object $db): void
    {
        $fixture = $this->fixture;
        if ($fixture === null) {
            return;
        }

        $lastName = 'Race ' . $fixture->run;
        $consentIdentity = ['first_name' => 'Synthetic', 'last_name' => $lastName];
        $appointments = $db->get_where('appointments', ['notes' => $fixture->run . '_race'])->result_array();
        foreach ($appointments as $appointment) {
            self::assertSame($fixture->providerId, (int) $appointment['id_users_provider']);
            self::assertSame($fixture->serviceId, (int) $appointment['id_services']);
            $appointmentId = (int) $appointment['id'];
            self::assertTrue($db->delete('reschedule_authorities', ['appointment_id' => $appointmentId]));
            self::assertTrue($db->delete('appointments', ['id' => $appointmentId]));
            self::assertSame([], $fixture->row('appointments', $appointmentId));
        }

        // Consent or customer creation might be the only committed partial
        // effect if the tested rejection regresses. Remove those own rows even
        // when no appointment was created.
        self::assertTrue($db->delete('consents', $consentIdentity));
        self::assertSame(0, $db->get_where('consents', $consentIdentity)->num_rows());
        $customers = $db
            ->get_where('users', [
                'first_name' => 'Synthetic',
                'last_name' => $lastName,
                'notes' => $fixture->run,
            ])
            ->result_array();
        foreach ($customers as $customer) {
            $customerId = (int) $customer['id'];
            self::assertSame(0, $db->get_where('appointments', ['id_users_customer' => $customerId])->num_rows());
            self::assertTrue($db->delete('users', ['id' => $customerId]));
            self::assertSame([], $fixture->row('users', $customerId));
        }
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP/DB coverage for a manual provider block committed during public booking. */
final class PublicBookingManualUnavailabilityRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?GateHttpClient $client = null;
    private ?array $displayEmailSetting = null;
    private ?array $requireEmailSetting = null;
    private ?array $displayTermsSetting = null;
    private ?int $manualBlockId = null;
    private array $baselineCounts = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->baselineCounts = [
                'appointments' => $db->count_all('appointments'),
                'users' => $db->count_all('users'),
                'consents' => $db->count_all('consents'),
            ];
            $this->displayEmailSetting = $db->get_where('settings', ['name' => 'display_email'])->row_array() ?: null;
            $this->requireEmailSetting = $db->get_where('settings', ['name' => 'require_email'])->row_array() ?: null;
            $this->displayTermsSetting =
                $db->get_where('settings', ['name' => 'display_terms_and_conditions'])->row_array() ?: null;
            self::assertNotNull($this->displayEmailSetting);
            self::assertNotNull($this->requireEmailSetting);
            self::assertNotNull($this->displayTermsSetting);
            $db->update('settings', ['value' => '0'], ['id' => $this->displayEmailSetting['id']]);
            $db->update('settings', ['value' => '0'], ['id' => $this->requireEmailSetting['id']]);
            $db->update('settings', ['value' => '1'], ['id' => $this->displayTermsSetting['id']]);
            $this->server = new DefenseCycleHttpServer();
            $this->client = $this->server->client();
            self::assertSame(200, $this->client->get('booking')->statusCode);
        } catch (Throwable $error) {
            $this->server?->close();
            try {
                $this->cleanupOwnedRows();
            } finally {
                try {
                    $this->restoreSettings();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $this->cleanupOwnedRows();
            } finally {
                try {
                    $this->restoreSettings();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
        }
    }

    public function testBookingRejectsManualBlockCommittedWhileWaitingForProviderLock(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $db = get_instance()->db;
        $start = (new DateTimeImmutable('today'))->modify('+14 days')->setTime(10, 0);
        $payload = $this->bookingPayload($fixture, $start, 'race');
        $observer = $this->independentConnection();
        $writer = $this->independentConnection();
        $ownerId = mysqli_thread_id($writer->conn_id);
        $usersTable = $db->dbprefix('users');
        $writerTransactionOpen = false;
        $handle = null;
        $multi = null;

        try {
            // Insert first and keep this transaction open. The uncommitted row
            // holds a shared lock on the provider parent through the FK. The
            // booking request can read the pre-existing availability, then its
            // provider FOR UPDATE waits on this writer transaction.
            self::assertTrue($writer->trans_begin());
            $writerTransactionOpen = true;
            $blockStart = $start->format('Y-m-d H:i:s');
            $blockEnd = $start->modify('+30 minutes')->format('Y-m-d H:i:s');
            self::assertTrue(
                $writer->insert('appointments', [
                    'book_datetime' => date('Y-m-d H:i:s'),
                    'start_datetime' => $blockStart,
                    'end_datetime' => $blockEnd,
                    'notes' => $fixture->run . '_manual_race_block',
                    'hash' => $fixture->run . '_manual_race_hash',
                    'is_unavailability' => 1,
                    'id_users_provider' => $fixture->providerId,
                    'create_datetime' => date('Y-m-d H:i:s'),
                    'update_datetime' => date('Y-m-d H:i:s'),
                ]),
            );
            $this->manualBlockId = (int) $writer->insert_id();
            self::assertGreaterThan(0, $this->manualBlockId);
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
            self::assertSame(1, $this->waitForProviderWait($observer, $ownerId, $usersTable, $multi));
            // The row is not visible to another connection until this commit.
            // Commit while the public request is blocked, then prove visibility
            // on the independent observer before allowing the request to finish.
            self::assertTrue($writer->trans_commit());
            $writerTransactionOpen = false;
            $visible = $observer->get_where('appointments', ['id' => $this->manualBlockId])->row_array();
            self::assertSame($fixture->run . '_manual_race_block', $visible['notes'] ?? null);
            self::assertSame(1, (int) ($visible['is_unavailability'] ?? 0));
            self::assertSame($blockStart, $visible['start_datetime'] ?? null);
            self::assertSame($blockEnd, $visible['end_datetime'] ?? null);
            self::assertSame($fixture->providerId, (int) ($visible['id_users_provider'] ?? 0));
            self::assertSame(0, (int) ($visible['id_services'] ?? 0));
            self::assertSame(0, (int) ($visible['id_users_customer'] ?? 0));

            self::assertTrue($this->drain($multi));
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $body = (string) curl_multi_getcontent($handle);
            self::assertSame(409, $status, $body);
            self::assertSame(
                ['success' => false, 'message' => lang('requested_hour_is_unavailable')],
                json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            );
            $expectedCounts = $this->baselineCounts;
            $expectedCounts['appointments']++;
            self::assertSame($expectedCounts, $this->mutationCounts());
            self::assertSame(0, $db->where('notes', $fixture->run . '_race')->count_all_results('appointments'));
            self::assertSame(0, $db->where('last_name', 'Race ' . $fixture->run)->count_all_results('users'));
            self::assertSame(0, $db->where('last_name', 'Race ' . $fixture->run)->count_all_results('consents'));
            self::assertSame($visible, $fixture->row('appointments', $this->manualBlockId));
        } finally {
            if ($writerTransactionOpen && $writer->trans_active()) {
                $writer->trans_rollback();
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

    public function testSameSlotBooksAfterOwnManualBlockIsRemovedWithFreshClient(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $start = (new DateTimeImmutable('today'))->modify('+15 days')->setTime(10, 0);
        $blockStart = $start->format('Y-m-d H:i:s');
        $blockEnd = $start->modify('+30 minutes')->format('Y-m-d H:i:s');
        self::assertTrue(
            $db->insert('appointments', [
                'book_datetime' => date('Y-m-d H:i:s'),
                'start_datetime' => $blockStart,
                'end_datetime' => $blockEnd,
                'notes' => $fixture->run . '_manual_positive_block',
                'hash' => $fixture->run . '_manual_positive_hash',
                'is_unavailability' => 1,
                'id_users_provider' => $fixture->providerId,
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]),
        );
        $this->manualBlockId = (int) $db->insert_id();
        self::assertGreaterThan(0, $this->manualBlockId);
        self::assertTrue(
            $db->delete('appointments', [
                'id' => $this->manualBlockId,
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
                'notes' => $fixture->run . '_manual_positive_block',
            ]),
        );
        self::assertSame([], $fixture->row('appointments', $this->manualBlockId));
        $this->manualBlockId = null;

        $freshServer = new DefenseCycleHttpServer();
        try {
            $freshClient = $freshServer->client();
            self::assertSame(200, $freshClient->get('booking')->statusCode);
            $response = $freshClient->post('booking/register', [
                'post_data' => $this->bookingPayload($fixture, $start, 'positive'),
            ]);
            self::assertSame(200, $response->statusCode, $response->body);
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('appointment_id', $decoded);
            $row = $fixture->row('appointments', (int) $decoded['appointment_id']);
            self::assertSame($fixture->providerId, (int) $row['id_users_provider']);
            self::assertSame($fixture->serviceId, (int) $row['id_services']);
            self::assertSame($fixture->run . '_positive', $row['notes']);
            self::assertSame(1, $db->where('notes', $fixture->run . '_positive')->count_all_results('appointments'));
            self::assertSame(1, $db->where('last_name', 'Positive ' . $fixture->run)->count_all_results('users'));
            self::assertSame(1, $db->where('last_name', 'Positive ' . $fixture->run)->count_all_results('consents'));
        } finally {
            $freshServer->close();
        }
    }

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

    private function bookingPayload(DefenseCycleFixtures $fixture, DateTimeImmutable $start, string $case): array
    {
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

    private function mutationCounts(): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'consents' => $db->count_all('consents'),
        ];
    }

    private function cleanupOwnedRows(): void
    {
        $fixture = $this->fixture;
        if ($fixture === null || !isset($fixture->providerId)) {
            return;
        }
        $db = get_instance()->db;
        $blockRows = $db
            ->get_where('appointments', [
                'notes' => $fixture->run . '_manual_race_block',
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
            ])
            ->result_array();
        $blockRows = array_merge(
            $blockRows,
            $db
                ->get_where('appointments', [
                    'notes' => $fixture->run . '_manual_positive_block',
                    'id_users_provider' => $fixture->providerId,
                    'is_unavailability' => 1,
                ])
                ->result_array(),
        );
        if ($this->manualBlockId !== null) {
            $row = $db->get_where('appointments', ['id' => $this->manualBlockId])->row_array();
            // A failed race setup can roll back the writer, leaving no row for
            // its assigned ID. Keep the original failure visible in that case.
            if (is_array($row) && $row !== []) {
                self::assertSame($fixture->providerId, (int) ($row['id_users_provider'] ?? 0));
                self::assertSame(1, (int) ($row['is_unavailability'] ?? 0));
                self::assertContains($row['notes'] ?? null, [
                    $fixture->run . '_manual_race_block',
                    $fixture->run . '_manual_positive_block',
                ]);
                self::assertSame(0, (int) ($row['id_services'] ?? 0));
                self::assertSame(0, (int) ($row['id_users_customer'] ?? 0));
                if (
                    !array_filter(
                        $blockRows,
                        fn(array $candidate): bool => (int) $candidate['id'] === $this->manualBlockId,
                    )
                ) {
                    $blockRows[] = $row;
                }
            }
        }
        foreach ($blockRows as $row) {
            $id = (int) $row['id'];
            self::assertTrue(
                $db->delete('appointments', [
                    'id' => $id,
                    'id_users_provider' => $fixture->providerId,
                    'is_unavailability' => 1,
                    'notes' => (string) $row['notes'],
                ]),
            );
            self::assertSame([], $fixture->row('appointments', $id));
        }
        foreach (['race', 'positive'] as $case) {
            $lastName = ucfirst($case) . ' ' . $fixture->run;
            $rows = $db->get_where('appointments', ['notes' => $fixture->run . '_' . $case])->result_array();
            foreach ($rows as $row) {
                self::assertSame($fixture->providerId, (int) $row['id_users_provider']);
                self::assertSame($fixture->serviceId, (int) $row['id_services']);
                $id = (int) $row['id'];
                $customerId = (int) $row['id_users_customer'];
                $db->delete('reschedule_authorities', ['appointment_id' => $id]);
                $db->delete('appointments', ['id' => $id, 'notes' => $fixture->run . '_' . $case]);
                $db->delete('consents', ['first_name' => 'Synthetic', 'last_name' => $lastName]);
                $db->delete('users', ['id' => $customerId, 'first_name' => 'Synthetic', 'last_name' => $lastName]);
                self::assertSame([], $fixture->row('appointments', $id));
                self::assertSame(
                    0,
                    $db->where('first_name', 'Synthetic')->where('last_name', $lastName)->count_all_results('consents'),
                );
                self::assertSame(
                    0,
                    $db
                        ->where('first_name', 'Synthetic')
                        ->where('last_name', $lastName)
                        ->where('notes', $fixture->run)
                        ->count_all_results('users'),
                );
            }
            $db->delete('consents', ['first_name' => 'Synthetic', 'last_name' => $lastName]);
            $db->delete('users', ['first_name' => 'Synthetic', 'last_name' => $lastName, 'notes' => $fixture->run]);
            self::assertSame(
                0,
                $db->where('first_name', 'Synthetic')->where('last_name', $lastName)->count_all_results('consents'),
            );
            self::assertSame(
                0,
                $db
                    ->where('first_name', 'Synthetic')
                    ->where('last_name', $lastName)
                    ->where('notes', $fixture->run)
                    ->count_all_results('users'),
            );
        }
        $this->manualBlockId = null;
    }

    private function restoreSettings(): void
    {
        $db = get_instance()->db;
        foreach ([$this->displayEmailSetting, $this->requireEmailSetting, $this->displayTermsSetting] as $row) {
            if ($row !== null) {
                self::assertTrue($db->update('settings', ['value' => $row['value']], ['id' => $row['id']]));
                $restored = $db->get_where('settings', ['id' => $row['id']])->row_array();
                self::assertSame((string) $row['value'], (string) ($restored['value'] ?? null));
            }
        }
    }
}

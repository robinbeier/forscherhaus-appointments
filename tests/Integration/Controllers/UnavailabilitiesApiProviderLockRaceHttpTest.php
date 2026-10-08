<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP/DB coverage for API manual blocks contending with public booking's provider lock. */
final class UnavailabilitiesApiProviderLockRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array<string, mixed> */
    private array $credentials = [];
    /** @var list<int> */
    private array $ownedIds = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->cleanupOwned();
            $this->server?->close();
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
                $this->cleanupOwned();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testApiManualBlockWaitsForProviderLockOnBothWriteRoutes(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);

        $start = (new DateTimeImmutable('today'))->modify('+10 days')->setTime(10, 0);
        $client = $server->client();
        self::assertSame(200, $client->get('booking')->statusCode);
        $hours = $client->post('booking/get_available_hours', [
            'provider_id' => $fixture->providerId,
            'service_id' => $fixture->serviceId,
            'selected_date' => $start->format('Y-m-d'),
            'manage_mode' => '0',
            'appointment_id' => '',
        ]);
        self::assertSame(200, $hours->statusCode, $hours->body);
        self::assertContains(
            '10:00',
            json_decode($hours->body, true, 512, JSON_THROW_ON_ERROR),
            'The owned 10:00 provider/service slot must be available before the lock probe.',
        );

        foreach (['api/v1/unavailabilities', 'api/v1/unavailabilities_api_v1/store'] as $routeIndex => $route) {
            $this->probeRoute($route, $start, $routeIndex === 0 ? 'api' : 'alias');
        }
    }

    public function testApiManualBlockUpdateIsObservedWhileProviderLockIsHeld(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);
        $db = get_instance()->db;
        $firstStart = (new DateTimeImmutable('today'))->modify('+10 days')->setTime(10, 0);
        $client = $server->client();
        self::assertSame(200, $client->get('booking')->statusCode);
        foreach (
            ['api/v1/unavailabilities' => 'api', 'api/v1/unavailabilities_api_v1/update/' => 'alias']
            as $route => $marker
        ) {
            $start = $marker === 'api' ? $firstStart : $firstStart->modify('+1 day');
            $oldStart = $start->modify('-60 minutes');
            $hours = $client->post('booking/get_available_hours', [
                'provider_id' => $fixture->providerId,
                'service_id' => $fixture->serviceId,
                'selected_date' => $start->format('Y-m-d'),
                'manage_mode' => '0',
                'appointment_id' => '',
            ]);
            self::assertSame(200, $hours->statusCode, $hours->body);
            self::assertContains('10:00', json_decode($hours->body, true, 512, JSON_THROW_ON_ERROR));
            $note = $fixture->run . '_api_update_' . $marker;
            self::assertTrue(
                $db->insert('appointments', [
                    'book_datetime' => date('Y-m-d H:i:s'),
                    'start_datetime' => $oldStart->format('Y-m-d H:i:s'),
                    'end_datetime' => $oldStart->modify('+30 minutes')->format('Y-m-d H:i:s'),
                    'notes' => $note,
                    'hash' => bin2hex(random_bytes(6)),
                    'is_unavailability' => 1,
                    'id_users_provider' => $fixture->providerId,
                    'create_datetime' => date('Y-m-d H:i:s'),
                    'update_datetime' => date('Y-m-d H:i:s'),
                ]),
            );
            $id = (int) $db->insert_id();
            self::assertGreaterThan(0, $id);
            $this->ownedIds[] = $id;

            $observer = $this->connection($db, true);
            $owner = $this->connection($db);
            $ownerId = mysqli_thread_id($owner->conn_id);
            $table = $db->dbprefix('users');
            $transactionOpen = false;
            $handle = null;
            $multi = null;
            try {
                self::assertTrue($owner->trans_begin());
                $transactionOpen = true;
                self::assertNotFalse(
                    $owner->query('SELECT `id` FROM `' . $table . '` WHERE `id` = ? FOR UPDATE', [
                        $fixture->providerId,
                    ]),
                );
                $payload = [
                    'id' => $id,
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                    'providerId' => $fixture->providerId,
                    'notes' => $note,
                ];
                $routePath = str_ends_with($route, '/') ? $route . $id : $route . '/' . $id;
                $handle = $this->startJsonRequest(
                    $server,
                    $routePath,
                    'Basic ' .
                        base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
                    $payload,
                    'PUT',
                );
                $multi = curl_multi_init();
                self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
                $waiters = $this->waitForUserTableWait($observer, $ownerId, $table, $multi);
                if ($waiters !== []) {
                    self::assertSame(
                        $oldStart->format('Y-m-d H:i:s'),
                        $db->get_where('appointments', ['id' => $id])->row_array()['start_datetime'],
                    );
                } else {
                    self::assertTrue($this->drain($multi));
                    self::assertSame(200, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
                    self::assertSame(
                        $start->format('Y-m-d H:i:s'),
                        $db->get_where('appointments', ['id' => $id])->row_array()['start_datetime'],
                    );
                    self::fail(
                        'API PUT moved a manual block into the initially free slot while the provider lock was still held.',
                    );
                }
                self::assertTrue($owner->trans_commit());
                $transactionOpen = false;
                self::assertTrue($this->drain($multi));
                self::assertSame(200, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
                $updated = $fixture->row('appointments', $id);
                self::assertSame($start->format('Y-m-d H:i:s'), $updated['start_datetime'] ?? null);
                self::assertSame(
                    $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                    $updated['end_datetime'] ?? null,
                );
                self::assertSame($note, $updated['notes'] ?? null);
            } finally {
                if ($transactionOpen && $owner->trans_active()) {
                    $owner->trans_rollback();
                }
                if ($handle instanceof CurlHandle && $multi instanceof CurlMultiHandle) {
                    $this->drain($multi);
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    curl_multi_close($multi);
                }
                $owner->close();
                $observer->close();
            }
        }
    }

    public function testApiManualBlockRejectsProviderDriftObservedAfterLockWait(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);
        $db = get_instance()->db;
        $start = (new DateTimeImmutable('today'))->modify('+10 days')->setTime(10, 0);
        $oldStart = $start->modify('-60 minutes');
        $originalNote = $fixture->run . '_api_drift_original';
        $requestedNote = $fixture->run . '_api_drift_requested';
        $appointmentCountBefore = (int) $db->count_all('appointments');
        self::assertTrue(
            $db->insert('appointments', [
                'book_datetime' => date('Y-m-d H:i:s'),
                'start_datetime' => $oldStart->format('Y-m-d H:i:s'),
                'end_datetime' => $oldStart->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'notes' => $originalNote,
                'hash' => bin2hex(random_bytes(6)),
                'is_unavailability' => 1,
                'id_users_provider' => $fixture->providerId,
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]),
        );
        $id = (int) $db->insert_id();
        self::assertGreaterThan(0, $id);
        $this->ownedIds[] = $id;

        $observer = $this->connection($db, true);
        $owner = $this->connection($db);
        $ownerId = mysqli_thread_id($owner->conn_id);
        $table = $db->dbprefix('users');
        $transactionOpen = false;
        $handle = null;
        $multi = null;
        try {
            self::assertTrue($owner->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $owner->query('SELECT `id` FROM `' . $table . '` WHERE `id` = ? FOR UPDATE', [$fixture->providerId]),
            );
            $payload = [
                'id' => $id,
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'providerId' => $fixture->providerId,
                'notes' => $requestedNote,
            ];
            $handle = $this->startJsonRequest(
                $server,
                'api/v1/unavailabilities/' . $id,
                'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
                $payload,
                'PUT',
            );
            $multi = curl_multi_init();
            self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
            self::assertCount(1, $this->waitForUserTableWait($observer, $ownerId, $table, $multi));
            self::assertSame(
                $oldStart->format('Y-m-d H:i:s'),
                $db->get_where('appointments', ['id' => $id])->row_array()['start_datetime'],
            );

            self::assertTrue($owner->update('appointments', ['id_users_provider' => $fixture->actorId], ['id' => $id]));
            self::assertTrue($owner->trans_commit());
            $transactionOpen = false;
            $committedRow = $fixture->row('appointments', $id);
            self::assertSame($fixture->actorId, (int) ($committedRow['id_users_provider'] ?? 0));

            self::assertTrue($this->drain($multi));
            self::assertSame(409, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            self::assertSame($committedRow, $fixture->row('appointments', $id));
            self::assertSame($appointmentCountBefore + 1, (int) $db->count_all('appointments'));
        } finally {
            if ($transactionOpen && $owner->trans_active()) {
                $owner->trans_rollback();
            }
            if ($handle instanceof CurlHandle && $multi instanceof CurlMultiHandle) {
                $this->drain($multi);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                curl_multi_close($multi);
            }
            if (isset($id)) {
                $db->update('appointments', ['id_users_provider' => $fixture->providerId], ['id' => $id]);
            }
            $owner->close();
            $observer->close();
        }
    }

    private function probeRoute(string $route, DateTimeImmutable $start, string $marker): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);
        $db = get_instance()->db;
        $observer = $this->connection($db, true);
        $owner = $this->connection($db);
        $ownerId = mysqli_thread_id($owner->conn_id);
        $table = $db->dbprefix('users');
        $note = $fixture->run . '_api_lock_' . $marker;
        $transactionOpen = false;
        $handle = null;
        $multi = null;

        try {
            self::assertTrue($owner->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $owner->query('SELECT `id` FROM `' . $table . '` WHERE `id` = ? FOR UPDATE', [$fixture->providerId]),
            );

            $handle = $this->startJsonRequest(
                $server,
                $route,
                'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
                [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                    'providerId' => $fixture->providerId,
                    'notes' => $note,
                ],
            );
            $multi = curl_multi_init();
            self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));

            $waiters = $this->waitForUserTableWait($observer, $ownerId, $table, $multi);
            self::assertCount(1, $waiters, 'The API writer must remain queued behind the held provider row lock.');
            self::assertSame(
                0,
                $db
                    ->where('notes', $note)
                    ->where('id_users_provider', $fixture->providerId)
                    ->count_all_results('appointments'),
                'The API row must not be visible before the provider lock is released.',
            );

            self::assertTrue($owner->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi));
            self::assertSame(201, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            $body = json_decode((string) curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            $id = (int) ($body['id'] ?? 0);
            self::assertGreaterThan(0, $id);
            $this->ownedIds[] = $id;
            $row = $fixture->row('appointments', $id);
            self::assertSame($note, $row['notes'] ?? null);
            self::assertSame($start->format('Y-m-d H:i:s'), $row['start_datetime'] ?? null);
            self::assertSame($start->modify('+30 minutes')->format('Y-m-d H:i:s'), $row['end_datetime'] ?? null);
            self::assertSame($fixture->providerId, (int) ($row['id_users_provider'] ?? 0));
            self::assertSame(1, (int) ($row['is_unavailability'] ?? 0));
            self::assertSame(0, (int) ($row['id_services'] ?? 0));
            self::assertSame(0, (int) ($row['id_users_customer'] ?? 0));
            self::assertEmpty($row['id_parent_appointment'] ?? null);
        } finally {
            if ($transactionOpen && $owner->trans_active()) {
                $owner->trans_rollback();
            }
            if ($handle instanceof CurlHandle && $multi instanceof CurlMultiHandle) {
                $this->drain($multi);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                curl_multi_close($multi);
            }
            $owner->close();
            $observer->close();
        }
    }

    private function startJsonRequest(
        DefenseCycleHttpServer $server,
        string $route,
        string $authorization,
        array $payload,
        string $method = 'POST',
    ): CurlHandle {
        $handle = curl_init($server->baseUrl . '/index.php/' . $route);
        self::assertInstanceOf(CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: ' . $authorization,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
        ]);
        return $handle;
    }

    /** @return array<int, int> */
    private function waitForUserTableWait(object $observer, int $ownerId, string $table, CurlMultiHandle $multi): array
    {
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
            if ($waiters !== []) {
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

    private function connection(object $db, bool $readOnly = false): object
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
        if ($readOnly) {
            self::assertTrue($connection->query('SET SESSION TRANSACTION READ ONLY'));
        }
        self::assertNotSame(mysqli_thread_id(get_instance()->db->conn_id), mysqli_thread_id($connection->conn_id));
        return $connection;
    }

    private function cleanupOwned(): void
    {
        if ($this->fixture === null || !isset($this->fixture->providerId)) {
            return;
        }
        $db = get_instance()->db;
        foreach (['api_lock_api', 'api_lock_alias', 'api_update_api', 'api_update_alias'] as $marker) {
            $rows = $db
                ->get_where('appointments', [
                    'notes' => $this->fixture->run . '_' . $marker,
                    'id_users_provider' => $this->fixture->providerId,
                    'is_unavailability' => 1,
                ])
                ->result_array();
            foreach ($rows as $row) {
                $this->ownedIds[] = (int) $row['id'];
            }
        }
        $this->ownedIds = array_values(array_unique($this->ownedIds));
        foreach ($this->ownedIds as $id) {
            $row = $db->get_where('appointments', ['id' => $id])->row_array();
            if ($row !== []) {
                self::assertSame($this->fixture->providerId, (int) ($row['id_users_provider'] ?? 0));
                self::assertSame(1, (int) ($row['is_unavailability'] ?? 0));
                $db->delete('appointments', ['id' => $id, 'id_users_provider' => $this->fixture->providerId]);
            }
            self::assertSame([], $this->fixture->row('appointments', $id));
        }
        $this->ownedIds = [];
    }
}

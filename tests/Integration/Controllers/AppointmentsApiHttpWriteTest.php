<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Isolated HTTP regression coverage for the authenticated appointment API writes. */
final class AppointmentsApiHttpWriteTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh synthetic defense stack.');
        }

        $this->fixture = new DefenseCycleFixtures();
        $this->fixture->create();
        $this->credentials = $this->fixture->enableProviderHttpAuth();
        $this->server = new DefenseCycleHttpServer();
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testAdminAndBearerCanCreateUpdateAndDeleteAppointments(): void
    {
        $admin = $this->client(
            'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
        );
        $bearer = $this->client('Bearer ' . $this->credentials['token']);
        $payload = $this->payload('api-write');

        foreach ([$admin, $bearer] as $client) {
            $created = $this->json($client->requestJsonApp('POST', 'api/v1/appointments', $payload), 201);
            $id = (int) ($created['id'] ?? 0);
            self::assertGreaterThan(0, $id);
            $this->assertWritableFields($payload, $created);

            $updated = $payload;
            $updated['start'] = date('Y-m-d 12:00:00', strtotime('+17 days'));
            $updated['end'] = date('Y-m-d 12:30:00', strtotime('+17 days'));
            $updated['location'] = 'Updated Synthetic';
            $updated['color'] = '#123456';
            $updated['status'] = 'Confirmed';
            $updated['notes'] = $this->fixture->run . '_updated';
            $result = $this->json($client->requestJsonApp('PUT', 'api/v1/appointments/' . $id, $updated), 200);
            self::assertSame($id, (int) ($result['id'] ?? 0));
            $this->assertWritableFields($updated, $result);

            $sparseNotes = $this->fixture->run . '_sparse';
            $result = $this->json(
                $client->requestJsonApp('PUT', 'api/v1/appointments/' . $id, ['notes' => $sparseNotes]),
                200,
            );
            self::assertSame($sparseNotes, $result['notes'] ?? null);
            self::assertSame($updated['location'], $result['location'] ?? null);
            self::assertSame($updated['start'], $result['start'] ?? null);
            self::assertSame($updated['end'], $result['end'] ?? null);

            self::assertSame(204, $client->requestApp('DELETE', 'api/v1/appointments/' . $id)->statusCode);
            self::assertSame(404, $client->requestApp('DELETE', 'api/v1/appointments/' . $id)->statusCode);
        }
    }

    public function testWrongMethodsAndInvalidContentTypeDoNotReachAppointmentMutation(): void
    {
        $admin = $this->client(
            'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
        );
        $appointment = $this->fixture->appointment();
        $id = (int) $appointment['id'];
        $before = $this->snapshot($id);

        self::assertSame(200, $admin->get('api/v1/appointments/' . $id)->statusCode);
        self::assertSame(
            404,
            $admin->requestApp('POST', 'api/v1/appointments/' . $id, $this->payload('wrong'))->statusCode,
        );
        self::assertSame(415, $admin->requestApp('PUT', 'api/v1/appointments/' . $id, ['notes' => 'form'])->statusCode);

        foreach (
            [
                ['GET', 'api/v1/appointments_api_v1/store'],
                ['GET', 'api/v1/appointments_api_v1/update/' . $id],
                ['GET', 'api/v1/appointments_api_v1/destroy/' . $id],
                ['POST', 'api/v1/appointments_api_v1/update/' . $id],
                ['PUT', 'api/v1/appointments_api_v1/destroy/' . $id],
                ['DELETE', 'api/v1/appointments_api_v1/store'],
            ]
            as [$method, $path]
        ) {
            self::assertSame(405, $admin->requestApp($method, $path, $this->payload('alias'))->statusCode);
        }

        $ci = &get_instance();
        self::assertSame($before, $ci->db->get_where('appointments', ['id' => $id])->row_array());
    }

    public function testBasicAndBearerRejectPrimaryOverlapButAllowAdjacencyAndSelfUpdate(): void
    {
        $basic = $this->client(
            'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
        );
        $bearer = $this->client('Bearer ' . $this->credentials['token']);
        $base = $this->payloadAt('overlap-base', '+25 days', '09:00:00', '09:30:00');
        $created = $this->json($basic->requestJsonApp('POST', 'api/v1/appointments', $base), 201);
        $baseId = (int) $created['id'];

        $overlap = $this->payloadAt('overlap-denied', '+25 days', '09:15:00', '09:45:00');
        $before = $this->tableSnapshot();
        $denied = $bearer->requestJsonApp('POST', 'api/v1/appointments', $overlap);
        self::assertSame(409, $denied->statusCode);
        self::assertSame(['success' => false], json_decode($denied->body, true));
        self::assertSame($before, $this->tableSnapshot());

        $adjacent = $this->payloadAt('adjacent', '+25 days', '09:30:00', '10:00:00');
        $adjacentCreated = $this->json($bearer->requestJsonApp('POST', 'api/v1/appointments', $adjacent), 201);
        $adjacentId = (int) $adjacentCreated['id'];

        $adjacentBefore = $this->snapshot($adjacentId);
        $updateDenied = $basic->requestJsonApp('PUT', 'api/v1/appointments/' . $adjacentId, [
            'start' => $base['start'],
            'end' => $base['end'],
        ]);
        self::assertSame(409, $updateDenied->statusCode);
        self::assertSame(['success' => false], json_decode($updateDenied->body, true));
        self::assertSame($adjacentBefore, $this->snapshot($adjacentId));

        $selfUpdate = $this->json(
            $basic->requestJsonApp('PUT', 'api/v1/appointments/' . $baseId, ['notes' => $this->fixture->run . '_self']),
            200,
        );
        self::assertSame($baseId, (int) $selfUpdate['id']);

        self::assertSame(204, $basic->requestApp('DELETE', 'api/v1/appointments/' . $baseId)->statusCode);
        self::assertSame(204, $basic->requestApp('DELETE', 'api/v1/appointments/' . $adjacentId)->statusCode);
    }

    public function testParallelBasicAndBearerOverlapCreatesCommitAtMostOneAppointment(): void
    {
        $secondServer = new DefenseCycleHttpServer();
        $payload = $this->payloadAt('concurrent-overlap', '+26 days', '09:00:00', '09:30:00');
        $basicAuthorization =
            'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']);

        try {
            $responses = $this->parallelJsonRequests([
                [$this->server->baseUrl, $basicAuthorization, $payload],
                [$secondServer->baseUrl, 'Bearer ' . $this->credentials['token'], $payload],
            ]);
        } finally {
            $secondServer->close();
        }

        $statuses = array_column($responses, 'status');
        sort($statuses, SORT_NUMERIC);
        self::assertSame([201, 409], $statuses);

        $ci = &get_instance();
        $rows = $ci->db
            ->where('id_users_provider', $this->fixture->providerId)
            ->where('is_unavailability', false)
            ->where('id_parent_appointment IS NULL', null, false)
            ->where('start_datetime', $payload['start'])
            ->where('end_datetime', $payload['end'])
            ->get('appointments')
            ->result_array();
        self::assertCount(1, $rows);

        $winner = array_values(
            array_filter($responses, static fn(array $response): bool => $response['status'] === 201),
        );
        self::assertCount(1, $winner);
        $body = json_decode($winner[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((int) $rows[0]['id'], (int) ($body['id'] ?? 0));

        $basic = $this->client($basicAuthorization);
        self::assertSame(204, $basic->requestApp('DELETE', 'api/v1/appointments/' . (int) $rows[0]['id'])->statusCode);
    }

    public function testNonPaddedDatetimeCannotBypassPrimaryOverlap(): void
    {
        $basic = $this->client(
            'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
        );
        $bearer = $this->client('Bearer ' . $this->credentials['token']);
        $year = (int) date('Y') + 1;
        $base = $this->payload('canonical-overlap-base');
        $base['start'] = sprintf('%04d-01-02 09:00:00', $year);
        $base['end'] = sprintf('%04d-01-02 09:30:00', $year);
        $created = $this->json($basic->requestJsonApp('POST', 'api/v1/appointments', $base), 201);
        $baseId = (int) $created['id'];

        $overlap = $this->payload('canonical-overlap-denied');
        $overlap['start'] = $year . '-1-2 9:15:00';
        $overlap['end'] = $year . '-1-2 9:45:00';
        $before = $this->tableSnapshot();
        $denied = $bearer->requestJsonApp('POST', 'api/v1/appointments', $overlap);

        self::assertSame(409, $denied->statusCode);
        self::assertSame(['success' => false], json_decode($denied->body, true));
        self::assertSame($before, $this->tableSnapshot());

        $shortYear = $overlap;
        $shortYear['start'] = substr((string) $year, -2) . '-1-2 09:15:00';
        $shortYear['end'] = substr((string) $year, -2) . '-1-2 09:45:00';
        $shortYearDenied = $bearer->requestJsonApp('POST', 'api/v1/appointments', $shortYear);
        self::assertSame(400, $shortYearDenied->statusCode);
        self::assertSame(['success' => false], json_decode($shortYearDenied->body, true));
        self::assertSame($before, $this->tableSnapshot());

        self::assertSame(204, $basic->requestApp('DELETE', 'api/v1/appointments/' . $baseId)->statusCode);
    }

    public function testTimezoneGapDoesNotChangeOverlapComparison(): void
    {
        $ci = &get_instance();
        $timezone = $ci->db->get_where('settings', ['name' => 'default_timezone'])->row_array();
        self::assertIsArray($timezone);
        self::assertSame(
            'UTC',
            $ci->db->get_where('users', ['id' => $this->fixture->providerId])->row_array()['timezone'] ?? null,
        );
        self::assertTrue($ci->db->update('settings', ['value' => 'Europe/Berlin'], ['id' => $timezone['id']]));

        try {
            $basic = $this->client(
                'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            );
            $bearer = $this->client('Bearer ' . $this->credentials['token']);
            $base = $this->payload('dst-overlap-base');
            $base['start'] = '2027-03-28 00:00:00';
            $base['end'] = '2027-03-28 03:00:00';
            $created = $this->json($basic->requestJsonApp('POST', 'api/v1/appointments', $base), 201);
            $baseId = (int) $created['id'];

            $overlap = $this->payload('dst-overlap-denied');
            $overlap['start'] = '2027-03-28 02:00:00';
            $overlap['end'] = '2027-03-28 02:15:00';
            $before = $this->tableSnapshot();
            $denied = $bearer->requestJsonApp('POST', 'api/v1/appointments', $overlap);

            self::assertSame(409, $denied->statusCode);
            self::assertSame(['success' => false], json_decode($denied->body, true));
            self::assertSame($before, $this->tableSnapshot());
            self::assertSame(204, $basic->requestApp('DELETE', 'api/v1/appointments/' . $baseId)->statusCode);
        } finally {
            self::assertTrue($ci->db->update('settings', ['value' => $timezone['value']], ['id' => $timezone['id']]));
        }
    }

    public function testOverlapUpdateSeesCommitMadeWhileWaitingForProviderLock(): void
    {
        $db = get_instance()->db;
        $target = $this->fixture->appointment();
        $targetId = (int) $target['id'];
        $window = $this->payloadAt('wait-commit-overlap', '+28 days', '09:00:00', '09:30:00');
        self::assertTrue(
            $db->update(
                'appointments',
                ['start_datetime' => $window['start'], 'end_datetime' => $window['end']],
                ['id' => $targetId],
            ),
        );
        $before = $this->snapshot($targetId);
        $observer = null;
        $multi = null;
        $handle = null;
        $transactionOpen = false;
        $peerId = 0;

        try {
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
            $ownerId = mysqli_thread_id($db->conn_id);
            self::assertNotSame($ownerId, mysqli_thread_id($observer->conn_id));

            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $db->query('SELECT `id` FROM `' . $db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE', [
                    $this->fixture->providerId,
                ]),
            );
            $now = date('Y-m-d H:i:s');
            self::assertTrue(
                $db->insert('appointments', [
                    'book_datetime' => $now,
                    'start_datetime' => $window['start'],
                    'end_datetime' => $window['end'],
                    'location' => 'Synthetic lock owner',
                    'color' => '#ffffff',
                    'status' => 'Booked',
                    'notes' => $this->fixture->run . '_wait_commit_peer',
                    'hash' => bin2hex(random_bytes(32)),
                    'is_unavailability' => false,
                    'id_users_provider' => $this->fixture->providerId,
                    'id_users_customer' => $this->fixture->customerId,
                    'id_services' => $this->fixture->serviceId,
                    'create_datetime' => $now,
                    'update_datetime' => $now,
                ]),
            );
            $peerId = (int) $db->insert_id();

            $multi = curl_multi_init();
            $handle = curl_init($this->server->baseUrl . '/index.php/api/v1/appointments/' . $targetId);
            if ($multi === false || $handle === false) {
                throw new RuntimeException('Could not initialize the waiting Appointments API request.');
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode(
                    ['start' => $window['start'], 'end' => $window['end']],
                    JSON_THROW_ON_ERROR,
                ),
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 15,
            ]);
            self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));

            $waiting = false;
            $deadline = microtime(true) + 8.0;
            do {
                self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
                $waiting = $this->observesProviderLockWait($observer, $ownerId);
                if ($waiting || $running === 0) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (microtime(true) < $deadline);
            self::assertTrue($waiting, 'The API update must wait on the provider lock after its initial snapshot.');

            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi, $handle));
            self::assertSame(409, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            self::assertSame(['success' => false], json_decode((string) curl_multi_getcontent($handle), true));
            self::assertSame($before, $this->snapshot($targetId));
            self::assertSame($this->fixture->run . '_wait_commit_peer', $this->snapshot($peerId)['notes'] ?? null);
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            if ($multi instanceof CurlMultiHandle && $handle instanceof CurlHandle) {
                $this->drain($multi, $handle);
                curl_multi_remove_handle($multi, $handle);
            }
            if ($handle instanceof CurlHandle) {
                curl_close($handle);
            }
            if ($multi instanceof CurlMultiHandle) {
                curl_multi_close($multi);
            }
            if (is_object($observer) && isset($observer->conn_id)) {
                $observer->close();
            }
            if ($peerId > 0) {
                $db->delete('appointments', ['id' => $peerId]);
            }
        }
    }

    public function testInvalidAuthAndProtectedOrRedirectingPayloadsDoNotMutate(): void
    {
        $admin = $this->client(
            'Basic ' . base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
        );
        $provider = $this->client(
            'Basic ' . base64_encode($this->credentials['provider_username'] . ':' . $this->credentials['password']),
        );
        $target = $this->fixture->appointment();
        $targetId = (int) $target['id'];
        $ci = &get_instance();
        self::assertTrue(
            $ci->db->update(
                'appointments',
                [
                    'hash' => $this->fixture->run . '_hash',
                    'id_google_calendar' => $this->fixture->run . '_google',
                    'id_caldav_calendar' => $this->fixture->run . '_caldav',
                ],
                ['id' => $targetId],
            ),
        );
        $before = $this->snapshot($targetId);
        $beforeTable = $this->tableSnapshot();

        foreach (
            [
                $this->server->client(),
                $provider,
                $this->client('Basic ' . base64_encode($this->credentials['admin_username'] . ':wrong-password')),
                $this->client('Bearer wrong-token'),
            ]
            as $denied
        ) {
            $response = $denied->requestJsonApp('PUT', 'api/v1/appointments/' . $targetId, $this->payload('denied'));
            self::assertSame(401, $response->statusCode);
            self::assertSame($beforeTable, $this->tableSnapshot());
            self::assertSame($before, $this->snapshot($targetId));
        }

        $invalidPayloads = ['', '{bad}', '1', '[1]', '{}'];
        foreach ($invalidPayloads as $raw) {
            $beforeInvalid = $this->tableSnapshot();
            $response = $this->rawRequest('POST', 'api/v1/appointments', $raw, 'application/json');
            self::assertSame(400, $response['status'], 'Invalid JSON payload must be rejected.');
            self::assertSame($beforeInvalid, $this->tableSnapshot());
        }

        $beforeInvalid = $this->tableSnapshot();
        $formResponse = $admin->requestApp('POST', 'api/v1/appointments', $this->payload('form'));
        self::assertSame(415, $formResponse->statusCode);
        self::assertSame($beforeInvalid, $this->tableSnapshot());

        $wrongRoleResponse = $admin->requestJsonApp('PUT', 'api/v1/appointments/' . $targetId, [
            'customerId' => $this->fixture->providerId,
        ]);
        self::assertSame(400, $wrongRoleResponse->statusCode);
        self::assertSame($before, $this->snapshot($targetId));

        $invalidScalarResponse = $admin->requestJsonApp('PUT', 'api/v1/appointments/' . $targetId, [
            'end' => '2000-01-01 00:00:00',
        ]);
        self::assertSame(400, $invalidScalarResponse->statusCode);
        self::assertSame($before, $this->snapshot($targetId));

        foreach (['id', 'hash', 'book', 'googleCalendarId', 'caldavCalendarId', 'parentAppointmentId'] as $field) {
            $payload = $this->payload('protected');
            $payload[$field] = $field === 'id' ? $targetId + 1 : 'attacker-controlled';
            $beforeInsert = $this->tableSnapshot();
            $createResponse = $admin->requestJsonApp('POST', 'api/v1/appointments', $payload);
            self::assertSame(400, $createResponse->statusCode, 'POST ' . $field . ' must be rejected.');
            self::assertFalse(str_contains($createResponse->body, $this->fixture->run));
            self::assertSame($beforeInsert, $this->tableSnapshot());
            self::assertSame($before, $this->snapshot($targetId));
            $response = $admin->requestJsonApp('PUT', 'api/v1/appointments/' . $targetId, $payload);
            self::assertSame(400, $response->statusCode, $field . ' must be rejected.');
            self::assertFalse(str_contains($response->body, $this->fixture->run));
            self::assertSame($before, $this->snapshot($targetId));
        }

        $redirect = $this->payload('uri-target');
        $redirect['notes'] = $this->fixture->run . '_uri_target';
        $response = $admin->requestJsonApp('PUT', 'api/v1/appointments/' . $targetId, $redirect);
        self::assertSame(200, $response->statusCode);
        $updated = json_decode($response->body, true);
        self::assertSame($targetId, (int) ($updated['id'] ?? 0));
        $after = $this->snapshot($targetId);
        self::assertSame($redirect['notes'], $after['notes']);
        foreach (['hash', 'id_google_calendar', 'id_caldav_calendar', 'id_parent_appointment'] as $field) {
            self::assertSame($before[$field], $after[$field], $field . ' must survive PUT.');
        }

        $sentinelId = (int) $this->fixture->appointment()['id'];
        $sentinel = $this->snapshot($sentinelId);
        $deleteTarget = $this->fixture->appointment();
        $deleteId = (int) $deleteTarget['id'];
        self::assertSame(204, $admin->requestApp('DELETE', 'api/v1/appointments/' . $deleteId)->statusCode);
        self::assertSame($sentinel, $this->snapshot($sentinelId));
    }

    private function payload(string $suffix): array
    {
        return [
            'start' => date('Y-m-d 11:00:00', strtotime('+16 days')),
            'end' => date('Y-m-d 11:30:00', strtotime('+16 days')),
            'location' => 'Synthetic',
            'color' => '#ffffff',
            'status' => 'Booked',
            'notes' => $this->fixture->run . '_' . $suffix,
            'customerId' => $this->fixture->customerId,
            'providerId' => $this->fixture->providerId,
            'serviceId' => $this->fixture->serviceId,
        ];
    }

    private function payloadAt(string $suffix, string $day, string $start, string $end): array
    {
        $payload = $this->payload($suffix);
        $timestamp = strtotime($day);
        $payload['start'] = date('Y-m-d ', $timestamp) . $start;
        $payload['end'] = date('Y-m-d ', $timestamp) . $end;
        return $payload;
    }

    /**
     * @param list<array{0:string,1:string,2:array<string,mixed>}> $requests
     * @return list<array{status:int,body:string}>
     */
    private function parallelJsonRequests(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($requests as $index => [$baseUrl, $authorization, $payload]) {
            $handle = curl_init(rtrim($baseUrl, '/') . '/index.php/api/v1/appointments');
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: ' . $authorization,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$index] = $handle;
        }

        try {
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK) {
                    throw new RuntimeException('Parallel HTTP execution failed.');
                }
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);

            $responses = [];
            foreach ($handles as $handle) {
                $body = curl_multi_getcontent($handle);
                if ($body === false) {
                    throw new RuntimeException('Parallel HTTP response was unavailable.');
                }
                $responses[] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => $body,
                ];
            }
            return $responses;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }

    private function observesProviderLockWait(object $observer, int $ownerId): bool
    {
        $result = $observer->query(
            'SELECT requesting_lock.OBJECT_SCHEMA, requesting_lock.OBJECT_NAME, ' .
                'COALESCE(requesting_statement.SQL_TEXT, requesting_thread.PROCESSLIST_INFO) AS REQUESTING_SQL ' .
                'FROM performance_schema.data_lock_waits waits ' .
                'JOIN performance_schema.data_locks requesting_lock ' .
                'ON requesting_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID ' .
                'JOIN performance_schema.threads requesting_thread ' .
                'ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID ' .
                'JOIN performance_schema.threads blocking_thread ' .
                'ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID ' .
                'LEFT JOIN performance_schema.events_statements_current requesting_statement ' .
                'ON requesting_statement.THREAD_ID = requesting_thread.THREAD_ID ' .
                'WHERE blocking_thread.PROCESSLIST_ID = ' .
                (int) $ownerId,
        );
        self::assertNotFalse($result, 'Independent provider-lock instrumentation must be available.');
        $usersTable = get_instance()->db->dbprefix('users');
        $normalize = static fn(string $sql): string => (string) preg_replace('/\s+/', ' ', strtoupper(trim($sql)));
        foreach ($result->result_array() as $wait) {
            $sql = $normalize((string) ($wait['REQUESTING_SQL'] ?? ''));
            if (
                ($wait['OBJECT_SCHEMA'] ?? null) === Config::DB_NAME &&
                ($wait['OBJECT_NAME'] ?? null) === $usersTable &&
                str_contains($sql, 'SELECT `ID`, `ID_ROLES` FROM `' . strtoupper($usersTable) . '`') &&
                str_contains($sql, 'ORDER BY `ID` ASC FOR UPDATE')
            ) {
                return true;
            }
        }
        return false;
    }

    private function drain(CurlMultiHandle $multi, CurlHandle $handle): bool
    {
        $deadline = microtime(true) + 8.0;
        do {
            if (curl_multi_exec($multi, $running) !== CURLM_OK) {
                return false;
            }
            if ($running === 0) {
                return curl_errno($handle) === CURLE_OK;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function client(string $authorization): GateHttpClient
    {
        return new GateHttpClient($this->server->baseUrl, additionalHeaders: ['Authorization' => $authorization]);
    }

    private function assertWritableFields(array $expected, array $actual): void
    {
        foreach (
            ['start', 'end', 'location', 'color', 'status', 'notes', 'customerId', 'providerId', 'serviceId']
            as $field
        ) {
            self::assertSame($expected[$field], $actual[$field] ?? null, $field . ' must persist.');
        }
    }

    private function snapshot(int $id): array
    {
        $ci = &get_instance();
        return $ci->db->get_where('appointments', ['id' => $id])->row_array();
    }

    private function tableSnapshot(): array
    {
        $ci = &get_instance();
        return $ci->db->order_by('id')->get('appointments')->result_array();
    }

    /** @return array{status:int,body:string} */
    private function rawRequest(string $method, string $path, string $body, string $contentType): array
    {
        $url = rtrim($this->server->baseUrl, '/') . '/index.php/' . ltrim($path, '/');
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' .
                base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
                'Content-Type: ' . $contentType,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $responseBody = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => $responseBody];
    }

    private function json(object $response, int $status): array
    {
        self::assertSame($status, $response->statusCode);
        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    }
}

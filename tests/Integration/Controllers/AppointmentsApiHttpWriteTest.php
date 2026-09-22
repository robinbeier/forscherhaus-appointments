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

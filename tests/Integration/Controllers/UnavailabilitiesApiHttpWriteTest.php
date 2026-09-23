<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP regression coverage for the Unavailabilities API URI/body ID boundary. */
final class UnavailabilitiesApiHttpWriteTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $serviceId = 0;
    private int $unavailabilityA = 0;
    private int $unavailabilityB = 0;
    private int $ordinaryAppointment = 0;
    /** @var list<int> */
    private array $ownedAppointmentIds = [];
    /** @var array<string, bool> */
    private array $ownedPostMarkers = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->seedOwnedRecords();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->cleanupOwnedRecords();
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
                $this->cleanupOwnedRecords();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testPutBodyIdCannotRedirectBetweenManualUnavailabilities(): void
    {
        $admin = $this->adminClient();
        $before = ['a' => $this->snapshot($this->unavailabilityA), 'b' => $this->snapshot($this->unavailabilityB)];
        $response = $admin->requestJsonApp(
            'PUT',
            'api/v1/unavailabilities/' . $this->unavailabilityA,
            $this->payload($this->unavailabilityB, 'redirect'),
        );

        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertSame($before['a'], $this->snapshot($this->unavailabilityA));
        self::assertSame($before['b'], $this->snapshot($this->unavailabilityB));
    }

    public function testPutBodyOrdinaryAppointmentIdRejectsWithoutMutation(): void
    {
        $admin = $this->adminClient();
        $before = [
            'a' => $this->snapshot($this->unavailabilityA),
            'ordinary' => $this->snapshot($this->ordinaryAppointment),
            'buffers' => $this->bufferSnapshot($this->ordinaryAppointment),
        ];
        $response = $admin->requestJsonApp(
            'PUT',
            'api/v1/unavailabilities/' . $this->unavailabilityA,
            $this->payload($this->ordinaryAppointment, 'ordinary-redirect'),
        );

        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertSame($before['a'], $this->snapshot($this->unavailabilityA));
        self::assertSame($before['ordinary'], $this->snapshot($this->ordinaryAppointment));
        self::assertSame($before['buffers'], $this->bufferSnapshot($this->ordinaryAppointment));
    }

    public function testPutWithoutIdAndMatchingIdUpdatesOnlyUriUnavailability(): void
    {
        $admin = $this->adminClient();
        $beforeB = $this->snapshot($this->unavailabilityB);
        $withoutId = $this->payload(null, 'without-id');
        $response = $admin->requestJsonApp('PUT', 'api/v1/unavailabilities/' . $this->unavailabilityA, $withoutId);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($this->unavailabilityA, (int) ($this->jsonBody($response)['id'] ?? 0));
        self::assertSame($withoutId['start'], $this->snapshot($this->unavailabilityA)['row']['start_datetime']);
        self::assertSame($beforeB, $this->snapshot($this->unavailabilityB));

        $matching = $this->payload($this->unavailabilityA, 'matching-id');
        $response = $admin->requestJsonApp('PUT', 'api/v1/unavailabilities/' . $this->unavailabilityA, $matching);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($this->unavailabilityA, (int) ($this->jsonBody($response)['id'] ?? 0));
        self::assertSame($matching['start'], $this->snapshot($this->unavailabilityA)['row']['start_datetime']);
        self::assertSame($beforeB, $this->snapshot($this->unavailabilityB));
    }

    public function testPostCreatesAndDeleteRemovesOnlyOwnedManualUnavailability(): void
    {
        $admin = $this->adminClient();
        $beforeA = $this->snapshot($this->unavailabilityA);
        $beforeB = $this->snapshot($this->unavailabilityB);
        $marker = $this->registerPostMarker('created');
        $response = $admin->requestJsonApp('POST', 'api/v1/unavailabilities', $this->payload(null, 'created'));
        self::assertSame(201, $response->statusCode, $response->body);
        $createdId = (int) ($this->jsonBody($response)['id'] ?? 0);
        self::assertGreaterThan(0, $createdId);
        $this->registerCreatedPostRow($marker, $createdId);
        self::assertSame(1, (int) ($this->snapshot($createdId)['row']['is_unavailability'] ?? 0));

        $response = $admin->requestApp('DELETE', 'api/v1/unavailabilities/' . $createdId);
        self::assertSame(204, $response->statusCode, $response->body);
        self::assertSame([], $this->snapshot($createdId)['row']);
        self::assertSame($beforeA, $this->snapshot($this->unavailabilityA));
        self::assertSame($beforeB, $this->snapshot($this->unavailabilityB));
    }

    public function testPostBodyIdCannotOverwriteExistingUnavailability(): void
    {
        $admin = $this->adminClient();
        $beforeB = $this->snapshot($this->unavailabilityB);
        $marker = $this->registerPostMarker('forged-create-id');
        $response = $admin->requestJsonApp(
            'POST',
            'api/v1/unavailabilities',
            $this->payload($this->unavailabilityB, 'forged-create-id'),
        );
        self::assertSame(201, $response->statusCode, $response->body);
        $createdId = (int) ($this->jsonBody($response)['id'] ?? 0);
        self::assertGreaterThan(0, $createdId);
        $this->registerCreatedPostRow($marker, $createdId);
        self::assertNotSame($this->unavailabilityB, $createdId);
        self::assertSame(1, (int) ($this->snapshot($createdId)['row']['is_unavailability'] ?? 0));
        self::assertSame($beforeB, $this->snapshot($this->unavailabilityB));
    }

    public function testPutRejectsStringBodyIdAndGeneratedBufferWithoutMutation(): void
    {
        $admin = $this->adminClient();
        $beforeA = $this->snapshot($this->unavailabilityA);
        $stringId = $this->payload(null, 'string-id');
        $stringId['id'] = (string) $this->unavailabilityA;
        $response = $admin->requestJsonApp('PUT', 'api/v1/unavailabilities/' . $this->unavailabilityA, $stringId);
        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($beforeA, $this->snapshot($this->unavailabilityA));

        $buffers = $this->bufferSnapshot($this->ordinaryAppointment);
        self::assertNotEmpty($buffers);
        $bufferId = (int) $buffers[0]['id'];
        $response = $admin->requestJsonApp(
            'PUT',
            'api/v1/unavailabilities/' . $bufferId,
            $this->payload($bufferId, 'buffer-put'),
        );
        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertSame($buffers, $this->bufferSnapshot($this->ordinaryAppointment));
        self::assertSame($beforeA, $this->snapshot($this->unavailabilityA));
    }

    public function testDeleteBufferAndOrdinaryAppointmentIdsRejectWithoutMutation(): void
    {
        $admin = $this->adminClient();
        $bufferIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $this->bufferSnapshot($this->ordinaryAppointment),
        );
        self::assertNotEmpty($bufferIds);
        foreach (array_merge($bufferIds, [$this->ordinaryAppointment]) as $id) {
            $before = $this->snapshot($id);
            $response = $admin->requestApp('DELETE', 'api/v1/unavailabilities/' . $id);
            self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
            self::assertSame($before, $this->snapshot($id));
        }
        self::assertSame(
            $bufferIds,
            array_map(
                static fn(array $row): int => (int) $row['id'],
                $this->bufferSnapshot($this->ordinaryAppointment),
            ),
        );
    }

    public function testUnauthenticatedWritesReturn401WithoutMutation(): void
    {
        $client = $this->server->client();
        $postMarker = $this->registerPostMarker('unauth-post');
        $before = [
            'a' => $this->snapshot($this->unavailabilityA),
            'b' => $this->snapshot($this->unavailabilityB),
            'ordinary' => $this->snapshot($this->ordinaryAppointment),
            'buffers' => $this->bufferSnapshot($this->ordinaryAppointment),
        ];
        self::assertSame(
            401,
            $client->requestJsonApp('POST', 'api/v1/unavailabilities', $this->payload(null, 'unauth-post'))->statusCode,
        );
        self::assertSame(
            [],
            get_instance()
                ->db->get_where('appointments', [
                    'notes' => $postMarker,
                    'is_unavailability' => 1,
                    'id_users_provider' => $this->fixture->providerId,
                ])
                ->result_array(),
        );
        self::assertSame(
            401,
            $client->requestJsonApp(
                'PUT',
                'api/v1/unavailabilities/' . $this->unavailabilityA,
                $this->payload(null, 'unauth-put'),
            )->statusCode,
        );
        self::assertSame(
            401,
            $client->requestApp('DELETE', 'api/v1/unavailabilities/' . $this->unavailabilityA)->statusCode,
        );
        self::assertSame($before['a'], $this->snapshot($this->unavailabilityA));
        self::assertSame($before['b'], $this->snapshot($this->unavailabilityB));
        self::assertSame($before['ordinary'], $this->snapshot($this->ordinaryAppointment));
        self::assertSame($before['buffers'], $this->bufferSnapshot($this->ordinaryAppointment));
    }

    public function testWrongMethodAliasesCannotReachUnavailabilityMutation(): void
    {
        $admin = $this->adminClient();
        $before = [
            'a' => $this->snapshot($this->unavailabilityA),
            'b' => $this->snapshot($this->unavailabilityB),
            'ordinary' => $this->snapshot($this->ordinaryAppointment),
            'buffers' => $this->bufferSnapshot($this->ordinaryAppointment),
        ];
        foreach (
            [
                ['GET', 'api/v1/unavailabilities_api_v1/store', 'POST'],
                ['GET', 'api/v1/unavailabilities_api_v1/update/' . $this->unavailabilityA, 'PUT'],
                ['GET', 'api/v1/unavailabilities_api_v1/destroy/' . $this->unavailabilityA, 'DELETE'],
                ['POST', 'api/v1/unavailabilities_api_v1/update/' . $this->unavailabilityA, 'PUT'],
                ['PUT', 'api/v1/unavailabilities_api_v1/destroy/' . $this->unavailabilityA, 'DELETE'],
                ['DELETE', 'api/v1/unavailabilities_api_v1/store', 'POST'],
            ]
            as [$method, $path, $expected]
        ) {
            $response = $admin->requestApp($method, $path, $this->payload(null, 'wrong-method'));
            self::assertSame(405, $response->statusCode, $response->body);
            self::assertSame($expected, $response->header('allow'));
        }
        self::assertSame($before['a'], $this->snapshot($this->unavailabilityA));
        self::assertSame($before['b'], $this->snapshot($this->unavailabilityB));
        self::assertSame($before['ordinary'], $this->snapshot($this->ordinaryAppointment));
        self::assertSame($before['buffers'], $this->bufferSnapshot($this->ordinaryAppointment));
    }

    private function seedOwnedRecords(): void
    {
        $db = get_instance()->db;
        $f = $this->fixture;
        get_instance()->load->model('appointments_model');
        get_instance()->load->model('unavailabilities_model');
        $db->insert('services', [
            'name' => $f->run . '_buffer_service',
            'duration' => 30,
            'price' => 0,
            'currency' => 'EUR',
            'description' => $f->run . '_buffer_service',
            'location' => 'Synthetic',
            'is_private' => 0,
            'attendants_number' => 1,
            'buffer_before' => 10,
            'buffer_after' => 10,
        ]);
        $this->serviceId = (int) $db->insert_id();
        $db->insert('services_providers', ['id_users' => $f->providerId, 'id_services' => $this->serviceId]);

        $model = get_instance()->unavailabilities_model;
        foreach ([['A', '+12 days', '09:00:00'], ['B', '+12 days', '12:00:00']] as [$marker, $day, $time]) {
            $id = (int) $model->save([
                'start_datetime' => date('Y-m-d ' . $time, strtotime($day)),
                'end_datetime' => date('Y-m-d H:i:s', strtotime($day . ' ' . $time . ' +30 minutes')),
                'id_users_provider' => $f->providerId,
                'notes' => $f->run . '_manual_' . $marker,
            ]);
            $this->ownedAppointmentIds[] = $id;
            $this->{$marker === 'A' ? 'unavailabilityA' : 'unavailabilityB'} = $id;
        }
        $appointment = get_instance()->appointments_model->save([
            'start_datetime' => date('Y-m-d 15:00:00', strtotime('+20 days')),
            'end_datetime' => date('Y-m-d 15:30:00', strtotime('+20 days')),
            'notes' => $f->run . '_ordinary',
            'is_unavailability' => false,
            'id_users_provider' => $f->providerId,
            'id_users_customer' => $f->customerId,
            'id_services' => $this->serviceId,
        ]);
        $this->ordinaryAppointment = (int) $appointment;
        $this->ownedAppointmentIds[] = $this->ordinaryAppointment;
    }

    private function cleanupOwnedRecords(): void
    {
        $db = get_instance()->db;
        foreach (array_keys($this->ownedPostMarkers) as $marker) {
            $rows = $db
                ->get_where('appointments', [
                    'notes' => $marker,
                    'is_unavailability' => 1,
                    'id_users_provider' => $this->fixture?->providerId,
                ])
                ->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Owned POST marker matched multiple appointments.');
            }
            foreach ($rows as $row) {
                if (!empty($row['id_parent_appointment'])) {
                    throw new RuntimeException('Owned POST marker unexpectedly became a buffer block.');
                }
                $this->ownedAppointmentIds[] = (int) $row['id'];
            }
        }
        $this->ownedAppointmentIds = array_values(array_unique($this->ownedAppointmentIds));
        foreach ($this->ownedAppointmentIds as $id) {
            $row = $db->get_where('appointments', ['id' => $id])->row_array();
            if ($row && (int) ($row['id_users_provider'] ?? 0) !== ($this->fixture?->providerId ?? 0)) {
                throw new RuntimeException('Owned appointment provider identity changed outside this fixture.');
            }
            $db->delete('reschedule_authorities', ['appointment_id' => $id]);
            $db->delete('appointments', ['id_parent_appointment' => $id]);
            $db->delete('appointments', ['id' => $id]);
        }
        if ($this->serviceId > 0) {
            $db->delete('services_providers', ['id_services' => $this->serviceId]);
            $db->delete('services', [
                'id' => $this->serviceId,
                'description' => $this->fixture?->run . '_buffer_service',
            ]);
        }
        $this->ownedAppointmentIds = [];
        $this->ownedPostMarkers = [];
        $this->serviceId = 0;
    }

    private function adminClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            ],
        );
    }

    private function payload(?int $id, string $suffix): array
    {
        $payload = [
            'start' => date('Y-m-d 16:00:00', strtotime('+12 days')),
            'end' => date('Y-m-d 16:30:00', strtotime('+12 days')),
            'providerId' => $this->fixture->providerId,
            'notes' => $this->fixture->run . '_' . $suffix,
        ];
        return $id === null ? $payload : ['id' => $id] + $payload;
    }

    private function registerPostMarker(string $suffix): string
    {
        $marker = $this->fixture->run . '_' . $suffix;
        if (isset($this->ownedPostMarkers[$marker])) {
            throw new RuntimeException('Duplicate owned POST marker.');
        }
        if (
            get_instance()
                ->db->get_where('appointments', ['notes' => $marker])
                ->num_rows() !== 0
        ) {
            throw new RuntimeException('Owned POST marker already exists.');
        }
        $this->ownedPostMarkers[$marker] = true;
        return $marker;
    }

    private function registerCreatedPostRow(string $marker, int $id): void
    {
        $row = get_instance()
            ->db->get_where('appointments', [
                'id' => $id,
                'notes' => $marker,
                'is_unavailability' => 1,
                'id_users_provider' => $this->fixture->providerId,
            ])
            ->row_array();
        self::assertNotSame([], $row);
        self::assertEmpty($row['id_parent_appointment'] ?? null);
        $this->ownedAppointmentIds[] = $id;
    }

    private function snapshot(int $id): array
    {
        return [
            'row' =>
                get_instance()
                    ->db->get_where('appointments', ['id' => $id])
                    ->row_array() ?? [],
        ];
    }

    private function bufferSnapshot(int $parentId): array
    {
        $rows = get_instance()
            ->db->get_where('appointments', ['id_parent_appointment' => $parentId])
            ->result_array();
        usort($rows, static fn(array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
        return $rows;
    }

    private function jsonBody(GateHttpResponse $response): array
    {
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        return $body;
    }
}

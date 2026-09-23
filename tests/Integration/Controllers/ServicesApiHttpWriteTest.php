<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded local HTTP coverage for the Services API v1 URI/body ID boundary. */
final class ServicesApiHttpWriteTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?int $bodyServiceId = null;
    private string $bodyServiceDescription = '';
    /** @var array<int, array{name: string, description: string}> */
    private array $createdServiceIdentities = [];
    /** @var array{name: string, description: string}|null */
    private ?array $pendingCreatedServiceIdentity = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->server = new DefenseCycleHttpServer();
            $this->createBodyTargetService();
        } catch (Throwable $error) {
            $this->cleanupCreatedServices();
            $this->cleanupBodyTargetService();
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
                $this->cleanupCreatedServices();
            } finally {
                try {
                    $this->cleanupBodyTargetService();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
        }
    }

    public function testPutBodyIdCannotRedirectUpdateToAnotherService(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $serviceAId = $f->serviceId;
        $serviceBId = $this->bodyServiceId;
        self::assertNotNull($serviceBId);
        $serviceBId = (int) $serviceBId;
        self::assertNotSame($serviceAId, $serviceBId);

        $before = [
            'a' => $this->serviceSnapshot($serviceAId),
            'b' => $this->serviceSnapshot($serviceBId),
        ];
        $payload = $this->servicePayload($serviceBId);

        $response = $admin->requestJsonApp('PUT', 'api/v1/services/' . $serviceAId, $payload);

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($before['a'], $this->serviceSnapshot($serviceAId));
        self::assertSame($before['b'], $this->serviceSnapshot($serviceBId));
    }

    public function testPutWithoutIdAndWithMatchingIdPersistOnlyUriService(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $serviceAId = $f->serviceId;
        $serviceBId = (int) $this->bodyServiceId;
        $beforeB = $this->serviceSnapshot($serviceBId);

        $withoutId = $this->servicePayload(null, 'service_a_without_id');
        $response = $admin->requestJsonApp('PUT', 'api/v1/services/' . $serviceAId, $withoutId);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($serviceAId, (int) ($this->jsonBody($response)['id'] ?? 0));
        self::assertSame($withoutId['name'], $this->serviceSnapshot($serviceAId)['service']['name']);
        self::assertSame($beforeB, $this->serviceSnapshot($serviceBId));

        $withMatchingId = $this->servicePayload($serviceAId, 'service_a_matching_id');
        $response = $admin->requestJsonApp('PUT', 'api/v1/services/' . $serviceAId, $withMatchingId);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($serviceAId, (int) ($this->jsonBody($response)['id'] ?? 0));
        self::assertSame($withMatchingId['name'], $this->serviceSnapshot($serviceAId)['service']['name']);
        self::assertSame($beforeB, $this->serviceSnapshot($serviceBId));
    }

    public function testUnauthenticatedServiceWritesReturn401WithoutMutation(): void
    {
        $f = $this->fixture;
        $unauthenticated = $this->server->client();
        $serviceAId = $f->serviceId;
        $serviceBId = (int) $this->bodyServiceId;
        $before = [
            'a' => $this->serviceSnapshot($serviceAId),
            'b' => $this->serviceSnapshot($serviceBId),
        ];

        $post = $unauthenticated->requestJsonApp('POST', 'api/v1/services', $this->servicePayload(null, 'unauth_post'));
        self::assertSame(401, $post->statusCode);
        self::assertNotNull($post->header('www-authenticate'));
        $put = $unauthenticated->requestJsonApp(
            'PUT',
            'api/v1/services/' . $serviceAId,
            $this->servicePayload(null, 'unauth_put'),
        );
        self::assertSame(401, $put->statusCode);
        self::assertNotNull($put->header('www-authenticate'));
        $delete = $unauthenticated->requestApp('DELETE', 'api/v1/services/' . $serviceAId);
        self::assertSame(401, $delete->statusCode);
        self::assertNotNull($delete->header('www-authenticate'));

        self::assertSame($before['a'], $this->serviceSnapshot($serviceAId));
        self::assertSame($before['b'], $this->serviceSnapshot($serviceBId));
    }

    public function testAuthorizedPostCreatesAndDeleteRemovesExactOwnedService(): void
    {
        $admin = $this->adminClient();
        $payload = $this->servicePayload(null, 'service_created');
        $identity = [
            'name' => $payload['name'],
            'description' => $payload['description'],
        ];
        $this->pendingCreatedServiceIdentity = $identity;
        $created = $admin->requestJsonApp('POST', 'api/v1/services', $payload);
        $data = $this->jsonBody($created);
        $serviceId = (int) ($data['id'] ?? 0);
        self::assertGreaterThan(0, $serviceId);
        $createdRows = get_instance()->db->get_where('services', $identity)->result_array();
        self::assertCount(1, $createdRows);
        self::assertSame((int) $createdRows[0]['id'], $serviceId);
        $this->createdServiceIdentities[$serviceId] = $identity;
        $this->pendingCreatedServiceIdentity = null;
        self::assertSame(201, $created->statusCode, $created->body);
        self::assertSame($payload['name'], $this->serviceSnapshot($serviceId)['service']['name']);

        $deleted = $admin->requestApp('DELETE', 'api/v1/services/' . $serviceId);
        self::assertSame(204, $deleted->statusCode, $deleted->body);
        self::assertSame([], $this->serviceSnapshot($serviceId)['service']);
        unset($this->createdServiceIdentities[$serviceId]);
    }

    public function testWrongVerbAliasesCannotMutateOwnedServices(): void
    {
        $admin = $this->adminClient();
        $serviceAId = $this->fixture->serviceId;
        $serviceBId = (int) $this->bodyServiceId;
        $beforeA = $this->serviceSnapshot($serviceAId);
        $beforeB = $this->serviceSnapshot($serviceBId);

        foreach (
            [
                ['GET', 'api/v1/services_api_v1/store', 'POST'],
                ['GET', 'api/v1/services_api_v1/update/' . $serviceAId, 'PUT'],
                ['GET', 'api/v1/services_api_v1/destroy/' . $serviceBId, 'DELETE'],
                ['POST', 'api/v1/services_api_v1/update/' . $serviceAId, 'PUT'],
                ['PUT', 'api/v1/services_api_v1/destroy/' . $serviceBId, 'DELETE'],
                ['DELETE', 'api/v1/services_api_v1/store', 'POST'],
            ]
            as [$method, $path, $expected]
        ) {
            $response = $admin->requestApp($method, $path, $this->servicePayload(null, 'wrong_verb'));
            self::assertSame(405, $response->statusCode, $response->body);
            self::assertSame($expected, $response->header('allow'));
        }

        self::assertSame($beforeA, $this->serviceSnapshot($serviceAId));
        self::assertSame($beforeB, $this->serviceSnapshot($serviceBId));
    }

    public function testPutRejectsInvalidDurationWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $serviceAId = $f->serviceId;
        $serviceBId = (int) $this->bodyServiceId;

        foreach ([0, null, 5.5, 2147483648, '30.0000000000000001'] as $duration) {
            $before = [
                'a' => $this->serviceSnapshot($serviceAId),
                'b' => $this->serviceSnapshot($serviceBId),
            ];
            $payload = $this->servicePayload($serviceAId, 'invalid_duration');
            $payload['duration'] = $duration;
            $response = $admin->requestJsonApp('PUT', 'api/v1/services/' . $serviceAId, $payload);

            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame($before['a'], $this->serviceSnapshot($serviceAId));
            self::assertSame($before['b'], $this->serviceSnapshot($serviceBId));
        }
    }

    public function testPutRejectsFractionalAttendantsNumberWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $serviceAId = $f->serviceId;
        $serviceBId = (int) $this->bodyServiceId;
        $before = [
            'a' => $this->serviceSnapshot($serviceAId),
            'b' => $this->serviceSnapshot($serviceBId),
        ];
        foreach ([1.9, '1.0000000000000001'] as $attendantsNumber) {
            $payload = $this->servicePayload($serviceAId, 'fractional_attendants');
            $payload['attendantsNumber'] = $attendantsNumber;
            $response = $admin->requestJsonApp('PUT', 'api/v1/services/' . $serviceAId, $payload);

            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame($before['a'], $this->serviceSnapshot($serviceAId));
            self::assertSame($before['b'], $this->serviceSnapshot($serviceBId));
        }
    }

    public function testPutRejectsPrecisionHiddenRawJsonNumbersWithoutMutation(): void
    {
        $serviceAId = $this->fixture->serviceId;
        $serviceBId = (int) $this->bodyServiceId;
        $beforeA = $this->serviceSnapshot($serviceAId);
        $beforeB = $this->serviceSnapshot($serviceBId);
        $admin = $this->adminClient();

        foreach (
            [
                ['"duration":30', '"duration":30.0000000000000001'],
                ['"attendantsNumber":1', '"attendantsNumber":1.0000000000000001'],
            ]
            as [$original, $fractional]
        ) {
            $body = json_encode($this->servicePayload($serviceAId, 'raw_fraction'), JSON_THROW_ON_ERROR);
            self::assertStringContainsString($original, $body);
            $body = str_replace($original, $fractional, $body);

            $response = $admin->requestRawApp('PUT', 'api/v1/services/' . $serviceAId, $body, 'application/json');

            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame($beforeA, $this->serviceSnapshot($serviceAId));
            self::assertSame($beforeB, $this->serviceSnapshot($serviceBId));
        }
    }

    private function createBodyTargetService(): void
    {
        $f = $this->fixture;
        $db = get_instance()->db;
        $this->bodyServiceDescription = $f->run . '_service_b';
        $db->insert('services', [
            'name' => $f->run . '_service_b',
            'duration' => 30,
            'price' => 0,
            'currency' => 'EUR',
            'description' => $this->bodyServiceDescription,
            'location' => 'Synthetic B',
            'is_private' => 0,
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ]);
        $this->bodyServiceId = (int) $db->insert_id();
        if ($this->bodyServiceId < 1) {
            throw new RuntimeException('Synthetic body-target service was not created.');
        }
    }

    /** @return array<string, mixed> */
    private function servicePayload(?int $bodyId, string $suffix = 'service_a_updated'): array
    {
        $f = $this->fixture;
        $payload = [
            'name' => $f->run . '_' . $suffix,
            'duration' => 30,
            'price' => 0,
            'currency' => 'EUR',
            // Keep the fixture-owned A description unchanged so DefenseCycleFixtures can remove it.
            'description' => $f->run,
            'location' => 'Synthetic ' . $suffix,
            'color' => null,
            'availabilitiesType' => null,
            'attendantsNumber' => 1,
            'bufferBefore' => 0,
            'bufferAfter' => 0,
            'isPrivate' => false,
            'serviceCategoryId' => null,
        ];
        if ($bodyId !== null) {
            $payload = ['id' => $bodyId] + $payload;
        }
        return $payload;
    }

    /** @return array<string, mixed> */
    private function jsonBody(GateHttpResponse $response): array
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @return array{service: array<string, mixed>, providers: list<array<string, mixed>>, appointments: list<array<string, mixed>>} */
    private function serviceSnapshot(int $id): array
    {
        $db = get_instance()->db;
        return [
            'service' => $db->get_where('services', ['id' => $id])->row_array() ?? [],
            'providers' => $db->get_where('services_providers', ['id_services' => $id])->result_array(),
            'appointments' => $db->get_where('appointments', ['id_services' => $id])->result_array(),
        ];
    }

    private function cleanupBodyTargetService(): void
    {
        if ($this->bodyServiceId === null || !isset($this->fixture)) {
            return;
        }

        $db = get_instance()->db;
        $row = $db->get_where('services', ['id' => $this->bodyServiceId])->row_array();
        if (!$row) {
            $this->bodyServiceId = null;
            return;
        }
        $isOriginalBodyTarget = ($row['description'] ?? null) === $this->bodyServiceDescription;
        $isRedirectedBodyTarget =
            ($row['name'] ?? null) === $this->fixture->run . '_service_a_updated' &&
            ($row['description'] ?? null) === $this->fixture->run;
        if (!$isOriginalBodyTarget && !$isRedirectedBodyTarget) {
            throw new RuntimeException('Body-target service identity changed outside the owned fixture.');
        }
        if (
            $db->get_where('services_providers', ['id_services' => $this->bodyServiceId])->num_rows() !== 0 ||
            $db->get_where('appointments', ['id_services' => $this->bodyServiceId])->num_rows() !== 0
        ) {
            throw new RuntimeException('Unexpected body-target relationships; dispose the owned stack.');
        }
        $db->delete('services', [
            'id' => $this->bodyServiceId,
            'name' => $row['name'],
            'description' => $row['description'],
        ]);
        if ($db->get_where('services', ['id' => $this->bodyServiceId])->num_rows() !== 0) {
            throw new RuntimeException('Body-target service cleanup was not confirmed.');
        }
        $this->bodyServiceId = null;
    }

    private function cleanupCreatedServices(): void
    {
        if ($this->pendingCreatedServiceIdentity !== null) {
            $identity = $this->pendingCreatedServiceIdentity;
            $rows = get_instance()->db->get_where('services', $identity)->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Pending service cleanup matched multiple rows.');
            }
            if ($rows !== []) {
                $this->createdServiceIdentities[(int) $rows[0]['id']] = $identity;
            }
            $this->pendingCreatedServiceIdentity = null;
        }

        if ($this->createdServiceIdentities === []) {
            return;
        }
        $db = get_instance()->db;
        foreach ($this->createdServiceIdentities as $id => $identity) {
            $row = $db->get_where('services', ['id' => $id])->row_array();
            if (!$row) {
                unset($this->createdServiceIdentities[$id]);
                continue;
            }
            if (
                ($row['name'] ?? null) !== $identity['name'] ||
                ($row['description'] ?? null) !== $identity['description']
            ) {
                throw new RuntimeException('Created service identity changed outside the owned fixture.');
            }
            if ($db->get_where('services_providers', ['id_services' => $id])->num_rows() !== 0) {
                throw new RuntimeException('Created service has an unexpected provider relation.');
            }
            if ($db->get_where('appointments', ['id_services' => $id])->num_rows() !== 0) {
                throw new RuntimeException('Created service has an unexpected appointment relation.');
            }
            $db->delete('services', ['id' => $id]);
            if ($db->get_where('services', ['id' => $id])->num_rows() !== 0) {
                throw new RuntimeException('Created service cleanup was not confirmed.');
            }
            unset($this->createdServiceIdentities[$id]);
        }
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
}

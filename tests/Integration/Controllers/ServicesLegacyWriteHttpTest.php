<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Services::store/update/destroy surface. */
final class ServicesLegacyWriteHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $actorRoleBefore = 0;
    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->actorRoleBefore = (int) ($this->fixture->row('users', $this->fixture->actorId)['id_roles'] ?? 0);
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->cleanupOwnedServices();
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixture !== null && $this->actorRoleBefore > 0) {
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->actorRoleBefore],
                    ['id' => $this->fixture->actorId],
                );
            }
            $this->cleanupOwnedServices();
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAuthorizedAdminCanCompleteClassicCrudLifecycle(): void
    {
        $admin = $this->login($this->server->client());
        $name = $this->fixture->run . '_lifecycle';

        $stored = $admin->post('services/store', ['service' => $this->servicePayload($name)]);
        self::assertSame(200, $stored->statusCode, $stored->body);
        $storedData = json_decode($stored->body, true, 512, JSON_THROW_ON_ERROR);
        $serviceId = (int) ($storedData['id'] ?? 0);
        self::assertGreaterThan(0, $serviceId, $stored->body);
        $this->createdIds[] = $serviceId;
        self::assertSame($name, $this->serviceRow($serviceId)['name'] ?? null);

        $updatedName = $name . '_updated';
        $updated = $admin->post('services/update', ['service' => $this->servicePayload($updatedName, $serviceId)]);
        self::assertSame(200, $updated->statusCode, $updated->body);
        self::assertSame($updatedName, $this->serviceRow($serviceId)['name'] ?? null);

        $destroyed = $admin->post('services/destroy', ['service_id' => $serviceId]);
        self::assertSame(200, $destroyed->statusCode, $destroyed->body);
        self::assertTrue((bool) (json_decode($destroyed->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        self::assertSame([], $this->serviceRow($serviceId));
        $this->createdIds = array_values(array_diff($this->createdIds, [$serviceId]));
    }

    public function testCanonicalAndDirectAliasRejectAllNonPostMethodsWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->serviceRow($this->fixture->serviceId);

        foreach (['services/store', 'index.php/services/store'] as $path) {
            $client =
                $path === 'services/store'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $payload = $this->actionPayload('store');
                $response =
                    $method === 'GET'
                        ? $client->get($path, $payload)
                        : $client->requestApp($method, $path, $payload, null, false);

                self::assertSame(405, $response->statusCode, $method . ' ' . $path);
                self::assertSame('POST', $response->header('allow'), $method . ' ' . $path . ' Allow');
                self::assertSame($before, $this->serviceRow($this->fixture->serviceId));
                self::assertSame([], $this->ownedRows($payload['service']['name'] ?? ''));
            }
        }

        foreach (['update', 'destroy'] as $action) {
            foreach (['services/' . $action, 'index.php/services/' . $action] as $path) {
                $client = str_starts_with($path, 'services/')
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
                foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $requestPath = $path . ($action === 'destroy' ? '?service_id=' . $this->fixture->serviceId : '');
                    $response =
                        $method === 'GET'
                            ? $client->get($requestPath, $this->actionPayload($action))
                            : $client->requestApp($method, $requestPath, $this->actionPayload($action), null, false);

                    self::assertSame(405, $response->statusCode, $method . ' ' . $path);
                    self::assertSame('POST', $response->header('allow'), $method . ' ' . $path . ' Allow');
                    self::assertSame($before, $this->serviceRow($this->fixture->serviceId));
                }
            }
        }
    }

    public function testCsrfLessPostCannotStoreUpdateOrDestroy(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->serviceRow($this->fixture->serviceId);
        $newName = $this->fixture->run . '_csrf_missing';

        foreach (
            [
                ['services/store', ['service' => $this->servicePayload($newName)]],
                ['services/update', ['service' => $this->servicePayload($newName, $this->fixture->serviceId)]],
                ['services/destroy', ['service_id' => $this->fixture->serviceId]],
            ]
            as [$path, $payload]
        ) {
            $response = $admin->requestApp('POST', $path, $payload, null, false);

            self::assertSame(403, $response->statusCode, $path);
            self::assertSame($before, $this->serviceRow($this->fixture->serviceId));
            self::assertSame([], $this->ownedRows($newName));
        }
    }

    public function testPersistedRoleDemotionRejectsEachWriteActionFromExistingSession(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->serviceRow($this->fixture->serviceId);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);

        try {
            self::assertTrue(
                (bool) get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $this->fixture->actorId],
                ),
            );

            $requests = [
                ['services/store', ['service' => $this->servicePayload($this->fixture->run . '_demoted_store')]],
                [
                    'services/update',
                    [
                        'service' => $this->servicePayload(
                            $this->fixture->run . '_demoted_update',
                            $this->fixture->serviceId,
                        ),
                    ],
                ],
                ['services/destroy', ['service_id' => $this->fixture->serviceId]],
            ];
            foreach ($requests as [$path, $payload]) {
                $response = $admin->post($path, $payload);

                self::assertSame(403, $response->statusCode, $path);
                self::assertSame($before, $this->serviceRow($this->fixture->serviceId));
                self::assertSame([], $this->ownedRows($payload['service']['name'] ?? ''));
            }
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    public function testEditOnlyRoleCannotCreateThroughUpdateWithoutId(): void
    {
        $admin = $this->login($this->server->client());
        $roleBefore = get_instance()
            ->db->get_where('roles', ['id' => $this->actorRoleBefore])
            ->row_array();
        self::assertNotEmpty($roleBefore);
        $before = $this->serviceRow($this->fixture->serviceId);
        $payload = ['service' => $this->servicePayload($this->fixture->run . '_edit_only_update')];

        try {
            self::assertTrue(
                (bool) get_instance()->db->update('roles', ['services' => PRIV_EDIT], ['id' => $this->actorRoleBefore]),
            );
            $response = $admin->post('services/update', $payload);

            self::assertContains($response->statusCode, [400, 403], $response->body);
            self::assertSame($before, $this->serviceRow($this->fixture->serviceId));
            self::assertSame([], $this->ownedRows($payload['service']['name']));
        } finally {
            get_instance()->db->update(
                'roles',
                ['services' => $roleBefore['services']],
                ['id' => $this->actorRoleBefore],
            );
        }
    }

    /** @return array<string, mixed> */
    private function actionPayload(string $action): array
    {
        return match ($action) {
            'store' => ['service' => $this->servicePayload($this->fixture->run . '_wrong_method')],
            'update' => [
                'service' => $this->servicePayload($this->fixture->run . '_wrong_method', $this->fixture->serviceId),
            ],
            'destroy' => ['service_id' => $this->fixture->serviceId],
            default => throw new InvalidArgumentException('Unknown service action.'),
        };
    }

    /** @return array<string, mixed> */
    private function servicePayload(string $name, ?int $id = null): array
    {
        $payload = [
            'name' => $name,
            'duration' => 30,
            'price' => 0,
            'currency' => 'EUR',
            'description' => $this->fixture->run,
            'location' => 'Synthetic',
            'is_private' => 0,
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        if ($id !== null) {
            $payload['id'] = $id;
        }
        return $payload;
    }

    private function login(GateHttpClient $client): GateHttpClient
    {
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['admin_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        return $client;
    }

    private function serviceRow(int $id): array
    {
        return get_instance()
            ->db->get_where('services', ['id' => $id])
            ->row_array() ?:
            [];
    }

    /** @return list<array<string, mixed>> */
    private function ownedRows(string $name): array
    {
        if ($name === '') {
            return [];
        }
        return get_instance()
            ->db->get_where('services', ['name' => $name])
            ->result_array();
    }

    private function cleanupOwnedServices(): void
    {
        if ($this->fixture === null) {
            return;
        }
        $db = get_instance()->db;
        foreach ($this->createdIds as $id) {
            $db->delete('services_providers', ['id_services' => $id]);
            $db->delete('services', ['id' => $id]);
        }
        foreach ($db->get('services')->result_array() as $row) {
            if (str_starts_with((string) ($row['name'] ?? ''), $this->fixture->run . '_')) {
                $id = (int) $row['id'];
                $db->delete('services_providers', ['id_services' => $id]);
                $db->delete('services', ['id' => $id]);
            }
        }
        self::assertSame(
            [],
            array_values(
                array_filter(
                    $db->get('services')->result_array(),
                    fn(array $row): bool => str_starts_with((string) ($row['name'] ?? ''), $this->fixture->run . '_'),
                ),
            ),
            'Every synthetic service must be removed during cleanup.',
        );
    }
}

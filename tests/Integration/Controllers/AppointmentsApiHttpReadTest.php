<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for Appointments API v1 read authentication and projection. */
final class AppointmentsApiHttpReadTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $actorBefore = null;

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
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->actorBefore !== null && $this->fixture !== null) {
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->actorBefore['id_roles']],
                    [
                        'id' => $this->fixture->actorId,
                    ],
                );
            }
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testCollectionAndShowRejectMissingInvalidAndProviderBasicCredentials(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $paths = [
            'api/v1/appointments',
            'api/v1/appointments/' . $appointment['id'],
            'api/v1/appointments_api_v1/index',
            'api/v1/appointments_api_v1/show/' . $appointment['id'],
        ];
        $clients = [
            'anonymous' => $this->server->client(),
            'invalid-bearer' => $this->bearerClient($this->credentials['token'] . '-invalid'),
            'provider-basic' => $this->basicClient(
                $this->credentials['provider_username'],
                $this->credentials['password'],
            ),
        ];

        foreach ($clients as $case => $client) {
            foreach ($paths as $path) {
                $response = $client->get($path);
                self::assertSame(401, $response->statusCode, $case . ' must be denied for ' . $path);
                self::assertNotNull($response->header('www-authenticate'));
                self::assertStringNotContainsString($fixture->run, $response->body);
            }
        }
    }

    public function testAdminAndGlobalBearerReadCollectionAndShowWithSafeRelations(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $id = (int) $appointment['id'];
        $clients = [
            'admin-basic' => $this->basicClient($this->credentials['admin_username'], $this->credentials['password']),
            'global-bearer' => $this->bearerClient($this->credentials['token']),
        ];

        foreach ($clients as $case => $client) {
            foreach (['api/v1/appointments', 'api/v1/appointments_api_v1/index'] as $path) {
                $rows = $this->decode(
                    $client->get($path, ['q' => $fixture->run, 'with' => 'provider,customer,service']),
                );
                $matches = array_values(
                    array_filter(
                        $rows,
                        static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $id,
                    ),
                );
                self::assertCount(1, $matches, $case . ' must return the fixture appointment from ' . $path);
                $this->assertSafeAppointment($matches[0]);
            }

            foreach (['api/v1/appointments/' . $id, 'api/v1/appointments_api_v1/show/' . $id] as $path) {
                $response = $client->get($path, ['with' => 'provider,customer,service']);
                $row = $this->decode($response);
                self::assertSame($id, $row['id'] ?? null);
                $this->assertSafeAppointment($row);
            }
        }
    }

    public function testStoredAdminRoleChangeRejectsExistingBasicCredentials(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->actorBefore = $fixture->row('users', $fixture->actorId);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('api/v1/appointments')->statusCode);
        self::assertTrue(
            get_instance()->db->update(
                'users',
                ['id_roles' => (int) $customerRole['id']],
                [
                    'id' => $fixture->actorId,
                ],
            ),
        );
        foreach (['api/v1/appointments', 'api/v1/appointments_api_v1/index'] as $path) {
            $response = $admin->get($path);
            self::assertSame(401, $response->statusCode, $path);
            self::assertStringNotContainsString($fixture->run, $response->body);
        }
    }

    public function testDirectReadAliasesRejectNonGetMethodsWithoutDataOrMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $id = (int) $appointment['id'];
        $before = $fixture->row('appointments', $id);
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $requests = [
            ['POST', 'api/v1/appointments_api_v1/index'],
            ['PUT', 'api/v1/appointments_api_v1/index'],
            ['PATCH', 'api/v1/appointments_api_v1/index'],
            ['DELETE', 'api/v1/appointments_api_v1/index'],
            ['HEAD', 'api/v1/appointments_api_v1/index'],
            ['POST', 'api/v1/appointments_api_v1/show/' . $id],
            ['PUT', 'api/v1/appointments_api_v1/show/' . $id],
            ['PATCH', 'api/v1/appointments_api_v1/show/' . $id],
            ['DELETE', 'api/v1/appointments_api_v1/show/' . $id],
            ['HEAD', 'api/v1/appointments_api_v1/show/' . $id],
        ];
        foreach ($requests as [$method, $path]) {
            $response = $admin->requestApp($method, $path);
            self::assertSame(405, $response->statusCode, $method . ' ' . $path);
            self::assertSame('GET', $response->header('allow'));
            self::assertSame('', $response->body, $method . ' must not emit appointment data.');
            self::assertSame($before, $fixture->row('appointments', $id));
        }
    }

    public function testFieldsIdKeepsRelationsAndCollectionFilterPaginationBounded(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $first = $fixture->appointment();
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                [
                    'start_datetime' => date('Y-m-d 10:00:00', strtotime('+15 days')),
                    'end_datetime' => date('Y-m-d 10:30:00', strtotime('+15 days')),
                ],
                ['id' => (int) $first['id']],
            ),
        );
        $second = $fixture->appointment();
        $secondId = (int) $second['id'];

        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $with = 'provider,customer,service';
        foreach (['api/v1/appointments/' . $first['id'], 'api/v1/appointments_api_v1/show/' . $first['id']] as $path) {
            $row = $this->decode($admin->get($path, ['fields' => 'id', 'with' => $with]));
            self::assertSame(['customer', 'id', 'provider', 'service'], $this->sortedKeys($row));
            self::assertSame($fixture->providerId, $row['provider']['id'] ?? null);
            self::assertSame($fixture->customerId, $row['customer']['id'] ?? null);
            self::assertSame($fixture->serviceId, $row['service']['id'] ?? null);
            $this->assertProjectedRelations($row);
        }

        $query = ['serviceId' => $fixture->serviceId, 'length' => 1, 'sort' => '+id'];
        $pageOne = $this->decode($admin->get('api/v1/appointments', $query + ['page' => 1]));
        $pageTwo = $this->decode($admin->get('api/v1/appointments', $query + ['page' => 2]));
        self::assertCount(1, $pageOne);
        self::assertCount(1, $pageTwo);
        self::assertNotSame($pageOne[0]['id'] ?? null, $pageTwo[0]['id'] ?? null);
        self::assertEqualsCanonicalizing(
            [(int) $first['id'], $secondId],
            [(int) ($pageOne[0]['id'] ?? 0), (int) ($pageTwo[0]['id'] ?? 0)],
        );
    }

    public function testAggregatesWithSubsetReplacesOnlyRequestedRelation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);

        foreach (['provider', 'customer', 'service'] as $relation) {
            $rows = $this->decode(
                $admin->get('api/v1/appointments', [
                    'q' => $fixture->run,
                    'aggregates' => 1,
                    'with' => $relation,
                ]),
            );
            $matches = array_values(
                array_filter(
                    $rows,
                    static fn(mixed $row): bool => is_array($row) &&
                        (int) ($row['id'] ?? 0) === (int) $appointment['id'],
                ),
            );
            self::assertCount(1, $matches, 'aggregate relation must remain readable with=' . $relation);
            self::assertSame($fixture->providerId, $matches[0]['provider']['id'] ?? null);
            self::assertSame($fixture->customerId, $matches[0]['customer']['id'] ?? null);
            self::assertSame($fixture->serviceId, $matches[0]['service']['id'] ?? null);
            $this->assertProjectedRelations($matches[0]);
        }
    }

    private function assertProjectedRelations(array $appointment): void
    {
        foreach (['provider', 'customer', 'service'] as $relation) {
            self::assertIsArray($appointment[$relation] ?? null, $relation . ' must be projected.');
        }
        foreach (
            ['id_roles', 'ldap_dn', 'first_name', 'last_name', 'password', 'salt', 'google_token', 'caldav_password']
            as $rawField
        ) {
            self::assertArrayNotHasKey($rawField, $appointment['provider']);
            self::assertArrayNotHasKey($rawField, $appointment['customer']);
            self::assertArrayNotHasKey($rawField, $appointment['service']);
        }
    }

    private function assertSafeAppointment(array $appointment): void
    {
        self::assertSame($this->fixture->providerId, $appointment['providerId'] ?? null);
        self::assertSame($this->fixture->customerId, $appointment['customerId'] ?? null);
        self::assertSame($this->fixture->serviceId, $appointment['serviceId'] ?? null);
        $provider = $appointment['provider'] ?? null;
        $customer = $appointment['customer'] ?? null;
        $service = $appointment['service'] ?? null;
        self::assertIsArray($provider, 'provider must be projected.');
        self::assertIsArray($customer, 'customer must be projected.');
        self::assertIsArray($service, 'service must be projected.');
        self::assertSame(
            [
                'address',
                'city',
                'email',
                'firstName',
                'id',
                'isPrivate',
                'language',
                'lastName',
                'ldapDn',
                'mobile',
                'notes',
                'phone',
                'state',
                'timezone',
                'zip',
            ],
            $this->sortedKeys($provider),
        );
        self::assertSame(
            [
                'address',
                'city',
                'customField1',
                'customField2',
                'customField3',
                'customField4',
                'customField5',
                'email',
                'firstName',
                'id',
                'language',
                'lastName',
                'ldapDn',
                'notes',
                'phone',
                'timezone',
                'zip',
            ],
            $this->sortedKeys($customer),
        );
        self::assertSame(
            [
                'attendantsNumber',
                'availabilitiesType',
                'bufferAfter',
                'bufferBefore',
                'currency',
                'description',
                'duration',
                'id',
                'isPrivate',
                'location',
                'name',
                'price',
                'serviceCategoryId',
            ],
            $this->sortedKeys($service),
        );
        foreach ($this->fixture->seededStaffSecretValues() as $secret) {
            self::assertStringNotContainsString($secret, json_encode($appointment, JSON_THROW_ON_ERROR));
        }
    }

    private function decode(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @param array<string,mixed> $value @return list<string> */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);
        return $keys;
    }

    private function basicClient(string $username, string $password): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            ],
        );
    }

    private function bearerClient(string $token): GateHttpClient
    {
        return new GateHttpClient($this->server->baseUrl, additionalHeaders: ['Authorization' => 'Bearer ' . $token]);
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Providers::update surface. */
final class ProvidersUpdateHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private array $customerBefore = [];
    private array $customerSettingsBefore = [];
    private array $customerServicesBefore = [];
    private int $actorRoleBefore = 0;

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
            $db = get_instance()->db;
            $this->customerBefore = $this->fixture->row('users', $this->fixture->customerId);
            $this->customerSettingsBefore = $this->fixture->userSettingsRow($this->fixture->customerId);
            $this->actorRoleBefore = (int) ($this->fixture->row('users', $this->fixture->actorId)['id_roles'] ?? 0);
            $this->customerServicesBefore = $db
                ->get_where('services_providers', ['id_users' => $this->fixture->customerId])
                ->result_array();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixture !== null) {
                $db = get_instance()->db;
                if ($this->actorRoleBefore > 0) {
                    $db->update('users', ['id_roles' => $this->actorRoleBefore], ['id' => $this->fixture->actorId]);
                }
                $db->delete('services_providers', ['id_users' => $this->fixture->customerId]);
                foreach ($this->customerServicesBefore as $connection) {
                    $db->insert('services_providers', $connection);
                }
                foreach (
                    $db->get_where('user_settings', ['id_users' => $this->fixture->customerId])->result_array()
                    as $row
                ) {
                    $db->delete('user_settings', ['id_users' => $this->fixture->customerId]);
                }
                if ($this->customerSettingsBefore !== []) {
                    $db->insert('user_settings', $this->customerSettingsBefore);
                }
                if ($this->customerBefore !== []) {
                    $db->update('users', $this->customerBefore, ['id' => $this->fixture->customerId]);
                }
                self::assertSame($this->customerBefore, $this->fixture->row('users', $this->fixture->customerId));
                self::assertSame(
                    $this->customerSettingsBefore,
                    $this->fixture->userSettingsRow($this->fixture->customerId),
                );
                self::assertSame(
                    $this->customerServicesBefore,
                    $db->get_where('services_providers', ['id_users' => $this->fixture->customerId])->result_array(),
                );
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testCanonicalAndDirectAliasRejectWrongMethodsWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->providerState();

        foreach (['providers/update', 'index.php/providers/update'] as $path) {
            $client =
                $path === 'providers/update'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp($method, $path, [], null, false);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ' must be rejected.');
                self::assertSame('POST', $response->header('allow'), $method . ' ' . $path . ' Allow header.');
                self::assertSame($before, $this->providerState());
            }
        }
    }

    public function testUpdateWithoutIdCannotBecomeAnInsert(): void
    {
        $admin = $this->login($this->server->client());
        $apiPayload = $this->fixture->providerWritePayload('classic-missing-id');
        $payload = [
            'first_name' => $apiPayload['firstName'],
            'last_name' => $apiPayload['lastName'],
            'email' => $apiPayload['email'],
            'phone_number' => $apiPayload['phone'],
            'notes' => $apiPayload['notes'],
            'services' => $apiPayload['services'],
            'settings' => [
                'username' => $apiPayload['settings']['username'],
                'password' => $apiPayload['settings']['password'],
            ],
        ];

        $response = $admin->post('providers/update', ['provider' => $payload]);

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame([], $this->fixture->providerWriteState($apiPayload['email']));
    }

    public function testStoredRoleDemotionRejectsValidCsrfUpdateWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->providerState();
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);

        try {
            get_instance()->db->update(
                'users',
                ['id_roles' => (int) $customerRole['id']],
                ['id' => $this->fixture->actorId],
            );
            $response = $admin->post('providers/update', [
                'provider' => $this->providerPayload($this->fixture->providerId),
            ]);
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertSame($before, $this->providerState());
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    public function testAdminCanUpdateProviderNotesWithoutChangingRoleSettingsOrServices(): void
    {
        $admin = $this->login($this->server->client());
        // A normal save serializes empty exceptions as {}, so start from that form value.
        self::assertTrue(
            get_instance()->db->update(
                'user_settings',
                ['working_plan_exceptions' => '{}'],
                [
                    'id_users' => $this->fixture->providerId,
                ],
            ),
        );
        $before = $this->providerState();
        $payload = $this->providerPayload($this->fixture->providerId);
        $payload['notes'] = $this->fixture->run . '_ordinary_update';

        $response = $admin->post('providers/update', ['provider' => $payload]);

        self::assertSame(200, $response->statusCode, $response->body);
        $after = $this->providerState();
        self::assertSame($payload['notes'], $after['user']['notes']);
        self::assertSame($before['user']['id_roles'], $after['user']['id_roles']);
        self::assertSame($before['settings'], $after['settings']);
        self::assertSame($before['services'], $after['services']);
        $beforeUser = $before['user'];
        $afterUser = $after['user'];
        unset($beforeUser['notes'], $afterUser['notes'], $beforeUser['update_datetime'], $afterUser['update_datetime']);
        self::assertSame($beforeUser, $afterUser);
    }

    public function testProviderUpdateCannotDemoteTargetThroughCallerRoleId(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->providerState();
        $payload = $this->providerPayload($this->fixture->providerId);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);
        $payload['id_roles'] = (int) $customerRole['id'];
        $payload['notes'] = $this->fixture->run . '_must_not_demote';

        $response = $admin->post('providers/update', ['provider' => $payload]);

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($before, $this->providerState());
    }

    public function testProviderUpdateCannotPromoteNonProviderTargetThroughCallerRoleId(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->customerState();
        $providerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        self::assertNotEmpty($providerRole);
        $payload = $this->providerPayload($this->fixture->customerId);
        $payload['id_roles'] = (int) $providerRole['id'];
        $payload['email'] = $this->customerBefore['email'];
        $payload['first_name'] = $this->customerBefore['first_name'];
        $payload['last_name'] = $this->customerBefore['last_name'];

        $response = $admin->post('providers/update', ['provider' => $payload]);

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($before, $this->customerState());
    }

    public function testProviderUpdateRejectsCustomerTargetWithoutCallerRoleId(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->customerState();
        $payload = $this->providerPayload($this->fixture->customerId);
        unset($payload['id_roles']);
        $payload['email'] = $this->customerBefore['email'];
        $payload['first_name'] = $this->customerBefore['first_name'];
        $payload['last_name'] = $this->customerBefore['last_name'];

        $response = $admin->post('providers/update', ['provider' => $payload]);

        self::assertSame(404, $response->statusCode, $response->body);
        self::assertSame($before, $this->customerState());
    }

    public function testProviderUpdateRequiresCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->providerState();
        $payload = $this->providerPayload($this->fixture->providerId);
        $payload['notes'] = $this->fixture->run . '_without_csrf';

        $response = $admin->post('providers/update', ['provider' => $payload], withCsrfToken: false);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($before, $this->providerState());
    }

    public function testFailedServiceAssociationRollsBackProviderAggregate(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->providerState();
        $missingServiceId = 999999999;
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('services', ['id' => $missingServiceId])
                ->num_rows(),
        );
        $payload = $this->providerPayload($this->fixture->providerId);
        $payload['notes'] = $this->fixture->run . '_must_rollback';
        $payload['services'] = [$this->fixture->serviceId, $missingServiceId];

        // The second association fails its real FK after the user and first association writes.
        $response = $admin->post('providers/update', ['provider' => $payload]);

        self::assertSame(500, $response->statusCode, $response->body);
        self::assertStringContainsString('Could not write provider service associations.', $response->body);
        self::assertStringNotContainsString('services_providers_services', $response->body);
        self::assertSame($before, $this->providerState());
    }

    private function providerPayload(int $id): array
    {
        $provider = $this->fixture->row('users', $id);
        $settings = $this->fixture->userSettingsRow($id);
        $services = get_instance()
            ->db->get_where('services_providers', ['id_users' => $id])
            ->result_array();

        return [
            'id' => $id,
            'first_name' => $provider['first_name'],
            'last_name' => $provider['last_name'],
            'email' => $provider['email'],
            'phone_number' => $provider['phone_number'],
            'notes' => $provider['notes'],
            'timezone' => $provider['timezone'],
            'language' => $provider['language'],
            'is_private' => $provider['is_private'],
            'services' => array_map(static fn(array $row): int => (int) $row['id_services'], $services),
            'settings' => [
                'username' => $settings['username'] ?? '',
                'password' => $this->fixture->password,
                'working_plan' => $settings['working_plan'] ?? null,
                'working_plan_exceptions' => $settings['working_plan_exceptions'] ?? '{}',
                'calendar_view' => $settings['calendar_view'] ?? null,
            ],
        ];
    }

    private function login(GateHttpClient $client): GateHttpClient
    {
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['admin_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function providerState(): array
    {
        $id = $this->fixture->providerId;
        return [
            'user' => $this->fixture->row('users', $id),
            'settings' => $this->fixture->userSettingsRow($id),
            'services' => get_instance()
                ->db->get_where('services_providers', ['id_users' => $id])
                ->result_array(),
        ];
    }

    private function customerState(): array
    {
        $id = $this->fixture->customerId;
        return [
            'user' => $this->fixture->row('users', $id),
            'settings' => $this->fixture->userSettingsRow($id),
            'services' => get_instance()
                ->db->get_where('services_providers', ['id_users' => $id])
                ->result_array(),
        ];
    }
}

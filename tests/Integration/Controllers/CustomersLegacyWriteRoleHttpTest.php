<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Customers write surface and live role checks. */
final class CustomersLegacyWriteRoleHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
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
            $this->actorRoleBefore = (int) ($this->fixture->row('users', $this->fixture->actorId)['id_roles'] ?? 0);
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
            if ($this->fixture !== null && $this->actorRoleBefore > 0) {
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->actorRoleBefore],
                    ['id' => $this->fixture->actorId],
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

    public function testAuthorizedStaffCanCompleteClassicCustomerCrudLifecycle(): void
    {
        $client = $this->login($this->server->client());
        $customer = $this->fixture->customerWritePayload('lifecycle');

        $stored = $client->post('customers/store', ['customer' => $customer]);
        self::assertSame(200, $stored->statusCode, $stored->body);
        $storedData = json_decode($stored->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($storedData['success'] ?? false), $stored->body);
        $customerId = (int) ($storedData['id'] ?? 0);
        self::assertGreaterThan(0, $customerId, $stored->body);
        $created = $this->fixture->row('users', $customerId);
        self::assertSame($customer['email'], $created['email'] ?? null);

        $customer['id'] = $customerId;
        $customer['first_name'] = 'Updated Synthetic Customer';
        $updated = $client->post('customers/update', ['customer' => $customer]);
        self::assertSame(200, $updated->statusCode, $updated->body);
        self::assertSame('Updated Synthetic Customer', $this->fixture->row('users', $customerId)['first_name'] ?? null);

        $destroyed = $client->post('customers/destroy', ['customer_id' => $customerId]);
        self::assertSame(200, $destroyed->statusCode, $destroyed->body);
        self::assertTrue((bool) (json_decode($destroyed->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        self::assertSame([], $this->fixture->row('users', $customerId));
    }

    /** @return iterable<string, array{string, string}> */
    public static function legacyWriteCases(): iterable
    {
        foreach (['customers', 'index.php/customers'] as $prefix) {
            foreach (['store', 'update', 'destroy'] as $action) {
                yield $prefix . '/' . $action => [$prefix . '/' . $action, $action];
            }
        }
    }

    #[DataProvider('legacyWriteCases')]
    public function testPersistedCustomerRoleRejectsEachWriteActionFromExistingSession(
        string $path,
        string $action,
    ): void {
        $fixture = $this->fixture;
        $client = $this->login(
            str_starts_with($path, 'index.php/')
                ? new GateHttpClient($this->server->baseUrl, indexPage: '')
                : $this->server->client(),
        );
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);

        $before = $fixture->row('users', $fixture->customerId);
        try {
            self::assertTrue(
                (bool) get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );

            if ($action === 'store') {
                $payload = $fixture->customerWritePayload(
                    'demoted-' . (str_contains($path, 'index.php') ? 'alias' : 'canonical'),
                );
                $response = $client->post($path, ['customer' => $payload]);
                self::assertSame([], $this->rowsByEmail($payload['email']));
            } elseif ($action === 'update') {
                $update = $before;
                $update['id'] = $fixture->customerId;
                $update['first_name'] = $fixture->run . '_demoted_update';
                $response = $client->post($path, ['customer' => $update]);
                self::assertSame($before, $fixture->row('users', $fixture->customerId));
            } else {
                $response = $client->post($path, ['customer_id' => $fixture->customerId]);
                self::assertSame($before, $fixture->row('users', $fixture->customerId));
            }
            self::assertSame(403, $response->statusCode, $path . ' must reject demoted session.');
        } finally {
            get_instance()->db->update('users', ['id_roles' => $this->actorRoleBefore], ['id' => $fixture->actorId]);
        }
    }

    public function testLimitedVisibilityRejectsStaleAdminSessionAfterProviderRoleDemotion(): void
    {
        $fixture = $this->fixture;
        $client = $this->login($this->server->client());
        $providerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $setting = get_instance()
            ->db->get_where('settings', ['name' => 'limit_customer_visibility'])
            ->row_array();
        self::assertNotEmpty($providerRole);
        $settingCreated = false;
        if (!$setting) {
            self::assertTrue(
                (bool) get_instance()->db->insert('settings', ['name' => 'limit_customer_visibility', 'value' => '0']),
            );
            $setting = get_instance()
                ->db->get_where('settings', ['name' => 'limit_customer_visibility'])
                ->row_array();
            $settingCreated = true;
        }
        self::assertNotEmpty($setting);
        $payload = $fixture->customerWritePayload('demoted-provider-limited');

        try {
            self::assertTrue(
                (bool) get_instance()->db->update(
                    'roles',
                    ['customers' => PRIV_VIEW | PRIV_ADD],
                    ['id' => (int) $providerRole['id']],
                ),
            );
            self::assertTrue(
                (bool) get_instance()->db->update(
                    'settings',
                    ['value' => '1'],
                    ['name' => 'limit_customer_visibility'],
                ),
            );
            self::assertTrue(
                (bool) get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $providerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );

            $response = $client->post('customers/store', ['customer' => $payload]);
            self::assertSame([], $this->rowsByEmail($payload['email']));
            self::assertSame(403, $response->statusCode, $response->body);
        } finally {
            get_instance()->db->update('users', ['id_roles' => $this->actorRoleBefore], ['id' => $fixture->actorId]);
            get_instance()->db->update(
                'roles',
                ['customers' => $providerRole['customers']],
                ['id' => (int) $providerRole['id']],
            );
            if ($settingCreated) {
                get_instance()->db->delete('settings', ['id' => (int) $setting['id']]);
            } else {
                get_instance()->db->update('settings', ['value' => $setting['value']], ['id' => (int) $setting['id']]);
            }
        }
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

    /** @return list<array<string, mixed>> */
    private function rowsByEmail(string $email): array
    {
        return get_instance()
            ->db->get_where('users', ['email' => $email])
            ->result_array();
    }
}

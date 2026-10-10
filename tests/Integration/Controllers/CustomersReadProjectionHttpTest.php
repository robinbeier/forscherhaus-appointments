<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;
use Tests\Integration\Support\SessionFileReader;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';
require_once dirname(__DIR__) . '/Support/SessionFileReader.php';

/** Bounded HTTP regression coverage for backoffice customer read projections. */
final class CustomersReadProjectionHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    /** @var list<int> */
    private array $ownedCustomerIds = [];
    /** @var list<int> */
    private array $ownedAppointmentIds = [];
    private ?int $secretaryId = null;
    private ?array $providerRoleSnapshot = null;
    private ?array $secretaryRoleSnapshot = null;
    private ?array $limitCustomerAccessSnapshot = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->limitCustomerAccessSnapshot =
                get_instance()
                    ->db->get_where('settings', ['name' => 'limit_customer_access'])
                    ->row_array() ?:
                null;
            get_instance()->db->update('settings', ['value' => '1'], ['name' => 'limit_customer_access']);
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            try {
                $this->restoreRoleSnapshots();
                $this->restoreLimitCustomerAccess();
                $this->cleanupOwnedRows();
            } finally {
                $this->fixture?->cleanup();
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
                $this->restoreRoleSnapshots();
                $this->restoreLimitCustomerAccess();
                $this->cleanupOwnedRows();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testFindRejectsRevokedCustomersViewAfterLoginAndReturnsSafeProjection(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->createAppointment($fixture->providerId, $fixture->customerId);
        $before = $fixture->row('users', $fixture->customerId);
        $role = $this->snapshotRole(DB_SLUG_PROVIDER, 'provider');
        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);

        $allowed = $client->post('customers/find', ['customer_id' => $fixture->customerId]);
        self::assertSame(200, $allowed->statusCode, $allowed->body);
        $customer = $this->decodeJson($allowed);
        $this->assertSafeCustomerProjection($customer, $fixture->customerId);
        self::assertSame($fixture->run . '_customer@synthetic.invalid', $customer['email']);

        self::assertTrue(get_instance()->db->update('roles', ['customers' => 0], ['id' => $role['id']]));
        $revoked = $client->post('customers/find', ['customer_id' => $fixture->customerId]);
        $revokedSearch = $client->post('customers/search', ['keyword' => $fixture->run]);
        self::assertSame(403, $revoked->statusCode, $revoked->body);
        self::assertSame(403, $revokedSearch->statusCode, $revokedSearch->body);
        self::assertStringNotContainsString($fixture->run, $revoked->body);
        self::assertStringNotContainsString($fixture->run, $revokedSearch->body);
        self::assertSame($before, $fixture->row('users', $fixture->customerId));
    }

    public function testSearchScopesProviderAndSecretaryRowsAndRedactsAppointmentsWithoutView(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $providerRole = $this->snapshotRole(DB_SLUG_PROVIDER, 'provider');
        $secretaryRole = $this->snapshotRole(DB_SLUG_SECRETARY, 'secretary');
        $foreignProviderId = $this->foreignProviderId($fixture->providerId);
        $foreignCustomerId = $this->createCustomer($fixture->run . '_foreign');
        $ownedAppointmentId = $this->createAppointment($fixture->providerId, $fixture->customerId);
        $foreignAppointmentId = $this->createAppointment($foreignProviderId, $fixture->customerId);
        $this->createAppointment($foreignProviderId, $foreignCustomerId);
        $ownedAppointmentHash = (string) $fixture->row('appointments', $ownedAppointmentId)['hash'];
        $foreignAppointmentHash = (string) $fixture->row('appointments', $foreignAppointmentId)['hash'];
        $beforeOwnedCustomer = $fixture->row('users', $fixture->customerId);
        $beforeForeignCustomer = $fixture->row('users', $foreignCustomerId);
        $beforeAppointments = array_map(
            fn(int $id): array => $fixture->row('appointments', $id),
            $this->ownedAppointmentIds,
        );
        [$secretaryUsername, $secretaryPassword] = $this->createSecretary($fixture->providerId);

        $provider = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        $rows = $this->decodeJson($provider->post('customers/search', ['keyword' => $fixture->run]));
        $this->assertScopedSearchRows(
            $rows,
            $fixture->customerId,
            $foreignCustomerId,
            $foreignAppointmentId,
            $ownedAppointmentHash,
            $foreignAppointmentHash,
            true,
        );

        $limitedRows = $this->decodeJson(
            $provider->post('customers/search', [
                'keyword' => $fixture->run,
                'order_by' => 'id DESC',
                'limit' => 1,
            ]),
        );
        $this->assertScopedSearchRows(
            $limitedRows,
            $fixture->customerId,
            $foreignCustomerId,
            $foreignAppointmentId,
            $ownedAppointmentHash,
            $foreignAppointmentHash,
            true,
        );

        $alias = $provider->post('backend_api/ajax_filter_customers', [
            'keyword' => $fixture->run,
        ]);
        // The legacy POST alias redirects as GET; the followed request loses CSRF and must not reveal rows.
        self::assertSame(403, $alias->statusCode, $alias->body);
        self::assertStringNotContainsString($fixture->run, $alias->body);

        self::assertTrue(get_instance()->db->update('roles', ['appointments' => 0], ['id' => $providerRole['id']]));
        $withoutAppointmentsView = $this->decodeJson($provider->post('customers/search', ['keyword' => $fixture->run]));
        $this->assertScopedSearchRows(
            $withoutAppointmentsView,
            $fixture->customerId,
            $foreignCustomerId,
            $foreignAppointmentId,
            $ownedAppointmentHash,
            $foreignAppointmentHash,
            false,
        );

        $secretary = $this->login($secretaryUsername, $secretaryPassword);
        $secretaryRows = $this->decodeJson($secretary->post('customers/search', ['keyword' => $fixture->run]));
        $this->assertScopedSearchRows(
            $secretaryRows,
            $fixture->customerId,
            $foreignCustomerId,
            $foreignAppointmentId,
            $ownedAppointmentHash,
            $foreignAppointmentHash,
            true,
        );

        self::assertSame($beforeOwnedCustomer, $fixture->row('users', $fixture->customerId));
        self::assertSame($beforeForeignCustomer, $fixture->row('users', $foreignCustomerId));
        foreach ($this->ownedAppointmentIds as $index => $id) {
            self::assertSame($beforeAppointments[$index], $fixture->row('appointments', $id));
        }
    }

    public function testDemotedAdminCannotReadCustomersWithEitherLimitSetting(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $customerBefore = $fixture->row('users', $fixture->customerId);
        $actorBefore = $fixture->row('users', $fixture->actorId);
        get_instance()->load->library('permissions');
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);

        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->post('customers/find', ['customer_id' => $fixture->customerId])->statusCode);
        self::assertSame(200, $admin->get('customers/index')->statusCode);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );
            foreach (['0', '1'] as $limitSetting) {
                self::assertTrue(
                    get_instance()->db->update(
                        'settings',
                        ['value' => $limitSetting],
                        ['name' => 'limit_customer_access'],
                    ),
                );
                self::assertFalse(
                    get_instance()->permissions->has_customer_access($fixture->actorId, $fixture->customerId),
                );
                $find = $admin->post('customers/find', ['customer_id' => $fixture->customerId]);
                $search = $admin->post('customers/search', ['keyword' => $fixture->run]);
                $page = $admin->get('customers/index');
                foreach ([$find, $search, $page] as $response) {
                    self::assertSame(403, $response->statusCode, $response->body);
                    self::assertStringNotContainsString($fixture->run, $response->body);
                }
            }
        } finally {
            get_instance()->db->update('users', ['id_roles' => $actorBefore['id_roles']], ['id' => $fixture->actorId]);
        }

        self::assertSame($actorBefore, $fixture->row('users', $fixture->actorId));
        self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));
    }

    public function testDeniedCustomerPagesPreserveAuthenticatedDestinationAndSetAnonymousReturnTarget(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $server = $this->server;
        self::assertNotNull($server);

        foreach (['customers', 'customers/index'] as $path) {
            $anonymous = new GateHttpClient(
                $server->baseUrl,
                additionalHeaders: ['X-FH-Test' => 'customers-destination'],
            );
            $response = $anonymous->get($path);
            self::assertSame(307, $response->statusCode, $path);
            self::assertStringContainsString('/login', (string) $response->header('location'), $path);
            self::assertStringEndsWith('/customers', $this->sessionDestination($anonymous), $path);
        }

        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $actorBefore = $fixture->row('users', $fixture->actorId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );

            foreach (['customers', 'customers/index'] as $path) {
                $response = $admin->get($path);
                self::assertSame(403, $response->statusCode, $path . ' ' . $response->body);
                self::assertStringNotContainsString($fixture->run, $response->body, $path);
                self::assertSame($beforeDestination, $this->sessionDestination($admin), $path);
            }
        } finally {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $actorBefore['id_roles']],
                    ['id' => $fixture->actorId],
                ),
            );
        }
    }

    /** @return array{id:int, customers:int, appointments:int} */
    private function snapshotRole(string $slug, string $label): array
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => $slug])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null, 'Synthetic ' . $label . ' role is required.');
        $snapshot = [
            'id' => (int) $role['id'],
            'customers' => (int) ($role['customers'] ?? 0),
            'appointments' => (int) ($role['appointments'] ?? 0),
        ];
        if ($slug === DB_SLUG_PROVIDER) {
            $this->providerRoleSnapshot = $snapshot;
        } else {
            $this->secretaryRoleSnapshot = $snapshot;
        }
        return $snapshot;
    }

    private function createCustomer(string $suffix): int
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null);
        self::assertTrue(
            get_instance()->db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => $suffix,
                'email' => $suffix . '@synthetic.invalid',
                'phone_number' => '000000000',
                'notes' => $this->fixture?->run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $role['id'],
                'ldap_dn' => $suffix . '_internal',
            ]),
        );
        $id = (int) get_instance()->db->insert_id();
        $this->ownedCustomerIds[] = $id;
        return $id;
    }

    private function createAppointment(int $providerId, int $customerId): int
    {
        $ci = &get_instance();
        $ci->load->model('appointments_model');
        $id = $ci->appointments_model->save([
            'start_datetime' => date(
                'Y-m-d H:i:s',
                strtotime('+' . (10 + count($this->ownedAppointmentIds)) . ' days'),
            ),
            'end_datetime' => date(
                'Y-m-d H:i:s',
                strtotime('+' . (10 + count($this->ownedAppointmentIds)) . ' days +30 minutes'),
            ),
            'notes' => $this->fixture?->run,
            'is_unavailability' => false,
            'id_users_provider' => $providerId,
            'id_users_customer' => $customerId,
            'id_services' => $this->fixture?->serviceId,
        ]);
        $this->ownedAppointmentIds[] = (int) $id;
        return (int) $id;
    }

    /** @return array{0:string,1:string} */
    private function createSecretary(int $providerId): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null);
        $username = $fixture->run . '_secretary';
        $password = bin2hex(random_bytes(16));
        $salt = generate_salt();
        self::assertTrue(
            get_instance()->db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => 'Secretary',
                'email' => $username . '@synthetic.invalid',
                'phone_number' => '000000000',
                'notes' => $fixture->run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $role['id'],
            ]),
        );
        $this->secretaryId = (int) get_instance()->db->insert_id();
        self::assertTrue(
            get_instance()->db->insert('user_settings', [
                'id_users' => $this->secretaryId,
                'username' => $username,
                'password' => hash_password($salt, $password),
                'salt' => $salt,
                'notifications' => 0,
            ]),
        );
        self::assertTrue(
            get_instance()->db->insert('secretaries_providers', [
                'id_users_secretary' => $this->secretaryId,
                'id_users_provider' => $providerId,
            ]),
        );
        return [$username, $password];
    }

    /** @param list<array<string,mixed>> $rows */
    private function assertScopedSearchRows(
        array $rows,
        int $ownedCustomerId,
        int $foreignCustomerId,
        int $foreignAppointmentId,
        string $ownedAppointmentHash,
        string $foreignAppointmentHash,
        bool $withAppointments,
    ): void {
        $owned = array_values(
            array_filter(
                $rows,
                static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $ownedCustomerId,
            ),
        );
        self::assertCount(1, $owned);
        self::assertSame($ownedCustomerId, (int) $owned[0]['id']);
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertNotSame($foreignCustomerId, (int) ($row['id'] ?? 0));
            $projection = $row;
            unset($projection['appointments']);
            $this->assertSafeCustomerProjection($projection, $ownedCustomerId);
            if ($withAppointments) {
                self::assertArrayHasKey('appointments', $row);
                self::assertCount(1, $row['appointments']);
                self::assertNotSame($foreignAppointmentId, (int) ($row['appointments'][0]['id'] ?? 0));
                self::assertNotSame('', $ownedAppointmentHash);
                self::assertNotSame('', $foreignAppointmentHash);
                self::assertSame($ownedAppointmentHash, $row['appointments'][0]['hash'] ?? null);
                self::assertNotSame($foreignAppointmentHash, $row['appointments'][0]['hash'] ?? null);
                $expectedAppointment = [
                    'end_datetime',
                    'hash',
                    'id',
                    'id_users_provider',
                    'provider',
                    'service',
                    'start_datetime',
                ];
                $actualAppointment = array_keys($row['appointments'][0]);
                sort($actualAppointment);
                self::assertSame($expectedAppointment, $actualAppointment);
                self::assertSame(['name'], array_keys($row['appointments'][0]['service']));
                $providerFields = array_keys($row['appointments'][0]['provider']);
                sort($providerFields);
                self::assertSame(['first_name', 'last_name', 'timezone'], $providerFields);
            } else {
                self::assertSame([], $row['appointments'] ?? null);
            }
        }
    }

    /** @param array<string,mixed> $customer */
    private function assertSafeCustomerProjection(array $customer, int $customerId): void
    {
        self::assertSame($customerId, (int) ($customer['id'] ?? 0));
        foreach (['id_roles', 'password', 'salt', 'google_token', 'caldav_password'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $customer);
        }
        $expected = [
            'address',
            'city',
            'custom_field_1',
            'custom_field_2',
            'custom_field_3',
            'custom_field_4',
            'custom_field_5',
            'email',
            'first_name',
            'id',
            'language',
            'last_name',
            'ldap_dn',
            'notes',
            'phone_number',
            'timezone',
            'zip_code',
        ];
        $actual = array_keys($customer);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }

    private function decodeJson(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $path = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($path);
        $contents = SessionFileReader::read($path);
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));

        return $matches[1];
    }

    private function login(string $username, string $password): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $response->statusCode, $response->body);
        return $client;
    }

    private function foreignProviderId(int $ownedProviderId): int
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $row = get_instance()
            ->db->where('id_roles', (int) $role['id'])
            ->where('id !=', $ownedProviderId)
            ->order_by('id', 'ASC')
            ->get('users')
            ->row_array();
        self::assertNotEmpty($row['id'] ?? null, 'The isolated seed must provide a second provider.');
        return (int) $row['id'];
    }

    private function restoreRoleSnapshots(): void
    {
        foreach ([$this->providerRoleSnapshot, $this->secretaryRoleSnapshot] as $snapshot) {
            if ($snapshot !== null) {
                get_instance()->db->update(
                    'roles',
                    [
                        'customers' => $snapshot['customers'],
                        'appointments' => $snapshot['appointments'],
                    ],
                    ['id' => $snapshot['id']],
                );
            }
        }
    }

    private function restoreLimitCustomerAccess(): void
    {
        if ($this->limitCustomerAccessSnapshot !== null) {
            get_instance()->db->update(
                'settings',
                ['value' => $this->limitCustomerAccessSnapshot['value']],
                ['id' => $this->limitCustomerAccessSnapshot['id']],
            );
        }
    }

    private function cleanupOwnedRows(): void
    {
        $db = get_instance()->db;
        foreach ($this->ownedAppointmentIds as $id) {
            $db->delete('appointments', ['id' => $id]);
        }
        if ($this->secretaryId !== null) {
            $db->delete('secretaries_providers', ['id_users_secretary' => $this->secretaryId]);
            $db->delete('user_settings', ['id_users' => $this->secretaryId]);
            $db->delete('users', ['id' => $this->secretaryId]);
        }
        foreach ($this->ownedCustomerIds as $id) {
            $db->delete('users', ['id' => $id]);
        }
    }
}

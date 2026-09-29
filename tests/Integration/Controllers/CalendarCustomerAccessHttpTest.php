<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP regression for provider customer filtering and appointment-hash authority. */
final class CalendarCustomerAccessHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $limitCustomerAccessSnapshot = null;
    private ?int $foreignCustomerId = null;
    private ?int $foreignAppointmentId = null;
    private ?int $secretaryId = null;
    private ?array $providerRolePermissionSnapshot = null;

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
                $this->restoreLimitCustomerAccess();
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
                $this->restoreProviderRolePermissions();
            } finally {
                try {
                    $this->deleteForeignRows();
                } finally {
                    try {
                        $this->restoreLimitCustomerAccess();
                    } finally {
                        $this->fixture?->cleanup();
                    }
                }
            }
        }
    }

    public function testProviderWithoutCustomersViewCannotPreloadOrOpenOwnAppointment(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null, 'Synthetic provider role is required.');
        self::assertGreaterThanOrEqual(PRIV_VIEW, (int) ($role['appointments'] ?? 0));
        $this->providerRolePermissionSnapshot = [
            'id' => (int) $role['id'],
            'appointments' => (int) $role['appointments'],
            'customers' => (int) $role['customers'],
        ];

        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $beforeAppointment = $fixture->row('appointments', (int) $fixture->appointment()['id']);
        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'roles',
                    ['customers' => 0],
                    ['id' => $this->providerRolePermissionSnapshot['id']],
                ),
            );
            $initial = $client->get('calendar/index');
            self::assertSame(200, $initial->statusCode, $initial->body);
            $initialVars = $this->scriptVars($initial->body);
            self::assertSame([], $initialVars['customers'] ?? null);
            self::assertStringNotContainsString($this->customerEmail($fixture->customerId), $initial->body);

            $ownHash = rawurlencode((string) $beforeAppointment['hash']);
            $own = $client->get('calendar/index/' . $ownHash);
            self::assertSame(403, $own->statusCode, $own->body);
            $ownAlias = $client->get('calendar/reschedule/' . $ownHash);
            self::assertSame(403, $ownAlias->statusCode, $ownAlias->body);
            self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
            self::assertSame($beforeAppointment, $fixture->row('appointments', (int) $beforeAppointment['id']));
        } finally {
            $this->restoreProviderRolePermissions();
        }
    }

    public function testLimitedProviderSeesOnlyPermittedCustomersAndAppointmentHashes(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreignProviderId = $this->foreignProviderId($fixture->providerId);
        $ownedAppointment = $fixture->appointment();
        $foreignCustomer = $this->createForeignCustomer($fixture->run);
        $foreignAppointment = $this->createForeignAppointment(
            $foreignProviderId,
            $foreignCustomer,
            $fixture->serviceId,
            $fixture->run,
        );
        $customerInternal = $fixture->run . '-customer-internal';
        $appointmentInternal = $fixture->run . '-appointment-internal';
        self::assertTrue(
            get_instance()->db->update(
                'users',
                ['ldap_dn' => $customerInternal],
                [
                    'id' => $fixture->customerId,
                ],
            ),
        );
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                ['id_google_calendar' => $appointmentInternal],
                [
                    'id' => (int) $ownedAppointment['id'],
                ],
            ),
        );

        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        $initial = $client->get('calendar/index');
        self::assertSame(200, $initial->statusCode, $initial->body);
        $initialVars = $this->scriptVars($initial->body);
        $this->assertSafeCustomerProjection($initialVars, $this->customerEmail($fixture->customerId));
        self::assertStringContainsString($this->customerEmail($fixture->customerId), $initial->body);
        self::assertStringNotContainsString($this->customerEmail($foreignCustomer), $initial->body);
        self::assertStringNotContainsString($customerInternal, $initial->body);

        $beforeForeignAppointment = $fixture->row('appointments', (int) $foreignAppointment['id']);
        $foreign = $client->get('calendar/index/' . rawurlencode((string) $foreignAppointment['hash']));
        self::assertSame(403, $foreign->statusCode, $foreign->body);
        self::assertSame($beforeForeignAppointment, $fixture->row('appointments', (int) $foreignAppointment['id']));
        self::assertStringNotContainsString($this->customerEmail($foreignCustomer), $foreign->body);

        $foreignAlias = $client->get('calendar/reschedule/' . rawurlencode((string) $foreignAppointment['hash']));
        self::assertSame(403, $foreignAlias->statusCode, $foreignAlias->body);

        $own = $client->get('calendar/index/' . rawurlencode((string) $ownedAppointment['hash']));
        self::assertSame(200, $own->statusCode, $own->body);
        $ownVars = $this->scriptVars($own->body);
        $this->assertSafeCustomerProjection($ownVars, $this->customerEmail($fixture->customerId));
        $this->assertEditProjection($ownVars, $ownedAppointment, $fixture->customerId);
        self::assertStringContainsString($this->customerEmail($fixture->customerId), $own->body);
        self::assertStringNotContainsString((string) $foreignAppointment['hash'], $own->body);
        self::assertStringNotContainsString($customerInternal, $own->body);
        self::assertStringNotContainsString($appointmentInternal, $own->body);

        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        $adminInitial = $admin->get('calendar/index');
        self::assertSame(200, $adminInitial->statusCode, $adminInitial->body);
        self::assertStringContainsString($this->customerEmail($foreignCustomer), $adminInitial->body);
        $adminForeign = $admin->get('calendar/index/' . rawurlencode((string) $foreignAppointment['hash']));
        self::assertSame(200, $adminForeign->statusCode, $adminForeign->body);
        self::assertStringContainsString($this->customerEmail($foreignCustomer), $adminForeign->body);
        $this->assertEditProjection($this->scriptVars($adminForeign->body), $foreignAppointment, $foreignCustomer);

        $adminRoleId = (int) get_instance()
            ->db->get_where('users', ['id' => $fixture->actorId])
            ->row('id_roles');
        $providerRoleId = (int) get_instance()
            ->db->get_where('users', ['id' => $fixture->providerId])
            ->row('id_roles');
        try {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $providerRoleId], ['id' => $fixture->actorId]),
            );
            $staleAdmin = $admin->get('calendar/index');
            self::assertSame(200, $staleAdmin->statusCode, $staleAdmin->body);
            self::assertStringNotContainsString($this->customerEmail($foreignCustomer), $staleAdmin->body);
        } finally {
            get_instance()->db->update('users', ['id_roles' => $adminRoleId], ['id' => $fixture->actorId]);
        }
    }

    public function testLimitedSecretarySeesAssignedCustomerAndDeniesUnassignedCustomerHash(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreignProviderId = $this->foreignProviderId($fixture->providerId);
        $ownedAppointment = $fixture->appointment();
        $foreignCustomer = $this->createForeignCustomer($fixture->run . '-secretary');
        $foreignAppointment = $this->createForeignAppointment(
            $foreignProviderId,
            $foreignCustomer,
            $fixture->serviceId,
            $fixture->run . '-secretary',
        );
        [$username, $password] = $this->createSecretary($fixture->providerId, $fixture->run);

        $client = $this->login($username, $password);
        $initial = $client->get('calendar/index');
        self::assertSame(200, $initial->statusCode, $initial->body);
        $initialVars = $this->scriptVars($initial->body);
        $this->assertSafeCustomerProjection($initialVars, $this->customerEmail($fixture->customerId));
        self::assertStringContainsString($this->customerEmail($fixture->customerId), $initial->body);
        self::assertStringNotContainsString($this->customerEmail($foreignCustomer), $initial->body);

        $foreign = $client->get('calendar/index/' . rawurlencode((string) $foreignAppointment['hash']));
        self::assertSame(403, $foreign->statusCode, $foreign->body);
        self::assertStringNotContainsString($this->customerEmail($foreignCustomer), $foreign->body);

        $own = $client->get('calendar/index/' . rawurlencode((string) $ownedAppointment['hash']));
        self::assertSame(200, $own->statusCode, $own->body);
        $ownVars = $this->scriptVars($own->body);
        $this->assertSafeCustomerProjection($ownVars, $this->customerEmail($fixture->customerId));
        $this->assertEditProjection($ownVars, $ownedAppointment, $fixture->customerId);
        self::assertStringContainsString($this->customerEmail($fixture->customerId), $own->body);
    }

    private function login(string $username, string $password): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));

        return $client;
    }

    private function foreignProviderId(int $ownedProviderId): int
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null, 'Synthetic provider role is required.');
        $row = get_instance()
            ->db->where('id_roles', (int) $role['id'])
            ->where('id !=', $ownedProviderId)
            ->order_by('id', 'ASC')
            ->get('users')
            ->row_array();
        self::assertNotEmpty($row['id'] ?? null, 'The isolated seed must provide a second provider.');

        return (int) $row['id'];
    }

    private function createForeignCustomer(string $run): int
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null, 'Synthetic customer role is required.');
        $email = $run . '_foreign_calendar_customer@synthetic.invalid';
        $now = date('Y-m-d H:i:s');
        self::assertTrue(
            get_instance()->db->insert('users', [
                'first_name' => 'Foreign',
                'last_name' => 'Calendar Customer',
                'email' => $email,
                'phone_number' => '000000000',
                'notes' => $run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $role['id'],
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]),
        );
        $this->foreignCustomerId = (int) get_instance()->db->insert_id();

        return $this->foreignCustomerId;
    }

    private function createForeignAppointment(int $providerId, int $customerId, int $serviceId, string $run): array
    {
        $now = date('Y-m-d H:i:s');
        self::assertTrue(
            get_instance()->db->insert('appointments', [
                'book_datetime' => $now,
                'start_datetime' => date('Y-m-d 11:00:00', strtotime('+14 days')),
                'end_datetime' => date('Y-m-d 11:30:00', strtotime('+14 days')),
                'notes' => $run . '-foreign-appointment',
                'hash' => $run . '-foreign-calendar-hash',
                'is_unavailability' => false,
                'id_users_provider' => $providerId,
                'id_users_customer' => $customerId,
                'id_services' => $serviceId,
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]),
        );
        $this->foreignAppointmentId = (int) get_instance()->db->insert_id();

        return get_instance()
            ->db->get_where('appointments', ['id' => $this->foreignAppointmentId])
            ->row_array();
    }

    /** @return array{0:string,1:string} */
    private function createSecretary(int $providerId, string $run): array
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null, 'Synthetic secretary role is required.');
        $username = $run . '_calendar_secretary';
        $password = bin2hex(random_bytes(16));
        $salt = generate_salt();
        $now = date('Y-m-d H:i:s');
        self::assertTrue(
            get_instance()->db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => 'Calendar Secretary',
                'email' => $username . '@synthetic.invalid',
                'phone_number' => '000000000',
                'notes' => $run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $role['id'],
                'create_datetime' => $now,
                'update_datetime' => $now,
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

    private function customerEmail(int $customerId): string
    {
        return (string) (get_instance()
            ->db->select('email')
            ->get_where('users', ['id' => $customerId])
            ->row_array()['email'] ?? '');
    }

    /** @return array<string, mixed> */
    private function scriptVars(string $body): array
    {
        self::assertSame(1, preg_match('/const vars = (\{.*?\});\s*return/s', $body, $matches));
        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vars);

        return $vars;
    }

    /** @param array<string, mixed> $vars */
    private function assertSafeCustomerProjection(array $vars, string $expectedEmail): void
    {
        self::assertIsArray($vars['customers'] ?? null);
        $matching = array_values(
            array_filter(
                $vars['customers'],
                static fn(mixed $customer): bool => is_array($customer) &&
                    ($customer['email'] ?? null) === $expectedEmail,
            ),
        );
        self::assertCount(1, $matching);
        $this->assertCustomerReadKeys($matching[0]);
        foreach (['password', 'salt', 'ldap_dn', 'google_token', 'caldav_password'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $matching[0]);
        }
    }

    /** @param array<string, mixed> $vars @param array<string, mixed> $appointment */
    private function assertEditProjection(array $vars, array $appointment, int $customerId): void
    {
        $edit = $vars['edit_appointment'] ?? null;
        self::assertIsArray($edit);
        $expected = [
            'id',
            'start_datetime',
            'end_datetime',
            'location',
            'notes',
            'color',
            'status',
            'id_users_provider',
            'id_users_customer',
            'id_services',
            'customer',
        ];
        $actual = array_keys($edit);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertSame((int) $appointment['id'], (int) $edit['id']);
        self::assertSame((int) $appointment['id_users_provider'], (int) $edit['id_users_provider']);
        self::assertSame((int) $appointment['id_services'], (int) $edit['id_services']);
        self::assertSame($customerId, (int) $edit['id_users_customer']);
        self::assertIsArray($edit['customer']);
        $this->assertCustomerReadKeys($edit['customer']);
        self::assertSame($customerId, (int) $edit['customer']['id']);
        self::assertSame($this->customerEmail($customerId), $edit['customer']['email']);
    }

    /** @param array<string, mixed> $customer */
    private function assertCustomerReadKeys(array $customer): void
    {
        $expected = [
            'id',
            'first_name',
            'last_name',
            'email',
            'phone_number',
            'address',
            'city',
            'zip_code',
            'language',
            'timezone',
            'notes',
            'custom_field_1',
            'custom_field_2',
            'custom_field_3',
            'custom_field_4',
            'custom_field_5',
        ];
        $actual = array_keys($customer);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }

    private function deleteForeignRows(): void
    {
        try {
            if ($this->foreignAppointmentId !== null) {
                get_instance()->db->delete('reschedule_authorities', ['appointment_id' => $this->foreignAppointmentId]);
                get_instance()->db->delete('appointments', ['id' => $this->foreignAppointmentId]);
            }
        } finally {
            try {
                if ($this->foreignCustomerId !== null) {
                    get_instance()->db->delete('users', ['id' => $this->foreignCustomerId]);
                }
            } finally {
                if ($this->secretaryId !== null) {
                    get_instance()->db->delete('secretaries_providers', ['id_users_secretary' => $this->secretaryId]);
                    get_instance()->db->delete('user_settings', ['id_users' => $this->secretaryId]);
                    get_instance()->db->delete('users', ['id' => $this->secretaryId]);
                }
            }
        }

        if ($this->foreignAppointmentId !== null) {
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('appointments', [
                        'id' => $this->foreignAppointmentId,
                    ])
                    ->num_rows(),
            );
        }
        if ($this->foreignCustomerId !== null) {
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('users', [
                        'id' => $this->foreignCustomerId,
                    ])
                    ->num_rows(),
            );
        }
        if ($this->secretaryId !== null) {
            foreach (['secretaries_providers', 'user_settings'] as $table) {
                $column = $table === 'secretaries_providers' ? 'id_users_secretary' : 'id_users';
                self::assertSame(
                    0,
                    get_instance()
                        ->db->get_where($table, [
                            $column => $this->secretaryId,
                        ])
                        ->num_rows(),
                );
            }
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('users', [
                        'id' => $this->secretaryId,
                    ])
                    ->num_rows(),
            );
        }
        $this->foreignAppointmentId = null;
        $this->foreignCustomerId = null;
        $this->secretaryId = null;
    }

    private function restoreLimitCustomerAccess(): void
    {
        if ($this->limitCustomerAccessSnapshot === null) {
            return;
        }
        get_instance()->db->update(
            'settings',
            ['value' => $this->limitCustomerAccessSnapshot['value']],
            ['name' => 'limit_customer_access'],
        );
        $restored = get_instance()
            ->db->get_where('settings', ['name' => 'limit_customer_access'])
            ->row_array();
        self::assertSame($this->limitCustomerAccessSnapshot['value'], $restored['value'] ?? null);
        $this->limitCustomerAccessSnapshot = null;
    }

    private function restoreProviderRolePermissions(): void
    {
        if ($this->providerRolePermissionSnapshot === null) {
            return;
        }
        get_instance()->db->update(
            'roles',
            [
                'appointments' => $this->providerRolePermissionSnapshot['appointments'],
                'customers' => $this->providerRolePermissionSnapshot['customers'],
            ],
            ['id' => $this->providerRolePermissionSnapshot['id']],
        );
        $restored = get_instance()
            ->db->get_where('roles', ['id' => $this->providerRolePermissionSnapshot['id']])
            ->row_array();
        self::assertSame(
            $this->providerRolePermissionSnapshot['appointments'],
            (int) ($restored['appointments'] ?? -1),
        );
        self::assertSame($this->providerRolePermissionSnapshot['customers'], (int) ($restored['customers'] ?? -1));
        $this->providerRolePermissionSnapshot = null;
    }
}

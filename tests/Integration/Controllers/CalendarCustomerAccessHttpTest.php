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
    /** @var list<int> */
    private array $foreignCustomerIds = [];
    private ?int $foreignCustomerId = null;
    /** @var list<int> */
    private array $foreignAppointmentIds = [];
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

    public function testPromotedNoViewSessionCanReadCalendarUsingPersistedRole(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $originalRoleId = (int) get_instance()
            ->db->get_where('users', ['id' => $fixture->providerId])
            ->row('id_roles');
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        $adminRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($customerRole);
        self::assertNotEmpty($adminRole);
        self::assertSame(0, (int) $customerRole['appointments'] & PRIV_VIEW);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->providerId],
                ),
            );
            $staleCustomer = $this->login($this->credentials['provider_username'], $this->credentials['password']);
            self::assertSame(403, $staleCustomer->get('calendar/index')->statusCode);

            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $adminRole['id']],
                    ['id' => $fixture->providerId],
                ),
            );
            $promoted = $staleCustomer->get('calendar/index');
            self::assertSame(200, $promoted->statusCode, $promoted->body);
            self::assertStringContainsString('id="insert-appointment"', $promoted->body);
            self::assertStringNotContainsString('id="insert-working-plan-exception" hidden', $promoted->body);

            foreach (
                ['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view']
                as $path
            ) {
                $response = $this->calendarRead($staleCustomer, $path);
                $appointmentIds = array_map(
                    static fn(array $row): int => (int) ($row['id'] ?? 0),
                    $response['appointments'] ?? [],
                );
                self::assertContains((int) $appointment['id'], $appointmentIds, $path);
            }
        } finally {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $originalRoleId], ['id' => $fixture->providerId]),
            );
        }
    }

    public function testDemotedAdminCannotReadCalendarOrOverwriteExistingDestination(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('calendar/index')->statusCode);
        self::assertStringContainsString(
            $fixture->run,
            $admin->get('calendar/index/' . rawurlencode((string) $appointment['hash']))->body,
        );

        $admin->get('about');
        $beforeDestination = $this->sessionDestination($admin);
        self::assertStringEndsWith('/about', $beforeDestination);

        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $originalRoleId = (int) get_instance()
            ->db->get_where('users', ['id' => $fixture->actorId])
            ->row('id_roles');

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );

            foreach (
                ['calendar', 'calendar/index', 'calendar/reschedule/' . rawurlencode((string) $appointment['hash'])]
                as $path
            ) {
                $response = $admin->get($path);
                self::assertSame(403, $response->statusCode, $path);
                self::assertStringNotContainsString($fixture->run, $response->body, $path);
                self::assertSame($beforeDestination, $this->sessionDestination($admin), $path);
            }

            $anonymous = new GateHttpClient($this->server?->baseUrl ?? '', additionalHeaders: ['X-No-Redirect' => '1']);
            $anonymousResponse = $anonymous->get('calendar/reschedule/' . rawurlencode((string) $appointment['hash']));
            self::assertContains($anonymousResponse->statusCode, [302, 307]);
            self::assertStringContainsString('/login', (string) $anonymousResponse->header('location'));
            self::assertStringEndsWith(
                '/calendar/index/' . $appointment['hash'],
                $this->sessionDestination($anonymous),
            );
        } finally {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $originalRoleId], ['id' => $fixture->actorId]),
            );
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

        $client->get('about');
        $beforeForeignDestination = $this->sessionDestination($client);
        self::assertStringEndsWith('/about', $beforeForeignDestination);
        $beforeForeignAppointment = $fixture->row('appointments', (int) $foreignAppointment['id']);
        $foreign = $client->get('calendar/index/' . rawurlencode((string) $foreignAppointment['hash']));
        self::assertSame(403, $foreign->statusCode, $foreign->body);
        self::assertSame($beforeForeignDestination, $this->sessionDestination($client));
        self::assertSame($beforeForeignAppointment, $fixture->row('appointments', (int) $foreignAppointment['id']));
        self::assertStringNotContainsString($this->customerEmail($foreignCustomer), $foreign->body);

        $foreignAlias = $client->get('calendar/reschedule/' . rawurlencode((string) $foreignAppointment['hash']));
        self::assertSame(403, $foreignAlias->statusCode, $foreignAlias->body);
        self::assertSame($beforeForeignDestination, $this->sessionDestination($client));

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
        self::assertStringContainsString('id="insert-appointment"', $adminInitial->body);
        self::assertStringNotContainsString('id="insert-working-plan-exception" hidden', $adminInitial->body);
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
        $providerRole = get_instance()
            ->db->get_where('roles', ['id' => $providerRoleId])
            ->row_array();
        self::assertNotEmpty($providerRole);
        $this->providerRolePermissionSnapshot = [
            'id' => $providerRoleId,
            'appointments' => (int) $providerRole['appointments'],
            'customers' => (int) $providerRole['customers'],
        ];
        $actorAppointment = $this->createForeignAppointment(
            $fixture->actorId,
            $fixture->customerId,
            $fixture->serviceId,
            $fixture->run . '-actor-provider',
        );
        $blockedPeriodId = 0;
        try {
            $blockedStart = date('Y-m-d 12:00:00', strtotime('+14 days'));
            self::assertTrue(
                get_instance()->db->insert('blocked_periods', [
                    'name' => $fixture->run . '-private-block',
                    'start_datetime' => $blockedStart,
                    'end_datetime' => date('Y-m-d 13:00:00', strtotime('+14 days')),
                    'notes' => $fixture->run . '-private-block-notes',
                ]),
            );
            $blockedPeriodId = (int) get_instance()->db->insert_id();
            self::assertTrue(
                get_instance()->db->insert('services_providers', [
                    'id_users' => $fixture->actorId,
                    'id_services' => $fixture->serviceId,
                ]),
            );
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $providerRoleId], ['id' => $fixture->actorId]),
            );
            self::assertTrue(
                get_instance()->db->update(
                    'roles',
                    ['appointments' => $this->providerRolePermissionSnapshot['appointments'] & ~PRIV_ADD],
                    ['id' => $providerRoleId],
                ),
            );
            $staleAdmin = $admin->get('calendar/index');
            self::assertSame(200, $staleAdmin->statusCode, $staleAdmin->body);
            self::assertStringNotContainsString('id="insert-appointment"', $staleAdmin->body);
            self::assertStringNotContainsString('id="insert-working-plan-exception"', $staleAdmin->body);
            self::assertStringNotContainsString($this->customerEmail($foreignCustomer), $staleAdmin->body);
            $staleAdminVars = $this->scriptVars($staleAdmin->body);
            $availableProviderIds = array_map(
                static fn(array $provider): int => (int) ($provider['id'] ?? 0),
                $staleAdminVars['available_providers'] ?? [],
            );
            self::assertSame([$fixture->actorId], $availableProviderIds);

            self::assertTrue(
                get_instance()->db->update(
                    'roles',
                    ['appointments' => $this->providerRolePermissionSnapshot['appointments']],
                    ['id' => $providerRoleId],
                ),
            );
            $providerWithAdd = $admin->get('calendar/index');
            self::assertSame(200, $providerWithAdd->statusCode, $providerWithAdd->body);
            self::assertStringContainsString('id="insert-appointment"', $providerWithAdd->body);
            self::assertStringContainsString('id="insert-working-plan-exception" hidden', $providerWithAdd->body);

            // The browser session still carries the former admin role. A current
            // provider role must nevertheless prevent mutation of another
            // provider's appointment, without changing the stored row.
            $beforeForeignMutation = $fixture->row('appointments', (int) $foreignAppointment['id']);
            $deleteForeign = $admin->post('calendar/delete_appointment', [
                'appointment_id' => (string) $foreignAppointment['id'],
            ]);
            self::assertSame(403, $deleteForeign->statusCode, $deleteForeign->body);
            self::assertSame($beforeForeignMutation, $fixture->row('appointments', (int) $foreignAppointment['id']));

            foreach (
                ['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view']
                as $path
            ) {
                $response = $this->calendarRead($admin, $path);
                $appointmentIds = array_map(
                    static fn(array $appointment): int => (int) ($appointment['id'] ?? 0),
                    $response['appointments'] ?? [],
                );
                self::assertSame([(int) $actorAppointment['id']], $appointmentIds, $path);
                foreach ($response['appointments'] ?? [] as $appointment) {
                    self::assertSame($fixture->actorId, (int) ($appointment['id_users_provider'] ?? 0), $path);
                }
                $blockedPeriods = array_values(
                    array_filter(
                        $response['blocked_periods'] ?? [],
                        static fn(array $period): bool => (int) ($period['id'] ?? 0) === $blockedPeriodId,
                    ),
                );
                self::assertCount(1, $blockedPeriods, $path);
                self::assertArrayNotHasKey('notes', $blockedPeriods[0], $path);
            }
        } finally {
            get_instance()->db->update('users', ['id_roles' => $adminRoleId], ['id' => $fixture->actorId]);
            get_instance()->db->delete('services_providers', [
                'id_users' => $fixture->actorId,
                'id_services' => $fixture->serviceId,
            ]);
            if ($blockedPeriodId > 0) {
                get_instance()->db->delete('blocked_periods', ['id' => $blockedPeriodId]);
                self::assertSame(
                    0,
                    get_instance()
                        ->db->get_where('blocked_periods', ['id' => $blockedPeriodId])
                        ->num_rows(),
                );
            }
        }

        // The inverse transition must also use the persisted role: a provider
        // session promoted to admin must be allowed to delete the foreign
        // appointment it could not mutate before the promotion.
        $staleProvider = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        $beforePromotedMutation = $fixture->row('appointments', (int) $foreignAppointment['id']);
        self::assertNotEmpty($beforePromotedMutation);
        try {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $adminRoleId], ['id' => $fixture->providerId]),
            );

            $promoted = $staleProvider->get('calendar/index');
            self::assertSame(200, $promoted->statusCode, $promoted->body);
            self::assertStringContainsString('id="insert-appointment"', $promoted->body);
            self::assertStringNotContainsString('id="insert-working-plan-exception" hidden', $promoted->body);

            // The protected mutation follows the role change while this
            // session still carries the provider role.
            $createAfterPromotion = $staleProvider->post('calendar/save_appointment', [
                'appointment_data' => [
                    'start_datetime' => date('Y-m-d 12:00:00', strtotime('+14 days')),
                    'end_datetime' => date('Y-m-d 12:30:00', strtotime('+14 days')),
                    'notes' => $fixture->run . '-promoted-create',
                    'id_users_provider' => $foreignProviderId,
                    'id_users_customer' => $fixture->customerId,
                    'id_services' => $fixture->serviceId,
                    'is_unavailability' => false,
                ],
                'customer_data' => [],
            ]);
            self::assertSame(200, $createAfterPromotion->statusCode, $createAfterPromotion->body);
            self::assertTrue(
                (bool) (json_decode($createAfterPromotion->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false),
            );
            $createdAfterPromotion = get_instance()
                ->db->get_where('appointments', ['notes' => $fixture->run . '-promoted-create'])
                ->row_array();
            self::assertNotEmpty($createdAfterPromotion);
            $this->foreignAppointmentIds[] = (int) $createdAfterPromotion['id'];

            $deleteForeignAfterPromotion = $staleProvider->post('calendar/delete_appointment', [
                'appointment_id' => (string) $foreignAppointment['id'],
            ]);
            self::assertSame(200, $deleteForeignAfterPromotion->statusCode, $deleteForeignAfterPromotion->body);
            self::assertTrue(
                (bool) (json_decode($deleteForeignAfterPromotion->body, true, 512, JSON_THROW_ON_ERROR)['success'] ??
                    false),
            );
            self::assertSame([], $fixture->row('appointments', (int) $foreignAppointment['id']));
        } finally {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $providerRoleId], ['id' => $fixture->providerId]),
            );
            self::assertSame(
                $providerRoleId,
                (int) get_instance()
                    ->db->get_where('users', ['id' => $fixture->providerId])
                    ->row('id_roles'),
            );
        }
    }

    public function testLimitedProviderScopesBeforeTopFiftyCustomerLimit(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $ownedCustomer = $fixture->customerId;
        $fixture->appointment();

        for ($index = 0; $index < 51; $index++) {
            $this->createForeignCustomer(
                $fixture->run . '-top50-' . $index,
                date('Y-m-d H:i:s', strtotime('2099-01-01 +' . $index . ' seconds')),
            );
        }

        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        $initial = $client->get('calendar/index');
        self::assertSame(200, $initial->statusCode, $initial->body);
        $initialVars = $this->scriptVars($initial->body);
        $this->assertSafeCustomerProjection($initialVars, $this->customerEmail($ownedCustomer));
        foreach ($this->foreignCustomerIds as $foreignCustomerId) {
            self::assertStringNotContainsString($this->customerEmail($foreignCustomerId), $initial->body);
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

    public function testPersistedCustomerRevocationRedactsOwnAppointmentFromBothCalendarFeeds(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $beforeAppointment = $fixture->row('appointments', (int) $appointment['id']);
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null);
        self::assertGreaterThanOrEqual(PRIV_VIEW, (int) ($role['appointments'] ?? 0));
        $this->providerRolePermissionSnapshot = [
            'id' => (int) $role['id'],
            'appointments' => (int) $role['appointments'],
            'customers' => (int) $role['customers'],
        ];

        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        try {
            self::assertTrue(
                get_instance()->db->update(
                    'roles',
                    ['customers' => 0],
                    ['id' => $this->providerRolePermissionSnapshot['id']],
                ),
            );

            foreach (
                ['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view']
                as $path
            ) {
                $response = $this->calendarRead($client, $path);
                self::assertCount(1, $response['appointments'] ?? [], $path);
                $row = $response['appointments'][0];
                self::assertSame((int) $appointment['id'], (int) ($row['id'] ?? 0), $path);
                self::assertSame([], $row['customer'] ?? null, $path);
                self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId), $path);
                self::assertSame($beforeAppointment, $fixture->row('appointments', (int) $appointment['id']), $path);
            }
        } finally {
            $this->restoreProviderRolePermissions();
        }
    }

    public function testPermittedProviderCalendarFeedsUseUiAppointmentAndCustomerProjections(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $appointmentInternal = $fixture->run . '-appointment-internal';
        $customerInternal = $fixture->run . '-customer-internal';
        $customerState = $fixture->run . '-region';
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                [
                    'hash' => $fixture->run . '-calendar-sensitive-hash',
                    'id_google_calendar' => $appointmentInternal,
                    'id_caldav_calendar' => $fixture->run . '-caldav-internal',
                ],
                ['id' => (int) $appointment['id']],
            ),
        );
        self::assertTrue(
            get_instance()->db->update(
                'users',
                ['ldap_dn' => $customerInternal, 'state' => $customerState],
                ['id' => $fixture->customerId],
            ),
        );
        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);

        foreach (['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view'] as $path) {
            $response = $this->calendarRead($client, $path);
            self::assertCount(1, $response['appointments'] ?? [], $path);
            $row = $response['appointments'][0];
            $this->assertCalendarUiAppointmentProjection($row, $path);
            self::assertSame((int) $appointment['id'], (int) $row['id'], $path);
            self::assertSame($this->customerEmail($fixture->customerId), $row['customer']['email'], $path);
            self::assertSame($customerState, $row['customer']['state'], $path);
            self::assertStringNotContainsString($appointmentInternal, json_encode($row, JSON_THROW_ON_ERROR), $path);
            self::assertStringNotContainsString($customerInternal, json_encode($row, JSON_THROW_ON_ERROR), $path);
        }
    }

    public function testCalendarFeedsProjectGeneratedUnavailabilityWithoutCapabilityFields(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $parent = $fixture->appointment();
        get_instance()->load->model('unavailabilities_model');
        $unavailabilityId = (int) get_instance()->unavailabilities_model->save([
            'start_datetime' => date('Y-m-d 11:00:00', strtotime('+14 days')),
            'end_datetime' => date('Y-m-d 11:30:00', strtotime('+14 days')),
            'notes' => $fixture->run . '-unavailability',
            'id_users_provider' => $fixture->providerId,
        ]);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'appointments',
                    [
                        'id_parent_appointment' => (int) $parent['id'],
                        'id_google_calendar' => $fixture->run . '-internal-calendar-id',
                    ],
                    ['id' => $unavailabilityId],
                ),
            );
            $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);

            foreach (
                ['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view']
                as $path
            ) {
                $response = $this->calendarRead($client, $path);
                $rows = array_values(
                    array_filter(
                        $response['unavailabilities'] ?? [],
                        static fn(array $row): bool => (int) ($row['id'] ?? 0) === $unavailabilityId,
                    ),
                );
                self::assertCount(1, $rows, $path);
                $keys = array_keys($rows[0]);
                sort($keys);
                self::assertSame(
                    [
                        'end_datetime',
                        'id',
                        'id_parent_appointment',
                        'id_users_provider',
                        'is_unavailability',
                        'notes',
                        'provider',
                        'start_datetime',
                    ],
                    $keys,
                    $path,
                );
                self::assertSame((int) $parent['id'], (int) $rows[0]['id_parent_appointment'], $path);
            }
        } finally {
            get_instance()->db->delete('appointments', [
                'id' => $unavailabilityId,
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => true,
            ]);
            self::assertSame([], $fixture->row('appointments', $unavailabilityId));
        }
    }

    public function testLegacyCalendarFeedAliasesDoNotExposeAppointmentOrCustomerData(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $beforeAppointment = $fixture->row('appointments', (int) $appointment['id']);
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);

        foreach (['backend_api/ajax_get_calendar_appointments', 'backend_api/ajax_get_calendar_events'] as $path) {
            // The legacy POST redirects to the canonical route. The redirected
            // request currently fails the rotated CSRF token before data access.
            $response = $client->post($path, [
                'start_date' => date('Y-m-d', strtotime('+13 days')),
                'end_date' => date('Y-m-d', strtotime('+15 days')),
                'record_id' => FILTER_TYPE_ALL,
                'filter_type' => '',
                'is_all' => '1',
            ]);
            self::assertSame(403, $response->statusCode, $path);
            self::assertStringNotContainsString((string) $appointment['hash'], $response->body, $path);
            self::assertStringNotContainsString($this->customerEmail($fixture->customerId), $response->body, $path);
            self::assertSame($beforeAppointment, $fixture->row('appointments', (int) $appointment['id']), $path);
            self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId), $path);
        }
    }

    public function testSecretaryCalendarFeedsIncludeAssignedProviderOnly(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $fixture->appointment();
        $foreignProviderId = $this->foreignProviderId($fixture->providerId);
        $foreignCustomerId = $this->createForeignCustomer($fixture->run . '-secretary-feed');
        $foreignAppointment = $this->createForeignAppointment(
            $foreignProviderId,
            $foreignCustomerId,
            $fixture->serviceId,
            $fixture->run . '-secretary-feed',
        );
        [$username, $password] = $this->createSecretary($fixture->providerId, $fixture->run . '-feed');
        $client = $this->login($username, $password);

        foreach (['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view'] as $path) {
            $response = $this->calendarRead($client, $path);
            $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $response['appointments'] ?? []);
            self::assertNotContains((int) $foreignAppointment['id'], $ids, $path);
            self::assertCount(1, $ids, $path);
        }
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

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $path = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/dest_url\\|s:\\d+:"([^"]*)";/', $contents, $matches));

        return $matches[1];
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

    private function createForeignCustomer(string $run, ?string $updateDatetime = null): int
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
                'update_datetime' => $updateDatetime ?? $now,
            ]),
        );
        $this->foreignCustomerId = (int) get_instance()->db->insert_id();
        $this->foreignCustomerIds[] = $this->foreignCustomerId;

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
        $this->foreignAppointmentIds[] = $this->foreignAppointmentId;

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

    /** @return array<string, mixed> */
    private function calendarRead(GateHttpClient $client, string $path): array
    {
        $response = $client->post($path, [
            'start_date' => date('Y-m-d', strtotime('+13 days')),
            'end_date' => date('Y-m-d', strtotime('+15 days')),
            'record_id' => FILTER_TYPE_ALL,
            'filter_type' => '',
            'is_all' => '1',
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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
    private function assertCustomerReadKeys(array $customer, bool $includeState = false): void
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
        if ($includeState) {
            $expected[] = 'state';
        }
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }

    /** @param array<string, mixed> $appointment */
    private function assertCalendarUiAppointmentProjection(array $appointment, string $path): void
    {
        $expected = [
            'color',
            'customer',
            'end_datetime',
            'id',
            'id_services',
            'id_users_customer',
            'id_users_provider',
            'is_unavailability',
            'location',
            'notes',
            'provider',
            'service',
            'start_datetime',
            'status',
        ];
        $actual = array_keys($appointment);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, $path);
        self::assertIsArray($appointment['customer'] ?? null, $path);
        $this->assertCustomerReadKeys($appointment['customer'], true);
        self::assertIsArray($appointment['provider'] ?? null, $path);
        $providerKeys = array_keys($appointment['provider']);
        sort($providerKeys);
        self::assertSame(
            ['address', 'city', 'first_name', 'id', 'last_name', 'settings', 'state', 'timezone', 'zip_code'],
            $providerKeys,
            $path,
        );
        $settingKeys = array_keys($appointment['provider']['settings']);
        sort($settingKeys);
        self::assertSame(['working_plan', 'working_plan_exceptions'], $settingKeys, $path);
        self::assertSame(['id', 'name'], array_keys($appointment['service']), $path);
    }

    private function deleteForeignRows(): void
    {
        try {
            foreach (array_unique($this->foreignAppointmentIds) as $foreignAppointmentId) {
                get_instance()->db->delete('reschedule_authorities', ['appointment_id' => $foreignAppointmentId]);
                get_instance()->db->delete('appointments', ['id' => $foreignAppointmentId]);
            }
        } finally {
            try {
                foreach (array_unique($this->foreignCustomerIds) as $foreignCustomerId) {
                    get_instance()->db->delete('users', ['id' => $foreignCustomerId]);
                }
            } finally {
                if ($this->secretaryId !== null) {
                    get_instance()->db->delete('secretaries_providers', ['id_users_secretary' => $this->secretaryId]);
                    get_instance()->db->delete('user_settings', ['id_users' => $this->secretaryId]);
                    get_instance()->db->delete('users', ['id' => $this->secretaryId]);
                }
            }
        }

        foreach (array_unique($this->foreignAppointmentIds) as $foreignAppointmentId) {
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('appointments', ['id' => $foreignAppointmentId])
                    ->num_rows(),
            );
        }
        foreach (array_unique($this->foreignCustomerIds) as $foreignCustomerId) {
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('users', ['id' => $foreignCustomerId])
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
        $this->foreignAppointmentIds = [];
        $this->foreignCustomerIds = [];
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

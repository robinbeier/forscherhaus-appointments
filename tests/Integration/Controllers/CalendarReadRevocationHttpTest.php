<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP regression for persisted calendar-read revocation in an existing session. */
final class CalendarReadRevocationHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    /** @var array<int,array{appointments:int}> */
    private array $roleSnapshots = [];

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
            $this->server?->close();
        } finally {
            try {
                $this->restoreRolePermissions();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testProviderSessionReturnsForbiddenAfterPersistedAppointmentsViewRevocation(): void
    {
        $this->assertRevocationIsForbiddenInExistingSession('provider');
    }

    public function testAdminSessionReturnsForbiddenAfterPersistedAppointmentsViewRevocation(): void
    {
        $this->assertRevocationIsForbiddenInExistingSession('admin');
    }

    public function testPermissionDecoderHonorsEveryAppointmentsBitmaskCombination(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null, 'Synthetic provider role is required.');
        $roleId = (int) $role['id'];
        $this->roleSnapshots[$roleId] = ['appointments' => (int) $role['appointments']];
        get_instance()->load->model('roles_model');

        $allPermissions = PRIV_VIEW | PRIV_ADD | PRIV_EDIT | PRIV_DELETE;
        foreach (array_merge([-1], range(0, $allPermissions), [16, 18, 20, 24, 31]) as $mask) {
            self::assertTrue(get_instance()->db->update('roles', ['appointments' => $mask], ['id' => $roleId]));
            $permissions = get_instance()->roles_model->get_permissions_by_slug(DB_SLUG_PROVIDER)['appointments'];
            $effectiveMask = $mask >= 0 && ($mask & ~$allPermissions) === 0 ? $mask : 0;
            self::assertSame(($effectiveMask & PRIV_VIEW) === PRIV_VIEW, $permissions['view'], 'view mask ' . $mask);
            self::assertSame(($effectiveMask & PRIV_ADD) === PRIV_ADD, $permissions['add'], 'add mask ' . $mask);
            self::assertSame(($effectiveMask & PRIV_EDIT) === PRIV_EDIT, $permissions['edit'], 'edit mask ' . $mask);
            self::assertSame(
                ($effectiveMask & PRIV_DELETE) === PRIV_DELETE,
                $permissions['delete'],
                'delete mask ' . $mask,
            );
        }
    }

    private function assertRevocationIsForbiddenInExistingSession(string $actor): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $beforeAppointment = $fixture->row('appointments', (int) $appointment['id']);
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $roleSlug = $actor === 'provider' ? DB_SLUG_PROVIDER : DB_SLUG_ADMIN;
        $role = get_instance()
            ->db->get_where('roles', ['slug' => $roleSlug])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null, $roleSlug . ' role is required.');
        $originalAppointments = (int) $role['appointments'];
        $nonViewPermissions = PRIV_ADD | PRIV_EDIT | PRIV_DELETE;
        self::assertSame(
            PRIV_VIEW,
            $originalAppointments & PRIV_VIEW,
            $roleSlug . ' fixture must start with appointments.view.',
        );
        self::assertNotSame(
            0,
            $originalAppointments & $nonViewPermissions,
            $roleSlug . ' fixture must preserve at least one non-view appointment permission.',
        );
        $this->roleSnapshots[(int) $role['id']] = ['appointments' => $originalAppointments];

        $client = $this->login(
            $actor === 'provider' ? $this->credentials['provider_username'] : $this->credentials['admin_username'],
        );

        foreach ($this->calendarPaths() as $path) {
            $positive = $this->calendarRead($client, $path);
            $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $positive['appointments'] ?? []);
            self::assertContains((int) $appointment['id'], $ids, $path . ' positive control');
        }

        $revokedAppointments = $originalAppointments & ~PRIV_VIEW;
        self::assertTrue(
            get_instance()->db->update('roles', ['appointments' => $revokedAppointments], ['id' => (int) $role['id']]),
        );
        self::assertSame(
            $revokedAppointments,
            (int) get_instance()
                ->db->get_where('roles', ['id' => (int) $role['id']])
                ->row('appointments'),
            $roleSlug . ' role mask was not persisted exactly.',
        );

        foreach ($this->calendarPaths() as $path) {
            $response = $client->post($path, $this->rangePayload());
            self::assertSame(403, $response->statusCode, $path . ': ' . $response->body);
            $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(
                ['success' => false, 'message' => 'You do not have the required permissions for this task.'],
                $body,
                $path,
            );
            self::assertStringNotContainsString((string) $appointment['hash'], $response->body, $path);
            self::assertStringNotContainsString($fixture->run, $response->body, $path);
            self::assertStringNotContainsString($this->customerEmail($fixture->customerId), $response->body, $path);
            self::assertStringNotContainsString('appointments', strtolower($response->body), $path);
            self::assertStringNotContainsString('customer', strtolower($response->body), $path);
            self::assertStringNotContainsString('capabilit', strtolower($response->body), $path);
            self::assertSame($beforeAppointment, $fixture->row('appointments', (int) $appointment['id']), $path);
            self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId), $path);
        }
    }

    /** @return list<string> */
    private function calendarPaths(): array
    {
        return ['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view'];
    }

    private function login(string $username): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $username,
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));

        return $client;
    }

    /** @return array<string,mixed> */
    private function calendarRead(GateHttpClient $client, string $path): array
    {
        $response = $client->post($path, $this->rangePayload());
        self::assertSame(200, $response->statusCode, $path . ': ' . $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /** @return array<string,string> */
    private function rangePayload(): array
    {
        return [
            'start_date' => date('Y-m-d', strtotime('+13 days')),
            'end_date' => date('Y-m-d', strtotime('+15 days')),
            'record_id' => FILTER_TYPE_ALL,
            'filter_type' => '',
            'is_all' => '1',
        ];
    }

    private function customerEmail(int $customerId): string
    {
        return (string) get_instance()
            ->db->get_where('users', ['id' => $customerId])
            ->row('email');
    }

    private function restoreRolePermissions(): void
    {
        foreach ($this->roleSnapshots as $roleId => $snapshot) {
            self::assertTrue(get_instance()->db->update('roles', $snapshot, ['id' => $roleId]));
            $restoredRole = get_instance()
                ->db->get_where('roles', ['id' => $roleId])
                ->row_array();
            self::assertSame($snapshot['appointments'], (int) ($restoredRole['appointments'] ?? -1));
        }
        $this->roleSnapshots = [];
    }
}

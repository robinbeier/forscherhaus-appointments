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
        $this->roleSnapshots[(int) $role['id']] = ['appointments' => (int) $role['appointments']];

        $client = $this->login(
            $actor === 'provider' ? $this->credentials['provider_username'] : $this->credentials['admin_username'],
        );

        foreach ($this->calendarPaths() as $path) {
            $positive = $this->calendarRead($client, $path);
            $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $positive['appointments'] ?? []);
            self::assertContains((int) $appointment['id'], $ids, $path . ' positive control');
        }

        self::assertTrue(get_instance()->db->update('roles', ['appointments' => 0], ['id' => (int) $role['id']]));

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
            get_instance()->db->update('roles', $snapshot, ['id' => $roleId]);
        }
        $this->roleSnapshots = [];
    }
}

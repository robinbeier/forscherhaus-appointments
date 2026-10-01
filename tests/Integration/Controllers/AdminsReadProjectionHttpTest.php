<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for legacy Admins reads and current stored authority. */
final class AdminsReadProjectionHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $adminRoleSnapshot = null;

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
                $this->restoreAdminRole();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAdminIndexSearchAndFindExposeOnlySafeAdminProjectionAcrossRouteForms(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        self::assertTrue(
            $db->update(
                'user_settings',
                [
                    'google_token' => $fixture->run . '_google_integration',
                    'caldav_password' => $fixture->run . '_caldav_integration',
                ],
                ['id_users' => $fixture->actorId],
            ),
        );
        $storedSettings = $fixture->userSettingsRow($fixture->actorId);
        self::assertSame($fixture->run . '_google_integration', $storedSettings['google_token']);
        self::assertSame($fixture->run . '_caldav_integration', $storedSettings['caldav_password']);
        $before = $fixture->row('users', $fixture->actorId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);

        foreach (['admins', 'admins/index'] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path);
            self::assertStringNotContainsString($fixture->run . '_google_integration', $response->body);
            self::assertStringNotContainsString($fixture->run . '_caldav_integration', $response->body);
        }

        foreach (
            [
                'POST admins/search' => $admin->post('admins/search', ['keyword' => $fixture->run]),
                'GET admins/search' => $admin->get('admins/search', ['keyword' => $fixture->run]),
                'GET alias' => $admin->get('backend_api/ajax_filter_admins', ['keyword' => $fixture->run]),
            ]
            as $case => $response
        ) {
            $rows = $this->decodeList($response, $case);
            $matches = array_values(
                array_filter(
                    $rows,
                    static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $fixture->actorId,
                ),
            );
            self::assertCount(1, $matches, $case);
            $this->assertSafeAdminProjection($matches[0], $fixture->actorId);
            $this->assertNoIntegrationSecrets($response->body);
        }

        $postAlias = $admin->post('backend_api/ajax_filter_admins', ['keyword' => $fixture->run]);
        self::assertSame(403, $postAlias->statusCode);
        self::assertStringNotContainsString($fixture->run, $postAlias->body);

        foreach (
            [
                'POST admins/find' => $admin->post('admins/find', ['admin_id' => $fixture->actorId]),
                'GET admins/find' => $admin->get('admins/find', ['admin_id' => $fixture->actorId]),
            ]
            as $case => $response
        ) {
            $found = $this->decodeObject($response, $case);
            $this->assertSafeAdminProjection($found, $fixture->actorId);
            $this->assertNoIntegrationSecrets($response->body);
        }

        self::assertSame($before, $fixture->row('users', $fixture->actorId));
    }

    public function testFindRejectsProviderAndCustomerIdsWithoutReturningTheirData(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $beforeProvider = $fixture->row('users', $fixture->providerId);
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);

        foreach ([$fixture->providerId, $fixture->customerId] as $id) {
            $response = $admin->post('admins/find', ['admin_id' => $id]);
            self::assertSame(404, $response->statusCode);
            self::assertStringNotContainsString($fixture->run, $response->body);
        }

        self::assertSame($beforeProvider, $fixture->row('users', $fixture->providerId));
        self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
    }

    public function testStoredUsersCapabilityRevocationDeniesIndexSearchAndFindOnCanonicalAndAliasRoutes(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->adminRoleSnapshot = $this->snapshotAdminRole();
        $adminBefore = $fixture->row('users', $fixture->actorId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->post('admins/find', ['admin_id' => $fixture->actorId])->statusCode);

        self::assertTrue(get_instance()->db->update('roles', ['users' => 0], ['id' => $this->adminRoleSnapshot['id']]));
        foreach (
            [
                'GET admins' => $admin->get('admins'),
                'GET admins/index' => $admin->get('admins/index'),
                'POST admins/search' => $admin->post('admins/search', ['keyword' => $fixture->run]),
                'GET admins/search' => $admin->get('admins/search', ['keyword' => $fixture->run]),
                'GET alias' => $admin->get('backend_api/ajax_filter_admins', ['keyword' => $fixture->run]),
                'POST admins/find' => $admin->post('admins/find', ['admin_id' => $fixture->actorId]),
                'GET admins/find' => $admin->get('admins/find', ['admin_id' => $fixture->actorId]),
            ]
            as $case => $response
        ) {
            self::assertSame(403, $response->statusCode, $case);
            self::assertStringNotContainsString($fixture->run, $response->body, $case);
        }

        self::assertSame($adminBefore, $fixture->row('users', $fixture->actorId));
    }

    public function testStoredActorRoleChangeDeniesAllAdminReadsWithoutSessionDestinationDrift(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $adminBefore = $fixture->row('users', $fixture->actorId);
        $settingsBefore = $fixture->userSettingsRow($fixture->actorId);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
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
            foreach (
                [
                    'GET admins' => $admin->get('admins'),
                    'GET admins/index' => $admin->get('admins/index'),
                    'POST admins/search' => $admin->post('admins/search', ['keyword' => $fixture->run]),
                    'GET admins/search' => $admin->get('admins/search', ['keyword' => $fixture->run]),
                    'GET alias' => $admin->get('backend_api/ajax_filter_admins', ['keyword' => $fixture->run]),
                    'POST admins/find' => $admin->post('admins/find', ['admin_id' => $fixture->actorId]),
                    'GET admins/find' => $admin->get('admins/find', ['admin_id' => $fixture->actorId]),
                ]
                as $case => $response
            ) {
                self::assertSame(403, $response->statusCode, $case);
                self::assertStringNotContainsString($fixture->run, $response->body, $case);
            }
            self::assertSame($beforeDestination, $this->sessionDestination($admin));
            self::assertSame($settingsBefore, $fixture->userSettingsRow($fixture->actorId));
        } finally {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $adminBefore['id_roles']],
                    ['id' => $fixture->actorId],
                ),
            );
        }

        self::assertSame($adminBefore, $fixture->row('users', $fixture->actorId));
    }

    /** @return array<string,mixed> */
    private function snapshotAdminRole(): array
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null, 'Synthetic admin role is required.');
        return ['id' => (int) $role['id'], 'users' => (int) ($role['users'] ?? 0)];
    }

    private function restoreAdminRole(): void
    {
        if ($this->adminRoleSnapshot !== null) {
            get_instance()->db->update(
                'roles',
                ['users' => $this->adminRoleSnapshot['users']],
                ['id' => $this->adminRoleSnapshot['id']],
            );
        }
    }

    /** @param array<string,mixed> $admin */
    private function assertSafeAdminProjection(array $admin, int $adminId): void
    {
        self::assertSame($adminId, (int) ($admin['id'] ?? 0));
        $expected = [
            'address',
            'city',
            'email',
            'first_name',
            'id',
            'language',
            'ldap_dn',
            'last_name',
            'mobile_number',
            'notes',
            'phone_number',
            'settings',
            'state',
            'timezone',
            'zip_code',
        ];
        $actual = array_keys($admin);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertIsArray($admin['settings'] ?? null);
        $expectedSettings = ['calendar_view', 'username'];
        $actualSettings = array_keys($admin['settings']);
        sort($expectedSettings);
        sort($actualSettings);
        self::assertSame($expectedSettings, $actualSettings);
    }

    private function assertNoIntegrationSecrets(string $body): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertStringNotContainsString($fixture->run . '_google_integration', $body);
        self::assertStringNotContainsString($fixture->run . '_caldav_integration', $body);
    }

    /** @return list<array<string,mixed>> */
    private function decodeList(GateHttpResponse $response, string $case): array
    {
        self::assertSame(200, $response->statusCode, $case);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return array_values($data);
    }

    /** @return array<string,mixed> */
    private function decodeObject(GateHttpResponse $response, string $case): array
    {
        self::assertSame(200, $response->statusCode, $case);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function login(string $username, string $password): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $response->statusCode);
        return $client;
    }

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $sessionPath = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($sessionPath);
        $contents = file_get_contents($sessionPath);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/dest_url\\|s:\\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

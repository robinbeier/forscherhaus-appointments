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

/** Bounded HTTP coverage for legacy Secretaries reads and current authority. */
final class SecretariesReadProjectionHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?int $secretaryId = null;
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
            $this->createSecretaryFixture();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->cleanupSecretaryFixture();
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
                try {
                    $this->cleanupSecretaryFixture();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
        }
    }

    public function testAnonymousSecretaryDeepLinksKeepTheirLoginReturnTarget(): void
    {
        $server = $this->server;
        self::assertNotNull($server);

        foreach (['secretaries', 'secretaries/index'] as $path) {
            // Keep the first response visible so the client's redirect handling
            // cannot replace the new anonymous session before asserting it.
            $guest = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'secretary-deep-link']);
            $response = $guest->get($path);
            self::assertSame(307, $response->statusCode, $path);
            self::assertStringContainsString('/login', (string) $response->header('location'));
            self::assertStringEndsWith('/secretaries', $this->sessionDestination($guest));

            $login = $guest->get('login');
            self::assertSame(200, $login->statusCode);
            self::assertStringContainsString('/secretaries', $login->body);
        }
    }

    public function testAdminReadsSecretaryProjectionAcrossCanonicalAndLegacyRoutes(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $secretaryId = $this->secretaryId;
        self::assertNotNull($secretaryId);
        $before = $fixture->row('users', $secretaryId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);

        foreach (['secretaries', 'secretaries/index'] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path);
            $this->assertNoIntegrationSecrets($response->body);
        }

        foreach (
            [
                'POST secretaries/search' => $admin->post('secretaries/search', ['keyword' => $fixture->run]),
                'GET secretaries/search' => $admin->get('secretaries/search', ['keyword' => $fixture->run]),
                'GET alias' => $admin->get('backend_api/ajax_filter_secretaries', ['keyword' => $fixture->run]),
            ]
            as $case => $response
        ) {
            $rows = $this->decodeList($response, $case);
            $matches = array_values(
                array_filter(
                    $rows,
                    static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $secretaryId,
                ),
            );
            self::assertCount(1, $matches, $case);
            $this->assertSafeSecretaryProjection($matches[0], $secretaryId, $fixture->providerId);
            $this->assertNoIntegrationSecrets($response->body);
        }

        $postAlias = $admin->post('backend_api/ajax_filter_secretaries', ['keyword' => $fixture->run]);
        self::assertSame(403, $postAlias->statusCode);
        self::assertStringNotContainsString($fixture->run, $postAlias->body);

        foreach (
            [
                'POST secretaries/find' => $admin->post('secretaries/find', ['secretary_id' => $secretaryId]),
                'GET secretaries/find' => $admin->get('secretaries/find', ['secretary_id' => $secretaryId]),
            ]
            as $case => $response
        ) {
            $found = $this->decodeObject($response, $case);
            $this->assertSafeSecretaryProjection($found, $secretaryId, $fixture->providerId);
            $this->assertNoIntegrationSecrets($response->body);
        }

        self::assertSame($before, $fixture->row('users', $secretaryId));
    }

    public function testFindRejectsAdminProviderAndCustomerIdsWithoutReturningTheirData(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);

        foreach ([$fixture->actorId, $fixture->providerId, $fixture->customerId] as $id) {
            $before = $fixture->row('users', $id);
            foreach (['POST', 'GET'] as $method) {
                $response =
                    $method === 'POST'
                        ? $admin->post('secretaries/find', ['secretary_id' => $id])
                        : $admin->get('secretaries/find', ['secretary_id' => $id]);
                self::assertSame(404, $response->statusCode, $method . ' ID ' . $id);
                self::assertStringNotContainsString($fixture->run, $response->body);
            }
            self::assertSame($before, $fixture->row('users', $id));
        }
    }

    public function testStoredCapabilityRevocationDeniesSecretaryReadsWithoutMutationOrSessionDrift(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $secretaryId = $this->secretaryId;
        self::assertNotNull($secretaryId);
        $this->adminRoleSnapshot = $this->snapshotAdminRole();
        $beforeSecretary = $fixture->row('users', $secretaryId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);
        $beforeState = $this->readState($secretaryId, $fixture->actorId);

        try {
            self::assertTrue(
                get_instance()->db->update('roles', ['users' => 0], ['id' => $this->adminRoleSnapshot['id']]),
            );
            foreach ($this->readRequests($admin, $secretaryId, $fixture->run) as $case => $response) {
                self::assertSame(403, $response->statusCode, $case);
                self::assertStringNotContainsString($fixture->run, $response->body, $case);
            }
            self::assertSame($beforeDestination, $this->sessionDestination($admin));
        } finally {
            $this->restoreAdminRole();
        }

        self::assertSame($beforeSecretary, $fixture->row('users', $secretaryId));
        self::assertSame($beforeState, $this->readState($secretaryId, $fixture->actorId));
    }

    public function testStoredActorRoleChangeDeniesSecretaryReadsWithoutMutationOrSessionDrift(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $secretaryId = $this->secretaryId;
        self::assertNotNull($secretaryId);
        $adminBefore = $fixture->row('users', $fixture->actorId);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);
        $beforeState = $this->readState($secretaryId, $fixture->actorId);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );
            foreach ($this->readRequests($admin, $secretaryId, $fixture->run) as $case => $response) {
                self::assertSame(403, $response->statusCode, $case);
                self::assertStringNotContainsString($fixture->run, $response->body, $case);
            }
            self::assertSame($beforeDestination, $this->sessionDestination($admin));
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
        self::assertSame($beforeState, $this->readState($secretaryId, $fixture->actorId));
    }

    /** @return array<string,mixed> */
    private function readState(int $secretaryId, int $actorId): array
    {
        $db = get_instance()->db;

        return [
            'secretary_settings' => $db->get_where('user_settings', ['id_users' => $secretaryId])->row_array(),
            'actor_settings' => $db->get_where('user_settings', ['id_users' => $actorId])->row_array(),
            'provider_assignments' => $db
                ->get_where('secretaries_providers', ['id_users_secretary' => $secretaryId])
                ->result_array(),
        ];
    }

    /** @return array<string,GateHttpResponse> */
    private function readRequests(GateHttpClient $admin, int $secretaryId, string $keyword): array
    {
        return [
            'GET secretaries' => $admin->get('secretaries'),
            'GET secretaries/index' => $admin->get('secretaries/index'),
            'POST secretaries/search' => $admin->post('secretaries/search', ['keyword' => $keyword]),
            'GET secretaries/search' => $admin->get('secretaries/search', ['keyword' => $keyword]),
            'GET alias' => $admin->get('backend_api/ajax_filter_secretaries', ['keyword' => $keyword]),
            'POST alias' => $admin->post('backend_api/ajax_filter_secretaries', ['keyword' => $keyword]),
            'POST secretaries/find' => $admin->post('secretaries/find', ['secretary_id' => $secretaryId]),
            'GET secretaries/find' => $admin->get('secretaries/find', ['secretary_id' => $secretaryId]),
        ];
    }

    private function createSecretaryFixture(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null);
        $username = $fixture->run . '_secretary';
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
                'password' => hash_password($salt, $fixture->password),
                'salt' => $salt,
                'notifications' => 0,
                'google_token' => $fixture->run . '_secretary_google_integration',
                'caldav_password' => $fixture->run . '_secretary_caldav_integration',
            ]),
        );
        self::assertTrue(
            get_instance()->db->insert('secretaries_providers', [
                'id_users_secretary' => $this->secretaryId,
                'id_users_provider' => $fixture->providerId,
            ]),
        );
    }

    private function cleanupSecretaryFixture(): void
    {
        if ($this->secretaryId === null || $this->fixture === null) {
            return;
        }
        $db = get_instance()->db;
        $owned = $db->get_where('users', ['id' => $this->secretaryId])->row_array();
        $role = $db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])->row_array();
        if (
            !is_array($owned) ||
            !is_array($role) ||
            (int) $owned['id_roles'] !== (int) $role['id'] ||
            $owned['email'] !== $this->fixture->run . '_secretary@synthetic.invalid'
        ) {
            throw new RuntimeException('Secretary fixture identity changed; cleanup stopped.');
        }
        $db->delete('secretaries_providers', ['id_users_secretary' => $this->secretaryId]);
        $db->delete('user_settings', ['id_users' => $this->secretaryId]);
        $db->delete('users', ['id' => $this->secretaryId]);
        if ($db->get_where('secretaries_providers', ['id_users_secretary' => $this->secretaryId])->num_rows() !== 0) {
            throw new RuntimeException('Secretary provider fixture cleanup was not confirmed.');
        }
        if ($db->get_where('user_settings', ['id_users' => $this->secretaryId])->num_rows() !== 0) {
            throw new RuntimeException('Secretary settings fixture cleanup was not confirmed.');
        }
        if ($db->get_where('users', ['id' => $this->secretaryId])->num_rows() !== 0) {
            throw new RuntimeException('Secretary user fixture cleanup was not confirmed.');
        }
        $this->secretaryId = null;
    }

    /** @return array{id:int,users:int} */
    private function snapshotAdminRole(): array
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null);
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
            $this->adminRoleSnapshot = null;
        }
    }

    /** @param array<string,mixed> $secretary */
    private function assertSafeSecretaryProjection(array $secretary, int $id, int $providerId): void
    {
        self::assertSame($id, (int) ($secretary['id'] ?? 0));
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
            'providers',
            'settings',
            'state',
            'timezone',
            'zip_code',
        ];
        $actual = array_keys($secretary);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertSame([$providerId], $secretary['providers']);
        self::assertIsArray($secretary['settings'] ?? null);
        $settingsKeys = array_keys($secretary['settings']);
        sort($settingsKeys);
        self::assertSame(['calendar_view', 'username'], $settingsKeys);
    }

    private function assertNoIntegrationSecrets(string $body): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertStringNotContainsString($fixture->run . '_google_integration', $body);
        self::assertStringNotContainsString($fixture->run . '_caldav_integration', $body);
        self::assertStringNotContainsString($fixture->run . '_secretary_google_integration', $body);
        self::assertStringNotContainsString($fixture->run . '_secretary_caldav_integration', $body);
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
        $contents = SessionFileReader::read($sessionPath);
        self::assertSame(1, preg_match('/dest_url\\|s:\\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

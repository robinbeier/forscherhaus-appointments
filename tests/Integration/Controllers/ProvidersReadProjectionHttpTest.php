<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP regression coverage for backoffice provider read projections. */
final class ProvidersReadProjectionHttpTest extends TestCase
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
            try {
                $this->restoreAdminRole();
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
                $this->restoreAdminRole();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAdminCanFindAndSearchProviderUiProjectionWithoutIntegrationSecrets(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $before = $fixture->row('users', $fixture->providerId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);

        $found = $this->decodeJson($admin->post('providers/find', ['provider_id' => $fixture->providerId]));
        $this->assertSafeProviderProjection($found, $fixture->providerId);
        self::assertSame($fixture->run . '_provider@synthetic.invalid', $found['email']);
        self::assertSame($fixture->run . '_provider', $found['settings']['username'] ?? null);
        $this->assertNoIntegrationSecrets(
            $admin->post('providers/find', ['provider_id' => $fixture->providerId])->body,
        );

        $directFind = $admin->get('providers/find', ['provider_id' => $fixture->providerId]);
        self::assertSame(200, $directFind->statusCode, $directFind->body);
        $this->assertSafeProviderProjection($this->decodeJson($directFind), $fixture->providerId);
        $this->assertNoIntegrationSecrets($directFind->body);

        $rows = $this->decodeJson($admin->post('providers/search', ['keyword' => $fixture->run]));
        $matches = array_values(
            array_filter(
                $rows,
                static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $fixture->providerId,
            ),
        );
        self::assertCount(1, $matches);
        $this->assertSafeProviderProjection($matches[0], $fixture->providerId);
        $searchPost = $admin->post('providers/search', ['keyword' => $fixture->run]);
        $this->assertNoIntegrationSecrets($searchPost->body);

        $directSearch = $admin->get('providers/search', ['keyword' => $fixture->run]);
        self::assertSame(200, $directSearch->statusCode, $directSearch->body);
        $directRows = $this->decodeJson($directSearch);
        $directMatches = array_values(
            array_filter(
                $directRows,
                static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $fixture->providerId,
            ),
        );
        self::assertCount(1, $directMatches);
        $this->assertSafeProviderProjection($directMatches[0], $fixture->providerId);
        $this->assertNoIntegrationSecrets($directSearch->body);

        $directAlias = $admin->get('backend_api/ajax_filter_providers', ['keyword' => $fixture->run]);
        self::assertSame(200, $directAlias->statusCode, $directAlias->body);
        $aliasRows = $this->decodeJson($directAlias);
        $aliasMatches = array_values(
            array_filter(
                $aliasRows,
                static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $fixture->providerId,
            ),
        );
        self::assertCount(1, $aliasMatches);
        $this->assertSafeProviderProjection($aliasMatches[0], $fixture->providerId);
        $this->assertNoIntegrationSecrets($directAlias->body);

        $alias = $admin->post('backend_api/ajax_filter_providers', ['keyword' => $fixture->run]);
        self::assertSame(403, $alias->statusCode, $alias->body);
        self::assertStringNotContainsString($fixture->run, $alias->body);
        self::assertSame($before, $fixture->row('users', $fixture->providerId));
    }

    public function testFindRejectsCustomerIdAndNeverReturnsItsData(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $before = $fixture->row('users', $fixture->customerId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);

        $response = $admin->post('providers/find', ['provider_id' => $fixture->customerId]);
        self::assertSame(404, $response->statusCode, $response->body);
        self::assertStringNotContainsString($fixture->run, $response->body);
        $this->assertNoIntegrationSecrets($response->body);
        self::assertSame($before, $fixture->row('users', $fixture->customerId));
    }

    public function testDemotedAdminCannotReadProvidersAfterLogin(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $providerBefore = $fixture->row('users', $fixture->providerId);
        $actorBefore = $fixture->row('users', $fixture->actorId);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);

        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->post('providers/find', ['provider_id' => $fixture->providerId])->statusCode);

        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => (int) $customerRole['id']], ['id' => $fixture->actorId]),
        );
        foreach (
            [
                $admin->post('providers/find', ['provider_id' => $fixture->providerId]),
                $admin->post('providers/search', ['keyword' => $fixture->run]),
            ]
            as $response
        ) {
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertStringNotContainsString($fixture->run, $response->body);
        }

        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => $actorBefore['id_roles']], ['id' => $fixture->actorId]),
        );
        self::assertSame($actorBefore, $fixture->row('users', $fixture->actorId));
        self::assertSame($providerBefore, $fixture->row('users', $fixture->providerId));
    }

    public function testUsersViewRevocationDeniesProviderFindAndSearchWithoutMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->adminRoleSnapshot = $this->snapshotAdminRole();
        $providerBefore = $fixture->row('users', $fixture->providerId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->post('providers/find', ['provider_id' => $fixture->providerId])->statusCode);

        self::assertTrue(get_instance()->db->update('roles', ['users' => 0], ['id' => $this->adminRoleSnapshot['id']]));
        foreach (
            [
                $admin->post('providers/find', ['provider_id' => $fixture->providerId]),
                $admin->post('providers/search', ['keyword' => $fixture->run]),
            ]
            as $response
        ) {
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertStringNotContainsString($fixture->run, $response->body);
        }
        self::assertSame($providerBefore, $fixture->row('users', $fixture->providerId));
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

    /** @param array<string,mixed> $provider */
    private function assertSafeProviderProjection(array $provider, int $providerId): void
    {
        self::assertSame($providerId, (int) ($provider['id'] ?? 0));
        $expected = [
            'address',
            'city',
            'class_size_default',
            'email',
            'first_name',
            'id',
            'is_private',
            'language',
            'last_name',
            'ldap_dn',
            'mobile_number',
            'notes',
            'phone_number',
            'room',
            'services',
            'settings',
            'state',
            'timezone',
            'zip_code',
        ];
        $actual = array_keys($provider);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertIsArray($provider['settings']);
        $expectedSettings = ['calendar_view', 'username', 'working_plan', 'working_plan_exceptions'];
        $actualSettings = array_keys($provider['settings']);
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

    /** @return array<string,mixed> */
    private function decodeJson(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode, $response->body);
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
        self::assertSame(200, $response->statusCode, $response->body);
        return $client;
    }
}

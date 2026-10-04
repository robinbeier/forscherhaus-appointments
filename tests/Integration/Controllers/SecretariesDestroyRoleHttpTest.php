<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Secretaries::destroy surface. */
final class SecretariesDestroyRoleHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $actorRoleBefore = 0;
    /** @var list<int> */
    private array $targetIds = [];

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
            if ($this->fixture !== null) {
                $db = get_instance()->db;
                if ($this->actorRoleBefore > 0) {
                    $db->update('users', ['id_roles' => $this->actorRoleBefore], ['id' => $this->fixture->actorId]);
                }
                foreach ($this->targetIds as $targetId) {
                    $db->delete('secretaries_providers', ['id_users_secretary' => $targetId]);
                    $db->delete('user_settings', ['id_users' => $targetId]);
                    $db->delete('users', ['id' => $targetId]);
                    self::assertSame([], $this->fixture->row('users', $targetId));
                }
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testNonPostMethodsRejectOnCanonicalAndDirectAliasesWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $targetId = $this->createTarget(DB_SLUG_SECRETARY, 'get');
        $before = $this->fixture->secretaryDeleteState($targetId);

        foreach (
            ['secretaries/destroy', 'index.php/secretaries/destroy', 'backend_api/ajax_delete_secretary']
            as $path
        ) {
            $client =
                $path === 'index.php/secretaries/destroy'
                    ? $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''))
                    : $admin;
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp($method, $path . '?secretary_id=' . $targetId);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ': ' . $response->body);
                self::assertSame('POST', $response->header('allow'));
                self::assertSame($before, $this->fixture->secretaryDeleteState($targetId));
            }
        }
    }

    public function testCanonicalAndLegacyAliasRequireCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $targetId = $this->createTarget(DB_SLUG_SECRETARY, 'csrf');
        $before = $this->fixture->secretaryDeleteState($targetId);

        foreach (['secretaries/destroy', 'backend_api/ajax_delete_secretary'] as $path) {
            $response = $admin->post($path, ['secretary_id' => $targetId], null, false);
            self::assertSame(403, $response->statusCode, $path . ' missing CSRF must be rejected: ' . $response->body);
            self::assertSame($before, $this->fixture->secretaryDeleteState($targetId));
        }
    }

    public function testOtherRoleTargetIsRejectedWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $targetId = $this->createTarget(DB_SLUG_CUSTOMER, 'other-role');
        $before = $this->fixture->row('users', $targetId);

        $response = $admin->post('secretaries/destroy', ['secretary_id' => $targetId]);

        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->row('users', $targetId));
    }

    public function testAuthorizedAdminCanDeleteOwnedSecretaryThroughCanonicalAndLegacyAlias(): void
    {
        $admin = $this->login($this->server->client());
        $noFollow = $this->login(
            new GateHttpClient($this->server->baseUrl, additionalHeaders: ['X-FH-Test' => 'secretary-delete-alias']),
        );
        foreach (['secretaries/destroy', 'backend_api/ajax_delete_secretary'] as $index => $path) {
            $targetId = $this->createTarget(DB_SLUG_SECRETARY, 'authorized-' . $index);
            if ($path === 'backend_api/ajax_delete_secretary') {
                $before = $this->fixture->secretaryDeleteState($targetId);
                $redirect = $noFollow->post($path, ['secretary_id' => $targetId]);
                self::assertSame(307, $redirect->statusCode, $redirect->body);
                self::assertStringEndsWith('/secretaries/destroy', (string) $redirect->header('location'));
                self::assertSame($before, $this->fixture->secretaryDeleteState($targetId));
            }
            $response = $admin->post($path, ['secretary_id' => $targetId]);
            self::assertSame(200, $response->statusCode, $path . ': ' . $response->body);
            self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
            self::assertSame([], $this->fixture->secretaryDeleteState($targetId)['user']);
            self::assertSame([], $this->fixture->secretaryDeleteState($targetId)['settings']);
        }
    }

    public function testStoredRoleDemotionRejectsCanonicalAndLegacyAliasWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $targets = [
            'secretaries/destroy' => $this->createTarget(DB_SLUG_SECRETARY, 'demoted-canonical'),
            'backend_api/ajax_delete_secretary' => $this->createTarget(DB_SLUG_SECRETARY, 'demoted-alias'),
        ];
        $before = array_map(fn(int $targetId): array => $this->fixture->secretaryDeleteState($targetId), $targets);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);

        try {
            self::assertTrue(
                (bool) get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $this->fixture->actorId],
                ),
            );
            $nonPost = $admin->requestApp('GET', 'secretaries/destroy?secretary_id=' . $targets['secretaries/destroy']);
            self::assertSame(405, $nonPost->statusCode);
            self::assertSame('POST', $nonPost->header('allow'));
            self::assertSame(
                $before['secretaries/destroy'],
                $this->fixture->secretaryDeleteState($targets['secretaries/destroy']),
            );
            foreach ($targets as $path => $targetId) {
                $response = $admin->post($path, ['secretary_id' => $targetId]);
                self::assertSame(403, $response->statusCode, $path . ': ' . $response->body);
                self::assertSame($before[$path], $this->fixture->secretaryDeleteState($targetId));
            }
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    private function createTarget(string $roleSlug, string $suffix): int
    {
        $db = get_instance()->db;
        $role = $db->get_where('roles', ['slug' => $roleSlug])->row_array();
        self::assertNotEmpty($role);
        self::assertTrue(
            (bool) $db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => 'Destroy Target',
                'email' => $this->fixture->run . '_' . $suffix . '@synthetic.invalid',
                'phone_number' => '000000000',
                'notes' => $this->fixture->run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $role['id'],
                'is_private' => 0,
            ]),
        );
        $id = (int) $db->insert_id();
        self::assertTrue(
            (bool) $db->insert('user_settings', [
                'id_users' => $id,
                'username' => $this->fixture->run . '_' . $suffix,
                'password' => 'synthetic',
                'salt' => 'synthetic',
                'working_plan' => '{}',
                'notifications' => 0,
                'google_sync' => 0,
                'caldav_sync' => 0,
            ]),
        );
        $this->targetIds[] = $id;
        return $id;
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
}

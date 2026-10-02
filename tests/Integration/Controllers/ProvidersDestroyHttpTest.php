<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Providers::destroy surface. */
final class ProvidersDestroyHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $actorRoleBefore = 0;
    private ?int $deletionTargetId = null;
    private ?string $deletionTargetEmail = null;
    private ?int $otherRoleTargetId = null;
    private bool $actorRowDeleted = false;

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
            $db = get_instance()->db;
            if ($this->actorRoleBefore > 0 && $this->fixture !== null) {
                $db->update('users', ['id_roles' => $this->actorRoleBefore], ['id' => $this->fixture->actorId]);
            }
            if ($this->actorRowDeleted && $this->fixture !== null) {
                $db->delete('user_settings', ['id_users' => $this->fixture->actorId]);
                self::assertSame([], $this->fixture->userSettingsRow($this->fixture->actorId));
                self::assertSame([], $this->fixture->row('users', $this->fixture->actorId));
            }
            if ($this->deletionTargetId !== null) {
                $db->delete('user_settings', ['id_users' => $this->deletionTargetId]);
                $db->delete('services_providers', ['id_users' => $this->deletionTargetId]);
                $db->delete('users', ['id' => $this->deletionTargetId]);
                self::assertSame([], $this->fixture?->row('users', $this->deletionTargetId));
            }
            if ($this->otherRoleTargetId !== null) {
                $db->delete('user_settings', ['id_users' => $this->otherRoleTargetId]);
                $db->delete('users', ['id' => $this->otherRoleTargetId]);
                self::assertSame([], $this->fixture?->row('users', $this->otherRoleTargetId));
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testCanonicalAndDirectAliasRejectWrongMethodsWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->fixture->providerDeleteState($this->fixture->providerId);

        foreach (['providers/destroy', 'index.php/providers/destroy'] as $path) {
            $client =
                $path === 'providers/destroy'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp(
                    $method,
                    $path . '?provider_id=' . $this->fixture->providerId,
                    [],
                    null,
                    false,
                );
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ' must be rejected.');
                self::assertSame('POST', $response->header('allow'), $method . ' ' . $path . ' Allow header.');
                self::assertSame($before, $this->fixture->providerDeleteState($this->fixture->providerId));
            }
        }
    }

    public function testDestroyRequiresCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->fixture->providerDeleteState($this->fixture->providerId);

        $response = $admin->post(
            'providers/destroy',
            ['provider_id' => $this->fixture->providerId],
            withCsrfToken: false,
        );

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->providerDeleteState($this->fixture->providerId));
    }

    public function testStoredRoleDemotionRejectsDestroyWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->fixture->providerDeleteState($this->fixture->providerId);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);

        try {
            get_instance()->db->update(
                'users',
                ['id_roles' => (int) $customerRole['id']],
                ['id' => $this->fixture->actorId],
            );
            $response = $admin->post('providers/destroy', ['provider_id' => $this->fixture->providerId]);
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertSame($before, $this->fixture->providerDeleteState($this->fixture->providerId));
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    public function testPositiveIdIsRequired(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->fixture->providerDeleteState($this->fixture->providerId);

        $invalid = $admin->post('providers/destroy', ['provider_id' => 0]);
        self::assertSame(400, $invalid->statusCode, $invalid->body);
        self::assertSame($before, $this->fixture->providerDeleteState($this->fixture->providerId));
    }

    public function testOtherRoleTargetIsRejectedWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $this->createOtherRoleTarget();
        $otherRoleBefore = $this->fixture->row('users', $this->otherRoleTargetId);
        $response = $admin->post('providers/destroy', ['provider_id' => $this->otherRoleTargetId]);
        self::assertSame(404, $response->statusCode, $response->body);
        self::assertSame($otherRoleBefore, $this->fixture->row('users', $this->otherRoleTargetId));
    }

    public function testDeleteFailureDoesNotLeakSqlOrPartiallyMutateProvider(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->fixture->providerDeleteState($this->fixture->providerId);
        $db = get_instance()->db;
        $trigger = $this->fixture->run . '_deny_provider_delete';
        $fixtureAdmin = get_instance()->load->database(
            [
                'hostname' => 'mysql',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'easyappointments',
                'dbdriver' => 'mysqli',
                'dbprefix' => $db->dbprefix,
                'pconnect' => false,
                'db_debug' => false,
                'char_set' => 'utf8mb4',
                'dbcollat' => 'utf8mb4_general_ci',
            ],
            true,
        );
        $created = false;
        try {
            self::assertTrue(
                $fixtureAdmin->query(
                    'CREATE TRIGGER `' .
                        $trigger .
                        '` BEFORE DELETE ON `' .
                        $db->dbprefix('users') .
                        '` FOR EACH ROW BEGIN IF OLD.id = ' .
                        (int) $this->fixture->providerId .
                        " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic provider delete failure'; END IF; END",
                ),
            );
            $created = true;

            $response = $admin->post('providers/destroy', ['provider_id' => $this->fixture->providerId]);

            self::assertSame(500, $response->statusCode, $response->body);
            self::assertStringContainsString('Could not delete provider.', $response->body);
            self::assertStringNotContainsString('synthetic provider delete failure', $response->body);
            self::assertStringNotContainsString('SQLSTATE', $response->body);
            self::assertStringNotContainsString('DELETE FROM', $response->body);
            self::assertSame($before, $this->fixture->providerDeleteState($this->fixture->providerId));
        } finally {
            try {
                if ($created) {
                    self::assertTrue($fixtureAdmin->query('DROP TRIGGER `' . $trigger . '`'));
                }
                $triggerCount = $fixtureAdmin->query(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS ' .
                        'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                    [$trigger],
                );
                self::assertSame(0, (int) $triggerCount->num_rows());
            } finally {
                $fixtureAdmin->close();
            }
        }
    }

    public function testAuthorizedAdminCanDeleteOwnedSyntheticProvider(): void
    {
        $admin = $this->login($this->server->client());
        $this->createDeletionTarget();

        $response = $admin->post('providers/destroy', ['provider_id' => $this->deletionTargetId]);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame([], $this->fixture->row('users', $this->deletionTargetId));
        self::assertSame([], $this->fixture->userSettingsRow($this->deletionTargetId));
    }

    public function testDeletedSessionActorCannotDeleteSyntheticProvider(): void
    {
        $admin = $this->login($this->server->client());
        $this->createDeletionTarget();
        $before = $this->fixture->providerDeleteState($this->deletionTargetId);
        $db = get_instance()->db;

        $deleted = $db->delete('users', ['id' => $this->fixture->actorId]);
        $this->actorRowDeleted = true;
        self::assertTrue($deleted);
        self::assertSame(1, $db->affected_rows());
        self::assertSame([], $this->fixture->row('users', $this->fixture->actorId));

        $response = $admin->post('providers/destroy', ['provider_id' => $this->deletionTargetId]);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->providerDeleteState($this->deletionTargetId));
    }

    private function createDeletionTarget(): void
    {
        $db = get_instance()->db;
        $role = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($role);
        $this->deletionTargetEmail = $this->fixture->run . '_destroy_target@synthetic.invalid';
        self::assertTrue(
            (bool) $db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => 'Destroy Target',
                'email' => $this->deletionTargetEmail,
                'phone_number' => '000000000',
                'notes' => $this->fixture->run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $role['id'],
                'is_private' => 0,
            ]),
        );
        $this->deletionTargetId = (int) $db->insert_id();
        self::assertTrue(
            (bool) $db->insert('user_settings', [
                'id_users' => $this->deletionTargetId,
                'username' => $this->fixture->run . '_destroy_target',
                'password' => 'synthetic',
                'salt' => 'synthetic',
                'working_plan' => '{}',
                'notifications' => 0,
                'google_sync' => 0,
                'caldav_sync' => 0,
            ]),
        );
    }

    private function createOtherRoleTarget(): void
    {
        $db = get_instance()->db;
        $role = $db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();
        self::assertNotEmpty($role);
        self::assertTrue(
            (bool) $db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => 'Other Role Target',
                'email' => $this->fixture->run . '_other_role_target@synthetic.invalid',
                'phone_number' => '000000000',
                'notes' => $this->fixture->run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $role['id'],
                'is_private' => 0,
            ]),
        );
        $this->otherRoleTargetId = (int) $db->insert_id();
        self::assertTrue(
            (bool) $db->insert('user_settings', [
                'id_users' => $this->otherRoleTargetId,
                'username' => $this->fixture->run . '_other_role_target',
                'password' => 'synthetic',
                'salt' => 'synthetic',
                'working_plan' => '{}',
                'notifications' => 0,
                'google_sync' => 0,
                'caldav_sync' => 0,
            ]),
        );
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

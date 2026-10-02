<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Admins::destroy surface. */
final class AdminsDestroyHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $actorRoleBefore = 0;
    private ?int $targetId = null;
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
            if ($this->fixture !== null) {
                $db = get_instance()->db;
                if ($this->actorRoleBefore > 0 && !$this->actorRowDeleted) {
                    $db->update('users', ['id_roles' => $this->actorRoleBefore], ['id' => $this->fixture->actorId]);
                }
                if ($this->targetId !== null) {
                    $db->delete('user_settings', ['id_users' => $this->targetId]);
                    $db->delete('users', ['id' => $this->targetId]);
                    self::assertSame([], $this->fixture->row('users', $this->targetId));
                }
                if ($this->otherRoleTargetId !== null) {
                    $db->delete('user_settings', ['id_users' => $this->otherRoleTargetId]);
                    $db->delete('users', ['id' => $this->otherRoleTargetId]);
                    self::assertSame([], $this->fixture->row('users', $this->otherRoleTargetId));
                }
                if ($this->actorRowDeleted) {
                    self::assertSame([], $this->fixture->row('users', $this->fixture->actorId));
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

    public function testCanonicalAndDirectAliasRejectWrongMethodsWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->fixture->adminDeleteSnapshot();

        foreach (['admins/destroy', 'index.php/admins/destroy'] as $path) {
            $client =
                $path === 'admins/destroy'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp(
                    $method,
                    $path . '?admin_id=' . $this->fixture->actorId,
                    [],
                    null,
                    false,
                );
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ' must be rejected.');
                self::assertSame('POST', $response->header('allow'));
                self::assertSame($before, $this->fixture->adminDeleteSnapshot());
            }
        }
    }

    public function testDestroyRequiresCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $this->createTarget();
        $before = $this->fixture->adminDeleteState($this->targetId);

        $response = $admin->post('admins/destroy', ['admin_id' => $this->targetId], null, false);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->adminDeleteState($this->targetId));
    }

    public function testStoredRoleDemotionRejectsDestroyWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $this->createTarget();
        $before = $this->fixture->adminDeleteState($this->targetId);
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
            $response = $admin->post('admins/destroy', ['admin_id' => $this->targetId]);
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertSame($before, $this->fixture->adminDeleteState($this->targetId));
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    public function testPositiveIdAndAdminTargetAreRequired(): void
    {
        $admin = $this->login($this->server->client());
        $before = $this->fixture->adminDeleteSnapshot();
        foreach ([0, '0', '', ' ', ['unexpected']] as $id) {
            $response = $admin->post('admins/destroy', ['admin_id' => $id]);
            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame($before, $this->fixture->adminDeleteSnapshot());
        }
        $response = $admin->post('admins/destroy', ['admin_id' => $this->fixture->providerId]);
        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->adminDeleteSnapshot());
    }

    public function testOtherRoleTargetIsRejectedWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $this->createOtherRoleTarget();
        $before = $this->fixture->row('users', $this->otherRoleTargetId);
        $response = $admin->post('admins/destroy', ['admin_id' => $this->otherRoleTargetId]);
        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->row('users', $this->otherRoleTargetId));
    }

    public function testDeleteFailureDoesNotLeakSqlOrPartiallyMutateAdmin(): void
    {
        $admin = $this->login($this->server->client());
        $this->createTarget();
        $before = $this->fixture->adminDeleteState($this->targetId);
        $db = get_instance()->db;
        $trigger = $this->fixture->run . '_deny_admin_delete';
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
                (bool) $fixtureAdmin->query(
                    'CREATE TRIGGER `' .
                        $trigger .
                        '` BEFORE DELETE ON `' .
                        $db->dbprefix('users') .
                        '` FOR EACH ROW BEGIN IF OLD.id = ' .
                        (int) $this->targetId .
                        " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic admin delete failure'; END IF; END",
                ),
            );
            $created = true;
            $response = $admin->post('admins/destroy', ['admin_id' => $this->targetId]);
            self::assertSame(500, $response->statusCode, $response->body);
            self::assertStringContainsString('Could not delete admin.', $response->body);
            self::assertStringNotContainsString('synthetic admin delete failure', $response->body);
            self::assertStringNotContainsString('SQLSTATE', $response->body);
            self::assertStringNotContainsString('DELETE FROM', $response->body);
            self::assertSame($before, $this->fixture->adminDeleteState($this->targetId));
        } finally {
            try {
                $existing = $fixtureAdmin->query(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS ' .
                        'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                    [$trigger],
                );
                if ($created || ($existing !== false && $existing->num_rows() !== 0)) {
                    self::assertTrue((bool) $fixtureAdmin->query('DROP TRIGGER `' . $trigger . '`'));
                }
                $remaining = $fixtureAdmin->query(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS ' .
                        'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                    [$trigger],
                );
                self::assertSame(0, (int) $remaining->num_rows());
            } finally {
                $fixtureAdmin->close();
            }
        }
    }

    public function testAuthorizedAdminCanDeleteOwnedSyntheticAdmin(): void
    {
        $admin = $this->login($this->server->client());
        $this->createTarget();
        $response = $admin->post('admins/destroy', ['admin_id' => $this->targetId]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        self::assertSame([], $this->fixture->adminDeleteState($this->targetId)['user']);
        self::assertSame([], $this->fixture->adminDeleteState($this->targetId)['settings']);
    }

    public function testDeletedSessionActorCannotDeleteSyntheticAdmin(): void
    {
        $admin = $this->login($this->server->client());
        $this->createTarget();
        $before = $this->fixture->adminDeleteState($this->targetId);
        $db = get_instance()->db;
        self::assertTrue((bool) $db->delete('users', ['id' => $this->fixture->actorId]));
        $this->actorRowDeleted = true;
        self::assertSame([], $this->fixture->row('users', $this->fixture->actorId));
        $response = $admin->post('admins/destroy', ['admin_id' => $this->targetId]);
        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->adminDeleteState($this->targetId));
    }

    private function createTarget(): void
    {
        $this->targetId = $this->insertStaffTarget(DB_SLUG_ADMIN, 'destroy_target');
    }

    private function createOtherRoleTarget(): void
    {
        $this->otherRoleTargetId = $this->insertStaffTarget(DB_SLUG_CUSTOMER, 'other_role_target');
    }

    private function insertStaffTarget(string $roleSlug, string $suffix): int
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

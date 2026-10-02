<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Admins::store surface. */
final class AdminsStoreHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $actorRoleBefore = 0;
    private ?array $adminRoleBefore = null;

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
            if ($this->fixture !== null && $this->actorRoleBefore > 0) {
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->actorRoleBefore],
                    ['id' => $this->fixture->actorId],
                );
            }
            if ($this->adminRoleBefore !== null) {
                get_instance()->db->update(
                    'roles',
                    ['users' => $this->adminRoleBefore['users']],
                    ['id' => $this->adminRoleBefore['id']],
                );
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAuthorizedAdminCanCreateSyntheticAdmin(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->adminWritePayload('store-create');

        $response = $admin->post('admins/store', ['admin' => $this->formPayload($payload)]);

        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($data['success'] ?? false), $response->body);
        self::assertGreaterThan(0, (int) ($data['id'] ?? 0));
        $state = $this->fixture->adminWriteState($payload['email']);
        self::assertSame($payload['email'], $state['user']['email'] ?? null);
        self::assertSame($payload['settings']['username'], $state['settings']['username'] ?? null);
        self::assertSame(
            (int) get_instance()
                ->db->get_where('roles', ['slug' => 'admin'])
                ->row_array()['id'],
            (int) ($state['user']['id_roles'] ?? 0),
        );
    }

    public function testCanonicalAndDirectAliasRejectUnsupportedMethodsWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        foreach (['admins/store', 'index.php/admins/store'] as $path) {
            $client =
                $path === 'admins/store'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            $payload = $this->fixture->adminWritePayload(
                'method-' . ($path === 'admins/store' ? 'canonical' : 'alias'),
            );
            $before = $this->fixture->adminDeleteSnapshot();
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp($method, $path, [], null, false);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ' must be rejected.');
                self::assertSame('POST', $response->header('allow'));
                self::assertSame($before, $this->fixture->adminDeleteSnapshot());
            }
            self::assertSame([], $this->fixture->adminWriteState($payload['email']));
        }
    }

    public function testStoreRequiresCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->adminWritePayload('no-csrf');
        $before = $this->fixture->adminDeleteSnapshot();

        $response = $admin->post('admins/store', ['admin' => $this->formPayload($payload)], null, false);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame([], $this->fixture->adminWriteState($payload['email']));
        self::assertSame($before, $this->fixture->adminDeleteSnapshot());
    }

    public function testStoredRoleDemotionRejectsValidCsrfStoreWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->adminWritePayload('demoted');
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
            $before = $this->fixture->adminDeleteSnapshot();
            $response = $admin->post('admins/store', ['admin' => $this->formPayload($payload)]);
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertSame([], $this->fixture->adminWriteState($payload['email']));
            self::assertSame($before, $this->fixture->adminDeleteSnapshot());
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    public function testPositiveExistingIdCannotUpdateTarget(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->adminWritePayload('existing-id');
        $payload['id'] = $this->fixture->actorId;
        $before = $this->fixture->adminDeleteSnapshot();
        $targetBefore = $this->fixture->adminDeleteState($this->fixture->actorId);

        $response = $admin->post('admins/store', ['admin' => $this->formPayload($payload)]);

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame([], $this->fixture->adminWriteState($payload['email']));
        self::assertSame($targetBefore, $this->fixture->adminDeleteState($this->fixture->actorId));
        self::assertSame($before, $this->fixture->adminDeleteSnapshot());
    }

    public function testExistingNonAdminIdsCannotUpdateProviderOrCustomer(): void
    {
        $admin = $this->login($this->server->client());
        foreach (['provider' => $this->fixture->providerId, 'customer' => $this->fixture->customerId] as $role => $id) {
            $payload = $this->fixture->adminWritePayload('existing-' . $role . '-id');
            $payload['id'] = $id;
            $before = $this->fixture->adminDeleteSnapshot();
            $targetBefore = $this->fixture->adminDeleteState($id);

            $response = $admin->post('admins/store', ['admin' => $this->formPayload($payload)]);

            self::assertSame(400, $response->statusCode, $role . ' ID must not be accepted: ' . $response->body);
            self::assertSame([], $this->fixture->adminWriteState($payload['email']));
            self::assertSame($targetBefore, $this->fixture->adminDeleteState($id));
            self::assertSame($before, $this->fixture->adminDeleteSnapshot());
        }
    }

    public function testInvalidIdVariantsAreRejectedWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        foreach ([0, '0', ' ', ['unexpected']] as $case => $invalidId) {
            $payload = $this->fixture->adminWritePayload('invalid-id-' . $case);
            $payload['id'] = $invalidId;
            $before = $this->fixture->adminDeleteSnapshot();
            $response = $admin->post('admins/store', ['admin' => $this->formPayload($payload)]);
            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame([], $this->fixture->adminWriteState($payload['email']));
            self::assertSame($before, $this->fixture->adminDeleteSnapshot());
        }
    }

    public function testDuplicateUsernameAndEmailRemainActionableWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $existingUser = $this->fixture->row('users', $this->fixture->actorId);
        $existingSettings = $this->fixture->userSettingsRow($this->fixture->actorId);
        self::assertNotEmpty($existingUser);
        self::assertNotEmpty($existingSettings);

        $duplicateUsername = $this->fixture->adminWritePayload('duplicate-username');
        $duplicateUsername['settings']['username'] = $existingSettings['username'];
        $duplicateEmail = $this->fixture->adminWritePayload('duplicate-email');
        $duplicateEmailFixtureAddress = $duplicateEmail['email'];
        $duplicateEmail['email'] = $existingUser['email'];

        foreach (
            [
                [$duplicateUsername, 'The provided username is already in use', $duplicateUsername['email']],
                [$duplicateEmail, 'The provided email address is already in use', $duplicateEmailFixtureAddress],
            ]
            as [$payload, $message, $fixtureAddress]
        ) {
            $before = $this->fixture->adminDeleteSnapshot();
            $response = $admin->post('admins/store', ['admin' => $this->formPayload($payload)]);

            self::assertSame(500, $response->statusCode, $response->body);
            self::assertStringContainsString($message, $response->body);
            self::assertSame([], $this->fixture->adminWriteState($fixtureAddress));
            self::assertSame($before, $this->fixture->adminDeleteSnapshot());
        }
    }

    public function testCallerSuppliedRoleIsIgnoredAndAddOnlyActorCanCreate(): void
    {
        $db = get_instance()->db;
        $adminRole = $db->get_where('roles', ['slug' => 'admin'])->row_array();
        self::assertNotEmpty($adminRole);
        $this->adminRoleBefore = $adminRole;
        self::assertTrue((bool) $db->update('roles', ['users' => PRIV_ADD], ['id' => (int) $adminRole['id']]));
        self::assertTrue(
            (bool) $db->update('users', ['id_roles' => (int) $adminRole['id']], ['id' => $this->fixture->actorId]),
        );

        $actor = $this->login($this->server->client());
        $payload = $this->fixture->adminWritePayload('add-only');
        $payload['id_roles'] = (int) get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array()['id'];
        $payload['roleId'] = $payload['id_roles'];
        $response = $actor->post('admins/store', ['admin' => $this->formPayload($payload)]);

        self::assertSame(200, $response->statusCode, $response->body);
        $state = $this->fixture->adminWriteState($payload['email']);
        self::assertSame((int) $adminRole['id'], (int) ($state['user']['id_roles'] ?? 0));
    }

    public function testAdminWriteFailureRollsBackUserAndSettingsWrites(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->adminWritePayload('trigger-failure');
        $db = get_instance()->db;
        $trigger = $this->fixture->run . '_deny_admin_store_settings';
        $markerTable = $this->fixture->run . '_admin_store_marker';
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
        $markerCreated = false;
        $triggerCreated = false;
        try {
            self::assertTrue(
                (bool) $fixtureAdmin->query(
                    'CREATE TABLE `' . $markerTable . '` (`marker` VARCHAR(255) NOT NULL) ENGINE=MEMORY',
                ),
            );
            $markerCreated = true;
            self::assertTrue(
                (bool) $fixtureAdmin->query(
                    'CREATE TRIGGER `' .
                        $trigger .
                        '` BEFORE INSERT ON `' .
                        $db->dbprefix('user_settings') .
                        '` FOR EACH ROW BEGIN IF NEW.id_users = (SELECT id FROM `' .
                        $db->dbprefix('users') .
                        '` WHERE email = ' .
                        $fixtureAdmin->escape($payload['email']) .
                        ' LIMIT 1) THEN INSERT INTO `' .
                        $markerTable .
                        '` (`marker`) VALUES (' .
                        $fixtureAdmin->escape('user-written-before-settings-failure') .
                        '); SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'synthetic admin store settings failure\'; END IF; END',
                ),
            );
            $triggerCreated = true;
            $before = $this->fixture->adminDeleteSnapshot();
            $response = $admin->post('admins/store', ['admin' => $this->formPayload($payload)]);
            self::assertSame(500, $response->statusCode, $response->body);
            self::assertSame(
                ['success' => false, 'message' => 'Admin creation failed.'],
                json_decode($response->body, true, 512, JSON_THROW_ON_ERROR),
            );
            self::assertSame([], $this->fixture->adminWriteState($payload['email']));
            self::assertSame($before, $this->fixture->adminDeleteSnapshot());
            self::assertSame(
                [['marker' => 'user-written-before-settings-failure']],
                $fixtureAdmin->query('SELECT `marker` FROM `' . $markerTable . '`')->result_array(),
            );
        } finally {
            try {
                try {
                    if ($triggerCreated) {
                        $fixtureAdmin->query('DROP TRIGGER `' . $trigger . '`');
                    }
                } finally {
                    if ($markerCreated) {
                        $fixtureAdmin->query('DROP TABLE `' . $markerTable . '`');
                    }
                }
                $triggerCount = $fixtureAdmin->query(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                    [$trigger],
                );
                self::assertSame(0, (int) $triggerCount->num_rows());
                $markerCount = $fixtureAdmin->query(
                    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$markerTable],
                );
                self::assertSame(0, (int) $markerCount->num_rows());
            } finally {
                $fixtureAdmin->close();
            }
        }
    }

    private function formPayload(array $payload): array
    {
        return [
            'first_name' => $payload['firstName'],
            'last_name' => $payload['lastName'],
            'email' => $payload['email'],
            'notes' => $payload['notes'],
            'settings' => [
                'username' => $payload['settings']['username'],
                'password' => $payload['settings']['password'],
            ],
        ] +
            (isset($payload['id']) ? ['id' => $payload['id']] : []) +
            (isset($payload['id_roles']) ? ['id_roles' => $payload['id_roles']] : []) +
            (isset($payload['roleId']) ? ['roleId' => $payload['roleId']] : []);
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

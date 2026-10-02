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

    public function testConcurrentRoleRevocationIsRecheckedBeforeAdminStore(): void
    {
        $db = get_instance()->db;
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->adminWritePayload('concurrent-revocation');
        $adminRole = $db->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
        self::assertNotEmpty($adminRole);
        self::assertSame((int) $adminRole['id'], $this->actorRoleBefore);
        $this->adminRoleBefore = $adminRole;
        $before = $this->fixture->adminDeleteSnapshot();

        $observer = get_instance()->load->database(
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
        $multi = null;
        $handle = null;
        $transactionOpen = false;
        try {
            self::assertTrue($observer->query('SET SESSION TRANSACTION READ ONLY'));
            $ownerConnectionId = mysqli_thread_id($db->conn_id);
            self::assertNotSame($ownerConnectionId, mysqli_thread_id($observer->conn_id));
            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            $revokedUsers = ((int) $adminRole['users']) & ~PRIV_ADD;
            self::assertTrue($db->update('roles', ['users' => $revokedUsers], ['id' => (int) $adminRole['id']]));

            $cookies = [];
            foreach ($admin->cookieRecords() as $record) {
                if (isset($record['name'], $record['value'])) {
                    $cookies[] = $record['name'] . '=' . $record['value'];
                }
            }
            $csrf = $admin->getCookie('csrf_cookie');
            self::assertNotSame('', (string) $csrf);
            $form = $this->formPayload($payload);
            $multi = curl_multi_init();
            $handle = curl_init($this->server->baseUrl . '/admins/store');
            self::assertInstanceOf(CurlMultiHandle::class, $multi);
            self::assertInstanceOf(CurlHandle::class, $handle);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(
                    ['admin' => $form, 'csrf_token' => $csrf],
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                ),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Accept: */*',
                    'Content-Type: application/x-www-form-urlencoded',
                    'Cookie: ' . implode('; ', $cookies),
                ],
            ]);
            self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));

            $waiting = false;
            $deadline = microtime(true) + 8.0;
            do {
                do {
                    $multiResult = curl_multi_exec($multi, $running);
                } while ($multiResult === CURLM_CALL_MULTI_PERFORM);
                self::assertSame(CURLM_OK, $multiResult);
                $waiting = $this->actorStoreLockWaitObserved(
                    $observer,
                    $ownerConnectionId,
                    $db->dbprefix('roles'),
                    (int) $adminRole['id'],
                );
                if ($waiting || $running === 0 || microtime(true) >= $deadline) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (true);
            self::assertTrue(
                $waiting,
                'Admin store must wait on the role lock before authority recheck (running=' .
                    $running .
                    ', status=' .
                    (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) .
                    ', body=' .
                    curl_multi_getcontent($handle) .
                    ').',
            );
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drainCurl($multi, $handle));
            self::assertSame(403, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            self::assertSame([], $this->fixture->adminWriteState($payload['email']));
            self::assertSame($before, $this->fixture->adminDeleteSnapshot());
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            if ($multi instanceof CurlMultiHandle && $handle instanceof CurlHandle) {
                $this->drainCurl($multi, $handle);
                curl_multi_remove_handle($multi, $handle);
            }
            if ($handle instanceof CurlHandle) {
                curl_close($handle);
            }
            if ($multi instanceof CurlMultiHandle) {
                curl_multi_close($multi);
            }
            $observer->close();
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

    private function actorStoreLockWaitObserved(
        object $observer,
        int $ownerConnectionId,
        string $rolesTable,
        int $roleId,
    ): bool {
        $result = $observer->query(
            'SELECT waits.REQUESTING_THREAD_ID, requesting_lock.OBJECT_NAME AS REQUESTING_TABLE, ' .
                'COALESCE(requesting_statement.SQL_TEXT, requesting_thread.PROCESSLIST_INFO) AS REQUESTING_SQL ' .
                'FROM performance_schema.data_lock_waits waits ' .
                'JOIN performance_schema.data_locks requesting_lock ON requesting_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID ' .
                'JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID ' .
                'JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID ' .
                'LEFT JOIN performance_schema.events_statements_current requesting_statement ON requesting_statement.THREAD_ID = requesting_thread.THREAD_ID ' .
                'WHERE blocking_thread.PROCESSLIST_ID = ' .
                (int) $ownerConnectionId,
        );
        if ($result === false) {
            throw new RuntimeException('The independent lock observer could not read performance_schema.');
        }
        $normalize = static fn(string $value): string => (string) preg_replace('/\s+/', ' ', strtoupper(trim($value)));
        $expectedLiteral = $normalize(
            'SELECT `users` FROM `' . $rolesTable . '` WHERE `id` = ' . $roleId . ' FOR UPDATE',
        );
        $expectedParameter = $normalize('SELECT `users` FROM `' . $rolesTable . '` WHERE `id` = ? FOR UPDATE');
        foreach ($result->result_array() as $row) {
            $sql = $normalize((string) ($row['REQUESTING_SQL'] ?? ''));
            if (
                ($row['REQUESTING_TABLE'] ?? null) === $rolesTable &&
                ($sql === $expectedLiteral ||
                    $sql === $expectedParameter ||
                    (str_contains($sql, 'SELECT `USERS` FROM `' . strtoupper($rolesTable) . '`') &&
                        str_contains($sql, 'FOR UPDATE')))
            ) {
                return true;
            }
        }
        return false;
    }

    private function drainCurl(CurlMultiHandle $multi, CurlHandle $handle): bool
    {
        $deadline = microtime(true) + 8.0;
        do {
            do {
                $result = curl_multi_exec($multi, $running);
            } while ($result === CURLM_CALL_MULTI_PERFORM);
            if ($result !== CURLM_OK) {
                return false;
            }
            if ($running === 0) {
                return true;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return $running === 0;
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

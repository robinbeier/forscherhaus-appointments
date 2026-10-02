<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Admins::update surface. */
final class AdminsUpdateHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private array $actorBefore = [];
    private array $actorSettingsBefore = [];
    private int $actorRoleBefore = 0;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->actorBefore = $this->fixture->row('users', $this->fixture->actorId);
            $this->actorSettingsBefore = $this->fixture->userSettingsRow($this->fixture->actorId);
            $this->actorRoleBefore = (int) ($this->actorBefore['id_roles'] ?? 0);
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
                if ($this->actorBefore !== []) {
                    $db->update('users', $this->actorBefore, ['id' => $this->fixture->actorId]);
                }
                if ($this->actorSettingsBefore !== []) {
                    $db->update('user_settings', $this->actorSettingsBefore, ['id_users' => $this->fixture->actorId]);
                }
                self::assertSame($this->actorBefore, $this->fixture->row('users', $this->fixture->actorId));
                self::assertSame($this->actorSettingsBefore, $this->fixture->userSettingsRow($this->fixture->actorId));
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAuthorizedAdminCanUpdateOwnAdminRecord(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->adminPayload();
        $payload['notes'] = $this->fixture->run . '_ordinary_update';

        $response = $admin->post('admins/update', ['admin' => $payload]);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        $after = $this->fixture->adminDeleteState($this->fixture->actorId);
        self::assertSame($payload['notes'], $after['user']['notes']);
        $beforeUser = $this->actorBefore;
        $afterUser = $after['user'];
        unset($beforeUser['notes'], $afterUser['notes'], $beforeUser['update_datetime'], $afterUser['update_datetime']);
        self::assertSame($beforeUser, $afterUser);
        self::assertSame($this->actorSettingsBefore, $after['settings']);
    }

    public function testCanonicalAndDirectAliasRejectUnsupportedMethodsWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        foreach (['admins/update', 'index.php/admins/update'] as $path) {
            $client =
                $path === 'admins/update'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp($method, $path, [], null, false);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ' must be rejected.');
                self::assertSame('POST', $response->header('allow'));
                self::assertSame($this->actorBefore, $this->fixture->row('users', $this->fixture->actorId));
                self::assertSame($this->actorSettingsBefore, $this->fixture->userSettingsRow($this->fixture->actorId));
            }
        }
    }

    public function testUpdateRequiresCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->adminPayload();
        $payload['notes'] = $this->fixture->run . '_without_csrf';

        $response = $admin->post('admins/update', ['admin' => $payload], null, false);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($this->actorBefore, $this->fixture->row('users', $this->fixture->actorId));
        self::assertSame($this->actorSettingsBefore, $this->fixture->userSettingsRow($this->fixture->actorId));
    }

    public function testStoredRoleDemotionRejectsValidCsrfUpdateWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
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
            $response = $admin->post('admins/update', ['admin' => $this->adminPayload()]);
            $expected = $this->actorBefore;
            $expected['id_roles'] = (string) $customerRole['id'];
            self::assertSame($expected, $this->fixture->row('users', $this->fixture->actorId));
            self::assertSame($this->actorSettingsBefore, $this->fixture->userSettingsRow($this->fixture->actorId));
            self::assertSame(403, $response->statusCode, $response->body);
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    public function testConcurrentEditRevocationIsRecheckedBeforeAdminUpdate(): void
    {
        $db = get_instance()->db;
        $admin = $this->login($this->server->client());
        $adminRole = $db->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
        self::assertNotEmpty($adminRole);
        self::assertSame((int) $adminRole['id'], $this->actorRoleBefore);
        $before = $this->fixture->adminDeleteState($this->fixture->actorId);
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
            $revokedUsers = ((int) $adminRole['users']) & ~PRIV_EDIT;
            self::assertTrue($db->update('roles', ['users' => $revokedUsers], ['id' => (int) $adminRole['id']]));

            $cookies = [];
            foreach ($admin->cookieRecords() as $record) {
                if (isset($record['name'], $record['value'])) {
                    $cookies[] = $record['name'] . '=' . $record['value'];
                }
            }
            $csrf = $admin->getCookie('csrf_cookie');
            self::assertNotSame('', (string) $csrf);
            $form = $this->adminPayload();
            $multi = curl_multi_init();
            $handle = curl_init($this->server->baseUrl . '/admins/update');
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
                $waiting = $this->actorRoleLockWaitObserved(
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
            self::assertTrue($waiting, 'Admin update must wait on the role lock before authority recheck.');
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drainCurl($multi, $handle));
            self::assertSame(403, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            self::assertSame($before, $this->fixture->adminDeleteState($this->fixture->actorId));
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
            $db->update('roles', ['users' => (int) $adminRole['users']], ['id' => (int) $adminRole['id']]);
        }
    }

    public function testAbsentEmptyAndInvalidIdsCannotInsert(): void
    {
        $admin = $this->login($this->server->client());
        foreach ([null, '', 0, '0', ' ', ['unexpected']] as $case => $id) {
            $before = $this->fixture->adminDeleteSnapshot();
            $payload = $this->adminPayload();
            $registered = $this->fixture->adminWritePayload('invalid-id-' . $case);
            $payload['first_name'] = $registered['firstName'];
            $payload['last_name'] = $registered['lastName'];
            $payload['email'] = $registered['email'];
            $payload['notes'] = $registered['notes'];
            $payload['settings'] = [
                'username' => $registered['settings']['username'],
                'password' => $registered['settings']['password'],
            ];
            if ($id !== null) {
                $payload['id'] = $id;
            } else {
                unset($payload['id']);
            }
            $response = $admin->post('admins/update', ['admin' => $payload]);
            self::assertSame($before, $this->fixture->adminDeleteSnapshot());
            self::assertSame(400, $response->statusCode, $response->body);
        }
    }

    public function testPositiveNonAdminTargetsCannotUpdate(): void
    {
        $admin = $this->login($this->server->client());
        foreach (
            [$this->fixture->providerId, $this->fixture->customerId, $this->fixture->actorId + 100000]
            as $targetId
        ) {
            $before = $this->fixture->adminDeleteState($targetId);
            $payload = $this->adminPayload();
            $payload['id'] = $targetId;
            $payload['email'] = $this->fixture->run . '_foreign_' . $targetId . '@synthetic.invalid';
            $payload['settings']['username'] = $this->fixture->run . '_foreign_' . $targetId;
            $payload['notes'] = $this->fixture->run . '_must_not_update';
            $response = $admin->post('admins/update', ['admin' => $payload]);
            self::assertSame($before, $this->fixture->adminDeleteState($targetId));
            self::assertSame(400, $response->statusCode, $response->body);
        }
    }

    public function testCallerSuppliedRoleCannotUpdateAdmin(): void
    {
        $admin = $this->login($this->server->client());
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);
        $before = $this->fixture->adminDeleteState($this->fixture->actorId);
        $payload = $this->adminPayload();
        $payload['id_roles'] = (int) $customerRole['id'];

        $response = $admin->post('admins/update', ['admin' => $payload]);

        self::assertSame($before, $this->fixture->adminDeleteState($this->fixture->actorId));
        self::assertSame(400, $response->statusCode, $response->body);
    }

    private function adminPayload(): array
    {
        return [
            'id' => $this->fixture->actorId,
            'first_name' => $this->actorBefore['first_name'],
            'last_name' => $this->actorBefore['last_name'],
            'email' => $this->actorBefore['email'],
            'phone_number' => $this->actorBefore['phone_number'],
            'notes' => $this->actorBefore['notes'],
            'settings' => [
                'username' => $this->actorSettingsBefore['username'],
            ],
        ];
    }

    private function actorRoleLockWaitObserved(
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
        $expected = $normalize('SELECT `users` FROM `' . $rolesTable . '` WHERE `id` = ' . $roleId . ' FOR UPDATE');
        foreach ($result->result_array() as $row) {
            $sql = $normalize((string) ($row['REQUESTING_SQL'] ?? ''));
            if (
                ($row['REQUESTING_TABLE'] ?? null) === $rolesTable &&
                ($sql === $expected || (str_contains($sql, 'SELECT `USERS` FROM') && str_contains($sql, 'FOR UPDATE')))
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
            if ($result !== CURLM_OK || $running === 0) {
                return $result === CURLM_OK;
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

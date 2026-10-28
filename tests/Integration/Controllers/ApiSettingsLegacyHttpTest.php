<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;
use Tests\Integration\Support\SessionFileReader;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';
require_once dirname(__DIR__) . '/Support/SessionFileReader.php';

/** Bounded HTTP/DB coverage for the classic Api_settings controller. */
final class ApiSettingsLegacyHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $actorRoleSnapshot = null;
    private ?array $providerRoleSnapshot = null;
    private ?array $adminRoleSnapshot = null;
    private ?array $apiTokenSnapshot = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->server = new DefenseCycleHttpServer();
            $this->actorRoleSnapshot = $this->fixture->row('users', $this->fixture->actorId);
            $this->providerRoleSnapshot = $this->fixture->row('users', $this->fixture->providerId);
            $this->adminRoleSnapshot = get_instance()
                ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
                ->row_array();
            $this->apiTokenSnapshot = get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array();
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
                if ($this->actorRoleSnapshot !== null) {
                    $db->update(
                        'users',
                        ['id_roles' => $this->actorRoleSnapshot['id_roles']],
                        [
                            'id' => $this->actorRoleSnapshot['id'],
                        ],
                    );
                }
                if ($this->providerRoleSnapshot !== null) {
                    $db->update(
                        'users',
                        ['id_roles' => $this->providerRoleSnapshot['id_roles']],
                        [
                            'id' => $this->providerRoleSnapshot['id'],
                        ],
                    );
                }
                if ($this->apiTokenSnapshot !== null) {
                    $db->update('settings', $this->apiTokenSnapshot, ['id' => $this->apiTokenSnapshot['id']]);
                    self::assertSame(
                        $this->apiTokenSnapshot,
                        $db->get_where('settings', ['id' => $this->apiTokenSnapshot['id']])->row_array(),
                    );
                }
                if ($this->adminRoleSnapshot !== null) {
                    $db->update('roles', $this->adminRoleSnapshot, ['id' => $this->adminRoleSnapshot['id']]);
                    self::assertSame(
                        $this->adminRoleSnapshot,
                        $db->get_where('roles', ['id' => $this->adminRoleSnapshot['id']])->row_array(),
                    );
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

    public function testAdminCanReadCanonicalAndDirectIndexAndSaveSyntheticTokenWithCsrf(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);

        foreach (['api_settings', 'api_settings/index'] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringContainsString('id="api-settings-page"', $response->body, $path);
            self::assertStringContainsString('id="api-token"', $response->body, $path);
            self::assertStringContainsString($this->credentials['token'], $response->body, $path);
        }

        $before = $this->apiTokenSnapshot;
        self::assertNotNull($before);
        try {
            $replacement = $fixture->run . '_api_token_replacement';
            $saved = $admin->post('api_settings/save', [
                'api_settings' => [['name' => 'api_token', 'value' => $replacement]],
            ]);
            self::assertSame(200, $saved->statusCode, $saved->body);
            self::assertSame(
                $replacement,
                get_instance()
                    ->db->get_where('settings', ['id' => $before['id'], 'name' => 'api_token'])
                    ->row_array()['value'] ?? null,
            );
            $owned = $fixture->ownedSetting('foreign', 'before');
            $mixedBefore = get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array();
            $mixed = $admin->post('api_settings/save', [
                'api_settings' => [
                    ['name' => 'api_token', 'value' => $fixture->run . '_mixed_token'],
                    ['name' => $owned['name'], 'value' => 'after'],
                ],
            ]);
            self::assertSame(500, $mixed->statusCode, $mixed->body);
            self::assertSame(
                $mixedBefore,
                get_instance()
                    ->db->get_where('settings', ['name' => 'api_token'])
                    ->row_array(),
            );
            self::assertSame($owned, $fixture->settingRow((int) $owned['id']));
        } finally {
            self::assertTrue(get_instance()->db->update('settings', $before, ['id' => $before['id']]));
            self::assertSame(
                $before,
                get_instance()
                    ->db->get_where('settings', ['id' => $before['id']])
                    ->row_array(),
            );
        }
    }

    public function testViewOnlySystemSettingsRoleCannotReadOrWriteApiToken(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $adminRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        $before = get_instance()
            ->db->get_where('settings', ['name' => 'api_token'])
            ->row_array();
        self::assertTrue(
            get_instance()->db->update('roles', ['system_settings' => PRIV_VIEW], ['id' => $adminRole['id']]),
        );
        try {
            $client = $this->login($this->credentials['admin_username']);
            $integrations = $client->get('integrations');
            self::assertSame(200, $integrations->statusCode, $integrations->body);
            self::assertStringNotContainsString(
                'href="' . $this->server?->baseUrl . '/index.php/api_settings"',
                $integrations->body,
            );
            self::assertSame(403, $client->get('api_settings')->statusCode);
            $save = $client->post('api_settings/save', [
                'api_settings' => [['name' => 'api_token', 'value' => $fixture->run . '_view_only']],
            ]);
            self::assertSame(500, $save->statusCode, $save->body);
            self::assertSame(
                $before,
                get_instance()
                    ->db->get_where('settings', ['name' => 'api_token'])
                    ->row_array(),
            );
        } finally {
            self::assertTrue(
                get_instance()->db->update(
                    'roles',
                    ['system_settings' => $adminRole['system_settings']],
                    ['id' => $adminRole['id']],
                ),
            );
        }
    }

    public function testNonGetAndProviderSaveAreDeniedWithoutDatabaseMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);
        $before = get_instance()
            ->db->get_where('settings', ['name' => 'api_token'])
            ->row_array();

        $postIndex = $admin->post('api_settings/index', [], withCsrfToken: true);
        self::assertSame(405, $postIndex->statusCode, $postIndex->body);
        self::assertSame('GET', $postIndex->header('allow'));
        self::assertSame($beforeDestination, $this->sessionDestination($admin));

        foreach (['api_settings', 'api_settings/index'] as $path) {
            foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $deniedMethod = $admin->requestApp($method, $path);
                self::assertSame(405, $deniedMethod->statusCode, $path . ' ' . $method);
                self::assertSame('GET', $deniedMethod->header('allow'));
                self::assertSame($beforeDestination, $this->sessionDestination($admin));
                self::assertSame(
                    $before,
                    get_instance()
                        ->db->get_where('settings', ['name' => 'api_token'])
                        ->row_array(),
                );
            }
        }

        $missingCsrf = $admin->post(
            'api_settings/save',
            [
                'api_settings' => [['name' => 'api_token', 'value' => $fixture->run . '_missing_csrf']],
            ],
            withCsrfToken: false,
        );
        self::assertSame(403, $missingCsrf->statusCode, $missingCsrf->body);
        self::assertSame(
            $before,
            get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array(),
        );

        $provider = $this->login($this->credentials['provider_username']);
        self::assertSame(200, $provider->get('about')->statusCode);
        $providerDestination = $this->sessionDestination($provider);
        $denied = $provider->get('api_settings');
        self::assertSame(403, $denied->statusCode, $denied->body);
        self::assertSame($providerDestination, $this->sessionDestination($provider));

        $deniedSave = $provider->post('api_settings/save', [
            'api_settings' => [['name' => 'api_token', 'value' => $fixture->run . '_denied']],
        ]);
        self::assertSame(500, $deniedSave->statusCode, $deniedSave->body);
        self::assertSame(
            ['success' => false, 'message' => 'You do not have the required permissions for this task.'],
            json_decode($deniedSave->body, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            $before,
            get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array(),
        );
    }

    public function testStoredRoleDemotionAndPromotionApplyToExistingSessions(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $roles = get_instance()->db;
        $adminRole = $roles->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
        $providerRole = $roles->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        self::assertNotEmpty($providerRole['id'] ?? null);

        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('api_settings')->statusCode);
        $destinationBefore = $this->sessionDestination($admin);
        try {
            self::assertTrue($roles->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->actorId]));
            $demoted = $admin->get('api_settings');
            self::assertSame(403, $demoted->statusCode, $demoted->body);
            self::assertSame($destinationBefore, $this->sessionDestination($admin));
            $beforeDeniedSave = get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array();
            $deniedSave = $admin->post('api_settings/save', [
                'api_settings' => [['name' => 'api_token', 'value' => $fixture->run . '_demoted']],
            ]);
            self::assertSame(500, $deniedSave->statusCode, $deniedSave->body);
            self::assertSame(
                $beforeDeniedSave,
                get_instance()
                    ->db->get_where('settings', ['name' => 'api_token'])
                    ->row_array(),
            );

            $provider = $this->login($this->credentials['provider_username']);
            self::assertSame(403, $provider->get('api_settings')->statusCode);
            self::assertTrue($roles->update('users', ['id_roles' => $adminRole['id']], ['id' => $fixture->providerId]));
            $promoted = $provider->get('api_settings');
            self::assertSame(200, $promoted->statusCode, $promoted->body);
            self::assertStringContainsString('id="api-settings-page"', $promoted->body);
            self::assertStringContainsString('id="save-settings"', $promoted->body);
            $promotedValue = $fixture->run . '_promoted';
            $promotedSave = $provider->post('api_settings/save', [
                'api_settings' => [['name' => 'api_token', 'value' => $promotedValue]],
            ]);
            self::assertSame(200, $promotedSave->statusCode, $promotedSave->body);
            self::assertSame(
                $promotedValue,
                get_instance()
                    ->db->get_where('settings', ['name' => 'api_token'])
                    ->row_array()['value'] ?? null,
            );
        } finally {
            self::assertTrue(
                $roles->update(
                    'users',
                    ['id_roles' => $this->actorRoleSnapshot['id_roles']],
                    ['id' => $fixture->actorId],
                ),
            );
            self::assertTrue(
                $roles->update(
                    'users',
                    ['id_roles' => $this->providerRoleSnapshot['id_roles']],
                    ['id' => $fixture->providerId],
                ),
            );
        }
    }

    public function testConcurrentRoleRevocationPreventsApiTokenWrite(): void
    {
        $this->assertConcurrentRevocation('role');
    }

    public function testConcurrentActorDeletionPreventsApiTokenWrite(): void
    {
        $this->assertConcurrentRevocation('actor');
    }

    private function assertConcurrentRevocation(string $kind): void
    {
        $db = get_instance()->db;
        $admin = $this->login($this->credentials['admin_username']);
        $adminRole = $db->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
        self::assertNotEmpty($adminRole);
        self::assertSame((int) $adminRole['id'], (int) $this->actorRoleSnapshot['id_roles']);
        $this->adminRoleSnapshot = $adminRole;
        $before = $db->get_where('settings', ['name' => 'api_token'])->row_array();

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
            if ($kind === 'role') {
                $revoked = ((int) $adminRole['system_settings']) & ~PRIV_EDIT;
                self::assertTrue(
                    $db->update('roles', ['system_settings' => $revoked], ['id' => (int) $adminRole['id']]),
                );
            } else {
                self::assertTrue($db->delete('users', ['id' => $this->fixture->actorId]));
            }

            $cookies = [];
            foreach ($admin->cookieRecords() as $record) {
                if (isset($record['name'], $record['value'])) {
                    $cookies[] = $record['name'] . '=' . $record['value'];
                }
            }
            $csrf = $admin->getCookie('csrf_cookie');
            self::assertNotSame('', (string) $csrf);
            $form = [['name' => 'api_token', 'value' => $this->fixture->run . '_race']];
            $multi = curl_multi_init();
            $handle = curl_init($this->server->baseUrl . '/api_settings/save');
            self::assertInstanceOf(CurlMultiHandle::class, $multi);
            self::assertInstanceOf(CurlHandle::class, $handle);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(
                    ['api_settings' => $form, 'csrf_token' => $csrf],
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
                $waiting = $this->settingsLockWaitObserved(
                    $observer,
                    $ownerConnectionId,
                    $db->dbprefix($kind === 'role' ? 'roles' : 'users'),
                    $kind === 'role' ? (int) $adminRole['id'] : $this->fixture->actorId,
                );
                if ($waiting || $running === 0 || microtime(true) >= $deadline) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (true);
            self::assertTrue(
                $waiting,
                'API save must wait for the observed authorization lock before write (running=' .
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
            self::assertSame($before, $db->get_where('settings', ['name' => 'api_token'])->row_array());
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

    private function settingsLockWaitObserved(object $observer, int $ownerConnectionId, string $table, int $id): bool
    {
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
        foreach ($result->result_array() as $row) {
            $sql = $normalize((string) ($row['REQUESTING_SQL'] ?? ''));
            if (
                ($row['REQUESTING_TABLE'] ?? null) === $table &&
                str_contains($sql, 'FROM `' . strtoupper($table) . '`') &&
                str_contains($sql, 'FOR UPDATE')
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

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $path = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($path);
        $contents = SessionFileReader::read($path);
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

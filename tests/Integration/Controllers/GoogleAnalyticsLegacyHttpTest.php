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

final class GoogleAnalyticsLegacyHttpTest extends TestCase
{
    private const SETTING_NAME = 'google_analytics_code';

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $actorSnapshot = null;
    private ?array $actorSettingsSnapshot = null;
    private ?array $providerSnapshot = null;
    private ?array $adminRoleSnapshot = null;
    private ?array $providerRoleSnapshot = null;
    private ?array $settingSnapshot = null;

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
            $db = get_instance()->db;
            $this->actorSnapshot = $this->fixture->row('users', $this->fixture->actorId);
            $this->actorSettingsSnapshot = $db
                ->get_where('user_settings', ['id_users' => $this->fixture->actorId])
                ->row_array();
            $this->providerSnapshot = $this->fixture->row('users', $this->fixture->providerId);
            $this->adminRoleSnapshot = $db->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
            $this->providerRoleSnapshot = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
            $this->settingSnapshot = $db->get_where('settings', ['name' => self::SETTING_NAME])->row_array();
            self::assertNotEmpty($this->settingSnapshot, 'The synthetic stack must seed google_analytics_code.');
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
                if ($this->actorSnapshot !== null) {
                    $storedActor = $db->get_where('users', ['id' => $this->actorSnapshot['id']])->row_array();
                    if ($storedActor === null) {
                        self::assertTrue($db->insert('users', $this->actorSnapshot));
                    } else {
                        self::assertTrue(
                            $db->update('users', $this->actorSnapshot, ['id' => $this->actorSnapshot['id']]),
                        );
                    }
                    self::assertSame(
                        $this->actorSnapshot,
                        $db->get_where('users', ['id' => $this->actorSnapshot['id']])->row_array(),
                    );
                }
                if ($this->actorSettingsSnapshot !== null) {
                    $storedSettings = $db
                        ->get_where('user_settings', ['id_users' => $this->actorSettingsSnapshot['id_users']])
                        ->row_array();
                    if ($storedSettings === null) {
                        self::assertTrue($db->insert('user_settings', $this->actorSettingsSnapshot));
                    } else {
                        self::assertTrue(
                            $db->update('user_settings', $this->actorSettingsSnapshot, [
                                'id_users' => $this->actorSettingsSnapshot['id_users'],
                            ]),
                        );
                    }
                    self::assertSame(
                        $this->actorSettingsSnapshot,
                        $db
                            ->get_where('user_settings', ['id_users' => $this->actorSettingsSnapshot['id_users']])
                            ->row_array(),
                    );
                }
                if ($this->providerSnapshot !== null) {
                    $db->update(
                        'users',
                        ['id_roles' => $this->providerSnapshot['id_roles']],
                        ['id' => $this->providerSnapshot['id']],
                    );
                }
                if ($this->providerRoleSnapshot !== null) {
                    $db->update('roles', $this->providerRoleSnapshot, ['id' => $this->providerRoleSnapshot['id']]);
                    self::assertSame(
                        $this->providerRoleSnapshot,
                        $db->get_where('roles', ['id' => $this->providerRoleSnapshot['id']])->row_array(),
                    );
                }
                if ($this->adminRoleSnapshot !== null) {
                    self::assertTrue(
                        $db->update('roles', $this->adminRoleSnapshot, ['id' => $this->adminRoleSnapshot['id']]),
                    );
                    self::assertSame(
                        $this->adminRoleSnapshot,
                        $db->get_where('roles', ['id' => $this->adminRoleSnapshot['id']])->row_array(),
                    );
                }
                if ($this->settingSnapshot !== null) {
                    foreach ($db->get_where('settings', ['name' => self::SETTING_NAME])->result_array() as $row) {
                        if ((int) $row['id'] !== (int) $this->settingSnapshot['id']) {
                            $db->delete('settings', ['id' => $row['id'], 'name' => self::SETTING_NAME]);
                        }
                    }
                    $db->update('settings', $this->settingSnapshot, ['id' => $this->settingSnapshot['id']]);
                    self::assertSame(
                        $this->settingSnapshot,
                        $db
                            ->get_where('settings', [
                                'id' => $this->settingSnapshot['id'],
                                'name' => self::SETTING_NAME,
                            ])
                            ->row_array(),
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

    public function testAdminCanReadCanonicalAndDirectIndexWithExactProjectionAndSaveIgnoringCallerColumns(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        $db = get_instance()->db;
        $futureName = 'google_analytics_' . $fixture->run . '_future';
        $futureMarker = $fixture->run . '_future_value';
        self::assertSame(0, $db->get_where('settings', ['name' => $futureName])->num_rows());
        self::assertTrue($db->insert('settings', ['name' => $futureName, 'value' => $futureMarker]));
        $futureId = (int) $db->insert_id();

        try {
            foreach (['google_analytics_settings', 'google_analytics_settings/index'] as $path) {
                $response = $admin->get($path);
                self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
                self::assertStringContainsString('id="google-analytics-settings-page"', $response->body, $path);
                self::assertStringNotContainsString($futureMarker, $response->body, $path);
                $vars = $this->scriptVars($response->body);
                $settings = $vars['google_analytics_settings'] ?? null;
                self::assertIsArray($settings, $path);
                self::assertCount(1, $settings, $path);
                self::assertSame(['name', 'value'], array_keys($settings[0]), $path);
                self::assertSame(self::SETTING_NAME, $settings[0]['name'], $path);
            }

            $foreign = $fixture->ownedSetting('foreign', 'before');
            $replacement = $fixture->run . '_google_replacement';
            $saved = $admin->post('google_analytics_settings/save', [
                'google_analytics_settings' => [
                    [
                        'name' => self::SETTING_NAME,
                        'value' => $replacement,
                        'id' => $foreign['id'],
                        'extra' => 'discard',
                    ],
                ],
            ]);
            self::assertSame(200, $saved->statusCode, $saved->body);
            self::assertSame($replacement, $this->settingRow()['value'] ?? null);
            self::assertSame($this->settingSnapshot['id'], $this->settingRow()['id'] ?? null);
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        } finally {
            self::assertTrue($db->delete('settings', ['id' => $futureId, 'name' => $futureName]));
            self::assertSame([], $db->get_where('settings', ['name' => $futureName])->row_array() ?? []);
        }
    }

    public function testWrongMethodsAndMissingCsrfDoNotChangeSessionOrSetting(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('google_analytics_settings')->statusCode);
        $destination = $this->sessionDestination($admin);
        $before = $this->settingRow();

        foreach (['google_analytics_settings', 'google_analytics_settings/index'] as $path) {
            $post = $admin->post($path, [], withCsrfToken: true);
            self::assertSame(405, $post->statusCode, 'POST ' . $path);
            self::assertSame('GET', $post->header('allow'), 'POST ' . $path);
            self::assertSame($before, $this->settingRow());
            self::assertSame($destination, $this->sessionDestination($admin));
            foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $admin->requestApp($method, $path, [], null, false);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path);
                self::assertSame('GET', $response->header('allow'), $method . ' ' . $path);
                self::assertSame($before, $this->settingRow());
                self::assertSame($destination, $this->sessionDestination($admin));
            }
        }

        $saveGet = $admin->get('google_analytics_settings/save');
        self::assertSame(405, $saveGet->statusCode, $saveGet->body);
        self::assertSame('POST', $saveGet->header('allow'));
        foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $admin->requestApp($method, 'google_analytics_settings/save');
            self::assertSame(405, $response->statusCode, $method . ' save');
            self::assertSame('POST', $response->header('allow'), $method . ' save');
            self::assertSame($before, $this->settingRow());
            self::assertSame($destination, $this->sessionDestination($admin));
        }
        $missingCsrf = $admin->post(
            'google_analytics_settings/save',
            [
                'google_analytics_settings' => [['name' => self::SETTING_NAME, 'value' => 'missing-csrf']],
            ],
            null,
            false,
        );
        self::assertSame(403, $missingCsrf->statusCode, $missingCsrf->body);
        self::assertSame($before, $this->settingRow());
        self::assertSame($destination, $this->sessionDestination($admin));
    }

    public function testAnonymousAndForbiddenRequestsAreDeniedWithoutMutation(): void
    {
        $before = $this->settingRow();
        $server = $this->server;
        self::assertNotNull($server);
        $anonymous = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'google-anonymous']);
        foreach (['google_analytics_settings', 'google_analytics_settings/index'] as $path) {
            $anonymousIndex = $anonymous->get($path);
            self::assertContains($anonymousIndex->statusCode, [302, 307], $path);
            self::assertStringContainsString('/login', (string) $anonymousIndex->header('location'), $path);
        }

        $provider = $this->login($this->credentials['provider_username']);
        self::assertSame(200, $provider->get('about')->statusCode);
        $providerDestination = $this->sessionDestination($provider);
        foreach (['google_analytics_settings', 'google_analytics_settings/index'] as $path) {
            self::assertSame(403, $provider->get($path)->statusCode, $path);
        }
        self::assertSame($providerDestination, $this->sessionDestination($provider));
        $deniedWrite = $provider->post('google_analytics_settings/save', [
            'google_analytics_settings' => [['name' => self::SETTING_NAME, 'value' => 'forbidden']],
        ]);
        self::assertSame(500, $deniedWrite->statusCode, $deniedWrite->body);
        self::assertSame($before, $this->settingRow());
        self::assertSame($providerDestination, $this->sessionDestination($provider));
    }

    public function testInvalidDuplicateAndForeignMixedBatchesFailWithoutPartialMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreign = $fixture->ownedSetting('foreign_batch', 'before');
        $admin = $this->login($this->credentials['admin_username']);
        $before = $this->settingRow();
        foreach (
            [
                [
                    ['name' => self::SETTING_NAME, 'value' => 'after'],
                    ['name' => $foreign['name'], 'value' => 'after', 'id' => $foreign['id']],
                ],
                [
                    ['name' => self::SETTING_NAME, 'value' => 'first'],
                    ['name' => self::SETTING_NAME, 'value' => 'second'],
                ],
                [['name' => 'google_analytics_unknown_' . $fixture->run, 'value' => 'unknown', 'id' => $foreign['id']]],
            ]
            as $batch
        ) {
            $response = $admin->post('google_analytics_settings/save', ['google_analytics_settings' => $batch]);
            self::assertSame(500, $response->statusCode, $response->body);
            self::assertSame($before, $this->settingRow());
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        }
    }

    public function testValidSaveRecreatesTemporarilyAbsentSettingWithoutUsingForeignId(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->settingSnapshot);
        $db = get_instance()->db;
        $foreign = $fixture->ownedSetting('foreign_absent', 'before');
        $admin = $this->login($this->credentials['admin_username']);
        $original = $this->settingSnapshot;
        $temporaryName = $fixture->run . '_google_temporarily_absent';
        self::assertTrue($db->update('settings', ['name' => $temporaryName], ['id' => $original['id']]));

        try {
            $response = $admin->post('google_analytics_settings/save', [
                'google_analytics_settings' => [
                    [
                        'name' => self::SETTING_NAME,
                        'value' => $fixture->run . '_recreated',
                        'id' => $foreign['id'],
                    ],
                ],
            ]);
            self::assertSame(200, $response->statusCode, $response->body);
            $created = $db->get_where('settings', ['name' => self::SETTING_NAME])->row_array();
            self::assertNotEmpty($created);
            self::assertNotSame((int) $foreign['id'], (int) $created['id']);
            self::assertNotSame((int) $original['id'], (int) $created['id']);
            self::assertSame($fixture->run . '_recreated', $created['value']);
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        } finally {
            $created = $db->get_where('settings', ['name' => self::SETTING_NAME])->row_array();
            if (is_array($created) && $created !== []) {
                self::assertTrue($db->delete('settings', ['id' => $created['id'], 'name' => self::SETTING_NAME]));
            }
            self::assertTrue($db->update('settings', $original, ['id' => $original['id']]));
            self::assertSame($original, $db->get_where('settings', ['id' => $original['id']])->row_array());
            self::assertSame(
                [],
                $db->get_where('settings', ['name' => self::SETTING_NAME, 'id !=' => $original['id']])->result_array(),
            );
        }
    }

    public function testStoredRoleDemotionAndPromotionApplyToExistingSessions(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->actorSnapshot);
        self::assertNotNull($this->providerSnapshot);
        self::assertNotNull($this->providerRoleSnapshot);
        $db = get_instance()->db;
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('google_analytics_settings')->statusCode);
        $destination = $this->sessionDestination($admin);
        $before = $this->settingRow();
        try {
            self::assertTrue(
                $db->update('users', ['id_roles' => $this->providerRoleSnapshot['id']], ['id' => $fixture->actorId]),
            );
            self::assertSame(403, $admin->get('google_analytics_settings')->statusCode);
            $denied = $admin->post('google_analytics_settings/save', [
                'google_analytics_settings' => [['name' => self::SETTING_NAME, 'value' => 'denied']],
            ]);
            self::assertSame(500, $denied->statusCode, $denied->body);
            self::assertSame($before, $this->settingRow());
            self::assertSame($destination, $this->sessionDestination($admin));
        } finally {
            self::assertTrue(
                $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]),
            );
        }
        self::assertSame(200, $admin->get('google_analytics_settings')->statusCode);
    }

    public function testStoredRolePromotionRestoresExistingProviderSessionAccess(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->providerSnapshot);
        $db = get_instance()->db;
        $adminRole = $db->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        $provider = $this->login($this->credentials['provider_username']);
        self::assertSame(403, $provider->get('google_analytics_settings')->statusCode);
        try {
            self::assertTrue($db->update('users', ['id_roles' => $adminRole['id']], ['id' => $fixture->providerId]));
            self::assertSame(200, $provider->get('google_analytics_settings')->statusCode);
            $promotedValue = $fixture->run . '_promoted';
            $promotedSave = $provider->post('google_analytics_settings/save', [
                'google_analytics_settings' => [['name' => self::SETTING_NAME, 'value' => $promotedValue]],
            ]);
            self::assertSame(200, $promotedSave->statusCode, $promotedSave->body);
            self::assertSame($promotedValue, $this->settingRow()['value'] ?? null);
        } finally {
            self::assertTrue(
                $db->update(
                    'users',
                    ['id_roles' => $this->providerSnapshot['id_roles']],
                    ['id' => $fixture->providerId],
                ),
            );
        }
    }

    public function testConcurrentRoleRevocationPreventsGoogleAnalyticsWrite(): void
    {
        $this->assertConcurrentRevocation('role');
    }

    public function testConcurrentActorDeletionPreventsGoogleAnalyticsWrite(): void
    {
        $this->assertConcurrentRevocation('actor');
    }

    private function assertConcurrentRevocation(string $kind): void
    {
        $db = get_instance()->db;
        $admin = $this->login($this->credentials['admin_username']);
        $adminRole = $db->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
        self::assertNotEmpty($adminRole);
        self::assertSame((int) $adminRole['id'], (int) $this->actorSnapshot['id_roles']);
        $before = $this->settingRow();
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
                self::assertTrue($db->delete('users', ['id' => (int) $this->actorSnapshot['id']]));
            }
            $cookies = [];
            foreach ($admin->cookieRecords() as $record) {
                if (isset($record['name'], $record['value'])) {
                    $cookies[] = $record['name'] . '=' . $record['value'];
                }
            }
            $csrf = $admin->getCookie('csrf_cookie');
            self::assertNotSame('', (string) $csrf);
            $multi = curl_multi_init();
            $handle = curl_init($this->server->baseUrl . '/google_analytics_settings/save');
            self::assertInstanceOf(CurlMultiHandle::class, $multi);
            self::assertInstanceOf(CurlHandle::class, $handle);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(
                    [
                        'google_analytics_settings' => [
                            ['name' => self::SETTING_NAME, 'value' => $this->fixture->run . '_race'],
                        ],
                        'csrf_token' => $csrf,
                    ],
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
                    $kind === 'role' ? (int) $adminRole['id'] : (int) $this->actorSnapshot['id'],
                );
                if ($waiting || $running === 0 || microtime(true) >= $deadline) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (true);
            self::assertTrue(
                $waiting,
                'Google save must wait for the observed authorization lock (running=' .
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
            self::assertSame($before, $this->settingRow());
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
            'SELECT waits.REQUESTING_THREAD_ID, requesting_lock.OBJECT_NAME AS REQUESTING_TABLE, COALESCE(requesting_statement.SQL_TEXT, requesting_thread.PROCESSLIST_INFO) AS REQUESTING_SQL FROM performance_schema.data_lock_waits waits JOIN performance_schema.data_locks requesting_lock ON requesting_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID LEFT JOIN performance_schema.events_statements_current requesting_statement ON requesting_statement.THREAD_ID = requesting_thread.THREAD_ID WHERE blocking_thread.PROCESSLIST_ID = ' .
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

    private function settingRow(): array
    {
        return get_instance()
            ->db->get_where('settings', ['name' => self::SETTING_NAME])
            ->row_array();
    }

    private function scriptVars(string $body): array
    {
        self::assertSame(1, preg_match('/const vars = (.+?);\s*\n/s', $body, $matches));
        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vars);
        return $vars;
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

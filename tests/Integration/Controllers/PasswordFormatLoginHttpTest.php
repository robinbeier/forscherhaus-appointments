<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP/DB coverage for legacy and Argon2id password readers. */
final class PasswordFormatLoginHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
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
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testModernHashAuthenticatesAndWrongOrUnknownCredentialsDoNotMutateIt(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();
        $db = get_instance()->db;
        $settings = $fixture->userSettingsRow($fixture->actorId);
        $modern = hash_password_argon2id($fixture->password);
        self::assertTrue(
            (bool) $db->update('user_settings', ['password' => $modern], ['id_users' => $fixture->actorId]),
        );
        $before = $fixture->userSettingsRow($fixture->actorId);

        self::assertTrue($this->loginResult($server->client(), $settings['username'], $fixture->password));
        self::assertTrue(
            verify_password(
                (string) $fixture->userSettingsRow($fixture->actorId)['password'],
                (string) $fixture->userSettingsRow($fixture->actorId)['salt'],
                $fixture->password,
            ),
        );
        self::assertFalse($this->loginResult($server->client(), $settings['username'], $fixture->password . '-wrong'));
        self::assertFalse(
            $this->loginResult($server->client(), $settings['username'] . '-unknown', $fixture->password),
        );
        self::assertSame($before, $fixture->userSettingsRow($fixture->actorId));
    }

    public function testLegacyHashAuthenticatesAndUpgradeIsGuardedWithoutCrossAccountMutation(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();
        $db = get_instance()->db;
        $actor = $fixture->userSettingsRow($fixture->actorId);
        $db->update(
            'user_settings',
            ['password' => hash_legacy_password((string) $actor['salt'], $fixture->password)],
            ['id_users' => $fixture->actorId],
        );
        $actor = $fixture->userSettingsRow($fixture->actorId);
        $foreignBefore = $fixture->userSettingsRow($fixture->providerId);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $actor['password']);

        self::assertTrue($this->loginResult($server->client(), $actor['username'], $fixture->password));
        $after = $fixture->userSettingsRow($fixture->actorId);
        if (MODERN_PASSWORD_WRITES) {
            self::assertStringStartsWith('$argon2id$', (string) $after['password']);
            self::assertTrue(verify_password((string) $after['password'], (string) $after['salt'], $fixture->password));
        } else {
            self::assertSame($actor['password'], $after['password']);
            self::assertSame($actor['salt'], $after['salt']);
        }
        self::assertSame($foreignBefore, $fixture->userSettingsRow($fixture->providerId));
    }

    public function testModernCredentialAuthenticatesAdminBasicApiWithoutDisclosingSecrets(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();
        $settings = $fixture->userSettingsRow($fixture->actorId);
        get_instance()->db->update(
            'user_settings',
            ['password' => hash_password_argon2id($fixture->password)],
            ['id_users' => $fixture->actorId],
        );
        $client = new GateHttpClient(
            $server->baseUrl,
            'index.php',
            additionalHeaders: [
                'Authorization' => 'Basic ' . base64_encode($settings['username'] . ':' . $fixture->password),
            ],
        );
        $response = $client->get('api/v1/services');
        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertStringContainsString($fixture->run, $response->body);
        self::assertStringNotContainsString($fixture->password, $response->body);
        self::assertStringNotContainsString('$argon2id$', $response->body);
    }

    public function testConcurrentResetCannotBeOverwrittenByLegacyUpgrade(): void
    {
        if (!MODERN_PASSWORD_WRITES) {
            self::markTestSkipped('Legacy upgrades are deliberately inactive in the reader-first release.');
        }
        $db = get_instance()->db;
        $fixture = $this->fixture();
        $settings = $fixture->userSettingsRow($fixture->actorId);
        $db->update(
            'user_settings',
            ['password' => hash_legacy_password((string) $settings['salt'], $fixture->password)],
            ['id_users' => $fixture->actorId],
        );
        $admin = $this->server()->client();
        self::assertSame(200, $admin->get('login')->statusCode);
        $replacement = hash_password_argon2id('replacement-synthetic-password');
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
            self::assertTrue(
                $db->update('user_settings', ['password' => $replacement], ['id_users' => $fixture->actorId]),
            );

            $cookies = [];
            foreach ($admin->cookieRecords() as $record) {
                if (isset($record['name'], $record['value'])) {
                    $cookies[] = $record['name'] . '=' . $record['value'];
                }
            }
            $csrf = $admin->getCookie('csrf_cookie');
            self::assertNotSame('', (string) $csrf);
            $form = ['username' => $settings['username'], 'password' => $fixture->password];
            $multi = curl_multi_init();
            $handle = curl_init($this->server->baseUrl . '/login/validate');
            self::assertInstanceOf(CurlMultiHandle::class, $multi);
            self::assertInstanceOf(CurlHandle::class, $handle);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($form + ['csrf_token' => $csrf], '', '&', PHP_QUERY_RFC3986),
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
                    $db->dbprefix('user_settings'),
                    $fixture->actorId,
                );
                if ($waiting || $running === 0 || microtime(true) >= $deadline) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (true);
            self::assertTrue(
                $waiting,
                'Legacy upgrade must reach and wait at its exact credential CAS write (running=' .
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
            self::assertSame(200, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            $result = json_decode(curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse((bool) ($result['success'] ?? true));
            self::assertSame($replacement, $fixture->userSettingsRow($fixture->actorId)['password']);
            self::assertTrue(
                $this->loginResult($this->server()->client(), $settings['username'], 'replacement-synthetic-password'),
            );
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
                str_contains($sql, 'UPDATE `' . strtoupper($table) . '`')
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

    private function loginResult(GateHttpClient $client, string $username, string $password): bool
    {
        $login = $client->get('login');
        self::assertSame(200, $login->statusCode, $login->body);
        $response = $client->post('login/validate', compact('username', 'password'));
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return (bool) ($data['success'] ?? false);
    }

    private function fixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);
        return $this->fixture;
    }

    private function server(): DefenseCycleHttpServer
    {
        self::assertNotNull($this->server);
        return $this->server;
    }
}

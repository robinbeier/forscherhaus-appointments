<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** HTTP regression coverage for revocation of a demoted actor's Settings API access. */
final class SettingsApiBasicRevocationHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];

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

    public function testDemotedActorBasicCannotUseSettingsApiUntilExactRoleIsRestored(): void
    {
        self::assertNotNull($this->fixture);

        $db = get_instance()->db;
        $setting = $this->fixture->ownedSetting('demoted-actor', 'before');
        $settingBefore = $this->fixture->settingRow((int) $setting['id']);
        $actorBefore = $this->fixture->row('users', $this->fixture->actorId);
        $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($providerRole, 'The fixture requires a provider role row.');

        $admin = $this->basicClient();
        $bearer = $this->bearerClient();
        self::assertTrue(
            $db->update('users', ['id_roles' => $providerRole['id']], ['id' => $this->fixture->actorId]),
            'Could not demote the synthetic actor to Provider.',
        );
        self::assertSame(
            (int) $providerRole['id'],
            (int) $this->fixture->row('users', $this->fixture->actorId)['id_roles'],
        );

        try {
            foreach (['api/v1/settings', 'api/v1/settings_api_v1/index'] as $indexPath) {
                $this->assertUnauthorizedObservation($admin, $indexPath, $setting['name']);
            }
            foreach (
                [
                    'api/v1/settings/' . $setting['name'],
                    'api/v1/settings_api_v1/show/' . $setting['name'],
                    'api/v1/settings/api_token',
                    'api/v1/settings_api_v1/show/api_token',
                ]
                as $showPath
            ) {
                $this->assertUnauthorizedObservation($admin, $showPath);
            }
            foreach (
                ['api/v1/settings/' . $setting['name'], 'api/v1/settings_api_v1/update/' . $setting['name']]
                as $updatePath
            ) {
                $response = $admin->requestJsonApp('PUT', $updatePath, ['value' => 'must-not-persist']);
                self::assertSame(401, $response->statusCode, $updatePath . ' must reject the demoted actor.');
                self::assertNotEmpty((string) $response->header('www-authenticate'));
                self::assertStringNotContainsString($this->fixture->run, $response->body);
                self::assertStringNotContainsString($this->credentials['token'], $response->body);
                self::assertSame(
                    $setting,
                    $this->fixture->settingRow((int) $setting['id']),
                    $updatePath . ' must leave the own synthetic setting unchanged.',
                );
            }

            $bearerIndex = $bearer->get('api/v1/settings', ['q' => $setting['name']]);
            self::assertSame(
                200,
                $bearerIndex->statusCode,
                'Bearer control must remain authorized while actor is demoted.',
            );
            self::assertContains(
                ['name' => $setting['name'], 'value' => 'before'],
                $this->jsonList($bearerIndex, 'GET api/v1/settings'),
            );
            $bearerShow = $bearer->get('api/v1/settings_api_v1/show/' . $setting['name']);
            self::assertSame(200, $bearerShow->statusCode, 'Direct Bearer show control must remain authorized.');
            self::assertSame(
                ['name' => $setting['name'], 'value' => 'before'],
                $this->jsonObject($bearerShow, 'GET direct show'),
            );
            $bearerUpdate = $bearer->requestJsonApp('PUT', 'api/v1/settings_api_v1/update/' . $setting['name'], [
                'value' => 'bearer-control',
            ]);
            self::assertSame(200, $bearerUpdate->statusCode, 'Bearer control PUT must remain authorized.');
            $bearerAfter = $this->fixture->settingRow((int) $setting['id']);
            self::assertSame($settingBefore['id'], $bearerAfter['id']);
            self::assertSame($settingBefore['create_datetime'], $bearerAfter['create_datetime']);
            self::assertSame($settingBefore['name'], $bearerAfter['name']);
            self::assertSame('bearer-control', $bearerAfter['value']);
            self::assertNotNull($bearerAfter['update_datetime']);
            self::assertSame($bearerAfter, $this->fixture->settingRow((int) $setting['id']));
        } finally {
            self::assertTrue(
                $db->update('users', ['id_roles' => $actorBefore['id_roles']], ['id' => $actorBefore['id']]),
                'Could not restore the synthetic actor role.',
            );
            self::assertSame(
                $actorBefore,
                $this->fixture->row('users', $this->fixture->actorId),
                'The exact synthetic actor row must be restored after demotion.',
            );
        }

        $restoredBasicShow = $admin->get('api/v1/settings/' . $setting['name']);
        self::assertSame(200, $restoredBasicShow->statusCode, 'Basic access must work after actor restoration.');
        self::assertSame(
            ['name' => $setting['name'], 'value' => 'bearer-control'],
            $this->jsonObject($restoredBasicShow, 'GET restored show'),
        );
        $restoredBasicUpdate = $admin->requestJsonApp('PUT', 'api/v1/settings/' . $setting['name'], [
            'value' => 'basic-restored',
        ]);
        self::assertSame(200, $restoredBasicUpdate->statusCode, 'Basic PUT must work after actor restoration.');
        $restoredAfter = $this->fixture->settingRow((int) $setting['id']);
        self::assertSame($settingBefore['id'], $restoredAfter['id']);
        self::assertSame($settingBefore['create_datetime'], $restoredAfter['create_datetime']);
        self::assertSame($settingBefore['name'], $restoredAfter['name']);
        self::assertSame('basic-restored', $restoredAfter['value']);
        self::assertNotNull($restoredAfter['update_datetime']);
        self::assertSame($restoredAfter, $this->fixture->settingRow((int) $setting['id']));
    }

    public function testBasicWriteWaitsForActorRowThenRejectsAfterDemotion(): void
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);

        $db = get_instance()->db;
        $setting = $this->fixture->ownedSetting('actor-lock-race', 'before');
        $before = $this->fixture->settingRow((int) $setting['id']);
        $actorBefore = $this->fixture->row('users', $this->fixture->actorId);
        $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($providerRole);

        $observer = $this->observer($db);
        $multi = curl_multi_init();
        $handle = null;
        $transactionOpen = false;

        try {
            $ownerId = mysqli_thread_id($db->conn_id);
            self::assertNotSame($ownerId, mysqli_thread_id($observer->conn_id));
            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $db->query(
                    'SELECT * FROM `' .
                        $db->dbprefix('users') .
                        '` WHERE `id` = ' .
                        (int) $this->fixture->actorId .
                        ' FOR UPDATE',
                ),
            );

            $handle = $this->startBasicSettingsUpdate($setting['name'], $multi);
            self::assertTrue($this->waitsForBasicActorLock($multi, $observer, $ownerId));

            self::assertTrue(
                (bool) $db->update(
                    'users',
                    ['id_roles' => (int) $providerRole['id']],
                    ['id' => $this->fixture->actorId],
                ),
            );
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;

            self::assertTrue($this->drain($multi, $handle));
            self::assertSame(401, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            self::assertSame($before, $this->fixture->settingRow((int) $setting['id']));

            $bearer = $this->bearerClient();
            $bearerUpdate = $bearer->requestJsonApp('PUT', 'api/v1/settings_api_v1/update/' . $setting['name'], [
                'value' => 'bearer-control',
            ]);
            self::assertSame(200, $bearerUpdate->statusCode);
            self::assertSame('bearer-control', $this->fixture->settingRow((int) $setting['id'])['value']);
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            if ($handle instanceof CurlHandle) {
                $this->drain($multi, $handle);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
            $observer->close();
            self::assertTrue(
                (bool) $db->update('users', ['id_roles' => $actorBefore['id_roles']], ['id' => $actorBefore['id']]),
                'Could not restore the synthetic actor role.',
            );
            self::assertSame($actorBefore, $this->fixture->row('users', $this->fixture->actorId));
            self::assertTrue(
                (bool) $db->update('settings', ['value' => $before['value']], ['id' => $before['id']]),
                'Could not restore the synthetic setting value.',
            );
        }
    }

    private function startBasicSettingsUpdate(string $settingName, CurlMultiHandle $multi): CurlHandle
    {
        $handle = curl_init($this->server->baseUrl . '/api/v1/settings_api_v1/update/' . $settingName);
        self::assertInstanceOf(CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode(['value' => 'must-not-persist'], JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' .
                base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
                'Content-Type: application/json',
            ],
        ]);
        self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
        return $handle;
    }

    private function observer(object $db): object
    {
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
        self::assertTrue($observer->query('SET SESSION TRANSACTION READ ONLY'));
        return $observer;
    }

    private function waitsForBasicActorLock(CurlMultiHandle $multi, object $observer, int $ownerId): bool
    {
        $deadline = microtime(true) + 8;
        do {
            self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
            self::assertNotFalse(
                $result = $observer->query(
                    'SELECT l.OBJECT_NAME, COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text ' .
                        'FROM performance_schema.data_lock_waits w ' .
                        'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID ' .
                        'JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID ' .
                        'JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID ' .
                        'LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID ' .
                        'WHERE b.PROCESSLIST_ID = ' .
                        $ownerId,
                ),
            );
            foreach ($result->result_array() as $row) {
                $sql = strtoupper(
                    (string) preg_replace('/\s+/', ' ', str_replace('`', '', trim((string) $row['statement_text']))),
                );
                if (
                    $row['OBJECT_NAME'] === $observer->dbprefix('users') &&
                    str_contains($sql, 'FOR UPDATE') &&
                    str_contains($sql, 'ID_ROLES')
                ) {
                    return true;
                }
            }
            if ($running === 0) {
                return false;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function drain(CurlMultiHandle $multi, CurlHandle $handle): bool
    {
        $deadline = microtime(true) + 8;
        do {
            if (curl_multi_exec($multi, $running) !== CURLM_OK) {
                return false;
            }
            if ($running === 0) {
                return curl_errno($handle) === CURLE_OK;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function assertUnauthorizedObservation(GateHttpClient $client, string $path, ?string $keyword = null): void
    {
        $response = $client->get($path, $keyword === null ? [] : ['q' => $keyword]);
        self::assertSame(401, $response->statusCode, $path . ' must reject the demoted actor.');
        self::assertNotEmpty((string) $response->header('www-authenticate'));
        self::assertStringNotContainsString($this->fixture->run, $response->body);
        self::assertStringNotContainsString($this->credentials['token'], $response->body);
    }

    private function jsonObject(GateHttpResponse $response, string $operation): array
    {
        $value = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value, $operation);
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function jsonList(GateHttpResponse $response, string $operation): array
    {
        $value = $this->jsonObject($response, $operation);
        self::assertSame(array_keys($value), array_keys(array_values($value)));
        return $value;
    }

    private function basicClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            ],
        );
    }

    private function bearerClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Bearer ' . $this->credentials['token']],
        );
    }
}

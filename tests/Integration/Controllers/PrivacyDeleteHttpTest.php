<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/**
 * Exercise the public personal-information deletion endpoint over real HTTP.
 *
 * The setting is enabled only inside the disposable Defense Cycle database.
 */
final class PrivacyDeleteHttpTest extends TestCase
{
    private const ENDPOINT = 'privacy/delete_personal_information';
    private const DIRECT_ENDPOINT = 'index.php/privacy/delete_personal_information';

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?array $deleteSetting = null;
    private bool $deleteSettingCaptured = false;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->deleteSetting =
                $db->get_where('settings', ['name' => 'display_delete_personal_information'])->row_array() ?: null;
            $this->deleteSettingCaptured = true;
            if ($this->deleteSetting) {
                self::assertTrue(
                    $db->update('settings', ['value' => '1'], ['name' => 'display_delete_personal_information']),
                );
            } else {
                self::assertTrue(
                    $db->insert('settings', [
                        'name' => 'display_delete_personal_information',
                        'value' => '1',
                    ]),
                );
            }
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                try {
                    $this->restoreDeleteSetting();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $this->restoreDeleteSetting();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testReadCsrfAndTokenFailuresPreserveOwnedState(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);

        $appointment = $fixture->appointment();
        $buffer = $this->addBuffer($appointment);
        $before = $this->ownedState($appointment, $buffer);
        $client = $server->client();
        $token = $this->customerToken($client, (string) $appointment['hash']);
        $cacheKeys = ['customer-token-' . $token];

        try {
            $directClient = new \ReleaseGate\GateHttpClient($server->baseUrl, '');
            $matrixGetUrls = [];
            foreach (
                [[$directClient, self::ENDPOINT], [$directClient, self::DIRECT_ENDPOINT]]
                as [$endpointClient, $endpoint]
            ) {
                foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $response =
                        $method === 'GET'
                            ? $endpointClient->get($endpoint, ['customer_token' => $token])
                            : $endpointClient->requestApp($method, $endpoint, ['customer_token' => $token]);
                    if ($method === 'GET') {
                        $matrixGetUrls[] = $response->url;
                    }
                    self::assertSame(405, $response->statusCode);
                    self::assertStringContainsString('POST', (string) $response->header('allow'));
                    $expectedPath = '/' . trim($endpoint, '/');
                    self::assertStringEndsWith($expectedPath, parse_url($response->url, PHP_URL_PATH) ?: '');
                    self::assertSame($before, $this->ownedState($appointment, $buffer));
                }
            }
            self::assertCount(2, $matrixGetUrls);
            self::assertNotSame($matrixGetUrls[0], $matrixGetUrls[1]);

            $options = $client->requestApp('OPTIONS', self::ENDPOINT);
            self::assertSame(200, $options->statusCode);
            self::assertSame($before, $this->ownedState($appointment, $buffer));

            $missingCsrf = $client->requestApp('POST', self::ENDPOINT, ['customer_token' => $token]);
            self::assertSame(403, $missingCsrf->statusCode);
            self::assertSame($before, $this->ownedState($appointment, $buffer));

            $invalidCsrf = $client->requestApp('POST', self::ENDPOINT, [
                'customer_token' => $token,
                'csrf_token' => 'invalid-synthetic-csrf',
            ]);
            self::assertSame(403, $invalidCsrf->statusCode);
            self::assertSame($before, $this->ownedState($appointment, $buffer));

            $invalidToken = str_repeat('invalid-token-', 8);
            $invalid = $client->post(self::ENDPOINT, ['customer_token' => $invalidToken]);
            self::assertSame(500, $invalid->statusCode);
            self::assertSame($before, $this->ownedState($appointment, $buffer));

            $expiredToken = 'expired-' . $fixture->run;
            $this->cacheToken($expiredToken, $fixture->customerId, 1);
            $cacheKeys[] = 'customer-token-' . $expiredToken;
            sleep(2);
            $expired = $client->post(self::ENDPOINT, ['customer_token' => $expiredToken]);
            self::assertSame(500, $expired->statusCode);
            self::assertSame($before, $this->ownedState($appointment, $buffer));

            $foreignToken = 'foreign-' . $fixture->run;
            $this->cacheToken($foreignToken, $fixture->providerId, 600);
            $cacheKeys[] = 'customer-token-' . $foreignToken;
            $foreign = $client->post(self::ENDPOINT, ['customer_token' => $foreignToken]);
            self::assertSame(500, $foreign->statusCode);
            self::assertSame($before, $this->ownedState($appointment, $buffer));
        } finally {
            $this->deleteCacheKeys($cacheKeys);
        }
    }

    public function testValidTokenDeletesOnlyBoundCustomerAndDependencies(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);

        $appointment = $fixture->appointment();
        $buffer = $this->addBuffer($appointment);
        $client = $server->client();
        $token = $this->customerToken($client, (string) $appointment['hash']);
        $cacheKey = 'customer-token-' . $token;
        $providerBefore = $fixture->row('users', $fixture->providerId);
        $adminBefore = $fixture->row('users', $fixture->actorId);
        $serviceBefore = $fixture->row('services', $fixture->serviceId);

        try {
            $response = $client->post(self::ENDPOINT, ['customer_token' => $token]);
            self::assertSame(200, $response->statusCode);
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($payload['success'] ?? false);
            self::assertSame([], $fixture->row('users', $fixture->customerId));
            self::assertSame([], $fixture->row('appointments', (int) $appointment['id']));
            self::assertSame([], $fixture->row('appointments', (int) $buffer['id']));
            self::assertSame($providerBefore, $fixture->row('users', $fixture->providerId));
            self::assertSame($adminBefore, $fixture->row('users', $fixture->actorId));
            self::assertSame($serviceBefore, $fixture->row('services', $fixture->serviceId));

            $replay = $client->post(self::ENDPOINT, ['customer_token' => $token]);
            self::assertSame(500, $replay->statusCode);
            self::assertSame([], $fixture->row('users', $fixture->customerId));
            self::assertSame($providerBefore, $fixture->row('users', $fixture->providerId));
            self::assertSame($adminBefore, $fixture->row('users', $fixture->actorId));
        } finally {
            $this->deleteCacheKeys([$cacheKey]);
        }
    }

    public function testFailedCustomerDeleteRollsBackBufferAndAllowsTokenRetry(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);

        $db = get_instance()->db;
        $appointment = $fixture->appointment();
        $buffer = $this->addBuffer($appointment);
        $before = $this->ownedState($appointment, $buffer);
        $client = $server->client();
        $token = $this->customerToken($client, (string) $appointment['hash']);
        $cacheKey = 'customer-token-' . $token;
        $trigger = $fixture->run . '_deny_privacy_delete';
        $fixtureAdmin = null;
        $created = false;

        try {
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
            try {
                self::assertTrue(
                    $fixtureAdmin->query(
                        'CREATE TRIGGER `' .
                            $trigger .
                            '` BEFORE DELETE ON `' .
                            $db->dbprefix('users') .
                            '` FOR EACH ROW BEGIN IF OLD.id = ' .
                            (int) $fixture->customerId .
                            " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic privacy delete failure'; END IF; END",
                    ),
                );
                $created = true;

                $failed = $client->post(self::ENDPOINT, ['customer_token' => $token]);
                self::assertSame(500, $failed->statusCode);
                self::assertSame($before, $this->ownedState($appointment, $buffer));
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

            $retry = $client->post(self::ENDPOINT, ['customer_token' => $token]);
            self::assertSame(200, $retry->statusCode);
            self::assertSame([], $fixture->row('users', $fixture->customerId));
            self::assertSame([], $fixture->row('appointments', (int) $appointment['id']));
            self::assertSame([], $fixture->row('appointments', (int) $buffer['id']));
            self::assertSame($before['provider'], $fixture->row('users', $fixture->providerId));
            self::assertSame($before['admin'], $fixture->row('users', $fixture->actorId));
            self::assertSame($before['service'], $fixture->row('services', $fixture->serviceId));
        } finally {
            $this->deleteCacheKeys([$cacheKey]);
        }
    }

    private function customerToken(\ReleaseGate\GateHttpClient $client, string $hash): string
    {
        $response = $client->get('booking/reschedule/' . rawurlencode($hash));
        self::assertSame(200, $response->statusCode);
        $matched = preg_match('/const vars = (\{.*?\});\s*return/s', $response->body, $matches);
        self::assertSame(1, $matched);
        self::assertArrayHasKey(1, $matches);
        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($vars['customer_token'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $vars['customer_token']);
        return $vars['customer_token'];
    }

    private function ownedState(array $appointment, array $buffer): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        return [
            'customer' => $fixture->row('users', $fixture->customerId),
            'appointment' => $fixture->row('appointments', (int) $appointment['id']),
            'buffer' => $fixture->row('appointments', (int) $buffer['id']),
            'provider' => $fixture->row('users', $fixture->providerId),
            'admin' => $fixture->row('users', $fixture->actorId),
            'service' => $fixture->row('services', $fixture->serviceId),
        ];
    }

    private function addBuffer(array $appointment): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        self::assertTrue(
            $db->insert('appointments', [
                'start_datetime' => date('Y-m-d H:i:s', strtotime($appointment['start_datetime']) - 300),
                'end_datetime' => $appointment['start_datetime'],
                'is_unavailability' => 1,
                'id_users_provider' => $fixture->providerId,
                'id_parent_appointment' => $appointment['id'],
                'notes' => $fixture->run,
            ]),
        );
        return $fixture->row('appointments', (int) $db->insert_id());
    }

    private function cacheToken(string $token, int $customerId, int $ttl): void
    {
        $cache = get_instance()->cache ?? null;
        if (!is_object($cache) || !method_exists($cache, 'save')) {
            get_instance()->load->driver('cache', ['adapter' => 'file']);
            $cache = get_instance()->cache;
        }
        self::assertTrue($cache->save('customer-token-' . $token, $customerId, $ttl));
    }

    private function deleteCacheKeys(array $keys): void
    {
        $cache = get_instance()->cache ?? null;
        if (!is_object($cache) || !method_exists($cache, 'delete')) {
            get_instance()->load->driver('cache', ['adapter' => 'file']);
            $cache = get_instance()->cache ?? null;
        }
        self::assertTrue(is_object($cache) && method_exists($cache, 'delete'));
        foreach (array_unique($keys) as $key) {
            $cache->delete($key);
            self::assertFalse($cache->get($key));
        }
    }

    private function restoreDeleteSetting(): void
    {
        if (!$this->deleteSettingCaptured) {
            return;
        }
        $db = get_instance()->db;
        if ($this->deleteSetting) {
            self::assertTrue(
                $db->update(
                    'settings',
                    ['value' => $this->deleteSetting['value']],
                    ['name' => 'display_delete_personal_information'],
                ),
            );
        } else {
            self::assertTrue($db->delete('settings', ['name' => 'display_delete_personal_information']));
        }
        $restored = $db->get_where('settings', ['name' => 'display_delete_personal_information'])->row_array();
        self::assertSame($this->deleteSetting, $restored ?: null);
        $this->deleteSettingCaptured = false;
    }
}

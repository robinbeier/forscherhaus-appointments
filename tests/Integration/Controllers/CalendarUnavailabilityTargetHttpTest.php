<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Regression coverage for provider-only calendar unavailability writes. */
final class CalendarUnavailabilityTargetHttpTest extends TestCase
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

    public function testAuthorizedProviderCreateSucceedsThroughCanonicalRoute(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->authenticatedClient();
        $payload = $this->payload($fixture->providerId, '_provider');

        $response = $client->post('calendar/save_unavailability', ['unavailability' => $payload]);
        self::assertSame(200, $response->statusCode, $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($body['success'] ?? false), $response->body);

        $row = get_instance()
            ->db->get_where('appointments', [
                'notes' => $payload['notes'],
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
            ])
            ->row_array();
        self::assertNotEmpty($row);
        self::assertSame($payload['start_datetime'], $row['start_datetime']);
        self::assertSame($payload['end_datetime'], $row['end_datetime']);
    }

    public function testCustomerAndUnknownProviderIdsAreRejectedWithoutPartialMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->authenticatedClient();
        $before = $this->mutationSnapshot();

        foreach (
            [
                'customer' => [$fixture->customerId, '_customer'],
                'unknown' => [$this->nonexistentUserId(), '_unknown'],
            ]
            as $name => [$providerId, $suffix]
        ) {
            $response = $client->post('calendar/save_unavailability', [
                'unavailability' => $this->payload($providerId, $suffix),
            ]);
            self::assertSame(403, $response->statusCode, $name . ': ' . $response->body);
            $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertFalse((bool) ($body['success'] ?? true), $name . ': ' . $response->body);
            self::assertSame($before, $this->mutationSnapshot(), $name . ' changed persisted state.');
        }

        $aliasClient = $this->authenticatedClient(false);
        $response = $aliasClient->post('backend_api/ajax_save_unavailability', [
            'unavailability' => $this->payload($fixture->customerId, '_alias_customer'),
        ]);
        self::assertSame(303, $response->statusCode, $response->body);
        self::assertStringEndsWith('/calendar/save_unavailability', (string) $response->header('location'));
        self::assertSame($before, $this->mutationSnapshot());
    }

    public function testProviderDisappearanceAfterPrecheckReturns403WithoutCreatingUnavailability(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $client = $this->authenticatedClient();
        $provider = $db->get_where('users', ['id' => $fixture->providerId])->row_array();
        $settings = $fixture->userSettingsRow($fixture->providerId);
        $links = $db->get_where('services_providers', ['id_users' => $fixture->providerId])->result_array();
        self::assertNotEmpty($provider);
        self::assertNotEmpty($settings);
        self::assertNotEmpty($links);

        $observer = $this->observer($db);
        $multi = curl_multi_init();
        $handle = null;
        $transactionOpen = false;
        $providerDeleted = false;
        try {
            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $db->query(
                    'SELECT * FROM `' .
                        $db->dbprefix('users') .
                        '` WHERE `id` = ' .
                        $fixture->providerId .
                        ' FOR UPDATE',
                ),
            );

            $handle = $this->startRequest($client, $this->payload($fixture->providerId, '_disappearance'), $multi);
            self::assertTrue($this->waitsFor($multi, $observer, mysqli_thread_id($db->conn_id), $fixture->providerId));
            self::assertTrue((bool) $db->delete('users', ['id' => $fixture->providerId]));
            $providerDeleted = true;
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;

            self::assertTrue($this->drain($multi, $handle));
            self::assertSame(403, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            $body = json_decode((string) curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse((bool) ($body['success'] ?? true));
            self::assertSame(
                [],
                $db->get_where('appointments', ['notes' => $fixture->run . '_disappearance'])->result_array(),
            );
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
            if ($providerDeleted) {
                self::assertTrue((bool) $db->insert('users', $provider));
                self::assertTrue((bool) $db->insert('user_settings', $settings));
                foreach ($links as $link) {
                    self::assertTrue((bool) $db->insert('services_providers', $link));
                }
            }
        }
    }

    private function authenticatedClient(bool $followRedirects = true): GateHttpClient
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $followRedirects
            ? $this->server?->client()
            : new GateHttpClient($this->server->baseUrl, additionalHeaders: ['X-FH-Test' => 'rob-800']);
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function payload(int $providerId, string $suffix): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        return [
            'start_datetime' => '2035-09-10 10:00:00',
            'end_datetime' => '2035-09-10 10:30:00',
            'notes' => $fixture->run . $suffix,
            'id_users_provider' => $providerId,
            'is_unavailability' => true,
        ];
    }

    private function mutationSnapshot(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        return [
            'appointments' => $db->get_where('appointments', ['notes LIKE' => $fixture->run . '%'])->result_array(),
            'users' => $db->get_where('users', ['notes' => $fixture->run])->result_array(),
            'provider_settings' => $fixture->userSettingsRow($fixture->providerId),
            'customer_settings' => $fixture->userSettingsRow($fixture->customerId),
        ];
    }

    private function nonexistentUserId(): int
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $max = get_instance()->db->select_max('id')->get('users')->row_array()['id'] ?? 0;
        return max((int) $max, $fixture->actorId, $fixture->providerId, $fixture->customerId) + 1000;
    }

    private function startRequest(GateHttpClient $client, array $payload, CurlMultiHandle $multi): CurlHandle
    {
        $token = $client->getCookie('csrf_cookie');
        self::assertNotSame('', (string) $token);
        $cookies = array_map(
            static fn(array $record): string => $record['name'] . '=' . $record['value'],
            $client->cookieRecords(),
        );
        $handle = curl_init($this->server->baseUrl . '/calendar/save_unavailability');
        self::assertInstanceOf(CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['unavailability' => $payload, 'csrf_token' => $token]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Cookie: ' . implode('; ', $cookies),
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

    private function waitsFor(CurlMultiHandle $multi, object $observer, int $ownerId, int $providerId): bool
    {
        $deadline = microtime(true) + 8;
        do {
            self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
            $result = $observer->query(
                'SELECT l.OBJECT_NAME, COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text ' .
                    'FROM performance_schema.data_lock_waits w ' .
                    'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID ' .
                    'JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID ' .
                    'JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID ' .
                    'LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID ' .
                    'WHERE b.PROCESSLIST_ID = ' .
                    $ownerId,
            );
            foreach ($result->result_array() as $row) {
                $sql = strtoupper(
                    (string) preg_replace('/\s+/', ' ', str_replace('`', '', trim((string) $row['statement_text']))),
                );
                if (
                    $row['OBJECT_NAME'] === $observer->dbprefix('users') &&
                    str_contains($sql, 'FOR UPDATE') &&
                    str_contains($sql, (string) $providerId)
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
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Regression coverage for calendar appointment provider target validation. */
final class CalendarAppointmentProviderTargetHttpTest extends TestCase
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

    public function testSaveAppointmentAcceptsProviderAndRejectsCustomerOrUnknownProviderWithoutMutation(): void
    {
        $fixture = $this->requireFixture();
        $client = $this->authenticatedClient();

        $valid = $client->post('calendar/save_appointment', [
            'appointment_data' => $this->appointmentPayload($fixture->providerId, 'valid-provider'),
            'customer_data' => [],
        ]);
        self::assertSame(200, $valid->statusCode, $valid->body);
        self::assertSame(['success' => true], $this->json($valid));
        $created = $this->syntheticAppointments();
        self::assertCount(1, $created);
        self::assertSame($fixture->providerId, (int) $created[0]['id_users_provider']);
        self::assertSame($fixture->customerId, (int) $created[0]['id_users_customer']);

        $before = $this->mutationSnapshot();
        foreach (
            [
                'customer_id' => $fixture->customerId,
                'unknown_id' => $this->unknownUserId(),
            ]
            as $case => $providerId
        ) {
            $customer = $fixture->row('users', $fixture->customerId);
            $customer['first_name'] = 'Must remain unchanged ' . $case;
            $response = $client->post('calendar/save_appointment', [
                'appointment_data' => $this->appointmentPayload($providerId, $case),
                'customer_data' => $customer,
            ]);

            self::assertSame(403, $response->statusCode, $case . ': ' . $response->body);
            $payload = $this->json($response);
            self::assertFalse((bool) ($payload['success'] ?? true), $case . ': ' . $response->body);
            self::assertSame(
                'You do not have the required permissions for this task.',
                $payload['message'] ?? null,
                $case . ': ' . $response->body,
            );
            self::assertSame($before, $this->mutationSnapshot(), $case . ' partially mutated calendar state.');
        }
    }

    public function testDirectLegacyPostAliasRedirectsWithoutForwardingOrMutation(): void
    {
        $fixture = $this->requireFixture();
        $client = $this->authenticatedClient(false);
        $before = $this->mutationSnapshot();
        $response = $client->post('backend_api/ajax_save_appointment', [
            'appointment_data' => $this->appointmentPayload($fixture->providerId, 'legacy-alias'),
            'customer_data' => [],
        ]);

        self::assertSame(303, $response->statusCode, $response->body);
        self::assertStringEndsWith(
            '/calendar/save_appointment',
            (string) parse_url((string) $response->header('location'), PHP_URL_PATH),
        );
        self::assertSame($before, $this->mutationSnapshot());
    }

    public function testProviderDisappearanceAfterPrecheckReturns403WithoutCreatingAppointment(): void
    {
        $fixture = $this->requireFixture();
        $db = get_instance()->db;
        $client = $this->authenticatedClient();
        $provider = $db->get_where('users', ['id' => $fixture->providerId])->row_array();
        $settings = $fixture->userSettingsRow($fixture->providerId);
        $links = $db->get_where('services_providers', ['id_users' => $fixture->providerId])->result_array();
        $customerBefore = $fixture->row('users', $fixture->customerId);
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

            $handle = $this->startAppointmentRequest(
                $client,
                $this->appointmentPayload($fixture->providerId, 'disappearance'),
                $multi,
            );
            self::assertTrue(
                $this->waitsForProvider($multi, $observer, mysqli_thread_id($db->conn_id), $fixture->providerId),
                'Appointment request must wait on the actual provider parent lock.',
            );
            self::assertTrue((bool) $db->delete('users', ['id' => $fixture->providerId]));
            $providerDeleted = true;
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;

            self::assertTrue($this->drain($multi, $handle));
            self::assertSame(403, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            $body = json_decode((string) curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(
                [
                    'success' => false,
                    'message' => 'You do not have the required permissions for this task.',
                ],
                $body,
            );
            self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));
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

    private function requireFixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);
        return $this->fixture;
    }

    private function authenticatedClient(bool $followRedirects = true): GateHttpClient
    {
        $fixture = $this->requireFixture();
        $client = $followRedirects
            ? $this->server?->client()
            : new GateHttpClient($this->server->baseUrl, additionalHeaders: ['X-FH-Test' => 'rob-801']);
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) ($this->json($response)['success'] ?? false));
        return $client;
    }

    private function appointmentPayload(int $providerId, string $case): array
    {
        $fixture = $this->requireFixture();
        return [
            'start_datetime' => date('Y-m-d H:i:00', strtotime('+14 days')),
            'end_datetime' => date('Y-m-d H:i:00', strtotime('+14 days +30 minutes')),
            'notes' => $fixture->run . '_' . $case,
            'id_users_provider' => $providerId,
            'id_users_customer' => $fixture->customerId,
            'id_services' => $fixture->serviceId,
            'is_unavailability' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function syntheticAppointments(): array
    {
        $fixture = $this->requireFixture();
        return get_instance()
            ->db->get_where('appointments', [
                'notes' => $fixture->run . '_valid-provider',
            ])
            ->result_array();
    }

    /** @return array<string, mixed> */
    private function mutationSnapshot(): array
    {
        $fixture = $this->requireFixture();
        return [
            'customer' => $fixture->row('users', $fixture->customerId),
            'appointments' => get_instance()
                ->db->get_where('appointments', [
                    'id_users_customer' => $fixture->customerId,
                ])
                ->result_array(),
            'services' => $fixture->row('services', $fixture->serviceId),
            'service_provider_links' => get_instance()
                ->db->get_where('services_providers', [
                    'id_services' => $fixture->serviceId,
                ])
                ->result_array(),
        ];
    }

    private function unknownUserId(): int
    {
        $fixture = $this->requireFixture();
        $max = (int) (get_instance()->db->select_max('id')->get('users')->row_array()['id'] ?? 0);
        return max($max, $fixture->actorId, $fixture->providerId, $fixture->customerId) + 1000;
    }

    private function startAppointmentRequest(GateHttpClient $client, array $payload, CurlMultiHandle $multi): CurlHandle
    {
        $token = $client->getCookie('csrf_cookie');
        self::assertNotSame('', (string) $token);
        $cookies = array_map(
            static fn(array $record): string => $record['name'] . '=' . $record['value'],
            $client->cookieRecords(),
        );
        $server = $this->server;
        self::assertNotNull($server);
        $handle = curl_init($server->baseUrl . '/calendar/save_appointment');
        self::assertInstanceOf(CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'appointment_data' => $payload,
                'customer_data' => [],
                'csrf_token' => $token,
            ]),
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

    private function waitsForProvider(CurlMultiHandle $multi, object $observer, int $ownerId, int $providerId): bool
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

    /** @return array<string, mixed> */
    private function json(GateHttpResponse $response): array
    {
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }
}

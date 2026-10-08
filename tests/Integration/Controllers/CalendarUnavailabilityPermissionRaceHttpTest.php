<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP races for Calendar unavailability permission rechecks. */
final class CalendarUnavailabilityPermissionRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $providerRole = null;
    private int $ownedId = 0;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }
        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $db = get_instance()->db;
            $this->providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
            self::assertNotEmpty($this->providerRole);
            self::assertTrue(
                (bool) $db->update(
                    'roles',
                    [
                        'appointments' => PRIV_VIEW | PRIV_ADD | PRIV_EDIT | PRIV_DELETE,
                    ],
                    ['id' => (int) $this->providerRole['id']],
                ),
            );
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->cleanupOwned();
            $this->restoreRole();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
            $this->cleanupOwned();
            $this->restoreRole();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function mutationCases(): iterable
    {
        yield 'edit' => ['edit'];
        yield 'delete' => ['delete'];
    }

    #[DataProvider('mutationCases')]
    public function testAuthorizedProviderMutationWaitsOnAppointmentLockAndSucceeds(string $action): void
    {
        $this->runExistingRace($action, false);
    }

    #[DataProvider('mutationCases')]
    public function testRevokedAppointmentPermissionAfterInitialCheckCannotMutate(string $action): void
    {
        $this->runExistingRace($action, true);
    }

    public function testAuthorizedProviderCreateWaitsOnProviderLockAndSucceeds(): void
    {
        $this->runCreateRace(false);
    }

    public function testRevokedAddPermissionAfterInitialCheckCannotCreate(): void
    {
        $this->runCreateRace(true);
    }

    public function testProviderWithoutAppointmentWritePermissionCannotCreateOverHttp(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->loginProvider();
        $before = $this->publicHours();
        $this->revokeAppointmentWritePermission();
        $date = $this->publicDate();

        $response = $client->post('calendar/save_unavailability', [
            'unavailability' => [
                'start_datetime' => $date . ' 10:00:00',
                'end_datetime' => $date . ' 10:30:00',
                'notes' => $fixture->run . '_initial_create',
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => true,
            ],
        ]);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame(
            [],
            get_instance()
                ->db->get_where('appointments', [
                    'notes' => $fixture->run . '_initial_create',
                    'id_users_provider' => $fixture->providerId,
                ])
                ->result_array(),
        );
        self::assertSame($before, $this->publicHours());
    }

    public function testProviderWithoutAppointmentWritePermissionCannotEditOverHttp(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->loginProvider();
        $date = $this->publicDate();
        $id = $this->createUnavailability($date);
        $this->ownedId = $id;
        $before = $fixture->row('appointments', $id);
        $beforeHours = $this->publicHours(false);
        $this->revokeAppointmentWritePermission();

        $response = $client->post('calendar/save_unavailability', [
            'unavailability' => [
                'id' => $id,
                'start_datetime' => $date . ' 09:00:00',
                'end_datetime' => $date . ' 09:30:00',
                'notes' => $fixture->run . '_initial_edit',
                'id_users_provider' => $fixture->providerId,
            ],
        ]);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($before, $fixture->row('appointments', $id));
        self::assertSame($beforeHours, $this->publicHours(false));
    }

    public function testProviderWithoutAppointmentWritePermissionCannotDeleteOverHttp(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->loginProvider();
        $id = $this->createUnavailability($this->publicDate());
        $this->ownedId = $id;
        $before = $fixture->row('appointments', $id);
        $beforeHours = $this->publicHours(false);
        $this->revokeAppointmentWritePermission();

        $response = $client->post('calendar/delete_unavailability', ['unavailability_id' => $id]);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($before, $fixture->row('appointments', $id));
        self::assertSame($beforeHours, $this->publicHours(false));
    }

    private function runCreateRace(bool $revoke): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $client = $this->loginProvider();
        $before = $db->get_where('appointments', ['notes' => $fixture->run . '_create_race'])->result_array();
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
                        $fixture->providerId .
                        ' FOR UPDATE',
                ),
            );
            $handle = $this->startRequest(
                $client,
                'calendar/save_unavailability',
                [
                    'unavailability' => [
                        'start_datetime' => '2035-06-02 09:00:00',
                        'end_datetime' => '2035-06-02 09:30:00',
                        'notes' => $fixture->run . '_create_race',
                        'id_users_provider' => $fixture->providerId,
                        'is_unavailability' => true,
                    ],
                ],
                $multi,
            );
            self::assertTrue($this->waitsFor($multi, $observer, $ownerId, 'users', $fixture->providerId));
            if ($revoke) {
                self::assertTrue(
                    (bool) $db->update(
                        'roles',
                        ['appointments' => PRIV_VIEW],
                        [
                            'id' => (int) $this->providerRole['id'],
                        ],
                    ),
                );
            }
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi, $handle));
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $after = $db->get_where('appointments', ['notes' => $fixture->run . '_create_race'])->result_array();
            if ($revoke) {
                self::assertSame(403, $status);
                self::assertSame($before, $after);
            } else {
                self::assertSame(200, $status);
                self::assertCount(1, $after);
                $this->ownedId = (int) $after[0]['id'];
                self::assertSame($fixture->providerId, (int) $after[0]['id_users_provider']);
                self::assertSame('1', (string) $after[0]['is_unavailability']);
            }
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
            $this->cleanupOwned();
        }
    }

    private function runExistingRace(string $action, bool $revoke): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $client = $this->loginProvider();
        $id = $this->createUnavailability();
        $this->ownedId = $id;
        $before = $fixture->row('appointments', $id);
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
                $db->query('SELECT * FROM `' . $db->dbprefix('appointments') . '` WHERE `id` = ' . $id . ' FOR UPDATE'),
            );
            $fields =
                $action === 'edit'
                    ? [
                        'unavailability' => [
                            'id' => $id,
                            'start_datetime' => '2035-06-01 09:00:00',
                            'end_datetime' => '2035-06-01 09:30:00',
                            'notes' => $fixture->run . '_edit',
                            'id_users_provider' => $fixture->providerId,
                        ],
                    ]
                    : ['unavailability_id' => $id];
            $handle = $this->startRequest(
                $client,
                'calendar/' . ($action === 'edit' ? 'save_unavailability' : 'delete_unavailability'),
                $fields,
                $multi,
            );
            self::assertTrue($this->waitsFor($multi, $observer, $ownerId, 'appointments', $id));
            if ($revoke) {
                self::assertTrue(
                    (bool) $db->update(
                        'roles',
                        ['appointments' => PRIV_VIEW],
                        ['id' => (int) $this->providerRole['id']],
                    ),
                );
            }
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi, $handle));
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $after = $fixture->row('appointments', $id);
            if ($revoke) {
                if ($status === 200) {
                    if ($action === 'edit') {
                        self::assertSame('2035-06-01 09:00:00', $after['start_datetime']);
                    } else {
                        self::assertSame([], $after);
                    }
                }
                self::assertSame(403, $status);
                self::assertSame($before, $after);
            } elseif ($action === 'edit') {
                self::assertSame(200, $status);
                self::assertSame('2035-06-01 09:00:00', $after['start_datetime']);
                self::assertSame('2035-06-01 09:30:00', $after['end_datetime']);
            } else {
                self::assertSame(200, $status);
                self::assertSame([], $after);
            }
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
            $this->cleanupOwned();
        }
    }

    private function startRequest(
        GateHttpClient $client,
        string $path,
        array $fields,
        CurlMultiHandle $multi,
    ): CurlHandle {
        $token = $client->getCookie('csrf_cookie');
        self::assertNotSame('', (string) $token);
        $fields['csrf_token'] = $token;
        $cookies = array_map(static fn(array $r): string => $r['name'] . '=' . $r['value'], $client->cookieRecords());
        $handle = curl_init($this->server->baseUrl . '/' . $path);
        self::assertInstanceOf(CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
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

    private function waitsFor(CurlMultiHandle $multi, object $observer, int $ownerId, string $table, int $id): bool
    {
        $deadline = microtime(true) + 8;
        do {
            self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
            self::assertNotFalse(
                $result = $observer->query(
                    'SELECT l.OBJECT_NAME, COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID WHERE b.PROCESSLIST_ID = ' .
                        $ownerId,
                ),
            );
            foreach ($result->result_array() as $row) {
                $sql = strtoupper(
                    (string) preg_replace('/\s+/', ' ', str_replace('`', '', trim((string) $row['statement_text']))),
                );
                if (
                    $row['OBJECT_NAME'] === $observer->dbprefix($table) &&
                    str_contains($sql, 'FOR UPDATE') &&
                    str_contains($sql, (string) $id)
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

    private function loginProvider(): GateHttpClient
    {
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['provider_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        return $client;
    }

    private function revokeAppointmentWritePermission(): void
    {
        self::assertTrue(
            (bool) get_instance()->db->update(
                'roles',
                ['appointments' => PRIV_VIEW],
                ['id' => (int) $this->providerRole['id']],
            ),
        );
    }

    /** @return list<string> */
    private function publicHours(bool $expectSlot = true): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $public = $this->server?->client();
        self::assertNotNull($public);
        self::assertSame(200, $public->get('booking')->statusCode);
        $date = $this->publicDate();
        $response = $public->post('booking/get_available_hours', [
            'provider_id' => $fixture->providerId,
            'service_id' => $fixture->serviceId,
            'selected_date' => $date,
            'manage_mode' => 'false',
            'appointment_id' => '',
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        $hours = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($hours);
        if ($expectSlot) {
            self::assertContains('10:00', $hours);
        } else {
            self::assertNotContains('10:00', $hours);
        }
        return array_values(array_map('strval', $hours));
    }

    private function publicDate(): string
    {
        return (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days')->format('Y-m-d');
    }

    private function createUnavailability(?string $date = null): int
    {
        $f = $this->fixture;
        $now = date('Y-m-d H:i:s');
        $dynamicDate = $date !== null;
        $date ??= '2035-06-01';
        $start = $dynamicDate ? '10:00:00' : '08:00:00';
        $end = $dynamicDate ? '10:30:00' : '08:30:00';
        self::assertTrue(
            (bool) get_instance()->db->insert('appointments', [
                'book_datetime' => $now,
                'start_datetime' => $date . ' ' . $start,
                'end_datetime' => $date . ' ' . $end,
                'notes' => $f->run . '_race',
                'hash' => $f->run . '_race_hash',
                'is_unavailability' => 1,
                'id_users_provider' => $f->providerId,
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]),
        );
        return (int) get_instance()->db->insert_id();
    }

    private function cleanupOwned(): void
    {
        // The create request may have inserted a row even when an assertion fails
        // before its ID can be recorded. Only remove this fixture's exact run tag.
        if ($this->fixture !== null && isset($this->fixture->providerId)) {
            get_instance()->db->delete('appointments', [
                'notes' => $this->fixture->run . '_initial_create',
                'id_users_provider' => $this->fixture->providerId,
                'is_unavailability' => 1,
            ]);
            get_instance()->db->delete('appointments', [
                'notes' => $this->fixture->run . '_create_race',
                'id_users_provider' => $this->fixture->providerId,
                'is_unavailability' => 1,
            ]);
        }
        if ($this->ownedId > 0) {
            get_instance()->db->delete('appointments', ['id' => $this->ownedId]);
            $this->ownedId = 0;
        }
    }

    private function restoreRole(): void
    {
        if ($this->providerRole !== null) {
            get_instance()->db->update(
                'roles',
                ['appointments' => $this->providerRole['appointments']],
                ['id' => (int) $this->providerRole['id']],
            );
        }
    }
}

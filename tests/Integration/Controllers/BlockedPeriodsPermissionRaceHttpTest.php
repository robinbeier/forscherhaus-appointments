<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP coverage for actor demotion during a legacy blocked-period update. */
final class BlockedPeriodsPermissionRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $customerRole = null;
    private int $periodId = 0;
    private ?int $actorRoleBefore = null;

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
            $this->actorRoleBefore = (int) ($this->fixture->row('users', $this->fixture->actorId)['id_roles'] ?? 0);
            $this->customerRole = $db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();
            self::assertNotEmpty($this->customerRole);
            $this->server = new DefenseCycleHttpServer();
            $payload = $this->fixture->blockedPeriodWritePayload('permission-race');
            self::assertTrue((bool) $db->insert('blocked_periods', $payload));
            $this->periodId = (int) $db->insert_id();
            self::assertSame($payload['name'], $this->fixture->blockedPeriodRow($this->periodId)['name'] ?? null);
        } catch (Throwable $error) {
            $this->server?->close();
            $this->restoreActorRole();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
            $this->restoreActorRole();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function mutationCases(): iterable
    {
        yield 'store' => ['store'];
        yield 'update' => ['update'];
        yield 'destroy' => ['destroy'];
    }

    #[DataProvider('mutationCases')]
    public function testDemotionCommittedDuringBlockedMutationCannotMutateOwnedPeriod(string $action): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        $before = $action === 'store' ? [] : $fixture->blockedPeriodRow($this->periodId);
        $payload = $action === 'update' ? $fixture->blockedPeriodWritePayload('permission-race-update') : [];
        if ($action === 'update') {
            $payload['id'] = $this->periodId;
        }
        $fields = match ($action) {
            'store' => ['blocked_period' => $fixture->blockedPeriodWritePayload('permission-race-store')],
            'update' => ['blocked_period' => $payload],
            'destroy' => ['blocked_period_id' => (string) $this->periodId],
        };
        $path = 'blocked_periods/' . $action;
        $db = get_instance()->db;
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
                    'SELECT * FROM `' . $db->dbprefix('users') . '` WHERE `id` = ' . $fixture->actorId . ' FOR UPDATE',
                ),
            );

            $handle = $this->startRequest($admin, $path, $fields, $multi);
            self::assertTrue($this->waitsForActorLock($multi, $observer, $ownerId, $fixture->actorId));
            self::assertTrue(
                (bool) $db->update(
                    'users',
                    ['id_roles' => (int) $this->customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;

            self::assertTrue($this->drain($multi, $handle));
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            self::assertSame(403, $status);
            if ($action === 'store') {
                self::assertSame(
                    0,
                    $db->get_where('blocked_periods', ['name' => $fields['blocked_period']['name']])->num_rows(),
                );
            } else {
                self::assertSame($before, $fixture->blockedPeriodRow($this->periodId));
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
            $this->restorePeriodSnapshot($action, $before, $fields);
        }
    }

    private function restorePeriodSnapshot(string $action, array $before, array $fields): void
    {
        $db = get_instance()->db;
        if ($action === 'store') {
            $db->delete('blocked_periods', ['name' => $fields['blocked_period']['name']]);
            return;
        }
        if ($before === []) {
            return;
        }
        $id = (int) $before['id'];
        if ($db->get_where('blocked_periods', ['id' => $id])->num_rows() === 0) {
            $db->insert('blocked_periods', $before);
        } else {
            $db->update('blocked_periods', $before, ['id' => $id]);
        }
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
        return $client;
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
        $cookies = array_map(
            static fn(array $row): string => $row['name'] . '=' . $row['value'],
            $client->cookieRecords(),
        );
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

    private function waitsForActorLock(CurlMultiHandle $multi, object $observer, int $ownerId, int $actorId): bool
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
                    $row['OBJECT_NAME'] === $observer->dbprefix('users') &&
                    str_contains($sql, 'FOR UPDATE') &&
                    str_contains($sql, (string) $actorId)
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

    private function restoreActorRole(): void
    {
        if ($this->fixture !== null && $this->actorRoleBefore !== null && isset($this->fixture->actorId)) {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }
}

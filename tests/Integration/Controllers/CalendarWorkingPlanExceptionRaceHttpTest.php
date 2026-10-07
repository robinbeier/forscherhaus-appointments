<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP races for Calendar working-plan exception authorization and writes. */
final class CalendarWorkingPlanExceptionRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $providerRole = null;
    private ?array $customerRole = null;
    private ?array $actorRole = null;

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
            $this->customerRole = $db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();
            $this->actorRole = $db
                ->select('roles.*')
                ->from('users')
                ->join('roles', 'roles.id = users.id_roles', 'inner')
                ->where('users.id', $this->fixture->actorId)
                ->get()
                ->row_array();
            self::assertNotEmpty($this->providerRole);
            self::assertNotEmpty($this->customerRole);
            self::assertNotEmpty($this->actorRole);
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->restoreRoles();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
            $this->restoreRoles();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testAuthorizedExceptionWriteWaitsOnProviderLockAndSucceeds(): void
    {
        $this->runRace(false);
    }

    public function testRoleRevocationWhileExceptionWriteWaitsCannotMutate(): void
    {
        $this->runRace(true);
    }

    public function testTwoAuthorizedExceptionWritesWaitingOnProviderLockPreserveBothDates(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $firstClient = $this->loginAdmin();
        $secondClient = $this->loginAdmin();
        $firstDate = '2035-07-03';
        $secondDate = '2035-07-04';
        $before = $this->workingPlanExceptions();
        $observer = $this->observer($db);
        $multi = curl_multi_init();
        $handles = [];
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

            $handles[] = $this->startRequest($firstClient, $firstDate, $multi);
            $handles[] = $this->startRequest($secondClient, $secondDate, $multi);
            self::assertTrue(
                $this->waitsForCount($multi, $observer, $ownerId, 'users', $fixture->providerId, 2),
            );

            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            foreach ($handles as $handle) {
                self::assertTrue($this->drain($multi, $handle));
                self::assertSame(200, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
                $response = json_decode((string) curl_multi_getcontent($handle), true, 512, JSON_THROW_ON_ERROR);
                self::assertTrue((bool) ($response['success'] ?? false));
            }

            $after = $this->workingPlanExceptions();
            self::assertArrayNotHasKey($firstDate, $before);
            self::assertArrayNotHasKey($secondDate, $before);
            self::assertSame(
                ['start' => '10:00', 'end' => '12:00', 'breaks' => []],
                $after[$firstDate] ?? null,
            );
            self::assertSame(
                ['start' => '10:00', 'end' => '12:00', 'breaks' => []],
                $after[$secondDate] ?? null,
            );
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            foreach ($handles as $handle) {
                if ($handle instanceof CurlHandle) {
                    $this->drain($multi, $handle);
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                }
            }
            curl_multi_close($multi);
            $observer->close();
        }
    }

    private function runRace(bool $revoke): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $client = $this->loginAdmin();
        $date = $revoke ? '2035-07-02' : '2035-07-01';
        $before = $this->workingPlanExceptions();
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

            $handle = $this->startRequest($client, $date, $multi);
            self::assertTrue($this->waitsFor($multi, $observer, $ownerId, 'users', $fixture->providerId));

            if ($revoke) {
                self::assertTrue(
                    (bool) $db->update(
                        'users',
                        ['id_roles' => (int) $this->customerRole['id']],
                        ['id' => $fixture->actorId],
                    ),
                );
            }

            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi, $handle));
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $after = $this->workingPlanExceptions();

            if ($revoke) {
                self::assertSame($before, $after, 'HTTP ' . $status . ': ' . curl_multi_getcontent($handle));
                self::assertGreaterThanOrEqual(400, $status);
            } else {
                self::assertSame(200, $status);
                self::assertSame(['start' => '10:00', 'end' => '12:00', 'breaks' => []], $after[$date] ?? null);
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
        }
    }

    private function startRequest(GateHttpClient $client, string $date, CurlMultiHandle $multi): CurlHandle
    {
        $token = $client->getCookie('csrf_cookie');
        self::assertNotSame('', (string) $token);
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $fields = [
            'csrf_token' => $token,
            'provider_id' => $fixture->providerId,
            'date' => $date,
            'working_plan_exception' => ['start' => '10:00', 'end' => '12:00', 'breaks' => []],
        ];
        $cookies = array_map(static fn(array $r): string => $r['name'] . '=' . $r['value'], $client->cookieRecords());
        $handle = curl_init($this->server->baseUrl . '/calendar/save_working_plan_exception');
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

    private function waitsFor(CurlMultiHandle $multi, object $observer, int $ownerId, string $table, int $id): bool
    {
        return $this->waitsForCount($multi, $observer, $ownerId, $table, $id, 1);
    }

    private function waitsForCount(
        CurlMultiHandle $multi,
        object $observer,
        int $ownerId,
        string $table,
        int $id,
        int $expected,
    ): bool {
        $deadline = microtime(true) + 8;
        do {
            self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
            self::assertNotFalse(
                $result = $observer->query(
                    'SELECT l.OBJECT_NAME, r.PROCESSLIST_ID AS waiting_id,
                            COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text
                     FROM performance_schema.data_lock_waits w
                     JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                     JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
                     JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
                     LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID
                     WHERE b.PROCESSLIST_ID = ' . $ownerId,
                ),
            );
            $waitingIds = [];
            foreach ($result->result_array() as $row) {
                $sql = strtoupper(
                    (string) preg_replace('/\s+/', ' ', str_replace('`', '', trim((string) $row['statement_text']))),
                );
                if (
                    $row['OBJECT_NAME'] === $observer->dbprefix($table) &&
                    str_contains($sql, 'FOR UPDATE') &&
                    str_contains($sql, (string) $id)
                ) {
                    $waitingIds[] = (int) $row['waiting_id'];
                }
            }
            if (count(array_unique($waitingIds)) >= $expected) {
                return true;
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

    private function loginAdmin(): GateHttpClient
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['admin_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        return $client;
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

    private function workingPlanExceptions(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $row = $fixture->userSettingsRow($fixture->providerId);
        return json_decode((string) ($row['working_plan_exceptions'] ?? '{}'), true) ?: [];
    }

    private function restoreRoles(): void
    {
        if ($this->fixture === null || $this->providerRole === null || $this->actorRole === null) {
            return;
        }
        get_instance()->db->update(
            'users',
            ['id_roles' => (int) $this->providerRole['id']],
            ['id' => $this->fixture->providerId, 'notes' => $this->fixture->run],
        );
        get_instance()->db->update(
            'users',
            ['id_roles' => (int) $this->actorRole['id']],
            ['id' => $this->fixture->actorId, 'notes' => $this->fixture->run],
        );
    }
}

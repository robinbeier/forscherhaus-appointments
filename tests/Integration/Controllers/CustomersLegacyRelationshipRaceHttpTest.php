<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Deterministic HTTP/DB race coverage for provider customer relationship revocation. */
final class CustomersLegacyRelationshipRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $providerRole = null;
    private ?array $limitAccess = null;
    /** @var list<string> */
    private array $lastWaitDiagnostics = [];

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
            $this->limitAccess = $db->get_where('settings', ['name' => 'limit_customer_access'])->row_array() ?: null;
            self::assertNotEmpty($this->providerRole);
            self::assertNotEmpty($this->limitAccess);
            self::assertTrue(
                (bool) $db->update(
                    'roles',
                    ['customers' => PRIV_VIEW | PRIV_EDIT | PRIV_DELETE],
                    ['id' => (int) $this->providerRole['id']],
                ),
            );
            self::assertTrue((bool) $db->update('settings', ['value' => '1'], ['name' => 'limit_customer_access']));
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->restoreAuthorityFixtures();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->restoreAuthorityFixtures();
            $this->fixture?->cleanup();
        }
    }

    public function testAuthorizedProviderControlsCanUpdateAndDeleteOwnCustomer(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->loginProvider();
        $fixture->appointment();
        $before = $fixture->row('users', $fixture->customerId);
        $payload = array_replace($before, ['id' => $fixture->customerId, 'first_name' => $fixture->run . '_updated']);
        $updated = $client->post('customers/update', ['customer' => $payload]);
        self::assertSame(200, $updated->statusCode, $updated->body);
        self::assertSame($payload['first_name'], $fixture->row('users', $fixture->customerId)['first_name']);
        get_instance()->db->update('users', $before, ['id' => $fixture->customerId]);

        $secondary = $fixture->customerWritePayload('destroy-control');
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertTrue(
            (bool) get_instance()->db->insert(
                'users',
                $secondary + [
                    'timezone' => 'UTC',
                    'language' => 'english',
                    'id_roles' => (int) $role['id'],
                    'is_private' => 0,
                ],
            ),
        );
        $secondaryId = (int) get_instance()->db->insert_id();
        self::assertTrue(
            (bool) get_instance()->db->insert('appointments', [
                'book_datetime' => date('Y-m-d H:i:s'),
                'start_datetime' => date('Y-m-d 12:00:00', strtotime('+14 days')),
                'end_datetime' => date('Y-m-d 12:30:00', strtotime('+14 days')),
                'notes' => $fixture->run,
                'hash' => bin2hex(random_bytes(32)),
                'is_unavailability' => 0,
                'id_users_provider' => $fixture->providerId,
                'id_users_customer' => $secondaryId,
                'id_services' => $fixture->serviceId,
            ]),
        );
        $deleted = $client->post('customers/destroy', ['customer_id' => $secondaryId]);
        self::assertSame(200, $deleted->statusCode, $deleted->body);
        self::assertSame([], $fixture->row('users', $secondaryId));
        get_instance()->db->delete('appointments', ['id_users_customer' => $secondaryId]);
    }

    /** @return iterable<string, array{string,string}> */
    public static function relationshipRaceCases(): iterable
    {
        foreach (
            [
                'customers/update' => 'update',
                'index.php/customers/update' => 'update',
                'customers/destroy' => 'destroy',
                'index.php/customers/destroy' => 'destroy',
            ]
            as $path => $action
        ) {
            yield $path => [$path, $action];
        }
    }

    #[DataProvider('relationshipRaceCases')]
    public function testRevokedRelationshipIsRecheckedAfterCustomerLockCommit(string $path, string $action): void
    {
        $fixture = $this->fixture;
        $db = get_instance()->db;
        self::assertNotNull($fixture);
        $client = $this->loginProvider(
            str_starts_with($path, 'index.php/') ? new GateHttpClient($this->server->baseUrl, indexPage: '') : null,
        );
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $appointment = $fixture->appointment();
        $appointmentId = (int) $appointment['id'];
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
            $ownerId = mysqli_thread_id($db->conn_id);
            self::assertNotSame($ownerId, mysqli_thread_id($observer->conn_id));
            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $db->query(
                    'SELECT * FROM `' .
                        $db->dbprefix('users') .
                        '` WHERE `id` = ' .
                        $fixture->customerId .
                        ' FOR UPDATE',
                ),
            );
            self::assertTrue((bool) $db->delete('appointments', ['id' => $appointmentId]));

            $csrf = $client->getCookie('csrf_cookie');
            self::assertNotSame('', (string) $csrf);
            $cookies = array_map(
                static fn(array $r): string => $r['name'] . '=' . $r['value'],
                $client->cookieRecords(),
            );
            $racedName = $fixture->run . '_race_update';
            $fields =
                $action === 'update'
                    ? [
                        'customer' => array_replace($beforeCustomer, [
                            'id' => $fixture->customerId,
                            'first_name' => $racedName,
                        ]),
                        'csrf_token' => $csrf,
                    ]
                    : ['customer_id' => $fixture->customerId, 'csrf_token' => $csrf];
            $multi = curl_multi_init();
            $handle = curl_init($this->server->baseUrl . '/' . $path);
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
            $waiting = false;
            $deadline = microtime(true) + 8;
            do {
                self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
                $waiting = $this->observesCustomerWait($observer, $ownerId, $fixture->customerId);
                if ($waiting || $running === 0) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (microtime(true) < $deadline);
            self::assertTrue(
                $waiting,
                'HTTP customer write must wait on the owner customer-row lock. Observed: ' .
                    implode(' | ', $this->lastWaitDiagnostics),
            );
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi, $handle));
            $responseStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $afterCustomer = $fixture->row('users', $fixture->customerId);
            if ($action === 'update' && $responseStatus === 200) {
                self::assertSame($racedName, $afterCustomer['first_name'] ?? null);
            }
            if ($action === 'destroy' && $responseStatus === 200) {
                self::assertSame([], $afterCustomer);
            }
            self::assertSame(403, $responseStatus);
            self::assertSame($beforeCustomer, $afterCustomer);
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            if ($multi instanceof CurlMultiHandle && $handle instanceof CurlHandle) {
                $this->drain($multi, $handle);
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

    private function loginProvider(?GateHttpClient $client = null): GateHttpClient
    {
        $client ??= $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['provider_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        return $client;
    }

    private function observesCustomerWait(object $observer, int $ownerId, int $customerId): bool
    {
        $result = $observer->query(
            'SELECT l.OBJECT_NAME, COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID WHERE b.PROCESSLIST_ID = ' .
                $ownerId,
        );
        self::assertNotFalse($result);
        $this->lastWaitDiagnostics = [];
        foreach ($result->result_array() as $row) {
            $statement = strtoupper(str_replace('`', '', (string) $row['statement_text']));
            $statement = (string) preg_replace('/\s+/', ' ', trim($statement));
            $this->lastWaitDiagnostics[] =
                ($row['OBJECT_NAME'] ?? '?') . ':' . preg_replace('/\b\d+\b/', '<id>', $statement);
            $isTargetLockingRead = str_contains($statement, 'FOR UPDATE');
            $isTargetUpdate = str_starts_with(
                ltrim($statement),
                'UPDATE ' . strtoupper($observer->dbprefix('users')) . ' ',
            );
            if (
                $row['OBJECT_NAME'] === $observer->dbprefix('users') &&
                ($isTargetLockingRead || $isTargetUpdate) &&
                str_contains($statement, (string) $customerId)
            ) {
                return true;
            }
        }
        return false;
    }

    private function drain(CurlMultiHandle $multi, CurlHandle $handle): bool
    {
        $deadline = microtime(true) + 8;
        do {
            $result = curl_multi_exec($multi, $running);
            if ($result !== CURLM_OK) {
                return false;
            }
            if ($running === 0) {
                return curl_errno($handle) === CURLE_OK;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function restoreAuthorityFixtures(): void
    {
        $db = get_instance()->db;
        if ($this->providerRole !== null) {
            $db->update(
                'roles',
                ['customers' => $this->providerRole['customers']],
                ['id' => (int) $this->providerRole['id']],
            );
        }
        if ($this->limitAccess !== null) {
            $db->update('settings', ['value' => $this->limitAccess['value']], ['id' => (int) $this->limitAccess['id']]);
        }
    }
}

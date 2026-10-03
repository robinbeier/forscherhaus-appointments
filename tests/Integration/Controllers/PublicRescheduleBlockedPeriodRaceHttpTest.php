<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP/DB coverage for a global block committed during public rescheduling. */
final class PublicRescheduleBlockedPeriodRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?GateHttpClient $client = null;
    private ?array $displayEmailSetting = null;
    private ?array $requireEmailSetting = null;
    private ?array $displayTermsSetting = null;
    private ?int $blockedPeriodId = null;
    private ?int $appointmentId = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->displayEmailSetting = $db->get_where('settings', ['name' => 'display_email'])->row_array() ?: null;
            $this->requireEmailSetting = $db->get_where('settings', ['name' => 'require_email'])->row_array() ?: null;
            $this->displayTermsSetting =
                $db->get_where('settings', ['name' => 'display_terms_and_conditions'])->row_array() ?: null;
            self::assertNotNull($this->displayEmailSetting);
            self::assertNotNull($this->requireEmailSetting);
            self::assertNotNull($this->displayTermsSetting);
            $db->update('settings', ['value' => '0'], ['id' => $this->displayEmailSetting['id']]);
            $db->update('settings', ['value' => '0'], ['id' => $this->requireEmailSetting['id']]);
            $db->update('settings', ['value' => '1'], ['id' => $this->displayTermsSetting['id']]);
            $this->server = new DefenseCycleHttpServer();
            $this->client = $this->server->client();
            self::assertSame(200, $this->client->get('booking')->statusCode);
        } catch (Throwable $error) {
            $this->server?->close();
            $this->restoreSettings();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $db = get_instance()->db;
                if ($this->blockedPeriodId !== null) {
                    $db->delete('blocked_periods', ['id' => $this->blockedPeriodId]);
                    self::assertSame([], $this->fixture?->blockedPeriodRow($this->blockedPeriodId));
                }
                if ($this->appointmentId !== null) {
                    $db->delete('reschedule_authorities', ['appointment_id' => $this->appointmentId]);
                }
                if ($this->fixture !== null) {
                    $db->delete('consents', [
                        'first_name' => 'Synthetic',
                        'last_name' => 'customer',
                        'email' => $this->fixture->run . '_customer@synthetic.invalid',
                    ]);
                    self::assertSame(
                        0,
                        $db
                            ->where('first_name', 'Synthetic')
                            ->where('last_name', 'customer')
                            ->where('email', $this->fixture->run . '_customer@synthetic.invalid')
                            ->count_all_results('consents'),
                    );
                }
            } finally {
                try {
                    $this->restoreSettings();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
        }
    }

    public function testRescheduleRechecksGlobalBlockCommittedWhileWaitingForProviderLock(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $db = get_instance()->db;
        $appointment = $fixture->appointment();
        $this->appointmentId = (int) $appointment['id'];
        $beforeAppointment = $fixture->row('appointments', $this->appointmentId);
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $beforeService = $fixture->row('services', $fixture->serviceId);
        $consentIdentity = [
            'first_name' => (string) $beforeCustomer['first_name'],
            'last_name' => (string) $beforeCustomer['last_name'],
            'email' => (string) $beforeCustomer['email'],
            'type' => 'terms-and-conditions',
        ];
        $beforeOwnConsentCount = $db->get_where('consents', $consentIdentity)->num_rows();
        $target = (new DateTimeImmutable('today'))->modify('+15 days')->setTime(10, 0);
        $blockStart = $target->format('Y-m-d H:i:s');
        $targetPayload = $this->payload($beforeAppointment, $beforeCustomer, $target, 'race');
        $observer = $this->independentConnection();
        $writer = $this->independentConnection();
        $ownerId = mysqli_thread_id($db->conn_id);
        $transactionOpen = false;
        $handle = null;
        $multi = null;

        try {
            self::assertSame(
                200,
                $client->get('booking/reschedule/' . rawurlencode((string) $appointment['hash']))->statusCode,
            );
            $authorityBefore = $db
                ->get_where('reschedule_authorities', ['appointment_id' => $this->appointmentId])
                ->row_array();
            self::assertNotEmpty($authorityBefore);
            self::assertNull($authorityBefore['consumed_at']);

            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            $usersTable = $db->dbprefix('users');
            self::assertNotFalse(
                $db->query('SELECT `id` FROM `' . $usersTable . '` WHERE `id` IN (?, ?) ORDER BY `id` FOR UPDATE', [
                    $fixture->customerId,
                    $fixture->providerId,
                ]),
            );

            $csrf = (string) $client->getCookie('csrf_cookie');
            self::assertNotSame('', $csrf);
            $session = (string) $client->getCookie('ea_session');
            self::assertNotSame('', $session);
            $handle = curl_init($this->server?->baseUrl . '/index.php/booking/register');
            self::assertInstanceOf(CurlHandle::class, $handle);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(
                    ['post_data' => $targetPayload, 'csrf_token' => $csrf],
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                ),
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                    'Cookie: csrf_cookie=' . $csrf . '; ea_session=' . $session,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 20,
            ]);
            $multi = curl_multi_init();
            self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
            self::assertTrue($this->waitForProviderWait($observer, $ownerId, $usersTable, $multi));

            self::assertTrue($writer->trans_begin());
            self::assertTrue(
                $writer->insert('blocked_periods', [
                    'name' => $fixture->run . '_reschedule_race_block',
                    'start_datetime' => $blockStart,
                    'end_datetime' => $target->modify('+30 minutes')->format('Y-m-d H:i:s'),
                    'notes' => $fixture->run,
                ]),
            );
            $this->blockedPeriodId = (int) $writer->insert_id();
            self::assertGreaterThan(0, $this->blockedPeriodId);
            self::assertTrue($writer->trans_commit());
            self::assertSame(
                $blockStart,
                $observer->get_where('blocked_periods', ['id' => $this->blockedPeriodId])->row_array()[
                    'start_datetime'
                ] ?? null,
            );

            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi));
            $body = (string) curl_multi_getcontent($handle);
            self::assertSame(409, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
            self::assertSame(
                ['success' => false, 'message' => lang('requested_hour_is_unavailable')],
                json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            );
            self::assertSame($beforeAppointment, $fixture->row('appointments', $this->appointmentId));
            self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
            self::assertSame($beforeService, $fixture->row('services', $fixture->serviceId));
            self::assertSame($beforeOwnConsentCount, $db->get_where('consents', $consentIdentity)->num_rows());
            self::assertNotNull(
                $db->get_where('reschedule_authorities', ['appointment_id' => $this->appointmentId])->row_array()[
                    'consumed_at'
                ] ?? null,
            );

            self::assertTrue($db->delete('blocked_periods', ['id' => $this->blockedPeriodId]));
            self::assertSame([], $fixture->blockedPeriodRow($this->blockedPeriodId));
            $this->blockedPeriodId = null;
            self::assertSame(
                200,
                $client->get('booking/reschedule/' . rawurlencode((string) $appointment['hash']))->statusCode,
            );
            $positive = $client->post('booking/register', [
                'post_data' => $this->payload($beforeAppointment, $beforeCustomer, $target, 'positive'),
            ]);
            self::assertSame(200, $positive->statusCode, $positive->body);
            self::assertSame(
                $this->appointmentId,
                (int) (json_decode($positive->body, true, 512, JSON_THROW_ON_ERROR)['appointment_id'] ?? 0),
            );
            $updated = $fixture->row('appointments', $this->appointmentId);
            self::assertSame($target->format('Y-m-d H:i:s'), $updated['start_datetime']);
            self::assertSame($fixture->run . '_positive', $updated['notes']);
            self::assertSame($beforeOwnConsentCount + 1, $db->get_where('consents', $consentIdentity)->num_rows());
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            if ($handle instanceof CurlHandle && $multi instanceof CurlMultiHandle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                curl_multi_close($multi);
            }
            $writer->close();
            $observer->close();
        }
    }

    private function waitForProviderWait(object $observer, int $ownerId, string $table, CurlMultiHandle $multi): bool
    {
        $deadline = microtime(true) + 8;
        do {
            curl_multi_exec($multi, $running);
            $result = $observer->query(
                'SELECT COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID WHERE b.PROCESSLIST_ID = ' .
                    $ownerId .
                    ' AND l.OBJECT_SCHEMA = ' .
                    $observer->escape(Config::DB_NAME) .
                    ' AND l.OBJECT_NAME = ' .
                    $observer->escape($table),
            );
            self::assertNotFalse($result, 'Independent lock instrumentation must be available.');
            foreach ($result->result_array() as $row) {
                $statement = strtoupper((string) ($row['statement_text'] ?? ''));
                if (
                    str_contains($statement, strtoupper('SELECT `ID` FROM `' . $table . '` WHERE `ID` IN (')) &&
                    str_contains($statement, 'ORDER BY `ID` FOR UPDATE')
                ) {
                    return true;
                }
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function drain(CurlMultiHandle $multi): bool
    {
        $deadline = microtime(true) + 15;
        do {
            if (curl_multi_exec($multi, $running) !== CURLM_OK) {
                return false;
            }
            if ($running === 0) {
                return true;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function independentConnection(): object
    {
        $connection = get_instance()->load->database(
            [
                'hostname' => 'mysql',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'easyappointments',
                'dbdriver' => 'mysqli',
                'dbprefix' => get_instance()->db->dbprefix,
                'pconnect' => false,
                'db_debug' => false,
                'char_set' => 'utf8mb4',
                'dbcollat' => 'utf8mb4_general_ci',
            ],
            true,
        );
        self::assertNotSame(mysqli_thread_id(get_instance()->db->conn_id), mysqli_thread_id($connection->conn_id));
        return $connection;
    }

    private function payload(array $appointment, array $customer, DateTimeImmutable $target, string $case): array
    {
        return [
            'appointment' => [
                'id' => (int) $appointment['id'],
                'start_datetime' => $target->format('Y-m-d H:i:s'),
                'end_datetime' => $target->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => (int) $appointment['id_services'],
                'id_users_provider' => (int) $appointment['id_users_provider'],
                'location' => '',
                'notes' => $this->fixture?->run . '_' . $case,
                'color' => '',
            ],
            'customer' => [
                'id' => (int) $customer['id'],
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'email' => $customer['email'],
                'phone_number' => $customer['phone_number'] ?: '+49123456789',
                'address' => $customer['address'] ?: 'Teststrasse 1',
                'city' => $customer['city'] ?: 'Berlin',
                'zip_code' => $customer['zip_code'] ?: '10115',
                'timezone' => $customer['timezone'] ?: 'UTC',
                'notes' => $customer['notes'] ?? '',
            ],
            'manage_mode' => true,
        ];
    }

    private function restoreSettings(): void
    {
        $db = get_instance()->db;
        foreach ([$this->displayEmailSetting, $this->requireEmailSetting, $this->displayTermsSetting] as $row) {
            if ($row !== null) {
                $db->update('settings', ['value' => $row['value']], ['id' => $row['id']]);
                self::assertSame(
                    $row['value'],
                    $db->get_where('settings', ['id' => $row['id']])->row_array()['value'] ?? null,
                );
            }
        }
    }
}

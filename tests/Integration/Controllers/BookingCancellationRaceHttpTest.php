<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

final class BookingCancellationRaceHttpTest extends TestCase
{
    public static function concurrentChanges(): array
    {
        return [
            'deadline moves' => ['deadline', 403],
            'hash rotates' => ['hash', 403],
            'row disappears' => ['delete', 404],
        ];
    }

    #[DataProvider('concurrentChanges')]
    public function testCancellationRechecksTheLockedAppointment(string $change, int $expectedStatus): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }
        $fixture = new DefenseCycleFixtures();
        $server = null;
        $observer = null;
        $handle = null;
        $multi = null;
        $transactionOpen = false;
        $db = get_instance()->db;
        try {
            $fixture->create();
            $server = new DefenseCycleHttpServer();
            self::assertTrue($db->update('settings', ['value' => '60'], ['name' => 'book_advance_timeout']));
            $appointment = $fixture->appointment();
            $id = (int) $appointment['id'];
            self::assertTrue(
                $db->update(
                    'appointments',
                    [
                        'start_datetime' => date('Y-m-d H:i:s', time() + 7200),
                        'end_datetime' => date('Y-m-d H:i:s', time() + 9000),
                    ],
                    ['id' => $id],
                ),
            );
            $related = [
                $fixture->row('users', $fixture->customerId),
                $fixture->row('users', $fixture->providerId),
                $fixture->row('services', $fixture->serviceId),
            ];
            // The fixture constructor enforces the exact disposable Docker DB.
            // Root is used only by the independent, read-only lock observer.
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
            $ownerId = mysqli_thread_id($db->conn_id);
            self::assertNotSame($ownerId, mysqli_thread_id($observer->conn_id));
            self::assertTrue($db->trans_begin());
            $transactionOpen = true;
            // The HTTP lookup sees the committed future appointment. Its locking
            // reread must wait for this owner's actual update/delete to commit.
            if ($change === 'delete') {
                self::assertTrue($db->delete('appointments', ['id' => $id]));
            } else {
                $updates =
                    $change === 'hash'
                        ? ['hash' => bin2hex(random_bytes(32))]
                        : [
                            'start_datetime' => date('Y-m-d H:i:s', time() + 1800),
                            'end_datetime' => date('Y-m-d H:i:s', time() + 3600),
                        ];
                self::assertTrue($db->update('appointments', $updates, ['id' => $id]));
            }
            $expectedRow = $fixture->row('appointments', $id);
            $handle = curl_init($server->baseUrl . '/index.php/booking_cancellation/of/' . $appointment['hash']);
            $multi = curl_multi_init();
            self::assertInstanceOf(CurlHandle::class, $handle);
            self::assertInstanceOf(CurlMultiHandle::class, $multi);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 15,
            ]);
            self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
            $expectedSql = 'SELECT * FROM `' . $db->dbprefix('appointments') . '` WHERE `id` = ' . $id . ' FOR UPDATE';
            $deadline = microtime(true) + 8;
            $waiting = false;
            do {
                self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
                $waiting = $this->observesWait($observer, $ownerId, $db->dbprefix('appointments'), $expectedSql);
                if ($waiting || $running === 0) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (microtime(true) < $deadline);
            self::assertTrue(
                $waiting,
                'The HTTP authority reread must actually wait on the owned appointment transaction.',
            );
            self::assertTrue($db->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi, $handle), 'The HTTP request must finish after the owner commits.');
            self::assertSame($expectedStatus, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            self::assertStringNotContainsString(
                lang('appointment_cancelled_title'),
                (string) curl_multi_getcontent($handle),
            );
            self::assertSame($expectedRow, $fixture->row('appointments', $id));
            self::assertSame($related, [
                $fixture->row('users', $fixture->customerId),
                $fixture->row('users', $fixture->providerId),
                $fixture->row('services', $fixture->serviceId),
            ]);
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
            if ($multi instanceof CurlMultiHandle && $handle instanceof CurlHandle) {
                if (!$this->drain($multi, $handle)) {
                    $server?->close();
                }
                curl_multi_remove_handle($multi, $handle);
            }
            if ($handle instanceof CurlHandle) {
                curl_close($handle);
            }
            if ($multi instanceof CurlMultiHandle) {
                curl_multi_close($multi);
            }
            if (is_object($observer) && isset($observer->conn_id)) {
                $observer->close();
            }
            try {
                $server?->close();
            } finally {
                $fixture->cleanup();
            }
        }
    }

    private function observesWait(object $observer, int $ownerId, string $table, string $expectedSql): bool
    {
        $result = $observer->query(
            'SELECT l.OBJECT_SCHEMA, l.OBJECT_NAME, COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text ' .
                'FROM performance_schema.data_lock_waits w ' .
                'JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID ' .
                'JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID ' .
                'JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID ' .
                'LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID ' .
                'WHERE b.PROCESSLIST_ID = ' .
                $ownerId,
        );
        self::assertNotFalse($result, 'Independent fixture lock instrumentation must be available.');
        $normalize = static fn(string $sql): string => (string) preg_replace('/\s+/', ' ', strtoupper(trim($sql)));
        foreach ($result->result_array() as $wait) {
            if (
                $wait['OBJECT_SCHEMA'] === Config::DB_NAME &&
                $wait['OBJECT_NAME'] === $table &&
                $normalize((string) $wait['statement_text']) === $normalize($expectedSql)
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

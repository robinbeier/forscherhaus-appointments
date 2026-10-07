<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP/DB race coverage for a working-plan exception versus public booking. */
final class CalendarWorkingPlanPublicBookingRaceHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $calendarServer = null;
    private ?DefenseCycleHttpServer $bookingServer = null;
    private ?array $settings = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $credentials = $this->fixture->enableProviderHttpAuth();
            self::assertNotEmpty($credentials['admin_username']);
            $db = get_instance()->db;
            foreach (['display_email', 'require_email', 'display_terms_and_conditions'] as $name) {
                $row = $db->get_where('settings', ['name' => $name])->row_array() ?: null;
                self::assertNotNull($row);
                $this->settings[$name] = $row;
            }
            foreach (
                ['display_email' => '0', 'require_email' => '0', 'display_terms_and_conditions' => '1']
                as $name => $value
            ) {
                self::assertTrue($db->update('settings', ['value' => $value], ['id' => $this->settings[$name]['id']]));
            }
            $this->calendarServer = new DefenseCycleHttpServer();
            $this->bookingServer = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->closeAndCleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        $this->closeAndCleanup();
    }

    public function testWorkingPlanExceptionIsCommittedBeforePublicBookingFinalAvailabilityCheck(): void
    {
        $fixture = $this->fixture;
        $calendarServer = $this->calendarServer;
        $bookingServer = $this->bookingServer;
        self::assertNotNull($fixture);
        self::assertNotNull($calendarServer);
        self::assertNotNull($bookingServer);

        $db = get_instance()->db;
        $start = (new DateTimeImmutable('today'))->modify('+14 days')->setTime(10, 0);
        $date = $start->format('Y-m-d');
        $before = $this->mutationCounts();
        $observer = $this->connection(readOnly: true);
        $owner = $this->connection();
        $ownerId = mysqli_thread_id($owner->conn_id);
        $multi = curl_multi_init();
        $calendarHandle = null;
        $bookingHandle = null;
        $transactionOpen = false;

        try {
            $calendarClient = $this->loginAdmin($calendarServer, $fixture);
            $bookingClient = $bookingServer->client();
            self::assertSame(200, $bookingClient->get('booking')->statusCode);
            $CI = &get_instance();
            $CI->load->model('providers_model');
            $CI->load->model('services_model');
            $CI->load->library('availability');
            $provider = $CI->providers_model->find($fixture->providerId);
            $service = $CI->services_model->find($fixture->serviceId);
            self::assertContains(
                '10:00',
                $CI->availability->get_available_hours($date, $service, $provider),
                'The synthetic slot must be free before the race starts.',
            );

            self::assertTrue($owner->trans_begin());
            $transactionOpen = true;
            self::assertNotFalse(
                $owner->query('SELECT `id` FROM `' . $db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE', [
                    $fixture->providerId,
                ]),
            );

            $calendarHandle = $this->startCalendarRequest($calendarServer, $calendarClient, $fixture, $date, $multi);
            $calendarWaiters = $this->waitForProviderWaiters($multi, $observer, $ownerId, 1);
            self::assertCount(1, $calendarWaiters, 'Calendar exception must queue behind the held provider row.');

            $bookingHandle = $this->startBookingRequest($bookingServer, $bookingClient, $fixture, $start, $multi);
            $waiters = $this->waitForProviderWaiters($multi, $observer, $ownerId, 2);
            self::assertCount(
                2,
                $waiters,
                'Calendar and booking must be distinct queued requesters; booking status=' .
                    (string) curl_getinfo($bookingHandle, CURLINFO_RESPONSE_CODE),
            );
            self::assertArrayHasKey(
                array_key_first($calendarWaiters),
                $waiters,
                'The calendar exception must remain first in the provider lock wait set.',
            );

            self::assertTrue($owner->trans_commit());
            $transactionOpen = false;
            self::assertTrue($this->drain($multi));

            self::assertSame(200, (int) curl_getinfo($calendarHandle, CURLINFO_RESPONSE_CODE));
            $calendarBody = json_decode(
                (string) curl_multi_getcontent($calendarHandle),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertTrue((bool) ($calendarBody['success'] ?? false));
            $settings = $db->get_where('user_settings', ['id_users' => $fixture->providerId])->row_array();
            $exceptions = json_decode((string) ($settings['working_plan_exceptions'] ?? '{}'), true) ?: [];
            self::assertArrayHasKey(
                $date,
                $exceptions,
                'The own closing exception must be committed before the booking continues.',
            );
            self::assertNull($exceptions[$date]);

            self::assertSame(409, (int) curl_getinfo($bookingHandle, CURLINFO_RESPONSE_CODE));
            self::assertSame(
                ['success' => false, 'message' => lang('requested_hour_is_unavailable')],
                json_decode((string) curl_multi_getcontent($bookingHandle), true, 512, JSON_THROW_ON_ERROR),
            );
            $after = $this->mutationCounts();
            self::assertSame($before['appointments'], $after['appointments']);
            self::assertSame($before['users'], $after['users']);
            self::assertSame($before['consents'], $after['consents']);
            self::assertSame(
                0,
                $db->where('notes', $fixture->run . '_working_plan_booking')->count_all_results('appointments'),
            );
            self::assertSame(0, $db->where('last_name', 'Working Plan ' . $fixture->run)->count_all_results('users'));
            self::assertSame(
                0,
                $db->where('last_name', 'Working Plan ' . $fixture->run)->count_all_results('consents'),
            );
        } finally {
            if ($transactionOpen && $owner->trans_active()) {
                $owner->trans_rollback();
            }
            foreach ([$calendarHandle, $bookingHandle] as $handle) {
                if ($handle instanceof CurlHandle) {
                    $this->drain($multi);
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                }
            }
            curl_multi_close($multi);
            $owner->close();
            $observer->close();
        }
    }

    private function loginAdmin(DefenseCycleHttpServer $server, DefenseCycleFixtures $fixture): GateHttpClient
    {
        $client = $server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function startCalendarRequest(
        DefenseCycleHttpServer $server,
        GateHttpClient $client,
        DefenseCycleFixtures $fixture,
        string $date,
        CurlMultiHandle $multi,
    ): CurlHandle {
        $token = (string) $client->getCookie('csrf_cookie');
        self::assertNotSame('', $token);
        $cookies = array_map(
            static fn(array $record): string => $record['name'] . '=' . $record['value'],
            $client->cookieRecords(),
        );
        $handle = curl_init($server->baseUrl . '/index.php/calendar/save_working_plan_exception');
        self::assertInstanceOf(CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $token,
                'provider_id' => $fixture->providerId,
                'date' => $date,
                'working_plan_exception' => [],
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Cookie: ' . implode('; ', $cookies),
            ],
        ]);
        self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
        return $handle;
    }

    private function startBookingRequest(
        DefenseCycleHttpServer $server,
        GateHttpClient $client,
        DefenseCycleFixtures $fixture,
        DateTimeImmutable $start,
        CurlMultiHandle $multi,
    ): CurlHandle {
        $token = (string) $client->getCookie('csrf_cookie');
        self::assertNotSame('', $token);
        $cookies = array_map(
            static fn(array $record): string => $record['name'] . '=' . $record['value'],
            $client->cookieRecords(),
        );
        $handle = curl_init($server->baseUrl . '/index.php/booking/register');
        self::assertInstanceOf(CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $token,
                'post_data' => $this->bookingPayload($fixture, $start),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Cookie: ' . implode('; ', $cookies),
            ],
        ]);
        self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));
        return $handle;
    }

    /** @return array<int, int> */
    private function waitForProviderWaiters(CurlMultiHandle $multi, object $observer, int $ownerId, int $minimum): array
    {
        $deadline = microtime(true) + 8;
        do {
            self::assertSame(CURLM_OK, curl_multi_exec($multi, $running));
            $result = $observer->query(
                'SELECT DISTINCT r.THREAD_ID AS requester_thread_id, ' .
                    'COALESCE(s.SQL_TEXT, r.PROCESSLIST_INFO) AS statement_text ' .
                    'FROM performance_schema.data_lock_waits w ' .
                    'JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID ' .
                    'JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID ' .
                    'LEFT JOIN performance_schema.events_statements_current s ON s.THREAD_ID = r.THREAD_ID ' .
                    'WHERE b.PROCESSLIST_ID = ' .
                    $ownerId,
            );
            self::assertNotFalse($result, 'Independent lock instrumentation must be available.');
            $waiters = [];
            foreach ($result->result_array() as $row) {
                $statement = strtoupper((string) ($row['statement_text'] ?? ''));
                if (str_contains($statement, 'FOR UPDATE') && str_contains($statement, 'USERS')) {
                    $waiters[(int) $row['requester_thread_id']] = (int) $row['requester_thread_id'];
                }
            }
            if (count($waiters) >= $minimum) {
                return $waiters;
            }
            if ($running === 0) {
                return $waiters;
            }
            curl_multi_select($multi, 0.05);
        } while (microtime(true) < $deadline);
        return $waiters;
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

    private function connection(bool $readOnly = false): object
    {
        $db = get_instance()->db;
        $connection = get_instance()->load->database(
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
        if ($readOnly) {
            self::assertTrue($connection->query('SET SESSION TRANSACTION READ ONLY'));
        }
        self::assertNotSame(mysqli_thread_id($db->conn_id), mysqli_thread_id($connection->conn_id));
        return $connection;
    }

    /** @return array{appointments:int,users:int,consents:int} */
    private function mutationCounts(): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'consents' => $db->count_all('consents'),
        ];
    }

    private function bookingPayload(DefenseCycleFixtures $fixture, DateTimeImmutable $start): array
    {
        return [
            'appointment' => [
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => $fixture->serviceId,
                'id_users_provider' => $fixture->providerId,
                'location' => '',
                'notes' => $fixture->run . '_working_plan_booking',
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Synthetic',
                'last_name' => 'Working Plan ' . $fixture->run,
                'phone_number' => '000000000',
                'address' => '',
                'city' => '',
                'zip_code' => '',
                'timezone' => 'UTC',
                'notes' => $fixture->run,
            ],
            'manage_mode' => false,
        ];
    }

    private function closeAndCleanup(): void
    {
        $this->calendarServer?->close();
        $this->bookingServer?->close();
        if ($this->settings !== null) {
            $db = get_instance()->db;
            foreach ($this->settings as $row) {
                $db->update('settings', ['value' => $row['value']], ['id' => $row['id']]);
            }
        }
        $this->fixture?->cleanup();
        $this->calendarServer = null;
        $this->bookingServer = null;
    }
}

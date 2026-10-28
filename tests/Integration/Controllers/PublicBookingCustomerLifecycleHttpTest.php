<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';
require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/070_create_public_booking_customers.php';

/** Real HTTP coverage for anonymous customer/consent lifecycle ownership. */
final class PublicBookingCustomerLifecycleHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var list<int> */
    private array $ownedAppointmentIds = [];
    /** @var list<int> */
    private array $ownedCustomerIds = [];
    /** @var list<int> */
    private array $ownedConsentIds = [];
    /** @var array<string,array<string,mixed>> */
    private array $settings = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }
        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            foreach (
                ['display_email', 'require_email', 'display_terms_and_conditions', 'display_privacy_policy']
                as $name
            ) {
                $row = $db->get_where('settings', ['name' => $name])->row_array();
                self::assertNotEmpty($row);
                $this->settings[$name] = $row;
            }
            $db->update('settings', ['value' => '0'], ['id' => (int) $this->settings['display_email']['id']]);
            $db->update('settings', ['value' => '0'], ['id' => (int) $this->settings['require_email']['id']]);
            $db->update(
                'settings',
                ['value' => '1'],
                ['id' => (int) $this->settings['display_terms_and_conditions']['id']],
            );
            $db->update('settings', ['value' => '1'], ['id' => (int) $this->settings['display_privacy_policy']['id']]);
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testRepeatedPublicBookCancelRemovesOnlyOwnedCustomerAndConsents(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);
        $db = get_instance()->db;
        $baselineUsers = $db->count_all('users');
        $baselineConsents = $db->count_all('consents');
        $anchor = new DateTimeImmutable($fixture->appointment()['start_datetime']);

        foreach ([2, 4] as $hours) {
            $start = $anchor->modify('+' . $hours . ' hours');
            $client = $server->client();
            self::assertSame(200, $client->get('booking')->statusCode);
            $response = $client->post('booking/register', [
                'post_data' => [
                    'appointment' => $this->appointmentPayload($fixture, $start, 'lifecycle-' . $hours),
                    'customer' => [
                        'first_name' => 'Lifecycle',
                        'last_name' => 'Synthetic ' . $hours,
                        'phone_number' => '0000000000',
                        'address' => 'Synthetic Street 1',
                        'city' => 'Bielefeld',
                        'zip_code' => '33602',
                        'timezone' => 'Europe/Berlin',
                    ],
                    'manage_mode' => false,
                ],
            ]);
            self::assertSame(200, $response->statusCode, $response->body);
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            $appointmentId = (int) $data['appointment_id'];
            $this->ownedAppointmentIds[] = $appointmentId;
            $appointment = $db->get_where('appointments', ['id' => $appointmentId])->row_array();
            self::assertNotEmpty($appointment);
            $customerId = (int) $appointment['id_users_customer'];
            $this->ownedCustomerIds[] = $customerId;
            $consents = $db->get_where('consents', ['customer_id' => $customerId])->result_array();
            self::assertCount(2, $consents);
            foreach ($consents as $consent) {
                $this->ownedConsentIds[] = (int) $consent['id'];
            }
            self::assertSame(
                200,
                $server->client()->requestApp('POST', 'booking_cancellation/of/' . $data['appointment_hash'], [], 15)
                    ->statusCode,
            );
            self::assertSame([], $db->get_where('appointments', ['id' => $appointmentId])->result_array());
            self::assertSame([], $db->get_where('users', ['id' => $customerId])->result_array());
            self::assertSame(
                [],
                $db->get_where('public_booking_customers', ['customer_id' => $customerId])->result_array(),
            );
            self::assertSame([], $db->get_where('consents', ['customer_id' => $customerId])->result_array());
        }
        self::assertSame($baselineUsers, $db->count_all('users'));
        self::assertSame($baselineConsents, $db->count_all('consents'));
    }

    public function testCancellationRetainsUnmarkedHistoricalCustomerAndConsent(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $appointment = $fixture->appointment();
        $this->ownedAppointmentIds[] = (int) $appointment['id'];
        self::assertTrue(
            (bool) $db->insert('consents', [
                'first_name' => 'Historical',
                'last_name' => 'Customer',
                'email' => '-',
                'ip' => '127.0.0.1',
                'type' => 'privacy-policy',
            ]),
        );
        $consentId = (int) $db->insert_id();
        $this->ownedConsentIds[] = $consentId;
        $response = $this->server
            ?->client()
            ->requestApp('POST', 'booking_cancellation/of/' . $appointment['hash'], [], 15);
        self::assertSame(200, $response?->statusCode);
        self::assertNotEmpty($db->get_where('users', ['id' => $fixture->customerId])->row_array());
        self::assertNotEmpty($db->get_where('consents', ['id' => $consentId])->row_array());
    }

    public function testAnotherAppointmentPreservesCustomerUntilLastCancellation(): void
    {
        $first = $this->createPublicBooking();
        $db = get_instance()->db;
        $second = $first;
        unset($second['id'], $second['hash']);
        $second['start_datetime'] = (new DateTimeImmutable($first['start_datetime']))
            ->modify('+1 hour')
            ->format('Y-m-d H:i:s');
        $second['end_datetime'] = (new DateTimeImmutable($first['end_datetime']))
            ->modify('+1 hour')
            ->format('Y-m-d H:i:s');
        get_instance()->load->model('appointments_model');
        $secondId = get_instance()->appointments_model->save($second);
        $this->ownedAppointmentIds[] = $secondId;
        $second = $db->get_where('appointments', ['id' => $secondId])->row_array();
        $id = (int) $first['id_users_customer'];
        self::assertSame(200, $this->cancel($first)->statusCode);
        self::assertNotEmpty($db->get_where('users', ['id' => $id])->row_array());
        self::assertSame(2, $db->get_where('consents', ['customer_id' => $id])->num_rows());
        self::assertNotEmpty($db->get_where('appointments', ['id' => $secondId])->row_array());
        self::assertSame(200, $this->cancel($second)->statusCode);
        self::assertNull($db->get_where('users', ['id' => $id])->row_array());
        self::assertSame(0, $db->get_where('consents', ['customer_id' => $id])->num_rows());
    }

    public function testStaffUpdateThenPublicRescheduleNeverReadoptsCustomer(): void
    {
        $appointment = $this->createPublicBooking();
        $db = get_instance()->db;
        $id = (int) $appointment['id_users_customer'];
        get_instance()->load->model('customers_model');
        $customer = get_instance()->customers_model->find($id);
        $customer['notes'] = 'staff-retained-synthetic';
        get_instance()->customers_model->save($customer);
        self::assertNull($db->get_where('public_booking_customers', ['customer_id' => $id])->row_array());
        $client = $this->server->client();
        self::assertSame(200, $client->get('booking/reschedule/' . $appointment['hash'])->statusCode);
        $target = (new DateTimeImmutable($appointment['start_datetime']))->modify('+1 hour');
        $payload = $this->appointmentPayload($this->fixture, $target, 'public-reschedule');
        $payload['id'] = (int) $appointment['id'];
        $customer = get_instance()->customers_model->find($id);
        $response = $client->post('booking/register', [
            'post_data' => ['appointment' => $payload, 'customer' => $customer, 'manage_mode' => true],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertNull($db->get_where('public_booking_customers', ['customer_id' => $id])->row_array());
        $appointment = $db->get_where('appointments', ['id' => $appointment['id']])->row_array();
        self::assertSame(200, $this->cancel($appointment)->statusCode);
        self::assertNotEmpty($db->get_where('users', ['id' => $id])->row_array());
        self::assertSame('staff-retained-synthetic', $db->get_where('users', ['id' => $id])->row_array()['notes']);
    }

    public function testPublicReschedulePreservesCleanupEligibilityAndAllLinkedConsents(): void
    {
        $appointment = $this->createPublicBooking();
        $db = get_instance()->db;
        $id = (int) $appointment['id_users_customer'];
        $client = $this->server->client();
        self::assertSame(200, $client->get('booking/reschedule/' . $appointment['hash'])->statusCode);
        $target = (new DateTimeImmutable($appointment['start_datetime']))->modify('+1 hour');
        $payload = $this->appointmentPayload($this->fixture, $target, 'public-reschedule');
        $payload['id'] = (int) $appointment['id'];
        get_instance()->load->model('customers_model');
        $customer = get_instance()->customers_model->find($id);
        $customer['last_name'] = 'Public correction';
        $response = $client->post('booking/register', [
            'post_data' => ['appointment' => $payload, 'customer' => $customer, 'manage_mode' => true],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertNotEmpty($db->get_where('public_booking_customers', ['customer_id' => $id])->row_array());
        self::assertSame(4, $db->get_where('consents', ['customer_id' => $id])->num_rows());
        $appointment = $db->get_where('appointments', ['id' => $appointment['id']])->row_array();
        self::assertSame(200, $this->cancel($appointment)->statusCode);
        self::assertNull($db->get_where('users', ['id' => $id])->row_array());
        self::assertSame(0, $db->get_where('consents', ['customer_id' => $id])->num_rows());
    }

    public function testCleanupFailureAfterAppointmentDeletionRollsBackWholeLifecycle(): void
    {
        $appointment = $this->createPublicBooking();
        $db = get_instance()->db;
        $customerId = (int) $appointment['id_users_customer'];
        $beforeCustomer = $db->get_where('users', ['id' => $customerId])->row_array();
        $beforeConsents = $db
            ->order_by('id')
            ->get_where('consents', ['customer_id' => $customerId])
            ->result_array();
        $beforeMarker = $db->get_where('public_booking_customers', ['customer_id' => $customerId])->row_array();
        $admin = get_instance()->load->database(
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
        $table = $this->fixture->run . '_cleanup_seen';
        $trigger = $this->fixture->run . '_cleanup_fail';
        try {
            self::assertTrue(
                $admin->query('CREATE TABLE `' . $table . '` (`customer_id` INT, `remaining` INT) ENGINE=MEMORY'),
            );
            self::assertTrue(
                $admin->query(
                    'CREATE TRIGGER `' .
                        $trigger .
                        '` BEFORE DELETE ON `' .
                        $db->dbprefix('users') .
                        '` FOR EACH ROW BEGIN IF OLD.id = ' .
                        $customerId .
                        ' THEN INSERT INTO `' .
                        $table .
                        '` VALUES (OLD.id, (SELECT COUNT(*) FROM `' .
                        $db->dbprefix('appointments') .
                        '` WHERE id = ' .
                        (int) $appointment['id'] .
                        ")); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic lifecycle cleanup failure'; END IF; END",
                ),
            );
            $response = $this->cancel($appointment);
            self::assertSame(500, $response->statusCode, $response->body);
            self::assertStringNotContainsString('synthetic lifecycle cleanup failure', $response->body);
            $observed = $admin->query('SELECT * FROM `' . $table . '`')->result_array();
            self::assertCount(1, $observed, 'The exact post-appointment cleanup failpoint must be reached.');
            self::assertSame(
                0,
                (int) $observed[0]['remaining'],
                'The appointment delete must have happened before the failure.',
            );
            self::assertSame($appointment, $db->get_where('appointments', ['id' => $appointment['id']])->row_array());
            self::assertSame($beforeCustomer, $db->get_where('users', ['id' => $customerId])->row_array());
            self::assertSame(
                $beforeMarker,
                $db->get_where('public_booking_customers', ['customer_id' => $customerId])->row_array(),
            );
            self::assertSame(
                $beforeConsents,
                $db
                    ->order_by('id')
                    ->get_where('consents', ['customer_id' => $customerId])
                    ->result_array(),
            );
        } finally {
            $admin->query('DROP TRIGGER IF EXISTS `' . $trigger . '`');
            $admin->query('DROP TABLE IF EXISTS `' . $table . '`');
            $admin->close();
        }
        self::assertSame(200, $this->cancel($appointment)->statusCode);
        self::assertNull($db->get_where('users', ['id' => $customerId])->row_array());
    }

    public function testStaffAndApiCancellationUseTheSamePublicCustomerLifecycle(): void
    {
        $credentials = $this->fixture->enableProviderHttpAuth();
        $db = get_instance()->db;
        foreach (['calendar', 'api'] as $route) {
            $appointment = $this->createPublicBooking();
            $id = (int) $appointment['id_users_customer'];
            if ($route === 'calendar') {
                $client = $this->server->client();
                self::assertSame(200, $client->get('login')->statusCode);
                $login = $client->post('login/validate', [
                    'username' => $credentials['admin_username'],
                    'password' => $credentials['password'],
                ]);
                self::assertTrue(
                    (bool) (json_decode($login->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false),
                );
                $response = $client->post('calendar/delete_appointment', ['appointment_id' => $appointment['id']]);
                self::assertSame(200, $response->statusCode, $response->body);
                self::assertTrue(
                    (bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false),
                );
            } else {
                $client = new \ReleaseGate\GateHttpClient(
                    $this->server->baseUrl,
                    'index.php',
                    additionalHeaders: ['Authorization' => 'Bearer ' . $credentials['token']],
                );
                $response = $client->requestApp('DELETE', 'api/v1/appointments/' . $appointment['id']);
                self::assertSame(204, $response->statusCode, $response->body);
            }
            self::assertNull($db->get_where('appointments', ['id' => $appointment['id']])->row_array());
            self::assertNull($db->get_where('users', ['id' => $id])->row_array());
            self::assertSame(0, $db->get_where('consents', ['customer_id' => $id])->num_rows());
            self::assertNull($db->get_where('public_booking_customers', ['customer_id' => $id])->row_array());
        }
    }

    public function testSchemaRollbackPreservesRowsAndUpgradeDoesNotReconstructAttribution(): void
    {
        $db = get_instance()->db;
        self::assertSame(
            0,
            $db->count_all('public_booking_customers'),
            'Only this test may own attribution before exercising DDL rollback.',
        );
        $appointment = $this->createPublicBooking();
        $id = (int) $appointment['id_users_customer'];
        $beforeConsents = $db
            ->order_by('id')
            ->get_where('consents', ['customer_id' => $id])
            ->result_array();
        self::assertCount(2, $beforeConsents);
        get_instance()->load->library('migration');
        $migration = get_instance()->migration;
        self::assertSame(70, (int) $db->get('migrations')->row_array()['version']);
        $beforeMarker = $db->get_where('public_booking_customers', ['customer_id' => $id])->row_array();
        $schema = new Migration_Create_public_booking_customers();
        $schema->up();
        self::assertSame(
            $beforeMarker,
            $db->get_where('public_booking_customers', ['customer_id' => $id])->row_array(),
        );
        self::assertTrue(
            $db->query('ALTER TABLE `' . $db->dbprefix('consents') . '` DROP FOREIGN KEY `fk_consents_customer`'),
        );
        self::assertTrue(
            $db->query(
                'ALTER TABLE `' .
                    $db->dbprefix('public_booking_customers') .
                    '` DROP FOREIGN KEY `fk_public_booking_customers_customer`',
            ),
        );
        $schema->up();
        self::assertSame(
            $beforeMarker,
            $db->get_where('public_booking_customers', ['customer_id' => $id])->row_array(),
        );
        self::assertSame(
            $beforeConsents,
            $db
                ->order_by('id')
                ->get_where('consents', ['customer_id' => $id])
                ->result_array(),
        );
        try {
            self::assertNotFalse($migration->version(69));
            self::assertFalse($db->table_exists('public_booking_customers'));
            self::assertFalse($db->field_exists('customer_id', 'consents'));
            self::assertNotEmpty($db->get_where('users', ['id' => $id])->row_array());
            foreach ($beforeConsents as $row) {
                unset($row['customer_id']);
                self::assertSame($row, $db->get_where('consents', ['id' => $row['id']])->row_array());
            }
        } finally {
            self::assertNotFalse($migration->version(70));
        }
        self::assertSame(70, (int) $db->get('migrations')->row_array()['version']);
        self::assertSame(0, $db->count_all('public_booking_customers'));
        foreach ($beforeConsents as $row) {
            self::assertNull($db->get_where('consents', ['id' => $row['id']])->row_array()['customer_id']);
        }
        $rules = $db
            ->query(
                'SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN (?, ?) ORDER BY CONSTRAINT_NAME',
                ['fk_public_booking_customers_customer', 'fk_consents_customer'],
            )
            ->result_array();
        self::assertCount(2, $rules);
        foreach ($rules as $rule) {
            self::assertSame('CASCADE', $rule['DELETE_RULE']);
        }
        self::assertSame(200, $this->cancel($appointment)->statusCode);
        self::assertNotEmpty($db->get_where('users', ['id' => $id])->row_array());
        foreach ($beforeConsents as $row) {
            self::assertNotEmpty($db->get_where('consents', ['id' => $row['id']])->row_array());
        }
    }

    private function createPublicBooking(): array
    {
        $anchor = new DateTimeImmutable($this->fixture->appointment()['start_datetime']);
        $client = $this->server->client();
        self::assertSame(200, $client->get('booking')->statusCode);
        $response = $client->post('booking/register', [
            'post_data' => [
                'appointment' => $this->appointmentPayload($this->fixture, $anchor->modify('+2 hours'), 'lifecycle'),
                'customer' => [
                    'first_name' => 'Lifecycle',
                    'last_name' => 'Synthetic',
                    'phone_number' => '0000000000',
                    'timezone' => 'UTC',
                ],
                'manage_mode' => false,
            ],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('appointment_id', $data);
        $this->ownedAppointmentIds[] = (int) $data['appointment_id'];
        $db = get_instance()->db;
        $appointment = $db->get_where('appointments', ['id' => $data['appointment_id']])->row_array();
        self::assertNotEmpty($appointment);
        $this->ownedCustomerIds[] = (int) $appointment['id_users_customer'];
        foreach (
            $db->get_where('consents', ['customer_id' => $appointment['id_users_customer']])->result_array()
            as $row
        ) {
            $this->ownedConsentIds[] = (int) $row['id'];
        }
        return $appointment;
    }

    private function cancel(array $appointment): \ReleaseGate\GateHttpResponse
    {
        return $this->server->client()->requestApp('POST', 'booking_cancellation/of/' . $appointment['hash'], [], 15);
    }

    private function appointmentPayload(DefenseCycleFixtures $fixture, DateTimeImmutable $start, string $notes): array
    {
        return [
            'start_datetime' => $start->format('Y-m-d H:i:s'),
            'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'id_services' => $fixture->serviceId,
            'id_users_provider' => $fixture->providerId,
            'location' => '',
            'notes' => $notes,
            'color' => '',
        ];
    }

    private function cleanup(): void
    {
        $this->server?->close();
        $db = get_instance()->db;
        foreach ($this->ownedAppointmentIds as $id) {
            $db->delete('appointments', ['id' => $id]);
        }
        foreach ($this->ownedConsentIds as $id) {
            $db->delete('consents', ['id' => $id]);
        }
        foreach ($this->ownedCustomerIds as $id) {
            $db->delete('public_booking_customers', ['customer_id' => $id]);
            $db->delete('users', ['id' => $id]);
        }
        foreach ($this->settings as $row) {
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id']]);
        }
        $this->fixture?->cleanup();
        $this->server = null;
    }
}

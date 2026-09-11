<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Calendar;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\Integration\Support\TwoConnectionHarness;
use Tests\TestCase;

require_once APPPATH . 'controllers/Calendar.php';

/** Benign administrative concurrency checks on disposable synthetic fixtures. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TwoConnectionWriteContractTest extends TestCase
{
    private BookingFlowFixtures $fixtures;
    private array $createdProviders = [];
    private array $createdServices = [];
    private array $createdAppointments = [];
    private object $originalOutput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new BookingFlowFixtures();
        $this->originalOutput = get_instance()->output;
        get_instance()->output = new class extends \EA_Output {
            public int $statusCode = 200;
            public function set_status_header($code = 200, $text = '')
            {
                $this->statusCode = (int) $code;
                return parent::set_status_header($code, $text);
            }
        };
        foreach (['appointments_model', 'services_model', 'customers_model', 'providers_model'] as $model) {
            get_instance()->load->model($model);
        }
        get_instance()->load->library('permissions');
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
        $admin = get_instance()
            ->db->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles')
            ->where('roles.slug', DB_SLUG_ADMIN)
            ->get()
            ->row_array();
        $this->assertNotEmpty($admin);
        session([
            'user_id' => (int) $admin['id'],
            'role_slug' => DB_SLUG_ADMIN,
            'language' => 'english',
            'timezone' => 'UTC',
        ]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        foreach ($this->createdAppointments as $id) {
            get_instance()->db->delete('appointments', ['id_parent_appointment' => $id]);
        }
        $this->fixtures->cleanup();
        foreach ($this->createdServices as $id) {
            get_instance()->db->delete('services_providers', ['id_services' => $id]);
            get_instance()->db->delete('services', ['id' => $id]);
        }
        foreach ($this->createdProviders as $id) {
            get_instance()->db->delete('services_providers', ['id_users' => $id]);
            get_instance()->db->delete('user_settings', ['id_users' => $id]);
            get_instance()->db->delete('users', ['id' => $id]);
        }
        get_instance()->output = $this->originalOutput;
        parent::tearDown();
    }

    public function testAdministrativeCalendarReassignmentRejectsStaleSnapshot(): void
    {
        $this->calendarScenario(true);
    }

    public function testAdministrativeCalendarControlReachesWriteAndCommit(): void
    {
        $this->calendarScenario(false);
    }

    private function calendarScenario(bool $reassign): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $replacement = $this->createProvider($pair['provider_id'], $pair['service_id']);
        $customer = $this->fixtures->createCustomer();
        $id = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customer,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-07 09:00:00'),
        );
        $this->createdAppointments[] = $id;
        $row = $this->fixtures->findAppointmentById($id);
        $_POST = [
            'customer_data' => [],
            'appointment_data' => array_intersect_key(
                $row,
                array_flip([
                    'id',
                    'start_datetime',
                    'end_datetime',
                    'id_users_provider',
                    'id_users_customer',
                    'id_services',
                    'notes',
                ]),
            ),
        ];
        $_POST['appointment_data']['notes'] = 'Administrative concurrency control';
        $shared = get_instance()->db;
        $harness = new TwoConnectionHarness();
        $notifications = BookingFlowFixtures::createNoopNotifications();
        $writeReached = false;
        $harness->run(
            function ($primary, $peer, $checkpoint) use (
                $reassign,
                $id,
                $replacement,
                $notifications,
                &$writeReached,
                $harness,
            ): void {
                $this->assertNotSame($primary->conn_id->thread_id, $peer->conn_id->thread_id);
                $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
                $controller = new class extends Calendar {
                    public $afterAuthority;
                    public $afterParents;
                    public function __construct() {}
                    protected function lock_calendar_update_parents(
                        array $current,
                        array $requested,
                        array $additional = [],
                    ): void {
                        ($this->afterAuthority)();
                        parent::lock_calendar_update_parents($current, $requested, $additional);
                        ($this->afterParents)();
                    }
                };
                $controller->afterAuthority = function () use ($checkpoint, $peer, $reassign, $id, $replacement): void {
                    $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                    if ($reassign) {
                        // A second legitimate administrator changes a synthetic appointment.
                        $peer->trans_begin();
                        $this->assertTrue(
                            $peer->update('appointments', ['id_users_provider' => $replacement], ['id' => $id]),
                        );
                        $this->assertTrue($peer->trans_commit());
                    }
                };
                $controller->afterParents = fn() => $checkpoint(TwoConnectionHarness::AFTER_PARENT_LOCKS);
                $model = new class extends \Appointments_model {
                    public $beforeWrite;
                    public $afterWrite;
                    public function __construct() {}
                    public function save(array $appointment): int
                    {
                        ($this->beforeWrite)();
                        $id = parent::save($appointment);
                        ($this->afterWrite)();
                        return $id;
                    }
                };
                $model->beforeWrite = function () use ($checkpoint, &$writeReached): void {
                    $checkpoint(TwoConnectionHarness::BEFORE_WRITE);
                    $writeReached = true;
                };
                // The model has returned, while the outer Calendar transaction is still active.
                $model->afterWrite = function () use ($checkpoint, $primary): void {
                    $this->assertTrue($primary->trans_active());
                    $checkpoint(TwoConnectionHarness::BEFORE_COMMIT);
                };
                $CI = get_instance();
                foreach (
                    ['input', 'output', 'load', 'customers_model', 'providers_model', 'services_model', 'permissions']
                    as $property
                ) {
                    $controller->$property = $CI->$property;
                }
                $controller->db = $primary;
                $controller->appointments_model = $model;
                $controller->notifications = $notifications;
                $controller->save_appointment();
                $this->assertFalse($primary->trans_active());
                $result = json_decode(get_instance()->output->get_output(), true);
                $this->assertSame(!$reassign, $result['success'] ?? null);
                $harness->markBranch($reassign ? 'administrative_snapshot_conflict' : 'administrative_control');
            },
            $reassign ? 'administrative_snapshot_conflict' : 'administrative_control',
        );
        $this->assertSame($shared, get_instance()->db);
        $response = json_decode(get_instance()->output->get_output(), true);
        $this->assertIsArray($response);
        $this->assertSame(!$reassign, $response['success'] ?? null);
        $this->assertSame(!$reassign, $writeReached);
        $this->assertSame($reassign ? 409 : 200, get_instance()->output->statusCode);
        $expected = [
            TwoConnectionHarness::BEFORE_AUTHORITY,
            TwoConnectionHarness::AFTER_AUTHORITY,
            TwoConnectionHarness::AFTER_PARENT_LOCKS,
        ];
        if (!$reassign) {
            $expected[] = TwoConnectionHarness::BEFORE_WRITE;
            $expected[] = TwoConnectionHarness::BEFORE_COMMIT;
        }
        $harness->assertTrace($expected);
        $stored = $this->fixtures->findAppointmentById($id);
        $this->assertSame($reassign ? $replacement : $pair['provider_id'], (int) $stored['id_users_provider']);
        $this->assertSame($reassign ? $row['notes'] : 'Administrative concurrency control', $stored['notes']);
        $this->assertSame($reassign ? 0 : 1, $notifications->savedCalls);
    }

    public function testServiceBufferChangeSerializesBeforeLegitimateReschedule(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $CI = get_instance();
        $service = $CI->services_model->save([
            'name' => 'Two connection service',
            'duration' => 25,
            'price' => 0,
            'currency' => 'EUR',
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ]);
        $this->createdServices[] = $service;
        $provider = $this->createProvider($pair['provider_id'], $service);
        $plan = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $plan[$day] = ['start' => '08:00', 'end' => '18:00', 'breaks' => []];
        }
        $CI->db->update(
            'user_settings',
            ['working_plan' => json_encode($plan), 'working_plan_exceptions' => '{}'],
            ['id_users' => $provider],
        );
        $customer = $this->fixtures->createCustomer();
        $id = $this->fixtures->createAppointment(
            $provider,
            $customer,
            $service,
            new DateTimeImmutable('2035-05-07 09:00:00'),
            new DateTimeImmutable('2035-05-07 09:25:00'),
        );
        $this->createdAppointments[] = $id;
        $harness = new TwoConnectionHarness();
        $shared = $CI->db;
        try {
            $harness->run(function ($primary, $peer, $checkpoint) use ($id, $service, $harness): void {
                $this->assertNotSame($primary->conn_id->thread_id, $peer->conn_id->thread_id);
                $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
                $this->assertSame(DB_SLUG_ADMIN, session('role_slug'));
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                $primary->trans_begin();
                $services = get_instance()->services_model;
                $services->lock_buffer_sync_parents($service);
                $checkpoint(TwoConnectionHarness::AFTER_PARENT_LOCKS);
                $peer->trans_begin();
                // NOWAIT provides bounded, actual lock-contention evidence, without sleeps.
                $peer->db_debug = false;
                $lockSql =
                    'SELECT id FROM `' . $peer->dbprefix('appointments') . '` WHERE id = ' . $id . ' FOR UPDATE NOWAIT';
                try {
                    $result = $peer->query($lockSql);
                    $error = (int) $peer->error()['code'];
                    $this->assertFalse($result, 'Peer acquired the appointment while primary should hold it.');
                } catch (\mysqli_sql_exception $exception) {
                    $error = $exception->getCode();
                }
                $this->assertSame(
                    3572,
                    $error,
                    'Expected MySQL NOWAIT contention, not a deadlock or unrelated SQL error.',
                );
                $peer->trans_rollback();
                $checkpoint(TwoConnectionHarness::BEFORE_WRITE);
                $services->save(
                    [
                        'id' => $service,
                        'name' => 'Two connection service',
                        'duration' => 25,
                        'price' => 0,
                        'currency' => 'EUR',
                        'attendants_number' => 1,
                        'buffer_before' => 0,
                        'buffer_after' => 5,
                    ],
                    ['buffer_before' => 0, 'buffer_after' => 0],
                );
                $checkpoint(TwoConnectionHarness::BEFORE_COMMIT);
                $this->assertTrue($primary->trans_commit());

                // After commit, the legitimate peer runs the real appointment model update.
                get_instance()->db = $peer;
                $appointment = get_instance()->appointments_model->find($id);
                $appointment['start_datetime'] = '2035-05-07 10:00:00';
                $appointment['end_datetime'] = '2035-05-07 10:25:00';
                $this->assertSame($id, get_instance()->appointments_model->save($appointment));
                $this->assertFalse($peer->trans_active());
                $harness->markBranch('service_then_reschedule');
            }, 'service_then_reschedule');
            $this->assertSame($shared, $CI->db);
            $harness->assertTrace([
                TwoConnectionHarness::BEFORE_AUTHORITY,
                TwoConnectionHarness::AFTER_AUTHORITY,
                TwoConnectionHarness::AFTER_PARENT_LOCKS,
                TwoConnectionHarness::BEFORE_WRITE,
                TwoConnectionHarness::BEFORE_COMMIT,
            ]);
            $row = $this->fixtures->findAppointmentById($id);
            $this->assertSame('2035-05-07 10:00:00', $row['start_datetime']);
            $buffers = $shared->get_where('appointments', ['id_parent_appointment' => $id])->result_array();
            $this->assertCount(1, $buffers);
            $this->assertSame('2035-05-07 10:25:00', $buffers[0]['start_datetime']);
            $this->assertSame('2035-05-07 10:30:00', $buffers[0]['end_datetime']);
        } finally {
            $shared->delete('appointments', ['id_parent_appointment' => $id]);
            $shared->delete('appointments', ['id' => $id]);
            $shared->delete('services_providers', ['id_services' => $service]);
            $shared->delete('services', ['id' => $service]);
        }
    }

    private function createProvider(int $sourceId, int $serviceId): int
    {
        $db = get_instance()->db;
        $row = $db->get_where('users', ['id' => $sourceId])->row_array();
        unset($row['id']);
        $row['email'] = 'two-connection-' . bin2hex(random_bytes(6)) . '@example.org';
        $db->insert('users', $row);
        $id = (int) $db->insert_id();
        $this->createdProviders[] = $id;
        $settings = $db->get_where('user_settings', ['id_users' => $sourceId])->row_array();
        unset($settings['id']);
        $settings['id_users'] = $id;
        $db->insert('user_settings', $settings);
        $db->insert('services_providers', ['id_users' => $id, 'id_services' => $serviceId]);
        return $id;
    }
}

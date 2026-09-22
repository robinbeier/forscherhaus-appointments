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

    public function testSparseApiUpdateRebasesOnLockedRowWithoutLosingOmittedPeerChange(): void
    {
        $scenario = $this->createApiUpdateScenario();
        $shared = get_instance()->db;
        $harness = new TwoConnectionHarness();

        $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $harness): void {
            $this->assertNotSame($primary->conn_id->thread_id, $peer->conn_id->thread_id);
            $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
            $model = new class extends \Appointments_model {
                public $afterSnapshot;
                public function __construct() {}
                protected function snapshot_api_update_appointment(int $appointment_id): array
                {
                    $snapshot = parent::snapshot_api_update_appointment($appointment_id);
                    ($this->afterSnapshot)();
                    return $snapshot;
                }
            };
            $model->afterSnapshot = function () use ($peer, $scenario, $checkpoint): void {
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                $this->assertTrue($peer->trans_begin());
                $this->assertTrue(
                    $peer->update('appointments', ['notes' => 'peer notes'], ['id' => $scenario['appointment_id']]),
                );
                $this->assertTrue($peer->trans_commit());
            };

            $this->assertSame(
                $scenario['appointment_id'],
                $model->update_api($scenario['appointment_id'], ['location' => 'API location']),
            );
            $harness->markBranch('api_sparse_rebase');
        }, 'api_sparse_rebase');

        $this->assertSame($shared, get_instance()->db);
        $stored = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $this->assertSame('peer notes', $stored['notes']);
        $this->assertSame('API location', $stored['location']);
        $harness->assertTrace([TwoConnectionHarness::BEFORE_AUTHORITY, TwoConnectionHarness::AFTER_AUTHORITY]);
    }

    public function testSparseApiUpdateRejectsRequestedScalarDriftWithoutOverwritingPeer(): void
    {
        $scenario = $this->createApiUpdateScenario();
        $harness = new TwoConnectionHarness();

        $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $harness): void {
            $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
            $model = new class extends \Appointments_model {
                public $afterSnapshot;
                public function __construct() {}
                protected function snapshot_api_update_appointment(int $appointment_id): array
                {
                    $snapshot = parent::snapshot_api_update_appointment($appointment_id);
                    ($this->afterSnapshot)();
                    return $snapshot;
                }
            };
            $model->afterSnapshot = function () use ($peer, $scenario, $checkpoint): void {
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                $this->assertTrue(
                    $peer->update('appointments', ['notes' => 'peer wins'], ['id' => $scenario['appointment_id']]),
                );
            };

            try {
                $model->update_api($scenario['appointment_id'], ['notes' => 'API notes']);
                $this->fail('Expected requested scalar drift to be rejected.');
            } catch (\AppointmentApiUpdateException $exception) {
                $this->assertSame(409, $exception->getCode());
            }
            $harness->markBranch('api_requested_scalar_drift');
        }, 'api_requested_scalar_drift');

        $stored = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $this->assertSame('peer wins', $stored['notes']);
    }

    public function testSparseApiUpdateRejectsParentDriftWithoutMutation(): void
    {
        $scenario = $this->createApiUpdateScenario();
        $replacement = $this->createProvider($scenario['provider_id'], $scenario['service_id']);
        $harness = new TwoConnectionHarness();

        $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $replacement, $harness): void {
            $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
            $model = new class extends \Appointments_model {
                public $afterSnapshot;
                public function __construct() {}
                protected function snapshot_api_update_appointment(int $appointment_id): array
                {
                    $snapshot = parent::snapshot_api_update_appointment($appointment_id);
                    ($this->afterSnapshot)();
                    return $snapshot;
                }
            };
            $model->afterSnapshot = function () use ($peer, $scenario, $replacement, $checkpoint): void {
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                $this->assertTrue($peer->trans_begin());
                $this->assertTrue(
                    $peer->update(
                        'appointments',
                        ['id_users_provider' => $replacement],
                        ['id' => $scenario['appointment_id']],
                    ),
                );
                $this->assertTrue($peer->trans_commit());
            };

            try {
                $model->update_api($scenario['appointment_id'], ['notes' => 'API notes']);
                $this->fail('Expected the parent drift to be rejected.');
            } catch (\AppointmentApiUpdateException $exception) {
                $this->assertSame(409, $exception->getCode());
            }
            $harness->markBranch('api_parent_drift');
        }, 'api_parent_drift');

        $stored = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $this->assertSame($replacement, (int) $stored['id_users_provider']);
        $this->assertSame('initial notes', $stored['notes']);
    }

    public function testSparseApiUpdateMapsConcurrentTargetDeleteToNotFound(): void
    {
        $scenario = $this->createApiUpdateScenario();
        $harness = new TwoConnectionHarness();

        $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $harness): void {
            $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
            $model = new class extends \Appointments_model {
                public $afterSnapshot;
                public function __construct() {}
                protected function snapshot_api_update_appointment(int $appointment_id): array
                {
                    $snapshot = parent::snapshot_api_update_appointment($appointment_id);
                    ($this->afterSnapshot)();
                    return $snapshot;
                }
            };
            $model->afterSnapshot = function () use ($peer, $scenario, $checkpoint): void {
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                $this->assertTrue($peer->delete('appointments', ['id' => $scenario['appointment_id']]));
            };

            try {
                $model->update_api($scenario['appointment_id'], ['notes' => 'API notes']);
                $this->fail('Expected the deleted target to be reported as missing.');
            } catch (\AppointmentApiUpdateException $exception) {
                $this->assertSame(404, $exception->getCode());
            }
            $harness->markBranch('api_target_deleted');
        }, 'api_target_deleted');

        $this->assertNull($this->fixtures->findAppointmentById($scenario['appointment_id']));
    }

    public function testSparseApiUpdateRejectsProviderRoleDrift(): void
    {
        $scenario = $this->createApiUpdateScenario(true);
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        $this->assertNotEmpty($role['id']);
        $harness = new TwoConnectionHarness();

        try {
            $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $role, $harness): void {
                $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
                $model = new class extends \Appointments_model {
                    public $afterUserSnapshot;
                    public function __construct() {}
                    protected function snapshot_api_update_users(array $user_ids): array
                    {
                        $snapshot = parent::snapshot_api_update_users($user_ids);
                        ($this->afterUserSnapshot)();
                        return $snapshot;
                    }
                };
                $model->afterUserSnapshot = function () use ($peer, $scenario, $role, $checkpoint): void {
                    $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                    $this->assertTrue(
                        $peer->update('users', ['id_roles' => (int) $role['id']], ['id' => $scenario['provider_id']]),
                    );
                };

                try {
                    $model->update_api($scenario['appointment_id'], ['notes' => 'API notes']);
                    $this->fail('Expected the provider role drift to be rejected.');
                } catch (\AppointmentApiUpdateException $exception) {
                    $this->assertSame(409, $exception->getCode());
                }
                $harness->markBranch('api_provider_role_drift');
            }, 'api_provider_role_drift');
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $scenario['provider_role_id']],
                ['id' => $scenario['provider_id']],
            );
        }

        $stored = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $this->assertSame('initial notes', $stored['notes']);
    }

    public function testSparseApiUpdateRejectsCustomerRoleDrift(): void
    {
        $scenario = $this->createApiUpdateScenario();
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $this->assertNotEmpty($role['id']);
        $harness = new TwoConnectionHarness();

        $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $role, $harness): void {
            $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
            $model = new class extends \Appointments_model {
                public $afterUserSnapshot;
                public function __construct() {}
                protected function snapshot_api_update_users(array $user_ids): array
                {
                    $snapshot = parent::snapshot_api_update_users($user_ids);
                    ($this->afterUserSnapshot)();
                    return $snapshot;
                }
            };
            $model->afterUserSnapshot = function () use ($peer, $scenario, $role, $checkpoint): void {
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                $this->assertTrue(
                    $peer->update('users', ['id_roles' => (int) $role['id']], ['id' => $scenario['customer_id']]),
                );
            };

            try {
                $model->update_api($scenario['appointment_id'], ['notes' => 'API notes']);
                $this->fail('Expected the customer role drift to be rejected.');
            } catch (\AppointmentApiUpdateException $exception) {
                $this->assertSame(409, $exception->getCode());
            }
            $harness->markBranch('api_customer_role_drift');
        }, 'api_customer_role_drift');

        $stored = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $this->assertSame('initial notes', $stored['notes']);
    }

    public function testSparseApiUpdateMapsInvalidRebaseAfterOmittedScalarDriftToConflict(): void
    {
        $scenario = $this->createApiUpdateScenario();
        $harness = new TwoConnectionHarness();

        $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $harness): void {
            $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
            $model = new class extends \Appointments_model {
                public $afterSnapshot;
                public function __construct() {}
                protected function snapshot_api_update_appointment(int $appointment_id): array
                {
                    $snapshot = parent::snapshot_api_update_appointment($appointment_id);
                    ($this->afterSnapshot)();
                    return $snapshot;
                }
            };
            $model->afterSnapshot = function () use ($peer, $scenario, $checkpoint): void {
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                $this->assertTrue(
                    $peer->update(
                        'appointments',
                        ['start_datetime' => '2035-05-07 10:30:00'],
                        ['id' => $scenario['appointment_id']],
                    ),
                );
            };

            try {
                $model->update_api($scenario['appointment_id'], ['end_datetime' => '2035-05-07 10:00:00']);
                $this->fail('Expected the invalid rebased scalar combination to be rejected.');
            } catch (\AppointmentApiUpdateException $exception) {
                $this->assertSame(409, $exception->getCode());
            }
            $harness->markBranch('api_scalar_rebase_conflict');
        }, 'api_scalar_rebase_conflict');

        $stored = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $this->assertSame('2035-05-07 10:30:00', $stored['start_datetime']);
        $this->assertSame('2035-05-07 09:30:00', $stored['end_datetime']);
    }

    public function testSparseApiUpdateRollsBackAppointmentAndBufferCleanupTogether(): void
    {
        $scenario = $this->createApiUpdateScenario();
        $db = get_instance()->db;
        $now = date('Y-m-d H:i:s');
        $this->assertTrue(
            $db->insert('appointments', [
                'book_datetime' => $now,
                'start_datetime' => '2035-05-07 08:55:00',
                'end_datetime' => '2035-05-07 09:00:00',
                'notes' => 'existing buffer',
                'hash' => 'buffer-' . bin2hex(random_bytes(6)),
                'is_unavailability' => true,
                'id_users_provider' => $scenario['provider_id'],
                'id_parent_appointment' => $scenario['appointment_id'],
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]),
        );
        $bufferId = (int) $db->insert_id();
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeBuffer = $db->get_where('appointments', ['id' => $bufferId])->row_array();
        $model = new class extends \Appointments_model {
            public function __construct() {}
            protected function sync_buffer_unavailabilities(array $appointment): void
            {
                parent::sync_buffer_unavailabilities($appointment);
                throw new \RuntimeException('Injected failure after API buffer cleanup.');
            }
        };

        try {
            $model->update_api($scenario['appointment_id'], [
                'start_datetime' => '2035-05-07 10:00:00',
                'end_datetime' => '2035-05-07 10:30:00',
            ]);
            $this->fail('Expected API update and buffer cleanup to roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected failure after API buffer cleanup.', $exception->getMessage());
        }

        $this->assertFalse($db->trans_active());
        $this->assertSame($beforeAppointment, $this->fixtures->findAppointmentById($scenario['appointment_id']));
        $this->assertSame($beforeBuffer, $db->get_where('appointments', ['id' => $bufferId])->row_array());
    }

    public function testApiOverlapLockingReadSeesPeerCommitAfterRepeatableReadSnapshot(): void
    {
        $scenario = $this->createApiUpdateScenario(true);
        $harness = new TwoConnectionHarness();
        $peerAppointmentId = 0;

        $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $harness, &$peerAppointmentId): void {
            $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
            $model = new class extends \Appointments_model {
                public $afterSnapshot;
                public function __construct() {}
                protected function snapshot_api_update_appointment(int $appointment_id): array
                {
                    $snapshot = parent::snapshot_api_update_appointment($appointment_id);
                    ($this->afterSnapshot)();
                    return $snapshot;
                }
            };
            $model->afterSnapshot = function () use (
                $primary,
                $peer,
                $scenario,
                $checkpoint,
                &$peerAppointmentId,
            ): void {
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                $ci = &get_instance();
                $ci->db = $peer;
                try {
                    $peerModel = new class extends \Appointments_model {
                        public function __construct() {}
                    };
                    $peerAppointmentId = $peerModel->save([
                        'start_datetime' => '2035-05-07 10:00:00',
                        'end_datetime' => '2035-05-07 10:30:00',
                        'notes' => 'peer overlap after snapshot',
                        'is_unavailability' => false,
                        'id_users_provider' => $scenario['provider_id'],
                        'id_users_customer' => $scenario['customer_id'],
                        'id_services' => $scenario['service_id'],
                    ]);
                } finally {
                    $ci->db = $primary;
                }
            };

            try {
                $model->update_api($scenario['appointment_id'], [
                    'start_datetime' => '2035-05-07 10:00:00',
                    'end_datetime' => '2035-05-07 10:30:00',
                ]);
                $this->fail('Expected the current locking read to detect the peer overlap.');
            } catch (\AppointmentApiOverlapException $exception) {
                $this->assertSame(409, $exception->getCode());
            }
            $harness->markBranch('api_overlap_current_locking_read');
        }, 'api_overlap_current_locking_read');

        $this->assertGreaterThan(0, $peerAppointmentId);
        $this->createdAppointments[] = $peerAppointmentId;
        $stored = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $this->assertSame('2035-05-07 09:00:00', $stored['start_datetime']);
        $this->assertSame('2035-05-07 09:30:00', $stored['end_datetime']);
        $this->assertSame(
            'peer overlap after snapshot',
            $this->fixtures->findAppointmentById($peerAppointmentId)['notes'],
        );
        $harness->assertTrace([TwoConnectionHarness::BEFORE_AUTHORITY, TwoConnectionHarness::AFTER_AUTHORITY]);
    }

    public function testApiCreateFaultsRollBackParentBufferAndCommitPathsWithoutOrphans(): void
    {
        foreach (['parent', 'buffer', 'commit'] as $fault) {
            $scenario = $this->createApiCreateScenario();
            $db = get_instance()->db;
            $model = match ($fault) {
                'parent' => new class extends \Appointments_model {
                    public function __construct() {}
                    protected function lock_api_update_users(array $user_ids): array
                    {
                        $rows = parent::lock_api_update_users($user_ids);
                        throw new \RuntimeException('Injected API parent failure.');
                    }
                },
                'buffer' => new class extends \Appointments_model {
                    public function __construct() {}
                    protected function sync_buffer_unavailabilities(array $appointment): void
                    {
                        parent::sync_buffer_unavailabilities($appointment);
                        throw new \RuntimeException('Injected API buffer failure.');
                    }
                },
                'commit' => new class extends \Appointments_model {
                    public function __construct() {}
                    protected function commit_api_write_transaction(): bool
                    {
                        return false;
                    }
                },
            };

            try {
                $model->create_api($scenario['appointment']);
                $this->fail('Expected injected API ' . $fault . ' failure.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString($fault, strtolower($exception->getMessage()));
            }

            $this->assertFalse($db->trans_active());
            $rows = $db
                ->where('id_users_provider', $scenario['provider_id'])
                ->where('start_datetime >=', '2035-06-03 08:55:00')
                ->where('end_datetime <=', '2035-06-03 09:35:00')
                ->get('appointments')
                ->result_array();
            $this->assertSame([], $rows, $fault . ' failure left a parent or generated child row.');
        }
    }

    public function testApiCreateRejectsRoleAndServiceDriftAfterRelationshipSnapshots(): void
    {
        foreach (['role', 'service'] as $drift) {
            $scenario = $this->createApiCreateScenario();
            $db = get_instance()->db;
            $provider = $db->get_where('users', ['id' => $scenario['provider_id']])->row_array();
            $customerRole = $db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();
            $this->assertNotEmpty($provider);
            $this->assertNotEmpty($customerRole);
            $harness = new TwoConnectionHarness();

            try {
                $harness->run(function ($primary, $peer, $checkpoint) use ($scenario, $drift, $harness): void {
                    $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
                    if ($drift === 'role') {
                        $model = new class extends \Appointments_model {
                            public $afterSnapshot;
                            public function __construct() {}
                            protected function snapshot_api_update_users(array $user_ids): array
                            {
                                $snapshot = parent::snapshot_api_update_users($user_ids);
                                ($this->afterSnapshot)();
                                return $snapshot;
                            }
                        };
                        $model->afterSnapshot = function () use ($peer, $scenario, $checkpoint): void {
                            $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                            $role = $peer->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();
                            $this->assertTrue(
                                $peer->update(
                                    'users',
                                    ['id_roles' => (int) $role['id']],
                                    ['id' => $scenario['provider_id']],
                                ),
                            );
                        };
                    } else {
                        $model = new class extends \Appointments_model {
                            public $afterSnapshot;
                            public function __construct() {}
                            protected function snapshot_api_update_services(array $service_ids): array
                            {
                                $snapshot = parent::snapshot_api_update_services($service_ids);
                                ($this->afterSnapshot)();
                                return $snapshot;
                            }
                        };
                        $model->afterSnapshot = function () use ($peer, $scenario, $checkpoint): void {
                            $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
                            $serviceId = (int) $scenario['appointment']['id_services'];
                            $this->assertTrue($peer->delete('services_providers', ['id_services' => $serviceId]));
                            $this->assertTrue($peer->delete('services', ['id' => $serviceId]));
                        };
                    }

                    try {
                        $model->create_api($scenario['appointment']);
                        $this->fail('Expected API create ' . $drift . ' drift to be rejected.');
                    } catch (\AppointmentApiWriteException $exception) {
                        $this->assertSame(409, $exception->getCode());
                    }
                    $harness->markBranch('api_create_' . $drift . '_drift');
                }, 'api_create_' . $drift . '_drift');
            } finally {
                if ($drift === 'role') {
                    $db->update(
                        'users',
                        ['id_roles' => (int) $provider['id_roles']],
                        ['id' => $scenario['provider_id']],
                    );
                }
            }

            $rows = $db
                ->where('id_users_provider', $scenario['provider_id'])
                ->where('start_datetime', $scenario['appointment']['start_datetime'])
                ->get('appointments')
                ->result_array();
            $this->assertSame([], $rows);
            $harness->assertTrace([TwoConnectionHarness::BEFORE_AUTHORITY, TwoConnectionHarness::AFTER_AUTHORITY]);
        }
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
        $writeReached = false;
        $harness->run(
            function ($primary, $peer, $checkpoint) use ($reassign, $id, $replacement, &$writeReached, $harness): void {
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
                $serverInfo = strtolower((string) mysqli_get_server_info($peer->conn_id));
                // MariaDB reports lock timeout 1205 for NOWAIT; MySQL reports 3572.
                $expectedNowaitCode = str_contains($serverInfo, 'mariadb') ? 1205 : 3572;
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
                    $expectedNowaitCode,
                    $error,
                    'Expected vendor-specific NOWAIT contention, not a deadlock or unrelated SQL error.',
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

    /** @return array{appointment_id:int,customer_id:int,provider_id:int,provider_role_id:int,service_id:int} */
    private function createApiUpdateScenario(bool $useSyntheticProvider = false): array
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $provider = $useSyntheticProvider
            ? $this->createProvider($pair['provider_id'], $pair['service_id'])
            : $pair['provider_id'];
        $customer = $this->fixtures->createCustomer();
        $appointment = $this->fixtures->createAppointment(
            $provider,
            $customer,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-07 09:00:00'),
            new DateTimeImmutable('2035-05-07 09:30:00'),
            'initial notes',
        );
        $this->createdAppointments[] = $appointment;
        $providerRow = get_instance()
            ->db->get_where('users', ['id' => $provider])
            ->row_array();

        return [
            'appointment_id' => $appointment,
            'customer_id' => $customer,
            'provider_id' => $provider,
            'provider_role_id' => (int) $providerRow['id_roles'],
            'service_id' => $pair['service_id'],
        ];
    }

    private function createApiCreateScenario(): array
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $provider = $this->createProvider($pair['provider_id'], $pair['service_id']);
        $customer = $this->fixtures->createCustomer();
        $db = get_instance()->db;
        $workingPlan = array_fill_keys(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            ['start' => '08:00', 'end' => '18:00', 'breaks' => []],
        );
        $this->assertTrue(
            $db->update(
                'user_settings',
                ['working_plan' => json_encode($workingPlan, JSON_THROW_ON_ERROR)],
                ['id_users' => $provider],
            ),
        );
        $service = $db->get_where('services', ['id' => $pair['service_id']])->row_array();
        unset($service['id']);
        $service['name'] = 'api-overlap-' . bin2hex(random_bytes(5));
        $service['description'] = $service['name'];
        $service['attendants_number'] = 1;
        $service['buffer_before'] = 5;
        $service['buffer_after'] = 5;
        $this->assertTrue($db->insert('services', $service));
        $serviceId = (int) $db->insert_id();
        $this->createdServices[] = $serviceId;
        $this->assertTrue($db->insert('services_providers', ['id_users' => $provider, 'id_services' => $serviceId]));

        return [
            'provider_id' => $provider,
            'appointment' => [
                'start_datetime' => '2035-06-03 09:00:00',
                'end_datetime' => '2035-06-03 09:30:00',
                'notes' => 'api create ' . bin2hex(random_bytes(5)),
                'is_unavailability' => false,
                'id_users_provider' => $provider,
                'id_users_customer' => $customer,
                'id_services' => $serviceId,
            ],
        ];
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

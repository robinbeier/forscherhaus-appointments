<?php

namespace Tests\Unit\Models;

use Appointments_model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

require_once APPPATH . 'models/Appointments_model.php';

final class AppointmentsModelLockOrderTest extends TestCase
{
    public function testUpdateParentsLocksDeduplicatedUsersThenServices(): void
    {
        $database = new AppointmentsModelLockOrderFakeDatabase();
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $model = $this->createModel();
            $model->lock_update_parents(
                ['id_users_customer' => 30, 'id_users_provider' => 20, 'id_services' => 50],
                ['id_users_customer' => 40, 'id_users_provider' => 20, 'id_services' => 30],
                [10, 40],
            );
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertCount(2, $database->queries);
        $this->assertSame([10, 20, 30, 40], $database->queries[0]['bindings']);
        $this->assertStringContainsString('FROM `ea_users`', $database->queries[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertSame([30, 50], $database->queries[1]['bindings']);
        $this->assertStringContainsString('FROM `ea_services`', $database->queries[1]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[1]['sql']);
    }

    public function testUpdatePathLocksParentsBeforeAppointmentUpdate(): void
    {
        $database = new AppointmentsModelLockOrderFakeDatabase();
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $model = new class extends Appointments_model {
                public function __construct() {}

                public function callUpdate(array $appointment): int
                {
                    return $this->update($appointment);
                }

                protected function should_sync_buffer_unavailabilities(array $original, array $updated): bool
                {
                    return false;
                }
            };
            $model->callUpdate([
                'id' => 99,
                'id_users_customer' => 40,
                'id_users_provider' => 20,
                'id_services' => 30,
            ]);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['begin', 'users_lock', 'services_lock', 'appointment_update', 'commit'], $database->events);
    }

    public function testUpdateParentsFailsClosedWhenAParentIsMissing(): void
    {
        $database = new AppointmentsModelLockOrderFakeDatabase();
        $database->missingTable = 'services';
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $this->expectException(RuntimeException::class);
            $this->createModel()->lock_update_parents(
                ['id_users_customer' => 30, 'id_users_provider' => 20, 'id_services' => 50],
                ['id_users_customer' => 40, 'id_users_provider' => 20, 'id_services' => 30],
            );
        } finally {
            $CI->db = $originalDb;
        }
    }

    public function testDeleteLocksAppointmentBeforeBufferCleanup(): void
    {
        $database = new AppointmentsModelLockOrderFakeDatabase();
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $this->createModel()->delete(99);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(
            ['begin', 'appointment_lock', 'buffer_delete', 'appointment_delete', 'commit'],
            $database->events,
        );
        $this->assertStringContainsString('FROM `ea_appointments`', $database->queries[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertSame([99], $database->queries[0]['bindings']);
    }

    public function testBufferSyncReadsCurrentServiceUnderLock(): void
    {
        $database = new AppointmentsModelLockOrderFakeDatabase();
        $database->appointment = [
            'id' => 99,
            'id_users_customer' => 30,
            'id_users_provider' => 20,
            'id_services' => 50,
            'is_unavailability' => false,
            'start_datetime' => '2035-02-17 09:00:00',
            'end_datetime' => '2035-02-17 09:30:00',
        ];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = $this->createModel();
        $sync = (new ReflectionClass(Appointments_model::class))->getMethod('sync_buffer_unavailabilities');

        try {
            $sync->invoke($model, $database->appointment);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['services_lock', 'buffer_delete'], $database->events);
        $this->assertStringContainsString('SELECT * FROM `ea_services`', $database->queries[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertSame([50], $database->queries[0]['bindings']);
    }

    public function testStandaloneServiceBufferSyncKeepsOrderedLocksInOneTransaction(): void
    {
        $database = new AppointmentsModelLockOrderFakeDatabase();
        $database->appointment = [
            'id' => 99,
            'id_users_customer' => 30,
            'id_users_provider' => 20,
            'id_services' => 50,
            'is_unavailability' => false,
            'start_datetime' => '2035-02-17 09:00:00',
            'end_datetime' => '2035-02-17 09:30:00',
        ];
        $database->service = [
            'id' => 50,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'attendants_number' => 1,
        ];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $originalServicesModel = $CI->services_model ?? null;
        $CI->db = $database;
        $CI->services_model = new AppointmentsModelLockOrderFakeServicesModel($database);

        try {
            $this->createModel()->sync_service_buffer_unavailabilities(50);
        } finally {
            $CI->db = $originalDb;
            $CI->services_model = $originalServicesModel;
        }

        $this->assertSame(
            [
                'begin',
                'buffer_parent_locks',
                'appointment_lock',
                'buffer_batch_delete',
                'services_lock',
                'buffer_delete',
                'commit',
            ],
            $database->events,
        );
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
    }

    private function createModel(): Appointments_model
    {
        $reflection = new ReflectionClass(Appointments_model::class);
        return $reflection->newInstanceWithoutConstructor();
    }
}

final class AppointmentsModelLockOrderFakeDatabase
{
    /** @var array<int, array{sql:string,bindings:array<int, mixed>}> */
    public array $queries = [];
    public ?string $missingTable = null;
    /** @var list<string> */
    public array $events = [];
    /** @var array<string, mixed> */
    public array $appointment = ['id' => 99, 'id_users_customer' => 30, 'id_users_provider' => 20, 'id_services' => 50];
    /** @var array<string, mixed> */
    public array $service = ['id' => 50, 'buffer_before' => 0, 'buffer_after' => 0, 'attendants_number' => 1];
    private bool $usedWhereIn = false;

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): AppointmentsModelLockOrderFakeQuery
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];
        if (str_contains($sql, 'ea_appointments')) {
            $this->events[] = 'appointment_lock';
            return new AppointmentsModelLockOrderFakeQuery(1, $this->appointment);
        }
        $table = str_contains($sql, 'ea_users') ? 'users' : 'services';
        $this->events[] = $table . '_lock';
        $rowCount = $table === $this->missingTable ? count($bindings) - 1 : count($bindings);

        return new AppointmentsModelLockOrderFakeQuery($rowCount, $table === 'services' ? $this->service : []);
    }

    public function trans_begin(): bool
    {
        $this->events[] = 'begin';
        return true;
    }

    public function trans_commit(): bool
    {
        $this->events[] = 'commit';
        return true;
    }

    public function trans_rollback(): bool
    {
        $this->events[] = 'rollback';
        return true;
    }

    public function update(string $table, array $data, array $where = []): bool
    {
        $this->events[] = 'appointment_update';
        return true;
    }

    public function where(string $field, mixed $value): self
    {
        return $this;
    }

    public function delete(?string $table = null, array $where = []): bool
    {
        if ($this->usedWhereIn) {
            $this->events[] = 'buffer_batch_delete';
            $this->usedWhereIn = false;
        } else {
            $this->events[] = $table === 'appointments' && $where === [] ? 'buffer_delete' : 'appointment_delete';
        }
        return true;
    }

    public function select(string $fields): self
    {
        return $this;
    }

    public function from(string $table): self
    {
        return $this;
    }

    public function join(string $table, string $condition, string $type = ''): self
    {
        return $this;
    }

    public function group_start(): self
    {
        return $this;
    }

    public function or_where(string $field, mixed $value = null, bool $escape = true): self
    {
        return $this;
    }

    public function group_end(): self
    {
        return $this;
    }

    public function group_by(string $field): self
    {
        return $this;
    }

    public function order_by(string $field, string $direction = ''): self
    {
        return $this;
    }

    public function get_compiled_select(): string
    {
        return 'SELECT appointments.* FROM `ea_appointments`';
    }

    public function escape(mixed $value): string
    {
        return "'" . (string) $value . "'";
    }

    /** @param list<int> $values */
    public function where_in(string $field, array $values): self
    {
        $this->usedWhereIn = true;
        return $this;
    }

    /**
     * @param array<string, mixed> $where
     */
    public function get_where(string $table, array $where): AppointmentsModelLockOrderFakeQuery
    {
        return new AppointmentsModelLockOrderFakeQuery(1, $this->appointment);
    }
}

final class AppointmentsModelLockOrderFakeServicesModel
{
    public function __construct(private readonly AppointmentsModelLockOrderFakeDatabase $database) {}

    /** @return array<string, mixed> */
    public function lock_buffer_sync_parents(int $serviceId): array
    {
        $this->database->events[] = 'buffer_parent_locks';
        return $this->database->service;
    }
}

final class AppointmentsModelLockOrderFakeQuery
{
    /** @param array<string, mixed> $row */
    public function __construct(private readonly int $rowCount, private readonly array $row = []) {}

    public function num_rows(): int
    {
        return $this->rowCount;
    }

    /** @return array<string, mixed> */
    public function row_array(): array
    {
        return $this->row;
    }

    /** @return list<array<string, mixed>> */
    public function result_array(): array
    {
        return $this->row === [] ? [] : [$this->row];
    }
}

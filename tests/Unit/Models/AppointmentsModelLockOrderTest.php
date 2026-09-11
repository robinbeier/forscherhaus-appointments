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
        $table = str_contains($sql, 'ea_users') ? 'users' : 'services';
        $this->events[] = $table . '_lock';
        $rowCount = $table === $this->missingTable ? count($bindings) - 1 : count($bindings);

        return new AppointmentsModelLockOrderFakeQuery($rowCount);
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

    /**
     * @param array<string, mixed> $where
     */
    public function get_where(string $table, array $where): AppointmentsModelLockOrderFakeQuery
    {
        return new AppointmentsModelLockOrderFakeQuery(1, $this->appointment);
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
}

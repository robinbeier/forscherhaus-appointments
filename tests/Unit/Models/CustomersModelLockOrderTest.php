<?php

namespace Tests\Unit\Models;

use Customers_model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once APPPATH . 'models/Customers_model.php';
require_once APPPATH . 'libraries/Reschedule_authority.php';

final class CustomersModelLockOrderTest extends TestCase
{
    public function testInsertNewReusesHeldCreationLockWithoutReleasingOuterLock(): void
    {
        $database = new CustomersModelHeldCreationLockFakeDatabase();
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $modelClass = new class extends Customers_model {
                protected function insert(array $customer): int
                {
                    return 417;
                }

                public function validate(array $customer): void {}
            };
            $model = (new ReflectionClass($modelClass::class))->newInstanceWithoutConstructor();
            $lockName = Reschedule_authority::creationIdentityLockName('held@example.invalid', 'unit-test');

            self::assertNotNull($lockName);
            self::assertSame(417, $model->insert_new(['email' => 'held@example.invalid'], $lockName));

            $contended = $database->query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->row_array();
            self::assertSame(0, (int) ($contended['acquired'] ?? -1));
            self::assertSame([], $database->releaseQueries);
        } finally {
            $CI->db = $originalDb;
        }
    }

    public function testDeleteLocksCustomerAndAppointmentsBeforeBufferCleanup(): void
    {
        $database = new CustomersModelLockOrderFakeDatabase();
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $model = (new ReflectionClass(Customers_model::class))->newInstanceWithoutConstructor();
            $model->delete(42);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(
            ['begin', 'customer_lock', 'appointment_lock', 'buffer_cleanup', 'delete_users', 'commit'],
            $database->events,
        );
        $this->assertStringContainsString('FROM `ea_users`', $database->queries[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertSame([42, 7], $database->queries[0]['bindings']);
        $this->assertStringContainsString('ORDER BY `id` FOR UPDATE', $database->queries[1]['sql']);
        $this->assertSame([42], $database->queries[1]['bindings']);
        $this->assertStringContainsString('DELETE `buffer_blocks`', $database->queries[2]['sql']);
        $this->assertSame([42], $database->queries[2]['bindings']);
    }
}

final class CustomersModelHeldCreationLockFakeDatabase
{
    public string $database = 'unit-test';

    /** @var list<array{sql:string,bindings:array<int,mixed>}> */
    public array $queries = [];

    /** @var list<array{sql:string,bindings:array<int,mixed>}> */
    public array $releaseQueries = [];

    /** @param array<int,mixed> $bindings */
    public function query(string $sql, array $bindings = []): CustomersModelHeldCreationLockFakeQuery
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->releaseQueries[] = ['sql' => $sql, 'bindings' => $bindings];
        }

        return new CustomersModelHeldCreationLockFakeQuery(str_contains($sql, 'GET_LOCK') ? ['acquired' => 0] : []);
    }
}

final class CustomersModelHeldCreationLockFakeQuery
{
    /** @param array<string,mixed> $row */
    public function __construct(private readonly array $row) {}

    /** @return array<string,mixed> */
    public function row_array(): array
    {
        return $this->row;
    }
}

final class CustomersModelLockOrderFakeDatabase
{
    /** @var list<string> */
    public array $events = [];
    /** @var array<int, array{sql:string,bindings:array<int, mixed>}> */
    public array $queries = [];

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

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    /** @param array<string, mixed> $where */
    public function get_where(string $table, array $where): CustomersModelLockOrderFakeQuery
    {
        return new CustomersModelLockOrderFakeQuery(['id' => 7]);
    }

    /** @param array<int, mixed> $bindings */
    public function query(string $sql, array $bindings = []): CustomersModelLockOrderFakeQuery
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

        if (str_contains($sql, 'FROM `ea_users`')) {
            $this->events[] = 'customer_lock';
            return new CustomersModelLockOrderFakeQuery(['id' => 42]);
        }

        if (str_contains($sql, 'SELECT `id` FROM `ea_appointments`')) {
            $this->events[] = 'appointment_lock';
            return new CustomersModelLockOrderFakeQuery([]);
        }

        $this->events[] = 'buffer_cleanup';
        return new CustomersModelLockOrderFakeQuery([]);
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where = []): bool
    {
        $this->events[] = 'delete_' . $table;
        return true;
    }

    public function affected_rows(): int
    {
        return 1;
    }
}

final class CustomersModelLockOrderFakeQuery
{
    /** @param array<string, mixed> $row */
    public function __construct(private readonly array $row) {}

    /** @return array<string, mixed> */
    public function row_array(): array
    {
        return $this->row;
    }
}

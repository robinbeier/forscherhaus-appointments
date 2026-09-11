<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Services_model;

require_once APPPATH . 'models/Services_model.php';

final class ServicesModelLockOrderTest extends TestCase
{
    public function testUpdateLocksProviderUsersBeforeServiceRow(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->providerRows = [
            ['id_users_provider' => 30],
            ['id_users_provider' => 10],
            ['id_users_provider' => 30],
        ];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = (new ReflectionClass(Services_model::class))->newInstanceWithoutConstructor();
        $update = (new ReflectionClass(Services_model::class))->getMethod('update');

        try {
            $update->invoke($model, ['id' => 42, 'name' => 'Updated'], true);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(
            ['provider_snapshot', 'user_lock', 'user_lock', 'update_services', 'provider_current'],
            $database->events,
        );
        $this->assertSame([10, 30], $database->userLockIds);
        $this->assertSame(['services'], $database->updates);
        $this->assertStringNotContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertSame([42], $database->queries[0]['bindings']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[1]['sql']);
        $this->assertSame([10], $database->queries[1]['bindings']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[2]['sql']);
        $this->assertSame([30], $database->queries[2]['bindings']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[3]['sql']);
        $this->assertSame([42], $database->queries[3]['bindings']);
    }

    public function testUpdateAbortsAfterProviderDriftWithoutFurtherWrites(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->providerRows = [['id_users_provider' => 10]];
        $database->currentProviderRows = [['id_users_provider' => 20]];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = (new ReflectionClass(Services_model::class))->newInstanceWithoutConstructor();
        $update = (new ReflectionClass(Services_model::class))->getMethod('update');

        try {
            try {
                $update->invoke($model, ['id' => 42, 'name' => 'Updated'], true);
                $this->fail('Expected provider drift to abort the update.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Service appointments changed during update.', $exception->getMessage());
            }
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['services'], $database->updates);
        $this->assertSame(['provider_snapshot', 'user_lock', 'update_services', 'provider_current'], $database->events);
    }

    public function testDeleteLocksServiceBeforeBufferCleanupAndServiceDelete(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $model = (new ReflectionClass(Services_model::class))->newInstanceWithoutConstructor();
            $model->delete(42);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['begin', 'service_lock', 'buffer_cleanup', 'delete_services', 'commit'], $database->events);
        $this->assertStringContainsString('FROM `ea_services`', $database->queries[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertSame([42], $database->queries[0]['bindings']);
        $this->assertStringContainsString('DELETE `buffer_blocks`', $database->queries[1]['sql']);
        $this->assertSame([42], $database->queries[1]['bindings']);
        $this->assertSame(['services'], $database->deletes);
    }

    public function testDeleteKeepsIdempotentNoOpWhenServiceParentIsMissing(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->serviceExists = false;
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $model = (new ReflectionClass(Services_model::class))->newInstanceWithoutConstructor();
            $model->delete(42);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['begin', 'service_lock', 'buffer_cleanup', 'delete_services', 'commit'], $database->events);
        $this->assertStringContainsString('FROM `ea_services`', $database->queries[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertStringContainsString('DELETE `buffer_blocks`', $database->queries[1]['sql']);
        $this->assertSame(['services'], $database->deletes);
    }
}

final class ServicesModelLockOrderFakeDatabase
{
    /** @var array<int, array{sql:string,bindings:array<int, mixed>}> */
    public array $queries = [];
    /** @var array<int, string> */
    public array $deletes = [];
    /** @var list<string> */
    public array $events = [];
    public bool $serviceExists = true;
    /** @var list<array<string, mixed>> */
    public array $providerRows = [];
    /** @var list<array<string, mixed>> */
    public array $currentProviderRows = [];
    /** @var list<int> */
    public array $userLockIds = [];
    /** @var list<string> */
    public array $updates = [];
    public bool $transactionActive = true;

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function trans_begin(): bool
    {
        $this->events[] = 'begin';
        return true;
    }

    public function trans_active(): bool
    {
        return $this->transactionActive;
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

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): ServicesModelLockOrderFakeQuery
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

        if (str_contains($sql, 'DISTINCT `id_users_provider`')) {
            $is_current = str_contains($sql, 'FOR UPDATE');
            $this->events[] = $is_current ? 'provider_current' : 'provider_snapshot';
            $rows = $is_current && $this->currentProviderRows !== [] ? $this->currentProviderRows : $this->providerRows;
            return new ServicesModelLockOrderFakeQuery(0, $rows);
        }

        if (str_contains($sql, 'FROM `ea_users`')) {
            $this->events[] = 'user_lock';
            $this->userLockIds[] = (int) ($bindings[0] ?? 0);
            return new ServicesModelLockOrderFakeQuery(1);
        }

        if (str_contains($sql, 'FROM `ea_services`')) {
            $this->events[] = 'service_lock';
            return new ServicesModelLockOrderFakeQuery($this->serviceExists ? 1 : 0);
        }

        $this->events[] = 'buffer_cleanup';
        return new ServicesModelLockOrderFakeQuery(0);
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where = []): bool
    {
        $this->deletes[] = $table;
        $this->events[] = 'delete_' . $table;
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where = []): bool
    {
        $this->updates[] = $table;
        $this->events[] = 'update_' . $table;
        return true;
    }
}

final class ServicesModelLockOrderFakeQuery
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly int $rowCount, private readonly array $rows = []) {}

    public function num_rows(): int
    {
        return $this->rowCount;
    }

    /** @return list<array<string, mixed>> */
    public function result_array(): array
    {
        return $this->rows;
    }
}

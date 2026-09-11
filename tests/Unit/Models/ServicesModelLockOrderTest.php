<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Services_model;

require_once APPPATH . 'models/Services_model.php';

final class ServicesModelLockOrderTest extends TestCase
{
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

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
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

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): ServicesModelLockOrderFakeQuery
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

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
}

final class ServicesModelLockOrderFakeQuery
{
    public function __construct(private readonly int $rowCount) {}

    public function num_rows(): int
    {
        return $this->rowCount;
    }
}

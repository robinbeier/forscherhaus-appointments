<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
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
        $database->serviceRow = ['buffer_before' => 0, 'buffer_after' => 0];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = new ServicesModelLockOrderTestModel();

        try {
            $model->callUpdate(
                ['id' => 42, 'name' => 'Updated', 'buffer_before' => 0, 'buffer_after' => 20],
                ['buffer_before' => 0, 'buffer_after' => 0],
            );
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(
            [
                'provider_snapshot',
                'user_lock',
                'user_lock',
                'service_current',
                'provider_current',
                'update_services',
                'buffer_sync',
            ],
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
        $this->assertStringContainsString('SELECT `id`, `id_users_provider`', $database->queries[4]['sql']);
        $this->assertStringContainsString('ORDER BY `id` ASC FOR UPDATE', $database->queries[4]['sql']);
        $this->assertSame([42], $database->queries[4]['bindings']);
    }

    public function testUpdateAbortsAfterProviderDriftWithoutFurtherWrites(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->providerRows = [['id_users_provider' => 10]];
        $database->currentProviderRows = [['id_users_provider' => 20]];
        $database->serviceRow = ['buffer_before' => 0, 'buffer_after' => 0];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = new ServicesModelLockOrderTestModel();

        try {
            try {
                $model->callUpdate(
                    ['id' => 42, 'name' => 'Updated', 'buffer_before' => 0, 'buffer_after' => 20],
                    ['buffer_before' => 0, 'buffer_after' => 0],
                );
                $this->fail('Expected provider drift to abort the update.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Service appointments changed during update.', $exception->getMessage());
            }
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame([], $database->updates);
        $this->assertSame(['provider_snapshot', 'user_lock', 'service_current', 'provider_current'], $database->events);
    }

    public function testBufferSyncParentLockRequiresActiveTransactionBeforeAnyQuery(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->transactionActive = false;
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = new ServicesModelLockOrderTestModel();

        try {
            $model->lock_buffer_sync_parents(42);
            $this->fail('Expected parent locking without a transaction to abort.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Service buffer synchronization requires an active transaction.',
                $exception->getMessage(),
            );
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame([], $database->events);
        $this->assertSame([], $database->queries);
        $this->assertSame([], $database->updates);
    }

    public function testSparseUpdateUsesOnlyServiceLockAndPreservesCurrentBuffers(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->serviceRow = ['buffer_before' => 25, 'buffer_after' => 35];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = new ServicesModelLockOrderTestModel();

        try {
            $this->assertSame(
                42,
                $model->callUpdate(['id' => 42, 'name' => 'Updated'], ['buffer_before' => 25, 'buffer_after' => 35]),
            );
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['service_current', 'update_services'], $database->events);
        $this->assertSame([42], $database->queries[0]['bindings']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertSame(25, $database->updatedData['buffer_before']);
        $this->assertSame(35, $database->updatedData['buffer_after']);
        $this->assertSame(['services'], $database->updates);
    }

    public function testCurrentLockedBuffersDetermineWhetherResyncIsRequired(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->serviceRow = ['buffer_before' => 25, 'buffer_after' => 35];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = new ServicesModelLockOrderTestModel();

        try {
            $this->assertSame(
                42,
                $model->callUpdate(
                    ['id' => 42, 'name' => 'Updated', 'buffer_before' => 1, 'buffer_after' => 2],
                    ['buffer_before' => 25, 'buffer_after' => 35],
                ),
            );
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(1, $database->updatedData['buffer_before']);
        $this->assertSame(2, $database->updatedData['buffer_after']);
        $this->assertSame(
            ['provider_snapshot', 'service_current', 'provider_current', 'update_services', 'buffer_sync'],
            $database->events,
        );
    }

    public function testNeutralUpdateAbortsWhenCurrentBufferValuesDrifted(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->serviceRow = ['buffer_before' => 25, 'buffer_after' => 40];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = new ServicesModelLockOrderTestModel();

        try {
            $model->callUpdate(
                ['id' => 42, 'name' => 'Updated', 'buffer_before' => 25, 'buffer_after' => 35],
                ['buffer_before' => 25, 'buffer_after' => 35],
            );
            $this->fail('Expected concurrent buffer drift to abort the neutral update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Service buffer values changed concurrently.', $exception->getMessage());
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['service_current'], $database->events);
        $this->assertSame([], $database->updates);
    }

    public function testStandaloneSparseUpdateOwnsTransactionAndCommits(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->transactionActive = false;
        $database->serviceRow = ['buffer_before' => 25, 'buffer_after' => 35];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = new ServicesModelLockOrderTestModel();

        try {
            $this->assertSame(42, $model->callUpdate(['id' => 42, 'name' => 'Updated']));
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['begin', 'service_current', 'update_services', 'commit'], $database->events);
    }

    public function testStandaloneBufferChangeOwnsCommitAfterSynchronization(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->transactionActive = false;
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        try {
            $model = new ServicesModelLockOrderTestModel();
            $model->callUpdate(
                ['id' => 42, 'name' => 'Updated', 'buffer_after' => 20],
                ['buffer_before' => 0, 'buffer_after' => 0],
            );
        } finally {
            $CI->db = $originalDb;
        }
        $this->assertSame(
            [
                'begin',
                'provider_snapshot',
                'service_current',
                'provider_current',
                'update_services',
                'buffer_sync',
                'commit',
            ],
            $database->events,
        );
        $this->assertFalse($database->transactionActive);
    }

    public function testStandaloneBufferChangeRollsBackBeforeWrite(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $database->transactionActive = false;
        $database->serviceRow = ['buffer_before' => 25, 'buffer_after' => 35];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;
        $model = new ServicesModelLockOrderTestModel();

        try {
            $model->callUpdate(['id' => 42, 'name' => 'Updated', 'buffer_before' => 1, 'buffer_after' => 2]);
            $this->fail('Expected the standalone buffer change to abort.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Service buffer changes require expected values for atomic synchronization.',
                $exception->getMessage(),
            );
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(['begin', 'service_current', 'rollback'], $database->events);
        $this->assertSame([], $database->updates);
    }

    public function testDeleteLocksServiceBeforeBufferCleanupAndServiceDelete(): void
    {
        $database = new ServicesModelLockOrderFakeDatabase();
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $model = new ServicesModelLockOrderTestModel();
            $model->delete(42);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(
            ['begin', 'service_lock', 'appointment_lock', 'buffer_cleanup', 'delete_services', 'commit'],
            $database->events,
        );
        $this->assertStringContainsString('FROM `ea_services`', $database->queries[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertSame([42], $database->queries[0]['bindings']);
        $this->assertStringContainsString('FROM `ea_appointments`', $database->queries[1]['sql']);
        $this->assertStringContainsString('ORDER BY `id` FOR UPDATE', $database->queries[1]['sql']);
        $this->assertSame([42], $database->queries[1]['bindings']);
        $this->assertStringContainsString('DELETE `buffer_blocks`', $database->queries[2]['sql']);
        $this->assertSame([42], $database->queries[2]['bindings']);
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
            $model = new ServicesModelLockOrderTestModel();
            $model->delete(42);
        } finally {
            $CI->db = $originalDb;
        }

        $this->assertSame(
            ['begin', 'service_lock', 'appointment_lock', 'buffer_cleanup', 'delete_services', 'commit'],
            $database->events,
        );
        $this->assertStringContainsString('FROM `ea_services`', $database->queries[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[0]['sql']);
        $this->assertStringContainsString('FROM `ea_appointments`', $database->queries[1]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $database->queries[1]['sql']);
        $this->assertStringContainsString('DELETE `buffer_blocks`', $database->queries[2]['sql']);
        $this->assertSame(['services'], $database->deletes);
    }
}

final class ServicesModelLockOrderTestModel extends Services_model
{
    public function __construct() {}

    /** @param array<string, mixed>|null $expectedBufferValues */
    public function callUpdate(array $service, ?array $expectedBufferValues = null): int
    {
        return $this->update($service, $expectedBufferValues);
    }

    protected function sync_service_buffer_unavailabilities(int $service_id): void
    {
        get_instance()->db->events[] = 'buffer_sync';
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
    /** @var array<string, mixed> */
    public array $updatedData = [];
    /** @var array<string, mixed> */
    public array $serviceRow = ['buffer_before' => 0, 'buffer_after' => 0];
    public bool $transactionActive = true;
    /** @var array<string, mixed> */

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function trans_begin(): bool
    {
        $this->events[] = 'begin';
        $this->transactionActive = true;
        return true;
    }

    public function trans_active(): bool
    {
        return $this->transactionActive;
    }

    public function trans_commit(): bool
    {
        $this->events[] = 'commit';
        $this->transactionActive = false;
        return true;
    }

    public function trans_rollback(): bool
    {
        $this->events[] = 'rollback';
        $this->transactionActive = false;
        return true;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): ServicesModelLockOrderFakeQuery
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

        if (str_contains($sql, '`id_users_provider` FROM')) {
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

        if (str_starts_with(trim($sql), 'SELECT') && str_contains($sql, 'FROM `ea_appointments`')) {
            $this->events[] = 'appointment_lock';
            return new ServicesModelLockOrderFakeQuery(1);
        }

        if (str_contains($sql, 'FROM `ea_services`')) {
            if (str_contains($sql, 'SELECT *')) {
                $this->events[] = 'service_current';
                return new ServicesModelLockOrderFakeQuery(1, [$this->serviceRow]);
            }
            if (str_contains($sql, 'buffer_before')) {
                $this->events[] = 'service_current';
                return new ServicesModelLockOrderFakeQuery(1, [$this->serviceRow]);
            }
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
        $this->updatedData = $data;
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

    /** @return array<string, mixed> */
    public function row_array(): array
    {
        return $this->rows[0] ?? [];
    }
}

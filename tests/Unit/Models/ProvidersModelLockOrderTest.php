<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'models/Providers_model.php';

/** Deterministic unit coverage for the Providers update lock/write order. */
final class ProvidersModelLockOrderTest extends TestCase
{
    public function testUpdateLocksCurrentAndRequestedServiceParentsBeforeReplacingAssociations(): void
    {
        $database = new ProvidersModelLockOrderFakeDatabase();
        $database->currentAssociations = [['id_services' => 30], ['id_services' => 10], ['id_services' => 30]];
        $database->lockedServices = [10, 20, 30];
        $CI = &get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            $model = new ProvidersModelLockOrderTestModel();
            $model->callUpdate([
                'id' => 42,
                'first_name' => 'Synthetic',
                'last_name' => 'Provider',
                'email' => 'provider@synthetic.invalid',
                'services' => [30, 20, 30],
                'settings' => ['username' => 'provider', 'password' => 'synthetic-password'],
            ]);
        } finally {
            $CI->db = $originalDb;
        }

        $lockIndex = array_search('service_parent_lock', $database->events, true);
        $deleteIndex = array_search('delete_services_providers', $database->events, true);
        self::assertIsInt($lockIndex);
        self::assertIsInt($deleteIndex);
        self::assertLessThan($deleteIndex, $lockIndex);
        self::assertSame([10, 20, 30], $database->serviceLockBindings);
        self::assertSame([30, 20], $database->writtenServiceIds);
        self::assertSame(
            [30, 20],
            array_values(array_unique($database->writtenServiceIds, SORT_NUMERIC)),
            'Association writes must use the canonical requested service IDs included in the parent lock set.',
        );
        self::assertContains('update_users', $database->events);
    }
}

final class ProvidersModelLockOrderTestModel extends Providers_model
{
    public function __construct() {}

    /** @param array<string, mixed> $provider */
    public function callUpdate(array $provider): int
    {
        return $this->update($provider);
    }
}

final class ProvidersModelLockOrderFakeDatabase
{
    /** @var list<string> */
    public array $events = [];
    /** @var list<array<string, mixed>> */
    public array $currentAssociations = [];
    /** @var list<int> */
    public array $lockedServices = [];
    /** @var list<int> */
    public array $serviceLockBindings = [];
    /** @var list<int> */
    public array $writtenServiceIds = [];
    public bool $transactionActive = true;

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function trans_active(): bool
    {
        return $this->transactionActive;
    }

    public function trans_begin(): bool
    {
        $this->events[] = 'begin';
        $this->transactionActive = true;
        return true;
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

    public function trans_status(): bool
    {
        return true;
    }

    /** @param array<int, mixed> $bindings */
    public function query(string $sql, array $bindings = []): ProvidersModelLockOrderFakeQuery
    {
        if (str_contains($sql, 'id_roles') && str_contains($sql, 'FROM `ea_users`')) {
            $this->events[] = 'target_lock';
            return new ProvidersModelLockOrderFakeQuery([['id_roles' => 7]]);
        }

        if (str_contains($sql, 'FROM `ea_services`') && str_contains($sql, 'FOR UPDATE')) {
            $this->events[] = 'service_parent_lock';
            $this->serviceLockBindings = array_map('intval', $bindings);
            return new ProvidersModelLockOrderFakeQuery(
                array_map(static fn(int $id): array => ['id' => $id], $this->lockedServices),
            );
        }

        throw new RuntimeException('Unexpected SQL in Providers model lock-order test: ' . $sql);
    }

    /** @param array<string, mixed> $where */
    public function get_where(string $table, array $where = []): ProvidersModelLockOrderFakeQuery
    {
        if ($table === 'roles') {
            return new ProvidersModelLockOrderFakeQuery([['id' => 7]]);
        }
        if ($table === 'services_providers') {
            return new ProvidersModelLockOrderFakeQuery($this->currentAssociations);
        }
        if ($table === 'user_settings') {
            return new ProvidersModelLockOrderFakeQuery([['salt' => 'synthetic-salt']]);
        }
        throw new RuntimeException('Unexpected table in Providers model lock-order test: ' . $table);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $where */
    public function update(string $table, array $data, array $where = []): bool
    {
        $this->events[] = 'update_' . $table;
        return true;
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where = []): bool
    {
        $this->events[] = 'delete_' . $table;
        return true;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): bool
    {
        $this->events[] = 'insert_' . $table;
        if ($table === 'services_providers') {
            $this->writtenServiceIds[] = (int) ($data['id_services'] ?? 0);
        }
        return true;
    }
}

final class ProvidersModelLockOrderFakeQuery
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

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

    public function num_rows(): int
    {
        return count($this->rows);
    }
}

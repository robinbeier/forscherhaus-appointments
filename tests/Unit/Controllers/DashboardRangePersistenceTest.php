<?php

namespace Tests\Unit\Controllers;

use Dashboard;
use DateTimeImmutable;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard.php';

class DashboardRangePersistenceTest extends TestCase
{
    public function testPersistProviderDashboardRangeSkipsWriteWhenRangeUnchanged(): void
    {
        $db = new FakeDashboardRangeDb([
            'dashboard_range_start' => '2026-11-24',
            'dashboard_range_end' => '2026-11-30',
        ]);
        $controller = $this->createController($db);

        $controller->callPersistProviderDashboardRange(
            15,
            new DateTimeImmutable('2026-11-24'),
            new DateTimeImmutable('2026-11-30'),
        );

        $this->assertSame(0, $db->insertCalls);
        $this->assertSame(0, $db->updateCalls);
    }

    public function testPersistProviderDashboardRangeCreatesSettingsRowWhenMissing(): void
    {
        $db = new FakeDashboardRangeDb(null);
        $controller = $this->createController($db);

        $controller->callPersistProviderDashboardRange(
            44,
            new DateTimeImmutable('2026-11-24'),
            new DateTimeImmutable('2026-11-30'),
        );

        $this->assertSame(1, $db->insertCalls);
        $this->assertSame(0, $db->updateCalls);
        $this->assertSame('user_settings', $db->insertTable);
        $this->assertSame(
            [
                'id_users' => 44,
                'dashboard_range_start' => '2026-11-24',
                'dashboard_range_end' => '2026-11-30',
            ],
            $db->insertData,
        );
    }

    public function testPersistProviderDashboardRangeUpdatesExistingRowWhenRangeChanges(): void
    {
        $db = new FakeDashboardRangeDb([
            'dashboard_range_start' => '2026-11-24',
            'dashboard_range_end' => '2026-11-29',
        ]);
        $controller = $this->createController($db);

        $controller->callPersistProviderDashboardRange(
            44,
            new DateTimeImmutable('2026-11-24'),
            new DateTimeImmutable('2026-11-30'),
        );

        $this->assertSame(0, $db->insertCalls);
        $this->assertSame(1, $db->updateCalls);
        $this->assertSame('user_settings', $db->updateTable);
        $this->assertSame(
            [
                'dashboard_range_start' => '2026-11-24',
                'dashboard_range_end' => '2026-11-30',
            ],
            $db->updateData,
        );
        $this->assertSame(['id_users' => 44], $db->updateWhere);
    }

    public function testHasProviderDashboardRangeColumnsCachesFieldChecks(): void
    {
        $db = new FakeDashboardRangeDb(null);
        $controller = $this->createController($db);

        $this->assertTrue($controller->callHasProviderDashboardRangeColumns());
        $this->assertTrue($controller->callHasProviderDashboardRangeColumns());
        $this->assertCount(2, $db->fieldExistsCalls);
        $this->assertSame(
            [['dashboard_range_start', 'user_settings'], ['dashboard_range_end', 'user_settings']],
            $db->fieldExistsCalls,
        );
    }

    private function createController(FakeDashboardRangeDb $db): object
    {
        return new class ($db) extends Dashboard {
            public function __construct(FakeDashboardRangeDb $db)
            {
                $this->db = $db;
            }

            public function callPersistProviderDashboardRange(
                int $provider_id,
                DateTimeImmutable $start,
                DateTimeImmutable $end,
            ): void {
                $this->persistProviderDashboardRange($provider_id, $start, $end);
            }

            public function callHasProviderDashboardRangeColumns(): bool
            {
                return $this->hasProviderDashboardRangeColumns();
            }
        };
    }
}

class FakeDashboardRangeDb
{
    public ?string $insertTable = null;

    public array $insertData = [];

    public ?string $updateTable = null;

    public array $updateData = [];

    public array $updateWhere = [];

    public int $insertCalls = 0;

    public int $updateCalls = 0;

    public array $fieldExistsCalls = [];

    private ?array $row;

    public function __construct(?array $row)
    {
        $this->row = $row;
    }

    public function select(array $fields): self
    {
        return $this;
    }

    public function from(string $table): self
    {
        return $this;
    }

    public function where(string $field, int $value): self
    {
        return $this;
    }

    public function get(): FakeDashboardRangeResult
    {
        return new FakeDashboardRangeResult($this->row);
    }

    public function insert(string $table, array $data): bool
    {
        $this->insertCalls++;
        $this->insertTable = $table;
        $this->insertData = $data;

        return true;
    }

    public function update(string $table, array $data, array $where): bool
    {
        $this->updateCalls++;
        $this->updateTable = $table;
        $this->updateData = $data;
        $this->updateWhere = $where;

        return true;
    }

    public function field_exists(string $field_name, string $table_name): bool
    {
        $this->fieldExistsCalls[] = [$field_name, $table_name];

        return true;
    }
}

class FakeDashboardRangeResult
{
    private ?array $row;

    public function __construct(?array $row)
    {
        $this->row = $row;
    }

    public function row_array(): ?array
    {
        return $this->row;
    }
}

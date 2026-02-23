<?php

namespace Tests\Unit\Migrations;

use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/067_add_dashboard_appointments_indexes.php';

class DashboardAppointmentsIndexMigrationTest extends TestCase
{
    public function testUpSkipsWhenAppointmentsTableMissing(): void
    {
        $db = new FakeMigrationDb(false, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertSame([], $db->queries);
    }

    public function testUpAddsMissingIndexes(): void
    {
        $db = new FakeMigrationDb(true, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $alterQueries = $db->alterQueries();

        $this->assertSame(
            [
                'ALTER TABLE `ea_appointments` ADD INDEX `idx_appointments_dashboard_heatmap_start` (`is_unavailability`, `start_datetime`)',
                'ALTER TABLE `ea_appointments` ADD INDEX `idx_appointments_dashboard_provider_overlap` (`id_users_provider`, `is_unavailability`, `end_datetime`, `start_datetime`)',
            ],
            $alterQueries,
        );
    }

    public function testUpSkipsExistingIndexes(): void
    {
        $db = new FakeMigrationDb(true, 'ea_');
        $db->indexRows = [
            'idx_appointments_dashboard_heatmap_start' => 1,
            'idx_appointments_dashboard_provider_overlap' => 1,
        ];
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertSame([], $db->alterQueries());
    }

    public function testDownDropsExistingIndexes(): void
    {
        $db = new FakeMigrationDb(true, 'ea_');
        $db->indexRows = [
            'idx_appointments_dashboard_heatmap_start' => 1,
            'idx_appointments_dashboard_provider_overlap' => 1,
        ];
        $migration = $this->createMigration($db);

        $migration->down();

        $alterQueries = $db->alterQueries();

        $this->assertSame(
            [
                'ALTER TABLE `ea_appointments` DROP INDEX `idx_appointments_dashboard_provider_overlap`',
                'ALTER TABLE `ea_appointments` DROP INDEX `idx_appointments_dashboard_heatmap_start`',
            ],
            $alterQueries,
        );
    }

    private function createMigration(FakeMigrationDb $db): object
    {
        $reflection = new ReflectionClass('Migration_Add_dashboard_appointments_indexes');
        $migration = $reflection->newInstanceWithoutConstructor();
        $migration->db = $db;

        return $migration;
    }
}

class FakeMigrationDb
{
    public bool $tableExists;

    public string $prefix;

    public array $queries = [];

    public array $indexRows = [];

    public function __construct(bool $tableExists, string $prefix)
    {
        $this->tableExists = $tableExists;
        $this->prefix = $prefix;
    }

    public function table_exists(string $table): bool
    {
        return $this->tableExists;
    }

    public function dbprefix(string $table): string
    {
        return $this->prefix . $table;
    }

    public function escape(string $value): string
    {
        return "'" . $value . "'";
    }

    public function query(string $sql): FakeMigrationResult
    {
        $this->queries[] = $sql;

        if (str_starts_with($sql, 'SHOW INDEX')) {
            $index = $this->extractIndexName($sql);
            $rows = $this->indexRows[$index] ?? 0;

            return new FakeMigrationResult($rows);
        }

        return new FakeMigrationResult(0);
    }

    public function alterQueries(): array
    {
        return array_values(
            array_filter($this->queries, static fn(string $query): bool => str_starts_with($query, 'ALTER TABLE')),
        );
    }

    private function extractIndexName(string $sql): string
    {
        $matches = [];
        if (preg_match("/Key_name = '([^']+)'/", $sql, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}

class FakeMigrationResult
{
    private int $rows;

    public function __construct(int $rows)
    {
        $this->rows = $rows;
    }

    public function num_rows(): int
    {
        return $this->rows;
    }
}

<?php

namespace Tests\Unit\Migrations;

use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/068_delete_orphaned_buffer_blocks.php';

class DeleteOrphanedBufferBlocksMigrationTest extends TestCase
{
    public function testUpSkipsWhenAppointmentsTableMissing(): void
    {
        $db = new FakeOrphanCleanupMigrationDb(false, true, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertSame([], $db->queries);
    }

    public function testUpSkipsWhenParentAppointmentColumnMissing(): void
    {
        $db = new FakeOrphanCleanupMigrationDb(true, false, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertSame([], $db->queries);
    }

    public function testUpDeletesOrphanedBufferBlocks(): void
    {
        $db = new FakeOrphanCleanupMigrationDb(true, true, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertCount(1, $db->queries);
        $this->assertSame(
            'DELETE `buffer_blocks` FROM `ea_appointments` AS `buffer_blocks` ' .
                'LEFT JOIN `ea_appointments` AS `parent_appointments` ' .
                'ON `parent_appointments`.`id` = `buffer_blocks`.`id_parent_appointment` ' .
                'WHERE `buffer_blocks`.`is_unavailability` = 1 ' .
                'AND `buffer_blocks`.`id_parent_appointment` IS NOT NULL ' .
                'AND `parent_appointments`.`id` IS NULL',
            $this->normalizeSql($db->queries[0]),
        );
    }

    public function testDownIsNoOp(): void
    {
        $db = new FakeOrphanCleanupMigrationDb(true, true, 'ea_');
        $migration = $this->createMigration($db);

        $migration->down();

        $this->assertSame([], $db->queries);
    }

    private function createMigration(FakeOrphanCleanupMigrationDb $db): object
    {
        $reflection = new ReflectionClass('Migration_Delete_orphaned_buffer_blocks');
        $migration = $reflection->newInstanceWithoutConstructor();
        $migration->db = $db;

        return $migration;
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?: '';
    }
}

class FakeOrphanCleanupMigrationDb
{
    public bool $appointmentsTableExists;

    public bool $parentColumnExists;

    public string $prefix;

    public array $queries = [];

    public function __construct(bool $appointmentsTableExists, bool $parentColumnExists, string $prefix)
    {
        $this->appointmentsTableExists = $appointmentsTableExists;
        $this->parentColumnExists = $parentColumnExists;
        $this->prefix = $prefix;
    }

    public function table_exists(string $table): bool
    {
        if ($table !== 'appointments') {
            return false;
        }

        return $this->appointmentsTableExists;
    }

    public function field_exists(string $field, string $table): bool
    {
        if ($table !== 'appointments' || $field !== 'id_parent_appointment') {
            return false;
        }

        return $this->parentColumnExists;
    }

    public function dbprefix(string $table): string
    {
        return $this->prefix . $table;
    }

    public function query(string $sql): FakeOrphanCleanupMigrationResult
    {
        $this->queries[] = $sql;

        return new FakeOrphanCleanupMigrationResult();
    }
}

class FakeOrphanCleanupMigrationResult
{
}

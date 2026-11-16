<?php

namespace Tests\Unit\Migrations;

use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/074_add_appointment_hash_index.php';

class AppointmentHashIndexMigrationTest extends TestCase
{
    public function testUpAddsA64CharacterNonUniqueHashIndexAndIsIdempotent(): void
    {
        $db = new FakeAppointmentHashIndexMigrationDb(true, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertSame(
            ['ALTER TABLE `ea_appointments` ADD INDEX `idx_appointments_hash` (`hash`(64))'],
            $db->alterQueries(),
        );
        $this->assertStringNotContainsString('UNIQUE', $db->alterQueries()[0]);

        $migration->up();

        $this->assertCount(1, $db->alterQueries());
    }

    public function testUpSkipsWhenAppointmentsTableIsMissing(): void
    {
        $db = new FakeAppointmentHashIndexMigrationDb(false, 'ea_');

        $this->createMigration($db)->up();

        $this->assertSame([], $db->queries);
    }

    public function testUpFailsClosedForAnExistingWrongDefinition(): void
    {
        $db = new FakeAppointmentHashIndexMigrationDb(true, 'ea_');
        $db->indexRows = [
            'idx_appointments_hash' => [
                'Key_name' => 'idx_appointments_hash',
                'Column_name' => 'hash',
                'Non_unique' => 1,
                'Seq_in_index' => 1,
                'Sub_part' => 32,
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Existing appointment hash index has an unexpected definition.');

        $this->createMigration($db)->up();
    }

    public function testDownDropsOnlyTheExpectedIndexAndIsIdempotent(): void
    {
        $db = new FakeAppointmentHashIndexMigrationDb(true, 'ea_');
        $db->indexRows = ['idx_appointments_hash' => $db->expectedIndexRow()];
        $migration = $this->createMigration($db);

        $migration->down();
        $migration->down();

        $this->assertSame(['ALTER TABLE `ea_appointments` DROP INDEX `idx_appointments_hash`'], $db->alterQueries());
    }

    private function createMigration(FakeAppointmentHashIndexMigrationDb $db): object
    {
        $reflection = new ReflectionClass('Migration_Add_appointment_hash_index');
        $migration = $reflection->newInstanceWithoutConstructor();
        $migration->db = $db;

        return $migration;
    }
}

class FakeAppointmentHashIndexMigrationDb
{
    public array $queries = [];
    public array $indexRows = [];

    public function __construct(private bool $tableExists, private string $prefix) {}

    public function table_exists(string $table): bool
    {
        return $this->tableExists && $table === 'appointments';
    }

    public function dbprefix(string $table): string
    {
        return $this->prefix . $table;
    }

    public function escape(string $value): string
    {
        return "'" . $value . "'";
    }

    public function query(string $sql): FakeAppointmentHashIndexMigrationResult
    {
        $this->queries[] = $sql;

        if (str_starts_with($sql, 'SHOW INDEX')) {
            $index = 'idx_appointments_hash';
            $row = $this->indexRows[$index] ?? null;

            return new FakeAppointmentHashIndexMigrationResult($row === null ? [] : [$row]);
        }

        if (str_contains($sql, 'ADD INDEX')) {
            $this->indexRows['idx_appointments_hash'] = $this->expectedIndexRow();
        }

        if (str_contains($sql, 'DROP INDEX')) {
            unset($this->indexRows['idx_appointments_hash']);
        }

        return new FakeAppointmentHashIndexMigrationResult([]);
    }

    public function expectedIndexRow(): array
    {
        return [
            'Key_name' => 'idx_appointments_hash',
            'Column_name' => 'hash',
            'Non_unique' => 1,
            'Seq_in_index' => 1,
            'Sub_part' => 64,
        ];
    }

    public function alterQueries(): array
    {
        return array_values(
            array_filter($this->queries, static fn(string $query): bool => str_starts_with($query, 'ALTER TABLE')),
        );
    }
}

class FakeAppointmentHashIndexMigrationResult
{
    public function __construct(private array $rows) {}

    public function result_array(): array
    {
        return $this->rows;
    }
}

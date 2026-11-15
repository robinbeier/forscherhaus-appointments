<?php

namespace Tests\Unit\Migrations;

use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/072_add_settings_name_index.php';

class SettingsNameIndexMigrationTest extends TestCase
{
    public function testUpAddsANonUniqueNameIndexAndIsIdempotent(): void
    {
        $db = new FakeSettingsNameIndexMigrationDb(true, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertSame(
            ['ALTER TABLE `ea_settings` ADD INDEX `idx_settings_name` (`name`(191))'],
            $db->alterQueries(),
        );
        $this->assertStringNotContainsString('UNIQUE', $db->alterQueries()[0]);

        $migration->up();

        $this->assertCount(1, $db->alterQueries());
        $this->assertSame(2, count($db->indexLookups));
    }

    public function testUpSkipsWhenSettingsTableIsMissing(): void
    {
        $db = new FakeSettingsNameIndexMigrationDb(false, 'ea_');

        $this->createMigration($db)->up();

        $this->assertSame([], $db->queries);
    }

    public function testUpSkipsAnExistingIndexWithoutChangingItsIdentity(): void
    {
        $db = new FakeSettingsNameIndexMigrationDb(true, 'ea_');
        $db->indexRows = ['idx_settings_name' => $db->expectedIndexRow()];

        $this->createMigration($db)->up();

        $this->assertSame([], $db->alterQueries());
        $this->assertSame(['idx_settings_name'], $db->indexLookups);
    }

    public function testDownDropsOnlyTheNamedIndexAndIsIdempotent(): void
    {
        $db = new FakeSettingsNameIndexMigrationDb(true, 'ea_');
        $db->indexRows = ['idx_settings_name' => $db->expectedIndexRow()];
        $migration = $this->createMigration($db);

        $migration->down();

        $this->assertSame(['ALTER TABLE `ea_settings` DROP INDEX `idx_settings_name`'], $db->alterQueries());

        $db->indexRows = [];
        $migration->down();

        $this->assertCount(1, $db->alterQueries());
    }

    public function testUpFailsClosedAndDownPreservesAnExistingWrongDefinition(): void
    {
        $db = new FakeSettingsNameIndexMigrationDb(true, 'ea_');
        $db->indexRows = [
            'idx_settings_name' => [
                'Key_name' => 'idx_settings_name',
                'Column_name' => 'name',
                'Non_unique' => 1,
                'Seq_in_index' => 1,
                'Sub_part' => 255,
            ],
        ];
        $migration = $this->createMigration($db);

        try {
            $migration->up();
            $this->fail('Expected conflicting index definition to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Existing settings index has an unexpected definition.', $exception->getMessage());
        }

        $migration->down();

        $this->assertSame([], $db->alterQueries());
    }

    private function createMigration(FakeSettingsNameIndexMigrationDb $db): object
    {
        $reflection = new ReflectionClass('Migration_Add_settings_name_index');
        $migration = $reflection->newInstanceWithoutConstructor();
        $migration->db = $db;

        return $migration;
    }
}

class FakeSettingsNameIndexMigrationDb
{
    public array $queries = [];

    public array $indexRows = [];

    public array $indexLookups = [];

    public function __construct(private bool $tableExists, private string $prefix) {}

    public function table_exists(string $table): bool
    {
        return $this->tableExists && $table === 'settings';
    }

    public function dbprefix(string $table): string
    {
        return $this->prefix . $table;
    }

    public function escape(string $value): string
    {
        return "'" . $value . "'";
    }

    public function query(string $sql): FakeSettingsNameIndexMigrationResult
    {
        $this->queries[] = $sql;

        if (str_starts_with($sql, 'SHOW INDEX')) {
            $matches = [];
            preg_match("/Key_name = '([^']+)'/", $sql, $matches);
            $index = $matches[1] ?? '';
            $this->indexLookups[] = $index;

            $row = $this->indexRows[$index] ?? null;

            return new FakeSettingsNameIndexMigrationResult($row === null ? [] : [$row]);
        }

        if (preg_match('/ADD INDEX `([^`]+)`/', $sql, $matches) === 1) {
            $this->indexRows[$matches[1]] = $this->expectedIndexRow();
        }

        if (preg_match('/DROP INDEX `([^`]+)`/', $sql, $matches) === 1) {
            unset($this->indexRows[$matches[1]]);
        }

        return new FakeSettingsNameIndexMigrationResult([]);
    }

    public function expectedIndexRow(): array
    {
        return [
            'Key_name' => 'idx_settings_name',
            'Column_name' => 'name',
            'Non_unique' => 1,
            'Seq_in_index' => 1,
            'Sub_part' => 191,
        ];
    }

    public function alterQueries(): array
    {
        return array_values(
            array_filter($this->queries, static fn(string $query): bool => str_starts_with($query, 'ALTER TABLE')),
        );
    }
}

class FakeSettingsNameIndexMigrationResult
{
    public function __construct(private array $rows) {}

    public function num_rows(): int
    {
        return count($this->rows);
    }

    public function result_array(): array
    {
        return $this->rows;
    }
}

<?php

namespace Tests\Unit\Migrations;

use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/050_add_custom_fields_columns_to_users_table.php';
require_once APPPATH . 'migrations/051_insert_custom_field_rows_to_settings_table.php';

class CustomFieldsRollbackTest extends TestCase
{
    public function testUpAndDownRestoreOnlyOwnedColumnsAndSettings(): void
    {
        $db = new FakeCustomFieldsMigrationDb();
        $columnsMigration = $this->createMigration('Migration_Add_custom_fields_columns_to_users_table', $db, true);
        $settingsMigration = $this->createMigration('Migration_Insert_custom_field_rows_to_settings_table', $db);

        $columnsMigration->up();
        $settingsMigration->up();

        $this->assertEqualsCanonicalizing(
            [
                'id',
                'language',
                'unrelated_column',
                'custom_field_1',
                'custom_field_2',
                'custom_field_3',
                'custom_field_4',
                'custom_field_5',
            ],
            $db->columns,
        );
        $this->assertCount(16, $db->settings);
        $this->assertSame('keep', $db->settings['unrelated_setting']);

        $settingsMigration->down();
        $settingsMigration->down();
        $columnsMigration->down();
        $columnsMigration->down();

        $this->assertSame(['id', 'language', 'unrelated_column'], $db->columns);
        $this->assertSame(['unrelated_setting' => 'keep'], $db->settings);
    }

    private function createMigration(string $class, FakeCustomFieldsMigrationDb $db, bool $withForge = false): object
    {
        $migration = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $migration->db = $db;
        if ($withForge) {
            $migration->dbforge = $db;
        }

        return $migration;
    }
}

final class FakeCustomFieldsMigrationDb
{
    public array $columns = ['id', 'language', 'unrelated_column'];

    public array $settings = ['unrelated_setting' => 'keep'];

    public function field_exists(string $field, string $table): bool
    {
        return $table === 'users' && in_array($field, $this->columns, true);
    }

    public function add_column(string $table, array $fields): void
    {
        foreach (array_keys($fields) as $field) {
            $this->columns[] = $field;
        }
    }

    public function drop_column(string $table, string $field): void
    {
        $this->columns = array_values(
            array_filter($this->columns, static fn(string $column): bool => $column !== $field),
        );
    }

    public function get_where(string $table, array $where): FakeCustomFieldsMigrationResult
    {
        $name = $where['name'];

        return new FakeCustomFieldsMigrationResult(array_key_exists($name, $this->settings));
    }

    public function insert(string $table, array $row): void
    {
        $this->settings[$row['name']] = $row['value'];
    }

    public function delete(string $table, array $where): void
    {
        unset($this->settings[$where['name']]);
    }
}

final class FakeCustomFieldsMigrationResult
{
    public function __construct(private bool $found) {}

    public function num_rows(): int
    {
        return $this->found ? 1 : 0;
    }
}

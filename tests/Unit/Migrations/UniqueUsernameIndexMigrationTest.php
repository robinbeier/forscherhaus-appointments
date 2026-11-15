<?php

namespace Tests\Unit\Migrations;

use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/073_add_unique_username_index.php';

final class UniqueUsernameIndexMigrationTest extends TestCase
{
    public function testUpAddsUniqueUsernameIndexAndIsIdempotent(): void
    {
        $db = new FakeUniqueUsernameMigrationDb();
        $migration = $this->createMigration($db);

        $migration->up();
        $this->assertSame(
            [
                "ALTER TABLE `ea_user_settings` ADD COLUMN `username_unique` VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (NULLIF(`username`, '')) STORED INVISIBLE",
                'ALTER TABLE `ea_user_settings` ADD UNIQUE KEY `uq_user_settings_username` (`username_unique`)',
            ],
            $db->alterQueries,
        );

        $db->indexRows = [$db->expectedIndexRow()];
        $migration->up();
        $this->assertCount(2, $db->alterQueries);
    }

    public function testUpFailsClosedWhenDuplicateUsernamesExist(): void
    {
        $db = new FakeUniqueUsernameMigrationDb();
        $db->duplicateRows = [['username' => 'duplicate']];

        try {
            $this->createMigration($db)->up();
            $this->fail('Expected duplicate usernames to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('duplicate values exist', $exception->getMessage());
        }
        $this->assertSame([], $db->alterQueries);
    }

    public function testDownDropsOnlyTheExpectedUniqueIndex(): void
    {
        $db = new FakeUniqueUsernameMigrationDb();
        $db->indexRows = [$db->expectedIndexRow()];
        $db->generatedColumnExists = true;
        $migration = $this->createMigration($db);

        $migration->down();

        $this->assertSame(
            [
                'ALTER TABLE `ea_user_settings` DROP INDEX `uq_user_settings_username`',
                'ALTER TABLE `ea_user_settings` DROP COLUMN `username_unique`',
            ],
            $db->alterQueries,
        );
    }

    public function testDownFailsClosedWhenIndexRemovalFails(): void
    {
        $db = new FakeUniqueUsernameMigrationDb();
        $db->indexRows = [$db->expectedIndexRow()];
        $db->generatedColumnExists = true;
        $db->failDropIndex = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not remove the username uniqueness index');
        $this->createMigration($db)->down();
    }

    public function testDownLeavesIndexUntouchedWhenGeneratedColumnDefinitionIsUnexpected(): void
    {
        $db = new FakeUniqueUsernameMigrationDb();
        $db->indexRows = [$db->expectedIndexRow()];
        $db->generatedColumnExists = true;
        $db->malformedGeneratedColumn = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected definition');
        try {
            $this->createMigration($db)->down();
        } finally {
            $this->assertSame([], $db->alterQueries);
        }
    }

    public function testUpFailsClosedWhenDuplicateInspectionFails(): void
    {
        $db = new FakeUniqueUsernameMigrationDb();
        $db->failDuplicateInspection = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not inspect existing usernames');
        $this->createMigration($db)->up();
    }

    private function createMigration(FakeUniqueUsernameMigrationDb $db): object
    {
        $migration = (new ReflectionClass('Migration_Add_unique_username_index'))->newInstanceWithoutConstructor();
        $migration->db = $db;

        return $migration;
    }
}

final class FakeUniqueUsernameMigrationDb
{
    public array $alterQueries = [];
    public array $duplicateRows = [];
    public array $indexRows = [];

    public bool $generatedColumnExists = false;
    public bool $failDuplicateInspection = false;
    public bool $failDropIndex = false;
    public bool $malformedGeneratedColumn = false;

    public function table_exists(string $table): bool
    {
        return $table === 'user_settings';
    }

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function field_exists(string $field, string $table): bool
    {
        return $table === 'user_settings' && $field === 'username_unique' && $this->generatedColumnExists;
    }

    public function escape(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }

    public function query(string $sql): mixed
    {
        if (str_starts_with($sql, 'SELECT `DATA_TYPE`')) {
            return new FakeUniqueUsernameQuery(
                $this->generatedColumnExists
                    ? [
                        [
                            'DATA_TYPE' => 'varchar',
                            'CHARACTER_MAXIMUM_LENGTH' => 256,
                            'GENERATION_EXPRESSION' => "nullif(`username`,_utf8mb4'')",
                            'EXTRA' => $this->malformedGeneratedColumn
                                ? 'STORED GENERATED'
                                : 'STORED GENERATED, INVISIBLE',
                            'COLLATION_NAME' => 'utf8mb4_unicode_ci',
                            'SOURCE_COLLATION' => 'utf8mb4_unicode_ci',
                        ],
                    ]
                    : [],
            );
        }
        if (str_starts_with($sql, 'SELECT `username`')) {
            if ($this->failDuplicateInspection) {
                return false;
            }
            return new FakeUniqueUsernameQuery($this->duplicateRows);
        }

        if (str_starts_with($sql, 'SHOW INDEX')) {
            return new FakeUniqueUsernameQuery($this->indexRows);
        }

        $this->alterQueries[] = $sql;
        if (str_contains($sql, 'DROP INDEX') && $this->failDropIndex) {
            return false;
        }
        if (str_contains($sql, 'DROP INDEX')) {
            $this->indexRows = [];
        }
        if (str_contains($sql, 'ADD COLUMN `username_unique`')) {
            $this->generatedColumnExists = true;
        }
        if (str_contains($sql, 'DROP COLUMN `username_unique`')) {
            $this->generatedColumnExists = false;
        }
        if (str_contains($sql, 'ADD UNIQUE KEY `uq_user_settings_username`')) {
            $this->indexRows = [$this->expectedIndexRow()];
        }
        return new FakeUniqueUsernameQuery([]);
    }

    public function expectedIndexRow(): array
    {
        return [
            'Key_name' => 'uq_user_settings_username',
            'Column_name' => 'username_unique',
            'Non_unique' => 0,
            'Seq_in_index' => 1,
            'Sub_part' => 0,
        ];
    }
}

final class FakeUniqueUsernameQuery
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

    public function row_array(): array
    {
        return $this->rows[0] ?? [];
    }
}

<?php

namespace Tests\Unit\Migrations;

use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/069_create_reschedule_authorities_table.php';

class RescheduleAuthoritiesMigrationTest extends TestCase
{
    public function testUpCreatesBoundedAuthorityTableWithIndexesAndForeignKey(): void
    {
        $db = new FakeRescheduleAuthoritiesMigrationDb(false, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertSame('INT', $db->forge->fields['id']['type']);
        $this->assertTrue($db->forge->fields['id']['auto_increment']);
        $this->assertSame('INT', $db->forge->fields['appointment_id']['type']);
        $this->assertSame(11, $db->forge->fields['appointment_id']['constraint']);
        $this->assertSame('INT', $db->forge->fields['customer_id']['type']);
        $this->assertSame(11, $db->forge->fields['customer_id']['constraint']);
        foreach (['token_digest', 'context_digest', 'snapshot_digest'] as $digest) {
            $this->assertSame('CHAR', $db->forge->fields[$digest]['type']);
            $this->assertSame(64, $db->forge->fields[$digest]['constraint']);
        }
        $this->assertSame('DATETIME', $db->forge->fields['expires_at']['type']);
        $this->assertTrue($db->forge->fields['consumed_at']['null']);
        $this->assertTrue($db->forge->fields['create_datetime']['null']);
        $this->assertTrue($db->forge->fields['update_datetime']['null']);
        $this->assertSame(['id'], $db->forge->primaryKeys);
        $this->assertSame('reschedule_authorities', $db->forge->createdTable);
        $this->assertSame(['engine' => 'InnoDB'], $db->forge->createdOptions);
        $this->assertSame(
            'ALTER TABLE `ea_reschedule_authorities` ' .
                'ADD UNIQUE KEY `uq_reschedule_authorities_token_digest` (`token_digest`), ' .
                'ADD UNIQUE KEY `uq_reschedule_authorities_appointment_id` (`appointment_id`), ' .
                'ADD CONSTRAINT `fk_reschedule_authorities_appointment_id` FOREIGN KEY (`appointment_id`) ' .
                'REFERENCES `ea_appointments` (`id`) ON DELETE CASCADE',
            $db->queries[0],
        );
    }

    public function testUpIsIdempotentWhenTableAlreadyExists(): void
    {
        $db = new FakeRescheduleAuthoritiesMigrationDb(true, 'ea_');
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('information_schema', $db->queries[0]);
        $this->assertNull($db->forge->createdTable);
    }

    public function testUpRepairsIncompleteSchemaWithoutDroppingAuthorityTable(): void
    {
        $db = new FakeRescheduleAuthoritiesMigrationDb(true, 'ea_', false);
        $migration = $this->createMigration($db);

        $migration->up();

        $this->assertNull($db->forge->createdTable);
        $this->assertSame([], $db->forge->droppedTables);
        $this->assertCount(4, $db->queries);
        $this->assertStringContainsString('information_schema', $db->queries[0]);
        $this->assertStringContainsString('uq_reschedule_authorities_token_digest', $db->queries[1]);
        $this->assertStringContainsString('uq_reschedule_authorities_appointment_id', $db->queries[2]);
        $this->assertStringContainsString('fk_reschedule_authorities_appointment_id', $db->queries[3]);
    }

    public function testDownDropsExistingTableAndSkipsMissingTable(): void
    {
        $db = new FakeRescheduleAuthoritiesMigrationDb(true, 'ea_');
        $migration = $this->createMigration($db);

        $migration->down();

        $this->assertSame(['reschedule_authorities'], $db->forge->droppedTables);

        $missingDb = new FakeRescheduleAuthoritiesMigrationDb(false, 'ea_');
        $this->createMigration($missingDb)->down();
        $this->assertSame([], $missingDb->forge->droppedTables);
    }

    private function createMigration(FakeRescheduleAuthoritiesMigrationDb $db): object
    {
        $reflection = new ReflectionClass('Migration_Create_reschedule_authorities_table');
        $migration = $reflection->newInstanceWithoutConstructor();
        $migration->db = $db;
        $migration->dbforge = $db->forge;

        return $migration;
    }
}

class FakeRescheduleAuthoritiesMigrationDb
{
    public FakeRescheduleAuthoritiesMigrationForge $forge;

    public array $queries = [];

    public function __construct(private bool $tableExists, private string $prefix, private bool $schemaComplete = true)
    {
        $this->forge = new FakeRescheduleAuthoritiesMigrationForge();
    }

    public function table_exists(string $table): bool
    {
        return $this->tableExists && $table === 'reschedule_authorities';
    }

    public function dbprefix(string $table): string
    {
        return $this->prefix . $table;
    }

    public function query(string $sql, array $bindings = []): object
    {
        $this->queries[] = $sql;

        if (str_contains($sql, 'information_schema')) {
            return new FakeRescheduleAuthoritiesMigrationQuery([
                'token_index_exists' => $this->schemaComplete ? 1 : 0,
                'appointment_index_exists' => $this->schemaComplete ? 1 : 0,
                'foreign_key_exists' => $this->schemaComplete ? 1 : 0,
            ]);
        }

        return new FakeRescheduleAuthoritiesMigrationQuery([]);
    }
}

class FakeRescheduleAuthoritiesMigrationQuery
{
    public function __construct(private array $row) {}

    public function row_array(): array
    {
        return $this->row;
    }
}

class FakeRescheduleAuthoritiesMigrationForge
{
    public array $fields = [];

    public array $primaryKeys = [];

    public ?string $createdTable = null;

    public array $createdOptions = [];

    public array $droppedTables = [];

    public function add_field(array $fields): void
    {
        $this->fields = $fields;
    }

    public function add_key(string $key, bool $primary = false): void
    {
        if ($primary) {
            $this->primaryKeys[] = $key;
        }
    }

    public function create_table(string $table, bool $if_not_exists, array $options): void
    {
        $this->createdTable = $table;
        $this->createdOptions = $options;
    }

    public function drop_table(string $table): void
    {
        $this->droppedTables[] = $table;
    }
}

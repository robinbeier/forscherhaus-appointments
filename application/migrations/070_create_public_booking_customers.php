<?php defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Create_public_booking_customers extends EA_Migration
{
    public function up(): void
    {
        if (!$this->db->table_exists('public_booking_customers')) {
            $this->dbforge->add_field([
                'customer_id' => ['type' => 'INT', 'constraint' => 11],
                'fingerprint' => ['type' => 'CHAR', 'constraint' => 64],
                'create_datetime' => ['type' => 'DATETIME', 'null' => true],
                'update_datetime' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->dbforge->add_key('customer_id', true);
            $this->dbforge->create_table('public_booking_customers', true, ['engine' => 'InnoDB']);
        }

        $markers = $this->db->dbprefix('public_booking_customers');
        $users = $this->db->dbprefix('users');
        $this->ensureForeignKey($markers, 'fk_public_booking_customers_customer', $users, 'customer_id', 'id');

        if (!$this->column_exists('consents', 'customer_id')) {
            $this->dbforge->add_column('consents', [
                'customer_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            ]);
        }
        $consents = $this->db->dbprefix('consents');
        $this->ensureForeignKey($consents, 'fk_consents_customer', $users, 'customer_id', 'id');
    }

    public function down(): void
    {
        if ($this->db->table_exists('consents') && $this->column_exists('consents', 'customer_id')) {
            $consents = $this->db->dbprefix('consents');
            $this->dropForeignKey($consents, 'fk_consents_customer');
            $this->dbforge->drop_column('consents', 'customer_id');
        }
        if ($this->db->table_exists('public_booking_customers')) {
            $this->dropForeignKey(
                $this->db->dbprefix('public_booking_customers'),
                'fk_public_booking_customers_customer',
            );
            $this->dbforge->drop_table('public_booking_customers');
        }
    }

    private function column_exists(string $table, string $column): bool
    {
        return $this->db->field_exists($column, $table);
    }

    private function ensureForeignKey(
        string $table,
        string $name,
        string $parent,
        string $column,
        string $parentColumn,
    ): void {
        $exists =
            $this->db
                ->query(
                    'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?',
                    [$table, $name],
                )
                ->num_rows() > 0;
        if (!$exists) {
            $this->db->query(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`{$parentColumn}`) ON DELETE CASCADE",
            );
        }
    }

    private function dropForeignKey(string $table, string $name): void
    {
        $exists =
            $this->db
                ->query(
                    'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?',
                    [$table, $name],
                )
                ->num_rows() > 0;
        if ($exists) {
            $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }
    }
}

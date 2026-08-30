<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * ---------------------------------------------------------------------------- */

class Migration_Create_reschedule_authorities_table extends EA_Migration
{
    private const TABLE = 'reschedule_authorities';

    private const APPOINTMENTS_TABLE = 'appointments';

    private const FOREIGN_KEY = 'fk_reschedule_authorities_appointment_id';

    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if ($this->db->table_exists(self::TABLE)) {
            $this->repairIncompleteSchema();

            return;
        }

        $this->dbforge->add_field([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'auto_increment' => true,
            ],
            'appointment_id' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'customer_id' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'token_digest' => [
                'type' => 'CHAR',
                'constraint' => 64,
            ],
            'context_digest' => [
                'type' => 'CHAR',
                'constraint' => 64,
            ],
            'snapshot_digest' => [
                'type' => 'CHAR',
                'constraint' => 64,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'consumed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'create_datetime' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'update_datetime' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->dbforge->add_key('id', true);
        $this->dbforge->create_table(self::TABLE, true, ['engine' => 'InnoDB']);

        $table = $this->db->dbprefix(self::TABLE);
        $appointments_table = $this->db->dbprefix(self::APPOINTMENTS_TABLE);

        $this->db->query(
            'ALTER TABLE `' .
                $table .
                '` ' .
                'ADD UNIQUE KEY `uq_reschedule_authorities_token_digest` (`token_digest`), ' .
                'ADD UNIQUE KEY `uq_reschedule_authorities_appointment_id` (`appointment_id`), ' .
                'ADD CONSTRAINT `' .
                self::FOREIGN_KEY .
                '` FOREIGN KEY (`appointment_id`) ' .
                'REFERENCES `' .
                $appointments_table .
                '` (`id`) ON DELETE CASCADE',
        );
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        if ($this->db->table_exists(self::TABLE)) {
            $this->dbforge->drop_table(self::TABLE);
        }
    }

    /**
     * MySQL DDL auto-commits. Safely finish a prior run that created the table
     * but stopped before all indexes or the foreign key were installed.
     */
    private function repairIncompleteSchema(): void
    {
        $table = $this->db->dbprefix(self::TABLE);
        $appointments_table = $this->db->dbprefix(self::APPOINTMENTS_TABLE);
        $state = $this->db
            ->query(
                'SELECT ' .
                    'EXISTS(SELECT 1 FROM `information_schema`.`STATISTICS` ' .
                    'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `INDEX_NAME` = ?) ' .
                    'AS `token_index_exists`, ' .
                    'EXISTS(SELECT 1 FROM `information_schema`.`STATISTICS` ' .
                    'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `INDEX_NAME` = ?) ' .
                    'AS `appointment_index_exists`, ' .
                    'EXISTS(SELECT 1 FROM `information_schema`.`TABLE_CONSTRAINTS` ' .
                    'WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? ' .
                    'AND `CONSTRAINT_NAME` = ? AND `CONSTRAINT_TYPE` = ?) AS `foreign_key_exists`',
                [
                    $table,
                    'uq_reschedule_authorities_token_digest',
                    $table,
                    'uq_reschedule_authorities_appointment_id',
                    $table,
                    self::FOREIGN_KEY,
                    'FOREIGN KEY',
                ],
            )
            ->row_array();

        if (empty($state['token_index_exists'])) {
            $this->db->query(
                'ALTER TABLE `' . $table . '` ADD UNIQUE KEY `uq_reschedule_authorities_token_digest` (`token_digest`)',
            );
        }

        if (empty($state['appointment_index_exists'])) {
            $this->db->query(
                'ALTER TABLE `' .
                    $table .
                    '` ADD UNIQUE KEY `uq_reschedule_authorities_appointment_id` (`appointment_id`)',
            );
        }

        if (empty($state['foreign_key_exists'])) {
            $this->db->query(
                'ALTER TABLE `' .
                    $table .
                    '` ADD CONSTRAINT `' .
                    self::FOREIGN_KEY .
                    '` FOREIGN KEY (`appointment_id`) REFERENCES `' .
                    $appointments_table .
                    '` (`id`) ON DELETE CASCADE',
            );
        }
    }
}

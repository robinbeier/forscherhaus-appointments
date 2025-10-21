<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.6.0
 * ---------------------------------------------------------------------------- */

class Migration_Add_service_buffers_and_buffer_links extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if (!$this->db->field_exists('buffer_before', 'services')) {
            $this->dbforge->add_column('services', [
                'buffer_before' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'null' => false,
                    'default' => 0,
                    'after' => 'duration',
                ],
            ]);
        }

        if (!$this->db->field_exists('buffer_after', 'services')) {
            $this->dbforge->add_column('services', [
                'buffer_after' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'null' => false,
                    'default' => 0,
                    'after' => 'buffer_before',
                ],
            ]);
        }

        if (!$this->db->field_exists('id_parent_appointment', 'appointments')) {
            $this->dbforge->add_column('appointments', [
                'id_parent_appointment' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'id_services',
                ],
            ]);

            $this->db->query(
                'ALTER TABLE `' .
                $this->db->dbprefix('appointments') .
                '` ADD INDEX `idx_parent_appointment` (`id_parent_appointment`)',
            );
        }
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        if ($this->db->field_exists('buffer_before', 'services')) {
            $this->dbforge->drop_column('services', 'buffer_before');
        }

        if ($this->db->field_exists('buffer_after', 'services')) {
            $this->dbforge->drop_column('services', 'buffer_after');
        }

        if ($this->db->field_exists('id_parent_appointment', 'appointments')) {
            $this->dbforge->drop_column('appointments', 'id_parent_appointment');
        }
    }
}

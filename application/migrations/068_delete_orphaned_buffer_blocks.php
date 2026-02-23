<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.8.0
 * ---------------------------------------------------------------------------- */

class Migration_Delete_orphaned_buffer_blocks extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if (!$this->db->table_exists('appointments')) {
            return;
        }

        if (!$this->db->field_exists('id_parent_appointment', 'appointments')) {
            return;
        }

        $appointments_table = $this->db->dbprefix('appointments');

        $this->db->query(
            'DELETE `buffer_blocks`
            FROM `' .
                $appointments_table .
                '` AS `buffer_blocks`
            LEFT JOIN `' .
                $appointments_table .
                '` AS `parent_appointments`
                ON `parent_appointments`.`id` = `buffer_blocks`.`id_parent_appointment`
            WHERE `buffer_blocks`.`is_unavailability` = 1
                AND `buffer_blocks`.`id_parent_appointment` IS NOT NULL
                AND `parent_appointments`.`id` IS NULL',
        );
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        // Intentionally left as a no-op because deleted orphan rows cannot be restored.
    }
}

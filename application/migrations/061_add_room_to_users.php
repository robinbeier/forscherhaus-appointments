<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.4.0
 * ---------------------------------------------------------------------------- */

class Migration_Add_room_to_users extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        $table = $this->db->dbprefix('users');

        if (!$this->db->field_exists('room', $table)) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `room` VARCHAR(64) NULL AFTER `notes`;");
        }
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        $table = $this->db->dbprefix('users');

        if ($this->db->field_exists('room', $table)) {
            $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `room`;");
        }
    }
}

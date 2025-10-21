<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

class Migration_Add_class_size_default_to_users extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        $table = $this->db->dbprefix('users');

        if (!$this->db->field_exists('class_size_default', $table)) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `class_size_default` INT NULL AFTER `room`;");
        }
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        $table = $this->db->dbprefix('users');

        if ($this->db->field_exists('class_size_default', $table)) {
            $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `class_size_default`;");
        }
    }
}

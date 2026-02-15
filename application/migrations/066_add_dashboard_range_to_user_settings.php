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

class Migration_Add_dashboard_range_to_user_settings extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if (!$this->db->field_exists('dashboard_range_start', 'user_settings')) {
            $this->dbforge->add_column('user_settings', [
                'dashboard_range_start' => [
                    'type' => 'DATE',
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }

        if (!$this->db->field_exists('dashboard_range_end', 'user_settings')) {
            $this->dbforge->add_column('user_settings', [
                'dashboard_range_end' => [
                    'type' => 'DATE',
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        if ($this->db->field_exists('dashboard_range_start', 'user_settings')) {
            $this->dbforge->drop_column('user_settings', 'dashboard_range_start');
        }

        if ($this->db->field_exists('dashboard_range_end', 'user_settings')) {
            $this->dbforge->drop_column('user_settings', 'dashboard_range_end');
        }
    }
}

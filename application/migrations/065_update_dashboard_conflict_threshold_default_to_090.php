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

class Migration_Update_dashboard_conflict_threshold_default_to_090 extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        $query = $this->db->get_where('settings', ['name' => 'dashboard_conflict_threshold']);

        if (!$query->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'dashboard_conflict_threshold',
                'value' => '0.90',
            ]);

            return;
        }

        $row = $query->row_array();
        $value = $row['value'] ?? null;

        if (is_numeric($value) && abs((float) $value - 0.75) < 0.000001) {
            $this->db->update('settings', ['value' => '0.90'], ['name' => 'dashboard_conflict_threshold']);
        }
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        $query = $this->db->get_where('settings', ['name' => 'dashboard_conflict_threshold']);

        if (!$query->num_rows()) {
            return;
        }

        $row = $query->row_array();
        $value = $row['value'] ?? null;

        if (is_numeric($value) && abs((float) $value - 0.9) < 0.000001) {
            $this->db->update('settings', ['value' => '0.75'], ['name' => 'dashboard_conflict_threshold']);
        }
    }
}

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

class Migration_Add_dashboard_appointments_indexes extends EA_Migration
{
    protected const TABLE_APPOINTMENTS = 'appointments';

    protected const INDEX_DASHBOARD_HEATMAP_START = 'idx_appointments_dashboard_heatmap_start';

    protected const INDEX_DASHBOARD_PROVIDER_OVERLAP = 'idx_appointments_dashboard_provider_overlap';

    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if (!$this->db->table_exists(self::TABLE_APPOINTMENTS)) {
            return;
        }

        $this->addIndexIfMissing(self::INDEX_DASHBOARD_HEATMAP_START, ['is_unavailability', 'start_datetime']);

        $this->addIndexIfMissing(self::INDEX_DASHBOARD_PROVIDER_OVERLAP, [
            'id_users_provider',
            'is_unavailability',
            'end_datetime',
            'start_datetime',
        ]);
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        if (!$this->db->table_exists(self::TABLE_APPOINTMENTS)) {
            return;
        }

        $this->dropIndexIfExists(self::INDEX_DASHBOARD_PROVIDER_OVERLAP);
        $this->dropIndexIfExists(self::INDEX_DASHBOARD_HEATMAP_START);
    }

    protected function addIndexIfMissing(string $index_name, array $columns): void
    {
        if (empty($columns) || $this->indexExists($index_name)) {
            return;
        }

        $quoted_columns = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns));

        $this->db->query(
            'ALTER TABLE `' .
                $this->appointmentsTable() .
                '` ADD INDEX `' .
                $index_name .
                '` (' .
                $quoted_columns .
                ')',
        );
    }

    protected function dropIndexIfExists(string $index_name): void
    {
        if (!$this->indexExists($index_name)) {
            return;
        }

        $this->db->query('ALTER TABLE `' . $this->appointmentsTable() . '` DROP INDEX `' . $index_name . '`');
    }

    protected function indexExists(string $index_name): bool
    {
        $query = $this->db->query(
            'SHOW INDEX FROM `' . $this->appointmentsTable() . '` WHERE Key_name = ' . $this->db->escape($index_name),
        );

        return $query->num_rows() > 0;
    }

    protected function appointmentsTable(): string
    {
        return $this->db->dbprefix(self::TABLE_APPOINTMENTS);
    }
}

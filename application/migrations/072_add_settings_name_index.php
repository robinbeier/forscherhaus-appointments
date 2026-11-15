<?php defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_settings_name_index extends EA_Migration
{
    protected const TABLE_SETTINGS = 'settings';

    protected const INDEX_SETTINGS_NAME = 'idx_settings_name';

    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if (!$this->db->table_exists(self::TABLE_SETTINGS)) {
            return;
        }

        $indexRows = $this->indexRows();
        if ($indexRows !== []) {
            if (!$this->isExpectedIndex($indexRows)) {
                throw new RuntimeException('Existing settings index has an unexpected definition.');
            }

            return;
        }

        $this->db->query(
            'ALTER TABLE `' . $this->settingsTable() . '` ADD INDEX `' . self::INDEX_SETTINGS_NAME . '` (`name`(191))',
        );
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        if (!$this->db->table_exists(self::TABLE_SETTINGS) || !$this->isExpectedIndex($this->indexRows())) {
            return;
        }

        $this->db->query('ALTER TABLE `' . $this->settingsTable() . '` DROP INDEX `' . self::INDEX_SETTINGS_NAME . '`');
    }

    protected function indexRows(): array
    {
        $query = $this->db->query(
            'SHOW INDEX FROM `' .
                $this->settingsTable() .
                '` WHERE Key_name = ' .
                $this->db->escape(self::INDEX_SETTINGS_NAME),
        );

        return $query->result_array();
    }

    protected function isExpectedIndex(array $rows): bool
    {
        if (count($rows) !== 1) {
            return false;
        }

        $row = $rows[0];

        return (string) ($row['Key_name'] ?? '') === self::INDEX_SETTINGS_NAME &&
            (string) ($row['Column_name'] ?? '') === 'name' &&
            (int) ($row['Non_unique'] ?? 0) === 1 &&
            (int) ($row['Seq_in_index'] ?? 0) === 1 &&
            (int) ($row['Sub_part'] ?? 0) === 191;
    }

    protected function settingsTable(): string
    {
        return $this->db->dbprefix(self::TABLE_SETTINGS);
    }
}

<?php defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_appointment_hash_index extends EA_Migration
{
    private const TABLE = 'appointments';
    private const INDEX = 'idx_appointments_hash';
    private const PREFIX_LENGTH = 64;

    public function up(): void
    {
        if (!$this->db->table_exists(self::TABLE)) {
            return;
        }

        $rows = $this->indexRows();
        if ($rows !== []) {
            if (!$this->isExpectedIndex($rows)) {
                throw new RuntimeException('Existing appointment hash index has an unexpected definition.');
            }

            return;
        }

        $result = $this->db->query(
            'ALTER TABLE `' .
                $this->appointmentsTable() .
                '` ADD INDEX `' .
                self::INDEX .
                '` (`hash`(' .
                self::PREFIX_LENGTH .
                '))',
        );
        if ($result === false || !$this->isExpectedIndex($this->indexRows())) {
            throw new RuntimeException('Could not verify the appointment hash index.');
        }
    }

    public function down(): void
    {
        if (!$this->db->table_exists(self::TABLE)) {
            return;
        }

        $rows = $this->indexRows();
        if ($rows === []) {
            return;
        }
        if (!$this->isExpectedIndex($rows)) {
            return;
        }

        $result = $this->db->query('ALTER TABLE `' . $this->appointmentsTable() . '` DROP INDEX `' . self::INDEX . '`');
        if ($result === false || $this->indexRows() !== []) {
            throw new RuntimeException('Could not remove the appointment hash index.');
        }
    }

    private function indexRows(): array
    {
        $query = $this->db->query(
            'SHOW INDEX FROM `' . $this->appointmentsTable() . '` WHERE Key_name = ' . $this->db->escape(self::INDEX),
        );
        if ($query === false) {
            throw new RuntimeException('Could not inspect the appointment hash index.');
        }

        return $query->result_array();
    }

    private function isExpectedIndex(array $rows): bool
    {
        if (count($rows) !== 1) {
            return false;
        }

        $row = $rows[0];

        return (string) ($row['Key_name'] ?? '') === self::INDEX &&
            (string) ($row['Column_name'] ?? '') === 'hash' &&
            (int) ($row['Non_unique'] ?? 0) === 1 &&
            (int) ($row['Seq_in_index'] ?? 0) === 1 &&
            (int) ($row['Sub_part'] ?? 0) === self::PREFIX_LENGTH;
    }

    private function appointmentsTable(): string
    {
        return $this->db->dbprefix(self::TABLE);
    }
}

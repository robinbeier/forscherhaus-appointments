<?php defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_unique_username_index extends EA_Migration
{
    private const TABLE = 'user_settings';
    private const INDEX = 'uq_user_settings_username';
    private const GENERATED_COLUMN = 'username_unique';

    public function up(): void
    {
        if (!$this->db->table_exists(self::TABLE)) {
            return;
        }

        $this->assertSupportedServer();

        $duplicates = $this->db->query(
            'SELECT `username` FROM `' .
                $this->db->dbprefix(self::TABLE) .
                '` WHERE `username` IS NOT NULL AND `username` <> \'\' GROUP BY `username` HAVING COUNT(*) > 1 LIMIT 1',
        );
        if ($duplicates === false) {
            throw new RuntimeException('Could not inspect existing usernames.');
        }
        if ($duplicates->num_rows() > 0) {
            throw new RuntimeException('Cannot enforce unique usernames while duplicate values exist.');
        }

        if ($this->generatedColumnMetadata() === null) {
            $result = $this->db->query(
                'ALTER TABLE `' .
                    $this->db->dbprefix(self::TABLE) .
                    '` ADD COLUMN `' .
                    self::GENERATED_COLUMN .
                    '` VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (NULLIF(`username`, \'\')) STORED INVISIBLE',
            );
            if ($result === false || !$this->isExpectedGeneratedColumn()) {
                throw new RuntimeException('Could not create the username uniqueness column.');
            }
        }
        if (!$this->isExpectedGeneratedColumn()) {
            throw new RuntimeException('Existing username uniqueness column has an unexpected definition.');
        }

        $rows = $this->indexRows();
        if ($rows !== []) {
            if (!$this->isExpectedIndex($rows)) {
                throw new RuntimeException('Existing username index has an unexpected definition.');
            }
            return;
        }

        $result = $this->db->query(
            'ALTER TABLE `' .
                $this->db->dbprefix(self::TABLE) .
                '` ADD UNIQUE KEY `' .
                self::INDEX .
                '` (`' .
                self::GENERATED_COLUMN .
                '`)',
        );
        if ($result === false || !$this->isExpectedIndex($this->indexRows())) {
            throw new RuntimeException('Could not verify the username uniqueness index.');
        }
    }

    public function down(): void
    {
        if (!$this->db->table_exists(self::TABLE)) {
            return;
        }

        $this->assertSupportedServer();
        $rows = $this->indexRows();
        if ($rows !== [] && !$this->isExpectedIndex($rows)) {
            return;
        }
        $columnMetadata = $this->generatedColumnMetadata();
        if ($rows !== [] && $columnMetadata === null) {
            throw new RuntimeException('Username uniqueness index is missing its generated column.');
        }
        if ($columnMetadata !== null && !$this->isExpectedGeneratedColumn()) {
            throw new RuntimeException('Existing username uniqueness column has an unexpected definition.');
        }
        if ($rows !== []) {
            $result = $this->db->query(
                'ALTER TABLE `' . $this->db->dbprefix(self::TABLE) . '` DROP INDEX `' . self::INDEX . '`',
            );
            if ($result === false || $this->indexRows() !== []) {
                throw new RuntimeException('Could not remove the username uniqueness index.');
            }
        }
        if ($this->generatedColumnMetadata() !== null) {
            $result = $this->db->query(
                'ALTER TABLE `' . $this->db->dbprefix(self::TABLE) . '` DROP COLUMN `' . self::GENERATED_COLUMN . '`',
            );
            if ($result === false || $this->generatedColumnMetadata() !== null) {
                throw new RuntimeException('Could not remove the username uniqueness column.');
            }
        }
    }

    private function indexRows(): array
    {
        $query = $this->db->query(
            'SHOW INDEX FROM `' .
                $this->db->dbprefix(self::TABLE) .
                '` WHERE Key_name = ' .
                $this->db->escape(self::INDEX),
        );
        if ($query === false) {
            throw new RuntimeException('Could not inspect the username index.');
        }
        return $query->result_array();
    }

    private function assertSupportedServer(): void
    {
        $query = $this->db->query('SELECT VERSION() AS server_version');
        $version = $query === false ? '' : (string) ($query->row_array()['server_version'] ?? '');
        $isMariaDb = stripos($version, 'mariadb') !== false;
        if ($isMariaDb && str_starts_with($version, '5.5.5-')) {
            $version = substr($version, 6);
        }
        if (!preg_match('/\A(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            throw new RuntimeException('Could not verify the database version for username uniqueness.');
        }

        $serverVersion = $matches[1] . '.' . $matches[2] . '.' . $matches[3];
        $minimum = $isMariaDb ? '11.8.0' : '8.4.0';
        if (version_compare($serverVersion, $minimum, '<')) {
            throw new RuntimeException(
                'Username uniqueness requires MySQL 8.4+ or MariaDB 11.8+; upgrade the database before migration 073.',
            );
        }
    }

    private function isExpectedGeneratedColumn(): bool
    {
        $row = $this->generatedColumnMetadata();
        if ($row === null) {
            return false;
        }
        $expression = strtolower((string) ($row['GENERATION_EXPRESSION'] ?? ''));
        // MySQL 8.4 emits _utf8mb4; MariaDB may emit _latin1 or no introducer.
        // Accept only these observed charset introducers, then compare the
        // normalized expression exactly.
        $expression = str_replace(['\\', '_latin1', '_utf8mb4'], '', $expression);
        $expression = preg_replace('/\s+/', '', $expression);
        return ($row['DATA_TYPE'] ?? '') === 'varchar' &&
            (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0) === 256 &&
            in_array($row['EXTRA'] ?? '', ['STORED GENERATED, INVISIBLE', 'STORED GENERATED INVISIBLE'], true) &&
            !empty($row['COLLATION_NAME']) &&
            ($row['COLLATION_NAME'] ?? '') === ($row['SOURCE_COLLATION'] ?? '') &&
            $expression === "nullif(`username`,'')";
    }

    private function generatedColumnMetadata(): ?array
    {
        $query = $this->db->query(
            'SELECT `DATA_TYPE`, `CHARACTER_MAXIMUM_LENGTH`, `GENERATION_EXPRESSION`, `EXTRA`, `COLLATION_NAME`, ' .
                '(SELECT `COLLATION_NAME` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ' .
                $this->db->escape($this->db->dbprefix(self::TABLE)) .
                ' AND `COLUMN_NAME` = \'username\') AS `SOURCE_COLLATION` ' .
                'FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ' .
                $this->db->escape($this->db->dbprefix(self::TABLE)) .
                ' AND `COLUMN_NAME` = ' .
                $this->db->escape(self::GENERATED_COLUMN),
        );
        if ($query === false) {
            throw new RuntimeException('Could not inspect the username uniqueness column.');
        }
        $row = $query->row_array();
        return $row === [] ? null : $row;
    }

    private function isExpectedIndex(array $rows): bool
    {
        if (count($rows) !== 1) {
            return false;
        }
        $row = $rows[0];
        return ($row['Key_name'] ?? '') === self::INDEX &&
            ($row['Column_name'] ?? '') === self::GENERATED_COLUMN &&
            (int) ($row['Non_unique'] ?? 1) === 0 &&
            (int) ($row['Seq_in_index'] ?? 0) === 1 &&
            (int) ($row['Sub_part'] ?? 0) === 0;
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'core/EA_Migration.php';
require_once APPPATH . 'migrations/074_add_appointment_hash_index.php';

final class AppointmentHashIndexDatabaseTest extends TestCase
{
    public function testRealDatabaseCreatesAndRollsBackPrefixIndexOnTemporaryAppointmentsTable(): void
    {
        $db = get_instance()->db;
        $table = $db->dbprefix('appointments');
        self::assertTrue(
            (bool) $db->query(
                'CREATE TEMPORARY TABLE `' .
                    $table .
                    '` (`id` INT NOT NULL PRIMARY KEY, `hash` TEXT NULL) ENGINE=InnoDB',
            ),
        );

        try {
            $migration = (new ReflectionClass(
                Migration_Add_appointment_hash_index::class,
            ))->newInstanceWithoutConstructor();
            $migration->db = $db;

            $migration->up();
            $rows = $db
                ->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $db->escape('idx_appointments_hash'))
                ->result_array();
            self::assertCount(1, $rows);
            self::assertSame('hash', $rows[0]['Column_name']);
            self::assertSame(1, (int) $rows[0]['Non_unique']);
            self::assertSame(64, (int) $rows[0]['Sub_part']);

            $migration->down();
            self::assertSame(
                [],
                $db
                    ->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $db->escape('idx_appointments_hash'))
                    ->result_array(),
            );
        } finally {
            $db->query('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
        }
    }
}

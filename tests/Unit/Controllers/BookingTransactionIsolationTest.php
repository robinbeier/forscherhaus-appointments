<?php

namespace Tests\Unit\Controllers;

use Booking;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'controllers/Booking.php';

final class BookingTransactionIsolationTest extends TestCase
{
    public function testRescheduleTransactionSetsReadCommittedBeforeBegin(): void
    {
        $database = new BookingTransactionIsolationFakeDatabase();
        $controller = new class ($database) extends Booking {
            public function __construct(private readonly object $database) {}

            public function begin(bool $reschedule): bool
            {
                $this->db = $this->database;
                return $this->begin_public_booking_transaction($reschedule);
            }
        };

        $this->assertTrue($controller->begin(true));
        $this->assertSame(['set_transaction', 'begin'], $database->events);
        $this->assertSame(['SET TRANSACTION ISOLATION LEVEL READ COMMITTED'], $database->queries);

        $database->events = [];
        $database->queries = [];
        $this->assertTrue($controller->begin(false));
        $this->assertSame(['begin'], $database->events);
        $this->assertSame([], $database->queries);
    }

    public function testRescheduleTransactionFailsClosedWhenIsolationSetupFails(): void
    {
        $database = new BookingTransactionIsolationFakeDatabase();
        $database->queryResult = false;
        $controller = new class ($database) extends Booking {
            public function __construct(private readonly object $database) {}

            public function begin(bool $reschedule): bool
            {
                $this->db = $this->database;
                return $this->begin_public_booking_transaction($reschedule);
            }
        };

        $this->assertFalse($controller->begin(true));
        $this->assertSame(['set_transaction'], $database->events);
        $this->assertSame(['SET TRANSACTION ISOLATION LEVEL READ COMMITTED'], $database->queries);
    }
}

final class BookingTransactionIsolationFakeDatabase
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<string> */
    public array $queries = [];

    public object|false $queryResult;

    public function __construct()
    {
        $this->queryResult = new class {};
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): object|false
    {
        $this->events[] = 'set_transaction';
        $this->queries[] = $sql;
        return $this->queryResult;
    }

    public function trans_begin(): bool
    {
        $this->events[] = 'begin';
        return true;
    }
}

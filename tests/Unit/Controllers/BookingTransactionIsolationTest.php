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

        $database->events = [];
        $this->assertTrue($controller->begin(false));
        $this->assertSame(['begin'], $database->events);
    }
}

final class BookingTransactionIsolationFakeDatabase
{
    /** @var list<string> */
    public array $events = [];

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): object
    {
        $this->events[] = 'set_transaction';
        return new class {};
    }

    public function trans_begin(): bool
    {
        $this->events[] = 'begin';
        return true;
    }
}

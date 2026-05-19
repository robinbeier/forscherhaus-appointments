<?php

namespace Tests\Unit\Controllers;

use Calendar;
use RuntimeException;
use Tests\TestCase;
use Throwable;

require_once APPPATH . 'controllers/Calendar.php';

class CalendarAppointmentSaveConflictTest extends TestCase
{
    public function testExpectedAppointmentSaveConflictsReturnConflictStatus(): void
    {
        $controller = $this->createController();

        $this->assertSame(409, $controller->callExpectedStatus(new RuntimeException(lang('buffer_conflict_error'))));
        $this->assertSame(
            409,
            $controller->callExpectedStatus(new RuntimeException(lang('buffer_outside_schedule_error'))),
        );
        $this->assertSame(
            409,
            $controller->callExpectedStatus(new RuntimeException(lang('requested_hour_is_unavailable'))),
        );
    }

    public function testUnexpectedAppointmentSaveErrorsKeepDefaultExceptionHandling(): void
    {
        $controller = $this->createController();

        $this->assertNull($controller->callExpectedStatus(new RuntimeException('database connection failed')));
    }

    private function createController(): object
    {
        return new class extends Calendar {
            public function __construct() {}

            public function callExpectedStatus(Throwable $e): ?int
            {
                return $this->get_appointment_save_expected_error_status($e);
            }
        };
    }
}

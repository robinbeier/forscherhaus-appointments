<?php

namespace Tests\Unit\Scripts;

use CiContract\BookingWriteContractState;
use CiContract\ContractAssertionException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/OpenApiContractValidator.php';
require_once __DIR__ . '/../../../scripts/ci/lib/BookingWriteContractState.php';

final class BookingWriteContractStateTest extends TestCase
{
    public function testRequirePrimaryAppointmentWindowReturnsPersistedAppointmentWindow(): void
    {
        $window = BookingWriteContractState::requirePrimaryAppointmentWindow([
            'primary_appointment_start' => '2026-04-13 16:00:00',
            'primary_appointment_end' => '2026-04-13 16:25:00',
        ]);

        $this->assertSame(
            [
                'start' => '2026-04-13 16:00:00',
                'end' => '2026-04-13 16:25:00',
            ],
            $window,
        );
    }

    public function testRequirePrimaryAppointmentWindowFailsWhenAppointmentWindowStateIsMissing(): void
    {
        $this->expectException(ContractAssertionException::class);
        $this->expectExceptionMessage('Primary appointment window state is required');

        BookingWriteContractState::requirePrimaryAppointmentWindow([
            'primary_appointment_start' => '2026-04-13 16:00:00',
        ]);
    }
}

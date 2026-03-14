<?php

namespace Tests\Unit\Scripts;

use CiContract\BookedSlotMatcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/BookedSlotMatcher.php';

final class BookedSlotMatcherTest extends TestCase
{
    public function testMatchesBookedSlotWhenStoredEndDiffersFromRequestedWindow(): void
    {
        $appointment = [
            'start' => '2026-04-13 15:30:00',
            'end' => '2026-04-13 15:55:00',
            'status' => 'Booked',
            'providerId' => 21,
            'serviceId' => 1,
            'isUnavailability' => false,
        ];

        $this->assertTrue(BookedSlotMatcher::matches($appointment, 21, 1, '2026-04-13 15:30:00'));
    }

    public function testRejectsDifferentSlotOrUnavailableEntries(): void
    {
        $differentStart = [
            'start' => '2026-04-13 15:35:00',
            'status' => 'Booked',
            'providerId' => 21,
            'serviceId' => 1,
            'isUnavailability' => false,
        ];
        $unavailability = [
            'start' => '2026-04-13 15:30:00',
            'status' => 'Booked',
            'providerId' => 21,
            'serviceId' => 1,
            'isUnavailability' => true,
        ];

        $this->assertFalse(BookedSlotMatcher::matches($differentStart, 21, 1, '2026-04-13 15:30:00'));
        $this->assertFalse(BookedSlotMatcher::matches($unavailability, 21, 1, '2026-04-13 15:30:00'));
    }
}

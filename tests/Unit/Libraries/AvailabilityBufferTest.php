<?php

namespace Tests\Unit\Libraries;

use Availability;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AvailabilityBufferTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::requireFile(APPPATH . 'libraries/Availability.php');
    }

    public function test_fixed_availabilities_use_duration_and_buffer_for_slot_step(): void
    {
        $service = [
            'duration' => 25,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 5,
        ];

        $hours = $this->generateAvailableHours($service, [
            [
                'start' => '13:00',
                'end' => '14:30',
            ],
        ]);

        $this->assertSame(['13:00', '13:30', '14:00'], $hours);
    }

    public function test_fixed_availabilities_respect_buffer_before_in_slot_step(): void
    {
        $service = [
            'duration' => 25,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 5,
            'buffer_after' => 0,
        ];

        $hours = $this->generateAvailableHours($service, [
            [
                'start' => '13:00',
                'end' => '14:20',
            ],
        ]);

        $this->assertSame(['13:05', '13:35'], $hours);
    }

    public function test_flexible_availabilities_keep_quarter_hour_steps(): void
    {
        $service = [
            'duration' => 25,
            'availabilities_type' => AVAILABILITIES_TYPE_FLEXIBLE,
            'buffer_before' => 0,
            'buffer_after' => 5,
        ];

        $hours = $this->generateAvailableHours($service, [
            [
                'start' => '13:00',
                'end' => '14:00',
            ],
        ]);

        $this->assertSame(['13:00', '13:15', '13:30'], $hours);
    }

    public function test_flexible_availabilities_keep_quarter_hour_steps_with_buffer_before(): void
    {
        $service = [
            'duration' => 25,
            'availabilities_type' => AVAILABILITIES_TYPE_FLEXIBLE,
            'buffer_before' => 5,
            'buffer_after' => 0,
        ];

        $hours = $this->generateAvailableHours($service, [
            [
                'start' => '13:00',
                'end' => '14:00',
            ],
        ]);

        $this->assertSame(['13:15', '13:30'], $hours);
    }

    private function generateAvailableHours(array $service, array $emptyPeriods): array
    {
        $availability_reflection = new ReflectionClass(Availability::class);
        $availability = $availability_reflection->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(Availability::class, 'generate_available_hours');
        $method->setAccessible(true);

        return $method->invoke($availability, '2026-02-18', $service, $emptyPeriods);
    }
}

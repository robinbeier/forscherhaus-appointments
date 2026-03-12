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

    public function testGetAvailableHoursDelegatesToAnalyticalMethodBeforeApplyingFilters(): void
    {
        $availability = new class extends Availability {
            public array $calls = [];

            public function __construct() {}

            public function get_offered_hours_for_analysis(
                string $date,
                array $service,
                array $provider,
                ?int $exclude_appointment_id = null,
            ): array {
                $this->calls[] = ['method' => 'raw', 'date' => $date, 'exclude' => $exclude_appointment_id];

                return ['09:00'];
            }

            protected function consider_book_advance_timeout(
                string $date,
                array $available_hours,
                array $provider,
            ): array {
                $this->calls[] = ['method' => 'advance', 'hours' => $available_hours];

                return ['09:15'];
            }

            protected function consider_future_booking_limit(
                string $selected_date,
                array $available_hours,
                array $provider,
            ): array {
                $this->calls[] = ['method' => 'future', 'hours' => $available_hours];

                return ['09:30'];
            }
        };

        $service = [
            'duration' => 30,
            'attendants_number' => 1,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        $provider = ['timezone' => 'Europe/Berlin'];

        $result = $availability->get_available_hours('2026-02-18', $service, $provider, 77);

        $this->assertSame(['09:30'], $result);
        $this->assertSame(
            [
                ['method' => 'raw', 'date' => '2026-02-18', 'exclude' => 77],
                ['method' => 'advance', 'hours' => ['09:00']],
                ['method' => 'future', 'hours' => ['09:15']],
            ],
            $availability->calls,
        );
    }

    public function test_subtract_ranges_splits_periods_around_blocked_windows(): void
    {
        $availability_reflection = new ReflectionClass(Availability::class);
        $availability = $availability_reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Availability::class, 'subtract_ranges');
        $method->setAccessible(true);

        $available_ranges = [
            [
                'start' => new \DateTimeImmutable('2026-02-18 09:00:00'),
                'end' => new \DateTimeImmutable('2026-02-18 12:00:00'),
            ],
        ];
        $blocked_ranges = [
            [
                'start' => new \DateTimeImmutable('2026-02-18 09:30:00'),
                'end' => new \DateTimeImmutable('2026-02-18 10:00:00'),
            ],
            [
                'start' => new \DateTimeImmutable('2026-02-18 11:00:00'),
                'end' => new \DateTimeImmutable('2026-02-18 11:30:00'),
            ],
        ];

        $result = $method->invoke($availability, $available_ranges, $blocked_ranges);

        $normalized = array_map(
            static fn(array $range): array => [
                'start' => $range['start']->format('H:i'),
                'end' => $range['end']->format('H:i'),
            ],
            $result,
        );

        $this->assertSame(
            [
                ['start' => '09:00', 'end' => '09:30'],
                ['start' => '10:00', 'end' => '11:00'],
                ['start' => '11:30', 'end' => '12:00'],
            ],
            $normalized,
        );
    }

    public function test_normalize_appointment_ranges_clips_to_day_boundaries(): void
    {
        $availability_reflection = new ReflectionClass(Availability::class);
        $availability = $availability_reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Availability::class, 'normalize_appointment_ranges');
        $method->setAccessible(true);

        $appointments = [
            [
                'start_datetime' => '2026-02-17 23:30:00',
                'end_datetime' => '2026-02-18 09:15:00',
            ],
            [
                'start_datetime' => '2026-02-18 16:45:00',
                'end_datetime' => '2026-02-19 00:30:00',
            ],
        ];

        $result = $method->invoke(
            $availability,
            $appointments,
            new \DateTimeImmutable('2026-02-18 00:00:00'),
            new \DateTimeImmutable('2026-02-18 23:59:59'),
        );

        $normalized = array_map(
            static fn(array $range): array => [
                'start' => $range['start']->format('Y-m-d H:i:s'),
                'end' => $range['end']->format('Y-m-d H:i:s'),
            ],
            $result,
        );

        $this->assertSame(
            [
                ['start' => '2026-02-18 00:00:00', 'end' => '2026-02-18 09:15:00'],
                ['start' => '2026-02-18 16:45:00', 'end' => '2026-02-18 23:59:59'],
            ],
            $normalized,
        );
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

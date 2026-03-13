<?php

namespace Tests\Unit\Libraries;

use Appointments_model;
use Availability;
use Blocked_periods_model;
use CI_DB_query_builder;
use CI_DB_result;
use Tests\TestCase;
use Unavailabilities_model;

class AvailabilityAnalyticsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::requireFile(APPPATH . 'libraries/Availability.php');
    }

    public function testGetOfferedHoursForAnalysisReturnsRawSlotsWithoutTimeRelativeFilters(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn(
            $this->createAppointmentsQueryBuilder([
                [
                    'start_datetime' => '2026-02-18 08:30:00',
                    'end_datetime' => '2026-02-18 09:00:00',
                ],
            ]),
        );

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('query')
            ->willReturn(
                $this->createAppointmentsQueryBuilder([
                    [
                        'start_datetime' => '2026-02-18 09:30:00',
                        'end_datetime' => '2026-02-18 10:00:00',
                    ],
                ]),
            );
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('cast')
            ->with($this->callback(static fn($value): bool => is_array($value)));

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('is_entire_date_blocked')
            ->with('2026-02-18')
            ->willReturn(false);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('get_for_period')
            ->with('2026-02-18', '2026-02-18')
            ->willReturn([
                [
                    'start_datetime' => '2026-02-18 10:30:00',
                    'end_datetime' => '2026-02-18 11:00:00',
                ],
            ]);

        $availability = new Availability($appointmentsModel, $unavailabilitiesModel, $blockedPeriodsModel);

        $service = [
            'duration' => 30,
            'attendants_number' => 1,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        $provider = [
            'id' => 1,
            'timezone' => 'Europe/Berlin',
            'settings' => [
                'working_plan' => json_encode([
                    'wednesday' => [
                        'start' => '08:00',
                        'end' => '11:00',
                        'breaks' => [],
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $hours = $availability->get_offered_hours_for_analysis('2026-02-18', $service, $provider);

        $this->assertSame(['08:00', '09:00', '10:00'], $hours);
    }

    public function testGetOfferedHoursForAnalysisReturnsEmptyWhenEntireDateIsBlocked(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->expects($this->never())->method('query');

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel->expects($this->never())->method('query');

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('is_entire_date_blocked')
            ->with('2026-02-18')
            ->willReturn(true);
        $blockedPeriodsModel->expects($this->never())->method('get_for_period');

        $availability = new Availability($appointmentsModel, $unavailabilitiesModel, $blockedPeriodsModel);

        $service = [
            'duration' => 30,
            'attendants_number' => 1,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        $provider = [
            'id' => 1,
            'timezone' => 'Europe/Berlin',
            'settings' => [
                'working_plan' => json_encode([
                    'wednesday' => [
                        'start' => '08:00',
                        'end' => '11:00',
                        'breaks' => [],
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $hours = $availability->get_offered_hours_for_analysis('2026-02-18', $service, $provider);

        $this->assertSame([], $hours);
    }

    public function testGetOfferedHoursByDateForAnalysisBatchesRangeQueriesAndSplitsSlotsPerDay(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel
            ->expects($this->once())
            ->method('query')
            ->willReturn(
                $this->createAppointmentsQueryBuilder([
                    [
                        'start_datetime' => '2026-02-18 08:30:00',
                        'end_datetime' => '2026-02-18 09:00:00',
                    ],
                ]),
            );

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('query')
            ->willReturn(
                $this->createAppointmentsQueryBuilder([
                    [
                        'start_datetime' => '2026-02-19 08:00:00',
                        'end_datetime' => '2026-02-19 08:30:00',
                    ],
                ]),
            );
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('cast')
            ->with($this->callback(static fn($value): bool => is_array($value)));

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('get_for_period')
            ->with('2026-02-17', '2026-02-20')
            ->willReturn([
                [
                    'start_datetime' => '2026-02-19 09:30:00',
                    'end_datetime' => '2026-02-19 10:00:00',
                ],
            ]);

        $availability = new Availability($appointmentsModel, $unavailabilitiesModel, $blockedPeriodsModel);

        $service = [
            'duration' => 30,
            'attendants_number' => 1,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        $provider = [
            'id' => 1,
            'timezone' => 'Europe/Berlin',
            'settings' => [
                'working_plan' => json_encode([
                    'wednesday' => [
                        'start' => '08:00',
                        'end' => '10:00',
                        'breaks' => [],
                    ],
                    'thursday' => [
                        'start' => '08:00',
                        'end' => '10:00',
                        'breaks' => [],
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $hoursByDate = $availability->get_offered_hours_by_date_for_analysis(
            '2026-02-18',
            '2026-02-19',
            $service,
            $provider,
        );

        $this->assertSame(
            [
                '2026-02-18' => ['08:00', '09:00', '09:30'],
                '2026-02-19' => ['08:30', '09:00'],
            ],
            $hoursByDate,
        );
    }

    public function testGetPlannedHoursByDateForAnalysisIgnoresBookedAppointments(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->expects($this->never())->method('query');

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('query')
            ->willReturn($this->createAppointmentsQueryBuilder([]));
        $unavailabilitiesModel->expects($this->never())->method('cast');

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('get_for_period')
            ->with('2026-02-17', '2026-02-19')
            ->willReturn([]);

        $availability = new Availability($appointmentsModel, $unavailabilitiesModel, $blockedPeriodsModel);

        $service = [
            'duration' => 30,
            'attendants_number' => 1,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        $provider = [
            'id' => 1,
            'timezone' => 'Europe/Berlin',
            'settings' => [
                'working_plan' => json_encode([
                    'wednesday' => [
                        'start' => '14:00',
                        'end' => '16:00',
                        'breaks' => [],
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $hoursByDate = $availability->get_planned_hours_by_date_for_analysis(
            '2026-02-18',
            '2026-02-18',
            $service,
            $provider,
        );

        $this->assertSame(
            [
                '2026-02-18' => ['14:00', '14:30', '15:00', '15:30'],
            ],
            $hoursByDate,
        );
    }

    public function testGetOfferedHoursByDateForAnalysisTreatsMissingBreaksAsNoBreaks(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel
            ->expects($this->once())
            ->method('query')
            ->willReturn($this->createAppointmentsQueryBuilder([]));

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('query')
            ->willReturn($this->createAppointmentsQueryBuilder([]));
        $unavailabilitiesModel->expects($this->never())->method('cast');

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('get_for_period')
            ->with('2026-02-17', '2026-02-19')
            ->willReturn([]);

        $availability = new Availability($appointmentsModel, $unavailabilitiesModel, $blockedPeriodsModel);

        $service = [
            'duration' => 30,
            'attendants_number' => 1,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        $provider = [
            'id' => 1,
            'timezone' => 'Europe/Berlin',
            'settings' => [
                'working_plan' => json_encode([
                    'wednesday' => [
                        'start' => '08:00',
                        'end' => '09:00',
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $hoursByDate = $availability->get_offered_hours_by_date_for_analysis(
            '2026-02-18',
            '2026-02-18',
            $service,
            $provider,
        );

        $this->assertSame(
            [
                '2026-02-18' => ['08:00', '08:30'],
            ],
            $hoursByDate,
        );
    }

    public function testGetOfferedHoursByDateForAnalysisKeepsBlockedPeriodBoundaryOverlaps(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel
            ->expects($this->once())
            ->method('query')
            ->willReturn($this->createAppointmentsQueryBuilder([]));

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('query')
            ->willReturn($this->createAppointmentsQueryBuilder([]));
        $unavailabilitiesModel->expects($this->never())->method('cast');

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('get_for_period')
            ->with('2026-02-17', '2026-02-20')
            ->willReturn([
                [
                    'start_datetime' => '2026-02-17 23:30:00',
                    'end_datetime' => '2026-02-18 08:30:00',
                ],
                [
                    'start_datetime' => '2026-02-19 09:30:00',
                    'end_datetime' => '2026-02-20 00:30:00',
                ],
            ]);

        $availability = new Availability($appointmentsModel, $unavailabilitiesModel, $blockedPeriodsModel);

        $service = [
            'duration' => 30,
            'attendants_number' => 1,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        $provider = [
            'id' => 1,
            'timezone' => 'Europe/Berlin',
            'settings' => [
                'working_plan' => json_encode([
                    'wednesday' => [
                        'start' => '08:00',
                        'end' => '10:00',
                        'breaks' => [],
                    ],
                    'thursday' => [
                        'start' => '08:00',
                        'end' => '10:00',
                        'breaks' => [],
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $hoursByDate = $availability->get_offered_hours_by_date_for_analysis(
            '2026-02-18',
            '2026-02-19',
            $service,
            $provider,
        );

        $this->assertSame(
            [
                '2026-02-18' => ['08:30', '09:00', '09:30'],
                '2026-02-19' => ['08:00', '08:30', '09:00'],
            ],
            $hoursByDate,
        );
    }

    public function testIndexEventsByDateClipsLongEventsToAnalysisWindow(): void
    {
        $availability = new class (
            $this->createMock(Appointments_model::class),
            $this->createMock(Unavailabilities_model::class),
            $this->createMock(Blocked_periods_model::class),
        ) extends Availability {
            public function exposeIndexEventsByDate(
                array $events,
                \DateTimeImmutable $rangeStart,
                \DateTimeImmutable $rangeEnd,
            ): array {
                return $this->index_events_by_date($events, $rangeStart, $rangeEnd);
            }
        };

        $eventsByDate = $availability->exposeIndexEventsByDate(
            [
                [
                    'start_datetime' => '2025-01-01 00:00:00',
                    'end_datetime' => '2027-12-31 23:59:59',
                ],
            ],
            new \DateTimeImmutable('2026-02-18'),
            new \DateTimeImmutable('2026-02-19'),
        );

        $this->assertSame(['2026-02-18', '2026-02-19'], array_keys($eventsByDate));
        $this->assertCount(1, $eventsByDate['2026-02-18']);
        $this->assertCount(1, $eventsByDate['2026-02-19']);
    }

    public function testGetOfferedHoursByDateForAnalysisRespectsEntireDateBlocks(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel
            ->expects($this->once())
            ->method('query')
            ->willReturn($this->createAppointmentsQueryBuilder([]));

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('query')
            ->willReturn($this->createAppointmentsQueryBuilder([]));
        $unavailabilitiesModel->expects($this->never())->method('cast');

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('get_for_period')
            ->with('2026-02-17', '2026-02-20')
            ->willReturn([
                [
                    'start_datetime' => '2026-02-18 00:00:00',
                    'end_datetime' => '2026-02-18 23:59:59',
                ],
                [
                    'start_datetime' => '2026-02-18 08:00:00',
                    'end_datetime' => '2026-02-18 09:00:00',
                ],
            ]);

        $availability = new Availability($appointmentsModel, $unavailabilitiesModel, $blockedPeriodsModel);

        $service = [
            'duration' => 30,
            'attendants_number' => 1,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ];
        $provider = [
            'id' => 1,
            'timezone' => 'Europe/Berlin',
            'settings' => [
                'working_plan' => json_encode([
                    'wednesday' => [
                        'start' => '08:00',
                        'end' => '09:00',
                        'breaks' => [],
                    ],
                    'thursday' => [
                        'start' => '08:00',
                        'end' => '09:00',
                        'breaks' => [],
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $hoursByDate = $availability->get_offered_hours_by_date_for_analysis(
            '2026-02-18',
            '2026-02-19',
            $service,
            $provider,
        );

        $this->assertSame(
            [
                '2026-02-18' => [],
                '2026-02-19' => ['08:00', '08:30'],
            ],
            $hoursByDate,
        );
    }

    private function createAppointmentsQueryBuilder(array $rows): CI_DB_query_builder
    {
        $builder = $this->createMock(CI_DB_query_builder::class);

        $builder->method('where')->willReturnSelf();
        $builder->method('get')->willReturn($this->createAppointmentsResult($rows));

        return $builder;
    }

    private function createAppointmentsResult(array $rows): CI_DB_result
    {
        $result = $this->createMock(CI_DB_result::class);
        $result->method('result_array')->willReturn($rows);

        return $result;
    }
}

<?php

namespace Tests\Unit\Libraries;

use Appointments_model;
use Booking_slot_analytics;
use CI_DB_query_builder;
use CI_DB_result;
use Dashboard_metrics;
use PHPUnit\Framework\MockObject\MockObject;
use Provider_utilization;
use Providers_model;
use Services_model;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Model.php';
require_once APPPATH . 'models/Providers_model.php';
require_once APPPATH . 'models/Services_model.php';
require_once APPPATH . 'libraries/Booking_slot_analytics.php';
require_once APPPATH . 'libraries/Provider_utilization.php';
require_once APPPATH . 'libraries/Dashboard_metrics.php';

class DashboardMetricsTest extends TestCase
{
    public function testSummarizeBuildsOverallBookingProgressTotals(): void
    {
        $library = new Dashboard_metrics(
            $this->createMock(Providers_model::class),
            $this->createMock(Appointments_model::class),
            $this->createMock(Provider_utilization::class),
            $this->createMock(Services_model::class),
            $this->createMock(Booking_slot_analytics::class),
        );

        $summary = $library->summarize(
            [
                [
                    'target' => 10,
                    'booked' => 6,
                    'open' => 4,
                    'needs_attention' => true,
                    'is_target_fallback' => false,
                    'has_explicit_target' => true,
                    'has_plan' => true,
                ],
                [
                    'target' => 8,
                    'booked' => 8,
                    'open' => 0,
                    'needs_attention' => false,
                    'is_target_fallback' => true,
                    'has_explicit_target' => false,
                    'has_plan' => true,
                ],
                [
                    'target' => 0,
                    'booked' => 0,
                    'open' => 0,
                    'needs_attention' => false,
                    'is_target_fallback' => false,
                    'has_explicit_target' => false,
                    'has_plan' => false,
                ],
            ],
            0.9,
        );

        $this->assertSame(3, $summary['provider_count']);
        $this->assertSame(18, $summary['target_total']);
        $this->assertSame(14, $summary['booked_total']);
        $this->assertSame(4, $summary['open_total']);
        $this->assertSame(1, $summary['attention_count']);
        $this->assertSame(1, $summary['fallback_count']);
        $this->assertSame(1, $summary['explicit_target_count']);
        $this->assertSame(2, $summary['without_target_count']);
        $this->assertSame(2, $summary['with_plan_count']);
        $this->assertSame(3, $summary['missing_to_threshold_total']);
        $this->assertSame(14, $summary['booked_distinct_total']);
        $this->assertSame(1, $summary['providers_below_threshold']);
        $this->assertEqualsWithDelta(14 / 18, $summary['fill_rate'], 0.0001);
    }

    public function testSummarizeComputesOpenTotalFromAggregateProgressToOneHundredPercent(): void
    {
        $library = new Dashboard_metrics(
            $this->createMock(Providers_model::class),
            $this->createMock(Appointments_model::class),
            $this->createMock(Provider_utilization::class),
            $this->createMock(Services_model::class),
            $this->createMock(Booking_slot_analytics::class),
        );

        $summary = $library->summarize(
            [
                [
                    'target' => 10,
                    'booked' => 12,
                    'open' => 0,
                    'needs_attention' => false,
                    'is_target_fallback' => false,
                    'has_explicit_target' => true,
                    'has_plan' => true,
                ],
                [
                    'target' => 10,
                    'booked' => 5,
                    'open' => 5,
                    'needs_attention' => true,
                    'is_target_fallback' => false,
                    'has_explicit_target' => true,
                    'has_plan' => true,
                ],
            ],
            0.9,
        );

        $this->assertSame(3, $summary['open_total']);
        $this->assertEqualsWithDelta(17 / 20, $summary['fill_rate'], 0.0001);
    }

    public function testSummarizeReturnsStableZeroStateWhenNoTargetsExist(): void
    {
        $library = new Dashboard_metrics(
            $this->createMock(Providers_model::class),
            $this->createMock(Appointments_model::class),
            $this->createMock(Provider_utilization::class),
            $this->createMock(Services_model::class),
            $this->createMock(Booking_slot_analytics::class),
        );

        $summary = $library->summarize(
            [
                [
                    'target' => 0,
                    'booked' => 0,
                    'open' => 0,
                    'needs_attention' => false,
                    'is_target_fallback' => false,
                    'has_explicit_target' => false,
                    'has_plan' => false,
                ],
            ],
            0.9,
        );

        $this->assertSame(0, $summary['target_total']);
        $this->assertSame(0, $summary['booked_total']);
        $this->assertSame(0, $summary['open_total']);
        $this->assertSame(0.0, $summary['fill_rate']);
        $this->assertSame(0, $summary['missing_to_threshold_total']);
        $this->assertSame(0, $summary['providers_below_threshold']);
    }

    public function testCollectBatchesBookedAppointmentCountsForAllProviders(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [1],
                'class_size_default' => 20,
            ],
            [
                'id' => 11,
                'first_name' => 'Alan',
                'last_name' => 'Turing',
                'email' => 'alan@example.org',
                'services' => [1],
                'class_size_default' => 20,
            ],
        ];
        $service = $this->makeService(1);

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->expects($this->once())->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->exactly(2))
            ->method('calculate')
            ->willReturnOnConsecutiveCalls(
                ['total' => 20, 'booked' => 6, 'has_plan' => true],
                ['total' => 20, 'booked' => 12, 'has_plan' => true],
            );

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder
            ->method('get')
            ->willReturn(
                $this->createCountResult([
                    ['id_users_provider' => 10, 'aggregate' => 5],
                    ['id_users_provider' => 11, 'aggregate' => 13],
                ]),
            );

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->expects($this->once())->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 1], 1)
            ->willReturn([$service]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->exactly(2))
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturnCallback(function (
                string $startDate,
                string $endDate,
                array $resolvedService,
                array $provider,
            ) use ($providers, $service): array {
                TestCase::assertSame('2024-02-01', $startDate);
                TestCase::assertSame('2024-02-29', $endDate);
                TestCase::assertSame($service, $resolvedService);
                TestCase::assertTrue(in_array($provider, $providers, true));

                return $this->buildDailyHoursMap('2024-02-01', '2024-02-29', ['09:00', '15:00']);
            });

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-29'), [
            'statuses' => ['Booked'],
        ]);

        $this->assertCount(2, $metrics);

        $metricsByProvider = [];
        foreach ($metrics as $metric) {
            $metricsByProvider[(int) $metric['provider_id']] = $metric;
        }

        $this->assertSame(5, $metricsByProvider[10]['booked']);
        $this->assertSame(13, $metricsByProvider[11]['booked']);
        $this->assertSame(58, $metricsByProvider[10]['slots_planned']);
        $this->assertFalse($metricsByProvider[10]['has_capacity_gap']);
        $this->assertTrue($metricsByProvider[10]['after_15_evaluable']);
        $this->assertSame(29, $metricsByProvider[10]['after_15_slots']);
        $this->assertSame(58, $metricsByProvider[10]['total_offered_slots']);
        $this->assertSame(1.0, $metricsByProvider[10]['after_15_ratio']);
        $this->assertSame(100.0, $metricsByProvider[10]['after_15_percent']);
        $this->assertTrue($metricsByProvider[10]['after_15_target_met']);
        $this->assertSame(
            [Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED],
            $metricsByProvider[10]['status_reasons'],
        );
    }

    public function testCollectUsesSelectedServiceForAfter15Metrics(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [1, 5],
                'class_size_default' => 20,
            ],
        ];
        $selectedService = $this->makeService(5);

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->with($providers[0], '2024-04-15', '2024-04-16', ['Booked'], 5)
            ->willReturn(['total' => 19, 'booked' => 4, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 4]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 5], 1)
            ->willReturn([$selectedService]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->once())
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturnCallback(function (string $startDate, string $endDate, array $service, array $provider) use (
                $selectedService,
                $providers,
            ): array {
                TestCase::assertSame('2024-04-15', $startDate);
                TestCase::assertSame('2024-04-16', $endDate);
                TestCase::assertSame($selectedService, $service);
                TestCase::assertSame($providers[0], $provider);

                return [
                    '2024-04-15' => ['09:00', '10:00', '15:00'],
                    '2024-04-16' => ['08:00', '15:30'],
                ];
            });

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-16'), [
            'statuses' => ['Booked'],
            'service_id' => 5,
        ]);

        $this->assertCount(1, $metrics);
        $this->assertSame(5, $metrics[0]['slots_planned']);
        $this->assertSame(22, $metrics[0]['slots_required']);
        $this->assertTrue($metrics[0]['has_capacity_gap']);
        $this->assertSame(2, $metrics[0]['after_15_slots']);
        $this->assertSame(5, $metrics[0]['total_offered_slots']);
        $this->assertEqualsWithDelta(2 / 22, $metrics[0]['after_15_ratio'], 0.0001);
        $this->assertSame(9.1, $metrics[0]['after_15_percent']);
        $this->assertFalse($metrics[0]['after_15_target_met']);
        $this->assertTrue($metrics[0]['after_15_evaluable']);
        $this->assertSame(
            [
                Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED,
                Dashboard_metrics::STATUS_REASON_AFTER_15_GOAL_MISSED,
                Dashboard_metrics::STATUS_REASON_CAPACITY_GAP,
            ],
            $metrics[0]['status_reasons'],
        );
    }

    public function testCollectKeepsCapacityGapDecisionBoundToPlannedSlotsWhenBookingsExist(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [1],
                'class_size_default' => 18,
            ],
        ];
        $service = $this->makeService(1);

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->expects($this->once())->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->with($providers[0], '2024-04-15', '2024-04-15', ['Booked'], null)
            ->willReturn(['total' => 20, 'booked' => 6, 'open' => 14, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 6]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->expects($this->once())->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 1], 1)
            ->willReturn([$service]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->once())
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturn([
                '2024-04-15' => $this->buildThresholdHours(14, 6),
            ]);

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-15'), [
            'statuses' => ['Booked'],
        ]);

        $this->assertCount(1, $metrics);
        $this->assertSame(20, $metrics[0]['slots_planned']);
        $this->assertSame(20, $metrics[0]['total_offered_slots']);
        $this->assertSame(20, $metrics[0]['slots_required']);
        $this->assertFalse($metrics[0]['has_capacity_gap']);
        $this->assertSame([Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED], $metrics[0]['status_reasons']);
    }

    public function testCollectKeepsCapacityAndAfter15StatusStableWhenBookingsOnlyCreateBufferBlocks(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Marvin',
                'last_name' => 'Hold',
                'email' => 'marvin@example.org',
                'services' => [1],
                'class_size_default' => 22,
            ],
        ];
        $service = $this->makeService(1);
        $service['duration'] = 25;
        $service['buffer_after'] = 5;

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->expects($this->once())->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->with($providers[0], '2024-04-15', '2024-04-16', ['Booked'], null)
            ->willReturn(['total' => 22, 'booked' => 6, 'open' => 16, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 6]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->expects($this->once())->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 1], 1)
            ->willReturn([$service]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->once())
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturnCallback(function (
                string $startDate,
                string $endDate,
                array $resolvedService,
                array $provider,
            ) use ($service, $providers): array {
                TestCase::assertSame('2024-04-15', $startDate);
                TestCase::assertSame('2024-04-16', $endDate);
                TestCase::assertSame($service, $resolvedService);
                TestCase::assertSame($providers[0], $provider);

                return [
                    '2024-04-15' => $this->buildThresholdHours(8, 4),
                    '2024-04-16' => $this->buildThresholdHours(8, 4),
                ];
            });

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-16'), [
            'statuses' => ['Booked'],
        ]);

        $this->assertCount(1, $metrics);
        $this->assertSame(24, $metrics[0]['slots_planned']);
        $this->assertSame(24, $metrics[0]['slots_required']);
        $this->assertFalse($metrics[0]['has_capacity_gap']);
        $this->assertSame(8, $metrics[0]['after_15_slots']);
        $this->assertSame(24, $metrics[0]['total_offered_slots']);
        $this->assertSame(33.3, $metrics[0]['after_15_percent']);
        $this->assertTrue($metrics[0]['after_15_target_met']);
        $this->assertSame([Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED], $metrics[0]['status_reasons']);
    }

    public function testCollectKeepsServiceSpecificAfter15RatioForFallbackTargets(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [1, 5],
            ],
        ];
        $selectedService = $this->makeService(5);
        $selectedService['duration'] = 60;
        $secondaryService = $this->makeService(1);
        $secondaryService['duration'] = 30;

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->with($providers[0], '2024-04-15', '2024-04-16', ['Booked'], 5)
            ->willReturn(['total' => 8, 'booked' => 4, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 99]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (array $where, int $limit) use ($selectedService, $secondaryService): array {
                TestCase::assertSame(1, $limit);

                return match ((int) ($where['id'] ?? 0)) {
                    1 => [$secondaryService],
                    5 => [$selectedService],
                };
            });

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->once())
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturnCallback(function (string $startDate, string $endDate, array $service, array $provider) use (
                $selectedService,
                $providers,
            ): array {
                TestCase::assertSame('2024-04-15', $startDate);
                TestCase::assertSame('2024-04-16', $endDate);
                TestCase::assertSame($selectedService, $service);
                TestCase::assertSame($providers[0], $provider);

                return [
                    '2024-04-15' => ['09:00', '10:00', '15:00'],
                    '2024-04-16' => ['08:00', '15:30'],
                ];
            });

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-16'), [
            'statuses' => ['Booked'],
            'service_id' => 5,
        ]);

        $this->assertCount(1, $metrics);
        $this->assertSame(8, $metrics[0]['target']);
        $this->assertTrue($metrics[0]['is_target_fallback']);
        $this->assertSame(5, $metrics[0]['slots_planned']);
        $this->assertSame(8, $metrics[0]['slots_required']);
        $this->assertTrue($metrics[0]['has_capacity_gap']);
        $this->assertSame(2, $metrics[0]['after_15_slots']);
        $this->assertSame(5, $metrics[0]['total_offered_slots']);
        $this->assertEqualsWithDelta(0.4, $metrics[0]['after_15_ratio'], 0.0001);
        $this->assertSame(40.0, $metrics[0]['after_15_percent']);
        $this->assertTrue($metrics[0]['after_15_target_met']);
        $this->assertTrue($metrics[0]['after_15_evaluable']);
        $this->assertSame([Dashboard_metrics::STATUS_REASON_CAPACITY_GAP], $metrics[0]['status_reasons']);
    }

    public function testCollectNormalizesFallbackTargetsWhenSlotSizeMatchesSelectedService(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [5],
            ],
        ];
        $selectedService = $this->makeService(5);

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->with($providers[0], '2024-04-15', '2024-04-16', ['Booked'], 5)
            ->willReturn(['total' => 8, 'booked' => 4, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 99]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 5], 1)
            ->willReturn([$selectedService]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->once())
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturn([
                '2024-04-15' => ['09:00', '10:00', '15:00'],
                '2024-04-16' => ['08:00', '15:30'],
            ]);

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-16'), [
            'statuses' => ['Booked'],
            'service_id' => 5,
        ]);

        $this->assertCount(1, $metrics);
        $this->assertSame(8, $metrics[0]['target']);
        $this->assertTrue($metrics[0]['is_target_fallback']);
        $this->assertSame(5, $metrics[0]['total_offered_slots']);
        $this->assertEqualsWithDelta(2 / 8, $metrics[0]['after_15_ratio'], 0.0001);
        $this->assertSame(25.0, $metrics[0]['after_15_percent']);
        $this->assertFalse($metrics[0]['after_15_target_met']);
        $this->assertSame(
            [Dashboard_metrics::STATUS_REASON_AFTER_15_GOAL_MISSED, Dashboard_metrics::STATUS_REASON_CAPACITY_GAP],
            $metrics[0]['status_reasons'],
        );
    }

    public function testCollectCapsCapacityRequirementAtTwentyFiveForSmallClasses(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [1],
                'class_size_default' => 24,
            ],
        ];
        $service = $this->makeService(1);

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->willReturn(['total' => 24, 'booked' => 12, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 12]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 1], 1)
            ->willReturn([$service]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->once())
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturn(['2024-04-15' => ['09:00', '10:00', '11:00', '12:00']]);

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-15'), [
            'statuses' => ['Booked'],
        ]);

        $this->assertCount(1, $metrics);
        $this->assertSame(24, $metrics[0]['target']);
        $this->assertSame(25, $metrics[0]['slots_required']);
        $this->assertSame(4, $metrics[0]['slots_planned']);
        $this->assertTrue($metrics[0]['has_capacity_gap']);
    }

    public function testCollectLeavesAfter15MetricsNeutralWithoutUniqueService(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [1, 5],
                'class_size_default' => 20,
            ],
        ];

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->willReturn(['total' => 10, 'booked' => 2, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 2]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel->expects($this->never())->method('get');

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics->expects($this->never())->method('get_planned_hours_by_date_for_analysis');

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-16'), [
            'statuses' => ['Booked'],
        ]);

        $this->assertCount(1, $metrics);
        $this->assertNull($metrics[0]['slots_planned']);
        $this->assertFalse($metrics[0]['has_capacity_gap']);
        $this->assertNull($metrics[0]['after_15_slots']);
        $this->assertNull($metrics[0]['total_offered_slots']);
        $this->assertNull($metrics[0]['after_15_ratio']);
        $this->assertNull($metrics[0]['after_15_percent']);
        $this->assertNull($metrics[0]['after_15_target_met']);
        $this->assertFalse($metrics[0]['after_15_evaluable']);
        $this->assertSame([Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED], $metrics[0]['status_reasons']);
    }

    public function testCollectLeavesAfter15MetricsNeutralWithoutAnyService(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [],
                'class_size_default' => 20,
            ],
        ];

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->willReturn(['total' => 10, 'booked' => 2, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 2]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel->expects($this->never())->method('get');

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics->expects($this->never())->method('get_planned_hours_by_date_for_analysis');

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-16'), [
            'statuses' => ['Booked'],
        ]);

        $this->assertCount(1, $metrics);
        $this->assertNull($metrics[0]['slots_planned']);
        $this->assertFalse($metrics[0]['has_capacity_gap']);
        $this->assertNull($metrics[0]['after_15_slots']);
        $this->assertNull($metrics[0]['total_offered_slots']);
        $this->assertNull($metrics[0]['after_15_ratio']);
        $this->assertNull($metrics[0]['after_15_percent']);
        $this->assertNull($metrics[0]['after_15_target_met']);
        $this->assertFalse($metrics[0]['after_15_evaluable']);
        $this->assertSame([Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED], $metrics[0]['status_reasons']);
    }

    public function testCollectLeavesAfter15MetricsNeutralWhenUniqueServiceHasZeroOfferedSlots(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [1],
                'class_size_default' => 20,
            ],
        ];
        $service = $this->makeService(1);

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->once())
            ->method('calculate')
            ->willReturn(['total' => 10, 'booked' => 2, 'has_plan' => true]);

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder->method('get')->willReturn($this->createCountResult([['id_users_provider' => 10, 'aggregate' => 2]]));

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 1], 1)
            ->willReturn([$service]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->once())
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturn([
                '2024-04-15' => [],
                '2024-04-16' => [],
            ]);

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-16'), [
            'statuses' => ['Booked'],
        ]);

        $this->assertCount(1, $metrics);
        $this->assertSame(0, $metrics[0]['slots_planned']);
        $this->assertTrue($metrics[0]['has_capacity_gap']);
        $this->assertSame(0, $metrics[0]['after_15_slots']);
        $this->assertSame(0, $metrics[0]['total_offered_slots']);
        $this->assertNull($metrics[0]['after_15_ratio']);
        $this->assertNull($metrics[0]['after_15_percent']);
        $this->assertNull($metrics[0]['after_15_target_met']);
        $this->assertFalse($metrics[0]['after_15_evaluable']);
        $this->assertSame(
            [Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED, Dashboard_metrics::STATUS_REASON_CAPACITY_GAP],
            $metrics[0]['status_reasons'],
        );
    }

    public function testCollectKeepsOtherProvidersWhenAfter15AnalyticsFailsForOneProvider(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.org',
                'services' => [1],
                'class_size_default' => 20,
            ],
            [
                'id' => 11,
                'first_name' => 'Alan',
                'last_name' => 'Turing',
                'email' => 'alan@example.org',
                'services' => [1],
                'class_size_default' => 20,
            ],
        ];
        $service = $this->makeService(1);

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->exactly(2))
            ->method('calculate')
            ->willReturnOnConsecutiveCalls(
                ['total' => 10, 'booked' => 2, 'has_plan' => true],
                ['total' => 12, 'booked' => 6, 'has_plan' => true],
            );

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder
            ->method('get')
            ->willReturn(
                $this->createCountResult([
                    ['id_users_provider' => 10, 'aggregate' => 2],
                    ['id_users_provider' => 11, 'aggregate' => 6],
                ]),
            );

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 1], 1)
            ->willReturn([$service]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->exactly(2))
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturnCallback(static function (
                string $startDate,
                string $endDate,
                array $resolvedService,
                array $provider,
            ) use ($service): array {
                TestCase::assertSame('2024-04-15', $startDate);
                TestCase::assertSame('2024-04-15', $endDate);
                TestCase::assertSame($service, $resolvedService);

                if ((int) $provider['id'] === 11) {
                    throw new \RuntimeException('malformed provider plan');
                }

                return ['2024-04-15' => ['09:00', '15:00']];
            });

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-15'), [
            'statuses' => ['Booked'],
        ]);

        $this->assertCount(2, $metrics);

        $metricsByProvider = [];
        foreach ($metrics as $metric) {
            $metricsByProvider[(int) $metric['provider_id']] = $metric;
        }

        $this->assertSame(1, $metricsByProvider[10]['after_15_slots']);
        $this->assertSame(2, $metricsByProvider[10]['total_offered_slots']);
        $this->assertSame(2, $metricsByProvider[10]['slots_planned']);
        $this->assertTrue($metricsByProvider[10]['has_capacity_gap']);
        $this->assertSame(4.5, $metricsByProvider[10]['after_15_percent']);
        $this->assertTrue($metricsByProvider[10]['after_15_evaluable']);
        $this->assertSame(
            [
                Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED,
                Dashboard_metrics::STATUS_REASON_AFTER_15_GOAL_MISSED,
                Dashboard_metrics::STATUS_REASON_CAPACITY_GAP,
            ],
            $metricsByProvider[10]['status_reasons'],
        );

        $this->assertNull($metricsByProvider[11]['slots_planned']);
        $this->assertFalse($metricsByProvider[11]['has_capacity_gap']);
        $this->assertNull($metricsByProvider[11]['after_15_slots']);
        $this->assertNull($metricsByProvider[11]['total_offered_slots']);
        $this->assertNull($metricsByProvider[11]['after_15_ratio']);
        $this->assertNull($metricsByProvider[11]['after_15_percent']);
        $this->assertNull($metricsByProvider[11]['after_15_target_met']);
        $this->assertFalse($metricsByProvider[11]['after_15_evaluable']);
        $this->assertSame(
            [Dashboard_metrics::STATUS_REASON_BOOKING_GOAL_MISSED],
            $metricsByProvider[11]['status_reasons'],
        );
    }

    public function testCollectUsesPlannedSlotsForAfter15ThresholdDecisions(): void
    {
        $providers = [
            [
                'id' => 10,
                'first_name' => 'Adina',
                'last_name' => 'Rossmeisl',
                'email' => 'adina@example.org',
                'services' => [1],
                'class_size_default' => 19,
            ],
            [
                'id' => 11,
                'first_name' => 'Christopher',
                'last_name' => 'Fink',
                'email' => 'christopher@example.org',
                'services' => [1],
                'class_size_default' => 13,
            ],
            [
                'id' => 12,
                'first_name' => 'Rebecca',
                'last_name' => 'Schleupner',
                'email' => 'rebecca@example.org',
                'services' => [1],
                'class_size_default' => 16,
            ],
        ];
        $service = $this->makeService(1);

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel->expects($this->once())->method('get_available_providers')->with(false)->willReturn($providers);

        /** @var Provider_utilization&MockObject $providerUtilization */
        $providerUtilization = $this->createMock(Provider_utilization::class);
        $providerUtilization
            ->expects($this->exactly(3))
            ->method('calculate')
            ->willReturnOnConsecutiveCalls(
                ['total' => 19, 'booked' => 19, 'has_plan' => true],
                ['total' => 13, 'booked' => 13, 'has_plan' => true],
                ['total' => 16, 'booked' => 16, 'has_plan' => true],
            );

        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('group_by')->willReturnSelf();
        $builder
            ->method('get')
            ->willReturn(
                $this->createCountResult([
                    ['id_users_provider' => 10, 'aggregate' => 19],
                    ['id_users_provider' => 11, 'aggregate' => 13],
                    ['id_users_provider' => 12, 'aggregate' => 16],
                ]),
            );

        /** @var Appointments_model&MockObject $appointmentsModel */
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->expects($this->once())->method('query')->willReturn($builder);

        /** @var Services_model&MockObject $servicesModel */
        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 1], 1)
            ->willReturn([$service]);

        /** @var Booking_slot_analytics&MockObject $bookingSlotAnalytics */
        $bookingSlotAnalytics = $this->createMock(Booking_slot_analytics::class);
        $bookingSlotAnalytics
            ->expects($this->exactly(3))
            ->method('get_planned_hours_by_date_for_analysis')
            ->willReturnCallback(function (
                string $startDate,
                string $endDate,
                array $resolvedService,
                array $provider,
            ) use ($service): array {
                TestCase::assertSame('2024-04-15', $startDate);
                TestCase::assertSame('2024-04-15', $endDate);
                TestCase::assertSame($service, $resolvedService);

                return match ((int) $provider['id']) {
                    10 => ['2024-04-15' => $this->buildThresholdHours(14, 7)],
                    11 => ['2024-04-15' => $this->buildThresholdHours(10, 5)],
                    12 => ['2024-04-15' => $this->buildThresholdHours(14, 4)],
                };
            });

        $library = new Dashboard_metrics(
            $providersModel,
            $appointmentsModel,
            $providerUtilization,
            $servicesModel,
            $bookingSlotAnalytics,
        );

        $metrics = $library->collect(new \DateTimeImmutable('2024-04-15'), new \DateTimeImmutable('2024-04-15'), [
            'statuses' => ['Booked'],
        ]);

        $metricsByProvider = [];
        foreach ($metrics as $metric) {
            $metricsByProvider[(int) $metric['provider_id']] = $metric;
        }

        $this->assertSame(33.3, $metricsByProvider[10]['after_15_percent']);
        $this->assertTrue($metricsByProvider[10]['after_15_target_met']);
        $this->assertNotContains(
            Dashboard_metrics::STATUS_REASON_AFTER_15_GOAL_MISSED,
            $metricsByProvider[10]['status_reasons'],
        );

        $this->assertSame(33.3, $metricsByProvider[11]['after_15_percent']);
        $this->assertTrue($metricsByProvider[11]['after_15_target_met']);
        $this->assertNotContains(
            Dashboard_metrics::STATUS_REASON_AFTER_15_GOAL_MISSED,
            $metricsByProvider[11]['status_reasons'],
        );

        $this->assertSame(22.2, $metricsByProvider[12]['after_15_percent']);
        $this->assertFalse($metricsByProvider[12]['after_15_target_met']);
        $this->assertContains(
            Dashboard_metrics::STATUS_REASON_AFTER_15_GOAL_MISSED,
            $metricsByProvider[12]['status_reasons'],
        );
    }

    private function createCountResult(array $rows): CI_DB_result
    {
        /** @var CI_DB_result&MockObject $result */
        $result = $this->createMock(CI_DB_result::class);
        $result->method('result_array')->willReturn($rows);

        return $result;
    }

    private function makeService(int $serviceId): array
    {
        return [
            'id' => $serviceId,
            'duration' => 60,
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function buildDailyHoursMap(string $startDate, string $endDate, array $hours): array
    {
        $map = [];
        $day = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);

        while ($day <= $end) {
            $map[$day->format('Y-m-d')] = $hours;
            $day = $day->add(new \DateInterval('P1D'));
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function buildThresholdHours(int $before15Slots, int $after15Slots): array
    {
        return array_merge(array_fill(0, $before15Slots, '09:00'), array_fill(0, $after15Slots, '15:00'));
    }
}

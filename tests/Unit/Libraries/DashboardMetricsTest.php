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
            ->expects($this->exactly(58))
            ->method('get_offered_hours_for_analysis')
            ->willReturn(['09:00', '15:00']);

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
        $this->assertTrue($metricsByProvider[10]['after_15_evaluable']);
        $this->assertSame(29, $metricsByProvider[10]['after_15_slots']);
        $this->assertSame(58, $metricsByProvider[10]['total_offered_slots']);
        $this->assertSame(0.5, $metricsByProvider[10]['after_15_ratio']);
        $this->assertSame(50.0, $metricsByProvider[10]['after_15_percent']);
        $this->assertTrue($metricsByProvider[10]['after_15_target_met']);
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
        $expectedCalls = [
            ['2024-04-15', $selectedService, $providers[0]],
            ['2024-04-16', $selectedService, $providers[0]],
        ];
        $invocation = 0;
        $bookingSlotAnalytics
            ->expects($this->exactly(2))
            ->method('get_offered_hours_for_analysis')
            ->willReturnCallback(function (string $date, array $service, array $provider) use (
                $expectedCalls,
                &$invocation,
            ): array {
                TestCase::assertLessThan(count($expectedCalls), $invocation);
                TestCase::assertSame($expectedCalls[$invocation][0], $date);
                TestCase::assertSame($expectedCalls[$invocation][1], $service);
                TestCase::assertSame($expectedCalls[$invocation][2], $provider);

                $responses = [['09:00', '10:00', '15:00'], ['08:00', '15:30']];

                return $responses[$invocation++];
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
        $this->assertSame(2, $metrics[0]['after_15_slots']);
        $this->assertSame(5, $metrics[0]['total_offered_slots']);
        $this->assertEqualsWithDelta(0.4, $metrics[0]['after_15_ratio'], 0.0001);
        $this->assertSame(40.0, $metrics[0]['after_15_percent']);
        $this->assertTrue($metrics[0]['after_15_target_met']);
        $this->assertTrue($metrics[0]['after_15_evaluable']);
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
        $bookingSlotAnalytics->expects($this->never())->method('get_offered_hours_for_analysis');

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
        $this->assertNull($metrics[0]['after_15_slots']);
        $this->assertNull($metrics[0]['total_offered_slots']);
        $this->assertNull($metrics[0]['after_15_ratio']);
        $this->assertNull($metrics[0]['after_15_percent']);
        $this->assertNull($metrics[0]['after_15_target_met']);
        $this->assertFalse($metrics[0]['after_15_evaluable']);
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
        $bookingSlotAnalytics->expects($this->never())->method('get_offered_hours_for_analysis');

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
        $this->assertNull($metrics[0]['after_15_slots']);
        $this->assertNull($metrics[0]['total_offered_slots']);
        $this->assertNull($metrics[0]['after_15_ratio']);
        $this->assertNull($metrics[0]['after_15_percent']);
        $this->assertNull($metrics[0]['after_15_target_met']);
        $this->assertFalse($metrics[0]['after_15_evaluable']);
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
            ->expects($this->exactly(2))
            ->method('get_offered_hours_for_analysis')
            ->willReturnOnConsecutiveCalls([], []);

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
        $this->assertSame(0, $metrics[0]['after_15_slots']);
        $this->assertSame(0, $metrics[0]['total_offered_slots']);
        $this->assertNull($metrics[0]['after_15_ratio']);
        $this->assertNull($metrics[0]['after_15_percent']);
        $this->assertNull($metrics[0]['after_15_target_met']);
        $this->assertFalse($metrics[0]['after_15_evaluable']);
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
            ->method('get_offered_hours_for_analysis')
            ->willReturnCallback(static function (string $date, array $resolvedService, array $provider) use (
                $service,
            ): array {
                TestCase::assertSame('2024-04-15', $date);
                TestCase::assertSame($service, $resolvedService);

                if ((int) $provider['id'] === 11) {
                    throw new \RuntimeException('malformed provider plan');
                }

                return ['09:00', '15:00'];
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
        $this->assertSame(50.0, $metricsByProvider[10]['after_15_percent']);
        $this->assertTrue($metricsByProvider[10]['after_15_evaluable']);

        $this->assertNull($metricsByProvider[11]['after_15_slots']);
        $this->assertNull($metricsByProvider[11]['total_offered_slots']);
        $this->assertNull($metricsByProvider[11]['after_15_ratio']);
        $this->assertNull($metricsByProvider[11]['after_15_percent']);
        $this->assertNull($metricsByProvider[11]['after_15_target_met']);
        $this->assertFalse($metricsByProvider[11]['after_15_evaluable']);
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
}

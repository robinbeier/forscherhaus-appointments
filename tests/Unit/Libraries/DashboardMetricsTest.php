<?php

namespace Tests\Unit\Libraries;

use Appointments_model;
use CI_DB_query_builder;
use CI_DB_result;
use Dashboard_metrics;
use PHPUnit\Framework\MockObject\MockObject;
use Provider_utilization;
use Providers_model;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Model.php';
require_once APPPATH . 'models/Providers_model.php';
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

        /** @var Providers_model&MockObject $providersModel */
        $providersModel = $this->createMock(Providers_model::class);
        $providersModel
            ->expects($this->once())
            ->method('get_available_providers')
            ->with(false)
            ->willReturn($providers);

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
        $appointmentsModel
            ->expects($this->once())
            ->method('query')
            ->willReturn($builder);

        $library = new Dashboard_metrics($providersModel, $appointmentsModel, $providerUtilization);

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
    }

    private function createCountResult(array $rows): CI_DB_result
    {
        /** @var CI_DB_result&MockObject $result */
        $result = $this->createMock(CI_DB_result::class);
        $result->method('result_array')->willReturn($rows);

        return $result;
    }
}

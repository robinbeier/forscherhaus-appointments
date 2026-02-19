<?php

namespace Tests\Unit\Libraries;

use Appointments_model;
use Blocked_periods_model;
use CI_DB_query_builder;
use CI_DB_result;
use Provider_utilization;
use Services_model;
use Tests\TestCase;
use Unavailabilities_model;

class ProviderUtilizationTest extends TestCase
{
    public function testCalculateReturnsExpectedSlots(): void
    {
        $appointments = [
            [
                'start_datetime' => '2024-01-01 08:30:00',
                'end_datetime' => '2024-01-01 09:00:00',
            ],
        ];

        $library = $this->createLibrary($appointments);

        $provider = [
            'id' => 1,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.org',
            'services' => [1],
            'settings' => [
                'working_plan' => json_encode([
                    'monday' => [
                        'start' => '08:00',
                        'end' => '10:00',
                        'breaks' => [],
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $result = $library->calculate($provider, '2024-01-01', '2024-01-01', []);

        $this->assertSame(4, $result['total']);
        $this->assertSame(1, $result['booked']);
        $this->assertSame(3, $result['open']);
        $this->assertSame(0.25, $result['fill_rate']);
        $this->assertTrue($result['has_plan']);
    }

    public function testCalculateFlagsMissingPlan(): void
    {
        $library = $this->createLibrary([]);

        $provider = [
            'id' => 2,
            'first_name' => 'Alex',
            'last_name' => 'Smith',
            'email' => 'alex@example.org',
            'services' => [1],
            'settings' => [
                'working_plan' => json_encode([]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $result = $library->calculate($provider, '2024-01-01', '2024-01-01', []);

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['booked']);
        $this->assertSame(0, $result['open']);
        $this->assertSame(0.0, $result['fill_rate']);
        $this->assertFalse($result['has_plan']);
    }

    public function testCalculateDoesNotOvercountPartialSlots(): void
    {
        $appointments = [
            [
                'start_datetime' => '2024-01-02 08:00:00',
                'end_datetime' => '2024-01-02 08:20:00',
            ],
        ];

        $library = $this->createLibrary($appointments, 20);

        $provider = [
            'id' => 3,
            'first_name' => 'Jamie',
            'last_name' => 'Lee',
            'email' => 'jamie@example.org',
            'services' => [1],
            'settings' => [
                'working_plan' => json_encode([
                    'tuesday' => [
                        'start' => '08:00',
                        'end' => '08:30',
                        'breaks' => [],
                    ],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $result = $library->calculate($provider, '2024-01-02', '2024-01-02', []);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['booked']);
        $this->assertSame(0, $result['open']);
        $this->assertSame(1.0, $result['fill_rate']);
    }

    public function testCalculateLoadsUnavailabilityAndBlockedPeriodsOncePerRange(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($this->createAppointmentsQueryBuilder([]));

        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel->method('get')->willReturn([
            [
                'duration' => 30,
                'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            ],
        ]);

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel
            ->expects($this->once())
            ->method('get')
            ->with([
                'id_users_provider' => 1,
                'DATE(start_datetime) <=' => '2024-01-05',
                'DATE(end_datetime) >=' => '2024-01-01',
            ])
            ->willReturn([]);

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel
            ->expects($this->once())
            ->method('get_for_period')
            ->with('2024-01-01', '2024-01-05')
            ->willReturn([]);

        $library = new Provider_utilization(
            $appointmentsModel,
            $servicesModel,
            $unavailabilitiesModel,
            $blockedPeriodsModel,
        );

        $provider = [
            'id' => 1,
            'first_name' => 'Taylor',
            'last_name' => 'Jordan',
            'email' => 'taylor@example.org',
            'services' => [1],
            'settings' => [
                'working_plan' => json_encode([
                    'monday' => ['start' => '08:00', 'end' => '10:00', 'breaks' => []],
                    'tuesday' => ['start' => '08:00', 'end' => '10:00', 'breaks' => []],
                    'wednesday' => ['start' => '08:00', 'end' => '10:00', 'breaks' => []],
                    'thursday' => ['start' => '08:00', 'end' => '10:00', 'breaks' => []],
                    'friday' => ['start' => '08:00', 'end' => '10:00', 'breaks' => []],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $result = $library->calculate($provider, '2024-01-01', '2024-01-05', []);

        $this->assertSame(20, $result['total']);
        $this->assertSame(0, $result['booked']);
        $this->assertSame(20, $result['open']);
    }

    public function testCalculateCachesServiceLookupsAcrossCalls(): void
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($this->createAppointmentsQueryBuilder([]));

        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('get')
            ->with(['id' => 1], 1)
            ->willReturn([
                [
                    'duration' => 30,
                    'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
                ],
            ]);

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel->method('get')->willReturn([]);

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel->method('get_for_period')->willReturn([]);

        $library = new Provider_utilization(
            $appointmentsModel,
            $servicesModel,
            $unavailabilitiesModel,
            $blockedPeriodsModel,
        );

        $provider = [
            'id' => 1,
            'first_name' => 'Taylor',
            'last_name' => 'Jordan',
            'email' => 'taylor@example.org',
            'services' => [1],
            'settings' => [
                'working_plan' => json_encode([
                    'monday' => ['start' => '08:00', 'end' => '10:00', 'breaks' => []],
                ]),
                'working_plan_exceptions' => '{}',
            ],
        ];

        $library->calculate($provider, '2024-01-01', '2024-01-01', []);
        $library->calculate($provider, '2024-01-08', '2024-01-08', []);
    }

    private function createLibrary(array $appointments, int $serviceDuration = 30): Provider_utilization
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($this->createAppointmentsQueryBuilder($appointments));

        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel->method('get')->willReturn([
            [
                'duration' => $serviceDuration,
                'availabilities_type' => AVAILABILITIES_TYPE_FIXED,
            ],
        ]);

        $unavailabilitiesModel = $this->createMock(Unavailabilities_model::class);
        $unavailabilitiesModel->method('get')->willReturn([]);

        $blockedPeriodsModel = $this->createMock(Blocked_periods_model::class);
        $blockedPeriodsModel->method('get_for_period')->willReturn([]);

        return new Provider_utilization(
            $appointmentsModel,
            $servicesModel,
            $unavailabilitiesModel,
            $blockedPeriodsModel,
        );
    }

    private function createAppointmentsQueryBuilder(array $appointments): CI_DB_query_builder
    {
        $builder = $this->createMock(CI_DB_query_builder::class);

        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('get')->willReturn($this->createAppointmentsResult($appointments));

        return $builder;
    }

    private function createAppointmentsResult(array $appointments): CI_DB_result
    {
        $result = $this->createMock(CI_DB_result::class);
        $result->method('result_array')->willReturn($appointments);

        return $result;
    }
}

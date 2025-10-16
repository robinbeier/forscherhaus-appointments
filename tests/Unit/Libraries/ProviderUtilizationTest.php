<?php

namespace Tests\Unit\Libraries;

use Appointments_model;
use Blocked_periods_model;
use Provider_utilization;
use Services_model;
use Tests\TestCase;
use Unavailabilities_model;

class ProviderUtilizationTest extends TestCase {
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

    private function createLibrary(array $appointments): Provider_utilization
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel
            ->method('query')
            ->willReturn(new FakeAppointmentsQueryBuilder($appointments));

        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->method('get')
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

        return new Provider_utilization(
            $appointmentsModel,
            $servicesModel,
            $unavailabilitiesModel,
            $blockedPeriodsModel,
        );
    }
}

class FakeAppointmentsQueryBuilder
{
    private array $records;

    public function __construct(array $records)
    {
        $this->records = $records;
    }

    public function select($fields)
    {
        return $this;
    }

    public function where($field, $value)
    {
        return $this;
    }

    public function where_in($field, $values)
    {
        return $this;
    }

    public function get(): FakeAppointmentsResult
    {
        return new FakeAppointmentsResult($this->records);
    }
}

class FakeAppointmentsResult
{
    private array $records;

    public function __construct(array $records)
    {
        $this->records = $records;
    }

    public function result_array(): array
    {
        return $this->records;
    }
}

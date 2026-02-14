<?php

namespace Tests\Unit\Libraries;

use Appointments_model;
use CI_DB_query_builder;
use CI_DB_result;
use DateTimeImmutable;
use Dashboard_heatmap;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use Services_model;
use Tests\TestCase;

require_once APPPATH . 'libraries/Dashboard_heatmap.php';

class DashboardHeatmapTest extends TestCase
{
    public function testCollectAggregatesBookedAppointmentsIntoSlots(): void
    {
        $appointments = [
            ['start_datetime' => '2024-02-05 08:00:00'], // Monday
            ['start_datetime' => '2024-02-06 09:30:00'], // Tuesday
            ['start_datetime' => '2024-02-10 10:00:00'], // Saturday (ignored)
        ];

        $library = $this->createLibrary($appointments);

        $start = new DateTimeImmutable('2024-02-05');
        $end = new DateTimeImmutable('2024-02-09');

        $result = $library->collect($start, $end, ['statuses' => ['Booked']]);

        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame(30, $result['meta']['intervalMinutes']);

        $mondaySlot = $this->findSlot($result['slots'], 1, '08:00');
        $this->assertNotNull($mondaySlot);
        $this->assertSame(1, $mondaySlot['count']);
        $this->assertSame(50.0, $mondaySlot['percent']);

        $tuesdaySlot = $this->findSlot($result['slots'], 2, '09:30');
        $this->assertNotNull($tuesdaySlot);
        $this->assertSame(1, $tuesdaySlot['count']);
        $this->assertSame(50.0, $tuesdaySlot['percent']);
    }

    public function testCollectUsesServiceDurationForInterval(): void
    {
        $appointments = [['start_datetime' => '2024-03-04 10:15:00']];

        $servicesModel = $this->createMock(Services_model::class);
        $servicesModel
            ->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn(['duration' => 45]);

        $library = $this->createLibrary($appointments, $servicesModel);

        $start = new DateTimeImmutable('2024-03-04');
        $end = new DateTimeImmutable('2024-03-08');

        $result = $library->collect($start, $end, ['service_id' => 5]);

        $this->assertSame(45, $result['meta']['intervalMinutes']);

        $slot = $this->findSlot($result['slots'], 1, '09:45');
        $this->assertNotNull($slot);
        $this->assertSame(1, $slot['count']);
    }

    public function testCollectReturnsCachedResultWhenAvailable(): void
    {
        $cached = [
            'meta' => [
                'startDate' => '2024-01-01',
                'endDate' => '2024-01-05',
                'intervalMinutes' => 30,
                'timezone' => 'Europe/Berlin',
                'total' => 0,
                'percentile95' => 0,
                'rangeLabel' => '06:00–20:00',
            ],
            'slots' => [],
        ];

        $cache = new class ($cached) {
            private array $store;

            public function __construct(array $cached)
            {
                $this->store = ['dashboard_heatmap:' => $cached];
            }

            public function get($key)
            {
                foreach ($this->store as $cachedKey => $value) {
                    if (str_starts_with($key, $cachedKey)) {
                        return $value;
                    }
                }

                return false;
            }

            public function save($key, $value, $ttl)
            {
                $this->store[$key] = $value;

                return true;
            }
        };

        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->expects($this->never())->method('query');

        $servicesModel = $this->createMock(Services_model::class);

        $library = new Dashboard_heatmap($appointmentsModel, $servicesModel, $cache, 'Europe/Berlin');

        $start = new DateTimeImmutable('2024-01-01');
        $end = new DateTimeImmutable('2024-01-05');

        $result = $library->collect($start, $end);

        $this->assertSame($cached, $result);
    }

    private function createLibrary(array $appointments, ?Services_model $servicesModel = null): Dashboard_heatmap
    {
        $appointmentsModel = $this->createMock(Appointments_model::class);
        $appointmentsModel->method('query')->willReturn($this->createAppointmentsQueryBuilder($appointments));

        if ($servicesModel === null) {
            $servicesModel = $this->createMock(Services_model::class);
            $servicesModel->method('find')->willThrowException(new InvalidArgumentException('Not used'));
        }

        $cache = new class {
            public function get($key)
            {
                return false;
            }

            public function save($key, $value, $ttl)
            {
                return true;
            }
        };

        return new Dashboard_heatmap($appointmentsModel, $servicesModel, $cache, 'Europe/Berlin');
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    private function findSlot(array $slots, int $weekday, string $time): ?array
    {
        foreach ($slots as $slot) {
            if ((int) ($slot['weekday'] ?? 0) === $weekday && ($slot['time'] ?? '') === $time) {
                return $slot;
            }
        }

        return null;
    }

    private function createAppointmentsQueryBuilder(array $appointments): CI_DB_query_builder
    {
        /** @var CI_DB_query_builder&MockObject $builder */
        $builder = $this->createMock(CI_DB_query_builder::class);

        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('where_in')->willReturnSelf();
        $builder->method('order_by')->willReturnSelf();
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

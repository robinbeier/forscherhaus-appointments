<?php

namespace Tests\Unit\Libraries;

use Dashboard_request_dto_factory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Dashboard_request_dto_factory.php';

class DashboardRequestDtoFactoryTest extends TestCase
{
    private Dashboard_request_dto_factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Dashboard_request_dto_factory(new Request_normalizer());
    }

    #[DataProvider('validSchoolWeekPeriods')]
    public function testCreatePeriodAcceptsSchoolWeekDays(string $start, string $end): void
    {
        $dto = $this->factory->createPeriod($start, $end);
        $this->assertSame($start, $dto->start->format('Y-m-d'));
        $this->assertSame($end, $dto->end->format('Y-m-d'));
        $this->assertSame('00:00:00', $dto->start->format('H:i:s'));
    }

    public static function validSchoolWeekPeriods(): array
    {
        return [
            ['2026-09-07', '2026-09-11'],
            ['2026-09-09', '2026-09-09'],
            ['2026-09-11', '2026-09-11'],
            ['2026-09-08', '2026-09-10'],
            ['2026-12-28', '2027-01-01'],
        ];
    }

    #[DataProvider('invalidSchoolWeekPeriods')]
    public function testCreatePeriodRejectsInvalidRanges(?string $start, ?string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->createPeriod($start, $end);
    }

    public static function invalidSchoolWeekPeriods(): array
    {
        return [
            'original resource trigger' => ['1000-01-01', '9999-12-31'],
            'same week number different year' => ['2025-09-08', '2026-09-11'],
            'short crossweek' => ['2026-09-11', '2026-09-14'],
            'reversed' => ['2026-09-11', '2026-09-07'],
            'saturday' => ['2026-09-12', '2026-09-12'],
            'sunday' => ['2026-09-13', '2026-09-13'],
            'weekend end' => ['2026-09-07', '2026-09-12'],
            'weekend start' => ['2026-09-06', '2026-09-07'],
            'missing' => [null, '2026-09-11'],
            'overflow date' => ['2026-02-30', '2026-03-03'],
            'trailing time' => ['2026-09-07 00:00:00', '2026-09-11'],
        ];
    }

    public function testCreateFilterNormalizesStatusesServiceAndProviderIds(): void
    {
        $dto = $this->factory->createFilter([' Booked ', '', 'Booked', 'Cancelled'], '7', ['2', '2', '0', '8']);

        $this->assertSame(['Booked', 'Cancelled'], $dto->statuses);
        $this->assertSame(7, $dto->serviceId);
        $this->assertSame([2, 8], $dto->providerIds);
    }

    public function testCreateThresholdUsesDefaultWhenInputMissing(): void
    {
        $dto = $this->factory->createThreshold('', 0.85, 'threshold invalid');

        $this->assertSame(0.85, $dto->threshold);
    }

    public function testCreateThresholdRejectsOutOfRangeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->createThreshold('1.2', null, 'threshold invalid');
    }
}

<?php

namespace Tests\Unit\Libraries;

use Dashboard_request_dto_factory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
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

    public function testCreatePeriodReturnsTypedDateRange(): void
    {
        $dto = $this->factory->createPeriod('2026-03-01', '2026-03-31');

        $this->assertSame('2026-03-01', $dto->start->format('Y-m-d'));
        $this->assertSame('2026-03-31', $dto->end->format('Y-m-d'));
    }

    public function testCreatePeriodRejectsInvalidRanges(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->createPeriod('2026-03-31', '2026-03-01');
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

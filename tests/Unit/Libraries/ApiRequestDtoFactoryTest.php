<?php

namespace Tests\Unit\Libraries;

use Api_request_dto_factory;
use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Api_request_dto_factory.php';

class ApiRequestDtoFactoryTest extends TestCase
{
    private Api_request_dto_factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Api_request_dto_factory(new Request_normalizer());
    }

    public function testCreateCollectionQueryDtoClampsPageAndOffsetButPreservesZeroLimit(): void
    {
        $dto = $this->factory->createCollectionQueryDto('search', 0, 0, -5, 'id ASC', ['id'], ['provider']);

        $this->assertSame('search', $dto->keyword);
        $this->assertSame(0, $dto->limit);
        $this->assertSame(1, $dto->page);
        $this->assertSame(0, $dto->offset);
        $this->assertSame('id ASC', $dto->orderBy);
        $this->assertSame(['id'], $dto->fields);
        $this->assertSame(['provider'], $dto->with);
    }

    public function testCreateAppointmentsReadRequestDtoNormalizesFlagsAndIds(): void
    {
        $query = $this->factory->createCollectionQueryDto(null, 20, 1, 0, null, null, null);
        $dto = $this->factory->createAppointmentsReadRequestDto(
            $query,
            'true',
            null,
            '2026-03-03',
            '2026-03-01',
            '2026-03-31',
            '5',
            '6',
            '7',
        );

        $this->assertTrue($dto->includeBufferBlocks);
        $this->assertFalse($dto->aggregates);
        $this->assertSame('2026-03-03', $dto->date);
        $this->assertSame(5, $dto->serviceId);
        $this->assertSame(6, $dto->providerId);
        $this->assertSame(7, $dto->customerId);
    }

    public function testCreateAppointmentsReadRequestDtoKeepsAggregatesFlagWhenParameterIsPresent(): void
    {
        $query = $this->factory->createCollectionQueryDto(null, 20, 1, 0, null, null, null);
        $dto = $this->factory->createAppointmentsReadRequestDto(
            $query,
            'false',
            '0',
            null,
            null,
            null,
            null,
            null,
            null,
        );

        $this->assertTrue($dto->aggregates);
    }

    public function testCreateAppointmentsReadRequestDtoPreservesMalformedIdFilters(): void
    {
        $query = $this->factory->createCollectionQueryDto(null, 20, 1, 0, null, null, null);
        $dto = $this->factory->createAppointmentsReadRequestDto(
            $query,
            'false',
            null,
            null,
            null,
            null,
            '-1',
            'abc',
            '000',
        );

        $this->assertSame(-1, $dto->serviceId);
        $this->assertSame('abc', $dto->providerId);
        $this->assertSame('000', $dto->customerId);
    }

    public function testCreateAvailabilitiesRequestDtoFallsBackToTodayWhenDateMissing(): void
    {
        $dto = $this->factory->createAvailabilitiesRequestDto('4', '9', null);

        $this->assertSame(4, $dto->providerId);
        $this->assertSame(9, $dto->serviceId);
        $this->assertSame(date('Y-m-d'), $dto->date);
    }

    public function testCreateAvailabilitiesRequestDtoFallsBackToTodayWhenDateIsZeroString(): void
    {
        $dto = $this->factory->createAvailabilitiesRequestDto('4', '9', '0');

        $this->assertSame(4, $dto->providerId);
        $this->assertSame(9, $dto->serviceId);
        $this->assertSame(date('Y-m-d'), $dto->date);
    }
}

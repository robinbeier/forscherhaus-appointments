<?php

namespace Tests\Unit\Libraries;

use Calendar_request_dto_factory;
use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Calendar_request_dto_factory.php';

class CalendarRequestDtoFactoryTest extends TestCase
{
    private Calendar_request_dto_factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Calendar_request_dto_factory(new Request_normalizer());
    }

    public function testCreateSaveAppointmentRequestDtoNormalizesCustomerAndAppointmentPayloads(): void
    {
        $dto = $this->factory->createSaveAppointmentRequestDto(['first_name' => 'Ada'], '{"id_users_provider":5}');

        $this->assertSame(['first_name' => 'Ada'], $dto->customerData);
        $this->assertSame(['id_users_provider' => 5], $dto->appointmentData);
    }

    public function testCreateDeleteAppointmentRequestDtoNormalizesIdAndReason(): void
    {
        $dto = $this->factory->createDeleteAppointmentRequestDto('44', '  sick leave ');

        $this->assertSame(44, $dto->appointmentId);
        $this->assertSame('sick leave', $dto->cancellationReason);
    }

    public function testCreateWorkingPlanExceptionRequestDtoNormalizesDateFields(): void
    {
        $dto = $this->factory->createWorkingPlanExceptionRequestDto(
            '8',
            '2026-03-20',
            'next monday',
            '{"notes":"override"}',
        );

        $this->assertSame(8, $dto->providerId);
        $this->assertSame('2026-03-20', $dto->date);
        $this->assertSame('next monday', $dto->originalDate);
        $this->assertSame(['notes' => 'override'], $dto->workingPlanException);
    }

    public function testCreateFilterRequestDtoNormalizesRecordAndFlags(): void
    {
        $dto = $this->factory->createFilterRequestDto('record-9', 'appointments', '1');

        $this->assertSame('record-9', $dto->recordId);
        $this->assertSame('appointments', $dto->filterType);
        $this->assertTrue($dto->isAll);
    }

    public function testCreateViewRequestDtoFallsBackToDefaultView(): void
    {
        $dto = $this->factory->createViewRequestDto('', 'week');

        $this->assertSame('week', $dto->calendarView);
    }

    public function testCreateEntityIdRequestDtoNormalizesPositiveInteger(): void
    {
        $dto = $this->factory->createEntityIdRequestDto('13');

        $this->assertSame(13, $dto->id);
    }
}

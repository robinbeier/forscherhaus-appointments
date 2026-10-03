<?php

namespace Tests\Unit\Libraries;

use Calendar_request_dto_factory;
use CalendarRangeValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCreateDeleteAppointmentRequestDtoNormalizesId(): void
    {
        $dto = $this->factory->createDeleteAppointmentRequestDto('44');

        $this->assertSame(44, $dto->appointmentId);
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

    public function testCalendarRangeAllowsTheSixWeekMonthViewAndAtMostSixtyTwoDays(): void
    {
        $month = $this->factory->createRangeRequestDto('2026-11-01', '2026-12-12');
        $maximum = $this->factory->createRangeRequestDto('2026-11-01', '2027-01-01');

        $this->assertSame('2026-12-12', $month->endDate);
        $this->assertSame('2027-01-01', $maximum->endDate);
    }

    #[DataProvider('invalidCalendarRanges')]
    public function testCalendarRangeRejectsInvalidOrUnboundedIntervals(mixed $start, mixed $end): void
    {
        $this->expectException(CalendarRangeValidationException::class);

        $this->factory->createRangeRequestDto($start, $end);
    }

    public static function invalidCalendarRanges(): array
    {
        return [
            'missing start' => [null, '2026-11-01'],
            'invalid date' => ['2026-02-30', '2026-11-01'],
            'reversed range' => ['2026-11-02', '2026-11-01'],
            'sixty-three days' => ['2026-11-01', '2027-01-02'],
            'multiple years' => ['2020-01-01', '2030-01-01'],
        ];
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

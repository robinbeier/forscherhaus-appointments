<?php

namespace Tests\Unit\Libraries;

use Booking_request_dto_factory;
use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Booking_request_dto_factory.php';

class BookingRequestDtoFactoryTest extends TestCase
{
    private Booking_request_dto_factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Booking_request_dto_factory(new Request_normalizer());
    }

    public function testFromRegisterPayloadNormalizesNestedPayloadAndOptionalCustomerFields(): void
    {
        $dto = $this->factory->fromRegisterPayload(
            [
                'appointment' => [
                    'id' => 12,
                    'start_datetime' => '2026-03-10 10:00:00',
                    'id_services' => 3,
                ],
                'customer' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'email' => 'ada@example.test',
                ],
                'manage_mode' => 'yes',
            ],
            '  captcha-value ',
        );

        $this->assertSame(12, $dto->appointment['id']);
        $this->assertSame('Ada', $dto->customer['first_name']);
        $this->assertSame('', $dto->customer['address']);
        $this->assertSame('', $dto->customer['phone_number']);
        $this->assertTrue($dto->manageMode);
        $this->assertSame('captcha-value', $dto->captcha);
    }

    public function testAvailableHoursDtoPreservesAnyProviderCompatibility(): void
    {
        $anyProvider = defined('ANY_PROVIDER') ? (string) constant('ANY_PROVIDER') : 'any-provider';

        $dto = $this->factory->fromAvailableHoursPayload($anyProvider, '6', '2026-04-08', '1', '45');

        $this->assertSame($anyProvider, $dto->providerId);
        $this->assertSame(6, $dto->serviceId);
        $this->assertSame('2026-04-08', $dto->selectedDate);
        $this->assertTrue($dto->manageMode);
        $this->assertSame(45, $dto->appointmentId);
    }

    public function testUnavailableDatesDtoNormalizesIdsAndDateFallbackCompatibly(): void
    {
        $dto = $this->factory->fromUnavailableDatesPayload('9', '2', '44', 'off', ' next friday ');

        $this->assertSame(9, $dto->providerId);
        $this->assertSame(2, $dto->serviceId);
        $this->assertSame(44, $dto->appointmentId);
        $this->assertFalse($dto->manageMode);
        $this->assertSame('next friday', $dto->selectedDate);
    }
}

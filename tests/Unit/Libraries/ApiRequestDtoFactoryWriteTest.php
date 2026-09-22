<?php

namespace Tests\Unit\Libraries;

use Api_request_dto_factory;
use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Api_request_dto_factory.php';

class ApiRequestDtoFactoryWriteTest extends TestCase
{
    private Api_request_dto_factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Api_request_dto_factory(new Request_normalizer());
    }

    public function testCreateEntityWritePayloadDtoSupportsAssocArrayAndJsonPayloads(): void
    {
        $from_array = $this->factory->createEntityWritePayloadDto(['name' => 'Service A']);
        $from_json = $this->factory->createEntityWritePayloadDto('{"name":"Service B"}');
        $from_list = $this->factory->createEntityWritePayloadDto(['a', 'b']);

        $this->assertSame(['name' => 'Service A'], $from_array->payload);
        $this->assertSame(['name' => 'Service B'], $from_json->payload);
        $this->assertSame([], $from_list->payload);
    }

    public function testCreateEntityWritePayloadDtoSupportsObjectPayloads(): void
    {
        $payload = (object) ['name' => 'Service C', 'is_private' => false];
        $dto = $this->factory->createEntityWritePayloadDto($payload);
        $this->assertSame(['name' => 'Service C', 'is_private' => false], $dto->payload);
    }

    public function testCreateDateFilterDtoNormalizesDateAndCompatFallbackValues(): void
    {
        $dto = $this->factory->createDateFilterDto('2026-03-20', 'next week', null);
        $this->assertSame('2026-03-20', $dto->date);
        $this->assertSame('next week', $dto->from);
        $this->assertNull($dto->till);
    }

    public function testCreateSettingsUpdateDtoPreservesRawValueForCompatibility(): void
    {
        $dto = $this->factory->createSettingsUpdateDto(['enabled' => true]);
        $this->assertSame(['enabled' => true], $dto->value);
    }

    public function testAppointmentsPayloadAcceptsOnlyTheOpenApiWriteAllowlist(): void
    {
        $payload = $this->factory->createAppointmentsWritePayloadDto(
            json_encode(
                [
                    'start' => '2026-10-01 10:00:00',
                    'end' => '2026-10-01 10:30:00',
                    'location' => 'Synthetic',
                    'color' => '#fff',
                    'status' => 'Booked',
                    'notes' => 'notes',
                    'customerId' => 1,
                    'providerId' => 2,
                    'serviceId' => 3,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertSame(
            ['start', 'end', 'location', 'color', 'status', 'notes', 'customerId', 'providerId', 'serviceId'],
            array_keys($payload->payload),
        );
    }

    public function testAppointmentsPayloadRejectsEmptyMalformedScalarListAndProtectedFields(): void
    {
        foreach (['', '{}', '[]', '1', '{bad}', '{"id":1}', '{"hash":"secret"}', '{"book":"date"}'] as $raw) {
            try {
                $this->factory->createAppointmentsWritePayloadDto($raw);
                self::fail('Payload should be rejected: ' . $raw);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(400, $exception->getCode(), $raw);
            }
        }
    }
}

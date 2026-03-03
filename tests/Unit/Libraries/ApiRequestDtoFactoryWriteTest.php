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
}

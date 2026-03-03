<?php

namespace Tests\Unit\Libraries;

use Backoffice_request_dto_factory;
use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Backoffice_request_dto_factory.php';

class BackofficeRequestDtoFactoryTest extends TestCase
{
    private Backoffice_request_dto_factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Backoffice_request_dto_factory(new Request_normalizer());
    }

    public function testCreateSearchRequestDtoNormalizesKeywordOrderAndPagination(): void
    {
        $dto = $this->factory->createSearchRequestDto(' term ', null, '-10', '-5');

        $this->assertSame('term', $dto->keyword);
        $this->assertSame('update_datetime DESC', $dto->orderBy);
        $this->assertSame(0, $dto->limit);
        $this->assertSame(0, $dto->offset);
    }

    public function testCreateEntityPayloadRequestDtoSupportsAssocArrayAndJsonPayloads(): void
    {
        $from_array = $this->factory->createEntityPayloadRequestDto(['name' => 'Ada']);
        $from_json = $this->factory->createEntityPayloadRequestDto('{"name":"Grace"}');

        $this->assertSame(['name' => 'Ada'], $from_array->payload);
        $this->assertSame(['name' => 'Grace'], $from_json->payload);
    }

    public function testCreateEntityIdRequestDtoKeepsStringFallbackForCompatibility(): void
    {
        $int_id = $this->factory->createEntityIdRequestDto('12');
        $string_id = $this->factory->createEntityIdRequestDto('external-id');
        $empty_id = $this->factory->createEntityIdRequestDto('');

        $this->assertSame(12, $int_id->id);
        $this->assertSame('external-id', $string_id->id);
        $this->assertNull($empty_id->id);
    }

    public function testCreateSettingsRequestDtoNormalizesToAssocArray(): void
    {
        $dto = $this->factory->createSettingsRequestDto('{"timezone":"Europe/Berlin"}');

        $this->assertSame(['timezone' => 'Europe/Berlin'], $dto->settings);
    }
}

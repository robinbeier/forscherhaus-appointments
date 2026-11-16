<?php

namespace Tests\Unit\Libraries;

use Backoffice_request_dto_factory;
use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'helpers/http_helper.php';
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

    public function testCreateSearchRequestDtoClampsOversizedLimitsToTheBound(): void
    {
        $at_bound = $this->factory->createSearchRequestDto('', null, '1000', '0');
        $over_bound = $this->factory->createSearchRequestDto('', null, '1001', '0');
        $maximum_int = $this->factory->createSearchRequestDto('', null, PHP_INT_MAX, '0');
        $custom_default = $this->factory->createSearchRequestDto('', null, null, '0', 'name ASC', 5000);

        $this->assertSame(1000, $at_bound->limit);
        $this->assertSame(1000, $over_bound->limit);
        $this->assertSame(1000, $maximum_int->limit);
        $this->assertSame(1000, $custom_default->limit);
        $this->assertSame('name ASC', $custom_default->orderBy);
    }

    public function testCreateSearchRequestDtoAcceptsSearchBounds(): void
    {
        $dto = $this->factory->createSearchRequestDto(
            str_repeat('k', 128),
            implode(', ', ['first_name ASC', 'last_name DESC', 'email ASC', 'id DESC']),
            20,
            100000,
        );

        $this->assertSame(128, strlen($dto->keyword));
        $this->assertSame('first_name ASC, last_name DESC, email ASC, id DESC', $dto->orderBy);
        $this->assertSame(100000, $dto->offset);
    }

    public function testCreateSearchRequestDtoRejectsUnboundedSearchInputs(): void
    {
        $cases = [
            'keyword' => [str_repeat('k', 129), 'update_datetime DESC', 20, 0],
            'order length' => ['', str_repeat('x', 257), 20, 0],
            'order terms' => ['', 'a ASC, b ASC, c ASC, d ASC, e ASC', 20, 0],
            'order term length' => ['', str_repeat('x', 65), 20, 0],
            'empty order term' => ['', ',,,', 20, 0],
            'offset' => ['', 'update_datetime DESC', 20, 100001],
        ];

        foreach ($cases as $name => [$keyword, $orderBy, $limit, $offset]) {
            try {
                $this->factory->createSearchRequestDto($keyword, $orderBy, $limit, $offset);
                $this->fail('Expected bounded search input to be rejected: ' . $name);
            } catch (\InvalidArgumentException $exception) {
                $this->assertInstanceOf(\BackofficeSearchInputException::class, $exception, $name);
                $this->assertNotSame('', $exception->getMessage(), $name);
            }
        }
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

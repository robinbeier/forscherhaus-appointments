<?php

namespace Tests\Unit\Libraries;

use Integrations_request_dto_factory;
use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Integrations_request_dto_factory.php';

class IntegrationsRequestDtoFactoryTest extends TestCase
{
    private Integrations_request_dto_factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Integrations_request_dto_factory(new Request_normalizer());
    }

    public function testCreateWebhookCrudRequestDtoNormalizesSearchPayloadAndId(): void
    {
        $dto = $this->factory->createWebhookCrudRequestDto(
            ' hooks ',
            null,
            '100',
            '-1',
            'uuid-1',
            '{"url":"https://hooks.example.test"}',
        );

        $this->assertSame('hooks', $dto->keyword);
        $this->assertSame('update_datetime DESC', $dto->orderBy);
        $this->assertSame(100, $dto->limit);
        $this->assertSame(0, $dto->offset);
        $this->assertSame('uuid-1', $dto->webhookId);
        $this->assertSame(['url' => 'https://hooks.example.test'], $dto->webhook);
    }
}

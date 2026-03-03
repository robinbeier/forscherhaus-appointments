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

    public function testCreateCaldavConnectRequestDtoNormalizesCredentials(): void
    {
        $dto = $this->factory->createCaldavConnectRequestDto(
            '9',
            ' https://caldav.example.test ',
            ' teacher ',
            ' pass ',
        );

        $this->assertSame(9, $dto->providerId);
        $this->assertSame('https://caldav.example.test', $dto->caldavUrl);
        $this->assertSame('teacher', $dto->caldavUsername);
        $this->assertSame(' pass ', $dto->caldavPassword);
    }

    public function testCreateCaldavConnectRequestDtoKeepsUrlAndUsernameNonNull(): void
    {
        $dto = $this->factory->createCaldavConnectRequestDto('9', '   ', '   ', ' pass ');

        $this->assertSame('', $dto->caldavUrl);
        $this->assertSame('', $dto->caldavUsername);
        $this->assertSame(' pass ', $dto->caldavPassword);
    }

    public function testCreateGoogleDtosNormalizeProviderAndCalendarValues(): void
    {
        $oauth = $this->factory->createGoogleOAuthCallbackRequestDto(' code-123 ');
        $provider = $this->factory->createGoogleProviderRequestDto('11');
        $selection = $this->factory->createGoogleCalendarSelectionRequestDto('11', ' primary ');

        $this->assertSame('code-123', $oauth->code);
        $this->assertSame(11, $provider->providerId);
        $this->assertSame(11, $selection->providerId);
        $this->assertSame('primary', $selection->calendarId);
    }

    public function testCreateLdapSearchRequestDtoPreservesKeywordWhitespaceCompat(): void
    {
        $dto = $this->factory->createLdapSearchRequestDto('  Ada  ');

        $this->assertSame('  Ada  ', $dto->keyword);
    }

    public function testCreateLdapSearchRequestDtoPreservesWhitespaceOnlySearchTerm(): void
    {
        $dto = $this->factory->createLdapSearchRequestDto('   ');

        $this->assertSame('   ', $dto->keyword);
    }

    public function testCreateLdapSearchRequestDtoConvertsNullToEmptyString(): void
    {
        $dto = $this->factory->createLdapSearchRequestDto(null);

        $this->assertSame('', $dto->keyword);
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

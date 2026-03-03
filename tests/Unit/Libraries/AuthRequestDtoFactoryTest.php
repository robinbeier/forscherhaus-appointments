<?php

namespace Tests\Unit\Libraries;

use Auth_request_dto_factory;
use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Auth_request_dto_factory.php';

class AuthRequestDtoFactoryTest extends TestCase
{
    private Auth_request_dto_factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Auth_request_dto_factory(new Request_normalizer());
    }

    public function testCreateLoginAndRecoveryDtosTrimCredentials(): void
    {
        $login = $this->factory->createLoginValidateRequestDto('  user ', ' secret ');
        $recovery = $this->factory->createRecoveryRequestDto('  user ', ' school@example.test ');

        $this->assertSame('user', $login->username);
        $this->assertSame('secret', $login->password);
        $this->assertSame('user', $recovery->username);
        $this->assertSame('school@example.test', $recovery->email);
    }

    public function testCreateAccountSaveRequestDtoNormalizesAssocPayload(): void
    {
        $dto = $this->factory->createAccountSaveRequestDto('{"email":"admin@example.test"}');

        $this->assertSame(['email' => 'admin@example.test'], $dto->account);
    }

    public function testCreateValidateUsernameRequestDtoNormalizesUsernameAndUserId(): void
    {
        $dto = $this->factory->createValidateUsernameRequestDto('  robin ', '15');

        $this->assertSame('robin', $dto->username);
        $this->assertSame(15, $dto->userId);
    }

    public function testCreateLocalizationPrivacyAndConsentDtosNormalizeValues(): void
    {
        $localization = $this->factory->createLocalizationRequestDto(' de ');
        $privacy = $this->factory->createPrivacyDeleteRequestDto(' token-123 ');
        $consent = $this->factory->createConsentSaveRequestDto('{"accepted":true}');

        $this->assertSame('de', $localization->language);
        $this->assertSame('token-123', $privacy->customerToken);
        $this->assertSame(['accepted' => true], $consent->consent);
    }
}

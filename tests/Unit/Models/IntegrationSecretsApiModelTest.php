<?php

namespace Tests\Unit\Models;

use InvalidArgumentException;
use Providers_model;
use Tests\TestCase;

final class IntegrationSecretsApiModelTest extends TestCase
{
    private Providers_model $providersModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('providers_model');
        $this->providersModel = $CI->providers_model;
    }

    public function testProviderIntegrationCredentialsAcceptOnlyNullableStrings(): void
    {
        foreach (['googleToken', 'caldavPassword'] as $field) {
            $provider = [
                'settings' => [
                    $field => ['synthetic-sensitive-value'],
                ],
            ];

            try {
                $this->providersModel->api_decode($provider);
                $this->fail('Expected the invalid provider integration credential type to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertTrue(str_contains($exception->getMessage(), 'must be a string or null'));
                $this->assertFalse(str_contains($exception->getMessage(), 'synthetic-sensitive-value'));
            }
        }
    }

    public function testProviderIntegrationCredentialsAreStorageLengthBounded(): void
    {
        foreach (
            [
                'googleToken' => str_repeat('x', 65536),
                'caldavPassword' => str_repeat('x', 257),
            ]
            as $field => $invalidValue
        ) {
            try {
                $provider = ['settings' => [$field => $invalidValue]];
                $this->providersModel->api_decode($provider);
                $this->fail('Expected the oversized provider integration credential to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertTrue(str_contains($exception->getMessage(), 'maximum length'));
                $this->assertFalse(str_contains($exception->getMessage(), $invalidValue));
            }
        }
    }

    public function testValidationErrorsDoNotContainCredentialValues(): void
    {
        $provider = [
            'settings' => [
                'google_token' => 'synthetic-sensitive-value',
                'caldav_password' => 'synthetic-sensitive-value',
            ],
        ];
        try {
            $this->providersModel->validate($provider);
            $this->fail('Expected the incomplete provider record to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertFalse(str_contains($exception->getMessage(), 'synthetic-sensitive-value'));
        }
    }
}

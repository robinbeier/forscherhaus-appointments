<?php

namespace Tests\Unit\Models;

use InvalidArgumentException;
use Providers_model;
use Tests\TestCase;
use Webhooks_model;

final class IntegrationSecretsApiModelTest extends TestCase
{
    private Providers_model $providersModel;

    private Webhooks_model $webhooksModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('providers_model');
        $CI->load->model('webhooks_model');
        $this->providersModel = $CI->providers_model;
        $this->webhooksModel = $CI->webhooks_model;
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

    public function testWebhookSecretInputIsTypeAndLengthBounded(): void
    {
        foreach ([['synthetic-sensitive-value'], str_repeat('x', 513)] as $invalidValue) {
            $webhook = ['secretToken' => $invalidValue];

            try {
                $this->webhooksModel->api_decode($webhook);
                $this->fail('Expected the invalid webhook credential to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertTrue(
                    str_contains($exception->getMessage(), 'string or null') ||
                        str_contains($exception->getMessage(), 'must not exceed 512 characters'),
                );
                $this->assertFalse(str_contains($exception->getMessage(), 'synthetic-sensitive-value'));
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
        $webhook = ['secret_token' => 'synthetic-sensitive-value'];

        foreach (
            [fn() => $this->providersModel->validate($provider), fn() => $this->webhooksModel->validate($webhook)]
            as $validate
        ) {
            try {
                $validate();
                $this->fail('Expected the incomplete record to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertFalse(str_contains($exception->getMessage(), 'synthetic-sensitive-value'));
            }
        }
    }

    public function testWebhookCredentialIsNotAnApiResourceField(): void
    {
        $this->assertNull($this->webhooksModel->db_field('secretToken'));
        $this->assertNull($this->webhooksModel->db_field('secret_token'));
    }
}

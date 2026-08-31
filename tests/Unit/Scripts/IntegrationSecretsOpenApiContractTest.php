<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class IntegrationSecretsOpenApiContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $spec;

    public static function setUpBeforeClass(): void
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/openapi.yml');
        self::assertIsArray($spec);
        self::$spec = $spec;
    }

    public function testRecordSchemasDoNotExposeIntegrationCredentials(): void
    {
        $providerSettings = $this->schemaProperties('ProviderRecord')['settings']['properties'] ?? null;
        self::assertIsArray($providerSettings);
        self::assertFalse(array_key_exists('googleToken', $providerSettings));
        self::assertFalse(array_key_exists('caldavPassword', $providerSettings));

        $webhookProperties = $this->schemaProperties('WebhookRecord');
        self::assertFalse(array_key_exists('secretToken', $webhookProperties));
    }

    public function testPayloadSchemasDeclareIntegrationCredentialsWriteOnly(): void
    {
        $providerSettings = $this->schemaProperties('ProviderPayload')['settings']['properties'] ?? null;
        self::assertIsArray($providerSettings);

        foreach (['googleToken', 'caldavPassword'] as $field) {
            $property = $providerSettings[$field] ?? null;
            self::assertIsArray($property);
            self::assertSame('string', $property['type'] ?? null);
            self::assertTrue(($property['nullable'] ?? null) === true);
            self::assertTrue(($property['writeOnly'] ?? null) === true);
        }

        self::assertSame(65535, $providerSettings['googleToken']['maxLength'] ?? null);
        self::assertSame(256, $providerSettings['caldavPassword']['maxLength'] ?? null);

        $webhookSecret = $this->schemaProperties('WebhookPayload')['secretToken'] ?? null;
        self::assertIsArray($webhookSecret);
        self::assertSame('string', $webhookSecret['type'] ?? null);
        self::assertTrue(($webhookSecret['nullable'] ?? null) === true);
        self::assertTrue(($webhookSecret['writeOnly'] ?? null) === true);
        self::assertSame(512, $webhookSecret['maxLength'] ?? null);
    }

    public function testExamplesDoNotContainIntegrationCredentials(): void
    {
        $providerRecordSettings = $this->schemaExample('ProviderRecord')['settings'] ?? null;
        self::assertIsArray($providerRecordSettings);
        self::assertFalse(array_key_exists('googleToken', $providerRecordSettings));
        self::assertFalse(array_key_exists('caldavPassword', $providerRecordSettings));

        $providerPayloadSettings = $this->schemaExample('ProviderPayload')['settings'] ?? null;
        self::assertIsArray($providerPayloadSettings);
        self::assertFalse(array_key_exists('googleToken', $providerPayloadSettings));
        self::assertFalse(array_key_exists('caldavPassword', $providerPayloadSettings));

        $webhookRecord = $this->schemaExample('WebhookRecord');
        $webhookPayload = $this->schemaExample('WebhookPayload');
        self::assertFalse(array_key_exists('secretToken', $webhookRecord));
        self::assertFalse(array_key_exists('secretToken', $webhookPayload));
    }

    /** @return array<string, mixed> */
    private function schemaProperties(string $schema): array
    {
        $properties = self::$spec['components']['schemas'][$schema]['properties'] ?? null;
        self::assertIsArray($properties);

        return $properties;
    }

    /** @return array<string, mixed> */
    private function schemaExample(string $schema): array
    {
        $example = self::$spec['components']['schemas'][$schema]['example'] ?? null;
        self::assertIsArray($example);

        return $example;
    }
}

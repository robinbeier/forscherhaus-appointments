<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the Settings API v1 read aliases. */
final class SettingsApiReadAliasMethodHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array{admin_username:string,provider_username:string,password:string,token:string} */
    private array $credentials = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testOwnedSettingReadControlsWorkForAdminBasicAndBearerOnCanonicalAndDirectRoutes(): void
    {
        $fixture = $this->requireFixture();
        $setting = $fixture->ownedSetting('read-alias', 'before');
        $clients = [$this->adminClient(), $this->bearerClient()];

        foreach ($clients as $client) {
            foreach (['api/v1/settings', 'api/v1/settings_api_v1/index'] as $path) {
                $response = $client->get($path, ['q' => $setting['name']]);
                self::assertSame(200, $response->statusCode, $path);
                self::assertContains(
                    ['name' => $setting['name'], 'value' => 'before'],
                    $this->jsonList($response, 'GET ' . $path),
                    $path . ' must expose the owned setting through GET.',
                );
            }

            foreach (
                ['api/v1/settings/' . $setting['name'], 'api/v1/settings_api_v1/show/' . $setting['name']]
                as $path
            ) {
                $response = $client->get($path);
                self::assertSame(200, $response->statusCode, $path);
                self::assertSame(
                    ['name' => $setting['name'], 'value' => 'before'],
                    $this->jsonObject($response, 'GET ' . $path),
                );
            }
        }
    }

    public function testUnauthenticatedDirectReadAliasesRejectWithoutSyntheticValues(): void
    {
        $fixture = $this->requireFixture();
        $setting = $fixture->ownedSetting('unauthenticated', 'before');
        $client = $this->server->client();

        foreach (['api/v1/settings_api_v1/index', 'api/v1/settings_api_v1/show/' . $setting['name']] as $path) {
            $response = $client->get($path, $path === 'api/v1/settings_api_v1/index' ? ['q' => $setting['name']] : []);
            self::assertSame(401, $response->statusCode, $path . ' must require authentication.');
            self::assertNotEmpty((string) $response->header('www-authenticate'));
            self::assertStringNotContainsString($fixture->run, $response->body, $path);
        }
    }

    public function testDirectReadAliasesRejectNonGetMethodsWithoutMutation(): void
    {
        $fixture = $this->requireFixture();
        $setting = $fixture->ownedSetting('wrong-method', 'before');
        $before = $fixture->settingRow((int) $setting['id']);
        $admin = $this->adminClient();
        $paths = ['api/v1/settings_api_v1/index', 'api/v1/settings_api_v1/show/' . $setting['name']];

        foreach ($paths as $path) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
                $response = match ($method) {
                    'POST', 'PUT' => $admin->requestJsonApp($method, $path, []),
                    default => $admin->requestApp($method, $path),
                };
                self::assertSame(405, $response->statusCode, $method . ' ' . $path);
                self::assertSame('GET', $response->header('allow'), $method . ' ' . $path);
                self::assertSame('', $response->body, $method . ' ' . $path . ' must not emit setting data.');
                self::assertStringNotContainsString($fixture->run, $response->body, $method . ' ' . $path);
                self::assertSame($before, $fixture->settingRow((int) $setting['id']));
            }
        }
    }

    private function requireFixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);

        return $this->fixture;
    }

    private function adminClient(): GateHttpClient
    {
        self::assertNotNull($this->server);

        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            ],
        );
    }

    private function bearerClient(): GateHttpClient
    {
        self::assertNotNull($this->server);

        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Bearer ' . $this->credentials['token']],
        );
    }

    /** @return list<array<string, mixed>> */
    private function jsonList(GateHttpResponse $response, string $operation): array
    {
        $value = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value, $operation);
        self::assertSame(array_keys($value), array_keys(array_values($value)), $operation);

        return $value;
    }

    /** @return array<string, mixed> */
    private function jsonObject(GateHttpResponse $response, string $operation): array
    {
        $value = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value, $operation);
        self::assertNotSame(array_keys($value), array_keys(array_values($value)), $operation);

        return $value;
    }
}

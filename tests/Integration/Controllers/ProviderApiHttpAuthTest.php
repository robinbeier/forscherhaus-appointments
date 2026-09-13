<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Ordinary local HTTP authentication coverage for the provider read routes. */
final class ProviderApiHttpAuthTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }
        $this->fixture = new DefenseCycleFixtures();
        $this->fixture->create();
        $this->credentials = $this->fixture->enableProviderHttpAuth();
        $this->server = new DefenseCycleHttpServer();
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testProviderListAndDetailAuthenticationAndSecretProjection(): void
    {
        $provider = $this->fixture->row('users', $this->fixture->providerId);
        $identity = [
            'firstName' => (string) $provider['first_name'],
            'lastName' => (string) $provider['last_name'],
            'email' => (string) $provider['email'],
        ];

        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $this->assertSuccessfulProviderReads($admin, $identity, 'admin-basic');
        $this->assertUnauthorizedProviderReads($this->server->client(), 'no-auth');
        $this->assertUnauthorizedProviderReads(
            $this->basicClient($this->credentials['admin_username'], 'synthetic-wrong-password'),
            'wrong-admin-password',
        );
        $this->assertUnauthorizedProviderReads(
            $this->basicClient($this->credentials['provider_username'], $this->credentials['password']),
            'provider-basic',
        );
        $this->assertUnauthorizedProviderReads(
            $this->bearerClient($this->credentials['token'] . '-wrong'),
            'wrong-bearer',
        );
        $this->assertSuccessfulProviderReads(
            $this->bearerClient($this->credentials['token']),
            $identity,
            'global-bearer',
        );
    }

    private function assertSuccessfulProviderReads(
        \ReleaseGate\GateHttpClient $client,
        array $identity,
        string $case,
    ): void {
        $list = $client->get('api/v1/providers', ['q' => $this->fixture->run, 'length' => 20]);
        $listData = $this->decodeSuccess($list);
        self::assertNotEmpty($listData, $case . ' provider list must be nonempty.');
        $matches = array_values(
            array_filter(
                $listData,
                fn(mixed $item): bool => is_array($item) && (int) ($item['id'] ?? 0) === $this->fixture->providerId,
            ),
        );
        self::assertCount(1, $matches, $case . ' provider list must contain the owned fixture.');
        $this->assertProviderIdentity($matches[0], $identity);
        $this->assertProviderSecretsAbsent($matches[0]);

        $detail = $client->get('api/v1/providers/' . $this->fixture->providerId);
        $detailData = $this->decodeSuccess($detail);
        $this->assertProviderIdentity($detailData, $identity);
        $this->assertProviderSecretsAbsent($detailData);
    }

    private function assertUnauthorizedProviderReads(\ReleaseGate\GateHttpClient $client, string $case): void
    {
        foreach (['api/v1/providers', 'api/v1/providers/' . $this->fixture->providerId] as $path) {
            $response = $client->get($path);
            self::assertSame(401, $response->statusCode, $case . ' provider request must be denied.');
            self::assertNotNull($response->header('www-authenticate'), $case . ' must challenge with Basic auth.');
            self::assertFalse(
                str_contains($response->body, $this->fixture->run),
                $case . ' must not return fixture data.',
            );
        }
    }

    private function basicClient(string $username, string $password): \ReleaseGate\GateHttpClient
    {
        return new \ReleaseGate\GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Basic ' . base64_encode($username . ':' . $password)],
        );
    }

    private function bearerClient(string $token): \ReleaseGate\GateHttpClient
    {
        return new \ReleaseGate\GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Bearer ' . $token],
        );
    }

    private function decodeSuccess(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($data), 'HTTP response must decode to an array.');
        self::assertFalse(array_key_exists('exception', $data), 'HTTP response must not be an error object.');
        // Normalize JSON escaping and inspect the entire response, including nested fields and other list items.
        $normalizedBody = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['_google_integration', '_caldav_integration'] as $suffix) {
            self::assertFalse(
                str_contains($normalizedBody, $this->fixture->run . $suffix),
                'Successful HTTP response must not contain a seeded integration secret value.',
            );
        }
        return $data;
    }

    private function assertProviderIdentity(array $provider, array $identity): void
    {
        self::assertSame($this->fixture->providerId, (int) ($provider['id'] ?? 0));
        self::assertSame($identity['firstName'], $provider['firstName'] ?? null);
        self::assertSame($identity['lastName'], $provider['lastName'] ?? null);
        self::assertSame($identity['email'], $provider['email'] ?? null);
    }

    private function assertProviderSecretsAbsent(array $provider): void
    {
        $settings = $provider['settings'] ?? [];
        self::assertIsArray($settings, 'Provider settings projection must be an array.');
        foreach (['googleToken', 'caldavPassword', 'google_token', 'caldav_password'] as $key) {
            self::assertFalse(
                array_key_exists($key, $settings),
                'Provider settings must omit integration secret keys.',
            );
            self::assertFalse(
                array_key_exists($key, $provider),
                'Provider must omit top-level integration secret keys.',
            );
            self::assertArrayNotHasKey($key, $provider);
        }
    }
}

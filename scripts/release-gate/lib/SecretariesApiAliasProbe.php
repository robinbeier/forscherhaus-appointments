<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded localhost proof for Secretary direct store/destroy aliases. */
final class SecretariesApiAliasProbe
{
    public function __construct(
        private readonly GateHttpClient $client,
        private readonly DefenseVerificationFixture $fixture,
    ) {}

    public static function forApp(
        string $baseUrl,
        string $username,
        string $password,
        DefenseVerificationFixture $fixture,
        string $indexPage = 'index.php',
    ): self {
        if ($username === '' || $password === '') {
            throw new RuntimeException('Secretaries API probe credentials are unavailable.');
        }
        return new self(
            new GateHttpClient(
                $baseUrl,
                indexPage: $indexPage,
                additionalHeaders: [
                    'X-FH-Ordinary-Probe' => '1',
                    'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                ],
            ),
            $fixture,
        );
    }

    public function run(?callable $observe = null): array
    {
        $observe ??= static function (string $phase, string $outcome): void {};
        $state = $this->fixture->read();
        if (($state['profile'] ?? null) !== 'secretaries_api') {
            throw new RuntimeException('Secretaries API verification requires its dedicated graph.');
        }
        $before = $this->fixture->secretariesApiSnapshot();
        $targetId = (int) ($before['target']['user']['id'] ?? 0);
        $sentinelId = (int) ($before['sentinel']['user']['id'] ?? 0);
        if ($targetId < 1 || $sentinelId < 1 || $targetId === $sentinelId) {
            throw new RuntimeException('Secretary API targets are unavailable.');
        }
        $targetPayload = [
            'firstName' => 'Must Not Persist',
            'lastName' => 'Must Not Persist',
            'email' => (string) ($before['target']['user']['email'] ?? ''),
            'providers' => [$this->providerId($before['target'])],
            'settings' => ['username' => (string) ($before['target']['settings']['username'] ?? '')],
        ];

        $this->phase($observe, 'secretaries_api_store_alias', function () use ($targetPayload, $before): void {
            $response = $this->client->requestJsonApp('PUT', 'api/v1/secretaries_api_v1/store', $targetPayload);
            $this->expectStatus($response, 405, 'direct PUT store alias');
            $this->expectAllow($response, 'POST', 'direct PUT store alias');
            $this->assertSnapshot($before, 'direct PUT store alias');
        });
        $this->fixture->beginSecretariesApiDestroyAlias();
        $this->phase($observe, 'secretaries_api_destroy_alias', function () use ($targetId, $before): void {
            $response = $this->client->requestApp('GET', 'api/v1/secretaries_api_v1/destroy/' . $targetId);
            $this->expectStatus($response, 405, 'direct GET destroy alias');
            $this->expectAllow($response, 'DELETE', 'direct GET destroy alias');
            $this->assertSnapshot($before, 'direct GET destroy alias');
        });
        $this->fixture->completeSecretariesApiDestroyAlias();

        return [
            'status' => 'verified',
            'coverage' => 'secretary_store_and_destroy_alias_method_boundaries',
            'store_wrong_verb_status' => 405,
            'destroy_wrong_verb_status' => 405,
            'observed' =>
                'Owned target and sentinel Secretary snapshots, including settings and provider links, remained unchanged after both wrong-verb alias requests.',
        ];
    }

    private function providerId(array $snapshot): int
    {
        $providers = $snapshot['providers'] ?? [];
        $providerId = is_array($providers) ? (int) ($providers[0]['id_users_provider'] ?? 0) : 0;
        if ($providerId < 1) {
            throw new RuntimeException('Secretary API provider relationship is unavailable.');
        }
        return $providerId;
    }

    private function phase(callable $observe, string $phase, callable $operation): void
    {
        $observe($phase, 'started');
        try {
            $operation();
            $observe($phase, 'passed');
        } catch (\Throwable $error) {
            $observe($phase, 'failed');
            throw $error;
        }
    }

    private function assertSnapshot(array $expected, string $context): void
    {
        if ($this->fixture->secretariesApiSnapshot() !== $expected) {
            throw new RuntimeException('Secretaries API ' . $context . ' changed an owned row.');
        }
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $context): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException('Secretaries API ' . $context . ' returned an unexpected status.');
        }
    }

    private function expectAllow(GateHttpResponse $response, string $expected, string $context): void
    {
        if ($response->header('Allow') !== $expected) {
            throw new RuntimeException('Secretaries API ' . $context . ' did not advertise ' . $expected . '.');
        }
    }
}

<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded localhost proof for Secretary API read and direct store/destroy aliases. */
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
        $targetEmail = (string) ($before['target']['user']['email'] ?? '');
        if ($targetEmail === '') {
            throw new RuntimeException('Secretary API target email is unavailable.');
        }

        $this->phase($observe, 'secretaries_api_read_aliases', function () use (
            $targetId,
            $targetEmail,
            $before,
        ): void {
            foreach (['api/v1/secretaries', 'api/v1/secretaries_api_v1/index'] as $path) {
                $response = $this->client->get($path, ['q' => $targetEmail]);
                $rows = $this->decodeJson($response, $path . ' GET');
                $matches = array_values(
                    array_filter(
                        $rows,
                        static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $targetId,
                    ),
                );
                if (count($rows) !== 1 || count($matches) !== 1) {
                    throw new RuntimeException(
                        'Secretaries API ' . $path . ' GET did not return exactly one filtered row.',
                    );
                }
                $this->assertSecretaryProjection($matches[0], $before['target'], $path . ' GET');
                $this->assertSnapshot($before, $path . ' GET');
            }
            foreach (['api/v1/secretaries/' . $targetId, 'api/v1/secretaries_api_v1/show/' . $targetId] as $path) {
                $response = $this->client->get($path);
                $row = $this->decodeJson($response, $path . ' GET');
                $this->assertSecretaryProjection($row, $before['target'], $path . ' GET');
                $this->assertSnapshot($before, $path . ' GET');
            }
        });

        $this->phase($observe, 'secretaries_api_read_method_boundaries', function () use ($targetId, $before): void {
            foreach (['api/v1/secretaries_api_v1/index'] as $path) {
                $this->assertWrongReadMethods($path, $before);
            }
            foreach (['api/v1/secretaries_api_v1/show/' . $targetId] as $path) {
                $this->assertWrongReadMethods($path, $before);
            }
        });

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
            'coverage' => 'secretary_read_projection_and_method_boundaries_store_and_destroy_alias_method_boundaries',
            'read_status' => 200,
            'read_wrong_verb_status' => 405,
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

    /** @return array<string,mixed>|array<int,array<string,mixed>> */
    private function decodeJson(GateHttpResponse $response, string $context): array
    {
        $this->expectStatus($response, 200, $context);
        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Secretaries API ' . $context . ' returned invalid JSON.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Secretaries API ' . $context . ' returned an invalid payload.');
        }
        return $decoded;
    }

    private function assertSecretaryProjection(array $row, array $snapshot, string $context): void
    {
        $expectedKeys = [
            'id',
            'firstName',
            'lastName',
            'email',
            'mobile',
            'phone',
            'address',
            'city',
            'state',
            'zip',
            'notes',
            'providers',
            'timezone',
            'language',
            'ldapDn',
            'settings',
        ];
        if (array_diff($expectedKeys, array_keys($row)) !== [] || array_diff(array_keys($row), $expectedKeys) !== []) {
            throw new RuntimeException('Secretaries API ' . $context . ' returned an unexpected projection.');
        }
        $user = $snapshot['user'] ?? [];
        $settings = $snapshot['settings'] ?? [];
        $providers = $snapshot['providers'] ?? [];
        $expectedProviders = array_map(
            static fn(array $provider): int => (int) ($provider['id_users_provider'] ?? 0),
            is_array($providers) ? $providers : [],
        );
        sort($expectedProviders, SORT_NUMERIC);
        $actualProviders = is_array($row['providers']) ? array_map('intval', $row['providers']) : [];
        sort($actualProviders, SORT_NUMERIC);
        $expected = [
            'id' => (int) ($user['id'] ?? 0),
            'firstName' => $user['first_name'] ?? null,
            'lastName' => $user['last_name'] ?? null,
            'email' => $user['email'] ?? null,
            'mobile' => $user['mobile_number'] ?? null,
            'phone' => $user['phone_number'] ?? null,
            'address' => $user['address'] ?? null,
            'city' => $user['city'] ?? null,
            'state' => $user['state'] ?? null,
            'zip' => $user['zip_code'] ?? null,
            'notes' => $user['notes'] ?? null,
            'timezone' => $user['timezone'] ?? null,
            'language' => $user['language'] ?? null,
            'ldapDn' => $user['ldap_dn'] ?? null,
            'settings' => [
                'username' => $settings['username'] ?? null,
                'calendarView' => $settings['calendar_view'] ?? null,
            ],
        ];
        foreach ($expected as $key => $value) {
            if ($row[$key] !== $value) {
                throw new RuntimeException('Secretaries API ' . $context . ' returned an unexpected ' . $key . '.');
            }
        }
        if (
            $actualProviders !== $expectedProviders ||
            array_key_exists('password', $row) ||
            array_key_exists('salt', $row)
        ) {
            throw new RuntimeException('Secretaries API ' . $context . ' exposed unexpected secretary data.');
        }
    }

    private function assertWrongReadMethods(string $path, array $before): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            $response = in_array($method, ['POST', 'PUT'], true)
                ? $this->client->requestJsonApp($method, $path, [])
                : $this->client->requestApp($method, $path);
            $this->expectStatus($response, 405, $path . ' ' . $method);
            $this->expectAllow($response, 'GET', $path . ' ' . $method);
            if ($response->body !== '') {
                throw new RuntimeException('Secretaries API ' . $path . ' ' . $method . ' emitted a response body.');
            }
            $this->assertSnapshot($before, $path . ' ' . $method);
        }
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

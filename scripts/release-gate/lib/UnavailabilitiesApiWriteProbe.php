<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded HTTP proof for the Unavailabilities API v1 write boundaries. */
final class UnavailabilitiesApiWriteProbe
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
            throw new RuntimeException('Unavailabilities API probe credentials are unavailable.');
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
        if (($state['profile'] ?? null) !== 'unavailabilities_api') {
            throw new RuntimeException('Unavailabilities API verification requires its dedicated graph.');
        }
        $before = $this->fixture->unavailabilitiesApiSnapshot();
        $a = (int) ($before['a']['id'] ?? 0);
        $b = (int) ($before['b']['id'] ?? 0);
        if ($a < 1 || $b < 1 || $a === $b) {
            throw new RuntimeException('Unavailabilities API targets are unavailable.');
        }
        $this->phase($observe, 'unavailabilities_api_conflict_put', function () use ($a, $b, $before): void {
            $response = $this->client->requestJsonApp(
                'PUT',
                'api/v1/unavailabilities/' . $a,
                $this->payload($before['b'], $b),
            );
            $this->expectStatus($response, 400, 'conflicting PUT');
            $this->assertSnapshot($before, 'conflicting PUT');
        });
        $after = null;
        $this->phase($observe, 'unavailabilities_api_matching_put', function () use ($a, $before, &$after): void {
            $payload = $this->payload($before['a'], $a);
            $payload['start'] = date('Y-m-d H:i:s', strtotime((string) $payload['start']) + 600);
            $payload['end'] = date('Y-m-d H:i:s', strtotime((string) $payload['end']) + 600);
            $response = $this->client->requestJsonApp('PUT', 'api/v1/unavailabilities/' . $a, $payload);
            $this->expectStatus($response, 200, 'matching PUT');
            $body = $this->decodeObject($response->body);
            if ((int) ($body['id'] ?? 0) !== $a) {
                throw new RuntimeException('Unavailabilities API matching PUT returned the wrong row.');
            }
            $after = $this->fixture->unavailabilitiesApiSnapshot();
            $expectedA = $before['a'];
            $expectedA['start_datetime'] = $payload['start'];
            $expectedA['end_datetime'] = $payload['end'];
            $observedA = $after['a'];
            if (
                !array_key_exists('update_datetime', $expectedA) ||
                !array_key_exists('update_datetime', $observedA) ||
                !is_string($observedA['update_datetime']) ||
                $observedA['update_datetime'] === ''
            ) {
                throw new RuntimeException('Unavailabilities API matching PUT has no update timestamp.');
            }
            $expectedA['update_datetime'] = $observedA['update_datetime'];
            if ($observedA !== $expectedA) {
                throw new RuntimeException('Unavailabilities API matching PUT changed an unexpected A field.');
            }
            if (
                $after['b'] !== $before['b'] ||
                $after['ordinary'] !== $before['ordinary'] ||
                $after['buffers'] !== $before['buffers']
            ) {
                throw new RuntimeException('Unavailabilities API matching PUT changed an unexpected row.');
            }
        });
        if (!is_array($after)) {
            throw new RuntimeException('Unavailabilities API positive snapshot is unavailable.');
        }
        $this->phase($observe, 'unavailabilities_api_wrong_verb_destroy', function () use ($a, $after): void {
            $response = $this->client->requestApp('GET', 'api/v1/unavailabilities_api_v1/destroy/' . $a);
            $this->expectStatus($response, 405, 'wrong-verb destroy alias');
            $this->assertSnapshot($after, 'wrong-verb destroy alias');
        });
        return [
            'status' => 'verified',
            'coverage' => 'put_target_binding_method_boundary_buffers',
            'conflict_status' => 400,
            'matching_status' => 200,
            'wrong_verb_status' => 405,
            'observed' =>
                'A conflicting body ID returned 400 without mutation; a matching PUT shifted only A by ten minutes; the wrong-verb destroy alias returned 405; the ordinary appointment and generated buffers remained unchanged.',
        ];
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
        $current = $this->fixture->unavailabilitiesApiSnapshot();
        foreach (['a', 'b', 'ordinary', 'buffers'] as $key) {
            if ($current[$key] !== $expected[$key]) {
                throw new RuntimeException('Unavailabilities API ' . $context . ' changed ' . $key . '.');
            }
        }
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $context): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException('Unavailabilities API ' . $context . ' returned an unexpected status.');
        }
    }

    private function payload(array $row, int $id): array
    {
        return [
            'id' => $id,
            'start' => $row['start_datetime'],
            'end' => $row['end_datetime'],
            'location' => $row['location'],
            'color' => $row['color'],
            'status' => $row['status'],
            'notes' => (string) $row['notes'],
            'providerId' => (int) $row['id_users_provider'],
        ];
    }

    private function decodeObject(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Unavailabilities API PUT returned invalid JSON.');
        }
        return $decoded;
    }
}

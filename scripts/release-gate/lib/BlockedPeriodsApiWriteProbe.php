<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded HTTP proof for the Blocked Periods API v1 write boundaries. */
final class BlockedPeriodsApiWriteProbe
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
            throw new RuntimeException('Blocked Periods API probe credentials are unavailable.');
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
        if (($state['profile'] ?? null) !== 'blocked_periods_api') {
            throw new RuntimeException('Blocked Periods API verification requires its dedicated graph.');
        }
        $before = $this->fixture->blockedPeriodsApiSnapshot();
        foreach (['a', 'b'] as $label) {
            $start = strtotime((string) ($before[$label]['start_datetime'] ?? ''));
            $end = strtotime((string) ($before[$label]['end_datetime'] ?? ''));
            if ($start === false || $end === false || $start >= $end || $end >= time() - 30 * 86400) {
                throw new RuntimeException('Blocked Periods API synthetic window is not safely in the past.');
            }
        }
        $a = (int) ($before['a']['id'] ?? 0);
        $b = (int) ($before['b']['id'] ?? 0);
        if ($a < 1 || $b < 1 || $a === $b) {
            throw new RuntimeException('Blocked Periods API targets are unavailable.');
        }

        $this->phase($observe, 'blocked_periods_api_conflict_put', function () use ($a, $b, $before): void {
            $payload = $this->payload($before['b']);
            $payload['id'] = $b;
            $payload['name'] = 'blocked-period conflict must not persist';
            $response = $this->client->requestJsonApp('PUT', 'api/v1/blocked_periods/' . $a, $payload);
            $this->expectStatus($response, 400, 'conflicting PUT');
            $this->assertSnapshot($before, 'conflicting PUT');
        });

        $after = null;
        $this->phase($observe, 'blocked_periods_api_matching_put', function () use ($a, $before, &$after): void {
            $payload = $this->payload($before['a']);
            $payload['id'] = $a;
            $payload['start'] = date('Y-m-d H:i:s', strtotime((string) $payload['start']) + 600);
            $payload['end'] = date('Y-m-d H:i:s', strtotime((string) $payload['end']) + 600);
            $response = $this->client->requestJsonApp('PUT', 'api/v1/blocked_periods/' . $a, $payload);
            $this->expectStatus($response, 200, 'matching PUT');
            $body = $this->decodeObject($response->body);
            if ((int) ($body['id'] ?? 0) !== $a) {
                throw new RuntimeException('Blocked Periods API matching PUT returned the wrong row.');
            }
            $after = $this->fixture->blockedPeriodsApiSnapshot();
            $expectedA = $before['a'];
            $expectedA['start_datetime'] = $payload['start'];
            $expectedA['end_datetime'] = $payload['end'];
            $expectedA['update_datetime'] = $after['a']['update_datetime'] ?? null;
            if ($after['a'] !== $expectedA || $after['b'] !== $before['b']) {
                throw new RuntimeException('Blocked Periods API matching PUT changed an unexpected row.');
            }
        });
        if (!is_array($after)) {
            throw new RuntimeException('Blocked Periods API positive snapshot is unavailable.');
        }

        $this->phase($observe, 'blocked_periods_api_wrong_verb_destroy', function () use ($b, $after): void {
            $response = $this->client->requestApp('GET', 'api/v1/blocked_periods_api_v1/destroy/' . $b);
            $this->expectStatus($response, 405, 'wrong-verb destroy alias');
            $this->assertSnapshot($after, 'wrong-verb destroy alias');
        });

        return [
            'status' => 'verified',
            'coverage' => 'put_target_binding_and_method_boundary',
            'conflict_status' => 400,
            'matching_status' => 200,
            'wrong_verb_status' => 405,
            'observed' =>
                'A conflicting body ID returned 400 without changing either owned period; matching PUT shifted only A by ten minutes; the direct destroy alias rejected GET.',
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
        $current = $this->fixture->blockedPeriodsApiSnapshot();
        if ($current['a'] !== $expected['a'] || $current['b'] !== $expected['b']) {
            throw new RuntimeException('Blocked Periods API ' . $context . ' changed an unexpected row.');
        }
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $context): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException('Blocked Periods API ' . $context . ' returned an unexpected status.');
        }
    }

    private function payload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'start' => (string) $row['start_datetime'],
            'end' => (string) $row['end_datetime'],
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }

    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Blocked Periods API PUT returned invalid JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Blocked Periods API PUT returned no period object.');
        }
        return $decoded;
    }
}

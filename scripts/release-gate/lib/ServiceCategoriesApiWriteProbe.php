<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded HTTP proof for the Service Categories API v1 write boundaries. */
final class ServiceCategoriesApiWriteProbe
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
            throw new RuntimeException('Service Categories API probe credentials are unavailable.');
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
        if (($state['profile'] ?? null) !== 'service_categories_api') {
            throw new RuntimeException('Service Categories API verification requires its dedicated graph.');
        }
        $before = $this->fixture->serviceCategoriesApiSnapshot();
        $a = (int) ($before['a']['id'] ?? 0);
        $b = (int) ($before['b']['id'] ?? 0);
        if ($a < 1 || $b < 1 || $a === $b) {
            throw new RuntimeException('Service Categories API targets are unavailable.');
        }

        $this->phase($observe, 'service_categories_api_conflict_put', function () use ($a, $b, $before): void {
            $payload = ['id' => $b, 'name' => 'Service Categories conflict', 'description' => 'must not persist'];
            $response = $this->client->requestJsonApp('PUT', 'api/v1/service_categories/' . $a, $payload);
            $this->expectStatus($response, 400, 'conflicting PUT');
            $this->assertSnapshot($before, 'conflicting PUT');
        });

        $after = null;
        $this->phase($observe, 'service_categories_api_matching_put', function () use ($a, $before, &$after): void {
            $payload = [
                'id' => $a,
                'name' => (string) $before['a']['name'] . ':updated',
                'description' => (string) $before['a']['description'] . ' updated',
            ];
            $response = $this->client->requestJsonApp('PUT', 'api/v1/service_categories/' . $a, $payload);
            $this->expectStatus($response, 200, 'matching PUT');
            $body = $this->decodeObject($response->body);
            if (
                (int) ($body['id'] ?? 0) !== $a ||
                ($body['name'] ?? null) !== $payload['name'] ||
                ($body['description'] ?? null) !== $payload['description']
            ) {
                throw new RuntimeException('Service Categories matching PUT returned the wrong row.');
            }
            $after = $this->fixture->serviceCategoriesApiSnapshot();
            if (
                ($after['a']['name'] ?? null) !== $payload['name'] ||
                ($after['a']['description'] ?? null) !== $payload['description']
            ) {
                throw new RuntimeException(
                    'Service Categories matching PUT did not persist only the intended A fields.',
                );
            }
            if ($after['b'] !== $before['b']) {
                throw new RuntimeException('Service Categories matching PUT changed category B.');
            }
        });
        if (!is_array($after)) {
            throw new RuntimeException('Service Categories positive snapshot is unavailable.');
        }

        $this->phase($observe, 'service_categories_api_wrong_verb_destroy', function () use ($b, $after): void {
            $response = $this->client->requestApp('GET', 'api/v1/service_categories_api_v1/destroy/' . $b);
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
                'A conflicting body ID returned 400 without changing either owned category; matching PUT changed only A; the direct destroy alias rejected GET.',
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
        $current = $this->fixture->serviceCategoriesApiSnapshot();
        if ($current['a'] !== $expected['a'] || $current['b'] !== $expected['b']) {
            throw new RuntimeException('Service Categories API ' . $context . ' changed an unexpected row.');
        }
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $context): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException('Service Categories API ' . $context . ' returned an unexpected status.');
        }
    }

    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Service Categories API PUT returned invalid JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Service Categories API PUT returned no category object.');
        }
        return $decoded;
    }
}

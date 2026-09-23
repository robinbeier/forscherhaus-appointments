<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded HTTP proof for the Services API v1 write boundaries. */
final class ServicesApiWriteProbe
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
            throw new RuntimeException('Services API probe credentials are unavailable.');
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
        if (($state['profile'] ?? null) !== 'services_api') {
            throw new RuntimeException('Services API verification requires the services-api graph.');
        }
        $serviceA = (int) ($state['ids']['service_a'] ?? 0);
        $serviceB = (int) ($state['ids']['service_b'] ?? 0);
        if ($serviceA < 1 || $serviceB < 1 || $serviceA === $serviceB) {
            throw new RuntimeException('Services API targets are unavailable.');
        }

        $before = $this->fixture->servicesApiSnapshot();
        $this->assertTargetIds($before, $serviceA, $serviceB);

        $this->phase($observe, 'services_api_conflict_put', function () use ($serviceA, $serviceB, $before): void {
            $payload = $this->apiPayload($before['b']);
            $payload['id'] = $serviceB;
            $payload['name'] = 'Services API conflict must not persist';
            $response = $this->client->requestJsonApp('PUT', 'api/v1/services/' . $serviceA, $payload);
            $this->expectStatus($response, 400, 'conflicting PUT');
            $this->assertSnapshot($before, 'conflicting PUT');
        });

        $afterPositive = null;
        $this->phase($observe, 'services_api_matching_put', function () use (
            $serviceA,
            $before,
            &$afterPositive,
        ): void {
            $payload = $this->apiPayload($before['a']);
            $payload['id'] = $serviceA;
            $payload['name'] = 'Services API verified';
            $response = $this->client->requestJsonApp('PUT', 'api/v1/services/' . $serviceA, $payload);
            $this->expectStatus($response, 200, 'matching PUT');
            $body = $this->decodeObject($response->body);
            if ((int) ($body['id'] ?? 0) !== $serviceA || ($body['name'] ?? null) !== $payload['name']) {
                throw new RuntimeException('Services API matching PUT returned the wrong service.');
            }
            $after = $this->fixture->servicesApiSnapshot();
            $expectedA = $before['a'];
            $expectedA['name'] = $payload['name'];
            $expectedA['update_datetime'] = $after['a']['update_datetime'] ?? null;
            if ($after['b'] !== $before['b'] || $after['a'] !== $expectedA) {
                throw new RuntimeException('Services API matching PUT changed an unexpected row.');
            }
            $afterPositive = $after;
        });
        if (!is_array($afterPositive)) {
            throw new RuntimeException('Services API positive snapshot is unavailable.');
        }

        $this->phase($observe, 'services_api_wrong_verb_destroy', function () use ($serviceB, $afterPositive): void {
            $response = $this->client->requestApp('GET', 'api/v1/services_api_v1/destroy/' . $serviceB);
            $this->expectStatus($response, 405, 'wrong-verb destroy alias');
            $this->assertSnapshot($afterPositive, 'wrong-verb destroy alias');
        });

        foreach ([['duration', 0], ['attendantsNumber', 1.9]] as [$field, $value]) {
            $phase = 'services_api_invalid_' . $field;
            $this->phase($observe, $phase, function () use ($serviceA, $afterPositive, $field, $value): void {
                $payload = $this->apiPayload($this->fixture->servicesApiSnapshot()['a']);
                $payload['id'] = $serviceA;
                $payload[$field] = $value;
                $response = $this->client->requestJsonApp('PUT', 'api/v1/services/' . $serviceA, $payload);
                $this->expectStatus($response, 400, 'invalid ' . $field);
                $this->assertSnapshot($afterPositive, 'invalid ' . $field);
            });
        }

        return [
            'status' => 'verified',
            'coverage' => 'put_target_binding_method_boundary_validation',
            'conflict_status' => 400,
            'matching_status' => 200,
            'wrong_verb_status' => 405,
            'invalid_status' => 400,
            'observed' =>
                'A conflicting body ID returned 400 without changing either owned service; a matching PUT changed only A; the direct destroy alias rejected the wrong verb; invalid duration and attendants values returned 400 without mutation.',
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
        $current = $this->fixture->servicesApiSnapshot();
        if ($current['b'] !== $expected['b']) {
            throw new RuntimeException('Services API ' . $context . ' changed service B.');
        }
        if ($current['a'] !== $expected['a']) {
            throw new RuntimeException('Services API ' . $context . ' changed service A.');
        }
    }

    private function assertTargetIds(array $snapshot, int $serviceA, int $serviceB): void
    {
        if ((int) ($snapshot['a']['id'] ?? 0) !== $serviceA || (int) ($snapshot['b']['id'] ?? 0) !== $serviceB) {
            throw new RuntimeException('Services API owned service snapshot is missing or drifted.');
        }
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $context): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException('Services API ' . $context . ' returned an unexpected status.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function apiPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'duration' => (int) $row['duration'],
            'price' => (float) $row['price'],
            'currency' => $row['currency'],
            'description' => $row['description'],
            'location' => $row['location'],
            'color' => $row['color'],
            'availabilitiesType' => $row['availabilities_type'],
            'attendantsNumber' => (int) $row['attendants_number'],
            'bufferBefore' => (int) $row['buffer_before'],
            'bufferAfter' => (int) $row['buffer_after'],
            'isPrivate' => (bool) $row['is_private'],
            'serviceCategoryId' => $row['id_service_categories'],
        ];
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Services API PUT returned invalid JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Services API PUT returned no service object.');
        }
        return $decoded;
    }
}

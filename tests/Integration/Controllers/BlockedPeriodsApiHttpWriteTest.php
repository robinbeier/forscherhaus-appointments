<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP regression coverage for the Blocked Periods API URI/body boundary. */
final class BlockedPeriodsApiHttpWriteTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $periodA = 0;
    private int $periodB = 0;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->server = new DefenseCycleHttpServer();
            $this->seedPeriods();
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

    public function testPutBodyIdCannotRedirectAndMatchingPutChangesOnlyUriTarget(): void
    {
        $admin = $this->adminClient();
        $beforeA = $this->snapshot($this->periodA);
        $beforeB = $this->snapshot($this->periodB);
        $redirect = $this->payload('redirect');
        $redirect['id'] = $this->periodB;

        $response = $admin->requestJsonApp('PUT', 'api/v1/blocked_periods/' . $this->periodA, $redirect);
        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($beforeA, $this->snapshot($this->periodA));
        self::assertSame($beforeB, $this->snapshot($this->periodB));

        foreach (['zero' => 0, 'null' => null, 'string' => (string) $this->periodA] as $case => $bodyId) {
            $invalid = $this->payload('invalid-id-' . $case);
            $invalid['id'] = $bodyId;
            $response = $admin->requestJsonApp('PUT', 'api/v1/blocked_periods/' . $this->periodA, $invalid);
            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame($beforeA, $this->snapshot($this->periodA));
            self::assertSame($beforeB, $this->snapshot($this->periodB));
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('blocked_periods', ['name' => $invalid['name']])
                    ->num_rows(),
            );
        }

        $matching = $this->payload('matching');
        $matching['id'] = $this->periodA;
        $response = $admin->requestJsonApp('PUT', 'api/v1/blocked_periods/' . $this->periodA, $matching);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($this->periodA, (int) ($this->jsonBody($response)['id'] ?? 0));
        self::assertSame($matching['name'], $this->snapshot($this->periodA)['name']);
        self::assertSame($beforeB, $this->snapshot($this->periodB));

        $withoutId = $this->payload('without-id');
        $response = $admin->requestJsonApp('PUT', 'api/v1/blocked_periods/' . $this->periodA, $withoutId);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($this->periodA, (int) ($this->jsonBody($response)['id'] ?? 0));
        self::assertSame($withoutId['name'], $this->snapshot($this->periodA)['name']);
        self::assertSame($beforeB, $this->snapshot($this->periodB));
    }

    public function testPostIgnoresBodyIdAndDeleteUsesUriOnly(): void
    {
        $admin = $this->adminClient();
        $payload = $this->payload('created');
        $payload['id'] = $this->periodB;
        $response = $admin->requestJsonApp('POST', 'api/v1/blocked_periods', $payload);
        self::assertSame(201, $response->statusCode, $response->body);
        $createdId = (int) ($this->jsonBody($response)['id'] ?? 0);
        self::assertGreaterThan(0, $createdId);
        self::assertNotSame($this->periodB, $createdId);
        self::assertSame($payload['name'], $this->snapshot($createdId)['name']);

        self::assertSame(204, $admin->requestApp('DELETE', 'api/v1/blocked_periods/' . $createdId)->statusCode);
        self::assertSame([], $this->snapshot($createdId));
        self::assertSame([], $this->fixture->blockedPeriodRow($createdId));
    }

    public function testConfiguredGlobalBearerCanWriteAnOwnedPeriod(): void
    {
        $bearer = $this->bearerClient($this->credentials['token']);
        $payload = $this->payload('bearer-created');
        $response = $bearer->requestJsonApp('POST', 'api/v1/blocked_periods', $payload);
        self::assertSame(201, $response->statusCode, $response->body);
        $createdId = (int) ($this->jsonBody($response)['id'] ?? 0);
        self::assertGreaterThan(0, $createdId);
        self::assertSame($payload['name'], $this->snapshot($createdId)['name']);

        self::assertSame(204, $bearer->requestApp('DELETE', 'api/v1/blocked_periods/' . $createdId)->statusCode);
        self::assertSame([], $this->snapshot($createdId));
    }

    public function testInvalidIntervalDoesNotMutateAndProviderOrInvalidBearerIsRejected(): void
    {
        $admin = $this->adminClient();
        $before = $this->snapshot($this->periodA);
        $invalid = $this->payload('invalid');
        $invalid['start'] = $invalid['end'];
        $response = $admin->requestJsonApp('PUT', 'api/v1/blocked_periods/' . $this->periodA, $invalid);
        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertSame($before, $this->snapshot($this->periodA));

        $provider = $this->basicClient($this->credentials['provider_username'], $this->credentials['password']);
        $providerBefore = $this->snapshot($this->periodA);
        self::assertSame(
            401,
            $provider->requestJsonApp('PUT', 'api/v1/blocked_periods/' . $this->periodA, $this->payload('provider'))
                ->statusCode,
        );
        self::assertSame($providerBefore, $this->snapshot($this->periodA));

        $invalidBearer = $this->bearerClient($this->credentials['token'] . '-invalid');
        self::assertSame(
            401,
            $invalidBearer->requestJsonApp('POST', 'api/v1/blocked_periods', $this->payload('invalid-bearer'))
                ->statusCode,
        );
    }

    public function testWrongVerbAliasesCannotReachBlockedPeriodMutations(): void
    {
        $admin = $this->adminClient();
        $beforeA = $this->snapshot($this->periodA);
        $beforeB = $this->snapshot($this->periodB);
        foreach (
            [
                ['GET', 'api/v1/blocked_periods_api_v1/store', 'POST'],
                ['GET', 'api/v1/blocked_periods_api_v1/update/' . $this->periodA, 'PUT'],
                ['GET', 'api/v1/blocked_periods_api_v1/destroy/' . $this->periodA, 'DELETE'],
                ['POST', 'api/v1/blocked_periods_api_v1/update/' . $this->periodA, 'PUT'],
                ['PUT', 'api/v1/blocked_periods_api_v1/destroy/' . $this->periodA, 'DELETE'],
                ['DELETE', 'api/v1/blocked_periods_api_v1/store', 'POST'],
            ]
            as $index => [$method, $path, $expected]
        ) {
            $response = $admin->requestApp($method, $path, $this->payload('wrong-verb-' . $index));
            self::assertSame(405, $response->statusCode, $response->body);
            self::assertSame($expected, $response->header('allow'));
        }
        self::assertSame($beforeA, $this->snapshot($this->periodA));
        self::assertSame($beforeB, $this->snapshot($this->periodB));
    }

    private function seedPeriods(): void
    {
        $admin = $this->adminClient();
        foreach (['A', 'B'] as $marker) {
            $payload = $this->payload('seed-' . strtolower($marker));
            $response = $admin->requestJsonApp('POST', 'api/v1/blocked_periods', $payload);
            self::assertSame(201, $response->statusCode, $response->body);
            $id = (int) ($this->jsonBody($response)['id'] ?? 0);
            self::assertGreaterThan(0, $id);
            if ($marker === 'A') {
                $this->periodA = $id;
            } else {
                $this->periodB = $id;
            }
        }
    }

    private function adminClient(): GateHttpClient
    {
        return $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
    }

    private function basicClient(string $username, string $password): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            ],
        );
    }

    private function bearerClient(string $token): GateHttpClient
    {
        return new GateHttpClient($this->server->baseUrl, additionalHeaders: ['Authorization' => 'Bearer ' . $token]);
    }

    private function payload(string $suffix): array
    {
        $payload = $this->fixture->blockedPeriodWritePayload($suffix);
        return [
            'name' => $payload['name'],
            'start' => $payload['start_datetime'],
            'end' => $payload['end_datetime'],
            'notes' => $payload['notes'],
        ];
    }

    private function snapshot(int $id): array
    {
        return $this->fixture->blockedPeriodRow($id);
    }

    private function jsonBody(GateHttpResponse $response): array
    {
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        return $body;
    }
}

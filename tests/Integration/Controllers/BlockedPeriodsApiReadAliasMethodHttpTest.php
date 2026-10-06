<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded real-HTTP coverage for direct Blocked-period API read aliases. */
final class BlockedPeriodsApiReadAliasMethodHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $periodId = 0;

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
            $this->seedPeriod();
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

    public function testCanonicalAndDirectReadAliasesHaveBoundedAuthenticatedAccess(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $ownedPeriod = $fixture->blockedPeriodRow($this->periodId);
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $bearer = $this->bearerClient($this->credentials['token']);

        foreach ([$admin, $bearer] as $client) {
            foreach (
                [
                    ['api/v1/blocked_periods', false],
                    ['api/v1/blocked_periods_api_v1/index', false],
                    ['api/v1/blocked_periods/' . $this->periodId, true],
                    ['api/v1/blocked_periods_api_v1/show/' . $this->periodId, true],
                ]
                as [$path, $single]
            ) {
                $response = $client->get($path);
                self::assertSame(200, $response->statusCode, $path . ': ' . $response->body);
                $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                $row = $single ? $body : $body[0] ?? null;
                self::assertIsArray($row);
                self::assertSame(['end', 'id', 'name', 'notes', 'start'], $this->sortedKeys($row), $path . ' fields');
                self::assertSame($this->periodId, (int) $row['id']);
                self::assertSame($fixture->run . '_blocked_alias-read', $row['name']);
                self::assertSame($ownedPeriod['start_datetime'], $row['start']);
                self::assertSame($ownedPeriod['end_datetime'], $row['end']);
                self::assertSame($fixture->run, $row['notes']);
            }

            $filtered = $client->get('api/v1/blocked_periods_api_v1/index?date=2000-01-01');
            self::assertSame(200, $filtered->statusCode, $filtered->body);
            self::assertSame([], json_decode($filtered->body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testUnauthenticatedReadAliasesAndAllDirectMutationMethodsAreRejectedWithoutDisclosure(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $anonymous = $this->server?->client();
        self::assertNotNull($anonymous);

        foreach (
            ['api/v1/blocked_periods_api_v1/index', 'api/v1/blocked_periods_api_v1/show/' . $this->periodId]
            as $path
        ) {
            $response = $anonymous->get($path);
            self::assertContains($response->statusCode, [401, 403], $path . ': ' . $response->body);
            self::assertStringNotContainsString($fixture->run, $response->body);
        }

        $invalidBearer = $this->bearerClient($this->credentials['token'] . '-invalid');
        $response = $invalidBearer->get('api/v1/blocked_periods_api_v1/index');
        self::assertSame(401, $response->statusCode, $response->body);
        self::assertStringNotContainsString($fixture->run, $response->body);

        $provider = $this->basicClient($this->credentials['provider_username'], $this->credentials['password']);
        $response = $provider->get('api/v1/blocked_periods_api_v1/index');
        self::assertSame(401, $response->statusCode, $response->body);
        self::assertStringNotContainsString($fixture->run, $response->body);

        $options = $anonymous->requestApp('OPTIONS', 'api/v1/blocked_periods_api_v1/index');
        self::assertSame(200, $options->statusCode, $options->body);
        self::assertSame('', $options->body);

        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $before = $fixture->blockedPeriodRow($this->periodId);
        foreach (
            ['api/v1/blocked_periods_api_v1/index', 'api/v1/blocked_periods_api_v1/show/' . $this->periodId]
            as $path
        ) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
                $response = $admin->requestApp($method, $path, []);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ': ' . $response->body);
                self::assertSame('GET', $response->header('allow'), $method . ' ' . $path . ' Allow');
                self::assertSame('', $response->body, $method . ' ' . $path . ' response body');
                self::assertStringNotContainsString($fixture->run, $response->body);
                self::assertSame($before, $fixture->blockedPeriodRow($this->periodId));
            }
        }
    }

    private function seedPeriod(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $period = $fixture->blockedPeriodWritePayload('alias-read');
        self::assertTrue(get_instance()->db->insert('blocked_periods', $period));
        $this->periodId = (int) get_instance()->db->insert_id();
        self::assertSame($period['name'], $fixture->blockedPeriodRow($this->periodId)['name'] ?? null);
    }

    private function basicClient(string $username, string $password): GateHttpClient
    {
        self::assertNotNull($this->server);
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Basic ' . base64_encode($username . ':' . $password)],
        );
    }

    private function bearerClient(string $token): GateHttpClient
    {
        self::assertNotNull($this->server);
        return new GateHttpClient($this->server->baseUrl, additionalHeaders: ['Authorization' => 'Bearer ' . $token]);
    }

    /** @return list<string> */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);
        return $keys;
    }
}

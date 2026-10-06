<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP regression coverage for Blocked Periods collection pagination. */
final class BlockedPeriodsApiPaginationHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    /** @var list<int> */
    private array $ownedIds = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->seedPeriods();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            try {
                $this->cleanupOwnedRows();
            } finally {
                $this->fixture?->cleanup();
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $this->cleanupOwnedRows();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testCanonicalAndDirectIndexPaginationSupportsBasicAndBearerReads(): void
    {
        foreach ([$this->basicClient(), $this->bearerClient()] as $client) {
            foreach ($this->paths() as $path) {
                $response = $client->get($path, [
                    'q' => $this->fixture?->run,
                    'sort' => 'id',
                    'length' => 2,
                    'page' => 2,
                ]);

                self::assertSame(200, $response->statusCode, $path . ': ' . $response->body);
                $rows = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($rows);
                self::assertCount(2, $rows);
                self::assertSame([$this->ownedIds[2], $this->ownedIds[3]], array_column($rows, 'id'));
                self::assertSame($this->fixture?->run . '_blocked_page-003', $rows[0]['name']);
            }
        }
    }

    public function testInvalidPaginationReturnsJson400AndPreservesFixtures(): void
    {
        self::assertCount(101, $this->ownedIds);
        $before = array_map(fn(int $id): array => $this->fixture?->blockedPeriodRow($id) ?? [], $this->ownedIds);
        $invalidQueries = [
            'length=0' => ['length' => 0],
            'length-over-100' => ['length' => 101],
            'page=0' => ['page' => 0],
            'page-over-10000' => ['page' => 10001],
            'length-nonnumeric' => ['length' => 'abc'],
            'page-nonnumeric' => ['page' => 'abc'],
        ];

        foreach ([$this->basicClient(), $this->bearerClient()] as $client) {
            foreach ($this->paths() as $path) {
                foreach ($invalidQueries as $case => $query) {
                    if ($case !== 'length-over-100') {
                        $query['q'] = $this->fixture?->run;
                    }
                    $response = $client->get($path, $query);

                    self::assertSame(400, $response->statusCode, "$case $path: {$response->body}");
                    self::assertIsArray(json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
                    self::assertSame(
                        $before,
                        array_map(fn(int $id): array => $this->fixture?->blockedPeriodRow($id) ?? [], $this->ownedIds),
                    );
                }
            }
        }
    }

    /** @return list<string> */
    private function paths(): array
    {
        return ['api/v1/blocked_periods', 'api/v1/blocked_periods_api_v1/index'];
    }

    private function seedPeriods(): void
    {
        $db = get_instance()->db;
        foreach (range(1, 101) as $number) {
            $case = sprintf('page-%03d', $number);
            $payload = $this->fixture?->blockedPeriodWritePayload($case);
            self::assertIsArray($payload);
            self::assertTrue($db->insert('blocked_periods', $payload));
            $id = (int) $db->insert_id();
            $this->ownedIds[] = $id;
            self::assertSame($payload['name'], $this->fixture?->blockedPeriodRow($id)['name'] ?? null);
        }
    }

    private function cleanupOwnedRows(): void
    {
        if ($this->ownedIds === []) {
            return;
        }

        $db = get_instance()->db;
        $db->where_in('id', $this->ownedIds)->delete('blocked_periods');
        foreach ($this->ownedIds as $id) {
            self::assertSame([], $this->fixture?->blockedPeriodRow($id) ?? []);
        }
        $this->ownedIds = [];
    }

    private function basicClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server?->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            ],
        );
    }

    private function bearerClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server?->baseUrl,
            additionalHeaders: ['Authorization' => 'Bearer ' . $this->credentials['token']],
        );
    }
}

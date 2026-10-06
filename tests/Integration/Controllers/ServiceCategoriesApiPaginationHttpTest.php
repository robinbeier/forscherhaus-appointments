<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP regression coverage for Service Categories collection pagination. */
final class ServiceCategoriesApiPaginationHttpTest extends TestCase
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
            $this->seedCategories();
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
                self::assertSame($this->fixture?->run . '_category-003', $rows[0]['name']);
            }
        }
    }

    public function testInvalidPaginationReturnsJson400AndPreservesFixtures(): void
    {
        self::assertCount(101, $this->ownedIds);
        $before = array_map(fn(int $id): array => $this->categoryRow($id), $this->ownedIds);
        $invalidQueries = [
            'length=0' => ['query' => ['length' => 0], 'field' => 'length'],
            'length-over-100' => ['query' => ['length' => 101], 'field' => 'length'],
            'length-over-100-without-query' => [
                'query' => ['length' => 101],
                'field' => 'length',
                'without_query' => true,
            ],
            'page=0' => ['query' => ['page' => 0], 'field' => 'page'],
            'page-over-10000' => ['query' => ['page' => 10001], 'field' => 'page'],
            'length-nonnumeric' => ['query' => ['length' => 'abc'], 'field' => 'length'],
            'page-nonnumeric' => ['query' => ['page' => 'abc'], 'field' => 'page'],
        ];

        foreach ([$this->basicClient(), $this->bearerClient()] as $client) {
            foreach ($this->paths() as $path) {
                foreach ($invalidQueries as $case => $caseData) {
                    $query = $caseData['query'];
                    if (($caseData['without_query'] ?? false) !== true) {
                        $query['q'] = $this->fixture?->run;
                    }
                    $response = $client->get($path, $query);

                    self::assertSame(400, $response->statusCode, "$case $path: {$response->body}");
                    $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                    self::assertIsArray($payload);
                    self::assertFalse($payload['success'] ?? true, "$case $path");
                    self::assertStringContainsString($caseData['field'], (string) ($payload['message'] ?? ''));
                    self::assertSame(
                        $before,
                        array_map(fn(int $id): array => $this->categoryRow($id), $this->ownedIds),
                    );
                }
            }
        }
    }

    /** @return list<string> */
    private function paths(): array
    {
        return ['api/v1/service_categories', 'api/v1/service_categories_api_v1/index'];
    }

    private function seedCategories(): void
    {
        $db = get_instance()->db;
        foreach (range(1, 101) as $number) {
            $payload = [
                'name' => sprintf('%s_category-%03d', $this->fixture?->run, $number),
                'description' => $this->fixture?->run,
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ];
            self::assertTrue($db->insert('service_categories', $payload));
            $id = (int) $db->insert_id();
            $this->ownedIds[] = $id;
            self::assertSame($payload['name'], $this->categoryRow($id)['name'] ?? null);
        }
    }

    /** @return array<string, mixed> */
    private function categoryRow(int $id): array
    {
        return get_instance()
            ->db->get_where('service_categories', ['id' => $id])
            ->row_array() ?:
            [];
    }

    private function cleanupOwnedRows(): void
    {
        if ($this->ownedIds === []) {
            return;
        }

        $db = get_instance()->db;
        $db->where_in('id', $this->ownedIds)->delete('service_categories');
        foreach ($this->ownedIds as $id) {
            self::assertSame([], $this->categoryRow($id));
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

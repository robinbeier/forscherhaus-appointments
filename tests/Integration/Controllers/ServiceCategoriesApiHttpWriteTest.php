<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

final class ServiceCategoriesApiHttpWriteTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $categoryA = 0;
    private int $categoryB = 0;
    /** @var list<int> */
    private array $createdCategoryIds = [];
    /** @var list<string> */
    private array $createdCategoryNames = [];

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
            $this->categoryA = $this->insertCategory('A');
            $this->categoryB = $this->insertCategory('B');
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->createdCategoryIds as $createdCategoryId) {
                get_instance()->db->delete('service_categories', ['id' => $createdCategoryId]);
                if ($this->snapshot($createdCategoryId) !== []) {
                    throw new RuntimeException('Created service-category cleanup was not confirmed.');
                }
            }
            foreach ($this->createdCategoryNames as $createdCategoryName) {
                get_instance()->db->delete('service_categories', ['name' => $createdCategoryName]);
                if (
                    get_instance()
                        ->db->get_where('service_categories', ['name' => $createdCategoryName])
                        ->num_rows() !== 0
                ) {
                    throw new RuntimeException('Named service-category cleanup was not confirmed.');
                }
            }
            if ($this->categoryA > 0) {
                get_instance()->db->delete('service_categories', ['id' => $this->categoryA]);
            }
            if ($this->categoryB > 0) {
                get_instance()->db->delete('service_categories', ['id' => $this->categoryB]);
            }
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testPutBodyIdCannotRedirectAndSnapshotsBothRows(): void
    {
        $beforeA = $this->snapshot($this->categoryA);
        $beforeB = $this->snapshot($this->categoryB);
        $payload = ['id' => $this->categoryB, 'name' => $this->fixture->run . '_updated_a', 'description' => 'updated'];

        $response = $this->adminClient()->requestJsonApp(
            'PUT',
            'api/v1/service_categories/' . $this->categoryA,
            $payload,
        );

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($beforeA, $this->snapshot($this->categoryA));
        self::assertSame($beforeB, $this->snapshot($this->categoryB));
    }

    public function testPutWithoutIdAndMatchingIdChangeOnlyUriTarget(): void
    {
        $beforeB = $this->snapshot($this->categoryB);
        $admin = $this->adminClient();
        foreach (
            [
                ['name' => $this->fixture->run . '_without_id', 'description' => 'without id'],
                ['id' => $this->categoryA, 'name' => $this->fixture->run . '_matching', 'description' => 'matching'],
            ]
            as $payload
        ) {
            $response = $admin->requestJsonApp('PUT', 'api/v1/service_categories/' . $this->categoryA, $payload);
            self::assertSame(200, $response->statusCode, $response->body);
            $returned = $this->jsonBody($response);
            self::assertSame($this->categoryA, (int) ($returned['id'] ?? 0));
            self::assertSame($payload['name'], $returned['name'] ?? null);
            self::assertSame($payload['description'], $returned['description'] ?? null);
            self::assertSame($payload['name'], $this->snapshot($this->categoryA)['name']);
            self::assertSame($beforeB, $this->snapshot($this->categoryB));
        }
    }

    public function testPutRejectsInvalidOrMismatchedBodyIdsWithoutMutation(): void
    {
        $admin = $this->adminClient();
        $beforeA = $this->snapshot($this->categoryA);
        $beforeB = $this->snapshot($this->categoryB);
        $beforeCount = get_instance()->db->count_all('service_categories');
        foreach ([$this->categoryB, 0, null, '', (string) $this->categoryA, 1.5] as $bodyId) {
            $payload = ['id' => $bodyId, 'name' => $this->fixture->run . '_invalid', 'description' => 'invalid'];
            $response = $admin->requestJsonApp('PUT', 'api/v1/service_categories/' . $this->categoryA, $payload);
            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame($beforeA, $this->snapshot($this->categoryA));
            self::assertSame($beforeB, $this->snapshot($this->categoryB));
            self::assertSame($beforeCount, get_instance()->db->count_all('service_categories'));
        }
    }

    public function testPutRejectsEmptyAndUnsupportedPayloadsWithoutMutation(): void
    {
        $admin = $this->adminClient();
        $before = $this->ownedCategorySnapshots();
        foreach ([[], ['unsupported' => 'ignored']] as $payload) {
            $response = $admin->requestJsonApp('PUT', 'api/v1/service_categories/' . $this->categoryA, $payload);
            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame($before, $this->ownedCategorySnapshots());
        }
    }

    public function testCanonicalPostPutDeleteAndGlobalBearerWrite(): void
    {
        $admin = $this->adminClient();
        $payload = ['id' => $this->categoryB, 'name' => $this->fixture->run . '_created', 'description' => 'created'];
        $response = $admin->requestJsonApp('POST', 'api/v1/service_categories', $payload);
        $this->registerCreatedCategoryName($payload['name']);
        self::assertSame(201, $response->statusCode, $response->body);
        $createdCategoryId = (int) ($this->jsonBody($response)['id'] ?? 0);
        $this->createdCategoryIds[] = $createdCategoryId;
        self::assertGreaterThan(0, $createdCategoryId);
        self::assertNotSame($this->categoryB, $createdCategoryId);
        self::assertSame($payload['name'], $this->snapshot($createdCategoryId)['name']);

        $bearerPayload = ['name' => $this->fixture->run . '_bearer', 'description' => 'bearer'];
        $bearer = new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Bearer ' . $this->credentials['token']],
        );
        $bearerResponse = $bearer->requestJsonApp('POST', 'api/v1/service_categories', $bearerPayload);
        $this->registerCreatedCategoryName($bearerPayload['name']);
        $bearerId = (int) ($this->jsonBody($bearerResponse)['id'] ?? 0);
        $this->createdCategoryIds[] = $bearerId;
        self::assertSame(201, $bearerResponse->statusCode, $bearerResponse->body);
        self::assertGreaterThan(0, $bearerId);
        self::assertSame(204, $bearer->requestApp('DELETE', 'api/v1/service_categories/' . $bearerId)->statusCode);
        self::assertSame([], $this->snapshot($bearerId));
        $this->createdCategoryIds = array_values(array_diff($this->createdCategoryIds, [$bearerId]));

        self::assertSame(
            204,
            $admin->requestApp('DELETE', 'api/v1/service_categories/' . $createdCategoryId)->statusCode,
        );
        self::assertSame([], $this->snapshot($createdCategoryId));
        $this->createdCategoryIds = array_values(array_diff($this->createdCategoryIds, [$createdCategoryId]));
    }

    public function testProviderInvalidBearerAndMissingCredentialsAreDeniedWithoutMutation(): void
    {
        $before = $this->ownedCategorySnapshots();
        $beforeService = $this->serviceSnapshot();
        $provider = new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['provider_username'] . ':' . $this->credentials['password']),
            ],
        );
        $invalidBearer = new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Bearer invalid'],
        );
        $anonymous = $this->server->client();
        foreach ([$provider, $invalidBearer, $anonymous] as $client) {
            foreach (
                [
                    [
                        'POST',
                        'api/v1/service_categories',
                        ['name' => $this->fixture->run . '_denied_post', 'description' => 'denied'],
                    ],
                    [
                        'PUT',
                        'api/v1/service_categories/' . $this->categoryA,
                        ['name' => $this->fixture->run . '_denied_put', 'description' => 'denied'],
                    ],
                    ['DELETE', 'api/v1/service_categories/' . $this->categoryA, null],
                ]
                as [$method, $path, $payload]
            ) {
                $response =
                    $payload === null
                        ? $client->requestApp($method, $path)
                        : $client->requestJsonApp($method, $path, $payload);
                self::assertSame(401, $response->statusCode, $response->body);
                self::assertSame($before, $this->ownedCategorySnapshots());
                self::assertSame($beforeService, $this->serviceSnapshot());
            }
        }
    }

    public function testWrongVerbAliasesReturn405AndDoNotMutate(): void
    {
        $admin = $this->adminClient();
        $beforeA = $this->snapshot($this->categoryA);
        $beforeB = $this->snapshot($this->categoryB);
        foreach (
            [
                ['GET', 'api/v1/service_categories_api_v1/store', 'POST'],
                ['GET', 'api/v1/service_categories_api_v1/update/' . $this->categoryA, 'PUT'],
                ['GET', 'api/v1/service_categories_api_v1/destroy/' . $this->categoryA, 'DELETE'],
                ['POST', 'api/v1/service_categories_api_v1/update/' . $this->categoryA, 'PUT'],
                ['PUT', 'api/v1/service_categories_api_v1/destroy/' . $this->categoryA, 'DELETE'],
                ['DELETE', 'api/v1/service_categories_api_v1/store', 'POST'],
            ]
            as [$method, $path, $allow]
        ) {
            $response = $admin->requestApp($method, $path, [
                'name' => $this->fixture->run . '_wrong',
                'description' => 'wrong',
            ]);
            self::assertSame(405, $response->statusCode, $response->body);
            self::assertSame($allow, $response->header('allow'));
        }
        self::assertSame($beforeA, $this->snapshot($this->categoryA));
        self::assertSame($beforeB, $this->snapshot($this->categoryB));
    }

    public function testDeletingOwnedCategoryLinkedToOwnedServiceLeavesServiceIntact(): void
    {
        $db = get_instance()->db;
        $db->update('services', ['id_service_categories' => $this->categoryA], ['id' => $this->fixture->serviceId]);
        $beforeService = $db->get_where('services', ['id' => $this->fixture->serviceId])->row_array();
        $availabilityPath =
            'api/v1/availabilities?serviceId=' .
            $this->fixture->serviceId .
            '&providerId=' .
            $this->fixture->providerId .
            '&date=' .
            date('Y-m-d', strtotime('+14 days'));
        $beforeAvailability = $this->adminClient()->requestApp('GET', $availabilityPath);
        self::assertSame(200, $beforeAvailability->statusCode, $beforeAvailability->body);
        $response = $this->adminClient()->requestApp('DELETE', 'api/v1/service_categories/' . $this->categoryA);
        self::assertSame(204, $response->statusCode, $response->body);
        self::assertSame([], $this->snapshot($this->categoryA));
        $afterService = $db->get_where('services', ['id' => $this->fixture->serviceId])->row_array();
        self::assertNotEmpty($afterService);
        self::assertSame($beforeService['id'], $afterService['id']);
        self::assertNull($afterService['id_service_categories']);
        $afterAvailability = $this->adminClient()->requestApp('GET', $availabilityPath);
        self::assertSame(200, $afterAvailability->statusCode, $afterAvailability->body);
        self::assertSame($beforeAvailability->body, $afterAvailability->body);
        $this->categoryA = 0;
    }

    private function insertCategory(string $marker): int
    {
        $db = get_instance()->db;
        $db->insert('service_categories', [
            'name' => $this->fixture->run . '_category_' . strtolower($marker),
            'description' => 'Synthetic ' . $marker,
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insert_id();
    }

    private function registerCreatedCategoryName(string $name): void
    {
        $this->createdCategoryNames[] = $name;
        $rows = get_instance()
            ->db->get_where('service_categories', ['name' => $name])
            ->result_array();
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && !in_array($id, $this->createdCategoryIds, true)) {
                $this->createdCategoryIds[] = $id;
            }
        }
    }

    private function snapshot(int $id): array
    {
        return get_instance()
            ->db->get_where('service_categories', ['id' => $id])
            ->row_array() ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    private function ownedCategorySnapshots(): array
    {
        return get_instance()
            ->db->order_by('id', 'ASC')
            ->get_where('service_categories', ['name >=' => $this->fixture->run . '_'])
            ->result_array();
    }

    /** @return array<string, mixed> */
    private function serviceSnapshot(): array
    {
        return get_instance()
            ->db->get_where('services', ['id' => $this->fixture->serviceId])
            ->row_array() ?? [];
    }

    private function jsonBody(\ReleaseGate\GateHttpResponse $response): array
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function adminClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            ],
        );
    }
}

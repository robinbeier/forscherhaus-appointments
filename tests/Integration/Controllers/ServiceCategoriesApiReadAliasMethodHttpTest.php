<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for service-category API v1 read aliases. */
final class ServiceCategoriesApiReadAliasMethodHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array{admin_username:string,provider_username:string,password:string,token:string} */
    private array $credentials = [];
    private int $categoryId = 0;
    private string $categoryName = '';

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
            $this->categoryName = $this->fixture->run . '_category_read_alias';
            $db = get_instance()->db;
            $db->insert('service_categories', [
                'name' => $this->categoryName,
                'description' => $this->fixture->run . '_description',
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]);
            $this->categoryId = (int) $db->insert_id();
            self::assertGreaterThan(0, $this->categoryId);
        } catch (Throwable $error) {
            $this->server?->close();
            $this->cleanupCategory();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
            $this->cleanupCategory();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testAdminAndBearerReadCanonicalAndDirectAliasesWithExactProjection(): void
    {
        $fixture = $this->requireFixture();
        $expected = [
            'id' => $this->categoryId,
            'name' => $this->categoryName,
            'description' => $fixture->run . '_description',
        ];
        $clients = [
            'admin-basic' => $this->basicClient($this->credentials['admin_username'], $this->credentials['password']),
            'global-bearer' => $this->bearerClient($this->credentials['token']),
        ];

        foreach ($clients as $case => $client) {
            foreach (['api/v1/service_categories', 'api/v1/service_categories_api_v1/index'] as $path) {
                $response = $client->get($path, ['q' => $this->categoryName]);
                self::assertSame(200, $response->statusCode, $case . ' ' . $path . ': ' . $response->body);
                $rows = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($rows);
                $matches = array_values(
                    array_filter(
                        $rows,
                        fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $this->categoryId,
                    ),
                );
                self::assertSame([$expected], $matches, $case . ' ' . $path);
            }

            foreach (
                [
                    'api/v1/service_categories/' . $this->categoryId,
                    'api/v1/service_categories_api_v1/show/' . $this->categoryId,
                ]
                as $path
            ) {
                $response = $client->get($path);
                self::assertSame(200, $response->statusCode, $case . ' ' . $path . ': ' . $response->body);
                self::assertSame($expected, json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testReadAliasesDenyUnauthenticatedInvalidBearerAndProviderBasicAndOptionsIsGlobal(): void
    {
        $fixture = $this->requireFixture();
        $paths = [
            'api/v1/service_categories_api_v1/index',
            'api/v1/service_categories_api_v1/show/' . $this->categoryId,
        ];
        $clients = [
            'anonymous' => $this->server->client(),
            'invalid-bearer' => $this->bearerClient($this->credentials['token'] . '-invalid'),
            'provider-basic' => $this->basicClient(
                $this->credentials['provider_username'],
                $this->credentials['password'],
            ),
        ];

        foreach ($clients as $case => $client) {
            foreach ($paths as $path) {
                $response = $client->get($path);
                self::assertSame(401, $response->statusCode, $case . ' ' . $path . ': ' . $response->body);
                self::assertNotEmpty($response->header('www-authenticate'), $case . ' ' . $path);
                self::assertStringNotContainsString($fixture->run, $response->body);
            }
        }

        $options = $this->server->client()->requestApp('OPTIONS', $paths[0]);
        self::assertSame(200, $options->statusCode, $options->body);
        self::assertSame('', $options->body);
    }

    public function testDirectReadAliasesRejectAllNonGetMethodsWithoutBodyOrMutation(): void
    {
        $fixture = $this->requireFixture();
        $before = get_instance()
            ->db->get_where('service_categories', ['id' => $this->categoryId])
            ->row_array();
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);

        foreach (
            ['api/v1/service_categories_api_v1/index', 'api/v1/service_categories_api_v1/show/' . $this->categoryId]
            as $path
        ) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
                $response = $admin->requestApp($method, $path);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ': ' . $response->body);
                self::assertSame('GET', $response->header('allow'), $method . ' ' . $path . ' Allow');
                self::assertSame('', $response->body, $method . ' ' . $path . ' body');
                self::assertStringNotContainsString($fixture->run, $response->body);
                self::assertSame(
                    $before,
                    get_instance()
                        ->db->get_where('service_categories', ['id' => $this->categoryId])
                        ->row_array(),
                );
            }
        }
    }

    private function requireFixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);
        return $this->fixture;
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

    private function cleanupCategory(): void
    {
        if ($this->categoryId > 0) {
            get_instance()->db->delete('service_categories', ['id' => $this->categoryId]);
            self::assertSame(
                [],
                get_instance()
                    ->db->get_where('service_categories', ['id' => $this->categoryId])
                    ->result_array(),
            );
        }
        if ($this->categoryName !== '') {
            get_instance()->db->delete('service_categories', ['name' => $this->categoryName]);
            self::assertSame(
                [],
                get_instance()
                    ->db->get_where('service_categories', ['name' => $this->categoryName])
                    ->result_array(),
            );
        }
    }
}

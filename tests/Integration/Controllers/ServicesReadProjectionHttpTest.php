<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the legacy Services read paths and current authority. */
final class ServicesReadProjectionHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $adminRoleSnapshot = null;

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
            try {
                $this->restoreAdminRole();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAdminReadsServiceProjectionAcrossCanonicalAndLegacyRoutes(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $before = $fixture->row('services', $fixture->serviceId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);

        foreach (['services', 'services/index'] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path);
            self::assertStringNotContainsString($fixture->run . '_unexpected', $response->body);
        }

        foreach (
            [
                'POST services/search' => $admin->post('services/search', ['keyword' => $fixture->run]),
                'GET services/search' => $admin->get('services/search', ['keyword' => $fixture->run]),
                'GET alias' => $admin->get('backend_api/ajax_filter_services', ['keyword' => $fixture->run]),
                'POST alias' => $admin->post('backend_api/ajax_filter_services', ['keyword' => $fixture->run]),
            ]
            as $case => $response
        ) {
            if ($case === 'POST alias') {
                self::assertSame(405, $response->statusCode, $case);
                self::assertStringContainsString('GET', (string) $response->header('allow'), $case);
                self::assertStringNotContainsString($fixture->run, $response->body, $case);
                continue;
            }
            $rows = $this->decodeList($response, $case);
            $matches = array_values(
                array_filter(
                    $rows,
                    static fn(mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $fixture->serviceId,
                ),
            );
            self::assertCount(1, $matches, $case);
            $this->assertServiceProjection($matches[0], $before, $case);
        }

        foreach (
            [
                'POST services/find' => $admin->post('services/find', ['service_id' => $fixture->serviceId]),
                'GET services/find' => $admin->get('services/find', ['service_id' => $fixture->serviceId]),
            ]
            as $case => $response
        ) {
            $this->assertServiceProjection($this->decodeObject($response, $case), $before, $case);
        }

        self::assertSame($before, $fixture->row('services', $fixture->serviceId));
    }

    public static function serviceCollectionRoutes(): array
    {
        return [
            'canonical' => ['api/v1/services'],
            'index-alias' => ['api/v1/services_api_v1/index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('serviceCollectionRoutes')]
    public function testServiceCollectionPaginationAndCategoryProjection(string $route): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $categoryName = $fixture->run . '_pagination_category';
        $categoryId = 0;
        $originalCategoryId = null;

        try {
            $originalCategoryId = $fixture->row('services', $fixture->serviceId)['id_service_categories'] ?? null;
            $db->insert('service_categories', [
                'name' => $categoryName,
                'description' => $fixture->run,
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]);
            $categoryId = (int) $db->insert_id();
            $db->update('services', ['id_service_categories' => $categoryId], ['id' => $fixture->serviceId]);
            $before = $fixture->row('services', $fixture->serviceId);
            get_instance()->load->model('services_model');
            $serviceForLoad = $before;
            $queryStart = count($db->queries);
            get_instance()->services_model->load($serviceForLoad, ['category', 'category', 'category']);
            self::assertSame(1, count($db->queries) - $queryStart);

            $clients = [
                new GateHttpClient(
                    $this->server->baseUrl,
                    additionalHeaders: [
                        'Authorization' =>
                            'Basic ' .
                            base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
                    ],
                ),
                new GateHttpClient(
                    $this->server->baseUrl,
                    additionalHeaders: ['Authorization' => 'Bearer ' . $this->credentials['token']],
                ),
            ];

            foreach ($clients as $client) {
                foreach (
                    [
                        'length-zero' => ['length' => '0'],
                        'length-over-bound' => ['length' => '101'],
                        'length-huge' => ['length' => '999999999999999999999999'],
                        'page-zero' => ['page' => '0'],
                        'page-over-bound' => ['page' => '10001'],
                        'page-huge' => ['page' => '999999999999999999999999'],
                    ]
                    as $name => $query
                ) {
                    $response = $client->get($route, $query);
                    self::assertSame(400, $response->statusCode, $route . ' ' . $name);
                    $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                    self::assertFalse($payload['success'] ?? true, $route . ' ' . $name);
                    self::assertStringContainsString(
                        (string) array_key_first($query),
                        (string) ($payload['message'] ?? ''),
                    );
                    self::assertSame($before, $fixture->row('services', $fixture->serviceId));
                }

                $single = $this->decodeList(
                    $client->get($route, ['q' => $fixture->run, 'with' => 'category']),
                    'single-category',
                );
                $default = $this->decodeList(
                    $client->get($route, ['q' => $fixture->run, 'with' => 'category,category,category']),
                    'repeated-category',
                );
                self::assertSame($single, $default);
                self::assertNotEmpty($default);
                self::assertLessThanOrEqual(20, count($default));
                $match = array_values(
                    array_filter(
                        $default,
                        static fn(mixed $row): bool => is_array($row) &&
                            (int) ($row['id'] ?? 0) === $fixture->serviceId,
                    ),
                );
                self::assertCount(1, $match);
                self::assertArrayHasKey('category', $match[0]);
                self::assertIsArray($match[0]['category']);
                $categoryKeys = array_keys($match[0]['category']);
                sort($categoryKeys);
                self::assertSame(['description', 'id', 'name'], $categoryKeys);
                self::assertSame($categoryId, (int) ($match[0]['category']['id'] ?? 0));
                self::assertSame($fixture->run . '_pagination_category', $match[0]['category']['name'] ?? null);
                self::assertStringNotContainsString(
                    $fixture->run . '_unexpected',
                    json_encode($match[0], JSON_THROW_ON_ERROR),
                );
                foreach (['_google_integration', '_caldav_integration'] as $suffix) {
                    self::assertStringNotContainsString(
                        $fixture->run . $suffix,
                        json_encode($match[0], JSON_THROW_ON_ERROR),
                    );
                }

                $boundary = $this->decodeList(
                    $client->get($route, ['q' => $fixture->run, 'length' => '1', 'page' => '1']),
                    'boundary',
                );
                self::assertCount(min(1, count($default)), $boundary);
                $maximum = $this->decodeList(
                    $client->get($route, ['q' => $fixture->run, 'length' => '100', 'page' => '10000']),
                    'maximum',
                );
                self::assertLessThanOrEqual(100, count($maximum));
            }

            self::assertSame($before, $fixture->row('services', $fixture->serviceId));
        } finally {
            if ($originalCategoryId !== null || $categoryId > 0) {
                $db->update(
                    'services',
                    ['id_service_categories' => $originalCategoryId],
                    ['id' => $fixture->serviceId],
                );
            }
            if ($categoryId > 0) {
                $db->delete('service_categories', ['id' => $categoryId]);
            }
            $db->delete('service_categories', ['name' => $categoryName]);
            self::assertSame([], $db->get_where('service_categories', ['name' => $categoryName])->result_array());
        }
    }

    public function testAnonymousServiceDeepLinksKeepTheirLoginReturnTarget(): void
    {
        $server = $this->server;
        self::assertNotNull($server);

        foreach (['services', 'services/index'] as $path) {
            $guest = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'service-deep-link']);
            $response = $guest->get($path);
            self::assertSame(307, $response->statusCode, $path);
            self::assertStringContainsString('/login', (string) $response->header('location'), $path);
            self::assertStringEndsWith('/services', $this->sessionDestination($guest), $path);

            $login = $guest->get('login');
            self::assertSame(200, $login->statusCode, $path);
            self::assertStringContainsString('/services', $login->body, $path);
        }
    }

    public function testUnsupportedMethodsDoNotExposeOrMutateServiceReads(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $beforeService = $fixture->row('services', $fixture->serviceId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);
        $statusVector = [];

        foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach (
                [
                    'services/search' => ['keyword' => $fixture->run],
                    'services/find' => ['service_id' => $fixture->serviceId],
                    'backend_api/ajax_filter_services' => ['keyword' => $fixture->run],
                ]
                as $path => $payload
            ) {
                $response = $admin->requestApp($method, $path, $payload);
                $key = $method . ' ' . $path;
                $statusVector[$key] = $response->statusCode;
                if (str_starts_with($path, 'backend_api/')) {
                    self::assertSame(405, $response->statusCode, $key);
                    self::assertStringContainsString('GET', (string) $response->header('allow'), $key);
                } else {
                    self::assertSame(405, $response->statusCode, $key . ' ' . json_encode($statusVector));
                    self::assertStringContainsString('GET', (string) $response->header('allow'), $key);
                    self::assertStringContainsString('POST', (string) $response->header('allow'), $key);
                }
                if ($method === 'HEAD') {
                    self::assertSame('', $response->body, $key);
                } else {
                    self::assertStringNotContainsString($fixture->run, $response->body, $key);
                }
            }
        }

        self::assertSame($beforeDestination, $this->sessionDestination($admin), json_encode($statusVector));
        self::assertSame($beforeService, $fixture->row('services', $fixture->serviceId), json_encode($statusVector));
        self::assertNotEmpty($statusVector);
    }

    public function testViewWithoutDeletePermissionCanFindServiceWithoutMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->adminRoleSnapshot = $this->snapshotAdminRole();
        $before = $fixture->row('services', $fixture->serviceId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);

        self::assertTrue(
            get_instance()->db->update('roles', ['services' => PRIV_VIEW], ['id' => $this->adminRoleSnapshot['id']]),
        );

        foreach (
            [
                'GET services' => $admin->get('services'),
                'GET services/index' => $admin->get('services/index'),
                'POST services/search' => $admin->post('services/search', ['keyword' => $fixture->run]),
                'GET services/search' => $admin->get('services/search', ['keyword' => $fixture->run]),
                'GET alias' => $admin->get('backend_api/ajax_filter_services', ['keyword' => $fixture->run]),
                'POST alias' => $admin->post('backend_api/ajax_filter_services', ['keyword' => $fixture->run]),
            ]
            as $case => $response
        ) {
            if ($case === 'POST alias') {
                self::assertSame(405, $response->statusCode, $case);
                self::assertStringContainsString('GET', (string) $response->header('allow'), $case);
                self::assertStringNotContainsString($fixture->run, $response->body, $case);
                continue;
            }
            self::assertSame(200, $response->statusCode, $case);
            if (str_contains($case, 'search') || str_contains($case, 'alias')) {
                $rows = $this->decodeList($response, $case);
                self::assertNotEmpty($rows, $case);
            }
        }

        foreach (
            [
                'POST services/find' => $admin->post('services/find', ['service_id' => $fixture->serviceId]),
                'GET services/find' => $admin->get('services/find', ['service_id' => $fixture->serviceId]),
            ]
            as $case => $response
        ) {
            $this->assertServiceProjection($this->decodeObject($response, $case), $before, $case);
        }

        self::assertSame($before, $fixture->row('services', $fixture->serviceId));
    }

    public function testStoredActorRoleChangeDeniesServiceReadsWithoutMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $beforeService = $fixture->row('services', $fixture->serviceId);
        $beforeActor = $fixture->row('users', $fixture->actorId);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );
            $responses = $this->readRequests($admin, $fixture->serviceId, $fixture->run);
            $statuses = array_map(static fn(GateHttpResponse $response): int => $response->statusCode, $responses);
            $expectedStatuses = array_fill_keys(array_keys($responses), 403);
            $expectedStatuses['POST alias'] = 405;
            self::assertSame($expectedStatuses, $statuses, json_encode($statuses));
            foreach ($responses as $case => $response) {
                if ($case === 'POST alias') {
                    self::assertStringContainsString('GET', (string) $response->header('allow'), $case);
                }
                self::assertStringNotContainsString($fixture->run, $response->body, $case);
            }
        } finally {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $beforeActor['id_roles']],
                    ['id' => $fixture->actorId],
                ),
            );
        }

        self::assertSame($beforeDestination, $this->sessionDestination($admin));
        self::assertSame($beforeActor, $fixture->row('users', $fixture->actorId));
        self::assertSame($beforeService, $fixture->row('services', $fixture->serviceId));
    }

    public function testStoredServicesCapabilityRevocationDeniesReadsWithoutMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->adminRoleSnapshot = $this->snapshotAdminRole();
        $beforeService = $fixture->row('services', $fixture->serviceId);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);

        try {
            self::assertTrue(
                get_instance()->db->update('roles', ['services' => 0], ['id' => $this->adminRoleSnapshot['id']]),
            );
            $responses = $this->readRequests($admin, $fixture->serviceId, $fixture->run);
            $statuses = array_map(static fn(GateHttpResponse $response): int => $response->statusCode, $responses);
            $expectedStatuses = array_fill_keys(array_keys($responses), 403);
            $expectedStatuses['POST alias'] = 405;
            self::assertSame($expectedStatuses, $statuses, json_encode($statuses));
            foreach ($responses as $case => $response) {
                if ($case === 'POST alias') {
                    self::assertStringContainsString('GET', (string) $response->header('allow'), $case);
                }
                self::assertStringNotContainsString($fixture->run, $response->body, $case);
            }
            self::assertSame($beforeDestination, $this->sessionDestination($admin));
        } finally {
            $this->restoreAdminRole();
        }

        self::assertSame($beforeService, $fixture->row('services', $fixture->serviceId));
    }

    /** @return array<string,GateHttpResponse> */
    private function readRequests(GateHttpClient $admin, int $serviceId, string $keyword): array
    {
        return [
            'GET services' => $admin->get('services'),
            'GET services/index' => $admin->get('services/index'),
            'POST services/search' => $admin->post('services/search', ['keyword' => $keyword]),
            'GET services/search' => $admin->get('services/search', ['keyword' => $keyword]),
            'GET alias' => $admin->get('backend_api/ajax_filter_services', ['keyword' => $keyword]),
            'POST alias' => $admin->post('backend_api/ajax_filter_services', ['keyword' => $keyword]),
            'POST services/find' => $admin->post('services/find', ['service_id' => $serviceId]),
            'GET services/find' => $admin->get('services/find', ['service_id' => $serviceId]),
        ];
    }

    /** @param array<string,mixed> $service */
    private function assertServiceProjection(array $service, array $expectedRow, string $case): void
    {
        self::assertSame((int) $expectedRow['id'], (int) ($service['id'] ?? 0), $case);
        $expectedKeys = [
            'id',
            'name',
            'duration',
            'price',
            'currency',
            'description',
            'color',
            'location',
            'availabilities_type',
            'attendants_number',
            'buffer_before',
            'buffer_after',
            'is_private',
            'id_service_categories',
        ];
        $actualKeys = array_keys($service);
        sort($expectedKeys);
        sort($actualKeys);
        self::assertSame($expectedKeys, $actualKeys, $case);
        self::assertArrayNotHasKey('create_datetime', $service, $case);
        self::assertArrayNotHasKey('update_datetime', $service, $case);
        foreach ($expectedRow as $key => $value) {
            if (!in_array($key, $expectedKeys, true)) {
                continue;
            }
            if ($key === 'id') {
                continue;
            }
            if ($key === 'is_private') {
                self::assertSame((bool) (int) $value, (bool) ($service[$key] ?? false), $case . ' ' . $key);
                continue;
            }
            if (is_bool($value)) {
                self::assertSame($value, (bool) ($service[$key] ?? false), $case . ' ' . $key);
                continue;
            }
            if ($value === null) {
                self::assertNull($service[$key] ?? null, $case . ' ' . $key);
                continue;
            }
            if (is_numeric($value) && is_numeric($service[$key] ?? null)) {
                self::assertSame((float) $value, (float) $service[$key], $case . ' ' . $key);
                continue;
            }
            self::assertSame((string) $value, (string) ($service[$key] ?? ''), $case . ' ' . $key);
        }
    }

    /** @return array{id:int,services:int} */
    private function snapshotAdminRole(): array
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null);
        return ['id' => (int) $role['id'], 'services' => (int) ($role['services'] ?? 0)];
    }

    private function restoreAdminRole(): void
    {
        if ($this->adminRoleSnapshot !== null) {
            get_instance()->db->update(
                'roles',
                ['services' => $this->adminRoleSnapshot['services']],
                ['id' => $this->adminRoleSnapshot['id']],
            );
            $this->adminRoleSnapshot = null;
        }
    }

    /** @return list<array<string,mixed>> */
    private function decodeList(GateHttpResponse $response, string $case): array
    {
        self::assertSame(200, $response->statusCode, $case);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return array_values($data);
    }

    /** @return array<string,mixed> */
    private function decodeObject(GateHttpResponse $response, string $case): array
    {
        self::assertSame(200, $response->statusCode, $case);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function login(string $username, string $password): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $response->statusCode);
        return $client;
    }

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $sessionPath = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($sessionPath);
        $contents = file_get_contents($sessionPath);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Service_categories controller surface. */
final class ServiceCategoriesLegacyHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $categoryId = 0;
    private array $roleBefore = [];
    /** @var list<int> */
    private array $createdIds = [];

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
            $this->roleBefore = $this->roleRow();
            $this->categoryId = $this->insertCategory('target');
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixture === null) {
                return;
            }
            $db = get_instance()->db;
            $run = $this->fixture->run;
            $roleRestored = true;
            if ($this->roleBefore !== []) {
                $roleRestored = $db->update('roles', $this->roleBefore, ['id' => $this->roleBefore['id']]);
                $roleRestored =
                    $roleRestored &&
                    $db->get_where('roles', ['id' => $this->roleBefore['id']])->row_array() === $this->roleBefore;
            }
            foreach ($this->createdIds as $id) {
                $db->delete('service_categories', ['id' => $id]);
            }
            if ($this->categoryId > 0) {
                $db->delete('service_categories', ['id' => $this->categoryId]);
            }
            // Sweep every synthetic name so a failed assertion cannot strand a row.
            foreach ($db->get('service_categories')->result_array() as $row) {
                if (str_starts_with((string) ($row['name'] ?? ''), $run . '_')) {
                    $db->delete('service_categories', ['id' => (int) $row['id']]);
                }
            }
            $ownedRemaining = array_values(
                array_filter(
                    $db->get('service_categories')->result_array(),
                    fn(array $row): bool => str_starts_with((string) ($row['name'] ?? ''), $run . '_'),
                ),
            );
            self::assertTrue($roleRestored, 'Shared Admin role must be restored exactly.');
            self::assertSame([], $ownedRemaining, 'Every owned category must be removed.');
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAdminCanReadAndCompleteClassicCrudLifecycle(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        $index = $admin->get('service_categories');
        self::assertSame(200, $index->statusCode, $index->body);
        self::assertSame(200, $admin->get('service_categories/index')->statusCode);
        self::assertStringContainsString('service_categories', $index->body);

        $search = $admin->post('service_categories/search', ['keyword' => $this->fixture->run]);
        $searchData = $this->json($search);
        $matches = array_values(
            array_filter($searchData, fn(mixed $row): bool => (int) ($row['id'] ?? 0) === $this->categoryId),
        );
        self::assertCount(1, $matches);
        self::assertSame(['id', 'name', 'description'], array_keys($matches[0]));
        self::assertSame($this->categoryId, $matches[0]['id']);
        self::assertSame($this->fixture->run . '_target', $matches[0]['name']);
        self::assertSame($this->fixture->run, $matches[0]['description']);

        $found = $this->json($admin->post('service_categories/find', ['service_category_id' => $this->categoryId]));
        self::assertSame(['id', 'name', 'description'], array_keys($found));
        self::assertSame($this->categoryId, $found['id']);
        self::assertSame($this->fixture->run . '_target', $found['name']);
        self::assertSame($this->fixture->run, $found['description']);

        $name = $this->fixture->run . '_created';
        $stored = $this->json(
            $admin->post('service_categories/store', [
                'service_category' => ['name' => $name, 'description' => 'created'],
            ]),
        );
        $createdId = (int) ($stored['id'] ?? 0);
        self::assertGreaterThan(0, $createdId);
        $this->createdIds[] = $createdId;
        self::assertSame($name, $this->row($createdId)['name'] ?? null);

        $updated = $this->json(
            $admin->post('service_categories/update', [
                'service_category' => ['id' => $createdId, 'name' => $name . '_updated', 'description' => 'updated'],
            ]),
        );
        self::assertSame($createdId, (int) ($updated['id'] ?? 0));
        self::assertSame($name . '_updated', $this->row($createdId)['name'] ?? null);

        $destroyed = $this->json($admin->post('service_categories/destroy', ['service_category_id' => $createdId]));
        self::assertTrue((bool) ($destroyed['success'] ?? false));
        self::assertSame([], $this->row($createdId));
        $this->createdIds = array_values(array_diff($this->createdIds, [$createdId]));
    }

    public function testClassicMethodBoundariesRejectWrongVerbsAndKeepRowsUnchanged(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        $before = $this->row($this->categoryId);
        $requests = [
            ['GET', 'service_categories/store', ['service_category[name]' => $this->fixture->run . '_wrong'], 'POST'],
            [
                'PUT',
                'service_categories/store',
                ['service_category' => ['name' => $this->fixture->run . '_wrong_put']],
                'POST',
            ],
            ['POST', 'service_categories', [], 'GET'],
            ['GET', 'service_categories/search', ['keyword' => $this->fixture->run], 'POST'],
            ['GET', 'service_categories/find', ['service_category_id' => $this->categoryId], 'POST'],
            [
                'GET',
                'service_categories/update',
                [
                    'service_category[id]' => $this->categoryId,
                    'service_category[name]' => $this->fixture->run . '_wrong_update',
                ],
                'POST',
            ],
            [
                'PATCH',
                'service_categories/update',
                ['service_category' => ['id' => $this->categoryId, 'name' => $this->fixture->run . '_wrong_patch']],
                'POST',
            ],
            ['GET', 'service_categories/destroy', ['service_category_id' => $this->categoryId], 'POST'],
            ['DELETE', 'service_categories/destroy', ['service_category_id' => $this->categoryId], 'POST'],
        ];
        $before = $this->row($this->categoryId);
        foreach ($requests as [$method, $path, $payload, $allow]) {
            $requestPath = $method === 'GET' && $payload !== [] ? $path . '?' . http_build_query($payload) : $path;
            $response = $admin->requestApp(
                $method,
                $requestPath,
                $method === 'GET' ? [] : $payload,
                withCsrfToken: $method === 'POST',
            );
            if ($path === 'service_categories/store') {
                self::assertSame(
                    0,
                    get_instance()
                        ->db->get_where('service_categories', ['name' => $this->fixture->run . '_wrong'])
                        ->num_rows(),
                    'A GET request must not create a category.',
                );
            }
            self::assertSame(405, $response->statusCode, $method . ' ' . $path . ' must be rejected.');
            self::assertSame($allow, $response->header('allow'));
            self::assertSame($before, $this->row($this->categoryId));
        }
    }

    public function testClassicPostWritesRequireCsrfAndLeaveOwnedDataUntouched(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        $before = $this->row($this->categoryId);
        $newName = $this->fixture->run . '_without_csrf';

        foreach (
            [
                ['service_categories/store', ['service_category' => ['name' => $newName]]],
                ['service_categories/update', ['service_category' => ['id' => $this->categoryId, 'name' => $newName]]],
                ['service_categories/destroy', ['service_category_id' => $this->categoryId]],
            ]
            as [$path, $payload]
        ) {
            $response = $admin->requestApp('POST', $path, $payload, withCsrfToken: false);
            self::assertSame(403, $response->statusCode, $path);
            self::assertSame($before, $this->row($this->categoryId), $path);
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('service_categories', ['name' => $newName])
                    ->num_rows(),
            );
        }
    }

    public function testProviderAndAnonymousRequestsAreDeniedWithoutMutationOrCookieChanges(): void
    {
        $before = $this->row($this->categoryId);
        $anonymous = new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['X-FH-Test' => 'anonymous-category'],
        );
        $index = $anonymous->get('service_categories');
        self::assertContains($index->statusCode, [302, 307]);
        self::assertStringContainsString('/login', (string) $index->header('location'));

        $provider = $this->login($this->credentials['provider_username']);
        $providerSession = $this->sessionCookieValue($provider);
        foreach ($this->deniedRequests() as [$method, $path, $payload]) {
            $response = $provider->requestApp($method, $path, $payload, withCsrfToken: true);
            self::assertSame(403, $response->statusCode, $method . ' ' . $path . ' must be denied.');
            self::assertSame($before, $this->row($this->categoryId));
            self::assertSame($providerSession, $this->sessionCookieValue($provider));
        }

        foreach ($this->deniedRequests() as [$method, $path, $payload]) {
            $response = $anonymous->requestApp($method, $path, $payload, withCsrfToken: true);
            self::assertSame(403, $response->statusCode, $method . ' ' . $path . ' must be denied.');
            self::assertSame($before, $this->row($this->categoryId));
        }
    }

    public function testStoredRoleChangeAppliesToExistingSessionAndPromotionRestoresAccess(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $destinationBefore = $this->sessionDestination($admin);
        $providerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        self::assertNotEmpty($providerRole);
        $session = $this->sessionCookieValue($admin);
        $before = $this->row($this->categoryId);
        try {
            get_instance()->db->update('users', ['id_roles' => $providerRole['id']], ['id' => $this->fixture->actorId]);
            $deniedIndex = $admin->get('service_categories');
            self::assertSame(403, $deniedIndex->statusCode, $deniedIndex->body);
            self::assertSame(403, $admin->get('service_categories/index')->statusCode);
            self::assertSame($before, $this->row($this->categoryId));
            foreach ($this->deniedRequests() as [$method, $path, $payload]) {
                $denied = $admin->requestApp($method, $path, $payload, withCsrfToken: true);
                self::assertSame(403, $denied->statusCode, $method . ' ' . $path . ' must be denied after demotion.');
                self::assertSame($before, $this->row($this->categoryId));
                self::assertSame($session, $this->sessionCookieValue($admin));
            }
            self::assertSame($destinationBefore, $this->sessionDestination($admin));
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->roleBefore['id']],
                ['id' => $this->fixture->actorId],
            );
        }
        $restored = $admin->post('service_categories/search', ['keyword' => $this->fixture->run]);
        self::assertSame(200, $restored->statusCode, $restored->body);
        self::assertNotEmpty($this->json($restored));

        $provider = $this->login($this->credentials['provider_username']);
        $providerRoleBefore = $providerRole;
        try {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->roleBefore['id']],
                ['id' => $this->fixture->providerId],
            );
            self::assertSame(200, $provider->get('service_categories')->statusCode);
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $providerRoleBefore['id']],
                ['id' => $this->fixture->providerId],
            );
        }
    }

    public function testStoreAndUpdateCannotCrossWritePermissionBoundaries(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        $db = get_instance()->db;
        $before = $this->row($this->categoryId);

        $db->update('roles', ['services' => PRIV_ADD], ['id' => $this->roleBefore['id']]);
        $store = $admin->post('service_categories/store', [
            'service_category' => ['id' => $this->categoryId, 'name' => $this->fixture->run . '_cross_store'],
        ]);
        self::assertSame(400, $store->statusCode, $store->body);
        self::assertSame($before, $this->row($this->categoryId));

        $db->update('roles', ['services' => PRIV_EDIT], ['id' => $this->roleBefore['id']]);
        $update = $admin->post('service_categories/update', [
            'service_category' => ['name' => $this->fixture->run . '_cross_update'],
        ]);
        self::assertSame(400, $update->statusCode, $update->body);
        self::assertSame($before, $this->row($this->categoryId));
    }

    /** @return list<array{0:string,1:string,2:array<string,mixed>}> */
    private function deniedRequests(): array
    {
        return [
            ['POST', 'service_categories/search', ['keyword' => $this->fixture->run]],
            ['POST', 'service_categories/find', ['service_category_id' => $this->categoryId]],
            ['POST', 'service_categories/store', ['service_category' => ['name' => $this->fixture->run . '_denied']]],
            [
                'POST',
                'service_categories/update',
                ['service_category' => ['id' => $this->categoryId, 'name' => 'denied']],
            ],
            ['POST', 'service_categories/destroy', ['service_category_id' => $this->categoryId]],
        ];
    }

    private function sessionCookieValue(GateHttpClient $client): ?string
    {
        foreach ($client->cookies() as $name => $value) {
            if (str_contains(strtolower($name), 'session')) {
                return $value;
            }
        }
        return null;
    }

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $path = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/dest_url\\|s:\\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }

    private function insertCategory(string $suffix): int
    {
        $db = get_instance()->db;
        $db->insert('service_categories', [
            'name' => $this->fixture->run . '_' . $suffix,
            'description' => $this->fixture->run,
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insert_id();
    }

    private function row(int $id): array
    {
        return get_instance()
            ->db->get_where('service_categories', ['id' => $id])
            ->row_array() ?:
            [];
    }

    private function roleRow(): array
    {
        return get_instance()
            ->db->get_where('roles', ['id' => $this->fixture->row('users', $this->fixture->actorId)['id_roles']])
            ->row_array();
    }

    private function login(string $username): GateHttpClient
    {
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $username,
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function json(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('exception', $data);
        return $data;
    }
}

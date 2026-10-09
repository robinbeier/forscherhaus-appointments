<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP/DB coverage for the classic Api_settings controller. */
final class ApiSettingsLegacyHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $actorRoleSnapshot = null;
    private ?array $providerRoleSnapshot = null;
    private ?array $adminRoleSnapshot = null;
    private ?array $apiTokenSnapshot = null;

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
            $this->actorRoleSnapshot = $this->fixture->row('users', $this->fixture->actorId);
            $this->providerRoleSnapshot = $this->fixture->row('users', $this->fixture->providerId);
            $this->adminRoleSnapshot = get_instance()
                ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
                ->row_array();
            $this->apiTokenSnapshot = get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixture !== null) {
                $db = get_instance()->db;
                if ($this->actorRoleSnapshot !== null) {
                    $db->update(
                        'users',
                        ['id_roles' => $this->actorRoleSnapshot['id_roles']],
                        [
                            'id' => $this->actorRoleSnapshot['id'],
                        ],
                    );
                }
                if ($this->providerRoleSnapshot !== null) {
                    $db->update(
                        'users',
                        ['id_roles' => $this->providerRoleSnapshot['id_roles']],
                        [
                            'id' => $this->providerRoleSnapshot['id'],
                        ],
                    );
                }
                if ($this->apiTokenSnapshot !== null) {
                    $db->update('settings', $this->apiTokenSnapshot, ['id' => $this->apiTokenSnapshot['id']]);
                    self::assertSame(
                        $this->apiTokenSnapshot,
                        $db->get_where('settings', ['id' => $this->apiTokenSnapshot['id']])->row_array(),
                    );
                }
                if ($this->adminRoleSnapshot !== null) {
                    $db->update('roles', $this->adminRoleSnapshot, ['id' => $this->adminRoleSnapshot['id']]);
                    self::assertSame(
                        $this->adminRoleSnapshot,
                        $db->get_where('roles', ['id' => $this->adminRoleSnapshot['id']])->row_array(),
                    );
                }
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAdminCanReadCanonicalAndDirectIndexAndSaveSyntheticTokenWithCsrf(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);

        foreach (['api_settings', 'api_settings/index'] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringContainsString('id="api-settings-page"', $response->body, $path);
            self::assertStringContainsString('id="api-token"', $response->body, $path);
            self::assertStringContainsString($this->credentials['token'], $response->body, $path);
        }

        $before = $this->apiTokenSnapshot;
        self::assertNotNull($before);
        try {
            $replacement = $fixture->run . '_api_token_replacement';
            $saved = $admin->post('api_settings/save', [
                'api_settings' => [['name' => 'api_token', 'value' => $replacement]],
            ]);
            self::assertSame(200, $saved->statusCode, $saved->body);
            self::assertSame(
                $replacement,
                get_instance()
                    ->db->get_where('settings', ['id' => $before['id'], 'name' => 'api_token'])
                    ->row_array()['value'] ?? null,
            );
            $owned = $fixture->ownedSetting('foreign', 'before');
            $mixedBefore = get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array();
            $mixed = $admin->post('api_settings/save', [
                'api_settings' => [
                    ['name' => 'api_token', 'value' => $fixture->run . '_mixed_token'],
                    ['name' => $owned['name'], 'value' => 'after'],
                ],
            ]);
            self::assertSame(500, $mixed->statusCode, $mixed->body);
            self::assertSame(
                $mixedBefore,
                get_instance()
                    ->db->get_where('settings', ['name' => 'api_token'])
                    ->row_array(),
            );
            self::assertSame($owned, $fixture->settingRow((int) $owned['id']));
        } finally {
            self::assertTrue(get_instance()->db->update('settings', $before, ['id' => $before['id']]));
            self::assertSame(
                $before,
                get_instance()
                    ->db->get_where('settings', ['id' => $before['id']])
                    ->row_array(),
            );
        }
    }

    public function testViewOnlySystemSettingsRoleCannotReadOrWriteApiToken(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $adminRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        $before = get_instance()
            ->db->get_where('settings', ['name' => 'api_token'])
            ->row_array();
        self::assertTrue(
            get_instance()->db->update('roles', ['system_settings' => PRIV_VIEW], ['id' => $adminRole['id']]),
        );
        try {
            $client = $this->login($this->credentials['admin_username']);
            $integrations = $client->get('integrations');
            self::assertSame(200, $integrations->statusCode, $integrations->body);
            self::assertStringNotContainsString(
                'href="' . $this->server?->baseUrl . '/index.php/api_settings"',
                $integrations->body,
            );
            self::assertSame(403, $client->get('api_settings')->statusCode);
            $save = $client->post('api_settings/save', [
                'api_settings' => [['name' => 'api_token', 'value' => $fixture->run . '_view_only']],
            ]);
            self::assertSame(500, $save->statusCode, $save->body);
            self::assertSame(
                $before,
                get_instance()
                    ->db->get_where('settings', ['name' => 'api_token'])
                    ->row_array(),
            );
        } finally {
            self::assertTrue(
                get_instance()->db->update(
                    'roles',
                    ['system_settings' => $adminRole['system_settings']],
                    ['id' => $adminRole['id']],
                ),
            );
        }
    }

    public function testNonGetAndProviderSaveAreDeniedWithoutDatabaseMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);
        $before = get_instance()
            ->db->get_where('settings', ['name' => 'api_token'])
            ->row_array();

        $postIndex = $admin->post('api_settings/index', [], withCsrfToken: true);
        self::assertSame(405, $postIndex->statusCode, $postIndex->body);
        self::assertSame('GET', $postIndex->header('allow'));
        self::assertSame($beforeDestination, $this->sessionDestination($admin));

        foreach (['api_settings', 'api_settings/index'] as $path) {
            foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $deniedMethod = $admin->requestApp($method, $path);
                self::assertSame(405, $deniedMethod->statusCode, $path . ' ' . $method);
                self::assertSame('GET', $deniedMethod->header('allow'));
                self::assertSame($beforeDestination, $this->sessionDestination($admin));
                self::assertSame(
                    $before,
                    get_instance()
                        ->db->get_where('settings', ['name' => 'api_token'])
                        ->row_array(),
                );
            }
        }

        $missingCsrf = $admin->post(
            'api_settings/save',
            [
                'api_settings' => [['name' => 'api_token', 'value' => $fixture->run . '_missing_csrf']],
            ],
            withCsrfToken: false,
        );
        self::assertSame(403, $missingCsrf->statusCode, $missingCsrf->body);
        self::assertSame(
            $before,
            get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array(),
        );

        $provider = $this->login($this->credentials['provider_username']);
        self::assertSame(200, $provider->get('about')->statusCode);
        $providerDestination = $this->sessionDestination($provider);
        $denied = $provider->get('api_settings');
        self::assertSame(403, $denied->statusCode, $denied->body);
        self::assertSame($providerDestination, $this->sessionDestination($provider));

        $deniedSave = $provider->post('api_settings/save', [
            'api_settings' => [['name' => 'api_token', 'value' => $fixture->run . '_denied']],
        ]);
        self::assertSame(500, $deniedSave->statusCode, $deniedSave->body);
        self::assertSame(
            ['success' => false, 'message' => 'You do not have the required permissions for this task.'],
            json_decode($deniedSave->body, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            $before,
            get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array(),
        );
    }

    public function testStoredRoleDemotionAndPromotionApplyToExistingSessions(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $roles = get_instance()->db;
        $adminRole = $roles->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
        $providerRole = $roles->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        self::assertNotEmpty($providerRole['id'] ?? null);

        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('api_settings')->statusCode);
        $destinationBefore = $this->sessionDestination($admin);
        try {
            self::assertTrue($roles->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->actorId]));
            $demoted = $admin->get('api_settings');
            self::assertSame(403, $demoted->statusCode, $demoted->body);
            self::assertSame($destinationBefore, $this->sessionDestination($admin));
            $beforeDeniedSave = get_instance()
                ->db->get_where('settings', ['name' => 'api_token'])
                ->row_array();
            $deniedSave = $admin->post('api_settings/save', [
                'api_settings' => [['name' => 'api_token', 'value' => $fixture->run . '_demoted']],
            ]);
            self::assertSame(500, $deniedSave->statusCode, $deniedSave->body);
            self::assertSame(
                $beforeDeniedSave,
                get_instance()
                    ->db->get_where('settings', ['name' => 'api_token'])
                    ->row_array(),
            );

            $provider = $this->login($this->credentials['provider_username']);
            self::assertSame(403, $provider->get('api_settings')->statusCode);
            self::assertTrue($roles->update('users', ['id_roles' => $adminRole['id']], ['id' => $fixture->providerId]));
            $promoted = $provider->get('api_settings');
            self::assertSame(200, $promoted->statusCode, $promoted->body);
            self::assertStringContainsString('id="api-settings-page"', $promoted->body);
            self::assertStringContainsString('id="save-settings"', $promoted->body);
            $promotedValue = $fixture->run . '_promoted';
            $promotedSave = $provider->post('api_settings/save', [
                'api_settings' => [['name' => 'api_token', 'value' => $promotedValue]],
            ]);
            self::assertSame(200, $promotedSave->statusCode, $promotedSave->body);
            self::assertSame(
                $promotedValue,
                get_instance()
                    ->db->get_where('settings', ['name' => 'api_token'])
                    ->row_array()['value'] ?? null,
            );
        } finally {
            self::assertTrue(
                $roles->update(
                    'users',
                    ['id_roles' => $this->actorRoleSnapshot['id_roles']],
                    ['id' => $fixture->actorId],
                ),
            );
            self::assertTrue(
                $roles->update(
                    'users',
                    ['id_roles' => $this->providerRoleSnapshot['id_roles']],
                    ['id' => $fixture->providerId],
                ),
            );
        }
    }

    private function login(string $username): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $username,
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $path = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($path);
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        try {
            self::assertTrue(flock($handle, LOCK_SH));
            $contents = stream_get_contents($handle);
            self::assertIsString($contents);
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

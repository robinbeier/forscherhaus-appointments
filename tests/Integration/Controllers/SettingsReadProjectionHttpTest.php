<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the legacy general and business settings reads. */
final class SettingsReadProjectionHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $adminRoleSnapshot = null;
    private ?int $actorRoleSnapshot = null;
    private ?int $providerRoleSnapshot = null;

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
            $this->restoreAuthority();
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
                $this->restoreAuthority();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testLegacySettingsRoutesReturnPageProjectionWithoutSecretsOrUnrelatedRows(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $apiMarker = $fixture->run . '_api_token_marker';
        self::assertTrue(get_instance()->db->update('settings', ['value' => $apiMarker], ['name' => 'api_token']));
        $unrelated = $fixture->ownedSetting('unrelated', $fixture->run . '_unrelated_marker');
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        $ldap = get_instance()
            ->db->get_where('settings', ['name' => 'ldap_password'])
            ->row_array();
        self::assertNotEmpty($ldap['id'] ?? null);
        $ldapMarker = $fixture->run . '_ldap_password_marker';
        self::assertTrue(
            get_instance()->db->update(
                'settings',
                ['value' => $ldapMarker],
                ['id' => (int) $ldap['id'], 'name' => 'ldap_password'],
            ),
        );

        try {
            $expected = [
                'general_settings' => [
                    'company_name',
                    'company_link',
                    'company_logo',
                    'company_color',
                    'theme',
                    'date_format',
                    'time_format',
                    'first_weekday',
                    'default_language',
                    'default_timezone',
                    'dashboard_conflict_threshold',
                ],
                'business_settings' => [
                    'book_advance_timeout',
                    'future_booking_limit',
                    'company_working_plan',
                    'appointment_status_options',
                ],
            ];

            foreach (
                ['general_settings', 'general_settings/index', 'business_settings', 'business_settings/index']
                as $path
            ) {
                $response = $admin->get($path);
                self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
                self::assertStringContainsString(
                    'href="' . $this->server?->baseUrl . '/index.php/service_categories"',
                    $response->body,
                    $path,
                );
                foreach (['customers', 'services', 'providers', 'secretaries', 'admins'] as $legacyTarget) {
                    self::assertStringContainsString(
                        'href="' . $this->server?->baseUrl . '/index.php/' . $legacyTarget . '"',
                        $response->body,
                        $path,
                    );
                }
                foreach (['booking_settings', 'legal_settings', 'integrations'] as $legacyTarget) {
                    self::assertStringContainsString(
                        'href="' . $this->server?->baseUrl . '/index.php/' . $legacyTarget . '"',
                        $response->body,
                        $path,
                    );
                }
                if (str_starts_with($path, 'business_settings')) {
                    self::assertStringContainsString(
                        'href="' . $this->server?->baseUrl . '/index.php/blocked_periods"',
                        $response->body,
                        $path,
                    );
                }
                $key = str_starts_with($path, 'general_settings') ? 'general_settings' : 'business_settings';
                $projection = $this->pageSettings($response, $key, $path);
                $names = array_column($projection, 'name');
                $expectedNames = $expected[$key];
                sort($expectedNames);
                sort($names);
                self::assertSame($expectedNames, $names, $path);
                self::assertNotContains('api_token', $names, $path);
                self::assertNotContains('ldap_password', $names, $path);
                self::assertNotContains($unrelated['name'], $names, $path);
                self::assertStringNotContainsString($apiMarker, $response->body, $path);
                self::assertStringNotContainsString($ldapMarker, $response->body, $path);
                self::assertStringNotContainsString($unrelated['value'], $response->body, $path);
                foreach ($projection as $row) {
                    $keys = array_keys($row);
                    sort($keys);
                    self::assertSame(['name', 'value'], $keys, $path);
                }
            }

            $alias = $admin->get('backend/settings');
            self::assertSame(200, $alias->statusCode, $alias->body);
            $this->pageSettings($alias, 'general_settings', 'backend/settings redirect');
        } finally {
            self::assertTrue(
                get_instance()->db->update(
                    'settings',
                    ['value' => $ldap['value']],
                    ['id' => (int) $ldap['id'], 'name' => 'ldap_password'],
                ),
            );
            self::assertSame(
                $ldap,
                get_instance()
                    ->db->get_where('settings', ['id' => (int) $ldap['id'], 'name' => 'ldap_password'])
                    ->row_array(),
            );
        }
    }

    public function testStoredRolePromotionUsesCurrentRoleAndShowsSettingsEditControls(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $adminRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        $this->providerRoleSnapshot = (int) $fixture->row('users', $fixture->providerId)['id_roles'];
        $provider = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        $before = $provider->get('about');
        self::assertSame(200, $before->statusCode);
        self::assertStringNotContainsString(
            'class="dropdown-item" href="' . $this->server?->baseUrl . '/index.php/general_settings"',
            $before->body,
        );
        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => (int) $adminRole['id']], ['id' => $fixture->providerId]),
        );

        foreach (['general_settings', 'business_settings'] as $path) {
            $response = $provider->get($path);
            self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
            self::assertSame('admin', $this->pageVars($response, $path)['role_slug'] ?? null, $path);
            self::assertStringContainsString('id="save-settings"', $response->body, $path);
            self::assertStringContainsString(
                'class="dropdown-item" href="' . $this->server?->baseUrl . '/index.php/general_settings"',
                $response->body,
                $path,
            );
            self::assertStringNotContainsString(
                'href="' . $this->server?->baseUrl . '/index.php/service_categories"',
                $response->body,
                $path,
            );
            foreach (['customers', 'services', 'providers', 'secretaries', 'admins'] as $legacyTarget) {
                self::assertStringNotContainsString(
                    'href="' . $this->server?->baseUrl . '/index.php/' . $legacyTarget . '"',
                    $response->body,
                    $path,
                );
            }
            self::assertStringContainsString(
                'href="' . $this->server?->baseUrl . '/index.php/business_settings"',
                $response->body,
                $path,
            );
            foreach (['booking_settings', 'legal_settings', 'integrations'] as $legacyTarget) {
                self::assertStringNotContainsString(
                    'href="' . $this->server?->baseUrl . '/index.php/' . $legacyTarget . '"',
                    $response->body,
                    $path,
                );
            }
            if ($path === 'business_settings') {
                self::assertStringNotContainsString(
                    'href="' . $this->server?->baseUrl . '/index.php/blocked_periods"',
                    $response->body,
                    $path,
                );
            }
        }
    }

    public function testViewOnlySystemSettingsRoleReadsPagesWithoutEditControls(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->adminRoleSnapshot = $this->roleSnapshot();
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertTrue(
            get_instance()->db->update(
                'roles',
                ['system_settings' => PRIV_VIEW],
                ['id' => $this->adminRoleSnapshot['id']],
            ),
        );

        foreach (['general_settings', 'business_settings'] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringNotContainsString('id="save-settings"', $response->body, $path);
            self::assertStringNotContainsString('id="apply-global-working-plan"', $response->body, $path);
        }
    }

    public function testStoredRoleDemotionBlocksAllSettingsWritesWithoutMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->actorRoleSnapshot = (int) $fixture->row('users', $fixture->actorId)['id_roles'];
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $owned = $fixture->ownedSetting('write-demotion', 'before');
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => (int) $customerRole['id']], ['id' => $fixture->actorId]),
        );
        $beforeWrites = $this->settingsWriteSnapshot();

        $requests = [
            'general_settings/save' => ['general_settings' => [['name' => $owned['name'], 'value' => 'general-after']]],
            'business_settings/save' => [
                'business_settings' => [['name' => $owned['name'], 'value' => 'business-after']],
            ],
            'business_settings/apply_global_working_plan' => [
                'working_plan' => ['monday' => ['start' => '09:00', 'end' => '17:00']],
            ],
        ];
        foreach ($requests as $path => $payload) {
            $response = $admin->post($path, $payload, null, true);
            self::assertSame(500, $response->statusCode, $path . ' ' . $response->body);
            self::assertSame($beforeWrites, $this->settingsWriteSnapshot(), $path);
        }

        $aliases = new GateHttpClient(
            $this->server?->baseUrl ?? '',
            additionalHeaders: ['X-Requested-With' => 'XMLHttpRequest'],
        );
        $this->loginClient($aliases, $this->credentials['admin_username'], $this->credentials['password']);
        foreach (
            [
                'backend_api/ajax_save_settings' => [
                    'general_settings' => [['name' => $owned['name'], 'value' => 'alias-after']],
                ],
                'backend_api/ajax_apply_global_working_plan' => [
                    'working_plan' => ['monday' => ['start' => '09:00', 'end' => '17:00']],
                ],
            ]
            as $path => $payload
        ) {
            $response = $aliases->post($path, $payload);
            self::assertSame(307, $response->statusCode, $path);
            self::assertSame($beforeWrites, $this->settingsWriteSnapshot(), $path);
            $target =
                $path === 'backend_api/ajax_save_settings'
                    ? 'general_settings/save'
                    : 'business_settings/apply_global_working_plan';
            $followed = $aliases->post($target, $payload, null, true);
            self::assertSame(500, $followed->statusCode, $target . ' via ' . $path);
            self::assertSame($beforeWrites, $this->settingsWriteSnapshot(), $path);
        }
    }

    public function testStoredRolePromotionAuthorizesBoundedGeneralSettingsWrite(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->providerRoleSnapshot = (int) $fixture->row('users', $fixture->providerId)['id_roles'];
        $adminRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        $owned = $fixture->ownedSetting('write-promotion', 'before');
        $provider = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => (int) $adminRole['id']], ['id' => $fixture->providerId]),
        );

        $response = $provider->post(
            'general_settings/save',
            [
                'general_settings' => [['name' => $owned['name'], 'value' => 'after-promotion']],
            ],
            null,
            true,
        );
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame('after-promotion', $fixture->settingRow((int) $owned['id'])['value']);
    }

    public function testStoredActorRoleDemotionAndCapabilityRevocationDenyAlreadyLoggedInReads(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->adminRoleSnapshot = $this->roleSnapshot();
        $this->actorRoleSnapshot = (int) $fixture->row('users', $fixture->actorId)['id_roles'];
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);

        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => (int) $customerRole['id']], ['id' => $fixture->actorId]),
        );
        $demotedPage = $admin->get('about');
        self::assertSame(200, $demotedPage->statusCode);
        $userMenuMarker = 'data-tippy-content="' . lang('settings_hint') . '"';
        $menuMarkerOffset = strpos($demotedPage->body, $userMenuMarker);
        self::assertNotFalse($menuMarkerOffset);
        $menuStart = strrpos(substr($demotedPage->body, 0, $menuMarkerOffset), '<li ');
        self::assertNotFalse($menuStart);
        $menuOpeningTag = substr($demotedPage->body, $menuStart, $menuMarkerOffset - $menuStart);
        self::assertStringNotContainsString('d-none', $menuOpeningTag);
        self::assertStringContainsString('href="' . $this->server?->baseUrl . '/index.php/logout"', $demotedPage->body);
        self::assertStringNotContainsString(
            'href="' . $this->server?->baseUrl . '/index.php/general_settings"',
            $demotedPage->body,
        );
        foreach (
            ['general_settings', 'general_settings/index', 'business_settings', 'business_settings/index']
            as $path
        ) {
            $response = $admin->get($path);
            self::assertSame(403, $response->statusCode, $path);
            self::assertStringNotContainsString($fixture->run, $response->body, $path);
        }
        self::assertSame($beforeDestination, $this->sessionDestination($admin));

        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => $this->actorRoleSnapshot], ['id' => $fixture->actorId]),
        );
        self::assertTrue(
            get_instance()->db->update('roles', ['system_settings' => 0], ['id' => $this->adminRoleSnapshot['id']]),
        );
        foreach (['general_settings', 'business_settings'] as $path) {
            $response = $admin->get($path);
            self::assertSame(403, $response->statusCode, $path);
            self::assertStringNotContainsString($fixture->run, $response->body, $path);
        }
    }

    public function testAnonymousDeepLinksPreserveLoginTargets(): void
    {
        $server = $this->server;
        self::assertNotNull($server);
        foreach (
            ['general_settings', 'general_settings/index', 'business_settings', 'business_settings/index']
            as $path
        ) {
            $guest = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'settings-deep-link']);
            $response = $guest->get($path);
            self::assertSame(307, $response->statusCode, $path);
            self::assertStringContainsString('/login', (string) $response->header('location'), $path);
            $target = str_starts_with($path, 'general_settings') ? '/general_settings' : '/business_settings';
            self::assertStringEndsWith($target, $this->sessionDestination($guest), $path);
            self::assertSame(200, $guest->get('login')->statusCode, $path);
        }
    }

    public function testUnsupportedMethodsAreDeniedWithoutExposingSettingMarkers(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $marker = $fixture->run . '_unsupported_marker';
        $beforeToken = get_instance()
            ->db->get_where('settings', ['name' => 'api_token'])
            ->row_array();
        self::assertNotEmpty($beforeToken['id'] ?? null);
        self::assertTrue(get_instance()->db->update('settings', ['value' => $marker], ['name' => 'api_token']));
        $beforeUnsupported = get_instance()
            ->db->get_where('settings', ['id' => (int) $beforeToken['id'], 'name' => 'api_token'])
            ->row_array();
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);
        foreach (
            ['general_settings', 'general_settings/index', 'business_settings', 'business_settings/index']
            as $path
        ) {
            foreach (['HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $admin->requestApp($method, $path, [], null, $method === 'POST');
                $case = $method . ' ' . $path;
                self::assertSame(405, $response->statusCode, $case);
                self::assertStringContainsString('GET', (string) $response->header('allow'), $case);
                self::assertStringNotContainsString($marker, $response->body, $case);
                if ($method === 'HEAD') {
                    self::assertSame('', $response->body, $case);
                }
            }
        }
        self::assertSame($beforeDestination, $this->sessionDestination($admin));
        self::assertSame(
            $beforeUnsupported,
            get_instance()
                ->db->get_where('settings', ['id' => (int) $beforeToken['id'], 'name' => 'api_token'])
                ->row_array(),
        );
    }

    /** @return list<array{name:string,value:mixed}> */
    private function pageSettings(GateHttpResponse $response, string $key, string $case): array
    {
        $this->assertStatus($response, 200, $case);
        $vars = $this->pageVars($response, $case);
        self::assertIsArray($vars[$key] ?? null, $case);
        return array_values($vars[$key]);
    }

    /** @return array<string,mixed> */
    private function pageVars(GateHttpResponse $response, string $case): array
    {
        self::assertSame(1, preg_match('/const vars = (.+?);\s*\n/s', $response->body, $matches), $case);
        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vars, $case);
        return $vars;
    }

    private function login(string $username, string $password): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        $this->loginClient($client, $username, $password);
        return $client;
    }

    private function loginClient(GateHttpClient $client, string $username, string $password): void
    {
        self::assertSame(200, $client->get('login')->statusCode);
        self::assertSame(
            200,
            $client->post('login/validate', ['username' => $username, 'password' => $password])->statusCode,
        );
    }

    /** @return array{id:int,system_settings:int} */
    private function roleSnapshot(): array
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($role['id'] ?? null);
        return ['id' => (int) $role['id'], 'system_settings' => (int) ($role['system_settings'] ?? 0)];
    }

    private function restoreAuthority(): void
    {
        if ($this->actorRoleSnapshot !== null && $this->fixture !== null) {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleSnapshot],
                ['id' => $this->fixture->actorId],
            );
            $this->actorRoleSnapshot = null;
        }
        if ($this->adminRoleSnapshot !== null) {
            get_instance()->db->update(
                'roles',
                ['system_settings' => $this->adminRoleSnapshot['system_settings']],
                ['id' => $this->adminRoleSnapshot['id']],
            );
            $this->adminRoleSnapshot = null;
        }
        if ($this->providerRoleSnapshot !== null && $this->fixture !== null) {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->providerRoleSnapshot],
                ['id' => $this->fixture->providerId],
            );
            $this->providerRoleSnapshot = null;
        }
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
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }

    /** @return array<string, list<array<string,mixed>>> */
    private function settingsWriteSnapshot(): array
    {
        $snapshot = [];
        foreach (['settings', 'user_settings'] as $table) {
            $rows = get_instance()->db->get($table)->result_array();
            usort($rows, static fn(array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));
            $snapshot[$table] = $rows;
        }
        return $snapshot;
    }

    private function assertStatus(GateHttpResponse $response, int $status, string $case): void
    {
        self::assertSame($status, $response->statusCode, $case . ' ' . $response->body);
    }
}

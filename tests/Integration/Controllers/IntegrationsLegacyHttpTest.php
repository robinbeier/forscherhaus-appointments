<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Integrations controller. */
final class IntegrationsLegacyHttpTest extends TestCase
{
    private const ROUTES = ['integrations', 'integrations/index'];

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private array $secretSettingSnapshots = [];
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
            $this->restoreRoles();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->restoreRoles();
        } finally {
            try {
                $this->restoreSecretSettings();
            } finally {
                try {
                    $this->server?->close();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
        }
    }

    public function testAnonymousRoutesRedirectToLoginWithoutExposingIntegrationSecrets(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);
        $this->seedSecretMarkers();

        foreach (self::ROUTES as $path) {
            $guest = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'rob-683']);
            $response = $guest->get($path);
            self::assertSame(307, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringContainsString('/login', (string) $response->header('location'), $path);
            self::assertStringNotContainsString($fixture->run . '_api_token_marker', $response->body, $path);
            self::assertStringNotContainsString($fixture->run . '_ldap_password_marker', $response->body, $path);
            self::assertStringNotContainsString($fixture->run . '_google_integration', $response->body, $path);
            self::assertStringNotContainsString($fixture->run . '_caldav_integration', $response->body, $path);
            self::assertStringContainsString('/index.php/integrations', $this->sessionDestination($guest), $path);
        }
    }

    public function testAdminCanReadBothRoutesWithoutExposingIntegrationSecrets(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->seedSecretMarkers();
        $admin = $this->login($this->credentials['admin_username']);

        foreach (self::ROUTES as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringContainsString('id="integrations-page"', $response->body, $path);
            self::assertStringNotContainsString($fixture->run . '_api_token_marker', $response->body, $path);
            self::assertStringNotContainsString($fixture->run . '_ldap_password_marker', $response->body, $path);
            self::assertStringNotContainsString($fixture->run . '_google_integration', $response->body, $path);
            self::assertStringNotContainsString($fixture->run . '_caldav_integration', $response->body, $path);
            self::assertSame('admin', $this->pageVars($response, $path)['role_slug'] ?? null, $path);
        }
    }

    public function testStoredRoleRevocationDeniesBothRoutesWithoutMutationOrSessionChange(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->actorRoleSnapshot = (int) $fixture->row('users', $fixture->actorId)['id_roles'];
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('integrations')->statusCode);
        $beforeSession = $this->sessionDestination($admin);
        $beforeDb = $this->settingsSnapshot();

        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => (int) $customerRole['id']], ['id' => $fixture->actorId]),
        );

        foreach (self::ROUTES as $path) {
            $response = $admin->get($path);
            self::assertSame(403, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringNotContainsString($fixture->run, $response->body, $path);
            self::assertSame($beforeSession, $this->sessionDestination($admin), $path);
            self::assertSame($beforeDb, $this->settingsSnapshot(), $path);
        }
    }

    public function testStoredRolePromotionGrantsCurrentReadPermission(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->providerRoleSnapshot = (int) $fixture->row('users', $fixture->providerId)['id_roles'];
        $adminRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        $provider = $this->login($this->credentials['provider_username']);
        self::assertSame(403, $provider->get('integrations')->statusCode);
        self::assertTrue(
            get_instance()->db->update('users', ['id_roles' => (int) $adminRole['id']], ['id' => $fixture->providerId]),
        );

        foreach (self::ROUTES as $path) {
            $response = $provider->get($path);
            self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringContainsString('id="integrations-page"', $response->body, $path);
            self::assertSame('admin', $this->pageVars($response, $path)['role_slug'] ?? null, $path);
            self::assertStringContainsString(
                'href="' . $this->server?->baseUrl . '/index.php/api_settings"',
                $response->body,
                $path,
            );
            self::assertStringNotContainsString($fixture->run . '_google_integration', $response->body, $path);
            self::assertStringNotContainsString($fixture->run . '_caldav_integration', $response->body, $path);
        }
    }

    public function testUnsupportedMethodsAreRejectedWithoutMutationOrSessionChange(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $this->seedSecretMarkers();
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $beforeSession = $this->sessionDestination($admin);
        $beforeDb = $this->settingsSnapshot();

        foreach (self::ROUTES as $path) {
            foreach (['HEAD', 'POST', 'PUT'] as $method) {
                $response = $admin->requestApp($method, $path, [], null, $method === 'POST');
                $case = $method . ' ' . $path;
                self::assertSame(405, $response->statusCode, $case);
                self::assertStringContainsString('GET', (string) $response->header('allow'), $case);
                self::assertStringNotContainsString($fixture->run, $response->body, $case);
                if ($method === 'HEAD') {
                    self::assertSame('', $response->body, $case);
                }
                self::assertSame($beforeSession, $this->sessionDestination($admin), $case);
                self::assertSame($beforeDb, $this->settingsSnapshot(), $case);
            }
        }
    }

    private function seedSecretMarkers(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $api = get_instance()
            ->db->get_where('settings', ['name' => 'api_token'])
            ->row_array();
        $ldap = get_instance()
            ->db->get_where('settings', ['name' => 'ldap_password'])
            ->row_array();
        self::assertNotEmpty($api['id'] ?? null);
        self::assertNotEmpty($ldap['id'] ?? null);
        $this->secretSettingSnapshots = [$api, $ldap];
        self::assertTrue(
            get_instance()->db->update(
                'settings',
                ['value' => $fixture->run . '_api_token_marker'],
                ['name' => 'api_token'],
            ),
        );
        self::assertTrue(
            get_instance()->db->update(
                'settings',
                ['value' => $fixture->run . '_ldap_password_marker'],
                ['id' => (int) $ldap['id'], 'name' => 'ldap_password'],
            ),
        );
    }

    private function restoreSecretSettings(): void
    {
        $firstFailure = null;
        foreach ($this->secretSettingSnapshots as $snapshot) {
            try {
                self::assertTrue(get_instance()->db->update('settings', $snapshot, ['id' => $snapshot['id']]));
                self::assertSame(
                    $snapshot,
                    get_instance()
                        ->db->get_where('settings', ['id' => $snapshot['id']])
                        ->row_array(),
                );
            } catch (Throwable $error) {
                $firstFailure ??= $error;
            }
        }
        if ($firstFailure !== null) {
            throw $firstFailure;
        }
        $this->secretSettingSnapshots = [];
    }

    /** @return array<string, mixed> */
    private function pageVars(GateHttpResponse $response, string $path): array
    {
        self::assertSame(1, preg_match('/const vars = (.+?);\s*\n/s', $response->body, $matches), $path);
        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vars, $path);
        return $vars;
    }

    private function login(string $username): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        self::assertSame(
            200,
            $client->post('login/validate', [
                'username' => $username,
                'password' => $this->credentials['password'],
            ])->statusCode,
        );
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
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function settingsSnapshot(): array
    {
        $snapshot = [];
        foreach (['settings', 'user_settings'] as $table) {
            $rows = get_instance()->db->get($table)->result_array();
            usort($rows, static fn(array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));
            $snapshot[$table] = $rows;
        }
        return $snapshot;
    }

    private function restoreRoles(): void
    {
        if ($this->fixture === null) {
            return;
        }
        if ($this->actorRoleSnapshot !== null) {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->actorRoleSnapshot],
                    ['id' => $this->fixture->actorId],
                ),
            );
            self::assertSame(
                $this->actorRoleSnapshot,
                (int) get_instance()
                    ->db->get_where('users', ['id' => $this->fixture->actorId])
                    ->row_array()['id_roles'],
            );
            $this->actorRoleSnapshot = null;
        }
        if ($this->providerRoleSnapshot !== null) {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->providerRoleSnapshot],
                    ['id' => $this->fixture->providerId],
                ),
            );
            self::assertSame(
                $this->providerRoleSnapshot,
                (int) get_instance()
                    ->db->get_where('users', ['id' => $this->fixture->providerId])
                    ->row_array()['id_roles'],
            );
            $this->providerRoleSnapshot = null;
        }
    }
}

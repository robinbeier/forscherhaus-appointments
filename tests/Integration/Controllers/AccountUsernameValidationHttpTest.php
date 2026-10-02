<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP/DB coverage for the account username validation endpoints. */
final class AccountUsernameValidationHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array<string, string> */
    private array $credentials = [];
    private array $actorBefore = [];
    private array $actorSettingsBefore = [];

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
            $this->actorBefore = $this->fixture->row('users', $this->fixture->actorId);
            $this->actorSettingsBefore = $this->fixture->userSettingsRow($this->fixture->actorId);
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
                if ($this->actorBefore !== []) {
                    $db->update('users', $this->actorBefore, ['id' => $this->actorBefore['id']]);
                    $db->update('user_settings', $this->actorSettingsBefore, [
                        'id_users' => $this->actorSettingsBefore['id_users'],
                    ]);
                }
                self::assertSame($this->actorBefore, $this->fixture->row('users', $this->fixture->actorId));
                self::assertSame($this->actorSettingsBefore, $this->fixture->userSettingsRow($this->fixture->actorId));
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAnonymousAndNonPostRequestsAreDeniedAndCsrfIsRequired(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $actorBefore = $fixture->row('users', $fixture->actorId);
        $settingsBefore = $fixture->userSettingsRow($fixture->actorId);
        $server = $this->server;
        self::assertNotNull($server);
        $anonymous = $server->client();
        self::assertSame(200, $anonymous->get('login')->statusCode);
        $anonymousStatus = [];

        foreach ($this->paths() as $path) {
            $get = $anonymous->get($path);
            $anonymousStatus[$path]['get'] = [$get->statusCode, $get->header('allow')];
            $post = $anonymous->post($path, $this->payload('anonymous'));
            $anonymousStatus[$path]['post_with_csrf'] = $post->statusCode;
            $missingCsrf = $anonymous->post($path, $this->payload('anonymous'), withCsrfToken: false);
            $anonymousStatus[$path]['post_without_csrf'] = $missingCsrf->statusCode;
        }
        self::assertSame(
            array_fill_keys($this->paths(), [
                'get' => [405, 'POST'],
                'post_with_csrf' => 403,
                'post_without_csrf' => 403,
            ]),
            $anonymousStatus,
            'Anonymous requests must not reveal username availability through either route.',
        );

        $admin = $this->login($this->credentials['admin_username']);
        foreach ($this->paths() as $path) {
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                $response = $admin->requestApp($method, $path);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path);
                self::assertSame('POST', $response->header('allow'), $method . ' ' . $path);
            }
            $missingCsrf = $admin->post($path, $this->payload('missing-csrf'), withCsrfToken: false);
            self::assertSame(403, $missingCsrf->statusCode, 'POST without CSRF ' . $path);
        }

        self::assertSame($actorBefore, $fixture->row('users', $fixture->actorId));
        self::assertSame($settingsBefore, $fixture->userSettingsRow($fixture->actorId));
    }

    public function testAdminAndSecretaryCanValidate(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $secretaryRole = $db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])->row_array();
        self::assertNotEmpty($secretaryRole['id'] ?? null);

        $admin = $this->login($this->credentials['admin_username']);
        $this->assertValidResponses($admin, 'admin');

        try {
            self::assertTrue($db->update('users', ['id_roles' => $secretaryRole['id']], ['id' => $fixture->actorId]));
            $secretary = $this->login($this->credentials['admin_username']);
            $this->assertValidResponses($secretary, 'secretary');
        } finally {
            self::assertTrue(
                $db->update(
                    'users',
                    ['id_roles' => $this->actorBefore['id_roles']],
                    [
                        'id' => $fixture->actorId,
                    ],
                ),
            );
        }
    }

    public function testDemotedExistingSessionIsDeniedWithoutSessionOrDatabaseSideEffects(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $customerRole = $db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('account')->statusCode);
        $sessionCookie = (string) config('sess_cookie_name');
        $sessionIdBefore = $admin->getCookie($sessionCookie);
        $destinationBefore = $this->sessionDestination($admin);
        $settingsBefore = $fixture->userSettingsRow($fixture->actorId);

        try {
            self::assertTrue($db->update('users', ['id_roles' => $customerRole['id']], ['id' => $fixture->actorId]));
            $demotedActor = $fixture->row('users', $fixture->actorId);
            foreach ($this->paths() as $path) {
                $response = $admin->post($path, $this->payload('demoted'));
                self::assertSame(403, $response->statusCode, 'Demoted POST ' . $path);
                self::assertSame($demotedActor, $fixture->row('users', $fixture->actorId));
                self::assertSame($settingsBefore, $fixture->userSettingsRow($fixture->actorId));
                self::assertSame($sessionIdBefore, $admin->getCookie($sessionCookie), 'Session changed for ' . $path);
                self::assertSame($destinationBefore, $this->sessionDestination($admin));
            }
        } finally {
            self::assertTrue(
                $db->update(
                    'users',
                    ['id_roles' => $this->actorBefore['id_roles']],
                    [
                        'id' => $fixture->actorId,
                    ],
                ),
            );
        }
    }

    public function testCallerSuppliedUserIdExcludesOnlyTheSelectedExistingUsername(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        $existing = (string) $this->actorSettingsBefore['username'];

        foreach ($this->paths() as $path) {
            $sameUser = $admin->post($path, ['username' => $existing, 'user_id' => $fixture->actorId]);
            self::assertSame(200, $sameUser->statusCode, $path);
            self::assertSame(['is_valid' => true], json_decode($sameUser->body, true, 512, JSON_THROW_ON_ERROR));

            $otherUser = $admin->post($path, ['username' => $existing, 'user_id' => $fixture->customerId]);
            self::assertSame(200, $otherUser->statusCode, $path);
            self::assertSame(['is_valid' => false], json_decode($otherUser->body, true, 512, JSON_THROW_ON_ERROR));

            $unique = $admin->post($path, ['username' => $fixture->run . '_unique', 'user_id' => $fixture->actorId]);
            self::assertSame(200, $unique->statusCode, $path);
            self::assertSame(['is_valid' => true], json_decode($unique->body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testAdminCanExcludeManagedSecretaryButNotAnUnrelatedRole(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $secretaryRole = $db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])->row_array();
        self::assertNotEmpty($secretaryRole['id'] ?? null);
        $providerBefore = $fixture->row('users', $fixture->providerId);
        $providerSettings = $fixture->userSettingsRow($fixture->providerId);
        $admin = $this->login($this->credentials['admin_username']);

        try {
            self::assertTrue(
                $db->update(
                    'users',
                    ['id_roles' => $secretaryRole['id']],
                    [
                        'id' => $fixture->providerId,
                    ],
                ),
            );
            foreach ($this->paths() as $path) {
                $response = $admin->post($path, [
                    'username' => $providerSettings['username'],
                    'user_id' => $fixture->providerId,
                ]);
                self::assertSame(200, $response->statusCode, $path);
                self::assertSame(['is_valid' => true], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
            }
        } finally {
            self::assertTrue(
                $db->update(
                    'users',
                    ['id_roles' => $providerBefore['id_roles']],
                    [
                        'id' => $fixture->providerId,
                    ],
                ),
            );
        }

        foreach ($this->paths() as $path) {
            $response = $admin->post($path, [
                'username' => $providerSettings['username'],
                'user_id' => $fixture->providerId,
            ]);
            self::assertSame(200, $response->statusCode, $path);
            self::assertSame(['is_valid' => false], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testProviderCannotExcludeAnotherActorsUsername(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $provider = $this->login($this->credentials['provider_username']);
        $providerUsername = $fixture->run . '_provider';
        $adminUsername = (string) $this->actorSettingsBefore['username'];

        foreach ($this->paths() as $path) {
            $own = $provider->post($path, ['username' => $providerUsername, 'user_id' => $fixture->providerId]);
            self::assertSame(200, $own->statusCode, $path . ' ' . $own->body);
            self::assertSame(['is_valid' => true], json_decode($own->body, true, 512, JSON_THROW_ON_ERROR));

            $other = $provider->post($path, ['username' => $adminUsername, 'user_id' => $fixture->actorId]);
            self::assertSame(200, $other->statusCode, $path . ' ' . $other->body);
            self::assertSame(
                ['is_valid' => false],
                json_decode($other->body, true, 512, JSON_THROW_ON_ERROR),
                'Caller-supplied ID must not exempt another account on ' . $path,
            );
        }
    }

    /** @return list<string> */
    private function paths(): array
    {
        return ['account/validate_username', 'backend_api/ajax_validate_username'];
    }

    /** @return array{username:string,user_id:int} */
    private function payload(string $suffix): array
    {
        return ['username' => $this->fixture?->run . '_' . $suffix, 'user_id' => $this->fixture?->actorId ?? 0];
    }

    private function assertValidResponses(GateHttpClient $client, string $role): void
    {
        foreach ($this->paths() as $path) {
            $response = $client->post($path, $this->payload($role));
            self::assertSame(200, $response->statusCode, $role . ' POST ' . $path . ' ' . $response->body);
            self::assertSame(
                ['is_valid' => true],
                json_decode($response->body, true, 512, JSON_THROW_ON_ERROR),
                $role . ' POST ' . $path,
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
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

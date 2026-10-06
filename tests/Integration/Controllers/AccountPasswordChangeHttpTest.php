<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP/DB coverage for authenticated account password changes. */
final class AccountPasswordChangeHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array<string, string> */
    private array $credentials = [];
    private array $actorBefore = [];
    private array $actorSettingsBefore = [];
    private array $foreignBefore = [];
    private array $foreignSettingsBefore = [];

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
            $this->foreignBefore = $this->fixture->row('users', $this->fixture->providerId);
            $this->foreignSettingsBefore = $this->fixture->userSettingsRow($this->fixture->providerId);
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
                    $db->update('users', $this->actorBefore, ['id' => $this->fixture->actorId]);
                    $db->update('user_settings', $this->actorSettingsBefore, [
                        'id_users' => $this->fixture->actorId,
                    ]);
                }
                self::assertSame($this->actorBefore, $this->fixture->row('users', $this->fixture->actorId));
                self::assertSame($this->actorSettingsBefore, $this->fixture->userSettingsRow($this->fixture->actorId));
                self::assertSame($this->foreignBefore, $this->fixture->row('users', $this->fixture->providerId));
                self::assertSame(
                    $this->foreignSettingsBefore,
                    $this->fixture->userSettingsRow($this->fixture->providerId),
                );
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAuthenticatedPasswordChangeUsesOwnRowOnCanonicalAndDirectIndexAlias(): void
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);

        $oldPassword = $this->credentials['password'];
        $beforeHash = $this->actorSettingsBefore['password'] ?? null;
        self::assertIsString($beforeHash);
        self::assertNotEmpty($this->foreignSettingsBefore['password'] ?? null);

        foreach (
            [
                ['account/save', new GateHttpClient($this->server->baseUrl)],
                ['index.php/account/save', new GateHttpClient($this->server->baseUrl, indexPage: '')],
            ]
            as [$path, $client]
        ) {
            $newPassword = $this->fixture->run . '_new_' . ($path === 'account/save' ? 'canonical' : 'alias');
            $admin = $this->login($client, $oldPassword);
            $account = $this->accountPayload($this->actorBefore, $this->actorSettingsBefore);
            $account['id'] = (string) $this->fixture->providerId;
            $account['settings']['password'] = $newPassword;

            $saved = $admin->post($path, ['account' => $account]);
            self::assertSame(200, $saved->statusCode, $path . ' must accept the authenticated password change.');
            self::assertStringNotContainsString($oldPassword, $saved->body, $path);
            self::assertStringNotContainsString($newPassword, $saved->body, $path);

            $actorSettings = $this->fixture->userSettingsRow($this->fixture->actorId);
            self::assertNotSame($beforeHash, $actorSettings['password'] ?? null, $path);
            self::assertTrue(
                $this->loginResult($this->server->client(), $this->credentials['admin_username'], $newPassword),
                'New password must authenticate through the real login endpoint.',
            );
            self::assertFalse(
                $this->loginResult($this->server->client(), $this->credentials['admin_username'], $oldPassword),
                'Old password must stop authenticating after the account save.',
            );
            self::assertSame($this->foreignBefore, $this->fixture->row('users', $this->fixture->providerId));
            self::assertSame(
                $this->foreignSettingsBefore,
                $this->fixture->userSettingsRow($this->fixture->providerId),
                'Caller-supplied foreign ID must not alter another user settings row.',
            );
            self::assertSame(
                $this->foreignSettingsBefore['password'],
                $this->fixture->userSettingsRow($this->fixture->providerId)['password'] ?? null,
                'Caller-supplied foreign ID must not alter the other account password hash.',
            );

            $oldPassword = $newPassword;
            $beforeHash = $actorSettings['password'];
        }

        $serverLog = file_get_contents($this->server->directory . '/server.log');
        self::assertIsString($serverLog);
        self::assertStringNotContainsString($this->credentials['password'], $serverLog);
        self::assertStringNotContainsString($this->fixture->run . '_new_canonical', $serverLog);
        self::assertStringNotContainsString($this->fixture->run . '_new_alias', $serverLog);
    }

    /** @return array<string, mixed> */
    private function accountPayload(array $user, array $settings): array
    {
        return [
            'id' => (string) $user['id'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'mobile_number' => $user['mobile_number'] ?? '',
            'phone_number' => $user['phone_number'] ?? '',
            'address' => $user['address'] ?? '',
            'city' => $user['city'] ?? '',
            'state' => $user['state'] ?? '',
            'zip_code' => $user['zip_code'] ?? '',
            'notes' => $user['notes'] ?? '',
            'language' => $user['language'],
            'timezone' => $user['timezone'],
            'settings' => [
                'username' => $settings['username'],
                'password' => '',
                'calendar_view' => $settings['calendar_view'] ?? 'default',
            ],
        ];
    }

    private function login(GateHttpClient $client, string $password): GateHttpClient
    {
        $result = $this->loginResult($client, $this->credentials['admin_username'], $password);
        self::assertTrue($result, 'Synthetic admin login must succeed.');
        return $client;
    }

    private function loginResult(GateHttpClient $client, string $username, string $password): bool
    {
        self::assertSame(200, $client->get('login')->statusCode, 'Login page must be available.');
        $response = $client->post('login/validate', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertStringNotContainsString($password, $response->body, 'Login diagnostics must not echo passwords.');
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        return (bool) ($decoded['success'] ?? false);
    }
}

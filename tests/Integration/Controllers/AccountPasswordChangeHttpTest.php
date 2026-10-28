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
                ['account/save', new GateHttpClient($this->server->baseUrl, indexPage: '')],
                ['index.php/account/save', new GateHttpClient($this->server->baseUrl, indexPage: '')],
            ]
            as [$path, $client]
        ) {
            $newPassword = $path === 'account/save' ? 'valid77' : $this->fixture->run . '_new_alias';
            $admin = $this->login($client, $oldPassword);
            $account = $this->accountPayload($this->actorBefore, $this->actorSettingsBefore);
            $account['id'] = (string) $this->fixture->providerId;
            $account['settings']['password'] = $newPassword;

            $saved = $admin->post($path, ['account' => $account]);
            self::assertSame(200, $saved->statusCode, $path . ' must accept the authenticated password change.');
            self::assertSame('/' . $path, parse_url($saved->url, PHP_URL_PATH));
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
        self::assertStringNotContainsString('valid77', $serverLog);
        self::assertStringNotContainsString($this->fixture->run . '_new_alias', $serverLog);
    }

    public function testInvalidPasswordValuesAreRejectedOnBothAccountSaveAliasesWithoutMutation(): void
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);

        // Form encoding normalizes non-string scalar/list types; those are covered by direct model tests.
        $values = ['0', 'a', 'ab', 'abc', 'abcd', 'abcde', 'abcdef'];
        foreach (['account/save', 'index.php/account/save'] as $path) {
            foreach ($values as $value) {
                $admin = $this->login(
                    new GateHttpClient($this->server->baseUrl, indexPage: ''),
                    $this->credentials['password'],
                );
                $account = $this->accountPayload($this->actorBefore, $this->actorSettingsBefore);
                $account['settings']['password'] = $value;

                $response = $admin->post($path, ['account' => $account]);
                self::assertSame(500, $response->statusCode, $path . ' must reject invalid passwords.');
                $error = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertFalse($error['success']);
                self::assertSame(
                    'The user password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.',
                    $error['message'],
                );
                self::assertSame($this->actorBefore, $this->fixture->row('users', $this->fixture->actorId));
                self::assertSame(
                    $this->actorSettingsBefore,
                    $this->fixture->userSettingsRow($this->fixture->actorId),
                    $path . ' must not mutate settings for an invalid password.',
                );
            }
        }
    }

    public function testDatabaseFailureAfterUserWriteReturnsGenericHttp500AndRollsBack(): void
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);

        $db = get_instance()->db;
        $fixtureAdmin = get_instance()->load->database(
            [
                'hostname' => 'mysql',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'easyappointments',
                'dbdriver' => 'mysqli',
                'dbprefix' => $db->dbprefix,
                'pconnect' => false,
                'db_debug' => false,
                'char_set' => 'utf8mb4',
                'dbcollat' => 'utf8mb4_general_ci',
            ],
            true,
        );
        $trigger = $this->fixture->run . '_deny_account_settings_update';
        $markerTable = $this->fixture->run . '_account_settings_marker';
        $createdTrigger = false;
        $createdMarker = false;
        try {
            self::assertTrue(
                (bool) $fixtureAdmin->query(
                    'CREATE TABLE `' . $markerTable . '` (`marker` VARCHAR(255) NOT NULL) ENGINE=MEMORY',
                ),
            );
            $createdMarker = true;
            self::assertTrue(
                (bool) $fixtureAdmin->query(
                    'CREATE TRIGGER `' .
                        $trigger .
                        '` BEFORE UPDATE ON `' .
                        $db->dbprefix('user_settings') .
                        '` FOR EACH ROW BEGIN IF NEW.id_users = ' .
                        (int) $this->fixture->actorId .
                        ' THEN INSERT INTO `' .
                        $markerTable .
                        '` (`marker`) VALUES (\'user-write-before-settings-failure\');' .
                        ' SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'synthetic account settings failure\'; END IF; END',
                ),
            );
            $createdTrigger = true;

            $beforeUser = $this->fixture->row('users', $this->fixture->actorId);
            $beforeSettings = $this->fixture->userSettingsRow($this->fixture->actorId);
            $admin = $this->login($this->server->client(), $this->credentials['password']);
            $account = $this->accountPayload($beforeUser, $beforeSettings);
            $account['first_name'] = $this->fixture->run . '_must_rollback';
            $account['settings']['password'] = $this->fixture->run . '_valid_new_password';

            $response = $admin->post('account/save', ['account' => $account]);
            self::assertSame(500, $response->statusCode, $response->body);
            self::assertStringContainsString('An unexpected error occurred.', $response->body);
            self::assertStringNotContainsString('synthetic account settings failure', $response->body);
            self::assertSame($beforeUser, $this->fixture->row('users', $this->fixture->actorId));
            self::assertSame($beforeSettings, $this->fixture->userSettingsRow($this->fixture->actorId));
            self::assertSame(
                [['marker' => 'user-write-before-settings-failure']],
                $fixtureAdmin->query('SELECT `marker` FROM `' . $markerTable . '`')->result_array(),
            );
        } finally {
            if ($createdTrigger) {
                $fixtureAdmin->query('DROP TRIGGER `' . $trigger . '`');
            }
            if ($createdMarker) {
                $fixtureAdmin->query('DROP TABLE `' . $markerTable . '`');
            }
            $fixtureAdmin->close();
        }
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

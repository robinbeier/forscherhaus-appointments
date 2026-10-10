<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;
use Tests\Integration\Support\SessionFileReader;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';
require_once dirname(__DIR__) . '/Support/SessionFileReader.php';

/** Bounded HTTP/DB coverage for the classic Ldap_settings controller. */
final class LdapSettingsLegacyHttpTest extends TestCase
{
    /** @var list<string> */
    private const LDAP_NAMES = ['ldap_is_active', 'ldap_host', 'ldap_port'];

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $actorSnapshot = null;
    private ?array $providerSnapshot = null;
    private ?array $adminRoleSnapshot = null;
    private ?array $providerRoleSnapshot = null;
    /** @var array<string, array<string, mixed>> */
    private array $ldapSnapshots = [];

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
            $db = get_instance()->db;
            $this->actorSnapshot = $this->fixture->row('users', $this->fixture->actorId);
            $this->providerSnapshot = $this->fixture->row('users', $this->fixture->providerId);
            $this->adminRoleSnapshot = $db->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
            $this->providerRoleSnapshot = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
            foreach (self::LDAP_NAMES as $name) {
                $row = $db->get_where('settings', ['name' => $name])->row_array();
                self::assertNotEmpty($row, 'The synthetic stack must seed ' . $name . '.');
                $this->ldapSnapshots[$name] = $row;
            }
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
                if ($this->actorSnapshot !== null) {
                    $db->update(
                        'users',
                        ['id_roles' => $this->actorSnapshot['id_roles']],
                        ['id' => $this->actorSnapshot['id']],
                    );
                }
                if ($this->providerSnapshot !== null) {
                    $db->update(
                        'users',
                        ['id_roles' => $this->providerSnapshot['id_roles']],
                        ['id' => $this->providerSnapshot['id']],
                    );
                }
                if ($this->adminRoleSnapshot !== null) {
                    $db->update('roles', $this->adminRoleSnapshot, ['id' => $this->adminRoleSnapshot['id']]);
                    self::assertSame(
                        $this->adminRoleSnapshot,
                        $db->get_where('roles', ['id' => $this->adminRoleSnapshot['id']])->row_array(),
                    );
                }
                if ($this->providerRoleSnapshot !== null) {
                    $db->update('roles', $this->providerRoleSnapshot, ['id' => $this->providerRoleSnapshot['id']]);
                    self::assertSame(
                        $this->providerRoleSnapshot,
                        $db->get_where('roles', ['id' => $this->providerRoleSnapshot['id']])->row_array(),
                    );
                }
                foreach ($this->ldapSnapshots as $name => $row) {
                    $db->update('settings', $row, ['id' => $row['id'], 'name' => $name]);
                    self::assertSame(
                        $row,
                        $db->get_where('settings', ['id' => $row['id'], 'name' => $name])->row_array(),
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

    public function testAdminCanReadCanonicalAndDirectIndexAndSaveAllowedSettingsWithCsrf(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);

        foreach (['ldap_settings', 'ldap_settings/index'] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringContainsString('id="ldap-settings-page"', $response->body, $path);
            self::assertStringContainsString('id="ldap-is-active"', $response->body, $path);
        }

        $foreign = $fixture->ownedSetting('foreign', 'before');
        $replacement = [
            ['name' => 'ldap_is_active', 'value' => '1', 'id' => $foreign['id'], 'extra' => 'discard-me'],
            ['name' => 'ldap_host', 'value' => $fixture->run . '.ldap.synthetic.invalid', 'extra' => 'discard-me-too'],
            ['name' => 'ldap_port', 'value' => '1636'],
        ];
        $saved = $admin->post('ldap_settings/save', ['ldap_settings' => $replacement]);
        self::assertSame(200, $saved->statusCode, $saved->body);
        $db = get_instance()->db;
        self::assertSame('1', $db->get_where('settings', ['name' => 'ldap_is_active'])->row_array()['value'] ?? null);
        self::assertSame(
            $fixture->run . '.ldap.synthetic.invalid',
            $db->get_where('settings', ['name' => 'ldap_host'])->row_array()['value'] ?? null,
        );
        self::assertSame('1636', $db->get_where('settings', ['name' => 'ldap_port'])->row_array()['value'] ?? null);
        self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
    }

    public function testClassicMethodsAndCsrfRejectInvalidWritesWithoutMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('ldap_settings')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);
        $before = $this->ldapRows();

        $postIndex = $admin->post('ldap_settings/index', [], withCsrfToken: true);
        self::assertSame(405, $postIndex->statusCode, $postIndex->body);
        self::assertSame('GET', $postIndex->header('allow'));
        foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $admin->requestApp($method, 'ldap_settings', [], null, false);
            self::assertSame(405, $response->statusCode, $method);
            self::assertSame('GET', $response->header('allow'));
            self::assertSame($before, $this->ldapRows());
        }

        $saveGet = $admin->get('ldap_settings/save');
        self::assertSame(405, $saveGet->statusCode, $saveGet->body);
        self::assertSame('POST', $saveGet->header('allow'));
        $missingCsrf = $admin->post(
            'ldap_settings/save',
            ['ldap_settings' => [['name' => 'ldap_host', 'value' => $fixture->run . '.missing-csrf']]],
            withCsrfToken: false,
        );
        self::assertSame(403, $missingCsrf->statusCode, $missingCsrf->body);
        self::assertSame($before, $this->ldapRows());
        self::assertSame($beforeDestination, $this->sessionDestination($admin));
    }

    public function testMixedForeignSettingBatchIsRejectedAtomically(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreign = $fixture->ownedSetting('foreign_batch', 'before');
        $admin = $this->login($this->credentials['admin_username']);
        $before = $this->ldapRows();
        $mixed = $admin->post('ldap_settings/save', [
            'ldap_settings' => [
                ['name' => 'ldap_host', 'value' => $fixture->run . '.mixed.invalid'],
                ['name' => $foreign['name'], 'value' => 'after'],
            ],
        ]);
        self::assertSame(500, $mixed->statusCode, $mixed->body);
        self::assertSame($before, $this->ldapRows());
        self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
    }

    public function testViewOnlyStoredRoleRendersWithoutUndefinedUserWarningOrSaveControl(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->actorSnapshot);
        self::assertNotNull($this->providerRoleSnapshot);
        $db = get_instance()->db;
        $admin = $this->login($this->credentials['admin_username']);
        $providerRoleId = (int) $this->providerRoleSnapshot['id'];

        try {
            self::assertTrue($db->update('roles', ['system_settings' => PRIV_VIEW], ['id' => $providerRoleId]));
            self::assertTrue($db->update('users', ['id_roles' => $providerRoleId], ['id' => $fixture->actorId]));

            $response = $admin->get('ldap_settings');
            self::assertSame(200, $response->statusCode, $response->body);
            self::assertStringNotContainsString('id="save-settings"', $response->body);
            self::assertStringNotContainsString('Undefined variable', $response->body);
            self::assertStringNotContainsString('Warning:', $response->body);
        } finally {
            $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]);
            $db->update('roles', $this->providerRoleSnapshot, ['id' => $providerRoleId]);
        }
    }

    public function testStoredSystemSettingsDemotionAndPromotionApplyToExistingSession(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $before = $this->ldapRows();
        $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($providerRole['id'] ?? null);
        self::assertNotNull($this->actorSnapshot);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('ldap_settings')->statusCode);
        $destination = $this->sessionDestination($admin);
        try {
            self::assertTrue($db->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->actorId]));
            self::assertSame(403, $admin->get('ldap_settings')->statusCode);
            $denied = $admin->post('ldap_settings/save', [
                'ldap_settings' => [['name' => 'ldap_host', 'value' => $fixture->run . '.denied.invalid']],
            ]);
            self::assertSame(500, $denied->statusCode, $denied->body);
            self::assertSame($destination, $this->sessionDestination($admin));
            self::assertSame($before, $this->ldapRows());

            self::assertTrue(
                $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]),
            );
            self::assertSame(200, $admin->get('ldap_settings')->statusCode);
            $saved = $admin->post('ldap_settings/save', [
                'ldap_settings' => [['name' => 'ldap_host', 'value' => $fixture->run . '.promoted.invalid']],
            ]);
            self::assertSame(200, $saved->statusCode, $saved->body);
        } finally {
            $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function ldapRows(): array
    {
        $db = get_instance()->db;
        $rows = [];
        foreach (self::LDAP_NAMES as $name) {
            $rows[$name] = $db->get_where('settings', ['name' => $name])->row_array();
        }
        return $rows;
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
        $contents = SessionFileReader::read($path);
        self::assertSame(1, preg_match('/dest_url\\|s:\\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

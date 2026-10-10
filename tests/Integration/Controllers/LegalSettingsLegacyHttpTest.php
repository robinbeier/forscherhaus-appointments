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

/** Bounded HTTP/DB coverage for the classic Legal_settings controller. */
final class LegalSettingsLegacyHttpTest extends TestCase
{
    /** @var list<string> */
    private const LEGAL_NAMES = [
        'display_cookie_notice',
        'cookie_notice_content',
        'display_terms_and_conditions',
        'terms_and_conditions_content',
        'display_privacy_policy',
        'privacy_policy_content',
    ];

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $actorSnapshot = null;
    private ?array $providerSnapshot = null;
    private ?array $adminRoleSnapshot = null;
    private ?array $providerRoleSnapshot = null;
    /** @var array<string, array<string, mixed>> */
    private array $legalSnapshots = [];

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
            foreach (self::LEGAL_NAMES as $name) {
                $row = $db->get_where('settings', ['name' => $name])->row_array();
                self::assertNotEmpty($row, 'The synthetic stack must seed ' . $name . '.');
                $this->legalSnapshots[$name] = $row;
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
                foreach ($this->legalSnapshots as $name => $row) {
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

    public function testAdminCanReadOnlyTheSixLegalSettingsOnCanonicalAndDirectIndex(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $marker = $fixture->run . '_unrelated_projection_marker';
        $fixture->ownedSetting('projection_marker', $marker);
        $apiToken = $db->get_where('settings', ['name' => 'api_token'])->row_array();
        $ldapSecret = $db->get_where('settings', ['name' => 'ldap_password'])->row_array();
        self::assertNotEmpty($apiToken);
        self::assertNotEmpty($ldapSecret);
        $apiMarker = $fixture->run . '_api_projection_secret';
        $ldapMarker = $fixture->run . '_ldap_projection_secret';
        $admin = $this->login($this->credentials['admin_username']);
        try {
            self::assertTrue($db->update('settings', ['value' => $apiMarker], ['id' => $apiToken['id']]));
            self::assertTrue($db->update('settings', ['value' => $ldapMarker], ['id' => $ldapSecret['id']]));
            foreach (['legal_settings', 'legal_settings/index'] as $path) {
                $response = $admin->get($path);
                self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
                self::assertStringContainsString('id="legal-settings-page"', $response->body);
                $vars = $this->scriptVars($response->body);
                $settings = $vars['legal_settings'] ?? null;
                self::assertIsArray($settings);
                foreach ($settings as $setting) {
                    $fields = array_keys($setting);
                    sort($fields);
                    self::assertSame(['name', 'value'], $fields, $path);
                }
                $names = array_column($settings, 'name');
                sort($names);
                $expectedNames = self::LEGAL_NAMES;
                sort($expectedNames);
                self::assertSame($expectedNames, $names, $path);
                self::assertStringNotContainsString($marker, $response->body, $path);
                self::assertStringNotContainsString($apiMarker, $response->body, $path);
                self::assertStringNotContainsString($ldapMarker, $response->body, $path);
            }
        } finally {
            $apiRestored = $db->update('settings', $apiToken, ['id' => $apiToken['id']]);
            $ldapRestored = $db->update('settings', $ldapSecret, ['id' => $ldapSecret['id']]);
            self::assertTrue($apiRestored);
            self::assertTrue($ldapRestored);
        }
    }

    public function testClassicMethodsAndCsrfRejectInvalidWritesWithoutMutation(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('legal_settings')->statusCode);
        $beforeDestination = $this->sessionDestination($admin);
        $before = $this->legalRows();

        $postIndex = $admin->post('legal_settings/index', [], withCsrfToken: true);
        self::assertSame(405, $postIndex->statusCode, $postIndex->body);
        self::assertSame('GET', $postIndex->header('allow'));
        foreach (['legal_settings', 'legal_settings/index'] as $path) {
            foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $admin->requestApp($method, $path);
                self::assertSame(405, $response->statusCode, $path . ' ' . $method);
                self::assertSame('GET', $response->header('allow'));
                self::assertSame($before, $this->legalRows());
            }
        }

        $saveGet = $admin->get('legal_settings/save');
        self::assertSame(405, $saveGet->statusCode, $saveGet->body);
        self::assertSame('POST', $saveGet->header('allow'));
        $missingCsrf = $admin->post(
            'legal_settings/save',
            [
                'legal_settings' => [['name' => 'cookie_notice_content', 'value' => 'missing-csrf']],
            ],
            withCsrfToken: false,
        );
        self::assertSame(403, $missingCsrf->statusCode, $missingCsrf->body);
        self::assertSame($before, $this->legalRows());
        self::assertSame($beforeDestination, $this->sessionDestination($admin));
    }

    public function testOnlyLegalNamesCanBeSavedAndCallerIdsOrExtraFieldsDoNotGrantAuthority(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreign = $fixture->ownedSetting('foreign', 'before');
        $admin = $this->login($this->credentials['admin_username']);
        $payload = [];
        foreach (self::LEGAL_NAMES as $index => $name) {
            $payload[] = [
                'name' => $name,
                'value' => (string) ($index % 2),
                'id' => $foreign['id'],
                'extra' => 'discard-me',
            ];
        }
        $saved = $admin->post('legal_settings/save', ['legal_settings' => $payload]);
        self::assertSame(200, $saved->statusCode, $saved->body);
        $rows = $this->legalRows();
        foreach (self::LEGAL_NAMES as $index => $name) {
            self::assertSame((string) ($index % 2), $rows[$name]['value'] ?? null, $name);
        }
        self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
    }

    public function testMixedForeignOrDuplicateNamesFailAtomically(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreign = $fixture->ownedSetting('foreign_batch', 'before');
        $admin = $this->login($this->credentials['admin_username']);
        $before = $this->legalRows();
        foreach (
            [
                [
                    ['name' => 'cookie_notice_content', 'value' => 'after'],
                    ['name' => $foreign['name'], 'value' => 'after'],
                ],
                [
                    ['name' => 'cookie_notice_content', 'value' => 'first'],
                    ['name' => 'cookie_notice_content', 'value' => 'second'],
                ],
            ]
            as $batch
        ) {
            $response = $admin->post('legal_settings/save', ['legal_settings' => $batch]);
            self::assertSame(500, $response->statusCode, $response->body);
            self::assertSame($before, $this->legalRows());
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        }
    }

    public function testStoredSystemSettingsDemotionAndPromotionApplyToExistingSession(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->actorSnapshot);
        $db = get_instance()->db;
        $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($providerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('legal_settings')->statusCode);
        $destination = $this->sessionDestination($admin);
        $before = $this->legalRows();
        try {
            self::assertTrue($db->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->actorId]));
            self::assertSame(403, $admin->get('legal_settings')->statusCode);
            $denied = $admin->post('legal_settings/save', [
                'legal_settings' => [['name' => 'cookie_notice_content', 'value' => 'denied']],
            ]);
            self::assertSame(500, $denied->statusCode, $denied->body);
            self::assertSame($before, $this->legalRows());
            self::assertSame($destination, $this->sessionDestination($admin));

            self::assertTrue(
                $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]),
            );
            self::assertSame(200, $admin->get('legal_settings')->statusCode);
            $saved = $admin->post('legal_settings/save', [
                'legal_settings' => [['name' => 'cookie_notice_content', 'value' => 'promoted']],
            ]);
            self::assertSame(200, $saved->statusCode, $saved->body);
        } finally {
            $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function legalRows(): array
    {
        $db = get_instance()->db;
        $rows = [];
        foreach (self::LEGAL_NAMES as $name) {
            $rows[$name] = $db->get_where('settings', ['name' => $name])->row_array();
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    private function scriptVars(string $body): array
    {
        self::assertSame(1, preg_match('/const vars = (.+?);\s*\n/s', $body, $matches));
        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vars);
        return $vars;
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
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP/DB coverage for the classic Booking_settings controller. */
final class BookingSettingsLegacyHttpTest extends TestCase
{
    /** @var list<string> */
    private const BOOKING_NAMES = [
        'display_first_name',
        'require_first_name',
        'display_last_name',
        'require_last_name',
        'display_email',
        'require_email',
        'display_phone_number',
        'require_phone_number',
        'display_address',
        'require_address',
        'display_city',
        'require_city',
        'display_zip_code',
        'require_zip_code',
        'display_notes',
        'require_notes',
        'label_custom_field_1',
        'display_custom_field_1',
        'require_custom_field_1',
        'label_custom_field_2',
        'display_custom_field_2',
        'require_custom_field_2',
        'label_custom_field_3',
        'display_custom_field_3',
        'require_custom_field_3',
        'label_custom_field_4',
        'display_custom_field_4',
        'require_custom_field_4',
        'label_custom_field_5',
        'display_custom_field_5',
        'require_custom_field_5',
        'limit_customer_access',
        'require_captcha',
        'display_any_provider',
        'display_login_button',
        'display_delete_personal_information',
        'disable_booking',
        'disable_booking_message',
    ];

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $actorSnapshot = null;
    private ?array $providerSnapshot = null;
    private ?array $adminRoleSnapshot = null;
    private ?array $providerRoleSnapshot = null;
    /** @var array<string, array<string, mixed>> */
    private array $bookingSnapshots = [];

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
            foreach (self::BOOKING_NAMES as $name) {
                $row = $db->get_where('settings', ['name' => $name])->row_array();
                self::assertNotEmpty($row, 'The synthetic stack must seed ' . $name . '.');
                $this->bookingSnapshots[$name] = $row;
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
                foreach ($this->bookingSnapshots as $name => $row) {
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

    public function testAdminCanReadOnlyBookingSettingsOnCanonicalAndDirectIndex(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $unrelated = $fixture->ownedSetting('booking_projection_marker', $fixture->run . '_booking_unrelated_marker');
        $apiToken = $db->get_where('settings', ['name' => 'api_token'])->row_array();
        $ldap = $db->get_where('settings', ['name' => 'ldap_password'])->row_array();
        self::assertNotEmpty($apiToken['id'] ?? null);
        self::assertNotEmpty($ldap['id'] ?? null);
        $apiMarker = $fixture->run . '_booking_api_projection_secret';
        $ldapMarker = $fixture->run . '_booking_ldap_projection_secret';
        try {
            self::assertTrue($db->update('settings', ['value' => $apiMarker], ['id' => $apiToken['id']]));
            self::assertTrue($db->update('settings', ['value' => $ldapMarker], ['id' => $ldap['id']]));
            $admin = $this->login($this->credentials['admin_username']);
            foreach (['booking_settings', 'booking_settings/index'] as $path) {
                $response = $admin->get($path);
                self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
                self::assertStringContainsString('id="booking-settings-page"', $response->body);
                $projection = $this->scriptVars($response->body)['booking_settings'] ?? null;
                self::assertIsArray($projection);
                $names = array_column($projection, 'name');
                sort($names);
                $expected = self::BOOKING_NAMES;
                sort($expected);
                self::assertSame($expected, $names, $path);
                foreach ($projection as $row) {
                    $keys = array_keys($row);
                    sort($keys);
                    self::assertSame(['name', 'value'], $keys, $path);
                }
                self::assertNotContains('api_token', $names, $path);
                self::assertNotContains('ldap_password', $names, $path);
                self::assertNotContains($unrelated['name'], $names, $path);
                self::assertStringNotContainsString($apiMarker, $response->body, $path);
                self::assertStringNotContainsString($ldapMarker, $response->body, $path);
                self::assertStringNotContainsString($unrelated['value'], $response->body, $path);
            }
        } finally {
            self::assertTrue($db->update('settings', $apiToken, ['id' => $apiToken['id']]));
            self::assertTrue($db->update('settings', $ldap, ['id' => $ldap['id']]));
            self::assertSame($apiToken, $db->get_where('settings', ['id' => $apiToken['id']])->row_array());
            self::assertSame($ldap, $db->get_where('settings', ['id' => $ldap['id']])->row_array());
        }
    }

    public function testMethodsCsrfAndDeniedRequestsDoNotMutateOrChangeSessionDestination(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('booking_settings')->statusCode);
        $destination = $this->sessionDestination($admin);
        $before = $this->bookingRows();
        $postIndex = $admin->post('booking_settings/index', [], withCsrfToken: true);
        self::assertSame(405, $postIndex->statusCode, $postIndex->body);
        self::assertSame('GET', $postIndex->header('allow'));
        foreach (['booking_settings', 'booking_settings/index'] as $path) {
            foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $admin->requestApp($method, $path);
                self::assertSame(405, $response->statusCode, $path . ' ' . $method);
                self::assertSame('GET', $response->header('allow'));
                self::assertSame($before, $this->bookingRows());
                self::assertSame($destination, $this->sessionDestination($admin));
            }
        }
        $saveGet = $admin->get('booking_settings/save');
        self::assertSame(405, $saveGet->statusCode, $saveGet->body);
        self::assertSame('POST', $saveGet->header('allow'));
        $missingCsrf = $admin->post(
            'booking_settings/save',
            [
                'booking_settings' => [['name' => 'display_first_name', 'value' => '0']],
            ],
            withCsrfToken: false,
        );
        self::assertSame(403, $missingCsrf->statusCode, $missingCsrf->body);
        self::assertSame($before, $this->bookingRows());
        self::assertSame($destination, $this->sessionDestination($admin));
    }

    public function testOnlyBookingNamesSaveWithCallerIdsAndExtraFieldsDiscarded(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreign = $fixture->ownedSetting('booking_foreign', 'before');
        $admin = $this->login($this->credentials['admin_username']);
        $payload = [];
        foreach (self::BOOKING_NAMES as $index => $name) {
            $payload[] = [
                'name' => $name,
                'value' => (string) ($index % 2),
                'id' => $foreign['id'],
                'extra' => 'discard-me',
            ];
        }
        $saved = $admin->post('booking_settings/save', ['booking_settings' => $payload]);
        self::assertSame(200, $saved->statusCode, $saved->body);
        foreach (self::BOOKING_NAMES as $index => $name) {
            self::assertSame((string) ($index % 2), $this->bookingRows()[$name]['value'] ?? null, $name);
        }
        self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
    }

    public function testCallerIdCannotRetargetAnotherRowWhenAllowedNameIsTemporarilyAbsent(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $name = 'disable_booking_message';
        $original = $this->bookingSnapshots[$name];
        $foreign = $fixture->ownedSetting('booking_missing_name_target', 'before');
        $admin = $this->login($this->credentials['admin_username']);

        try {
            self::assertTrue($db->delete('settings', ['id' => $original['id']]));
            $response = $admin->post('booking_settings/save', [
                'booking_settings' => [['name' => $name, 'value' => 'synthetic message', 'id' => $foreign['id']]],
            ]);
            self::assertSame(200, $response->statusCode, $response->body);
            $inserted = $db->get_where('settings', ['name' => $name])->row_array();
            self::assertNotEmpty($inserted);
            self::assertNotSame((int) $foreign['id'], (int) $inserted['id']);
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        } finally {
            $db->delete('settings', ['name' => $name]);
            if (!$db->get_where('settings', ['id' => $original['id']])->num_rows()) {
                $db->insert('settings', $original);
            }
            $foreignAfter = $db->get_where('settings', ['id' => $foreign['id']])->row_array();
            if (!$foreignAfter) {
                $db->insert('settings', $foreign);
            } else {
                $db->update('settings', $foreign, ['id' => $foreign['id']]);
            }
            self::assertSame($original, $db->get_where('settings', ['id' => $original['id']])->row_array());
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        }
    }

    public function testMixedForeignAndDuplicateBatchesFailAtomically(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreign = $fixture->ownedSetting('booking_foreign_batch', 'before');
        $admin = $this->login($this->credentials['admin_username']);
        $before = $this->bookingRows();
        foreach (
            [
                [['name' => 'display_first_name', 'value' => '0'], ['name' => $foreign['name'], 'value' => 'after']],
                [['name' => 'display_first_name', 'value' => '0'], ['name' => 'display_first_name', 'value' => '1']],
            ]
            as $batch
        ) {
            $response = $admin->post('booking_settings/save', ['booking_settings' => $batch]);
            self::assertSame(500, $response->statusCode, $response->body);
            self::assertSame($before, $this->bookingRows());
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        }
    }

    public function testStoredRoleDemotionAndPromotionApplyToExistingSession(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->actorSnapshot);
        $db = get_instance()->db;
        $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($providerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('booking_settings')->statusCode);
        $destination = $this->sessionDestination($admin);
        $before = $this->bookingRows();
        try {
            self::assertTrue($db->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->actorId]));
            self::assertSame(403, $admin->get('booking_settings')->statusCode);
            $denied = $admin->post('booking_settings/save', [
                'booking_settings' => [['name' => 'display_first_name', 'value' => '0']],
            ]);
            self::assertSame(500, $denied->statusCode, $denied->body);
            self::assertSame($before, $this->bookingRows());
            self::assertSame($destination, $this->sessionDestination($admin));
            self::assertTrue(
                $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]),
            );
            self::assertSame(200, $admin->get('booking_settings')->statusCode);
        } finally {
            $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function bookingRows(): array
    {
        $rows = [];
        foreach (self::BOOKING_NAMES as $name) {
            $rows[$name] = get_instance()
                ->db->get_where('settings', ['name' => $name])
                ->row_array();
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
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

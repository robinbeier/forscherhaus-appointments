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

/** Bounded HTTP/DB coverage for the classic Matomo_analytics_settings controller. */
final class MatomoAnalyticsLegacyHttpTest extends TestCase
{
    /** @var list<string> */
    private const MATOMO_NAMES = ['matomo_analytics_url', 'matomo_analytics_site_id'];

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private ?array $actorSnapshot = null;
    private ?array $providerSnapshot = null;
    private ?array $providerRoleSnapshot = null;
    /** @var array<string, array<string, mixed>> */
    private array $matomoSnapshots = [];

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
            $this->providerRoleSnapshot = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
            foreach (self::MATOMO_NAMES as $name) {
                $row = $db->get_where('settings', ['name' => $name])->row_array();
                self::assertNotEmpty($row, 'The synthetic stack must seed ' . $name . '.');
                $this->matomoSnapshots[$name] = $row;
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
                if ($this->providerRoleSnapshot !== null) {
                    $db->update('roles', $this->providerRoleSnapshot, ['id' => $this->providerRoleSnapshot['id']]);
                    self::assertSame(
                        $this->providerRoleSnapshot,
                        $db->get_where('roles', ['id' => $this->providerRoleSnapshot['id']])->row_array(),
                    );
                }
                foreach ($this->matomoSnapshots as $name => $row) {
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

    public function testAdminCanReadCanonicalAndDirectIndexWithExactProjectionAndSaveBatch(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        $db = get_instance()->db;
        $futureName = 'matomo_analytics_' . $fixture->run . '_future';
        $futureMarker = $fixture->run . '_future_value';
        self::assertSame(0, $db->get_where('settings', ['name' => $futureName])->num_rows());
        self::assertTrue($db->insert('settings', ['name' => $futureName, 'value' => $futureMarker]));
        $futureId = (int) $db->insert_id();

        try {
            foreach (['matomo_analytics_settings', 'matomo_analytics_settings/index'] as $path) {
                $response = $admin->get($path);
                self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
                self::assertStringContainsString('id="matomo-analytics-settings-page"', $response->body, $path);
                self::assertStringNotContainsString($futureMarker, $response->body, $path);
                $vars = $this->scriptVars($response->body);
                $settings = $vars['matomo_analytics_settings'] ?? null;
                self::assertIsArray($settings, $path);
                $actual = [];
                foreach ($settings as $setting) {
                    self::assertSame(['name', 'value'], array_keys($setting), $path);
                    $actual[] = $setting['name'];
                }
                sort($actual);
                $expected = self::MATOMO_NAMES;
                sort($expected);
                self::assertSame($expected, $actual, $path);
            }

            $foreign = $fixture->ownedSetting('foreign', 'before');
            $payload = [
                [
                    'name' => self::MATOMO_NAMES[0],
                    'value' => $fixture->run . '.matomo.synthetic.invalid',
                    'id' => $foreign['id'],
                    'extra' => 'discard',
                ],
                ['name' => self::MATOMO_NAMES[1], 'value' => '7654', 'extra' => 'discard'],
            ];
            $saved = $admin->post('matomo_analytics_settings/save', ['matomo_analytics_settings' => $payload]);
            self::assertSame(200, $saved->statusCode, $saved->body);
            self::assertSame(
                $fixture->run . '.matomo.synthetic.invalid',
                $this->matomoRows()[self::MATOMO_NAMES[0]]['value'],
            );
            self::assertSame('7654', $this->matomoRows()[self::MATOMO_NAMES[1]]['value']);
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        } finally {
            self::assertTrue($db->delete('settings', ['id' => $futureId, 'name' => $futureName]));
            self::assertSame([], $db->get_where('settings', ['name' => $futureName])->row_array() ?? []);
        }
    }

    public function testWrongMethodsAndMissingCsrfDoNotChangeSessionOrSettings(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('matomo_analytics_settings')->statusCode);
        $destination = $this->sessionDestination($admin);
        $before = $this->matomoRows();

        foreach (['matomo_analytics_settings', 'matomo_analytics_settings/index'] as $path) {
            $post = $admin->post($path, [], withCsrfToken: true);
            self::assertSame(405, $post->statusCode, 'POST ' . $path);
            self::assertSame('GET', $post->header('allow'), 'POST ' . $path);
            self::assertSame($before, $this->matomoRows());
            self::assertSame($destination, $this->sessionDestination($admin));

            foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $admin->requestApp($method, $path, [], null, false);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path);
                self::assertSame('GET', $response->header('allow'), $method . ' ' . $path);
                self::assertSame($before, $this->matomoRows());
                self::assertSame($destination, $this->sessionDestination($admin));
            }
        }

        $saveGet = $admin->get('matomo_analytics_settings/save');
        self::assertSame(405, $saveGet->statusCode, $saveGet->body);
        self::assertSame('POST', $saveGet->header('allow'));
        foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $admin->requestApp($method, 'matomo_analytics_settings/save');
            self::assertSame(405, $response->statusCode, $method . ' save');
            self::assertSame('POST', $response->header('allow'), $method . ' save');
            self::assertSame($before, $this->matomoRows());
            self::assertSame($destination, $this->sessionDestination($admin));
        }
        $missingCsrf = $admin->post(
            'matomo_analytics_settings/save',
            ['matomo_analytics_settings' => [['name' => self::MATOMO_NAMES[0], 'value' => 'missing-csrf']]],
            null,
            false,
        );
        self::assertSame(403, $missingCsrf->statusCode, $missingCsrf->body);
        self::assertSame($before, $this->matomoRows());
        self::assertSame($destination, $this->sessionDestination($admin));
    }

    public function testAnonymousAndForbiddenRequestsAreDeniedWithoutMutation(): void
    {
        $before = $this->matomoRows();
        $server = $this->server;
        self::assertNotNull($server);
        $anonymous = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'matomo-anonymous']);
        $anonymousIndex = $anonymous->get('matomo_analytics_settings');
        self::assertContains($anonymousIndex->statusCode, [302, 307]);
        self::assertStringContainsString('/login', (string) $anonymousIndex->header('location'));

        $provider = $this->login($this->credentials['provider_username']);
        self::assertSame(403, $provider->get('matomo_analytics_settings')->statusCode);
        $deniedWrite = $provider->post('matomo_analytics_settings/save', [
            'matomo_analytics_settings' => [['name' => self::MATOMO_NAMES[0], 'value' => 'forbidden']],
        ]);
        self::assertSame(500, $deniedWrite->statusCode, $deniedWrite->body);
        self::assertSame($before, $this->matomoRows());
    }

    public function testUnknownDuplicateAndForeignNamesFailAtomically(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $foreign = $fixture->ownedSetting('foreign_batch', 'before');
        $admin = $this->login($this->credentials['admin_username']);
        $before = $this->matomoRows();
        foreach (
            [
                [
                    ['name' => self::MATOMO_NAMES[0], 'value' => 'after'],
                    ['name' => $foreign['name'], 'value' => 'after', 'id' => $foreign['id']],
                ],
                [
                    ['name' => self::MATOMO_NAMES[0], 'value' => 'first'],
                    ['name' => self::MATOMO_NAMES[0], 'value' => 'second'],
                ],
                [['name' => 'matomo_unknown_synthetic', 'value' => 'unknown', 'id' => $foreign['id']]],
            ]
            as $batch
        ) {
            $response = $admin->post('matomo_analytics_settings/save', ['matomo_analytics_settings' => $batch]);
            self::assertSame(500, $response->statusCode, $response->body);
            self::assertSame($before, $this->matomoRows());
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
        }

        $absent = $this->matomoSnapshots[self::MATOMO_NAMES[1]];
        $temporaryName = $fixture->run . '_temporarily_absent';
        $db = get_instance()->db;
        self::assertTrue($db->update('settings', ['name' => $temporaryName], ['id' => $absent['id']]));
        try {
            $response = $admin->post('matomo_analytics_settings/save', [
                'matomo_analytics_settings' => [
                    ['name' => self::MATOMO_NAMES[1], 'value' => 'absent', 'id' => $foreign['id']],
                ],
            ]);
            self::assertSame(200, $response->statusCode, $response->body);
            self::assertSame($foreign, $fixture->settingRow((int) $foreign['id']));
            $inserted = $db->get_where('settings', ['name' => self::MATOMO_NAMES[1]])->row_array();
            self::assertNotEmpty($inserted);
            $insertedId = (int) $inserted['id'];
            self::assertNotSame((int) $foreign['id'], $insertedId);
            self::assertNotSame((int) $absent['id'], $insertedId);
            self::assertSame('absent', $inserted['value']);
        } finally {
            $created = $db->get_where('settings', ['name' => self::MATOMO_NAMES[1]])->row_array();
            $deleted = true;
            if (is_array($created) && $created !== []) {
                $deleted = $db->delete('settings', ['id' => $created['id'], 'name' => self::MATOMO_NAMES[1]]);
            }
            $remaining = $db->get_where('settings', ['name' => self::MATOMO_NAMES[1]])->row_array();
            $restored = $db->update('settings', $absent, ['id' => $absent['id']]);
            self::assertTrue($deleted);
            self::assertEmpty($remaining);
            self::assertTrue($restored);
            self::assertSame($absent, $db->get_where('settings', ['id' => $absent['id']])->row_array());
        }
    }

    public function testStoredSystemSettingsDemotionBlocksReadAndWriteForExistingSession(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->actorSnapshot);
        self::assertNotNull($this->providerRoleSnapshot);
        $db = get_instance()->db;
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('matomo_analytics_settings')->statusCode);
        $destination = $this->sessionDestination($admin);
        $before = $this->matomoRows();

        try {
            self::assertTrue(
                $db->update('users', ['id_roles' => $this->providerRoleSnapshot['id']], ['id' => $fixture->actorId]),
            );
            self::assertSame(403, $admin->get('matomo_analytics_settings')->statusCode);
            $denied = $admin->post('matomo_analytics_settings/save', [
                'matomo_analytics_settings' => [['name' => self::MATOMO_NAMES[0], 'value' => 'denied']],
            ]);
            self::assertSame(500, $denied->statusCode, $denied->body);
            self::assertSame($before, $this->matomoRows());
            self::assertSame($destination, $this->sessionDestination($admin));
        } finally {
            $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function matomoRows(): array
    {
        $rows = [];
        foreach (self::MATOMO_NAMES as $name) {
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
        $contents = SessionFileReader::read($path);
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

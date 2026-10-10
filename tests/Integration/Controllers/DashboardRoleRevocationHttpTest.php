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

/** Authenticated dashboard access must follow the current stored role. */
final class DashboardRoleRevocationHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];

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
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testDemotedAdminCannotReadOrWriteDashboardRoutesAfterLogin(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username'], $this->credentials['password']);

        self::assertSame(200, $admin->get('dashboard')->statusCode);
        self::assertSame(200, $admin->get('dashboard/index')->statusCode);
        $payload = $this->periodPayload();
        self::assertSame(200, $admin->requestApp('POST', 'dashboard/metrics', $payload, null, true)->statusCode);
        self::assertSame(200, $admin->requestApp('POST', 'dashboard/heatmap', $payload, null, true)->statusCode);
        self::assertSame(
            200,
            $admin->requestApp('POST', 'dashboard/threshold', ['threshold' => '0.42'], null, true)->statusCode,
        );
        self::assertStringContainsString('"dashboard_conflict_threshold":0.42', $admin->get('dashboard')->body);
        $admin->get('about');
        $beforeDestination = $this->sessionDestination($admin);
        self::assertStringEndsWith('/about', $beforeDestination);
        $beforeSettings = $fixture->userSettingsRow($fixture->actorId);
        $beforeRoleId = (int) ($fixture->row('users', $fixture->actorId)['id_roles'] ?? 0);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );

            $observed = [];
            $responses = [];
            foreach ($this->adminRoutes() as [$method, $path, $payload]) {
                $response = $admin->requestApp($method, $path, $payload, null, $method === 'POST');
                $key = $method . ' ' . $path;
                $observed[$key] = $response->statusCode;
                $responses[$key] = $response;
            }
            self::assertSame(
                [
                    'GET dashboard' => 403,
                    'GET dashboard/index' => 403,
                    'POST dashboard/metrics' => 403,
                    'POST dashboard/heatmap' => 403,
                    'POST dashboard/threshold' => 403,
                ],
                $observed,
            );
            foreach ($responses as $response) {
                self::assertStringNotContainsString($fixture->run, $response->body);
            }
            self::assertSame($beforeDestination, $this->sessionDestination($admin));
        } finally {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $beforeRoleId], ['id' => $fixture->actorId]),
            );
        }

        self::assertSame($beforeSettings, $fixture->userSettingsRow($fixture->actorId));
        self::assertStringContainsString(
            '"dashboard_conflict_threshold":0.42',
            $admin->get('dashboard')->body,
            'A denied threshold update must not change the retained session value.',
        );
    }

    public function testDemotedProviderCannotReadProviderDashboardAfterLogin(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $provider = $this->login($this->credentials['provider_username'], $this->credentials['password']);

        self::assertSame(200, $provider->get('dashboard')->statusCode);
        self::assertSame(200, $provider->get('dashboard/index')->statusCode);
        self::assertSame(
            200,
            $provider->requestApp('POST', 'dashboard/provider_metrics', $this->periodPayload(), null, true)->statusCode,
        );
        $provider->get('about');
        $beforeDestination = $this->sessionDestination($provider);
        self::assertStringEndsWith('/about', $beforeDestination);
        $beforeSettings = $fixture->userSettingsRow($fixture->providerId);
        $beforeRoleId = (int) ($fixture->row('users', $fixture->providerId)['id_roles'] ?? 0);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->providerId],
                ),
            );

            $observed = [];
            $responses = [];
            foreach (
                [
                    ['GET', 'dashboard', []],
                    ['GET', 'dashboard/index', []],
                    ['POST', 'dashboard/provider_metrics', $this->periodPayload('2026-11-25', '2026-11-26')],
                ]
                as [$method, $path, $payload]
            ) {
                $response = $provider->requestApp($method, $path, $payload, null, $method === 'POST');
                $key = $method . ' ' . $path;
                $observed[$key] = $response->statusCode;
                $responses[$key] = $response;
            }
            self::assertSame(
                [
                    'GET dashboard' => 403,
                    'GET dashboard/index' => 403,
                    'POST dashboard/provider_metrics' => 403,
                ],
                $observed,
            );
            foreach ($responses as $response) {
                self::assertStringNotContainsString($fixture->run, $response->body);
            }
            self::assertSame($beforeDestination, $this->sessionDestination($provider));
        } finally {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $beforeRoleId], ['id' => $fixture->providerId]),
            );
        }

        self::assertSame($beforeSettings, $fixture->userSettingsRow($fixture->providerId));
    }

    /** @return list<array{string,string,?array<string,mixed>}> */
    private function adminRoutes(): array
    {
        $payload = $this->periodPayload('2026-11-25', '2026-11-26');

        return [
            ['GET', 'dashboard', []],
            ['GET', 'dashboard/index', []],
            ['POST', 'dashboard/metrics', $payload],
            ['POST', 'dashboard/heatmap', $payload],
            ['POST', 'dashboard/threshold', ['threshold' => '0.63']],
        ];
    }

    /** @return array<string,mixed> */
    private function periodPayload(string $start = '2026-11-24', string $end = '2026-11-27'): array
    {
        return [
            'start_date' => $start,
            'end_date' => $end,
            'statuses' => ['Booked'],
            'service_id' => (string) $this->fixture?->serviceId,
            'provider_ids' => [(string) $this->fixture?->providerId],
        ];
    }

    private function login(string $username, string $password): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $response->statusCode, $response->body);
        return $client;
    }

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9,-]+$/', $sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $sessionPath = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($sessionPath);
        self::assertFalse(is_link($sessionPath));
        $contents = SessionFileReader::read($sessionPath);
        self::assertSame(1, preg_match('/dest_url\\|s:\\d+:"([^"]*)";/', $contents, $matches));

        return $matches[1];
    }
}

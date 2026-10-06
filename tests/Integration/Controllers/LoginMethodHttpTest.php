<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP contract coverage for the public login capability boundary. */
final class LoginMethodHttpTest extends TestCase
{
    private const ENDPOINT = 'login/validate';

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
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

    public function testNonPostMethodsCannotCreateASessionOnRewriteOrDirectAlias(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();

        foreach ($this->routes($server) as [$client, $endpoint, $calendarPath]) {
            /** @var GateHttpClient $client */
            $credentials = [
                'username' => $fixture->run . '_actor',
                'password' => $fixture->password,
            ];

            $get = $client->get($endpoint, $credentials);
            self::assertSame(405, $get->statusCode, $get->body);
            self::assertSame('POST', $get->header('allow'));
            $this->assertAnonymous($client, $calendarPath);

            $head = $client->requestApp('HEAD', $endpoint, $credentials);
            self::assertSame(405, $head->statusCode, $head->body);
            self::assertSame('POST', $head->header('allow'));
            $this->assertAnonymous($client, $calendarPath);

            $put = $client->requestApp('PUT', $endpoint, $credentials);
            self::assertSame(405, $put->statusCode, $put->body);
            self::assertSame('POST', $put->header('allow'));
            $this->assertAnonymous($client, $calendarPath);

            $options = $client->requestApp('OPTIONS', $endpoint);
            self::assertSame(200, $options->statusCode, $options->body);
            $this->assertAnonymous($client, $calendarPath);
        }
    }

    public function testPostWithoutCsrfCannotCreateASessionOnRewriteOrDirectAlias(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();

        foreach ($this->routes($server) as [$client, $endpoint, $calendarPath, $loginPath]) {
            /** @var GateHttpClient $client */
            $client->get($loginPath);
            $response = $client->requestApp(
                'POST',
                $endpoint,
                [
                    'username' => $fixture->run . '_actor',
                    'password' => $fixture->password,
                ],
                null,
                false,
            );

            self::assertSame(403, $response->statusCode, $response->body);
            $this->assertAnonymous($client, $calendarPath);
        }
    }

    public function testPostValidCredentialStillCreatesAnAuthenticatedSessionOnBothRoutes(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();

        foreach ($this->routes($server) as [$client, $endpoint, $calendarPath, $loginPath]) {
            /** @var GateHttpClient $client */
            $client->get($loginPath);
            $response = $client->post($endpoint, [
                'username' => $fixture->run . '_actor',
                'password' => $fixture->password,
            ]);
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(200, $response->statusCode, $response->body);
            self::assertTrue((bool) ($data['success'] ?? false));
            self::assertNotNull($client->getCookie('ea_session'));
        }
    }

    public function testSuccessfulLoginRotatesSessionAndRejectsThePreLoginCookie(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();

        foreach ($this->routes($server) as [$client, $endpoint, $calendarPath, $loginPath, $indexPage]) {
            /** @var GateHttpClient $client */
            self::assertSame(200, $client->get($loginPath)->statusCode);
            $cookieName = (string) config('sess_cookie_name');
            $beforeLoginCookie = $client->getCookie($cookieName);
            self::assertNotNull($beforeLoginCookie);

            $response = $client->post($endpoint, [
                'username' => $fixture->run . '_actor',
                'password' => $fixture->password,
            ]);

            self::assertSame(200, $response->statusCode, $response->body);
            self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
            $afterLoginCookie = $client->getCookie($cookieName);
            self::assertNotNull($afterLoginCookie);
            self::assertNotSame($beforeLoginCookie, $afterLoginCookie);

            $oldCookieClient = new GateHttpClient(
                $server->baseUrl,
                indexPage: $indexPage,
                additionalHeaders: ['Cookie' => $cookieName . '=' . $beforeLoginCookie],
            );
            $oldCookieCalendar = $oldCookieClient->get($calendarPath);
            self::assertSame(307, $oldCookieCalendar->statusCode, $oldCookieCalendar->body);
            self::assertStringContainsString('/login', (string) $oldCookieCalendar->header('location'));

            $newCookieCalendar = $client->get($calendarPath);
            self::assertSame(200, $newCookieCalendar->statusCode, $newCookieCalendar->body);
            self::assertStringContainsString('id="calendar-page"', $newCookieCalendar->body);
            self::assertStringNotContainsString('id="login-form"', $newCookieCalendar->body);
        }
    }

    public function testLogoutRejectsCurrentAndReplayedSessionCookiesOnCanonicalAndDirectRoutes(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();
        $cookieName = (string) config('sess_cookie_name');

        foreach (
            [
                [new GateHttpClient($server->baseUrl, ''), 'logout', 'calendar', '/logout'],
                [new GateHttpClient($server->baseUrl, 'index.php'), 'logout', 'calendar', '/index.php/logout'],
            ]
            as [$client, $logoutPath, $calendarPath, $logoutUrlPath]
        ) {
            /** @var GateHttpClient $client */
            $login = $client->get('login');
            self::assertSame(200, $login->statusCode, $login->body);
            $credentials = [
                'username' => $fixture->run . '_actor',
                'password' => $fixture->password,
            ];
            $authenticated = $client->post('login/validate', $credentials);
            self::assertSame(200, $authenticated->statusCode, $authenticated->body);
            $preLogoutCookie = $client->getCookie($cookieName);
            self::assertNotNull($preLogoutCookie);

            $beforeLogout = $client->get($calendarPath);
            self::assertSame(200, $beforeLogout->statusCode, $beforeLogout->body);
            self::assertStringContainsString('id="calendar-page"', $beforeLogout->body);

            $logout = $client->get($logoutPath);
            self::assertSame(200, $logout->statusCode, $logout->body);
            self::assertSame($logoutUrlPath, (string) parse_url($logout->url, PHP_URL_PATH));

            $currentCookie = $client->get($calendarPath);
            self::assertSame(200, $currentCookie->statusCode, $currentCookie->body);
            self::assertStringContainsString('id="login-form"', $currentCookie->body);
            self::assertStringNotContainsString('id="calendar-page"', $currentCookie->body);

            $replayedCookie = new GateHttpClient(
                $server->baseUrl,
                str_starts_with($logoutUrlPath, '/index.php/') ? 'index.php' : '',
                additionalHeaders: ['Cookie' => $cookieName . '=' . $preLogoutCookie],
            );
            $replay = $replayedCookie->get($calendarPath);
            self::assertSame(307, $replay->statusCode, $replay->body);
            self::assertStringContainsString('/login', (string) $replay->header('location'));
        }
    }

    public function testInvalidCredentialsDoNotAuthenticateTheAnonymousSession(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();

        foreach ($this->routes($server) as [$client, $endpoint, $calendarPath, $loginPath]) {
            /** @var GateHttpClient $client */
            self::assertSame(200, $client->get($loginPath)->statusCode);
            $beforeLoginCookie = $client->getCookie((string) config('sess_cookie_name'));

            $response = $client->post($endpoint, [
                'username' => $fixture->run . '_actor',
                'password' => $fixture->password . '-invalid',
            ]);

            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(200, $response->statusCode, $response->body);
            self::assertFalse((bool) ($data['success'] ?? true), $response->body);
            self::assertSame(lang('invalid_credentials_provided'), $data['message'] ?? null);
            self::assertSame($beforeLoginCookie, $client->getCookie((string) config('sess_cookie_name')));
            $this->assertAnonymous($client, $calendarPath);
        }
    }

    public function testLdapOutageDoesNotRevealWhetherTheUsernameIsKnown(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();
        $db = \get_instance()->db;
        $settingNames = ['ldap_is_active', 'ldap_host', 'ldap_port'];
        $settings = $db->where_in('name', $settingNames)->get('settings')->result_array();
        $actorUser = $db->get_where('users', ['id' => $fixture->actorId])->row_array();
        $providerUser = $db->get_where('users', ['id' => $fixture->providerId])->row_array();

        try {
            $db->update('settings', ['value' => '1'], ['name' => 'ldap_is_active']);
            $db->update('settings', ['value' => '127.0.0.1'], ['name' => 'ldap_host']);
            $db->update('settings', ['value' => '1'], ['name' => 'ldap_port']);
            $db->update(
                'users',
                ['ldap_dn' => 'uid=' . $fixture->run . ',ou=synthetic,dc=invalid'],
                [
                    'id' => $fixture->actorId,
                ],
            );
            $db->update('users', ['ldap_dn' => null], ['id' => $fixture->providerId]);

            self::assertSame(
                '1',
                (string) $db->get_where('settings', ['name' => 'ldap_is_active'])->row_array()['value'],
            );
            self::assertSame(
                '127.0.0.1',
                (string) $db->get_where('settings', ['name' => 'ldap_host'])->row_array()['value'],
            );
            self::assertSame('1', (string) $db->get_where('settings', ['name' => 'ldap_port'])->row_array()['value']);
            self::assertSame(
                'uid=' . $fixture->run . ',ou=synthetic,dc=invalid',
                (string) $db->get_where('users', ['id' => $fixture->actorId])->row_array()['ldap_dn'],
            );
            self::assertSame(
                '',
                (string) $db->get_where('users', ['id' => $fixture->providerId])->row_array()['ldap_dn'],
            );

            foreach ($this->routes($server) as [$client, $endpoint, , $loginPath]) {
                /** @var GateHttpClient $client */
                self::assertSame(200, $client->get($loginPath)->statusCode);
                $responses = [];

                foreach (
                    [$fixture->run . '_actor', $fixture->run . '_unknown', $fixture->run . '_provider']
                    as $username
                ) {
                    $response = $client->post($endpoint, [
                        'username' => $username,
                        'password' => $fixture->password . '-invalid',
                    ]);
                    $responses[] = [$response->statusCode, $response->body];
                }

                self::assertSame($responses[0], $responses[1]);
                self::assertSame($responses[0], $responses[2]);
                self::assertSame(
                    [
                        200,
                        json_encode(
                            [
                                'success' => false,
                                'message' => lang('invalid_credentials_provided'),
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                    ],
                    $responses[0],
                );
            }
        } finally {
            foreach ($settings as $row) {
                $db->update('settings', ['value' => $row['value']], ['name' => $row['name']]);
            }
            if ($actorUser) {
                $db->update(
                    'users',
                    ['ldap_dn' => $actorUser['ldap_dn'] ?? null],
                    [
                        'id' => $fixture->actorId,
                    ],
                );
            }
            if ($providerUser) {
                $db->update(
                    'users',
                    ['ldap_dn' => $providerUser['ldap_dn'] ?? null],
                    [
                        'id' => $fixture->providerId,
                    ],
                );
            }
        }
    }

    public function testMalformedCredentialsAreRejectedAsClientErrors(): void
    {
        $fixture = $this->fixture();
        $server = $this->server();

        foreach ($this->routes($server) as [$client, $endpoint, $calendarPath, $loginPath]) {
            /** @var GateHttpClient $client */
            self::assertSame(200, $client->get($loginPath)->statusCode);
            $beforeLoginCookie = $client->getCookie((string) config('sess_cookie_name'));

            $response = $client->post($endpoint, [
                'username' => $fixture->run . '_actor',
            ]);

            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(400, $response->statusCode, $response->body);
            self::assertFalse((bool) ($data['success'] ?? true), $response->body);
            self::assertSame('No password value provided.', $data['message'] ?? null);
            self::assertSame($beforeLoginCookie, $client->getCookie((string) config('sess_cookie_name')));
            $this->assertAnonymous($client, $calendarPath);
        }
    }

    /** @return list<array{GateHttpClient,string,string,string,string}> */
    private function routes(DefenseCycleHttpServer $server): array
    {
        return [
            [
                new GateHttpClient($server->baseUrl, '', additionalHeaders: ['X-FH-Test' => 'login-method']),
                self::ENDPOINT,
                'index.php/calendar',
                'index.php/login',
                '',
            ],
            [
                new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'login-method']),
                self::ENDPOINT,
                'calendar',
                'login',
                'index.php',
            ],
        ];
    }

    private function assertAnonymous(GateHttpClient $client, string $calendarPath): void
    {
        $calendar = $client->get($calendarPath);
        self::assertSame(307, $calendar->statusCode, $calendar->body);
        self::assertStringContainsString('/login', (string) $calendar->header('location'));
    }

    private function fixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);
        return $this->fixture;
    }

    private function server(): DefenseCycleHttpServer
    {
        self::assertNotNull($this->server);
        return $this->server;
    }
}

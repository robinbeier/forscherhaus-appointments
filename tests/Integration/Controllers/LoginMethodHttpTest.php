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

    /** @return list<array{GateHttpClient,string,string,string}> */
    private function routes(DefenseCycleHttpServer $server): array
    {
        return [
            [
                new GateHttpClient($server->baseUrl, '', additionalHeaders: ['X-FH-Test' => 'login-method']),
                self::ENDPOINT,
                'index.php/calendar',
                'index.php/login',
            ],
            [
                new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'login-method']),
                self::ENDPOINT,
                'calendar',
                'login',
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

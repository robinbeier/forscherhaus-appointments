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

/** Real HTTP coverage for the session language mutation boundary. */
final class LocalizationHttpTest extends TestCase
{
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

    public function testGetAndHeadCannotChangeSessionLanguage(): void
    {
        $client = $this->login();
        $before = $this->sessionLanguage($client);

        $get = $client->requestApp('GET', 'localization/change_language?language=german');
        self::assertSame(405, $get->statusCode, $get->body);
        self::assertSame('POST', $get->header('allow'));
        self::assertSame($before, $this->sessionLanguage($client));

        $head = $client->requestApp('HEAD', 'localization/change_language?language=german');
        self::assertSame(405, $head->statusCode, $head->body);
        self::assertSame('POST', $head->header('allow'));
        self::assertSame($before, $this->sessionLanguage($client));
    }

    public function testCsrfProtectedPostChangesSessionLanguage(): void
    {
        $client = $this->login();
        $response = $client->post('localization/change_language', ['language' => 'german']);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame('german', $this->sessionLanguage($client));
    }

    private function login(): GateHttpClient
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        $credentials = $this->fixture?->enableProviderHttpAuth();
        self::assertIsArray($credentials);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $credentials['admin_username'],
            'password' => $credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);

        return $client;
    }

    private function sessionLanguage(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $path = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertIsString($path);
        self::assertFileExists($path);
        $contents = SessionFileReader::read($path);
        self::assertSame(1, preg_match('/language\|s:\d+:"([^"]*)";/', $contents, $matches));

        return $matches[1];
    }
}

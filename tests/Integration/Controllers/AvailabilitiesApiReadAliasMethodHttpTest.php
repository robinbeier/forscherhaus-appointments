<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded real-HTTP coverage for the availability API v1 read route. */
final class AvailabilitiesApiReadAliasMethodHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array{admin_username:string,provider_username:string,password:string,token:string} */
    private array $credentials = [];
    private string $date = '';

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->date = date('Y-m-d', strtotime('+14 days'));
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

    public function testAdminBasicAndGlobalBearerReadCanonicalAndDirectAliasWithOwnedService(): void
    {
        $fixture = $this->requireFixture();
        $query = [
            'providerId' => $fixture->providerId,
            'serviceId' => $fixture->serviceId,
            'date' => $this->date,
        ];
        $clients = [
            'admin-basic' => $this->basicClient($this->credentials['admin_username'], $this->credentials['password']),
            'global-bearer' => $this->bearerClient($this->credentials['token']),
        ];

        foreach ($clients as $case => $client) {
            foreach (['api/v1/availabilities', 'api/v1/availabilities_api_v1/get'] as $path) {
                $response = $client->get($path, $query);
                self::assertSame(200, $response->statusCode, $case . ' ' . $path . ': ' . $response->body);
                $hours = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($hours, $case . ' ' . $path);
                self::assertNotEmpty($hours, $case . ' ' . $path . ' should expose the owned service schedule.');
                foreach ($hours as $hour) {
                    self::assertIsString($hour);
                    self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/D', $hour);
                }
            }
        }
    }

    public function testDirectReadAliasDeniesUnauthenticatedInvalidBearerAndProviderBasicAndOptionsIsGlobal(): void
    {
        $fixture = $this->requireFixture();
        $query = [
            'providerId' => $fixture->providerId,
            'serviceId' => $fixture->serviceId,
            'date' => $this->date,
        ];
        $clients = [
            'anonymous' => $this->server->client(),
            'invalid-bearer' => $this->bearerClient($this->credentials['token'] . '-invalid'),
            'provider-basic' => $this->basicClient(
                $this->credentials['provider_username'],
                $this->credentials['password'],
            ),
        ];

        foreach ($clients as $case => $client) {
            $response = $client->get('api/v1/availabilities_api_v1/get', $query);
            self::assertSame(401, $response->statusCode, $case . ': ' . $response->body);
            self::assertNotEmpty($response->header('www-authenticate'), $case);
            self::assertStringNotContainsString($fixture->run, $response->body, $case);
        }

        $options = $this->server->client()->requestApp('OPTIONS', 'api/v1/availabilities_api_v1/get');
        self::assertSame(200, $options->statusCode, $options->body);
        self::assertSame('', $options->body);
    }

    public function testDirectReadAliasRejectsAllNonGetMethodsWithoutBodyOrDbMutation(): void
    {
        $fixture = $this->requireFixture();
        $db = get_instance()->db;
        $before = [
            'provider' => $db->get_where('users', ['id' => $fixture->providerId])->row_array(),
            'service' => $db->get_where('services', ['id' => $fixture->serviceId])->row_array(),
            'appointments' => $db->count_all('appointments'),
        ];
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $query = http_build_query([
            'providerId' => $fixture->providerId,
            'serviceId' => $fixture->serviceId,
            'date' => $this->date,
        ]);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            $response = $admin->requestApp($method, 'api/v1/availabilities_api_v1/get?' . $query);
            self::assertSame(405, $response->statusCode, $method . ': ' . $response->body);
            self::assertSame('GET', $response->header('allow'), $method . ' Allow');
            self::assertSame('', $response->body, $method . ' body');
            self::assertStringNotContainsString($fixture->run, $response->body, $method);
            self::assertSame($before['provider'], $db->get_where('users', ['id' => $fixture->providerId])->row_array());
            self::assertSame(
                $before['service'],
                $db->get_where('services', ['id' => $fixture->serviceId])->row_array(),
            );
            self::assertSame($before['appointments'], $db->count_all('appointments'));
        }
    }

    private function requireFixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);
        return $this->fixture;
    }

    private function basicClient(string $username, string $password): GateHttpClient
    {
        self::assertNotNull($this->server);
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Basic ' . base64_encode($username . ':' . $password)],
        );
    }

    private function bearerClient(string $token): GateHttpClient
    {
        self::assertNotNull($this->server);
        return new GateHttpClient($this->server->baseUrl, additionalHeaders: ['Authorization' => 'Bearer ' . $token]);
    }
}

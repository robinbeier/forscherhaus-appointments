<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP coverage for the site's same-origin-only CORS policy. */
final class CorsPolicyHttpTest extends TestCase
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

    public function testForeignAndNullOriginsReceiveNoCorsAllowHeadersOnSimpleRequests(): void
    {
        $beforeAppointments = get_instance()->db->count_all('appointments');
        foreach (['https://foreign.example.test', 'null'] as $origin) {
            $client = $this->clientWithHeaders(['Origin' => $origin]);
            $response = $client->get('booking');

            self::assertSame(200, $response->statusCode, $response->body);
            $this->assertNoCorsAllowHeaders($response);

            // An ordinary cross-origin form request can reach the controller
            // without CORS permission. Invalid booking data must not mutate it.
            $post = $client->requestApp('POST', 'booking/register', ['post_data' => []]);
            self::assertSame(409, $post->statusCode, $post->body);
            $this->assertNoCorsAllowHeaders($post);
            self::assertSame($beforeAppointments, get_instance()->db->count_all('appointments'));
        }
    }

    public function testOptionsShortCircuitKeepsBothApiRoutesMutationFreeAndEmitsNoCorsHeaders(): void
    {
        $fixture = $this->fixture();
        $before = $fixture->row('users', $fixture->customerId);
        $beforeUserCount = get_instance()->db->count_all('users');

        foreach (['api/v1/customers', 'api/v1/customers_api_v1/store'] as $path) {
            $response = $this->clientWithHeaders([
                'Origin' => 'https://foreign.example.test',
                'Access-Control-Request-Method' => 'DELETE',
                'Access-Control-Request-Headers' => 'Authorization, X-Synthetic-Request',
            ])->requestApp('OPTIONS', $path);

            self::assertSame(200, $response->statusCode, $path . ' preflight must short-circuit.');
            self::assertSame('', $response->body, $path . ' preflight must not invoke a controller.');
            $this->assertNoCorsAllowHeaders($response);
            self::assertSame($before, $fixture->row('users', $fixture->customerId));
            self::assertSame($beforeUserCount, get_instance()->db->count_all('users'));
        }
    }

    public function testSameOriginAppAndApiReadsRemainFunctionalWithoutCorsHeaders(): void
    {
        $fixture = $this->fixture();
        $credentials = $fixture->enableProviderHttpAuth();

        $bookingPage = $this->server()->client()->get('booking');
        self::assertSame(200, $bookingPage->statusCode, $bookingPage->body);
        $this->assertNoCorsAllowHeaders($bookingPage);

        $api = new GateHttpClient(
            $this->server()->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' . base64_encode($credentials['admin_username'] . ':' . $credentials['password']),
            ],
        );
        $response = $api->get('api/v1/customers', ['q' => $fixture->run]);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertStringContainsString($fixture->run . '_customer@synthetic.invalid', $response->body);
        $this->assertNoCorsAllowHeaders($response);
    }

    /** @param array<string, string> $headers */
    private function clientWithHeaders(array $headers): GateHttpClient
    {
        return new GateHttpClient($this->server()->baseUrl, additionalHeaders: $headers);
    }

    private function assertNoCorsAllowHeaders(GateHttpResponse $response): void
    {
        foreach (
            [
                'access-control-allow-origin',
                'access-control-allow-credentials',
                'access-control-allow-methods',
                'access-control-allow-headers',
            ]
            as $header
        ) {
            self::assertNull($response->header($header), $header . ' must be absent.');
        }
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

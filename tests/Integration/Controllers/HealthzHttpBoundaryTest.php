<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Exercise the token-protected deep health endpoint over real HTTP. */
final class HealthzHttpBoundaryTest extends TestCase
{
    private const TOKEN = 'synthetic-health-token-for-http-boundary';
    private const ROUTES = [['', 'healthz'], ['index.php', 'healthz'], ['index.php', 'healthz/index']];

    private ?DefenseCycleHttpServer $server = null;
    private ?string $previousEnvToken = null;
    private bool $hadEnvToken = false;
    private ?string $previousProcessToken = null;
    private bool $hadProcessToken = false;

    protected function setUp(): void
    {
        $this->hadEnvToken = array_key_exists('HEALTHZ_TOKEN', $_ENV);
        $this->previousEnvToken = $_ENV['HEALTHZ_TOKEN'] ?? null;
        $processToken = getenv('HEALTHZ_TOKEN');
        $this->hadProcessToken = $processToken !== false;
        $this->previousProcessToken = $processToken === false ? null : $processToken;

        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        $_ENV['HEALTHZ_TOKEN'] = self::TOKEN;
        putenv('HEALTHZ_TOKEN=' . self::TOKEN);

        try {
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->restoreHealthToken();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->restoreHealthToken();
        }
    }

    public function testMissingAndWrongTokensAreRejectedWithoutDeepHealthDetails(): void
    {
        $server = $this->server;
        self::assertNotNull($server);

        foreach (self::ROUTES as [$indexPage, $path]) {
            $missing = new GateHttpClient($server->baseUrl, $indexPage);
            $wrong = new GateHttpClient(
                $server->baseUrl,
                $indexPage,
                additionalHeaders: ['X-Health-Token' => 'wrong-synthetic-token'],
            );
            foreach ([$missing->get($path), $wrong->get($path)] as $response) {
                self::assertSame(401, $response->statusCode, $path . ': ' . $response->body);
                self::assertSame('no-store, no-cache, must-revalidate', $response->header('cache-control'));
                self::assertSame('no-cache', $response->header('pragma'));
                self::assertStringNotContainsString('checks', strtolower($response->body));
                self::assertStringNotContainsString(self::TOKEN, $response->body);
                self::assertStringNotContainsString('database', strtolower($response->body));
                self::assertStringNotContainsString('storage', strtolower($response->body));
                self::assertStringNotContainsString('pdf', strtolower($response->body));
            }
        }
    }

    public function testValidTokenReturnsStableShapeWithoutExposingSecrets(): void
    {
        $server = $this->server;
        self::assertNotNull($server);

        foreach (self::ROUTES as [$indexPage, $path]) {
            $client = new GateHttpClient(
                $server->baseUrl,
                $indexPage,
                additionalHeaders: ['X-Health-Token' => self::TOKEN],
            );
            $response = $client->get($path);
            self::assertContains($response->statusCode, [200, 503], $path . ': ' . $response->body);
            self::assertSame('no-store, no-cache, must-revalidate', $response->header('cache-control'), $path);
            self::assertSame('no-cache', $response->header('pragma'), $path);
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertContains($payload['status'] ?? null, ['ok', 'error']);
            self::assertIsString($payload['timestamp_utc'] ?? null);
            self::assertIsArray($payload['checks'] ?? null);
            self::assertSame(['database', 'gd', 'storage', 'pdf_renderer'], array_keys($payload['checks']));
            self::assertStringNotContainsString(self::TOKEN, $response->body);
        }
    }

    public function testHealthzIsGetOnlyOnCanonicalAndDirectIndexRoutes(): void
    {
        $server = $this->server;
        self::assertNotNull($server);

        foreach (self::ROUTES as [$indexPage, $path]) {
            $authorized = new GateHttpClient(
                $server->baseUrl,
                $indexPage,
                additionalHeaders: ['X-Health-Token' => self::TOKEN],
            );
            foreach ([$authorized, new GateHttpClient($server->baseUrl, $indexPage)] as $client) {
                self::assertSame(200, $client->get('login')->statusCode);
                foreach (['HEAD', 'POST', 'PUT'] as $method) {
                    $response = $client->requestApp($method, $path, [], null, $method === 'POST');
                    $case = $path . ' ' . $method;
                    self::assertSame(405, $response->statusCode, $case . ': ' . $response->body);
                    self::assertSame('GET', $response->header('allow'), $case);
                    self::assertSame('no-store, no-cache, must-revalidate', $response->header('cache-control'));
                    self::assertStringNotContainsString('checks', strtolower($response->body));
                    self::assertStringNotContainsString(self::TOKEN, $response->body);
                }
            }
        }
    }

    private function restoreHealthToken(): void
    {
        if ($this->hadEnvToken) {
            $_ENV['HEALTHZ_TOKEN'] = $this->previousEnvToken;
        } else {
            unset($_ENV['HEALTHZ_TOKEN']);
        }
        if ($this->hadProcessToken) {
            putenv('HEALTHZ_TOKEN=' . $this->previousProcessToken);
        } else {
            putenv('HEALTHZ_TOKEN');
        }
    }
}

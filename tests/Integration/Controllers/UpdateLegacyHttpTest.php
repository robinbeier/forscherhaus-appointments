<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP/DB coverage for the migration-gated Update controller.
 *
 * The baseline deliberately never sends an authorized POST. On the unchanged
 * source that request reaches Instance::migrate(), so it is reserved for a
 * follow-up regression run after the authorization fix is present.
 */
final class UpdateLegacyHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array<string, string> */
    private array $credentials = [];
    private ?array $actorSnapshot = null;

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
            $this->actorSnapshot = $this->fixture->row('users', $this->fixture->actorId);
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixture !== null && $this->actorSnapshot !== null) {
                $db = get_instance()->db;
                $db->update(
                    'users',
                    ['id_roles' => $this->actorSnapshot['id_roles']],
                    ['id' => $this->actorSnapshot['id']],
                );
                self::assertSame($this->actorSnapshot, $this->fixture->row('users', (int) $this->actorSnapshot['id']));
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAnonymousAndForbiddenRequestsDoNotReachMigration(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);
        $db = get_instance()->db;
        $before = $db->get('migrations')->result_array();

        // Keep the redirect response visible; the default client follows it.
        $anonymous = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Test' => 'update-anonymous']);
        foreach (['update', 'update/index'] as $path) {
            $get = $anonymous->get($path);
            self::assertContains($get->statusCode, [302, 307], $path);
            self::assertStringContainsString('/login', (string) $get->header('location'), $path);
            $post = $anonymous->post($path, [], withCsrfToken: false);
            // Depending on framework order, CSRF can reject before auth.
            self::assertContains($post->statusCode, [302, 307, 403], 'POST ' . $path);
            if (in_array($post->statusCode, [302, 307], true)) {
                self::assertStringContainsString('/login', (string) $post->header('location'), 'POST ' . $path);
            }
        }

        $provider = $this->login($this->credentials['provider_username']);
        foreach (['update', 'update/index'] as $path) {
            self::assertSame(403, $provider->get($path)->statusCode, $path);
            self::assertSame(403, $provider->post($path, [], withCsrfToken: false)->statusCode, 'POST ' . $path);
        }

        self::assertSame($before, $db->get('migrations')->result_array());
    }

    public function testAuthorizedGetHeadAndNonPostMethodsOnlyRenderOrReject(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        $db = get_instance()->db;
        $before = $db->get('migrations')->result_array();

        foreach (['update', 'update/index'] as $path) {
            $get = $admin->get($path);
            self::assertSame(200, $get->statusCode, $path . ' ' . $get->body);
            self::assertStringContainsString('<form method="post"', $get->body, $path);
            self::assertStringContainsString('name="csrf_token"', $get->body, $path);

            $head = $admin->requestApp('HEAD', $path);
            self::assertSame(200, $head->statusCode, 'HEAD ' . $path);
            foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $admin->requestApp($method, $path);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path);
                self::assertSame('GET, HEAD, POST', $response->header('allow'), $method . ' ' . $path);
            }
        }

        self::assertSame($before, $db->get('migrations')->result_array());
    }

    public function testDemotedExistingSessionCannotReadOrSubmitWithoutCsrfOrMigration(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($providerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('update')->statusCode);
        $before = $db->get('migrations')->result_array();

        try {
            self::assertTrue($db->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->actorId]));
            foreach (['update', 'update/index'] as $path) {
                // This is expected to fail on the unchanged source because
                // cannot(..., null) trusts the stale session role.
                $get = $admin->get($path);
                self::assertSame(403, $get->statusCode, $path . ' still accepts the demoted session.');
                $post = $admin->post($path, [], withCsrfToken: false);
                self::assertSame(403, $post->statusCode, 'POST ' . $path);
            }
            self::assertSame($before, $db->get('migrations')->result_array());
        } finally {
            self::assertTrue(
                $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]),
            );
        }
    }

    /**
     * Runs only after the authorization fix is present: a valid CSRF token must
     * still not let a demoted session reach Instance::migrate().
     */
    public function testDemotedExistingSessionRejectsCsrfValidPostWithoutMigration(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($providerRole['id'] ?? null);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('update')->statusCode);
        $before = $db->get('migrations')->result_array();

        try {
            self::assertTrue($db->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->actorId]));
            foreach (['update', 'update/index'] as $path) {
                $response = $admin->post($path, [], withCsrfToken: true);
                self::assertSame(403, $response->statusCode, 'POST ' . $path);
                self::assertSame($before, $db->get('migrations')->result_array(), 'POST ' . $path);
            }
        } finally {
            self::assertTrue(
                $db->update('users', ['id_roles' => $this->actorSnapshot['id_roles']], ['id' => $fixture->actorId]),
            );
        }
    }

    public function testPromotedExistingProviderSessionCanReadConfirmation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $adminRole = $db->get_where('roles', ['slug' => DB_SLUG_ADMIN])->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);
        $provider = $this->login($this->credentials['provider_username']);
        self::assertSame(403, $provider->get('update')->statusCode);

        try {
            self::assertTrue($db->update('users', ['id_roles' => $adminRole['id']], ['id' => $fixture->providerId]));
            foreach (['update', 'update/index'] as $path) {
                $response = $provider->get($path);
                self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
                self::assertStringContainsString('<form method="post"', $response->body, $path);
            }
        } finally {
            $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
            self::assertNotEmpty($providerRole['id'] ?? null);
            self::assertTrue($db->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->providerId]));
        }
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
        $json = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($json['success'] ?? false));
        return $client;
    }
}

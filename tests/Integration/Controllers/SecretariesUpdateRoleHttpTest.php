<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Secretaries::update surface. */
final class SecretariesUpdateRoleHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $actorRoleBefore = 0;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->actorRoleBefore = (int) ($this->fixture->row('users', $this->fixture->actorId)['id_roles'] ?? 0);
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
            if ($this->fixture !== null && $this->actorRoleBefore > 0) {
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->actorRoleBefore],
                    ['id' => $this->fixture->actorId],
                );
            }
        } finally {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testNonPostMethodsRejectCanonicalAndDirectAliasWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->secretaryWritePayload('method', [$this->fixture->providerId]);
        $targetId = $this->createSecretary($admin, $payload);
        $before = $this->fixture->secretaryDeleteState($targetId);

        foreach (['secretaries/update', 'index.php/secretaries/update'] as $path) {
            $client =
                $path === 'index.php/secretaries/update'
                    ? $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''))
                    : $admin;
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp($method, $path);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ': ' . $response->body);
                self::assertSame('POST', $response->header('allow'));
                self::assertSame($before, $this->fixture->secretaryDeleteState($targetId));
            }
        }
    }

    public function testUpdateWithoutIdCannotBecomeAnInsert(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->secretaryWritePayload('missing-id', [$this->fixture->providerId]);

        $response = $admin->post('secretaries/update', ['secretary' => $this->secretaryForm($payload)]);

        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertSame([], $this->fixture->secretaryWriteState($payload['email']));
    }

    public function testOtherRoleTargetIsRejectedWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->secretaryWritePayload('other-role', [$this->fixture->providerId]);
        $customerBefore = $this->fixture->row('users', $this->fixture->customerId);
        $payload['id'] = $this->fixture->customerId;

        $response = $admin->post('secretaries/update', ['secretary' => $this->secretaryForm($payload)]);

        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertSame($customerBefore, $this->fixture->row('users', $this->fixture->customerId));
        self::assertSame([], $this->fixture->secretaryWriteState($payload['email']));
    }

    public function testUpdateRequiresCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->secretaryWritePayload('csrf', [$this->fixture->providerId]);
        $targetId = $this->createSecretary($admin, $payload);
        $before = $this->fixture->secretaryDeleteState($targetId);
        $payload['id'] = $targetId;
        $payload['notes'] .= '_without_csrf';

        $response = $admin->post('secretaries/update', ['secretary' => $this->secretaryForm($payload)], null, false);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->secretaryDeleteState($targetId));
    }

    public function testAuthorizedAdminCanUpdateSecretaryThroughCanonicalAndDirectAlias(): void
    {
        $admin = $this->login($this->server->client());
        foreach (['secretaries/update', 'index.php/secretaries/update'] as $index => $path) {
            $payload = $this->fixture->secretaryWritePayload('authorized-' . $index, [$this->fixture->providerId]);
            $targetId = $this->createSecretary($admin, $payload);
            $payload['id'] = $targetId;
            $payload['notes'] .= '_updated';
            $client =
                $path === 'index.php/secretaries/update'
                    ? $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''))
                    : $admin;

            $response = $client->post($path, ['secretary' => $this->secretaryForm($payload)]);

            self::assertSame(200, $response->statusCode, $path . ': ' . $response->body);
            self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
            self::assertSame($payload['notes'], $this->fixture->secretaryDeleteState($targetId)['user']['notes']);
        }
    }

    public function testStoredRoleDemotionRejectsValidCsrfUpdateWithExistingSession(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->secretaryWritePayload('demoted', [$this->fixture->providerId]);
        $targetId = $this->createSecretary($admin, $payload);
        $before = $this->fixture->secretaryDeleteState($targetId);
        $payload['id'] = $targetId;
        $payload['notes'] .= '_must_not_update';
        $directAlias = $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);

        try {
            self::assertTrue(
                (bool) get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $this->fixture->actorId],
                ),
            );
            $response = $directAlias->post('index.php/secretaries/update', [
                'secretary' => $this->secretaryForm($payload),
            ]);

            self::assertSame(403, $response->statusCode, $response->body);
            self::assertSame($before, $this->fixture->secretaryDeleteState($targetId));
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    private function createSecretary(GateHttpClient $admin, array $payload): int
    {
        $response = $admin->post('secretaries/store', ['secretary' => $this->secretaryForm($payload)]);
        self::assertSame(200, $response->statusCode, $response->body);
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($decoded['success'] ?? false), $response->body);
        $id = (int) ($decoded['id'] ?? 0);
        self::assertGreaterThan(0, $id, $response->body);
        self::assertSame($payload['email'], $this->fixture->secretaryWriteState($payload['email'])['user']['email']);
        return $id;
    }

    private function secretaryForm(array $payload): array
    {
        return [
            'id' => $payload['id'] ?? null,
            'first_name' => $payload['firstName'],
            'last_name' => $payload['lastName'],
            'email' => $payload['email'],
            'notes' => $payload['notes'],
            'providers' => $payload['providers'],
            'settings' => $payload['settings'],
        ];
    }

    private function login(GateHttpClient $client): GateHttpClient
    {
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['admin_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }
}

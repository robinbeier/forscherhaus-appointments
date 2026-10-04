<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Secretaries::store surface. */
final class SecretariesStoreRoleHttpTest extends TestCase
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

    public function testAuthorizedAdminCanCreateSecretaryThroughCanonicalAndDirectAlias(): void
    {
        $admin = $this->login($this->server->client());

        foreach (['secretaries/store', 'index.php/secretaries/store'] as $index => $path) {
            $payload = $this->fixture->secretaryWritePayload('authorized-' . $index, [$this->fixture->providerId]);
            $client =
                $path === 'secretaries/store'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));

            $response = $client->post($path, ['secretary' => $this->secretaryForm($payload)]);

            self::assertSame(200, $response->statusCode, $path . ': ' . $response->body);
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue((bool) ($data['success'] ?? false), $response->body);
            self::assertGreaterThan(0, (int) ($data['id'] ?? 0));
            $state = $this->fixture->secretaryWriteState($payload['email']);
            self::assertSame($payload['email'], $state['user']['email'] ?? null);
            self::assertSame([$this->fixture->providerId], $state['providers']);
        }
    }

    public function testStoreRequiresCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        foreach (['secretaries/store', 'index.php/secretaries/store'] as $index => $path) {
            $payload = $this->fixture->secretaryWritePayload('csrf-' . $index, [$this->fixture->providerId]);
            $client =
                $path === 'secretaries/store'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));

            $response = $client->post($path, ['secretary' => $this->secretaryForm($payload)], null, false);

            self::assertSame(403, $response->statusCode, $path . ': ' . $response->body);
            self::assertSame([], $this->fixture->secretaryWriteState($payload['email']));
        }
    }

    public function testStoreWithExistingSecretaryIdRejectsWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->secretaryWritePayload('existing-id', [$this->fixture->providerId]);
        $created = $admin->post('secretaries/store', ['secretary' => $this->secretaryForm($payload)]);
        self::assertSame(200, $created->statusCode, $created->body);
        $targetId = (int) (json_decode($created->body, true, 512, JSON_THROW_ON_ERROR)['id'] ?? 0);
        self::assertGreaterThan(0, $targetId);
        $before = $this->fixture->secretaryDeleteState($targetId);

        $payload['id'] = $targetId;
        $payload['notes'] .= '-updated';
        $response = $admin->post('secretaries/store', ['secretary' => $this->secretaryForm($payload)]);

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->secretaryDeleteState($targetId));
    }

    public function testNonPostMethodsRejectCanonicalAndDirectAliasWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        foreach (['secretaries/store', 'index.php/secretaries/store'] as $path) {
            $client =
                $path === 'secretaries/store'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp($method, $path);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ': ' . $response->body);
                self::assertSame('POST', $response->header('allow'));
            }
        }
    }

    public function testStoreRejectsSuppliedNonSecretaryRoleWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->secretaryWritePayload('role-override', [$this->fixture->providerId]);
        $before = $this->fixture->secretaryWriteState($payload['email']);
        $payload['id_roles'] = (int) get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array()['id'];

        $response = $admin->post('secretaries/store', ['secretary' => $this->secretaryForm($payload)]);

        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertSame($before, $this->fixture->secretaryWriteState($payload['email']));
    }

    public function testStoredRoleDemotionRejectsValidCsrfStoreThroughCanonicalAndDirectAlias(): void
    {
        $admin = $this->login($this->server->client());
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

            foreach (['secretaries/store', 'index.php/secretaries/store'] as $index => $path) {
                $payload = $this->fixture->secretaryWritePayload('demoted-' . $index, [$this->fixture->providerId]);
                $before = $this->fixture->secretaryWriteState($payload['email']);
                $client = $path === 'secretaries/store' ? $admin : $directAlias;
                $response = $client->post($path, ['secretary' => $this->secretaryForm($payload)]);

                self::assertSame(403, $response->statusCode, $path . ': ' . $response->body);
                self::assertSame($before, $this->fixture->secretaryWriteState($payload['email']));
            }
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    private function secretaryForm(array $payload): array
    {
        $form = [
            'id' => $payload['id'] ?? null,
            'first_name' => $payload['firstName'],
            'last_name' => $payload['lastName'],
            'email' => $payload['email'],
            'notes' => $payload['notes'],
            'providers' => $payload['providers'],
            'settings' => $payload['settings'],
        ];
        if (array_key_exists('id_roles', $payload)) {
            $form['id_roles'] = $payload['id_roles'];
        }
        return $form;
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

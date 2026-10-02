<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for the classic Providers::store surface. */
final class ProvidersStoreHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $actorRoleBefore = 0;
    private ?array $addOnlyRoleBefore = null;

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
            if ($this->addOnlyRoleBefore !== null) {
                get_instance()->db->update(
                    'roles',
                    ['users' => $this->addOnlyRoleBefore['users']],
                    ['id' => $this->addOnlyRoleBefore['id']],
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

    public function testAuthorizedAdminCanCreateSyntheticProvider(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->providerWritePayload('store-baseline');

        $response = $admin->post('providers/store', ['provider' => $this->formPayload($payload)]);

        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($data['success'] ?? false), $response->body);
        self::assertGreaterThan(0, (int) ($data['id'] ?? 0));
        $state = $this->fixture->providerWriteState($payload['email']);
        self::assertSame($payload['email'], $state['user']['email'] ?? null);
        self::assertSame($payload['settings']['username'], $state['settings']['username'] ?? null);
        self::assertSame([$this->fixture->serviceId], $state['services']);
    }

    public function testSafeProviderValidationMessagesRemainActionableWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $existingUser = $this->fixture->row('users', $this->fixture->providerId);
        $existingSettings = get_instance()
            ->db->get_where('user_settings', ['id_users' => $this->fixture->providerId])
            ->row_array();
        self::assertNotEmpty($existingUser);
        self::assertNotEmpty($existingSettings);

        $duplicateUsername = $this->fixture->providerWritePayload('store-duplicate-username');
        $duplicateUsername['settings']['username'] = $existingSettings['username'];
        $duplicateEmail = $this->fixture->providerWritePayload('store-duplicate-email');
        $duplicateEmail['email'] = $existingUser['email'];

        foreach (
            [
                [$duplicateUsername, 'The provided username is already in use'],
                [$duplicateEmail, 'The provided email address is already in use'],
            ]
            as [$payload, $message]
        ) {
            $before = $this->fixture->providerWriteSnapshot();
            $response = $admin->post('providers/store', ['provider' => $this->formPayload($payload)]);

            self::assertSame(500, $response->statusCode, $response->body);
            self::assertStringContainsString($message, $response->body);
            self::assertSame($before, $this->fixture->providerWriteSnapshot());
        }
    }

    public function testStoredRoleDemotionRejectsValidCsrfStoreWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->providerWritePayload('store-demoted');
        $invalidPayload = $this->fixture->providerWritePayload('store-demoted-invalid-id');
        $invalidPayload['id'] = $this->fixture->providerId;
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
            $before = $this->fixture->providerWriteSnapshot();
            $response = $admin->post('providers/store', ['provider' => $this->formPayload($payload)]);
            $invalidResponse = $admin->post('providers/store', ['provider' => $this->formPayload($invalidPayload)]);

            self::assertSame(403, $response->statusCode, $response->body);
            self::assertSame(403, $invalidResponse->statusCode, $invalidResponse->body);
            self::assertStringContainsString('Forbidden', $invalidResponse->body);
            self::assertSame([], $this->fixture->providerWriteState($payload['email']));
            self::assertSame([], $this->fixture->providerWriteState($invalidPayload['email']));
            self::assertSame($before, $this->fixture->providerWriteSnapshot());
        } finally {
            get_instance()->db->update(
                'users',
                ['id_roles' => $this->actorRoleBefore],
                ['id' => $this->fixture->actorId],
            );
        }
    }

    public function testCanonicalAndDirectAliasGetWithValidPayloadCannotCreate(): void
    {
        $admin = $this->login($this->server->client());

        foreach (['providers/store', 'index.php/providers/store'] as $path) {
            $case = $path === 'providers/store' ? 'canonical' : 'alias';
            $payload = $this->fixture->providerWritePayload('store-get-' . $case);
            $client =
                $path === 'providers/store'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            $response = $client->get($path, ['provider' => $this->formPayload($payload)]);

            self::assertSame(405, $response->statusCode, $response->body);
            self::assertSame('POST', $response->header('allow'), $path . ' Allow header.');
            self::assertSame([], $this->fixture->providerWriteState($payload['email']));
        }
    }

    public function testCanonicalAndDirectAliasRejectWrongMethodsWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $methods = ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'];

        foreach (['providers/store', 'index.php/providers/store'] as $path) {
            $client =
                $path === 'providers/store'
                    ? $admin
                    : $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
            $before = $this->fixture->providerWriteSnapshot();
            foreach ($methods as $method) {
                $response = $client->requestApp($method, $path, [], null, false);

                self::assertSame(405, $response->statusCode, $method . ' ' . $path . ' must be rejected.');
                self::assertSame('POST', $response->header('allow'), $method . ' ' . $path . ' Allow header.');
                self::assertSame($before, $this->fixture->providerWriteSnapshot());
            }
        }
    }

    public function testStoreRequiresCsrfWithoutMutation(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->providerWritePayload('store-no-csrf');
        $before = $this->fixture->providerWriteSnapshot();

        $response = $admin->post('providers/store', ['provider' => $this->formPayload($payload)], null, false);

        self::assertSame(403, $response->statusCode, $response->body);
        self::assertSame([], $this->fixture->providerWriteState($payload['email']));
        self::assertSame($before, $this->fixture->providerWriteSnapshot());
    }

    public function testStoreRejectsExistingIdWithoutMutatingTarget(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->providerWritePayload('store-existing-id');
        $payload['id'] = $this->fixture->providerId;
        $before = $this->fixture->providerWriteSnapshot();
        $targetBefore = $this->fixture->providerDeleteState($this->fixture->providerId);

        $response = $admin->post('providers/store', ['provider' => $this->formPayload($payload)]);

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertSame([], $this->fixture->providerWriteState($payload['email']));
        self::assertSame($targetBefore, $this->fixture->providerDeleteState($this->fixture->providerId));
        self::assertSame($before, $this->fixture->providerWriteSnapshot());
    }

    public function testStoreAcceptsExactlyEmptyIdAsCreateAndRejectsOtherIdBoundaries(): void
    {
        $admin = $this->login($this->server->client());
        $emptyIdPayload = $this->fixture->providerWritePayload('store-empty-id');
        $emptyIdPayload['id'] = '';
        $createResponse = $admin->post('providers/store', ['provider' => $this->formPayload($emptyIdPayload)]);

        self::assertSame(200, $createResponse->statusCode, $createResponse->body);
        self::assertNotEmpty($this->fixture->providerWriteState($emptyIdPayload['email']));

        foreach ([0, '0', ' ', ['unexpected']] as $case => $invalidId) {
            $payload = $this->fixture->providerWritePayload('store-id-boundary-' . $case);
            $payload['id'] = $invalidId;
            $before = $this->fixture->providerWriteSnapshot();

            $response = $admin->post('providers/store', ['provider' => $this->formPayload($payload)]);

            self::assertSame(400, $response->statusCode, $response->body);
            self::assertSame([], $this->fixture->providerWriteState($payload['email']));
            self::assertSame($before, $this->fixture->providerWriteSnapshot());
        }
    }

    public function testAddOnlyActorMayCreateButCannotUseStoreToEdit(): void
    {
        $db = get_instance()->db;
        $providerRole = $db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        self::assertNotEmpty($providerRole);
        $this->addOnlyRoleBefore = $providerRole;

        self::assertTrue((bool) $db->update('roles', ['users' => PRIV_ADD], ['id' => (int) $providerRole['id']]));
        self::assertTrue(
            (bool) $db->update('users', ['id_roles' => (int) $providerRole['id']], ['id' => $this->fixture->actorId]),
        );

        $actor = $this->login($this->server->client());
        $createPayload = $this->fixture->providerWritePayload('store-add-only-create');
        $createResponse = $actor->post('providers/store', ['provider' => $this->formPayload($createPayload)]);
        self::assertSame(200, $createResponse->statusCode, $createResponse->body);
        self::assertNotEmpty($this->fixture->providerWriteState($createPayload['email']));

        $targetBefore = $this->fixture->providerDeleteState($this->fixture->providerId);
        $editPayload = $this->fixture->providerWritePayload('store-add-only-edit');
        $editPayload['id'] = $this->fixture->providerId;
        $editPayload['notes'] = $this->fixture->run . '_add_only_must_not_edit';
        $editResponse = $actor->post('providers/store', ['provider' => $this->formPayload($editPayload)]);

        self::assertSame(400, $editResponse->statusCode, $editResponse->body);
        self::assertSame($targetBefore, $this->fixture->providerDeleteState($this->fixture->providerId));
        self::assertSame([], $this->fixture->providerWriteState($editPayload['email']));
    }

    public function testInvalidServiceDoesNotLeavePartialProviderAggregate(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->providerWritePayload('store-invalid-service');
        $payload['services'] = [999999999];
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('services', ['id' => $payload['services'][0]])
                ->num_rows(),
        );
        $before = $this->fixture->providerWriteSnapshot();

        $response = $admin->post('providers/store', ['provider' => $this->formPayload($payload)]);

        self::assertSame(400, $response->statusCode, $response->body);
        self::assertStringNotContainsString('SQLSTATE', $response->body);
        self::assertStringNotContainsString('INSERT INTO', $response->body);
        self::assertStringNotContainsString('services_providers', $response->body);
        self::assertSame([], $this->fixture->providerWriteState($payload['email']));
        self::assertSame($before, $this->fixture->providerWriteSnapshot());
    }

    public function testServiceAssociationFailureDoesNotLeavePartialProviderAggregate(): void
    {
        $admin = $this->login($this->server->client());
        $payload = $this->fixture->providerWritePayload('store-trigger-failure');
        $before = $this->fixture->providerWriteSnapshot();
        $db = get_instance()->db;
        $trigger = $this->fixture->run . '_deny_provider_store_service';
        $fixtureAdmin = get_instance()->load->database(
            [
                'hostname' => 'mysql',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'easyappointments',
                'dbdriver' => 'mysqli',
                'dbprefix' => $db->dbprefix,
                'pconnect' => false,
                'db_debug' => false,
                'char_set' => 'utf8mb4',
                'dbcollat' => 'utf8mb4_general_ci',
            ],
            true,
        );
        $created = false;
        try {
            self::assertTrue(
                $fixtureAdmin->query(
                    'CREATE TRIGGER `' .
                        $trigger .
                        '` BEFORE INSERT ON `' .
                        $db->dbprefix('services_providers') .
                        '` FOR EACH ROW BEGIN IF NEW.id_users = (SELECT id FROM `' .
                        $db->dbprefix('users') .
                        '` WHERE email = ' .
                        $fixtureAdmin->escape($payload['email']) .
                        ' LIMIT 1) THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = ' .
                        '\'synthetic provider store service failure\'; ' .
                        'END IF; END',
                ),
            );
            $created = true;

            $response = $admin->post('providers/store', ['provider' => $this->formPayload($payload)]);

            self::assertSame(500, $response->statusCode, $response->body);
            self::assertStringContainsString('Provider creation failed.', $response->body);
            self::assertStringNotContainsString('SQLSTATE', $response->body);
            self::assertStringNotContainsString('synthetic provider store service failure', $response->body);
            self::assertStringNotContainsString('services_providers', $response->body);
            self::assertSame([], $this->fixture->providerWriteState($payload['email']));
            self::assertSame($before, $this->fixture->providerWriteSnapshot());
        } finally {
            try {
                if ($created) {
                    self::assertTrue($fixtureAdmin->query('DROP TRIGGER `' . $trigger . '`'));
                }
                $triggerCount = $fixtureAdmin->query(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS ' .
                        'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                    [$trigger],
                );
                self::assertSame(0, (int) $triggerCount->num_rows());
            } finally {
                $fixtureAdmin->close();
            }
        }
    }

    private function formPayload(array $payload): array
    {
        return [
            'first_name' => $payload['firstName'],
            'last_name' => $payload['lastName'],
            'email' => $payload['email'],
            'phone_number' => $payload['phone'],
            'notes' => $payload['notes'],
            'services' => $payload['services'],
            'settings' => [
                'username' => $payload['settings']['username'],
                'password' => $payload['settings']['password'],
            ],
        ] + (isset($payload['id']) ? ['id' => $payload['id']] : []);
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

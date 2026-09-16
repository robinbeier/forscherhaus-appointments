<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Ordinary local HTTP POST/PUT/DELETE coverage for the provider API. */
final class ProviderApiHttpWriteTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private array $secretValues = [];

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
        } catch (Throwable $error) {
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

    public function testProviderJsonWritesHonorAuthPersistFieldsAndOmitSecrets(): void
    {
        $f = $this->fixture;
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $bearer = $this->bearerClient($this->credentials['token']);

        $adminPayload = $f->providerWritePayload('admin-post');
        $adminResponse = $admin->requestJsonApp('POST', 'api/v1/providers', $adminPayload);
        $adminData = $this->success($adminResponse, 201);
        $adminState = $f->providerWriteState($adminPayload['email']);
        $this->assertWriteResult($adminData, $adminPayload, $adminState, 'admin-post');

        $adminUpdate = $adminPayload;
        $adminUpdate['firstName'] = 'Updated Admin';
        $adminUpdate['notes'] = 'updated-admin-notes';
        $adminUpdate['settings']['googleToken'] = 'admin-updated-google';
        $adminUpdate['settings']['caldavPassword'] = 'admin-updated-caldav';
        $adminId = (int) ($adminState['user']['id'] ?? 0);
        $adminUpdateResponse = $admin->requestJsonApp('PUT', 'api/v1/providers/' . $adminId, $adminUpdate);
        $this->assertWriteResult(
            $this->success($adminUpdateResponse, 200),
            $adminUpdate,
            $f->providerWriteState($adminPayload['email']),
            'admin-put',
        );

        $bearerPayload = $f->providerWritePayload('bearer-post');
        $bearerData = $this->success($bearer->requestJsonApp('POST', 'api/v1/providers', $bearerPayload), 201);
        $bearerState = $f->providerWriteState($bearerPayload['email']);
        $this->assertWriteResult($bearerData, $bearerPayload, $bearerState, 'bearer-post');

        $bearerUpdate = $bearerPayload;
        $bearerUpdate['firstName'] = 'Updated Bearer';
        $bearerUpdate['notes'] = 'updated-bearer-notes';
        $bearerUpdate['settings']['googleToken'] = 'bearer-updated-google';
        $bearerUpdate['settings']['caldavPassword'] = 'bearer-updated-caldav';
        $bearerId = (int) ($bearerState['user']['id'] ?? 0);
        $this->assertWriteResult(
            $this->success($bearer->requestJsonApp('PUT', 'api/v1/providers/' . $bearerId, $bearerUpdate), 200),
            $bearerUpdate,
            $f->providerWriteState($bearerPayload['email']),
            'bearer-put',
        );
    }

    public function testProviderJsonDeniedWritesDoNotMutateFixture(): void
    {
        $f = $this->fixture;
        $authClients = [
            'no-auth' => $this->server->client(),
            'wrong-admin-password' => $this->basicClient(
                $this->credentials['admin_username'],
                'synthetic-wrong-password',
            ),
            'provider-basic' => $this->basicClient(
                $this->credentials['provider_username'],
                $this->credentials['password'],
            ),
            'wrong-bearer' => $this->bearerClient($this->credentials['token'] . '-wrong'),
        ];

        $existingPayload = $f->providerWritePayload('denial-target');
        $existing = $this->basicClient(
            $this->credentials['admin_username'],
            $this->credentials['password'],
        )->requestJsonApp('POST', 'api/v1/providers', $existingPayload);
        $existingState = $f->providerWriteState($existingPayload['email']);
        $this->assertWriteResult($this->success($existing, 201), $existingPayload, $existingState, 'denial-target');
        $id = (int) $existingState['user']['id'];

        foreach ($authClients as $case => $client) {
            $update = $existingPayload;
            $update['firstName'] = 'Denied Change';
            $update['notes'] = $f->run . '_denied_' . $case;
            $update['settings']['googleToken'] .= '_denied';
            $update['settings']['caldavPassword'] .= '_denied';
            foreach (
                [
                    'POST' => [$f->providerWritePayload($case . '-post'), 'api/v1/providers'],
                    'PUT' => [$update, 'api/v1/providers/' . $id],
                ]
                as $method => [$payload, $path]
            ) {
                $before = $f->providerWriteSnapshot();
                $response = $client->requestJsonApp($method, $path, $payload);
                self::assertSame(401, $response->statusCode, $case . ' ' . $method . ' must be denied.');
                self::assertNotNull($response->header('www-authenticate'), $case . ' must challenge with Basic auth.');
                self::assertFalse(
                    str_contains($response->body, $f->run),
                    $case . ' ' . $method . ' returned fixture data.',
                );
                self::assertTrue(
                    $before === $f->providerWriteSnapshot(),
                    $case . ' ' . $method . ' mutated fixture tables.',
                );
            }
        }
    }

    public function testProviderDeleteUsesAuthorizedAdminAndBearerAndIsIdempotent(): void
    {
        $f = $this->fixture;
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $bearer = $this->bearerClient($this->credentials['token']);

        foreach (['admin-delete' => $admin, 'bearer-delete' => $bearer] as $case => $client) {
            $before = $f->providerWriteSnapshot();
            $payload = $f->providerWritePayload($case);
            $created = $this->success($admin->requestJsonApp('POST', 'api/v1/providers', $payload), 201);
            $state = $f->providerWriteState($payload['email']);
            $id = (int) ($state['user']['id'] ?? 0);
            self::assertGreaterThan(0, $id, $case . ' fixture must have a positive Provider ID.');
            self::assertSame($id, (int) ($created['id'] ?? 0), $case . ' fixture creation must identify its Provider.');
            self::assertNotSame([], $state, $case . ' fixture must be registered before its HTTP requests.');
            self::assertNotSame([], $state['settings'], $case . ' fixture must have a settings row before deletion.');
            self::assertSame(
                [$f->serviceId],
                $state['services'],
                $case . ' fixture must have its expected service relation.',
            );
            self::assertSame([], $f->providerDeleteState($id)['appointments'], $case . ' must have no appointments.');

            $deleted = $client->requestApp('DELETE', 'api/v1/providers/' . $id);
            self::assertSame(204, $deleted->statusCode, $case . ' authorized delete must return 204.');
            self::assertSame(
                ['user' => [], 'settings' => [], 'services' => [], 'appointments' => []],
                $f->providerDeleteState($id),
                $case . ' must remove the Provider and owned rows/relations.',
            );
            self::assertSame($before, $f->providerWriteSnapshot(), $case . ' must preserve unrelated sentinel data.');

            $repeat = $client->requestApp('DELETE', 'api/v1/providers/' . $id);
            self::assertSame(404, $repeat->statusCode, $case . ' repeated delete must return 404.');
            self::assertSame($before, $f->providerWriteSnapshot(), $case . ' repeated delete must not mutate state.');
        }

        $f->cleanup();
        $f->cleanup();
        foreach (['admin-delete', 'bearer-delete'] as $case) {
            self::assertSame([], $f->providerWriteState($f->run . '_write_' . $case . '@synthetic.invalid'));
        }
    }

    public function testProviderDeleteRejectsMissingAuthWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $payload = $f->providerWritePayload('delete-no-auth');
        $created = $this->success($admin->requestJsonApp('POST', 'api/v1/providers', $payload), 201);
        $id = (int) ($created['id'] ?? 0);
        self::assertNotSame([], $f->providerWriteState($payload['email']));
        $before = $f->providerWriteSnapshot();

        $response = $this->server->client()->requestApp('DELETE', 'api/v1/providers/' . $id);
        self::assertSame(401, $response->statusCode, 'Missing authentication must be rejected.');
        self::assertNotNull($response->header('www-authenticate'));
        self::assertSame($before, $f->providerWriteSnapshot(), 'Missing authentication must not mutate state.');
    }

    public function testProviderWriteCleanupIsRepeatableAfterResponseIsIgnored(): void
    {
        $f = $this->fixture;
        $payload = $f->providerWritePayload('cleanup-regression');
        $response = $this->basicClient(
            $this->credentials['admin_username'],
            $this->credentials['password'],
        )->requestJsonApp('POST', 'api/v1/providers', $payload);
        $created = (int) ($f->providerWriteState($payload['email'])['user']['id'] ?? 0) > 0;
        $f->cleanup();
        $f->cleanup();
        self::assertTrue($created, 'Cleanup regression requires an actual created Provider.');
        self::assertSame([], $f->providerWriteState($payload['email']));
    }

    private function success(GateHttpResponse $response, int $status): array
    {
        self::assertSame($status, $response->statusCode);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($data), 'Successful response must be JSON data.');
        self::assertFalse(array_key_exists('exception', $data), 'Successful response must not be an error object.');
        return $data;
    }

    private function assertWriteResult(array $data, array $payload, array $state, string $case): void
    {
        self::assertTrue((int) ($data['id'] ?? 0) > 0, $case . ' response must contain a positive ID.');
        self::assertSame((int) ($state['user']['id'] ?? 0), (int) $data['id']);
        foreach (
            ['email' => 'email', 'firstName' => 'first_name', 'lastName' => 'last_name', 'notes' => 'notes']
            as $api => $db
        ) {
            self::assertTrue(($data[$api] ?? null) === $payload[$api], $case . ' response identity must match input.');
            self::assertTrue(
                ($state['user'][$db] ?? null) === $payload[$api],
                $case . ' persisted identity must match input.',
            );
        }
        self::assertTrue(
            ($state['services'] ?? null) === $payload['services'],
            'Stored service relation must match input.',
        );
        self::assertTrue(
            ($data['services'] ?? null) === $payload['services'],
            'Response service relation must match input.',
        );
        self::assertTrue(
            ($data['settings']['username'] ?? null) === $payload['settings']['username'],
            'Response username must match input.',
        );
        foreach (
            ['username' => 'username', 'googleToken' => 'google_token', 'caldavPassword' => 'caldav_password']
            as $api => $db
        ) {
            self::assertTrue(
                ($state['settings'][$db] ?? null) === $payload['settings'][$api],
                'Stored settings must match input.',
            );
        }
        self::assertTrue(
            isset($state['settings']['salt'], $state['settings']['password']) &&
                hash_password($state['settings']['salt'], $payload['settings']['password']) ===
                    $state['settings']['password'],
            'Stored password must authenticate the synthetic input.',
        );
        $normalized = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['googleToken', 'caldavPassword', 'google_token', 'caldav_password'] as $key) {
            self::assertFalse(
                str_contains($normalized, '"' . $key . '":'),
                'Response must recursively omit secret keys.',
            );
        }
        $this->secretValues = array_unique(
            array_merge($this->secretValues, [
                $payload['settings']['password'],
                $payload['settings']['googleToken'],
                $payload['settings']['caldavPassword'],
            ]),
        );
        foreach ($this->secretValues as $secret) {
            self::assertFalse(
                str_contains($normalized, (string) $secret),
                'Complete response must omit synthetic secret values.',
            );
        }
    }

    private function basicClient(string $username, string $password): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Basic ' . base64_encode($username . ':' . $password)],
        );
    }

    private function bearerClient(string $token): GateHttpClient
    {
        return new GateHttpClient($this->server->baseUrl, additionalHeaders: ['Authorization' => 'Bearer ' . $token]);
    }
}

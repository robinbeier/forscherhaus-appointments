<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

final class StaffSettingsApiHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }
        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->fixture->seedStaffIntegrationSecrets($this->fixture->actorId);
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

    public function testAdminAndSecretaryReadsAndSettingsRequireAuthAndExposePublicKeys(): void
    {
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $secretary = $this->fixture->secretaryWritePayload('read', [$this->fixture->providerId]);
        $created = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $secretary), 201);
        $secretaryId = (int) $created['id'];
        $this->fixture->seedStaffIntegrationSecrets($secretaryId);
        $adminRow = $this->fixture->row('users', $this->fixture->actorId);
        $adminIdentity = [
            'id' => $this->fixture->actorId,
            'firstName' => $adminRow['first_name'],
            'email' => $adminRow['email'],
        ];
        $secretaryIdentity = [
            'id' => $secretaryId,
            'firstName' => $secretary['firstName'],
            'email' => $secretary['email'],
        ];
        foreach ([$admin, $this->bearerClient($this->credentials['token'])] as $client) {
            $admins = $this->success($client->get('api/v1/admins', ['q' => $this->fixture->run]));
            $adminMatch = array_values(
                array_filter($admins, static fn(array $row): bool => (int) ($row['id'] ?? 0) === $adminIdentity['id']),
            );
            self::assertCount(1, $adminMatch);
            $this->assertStaff($adminMatch[0], $adminIdentity, false);
            $this->assertStaff(
                $this->success($client->get('api/v1/admins/' . $this->fixture->actorId)),
                $adminIdentity,
                false,
            );
            $secretaries = $this->success($client->get('api/v1/secretaries', ['q' => $this->fixture->run]));
            $secretaryMatch = array_values(
                array_filter($secretaries, static fn(array $row): bool => (int) ($row['id'] ?? 0) === $secretaryId),
            );
            self::assertCount(1, $secretaryMatch);
            $this->assertStaff($secretaryMatch[0], $secretaryIdentity, true);
            $this->assertStaff(
                $this->success($client->get('api/v1/secretaries/' . $secretaryId)),
                $secretaryIdentity,
                true,
            );
            $settings = $this->success($client->get('api/v1/settings'));
            self::assertContains(['name' => 'customer_notifications', 'value' => '0'], $settings);
            self::assertSame(
                ['name' => 'customer_notifications', 'value' => '0'],
                $this->success($client->get('api/v1/settings/customer_notifications')),
            );
            $this->assertNoSyntheticSecrets($admins, $secretaries, $settings);
        }
    }

    public static function unauthenticatedReadAuthCases(): array
    {
        return [
            'no-credentials' => ['no-credentials'],
            'wrong-admin-password' => ['wrong-admin-password'],
            'missing-admin-username' => ['missing-admin-username'],
            'invalid-bearer-token' => ['invalid-bearer-token'],
        ];
    }

    #[DataProvider('unauthenticatedReadAuthCases')]
    public function testStaffAndSettingsReadsRejectInvalidAuthenticationWithoutFixtureValues(string $case): void
    {
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $secretaryPayload = $this->fixture->secretaryWritePayload('noauth', [$this->fixture->providerId]);
        $secretary = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $secretaryPayload), 201);
        $secretaryId = (int) $secretary['id'];
        $this->fixture->seedStaffIntegrationSecrets($secretaryId);
        $client = match ($case) {
            'no-credentials' => $this->server->client(),
            'wrong-admin-password' => $this->basicClient(
                $this->credentials['admin_username'],
                $this->credentials['password'] . '-invalid',
            ),
            'missing-admin-username' => $this->basicClient(
                $this->credentials['admin_username'] . '-missing',
                $this->credentials['password'],
            ),
            'invalid-bearer-token' => $this->bearerClient($this->credentials['token'] . '-invalid'),
        };
        foreach (
            [
                'api/v1/admins',
                'api/v1/admins/' . $this->fixture->actorId,
                'api/v1/secretaries',
                'api/v1/secretaries/' . $secretaryId,
                'api/v1/settings',
                'api/v1/settings/customer_notifications',
            ]
            as $path
        ) {
            $response = $client->get($path);
            self::assertSame(401, $response->statusCode, $path . ' must require authentication.');
            self::assertNotEmpty(trim((string) $response->header('www-authenticate')));
            self::assertStringNotContainsString($this->fixture->run, $response->body);
            $this->assertNoSyntheticSecrets($response->body);
        }
    }

    public static function validWriteAuthenticationCases(): array
    {
        return ['basic-admin' => ['basic'], 'bearer' => ['bearer']];
    }

    #[DataProvider('validWriteAuthenticationCases')]
    public function testOwnedSettingWritePersistsThroughHttp(string $authentication): void
    {
        $setting = $this->fixture->ownedSetting('api-write', 'before');
        $value = $this->fixture->run . '_updated';
        $response = $this->success(
            $this->writeClient($authentication)->requestJsonApp('PUT', 'api/v1/settings/' . $setting['name'], [
                'value' => $value,
            ]),
        );
        self::assertSame(['name' => $setting['name'], 'value' => $value], $response);
        self::assertSame($value, $this->fixture->settingRow((int) $setting['id'])['value']);
    }

    #[DataProvider('validWriteAuthenticationCases')]
    public function testAdminAndSecretaryWritesPersistWithoutPasswordOnUpdateAndSecretaryClear(
        string $authentication,
    ): void {
        $admin = $this->writeClient($authentication);
        $adminPayload = $this->fixture->adminWritePayload('write');
        $adminPayload['notes'] = $this->fixture->run . '_admin_notes';
        $adminCreate = $this->success($admin->requestJsonApp('POST', 'api/v1/admins', $adminPayload), 201);
        $adminId = (int) $adminCreate['id'];
        $this->assertStaff(
            $adminCreate,
            ['id' => $adminId, 'firstName' => $adminPayload['firstName'], 'email' => $adminPayload['email']],
            false,
        );
        $adminBefore = $this->fixture->userSettingsRow($adminId);
        unset($adminPayload['settings']['password']);
        $adminPayload['firstName'] = 'HTTP Admin Updated';
        $adminUpdate = $this->success($admin->requestJsonApp('PUT', 'api/v1/admins/' . $adminId, $adminPayload));
        $adminAfter = $this->fixture->userSettingsRow($adminId);
        self::assertSame('HTTP Admin Updated', $this->fixture->row('users', $adminId)['first_name']);
        self::assertSame($adminBefore['password'], $adminAfter['password']);
        self::assertSame($adminBefore['salt'], $adminAfter['salt']);
        $this->assertStaff(
            $adminUpdate,
            ['id' => $adminId, 'firstName' => $adminPayload['firstName'], 'email' => $adminPayload['email']],
            false,
        );

        $secretaryPayload = $this->fixture->secretaryWritePayload('write', [$this->fixture->providerId]);
        $secretaryCreate = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $secretaryPayload), 201);
        $secretaryId = (int) $secretaryCreate['id'];
        $this->assertStaff(
            $secretaryCreate,
            [
                'id' => $secretaryId,
                'firstName' => $secretaryPayload['firstName'],
                'email' => $secretaryPayload['email'],
            ],
            true,
        );
        self::assertSame(
            [$this->fixture->providerId],
            $this->fixture->secretaryWriteState($secretaryPayload['email'])['providers'],
        );
        $secretaryBefore = $this->fixture->userSettingsRow($secretaryId);
        unset($secretaryPayload['settings']['password']);
        $secretaryPayload['firstName'] = 'Updated Secretary';
        $secretaryPayload['providers'] = [];
        $updated = $this->success(
            $admin->requestJsonApp('PUT', 'api/v1/secretaries/' . $secretaryId, $secretaryPayload),
        );
        $this->assertStaff(
            $updated,
            [
                'id' => $secretaryId,
                'firstName' => $secretaryPayload['firstName'],
                'email' => $secretaryPayload['email'],
            ],
            true,
        );
        $secretaryAfter = $this->fixture->userSettingsRow($secretaryId);
        self::assertSame($secretaryBefore['password'], $secretaryAfter['password']);
        self::assertSame($secretaryBefore['salt'], $secretaryAfter['salt']);
        self::assertSame([], $updated['providers']);
        self::assertSame('Updated Secretary', $this->fixture->row('users', $secretaryId)['first_name']);
        self::assertSame([], $this->fixture->secretaryWriteState($secretaryPayload['email'])['providers']);
        $this->assertNoSyntheticSecrets($adminCreate, $adminUpdate, $secretaryCreate, $updated);
    }

    #[DataProvider('validWriteAuthenticationCases')]
    public function testAuthorizedSecretaryDeleteRemovesOnlyOwnedRowsAndIsIdempotent(string $authentication): void
    {
        $f = $this->fixture;
        $client = $this->writeClient($authentication);
        $sentinelPayload = $f->secretaryWritePayload('delete-sentinel', [$f->providerId]);
        $sentinel = $this->success($client->requestJsonApp('POST', 'api/v1/secretaries', $sentinelPayload), 201);
        $sentinelId = (int) ($sentinel['id'] ?? 0);
        self::assertGreaterThan(0, $sentinelId);
        self::assertSame([$f->providerId], $f->secretaryWriteState($sentinelPayload['email'])['providers']);
        $before = $f->secretaryDeleteSnapshot();

        $payload = $f->secretaryWritePayload('delete-target', [$f->providerId]);
        $created = $this->success($client->requestJsonApp('POST', 'api/v1/secretaries', $payload), 201);
        $state = $f->secretaryWriteState($payload['email']);
        $id = (int) ($state['user']['id'] ?? 0);
        self::assertGreaterThan(0, $id, 'Secretary fixture must have a positive ID.');
        self::assertSame($id, (int) ($created['id'] ?? 0));
        self::assertNotSame([], $state['settings'], 'Secretary fixture must have settings before deletion.');
        self::assertSame([$f->providerId], $state['providers'], 'Secretary fixture must have its provider link.');

        $deleted = $client->requestApp('DELETE', 'api/v1/secretaries/' . $id);
        self::assertSame(204, $deleted->statusCode);
        self::assertSame(['user' => [], 'settings' => [], 'providers' => []], $f->secretaryDeleteState($id));
        self::assertSame(
            $before,
            $f->secretaryDeleteSnapshot(),
            'Unrelated sentinel rows and links must remain unchanged.',
        );

        $repeat = $client->requestApp('DELETE', 'api/v1/secretaries/' . $id);
        self::assertSame(404, $repeat->statusCode);
        self::assertSame($before, $f->secretaryDeleteSnapshot(), 'Repeated deletion must not mutate state.');

        $f->cleanup();
        $f->cleanup();
        self::assertSame([], $f->secretaryWriteState($sentinelPayload['email']));
        self::assertSame([], $f->secretaryWriteState($payload['email']));
    }

    public function testSecretaryDeleteRejectsMissingAuthWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->writeClient('basic');
        $payload = $f->secretaryWritePayload('delete-no-auth', [$f->providerId]);
        $created = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $payload), 201);
        $id = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        self::assertNotSame([], $f->secretaryWriteState($payload['email']));
        $before = $f->secretaryDeleteSnapshot();

        $response = $this->server->client()->requestApp('DELETE', 'api/v1/secretaries/' . $id);
        self::assertSame(401, $response->statusCode);
        self::assertNotEmpty((string) $response->header('www-authenticate'));
        self::assertSame($before, $f->secretaryDeleteSnapshot());
    }

    private function writeClient(string $authentication): GateHttpClient
    {
        return $authentication === 'bearer'
            ? $this->bearerClient($this->credentials['token'])
            : $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
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

    private function success(GateHttpResponse $response, int $status = 200): array
    {
        self::assertSame($status, $response->statusCode);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('exception', $data);
        $this->assertNoSyntheticSecrets($data);
        return $data;
    }

    private function assertStaff(array $row, array $identity, bool $secretary): void
    {
        $keys = ['id', 'firstName', 'lastName', 'email', 'mobile', 'phone', 'address', 'city', 'state', 'zip', 'notes'];
        if ($secretary) {
            $keys[] = 'providers';
        }
        $keys = array_merge($keys, ['timezone', 'language', 'ldapDn', 'settings']);
        self::assertSame([], array_diff($keys, array_keys($row)));
        self::assertSame([], array_diff(array_keys($row), $keys));
        self::assertSame($identity['id'], (int) $row['id']);
        self::assertSame($identity['firstName'], $row['firstName']);
        self::assertSame($identity['email'], $row['email']);
        $settings = $row['settings'];
        self::assertIsArray($settings);
        self::assertSame([], array_diff(['username', 'notifications', 'calendarView'], array_keys($settings)));
        self::assertSame([], array_diff(array_keys($settings), ['username', 'notifications', 'calendarView']));
        self::assertArrayNotHasKey('password', $row);
        self::assertArrayNotHasKey('salt', $row);
        self::assertArrayNotHasKey('password', $settings);
        self::assertArrayNotHasKey('salt', $settings);
    }

    private function assertNoSyntheticSecrets(mixed ...$responses): void
    {
        $json = json_encode($responses, JSON_THROW_ON_ERROR);
        foreach ($this->fixture->seededStaffSecretValues() as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }
}

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
        $ownedSetting = $this->fixture->ownedSetting('api-read', '0');
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
            $settings = $this->success($client->get('api/v1/settings', ['q' => $ownedSetting['name']]));
            self::assertContains(['name' => $ownedSetting['name'], 'value' => '0'], $settings);
            self::assertSame(
                ['name' => $ownedSetting['name'], 'value' => '0'],
                $this->success($client->get('api/v1/settings/' . $ownedSetting['name'])),
            );
            $this->assertNoSyntheticSecrets($admins, $secretaries, $settings);
        }
    }

    public function testValidProviderBasicCannotReadAdminSecretaryOrSettingsRoutes(): void
    {
        $provider = $this->fixture->row('users', $this->fixture->providerId);
        $providerSettings = $this->fixture->userSettingsRow($this->fixture->providerId);
        $providerRole = get_instance()
            ->db->get_where('roles', ['id' => (int) $provider['id_roles']])
            ->row_array();
        self::assertSame('provider', $providerRole['slug'] ?? null);
        self::assertSame($this->credentials['provider_username'], $providerSettings['username'] ?? null);
        self::assertSame(
            hash_password((string) $providerSettings['salt'], $this->credentials['password']),
            $providerSettings['password'] ?? null,
        );

        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $secretaryPayload = $this->fixture->secretaryWritePayload('provider-read-denial', [$this->fixture->providerId]);
        $secretary = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $secretaryPayload), 201);
        $secretaryId = (int) $secretary['id'];
        $this->fixture->seedStaffIntegrationSecrets($secretaryId);

        $providerClient = $this->basicClient($this->credentials['provider_username'], $this->credentials['password']);
        foreach (
            [
                'api/v1/admins',
                'api/v1/admins/' . $this->fixture->actorId,
                'api/v1/secretaries',
                'api/v1/secretaries/' . $secretaryId,
                'api/v1/settings',
                'api/v1/settings/api_token',
            ]
            as $path
        ) {
            $response = $providerClient->get($path);
            self::assertSame(401, $response->statusCode, $path . ' must reject Provider Basic credentials.');
            self::assertNotEmpty(trim((string) $response->header('www-authenticate')));
            self::assertStringNotContainsString($this->fixture->run, $response->body);
            self::assertStringNotContainsString($this->credentials['token'], $response->body);
            $this->assertNoSyntheticSecrets($response->body);
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

    public static function invalidWriteAuthenticationCases(): array
    {
        return [
            'no-credentials' => ['no-credentials'],
            'wrong-admin-password' => ['wrong-admin-password'],
            'missing-admin-username' => ['missing-admin-username'],
            'invalid-bearer-token' => ['invalid-bearer-token'],
            'provider-basic' => ['provider-basic'],
        ];
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

    public function testSettingsUpdateControllerAliasRejectsGetAndPostWithoutMutation(): void
    {
        $setting = $this->fixture->ownedSetting('alias-probe', 'before');
        $before = $this->fixture->settingRow((int) $setting['id']);
        foreach ([$this->writeClient('basic'), $this->writeClient('bearer')] as $client) {
            $get = $client->get('api/v1/settings_api_v1/update/' . $setting['name'], ['value' => 'get-value']);
            self::assertSame(405, $get->statusCode, $get->body);
            self::assertSame('PUT', $get->header('Allow'));
            self::assertSame($before, $this->fixture->settingRow((int) $setting['id']));

            $post = $client->requestJsonApp('POST', 'api/v1/settings_api_v1/update/' . $setting['name'], [
                'value' => 'post-value',
            ]);
            self::assertSame(405, $post->statusCode, $post->body);
            self::assertSame('PUT', $post->header('Allow'));
            self::assertSame($before, $this->fixture->settingRow((int) $setting['id']));
        }

        $canonicalPost = $this->writeClient('basic')->requestJsonApp('POST', 'api/v1/settings/' . $setting['name'], [
            'value' => 'canonical-post-value',
        ]);
        self::assertSame(404, $canonicalPost->statusCode, $canonicalPost->body);
        self::assertSame($before, $this->fixture->settingRow((int) $setting['id']));
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

    public function testAdminPutRejectsMismatchedOrInvalidBodyIdWithoutPartialChange(): void
    {
        $f = $this->fixture;
        $admin = $this->writeClient('basic');

        $adminPayloadA = $f->adminWritePayload('url-id-a');
        $adminA = $this->success($admin->requestJsonApp('POST', 'api/v1/admins', $adminPayloadA), 201);
        $adminIdA = (int) $adminA['id'];
        $adminPayloadB = $f->adminWritePayload('url-id-b');
        $adminB = $this->success($admin->requestJsonApp('POST', 'api/v1/admins', $adminPayloadB), 201);
        $adminIdB = (int) $adminB['id'];

        foreach ([$adminIdB, 0, null, '', 'not-an-id', 1.5] as $bodyId) {
            $update = $adminPayloadA;
            $update['id'] = $bodyId;
            // Preserve B's unique identity fields so the baseline can reach save(B)
            // and the assertion observes the target boundary instead of a duplicate error.
            $update['email'] = $adminPayloadB['email'];
            $update['settings']['username'] = $adminPayloadB['settings']['username'];
            $update['firstName'] = $f->run . '_must-not-change';
            $beforeA = $f->adminDeleteState($adminIdA);
            $beforeB = $f->adminDeleteState($adminIdB);

            $response = $admin->requestJsonApp('PUT', 'api/v1/admins/' . $adminIdA, $update);

            self::assertSame(
                400,
                $response->statusCode,
                'Admin PUT must reject body ID ' . var_export($bodyId, true) . ': ' . $response->body,
            );
            self::assertSame($beforeA, $f->adminDeleteState($adminIdA));
            self::assertSame($beforeB, $f->adminDeleteState($adminIdB));
        }

        $matchingAdmin = $adminPayloadA;
        $matchingAdmin['id'] = $adminIdA;
        $matchingAdmin['firstName'] = $f->run . '_matching-url-id';
        $response = $admin->requestJsonApp('PUT', 'api/v1/admins/' . $adminIdA, $matchingAdmin);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($adminIdA, (int) ($this->success($response, 200)['id'] ?? 0));
        self::assertSame($matchingAdmin['firstName'], $f->adminDeleteState($adminIdA)['user']['first_name']);
        self::assertSame($adminIdB, (int) ($f->adminDeleteState($adminIdB)['user']['id'] ?? 0));
    }

    public function testSecretaryPutRejectsMismatchedOrInvalidBodyIdWithoutPartialChange(): void
    {
        $f = $this->fixture;
        $admin = $this->writeClient('basic');
        $secretaryPayloadA = $f->secretaryWritePayload('url-id-a', [$f->providerId]);
        $secretaryA = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $secretaryPayloadA), 201);
        $secretaryIdA = (int) $secretaryA['id'];
        $secretaryPayloadB = $f->secretaryWritePayload('url-id-b', [$f->providerId]);
        $secretaryB = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $secretaryPayloadB), 201);
        $secretaryIdB = (int) $secretaryB['id'];

        foreach ([$secretaryIdB, 0, null, '', 'not-an-id', 1.5] as $bodyId) {
            $update = $secretaryPayloadA;
            $update['id'] = $bodyId;
            // Preserve B's unique identity fields so a redirected save(B) remains observable.
            $update['email'] = $secretaryPayloadB['email'];
            $update['settings']['username'] = $secretaryPayloadB['settings']['username'];
            $update['firstName'] = $f->run . '_must-not-change';
            $update['providers'] = [];
            $beforeA = $f->secretaryDeleteState($secretaryIdA);
            $beforeB = $f->secretaryDeleteState($secretaryIdB);

            $response = $admin->requestJsonApp('PUT', 'api/v1/secretaries/' . $secretaryIdA, $update);

            self::assertSame(
                400,
                $response->statusCode,
                'Secretary PUT must reject body ID ' . var_export($bodyId, true) . ': ' . $response->body,
            );
            self::assertSame($beforeA, $f->secretaryDeleteState($secretaryIdA));
            self::assertSame($beforeB, $f->secretaryDeleteState($secretaryIdB));
        }

        $matchingSecretary = $secretaryPayloadA;
        $matchingSecretary['id'] = $secretaryIdA;
        $matchingSecretary['firstName'] = $f->run . '_matching-url-id';
        $response = $admin->requestJsonApp('PUT', 'api/v1/secretaries/' . $secretaryIdA, $matchingSecretary);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($secretaryIdA, (int) ($this->success($response, 200)['id'] ?? 0));
        self::assertSame(
            $matchingSecretary['firstName'],
            $f->secretaryDeleteState($secretaryIdA)['user']['first_name'],
        );
        self::assertSame($secretaryIdB, (int) ($f->secretaryDeleteState($secretaryIdB)['user']['id'] ?? 0));
    }

    public function testAdminUpdateControllerAliasRejectsGetWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->writeClient('basic');
        $payloadA = $f->adminWritePayload('alias-a');
        $createdA = $this->success($admin->requestJsonApp('POST', 'api/v1/admins', $payloadA), 201);
        $idA = (int) $createdA['id'];
        $payloadB = $f->adminWritePayload('alias-b');
        $createdB = $this->success($admin->requestJsonApp('POST', 'api/v1/admins', $payloadB), 201);
        $idB = (int) $createdB['id'];
        $beforeA = $f->adminDeleteState($idA);
        $beforeB = $f->adminDeleteState($idB);

        $response = $admin->get('api/v1/admins_api_v1/update/' . $idA);

        self::assertSame(405, $response->statusCode, $response->body);
        self::assertSame('PUT', $response->header('Allow'));
        self::assertSame($beforeA, $f->adminDeleteState($idA));
        self::assertSame($beforeB, $f->adminDeleteState($idB));
    }

    public function testSecretaryUpdateControllerAliasRejectsGetWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->writeClient('basic');
        $payloadA = $f->secretaryWritePayload('alias-a', [$f->providerId]);
        $createdA = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $payloadA), 201);
        $idA = (int) $createdA['id'];
        $payloadB = $f->secretaryWritePayload('alias-b', [$f->providerId]);
        $createdB = $this->success($admin->requestJsonApp('POST', 'api/v1/secretaries', $payloadB), 201);
        $idB = (int) $createdB['id'];
        $beforeA = $f->secretaryDeleteState($idA);
        $beforeB = $f->secretaryDeleteState($idB);

        $response = $admin->get('api/v1/secretaries_api_v1/update/' . $idA);

        self::assertSame(405, $response->statusCode, $response->body);
        self::assertSame('PUT', $response->header('Allow'));
        self::assertSame($beforeA, $f->secretaryDeleteState($idA));
        self::assertSame($beforeB, $f->secretaryDeleteState($idB));
    }

    #[DataProvider('invalidWriteAuthenticationCases')]
    public function testStaffAndSettingsWritesRejectInvalidAuthenticationWithoutMutation(string $case): void
    {
        $f = $this->fixture;
        $client = $this->invalidWriteClient($case);

        $adminPostPayload = $f->adminWritePayload('deny-' . $case);
        $before = $f->adminDeleteSnapshot();
        $response = $client->requestJsonApp('POST', 'api/v1/admins', $adminPostPayload);
        $this->assertRejectedWrite($response, $case);
        self::assertSame($before, $f->adminDeleteSnapshot(), $case . ' Admin POST must not mutate state.');
        self::assertSame([], $f->adminWriteState($adminPostPayload['email']));

        $adminPutPayload = $f->adminWritePayload('put-' . $case);
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $adminCreated = $this->success($admin->requestJsonApp('POST', 'api/v1/admins', $adminPutPayload), 201);
        $adminId = (int) $adminCreated['id'];
        $adminState = $f->adminWriteState($adminPutPayload['email']);
        self::assertGreaterThan(0, $adminId);
        self::assertSame($adminId, (int) ($adminState['user']['id'] ?? 0));
        self::assertNotSame([], $adminState['settings']);
        $adminBefore = $f->adminDeleteSnapshot();
        $adminPutPayload['firstName'] = 'Denied Admin Update';
        $response = $client->requestJsonApp('PUT', 'api/v1/admins/' . $adminId, $adminPutPayload);
        $this->assertRejectedWrite($response, $case);
        self::assertSame($adminBefore, $f->adminDeleteSnapshot(), $case . ' Admin PUT must not mutate state.');

        $secretaryPostPayload = $f->secretaryWritePayload('deny-' . $case, [$f->providerId]);
        $before = $f->adminDeleteSnapshot();
        $response = $client->requestJsonApp('POST', 'api/v1/secretaries', $secretaryPostPayload);
        $this->assertRejectedWrite($response, $case);
        self::assertSame($before, $f->adminDeleteSnapshot(), $case . ' Secretary POST must not mutate state.');
        self::assertSame([], $f->secretaryWriteState($secretaryPostPayload['email']));

        $secretaryPutPayload = $f->secretaryWritePayload('put-' . $case, [$f->providerId]);
        $secretaryCreated = $this->success(
            $admin->requestJsonApp('POST', 'api/v1/secretaries', $secretaryPutPayload),
            201,
        );
        $secretaryId = (int) $secretaryCreated['id'];
        $secretaryState = $f->secretaryWriteState($secretaryPutPayload['email']);
        self::assertGreaterThan(0, $secretaryId);
        self::assertSame($secretaryId, (int) ($secretaryState['user']['id'] ?? 0));
        self::assertNotSame([], $secretaryState['settings']);
        self::assertSame([$f->providerId], $secretaryState['providers']);
        $secretaryBefore = $f->secretaryDeleteSnapshot();
        $secretaryPutPayload['firstName'] = 'Denied Secretary Update';
        $secretaryPutPayload['providers'] = [];
        $response = $client->requestJsonApp('PUT', 'api/v1/secretaries/' . $secretaryId, $secretaryPutPayload);
        $this->assertRejectedWrite($response, $case);
        self::assertSame(
            $secretaryBefore,
            $f->secretaryDeleteSnapshot(),
            $case . ' Secretary PUT must not mutate state.',
        );

        $response = $client->requestApp('DELETE', 'api/v1/secretaries/' . $secretaryId);
        $this->assertRejectedWrite($response, $case);
        self::assertSame(
            $secretaryBefore,
            $f->secretaryDeleteSnapshot(),
            $case . ' Secretary DELETE must not mutate state.',
        );

        $setting = $f->ownedSetting('deny-' . $case, 'before');
        $settingBefore = $f->settingRow((int) $setting['id']);
        $response = $client->requestJsonApp('PUT', 'api/v1/settings/' . $setting['name'], [
            'value' => $f->run . '_denied',
        ]);
        $this->assertRejectedWrite($response, $case);
        self::assertSame($settingBefore, $f->settingRow((int) $setting['id']));

        $f->cleanup();
        $f->cleanup();
        self::assertSame([], $f->adminWriteState($adminPutPayload['email']));
        self::assertSame([], $f->secretaryWriteState($secretaryPutPayload['email']));
        self::assertSame([], $f->settingRow((int) $setting['id']));
    }

    #[DataProvider('validWriteAuthenticationCases')]
    public function testAuthorizedAdminDeleteRemovesOnlyOwnedRowsAndIsIdempotent(string $authentication): void
    {
        $f = $this->fixture;
        $client = $this->writeClient($authentication);
        $before = $f->adminDeleteSnapshot();
        $payload = $f->adminWritePayload('delete-' . $authentication);
        $created = $this->success(
            $this->basicClient($this->credentials['admin_username'], $this->credentials['password'])->requestJsonApp(
                'POST',
                'api/v1/admins',
                $payload,
            ),
            201,
        );
        $state = $f->adminWriteState($payload['email']);
        $id = (int) ($state['user']['id'] ?? 0);
        self::assertGreaterThan(0, $id, $authentication . ' Admin fixture must have a positive ID.');
        self::assertSame($id, (int) ($created['id'] ?? 0));
        self::assertNotSame([], $state['settings'], 'Admin fixture must have settings before deletion.');

        $deleted = $client->requestApp('DELETE', 'api/v1/admins/' . $id);
        self::assertSame(204, $deleted->statusCode, $authentication . ' authorized delete must return 204.');
        self::assertSame(['user' => [], 'settings' => []], $f->adminDeleteState($id));
        self::assertSame($before, $f->adminDeleteSnapshot(), 'Unrelated staff state must remain unchanged.');

        $repeat = $client->requestApp('DELETE', 'api/v1/admins/' . $id);
        self::assertSame(404, $repeat->statusCode, $authentication . ' repeated delete must return 404.');
        self::assertSame($before, $f->adminDeleteSnapshot(), 'Repeated deletion must not mutate state.');

        $f->cleanup();
        $f->cleanup();
        self::assertSame([], $f->adminWriteState($payload['email']));
    }

    public static function invalidAdminDeleteAuthenticationCases(): array
    {
        return [
            'no-credentials' => ['no-credentials'],
            'wrong-admin-password' => ['wrong-admin-password'],
            'missing-admin-username' => ['missing-admin-username'],
            'invalid-bearer-token' => ['invalid-bearer-token'],
            'provider-basic' => ['provider-basic'],
        ];
    }

    #[DataProvider('invalidAdminDeleteAuthenticationCases')]
    public function testAdminDeleteRejectsInvalidAuthenticationWithoutMutation(string $case): void
    {
        $f = $this->fixture;
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $payload = $f->adminWritePayload(
            'deny-' .
                match ($case) {
                    'wrong-admin-password' => 'wrongpw',
                    'missing-admin-username' => 'missinguser',
                    'invalid-bearer-token' => 'bearer',
                    'no-credentials' => 'noauth',
                    'provider-basic' => 'provider',
                },
        );
        $created = $this->success($admin->requestJsonApp('POST', 'api/v1/admins', $payload), 201);
        $id = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        self::assertNotSame([], $f->adminWriteState($payload['email']));
        $before = $f->adminDeleteSnapshot();
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
            'provider-basic' => $this->basicClient(
                $this->credentials['provider_username'],
                $this->credentials['password'],
            ),
        };

        $response = $client->requestApp('DELETE', 'api/v1/admins/' . $id);
        self::assertSame(401, $response->statusCode, $case . ' must be rejected.');
        self::assertNotEmpty((string) $response->header('www-authenticate'));
        self::assertSame($before, $f->adminDeleteSnapshot(), $case . ' must not mutate state.');
    }

    public function testRealDatabaseLastAdminGuardRejectsActorAndOuterRollbackRestoresSeedRoles(): void
    {
        $db = get_instance()->db;
        $adminRole = $db->get_where('roles', ['slug' => 'admin'])->row_array();
        $providerRole = $db->get_where('roles', ['slug' => 'provider'])->row_array();
        self::assertNotEmpty($adminRole);
        self::assertNotEmpty($providerRole);
        $adminRoleId = (int) $adminRole['id'];
        $providerRoleId = (int) $providerRole['id'];
        $adminRows = $db
            ->order_by('id')
            ->get_where('users', ['id_roles' => $adminRoleId])
            ->result_array();
        $otherIds = array_values(
            array_diff(array_map(static fn(array $row): int => (int) $row['id'], $adminRows), [
                $this->fixture->actorId,
            ]),
        );
        self::assertNotEmpty($otherIds, 'The isolated install seed must provide another admin.');
        $originalRows = [];
        foreach ($otherIds as $id) {
            $row = $db->get_where('users', ['id' => $id])->row_array();
            self::assertNotEmpty($row);
            $originalRows[$id] = $row;
        }
        $actorBefore = $db->get_where('users', ['id' => $this->fixture->actorId])->row_array();

        self::assertTrue($db->trans_begin());
        try {
            self::assertTrue($db->where_in('id', $otherIds)->update('users', ['id_roles' => $providerRoleId]));
            $currentAdmins = $db
                ->order_by('id')
                ->get_where('users', ['id_roles' => $adminRoleId])
                ->result_array();
            self::assertSame(
                [$this->fixture->actorId],
                array_map(static fn(array $row): int => (int) $row['id'], $currentAdmins),
            );

            get_instance()->load->model('admins_model');
            try {
                get_instance()->admins_model->delete($this->fixture->actorId);
                self::fail('Expected the real database last-admin guard to reject deletion.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('requires at least one admin user', $exception->getMessage());
                self::assertTrue($db->trans_active(), 'The model must retain the outer transaction.');
                self::assertSame(1, $db->get_where('users', ['id' => $this->fixture->actorId])->num_rows());
            }
        } finally {
            if ($db->trans_active()) {
                self::assertTrue($db->trans_rollback());
            }
        }
        self::assertSame($actorBefore, $db->get_where('users', ['id' => $this->fixture->actorId])->row_array());
        foreach ($originalRows as $id => $row) {
            self::assertSame($row, $db->get_where('users', ['id' => $id])->row_array());
        }
    }

    public function testConcurrentAdminDeletesWaitAndRejectTheSecondLastAdminDelete(): void
    {
        $db = get_instance()->db;
        $server = $this->server;
        $fixture = $this->fixture;
        $adminRole = $db->get_where('roles', ['slug' => 'admin'])->row_array();
        $providerRole = $db->get_where('roles', ['slug' => 'provider'])->row_array();
        self::assertNotEmpty($adminRole);
        self::assertNotEmpty($providerRole);
        $adminRoleId = (int) $adminRole['id'];
        $providerRoleId = (int) $providerRole['id'];
        $seedAdminRows = $db
            ->order_by('id')
            ->get_where('users', ['id_roles' => $adminRoleId])
            ->result_array();
        self::assertNotEmpty($seedAdminRows, 'The isolated install must provide admin seed rows.');

        $targetA = 0;
        $targetB = 0;
        $multi = null;
        $handle = null;
        $observer = null;
        $outerTransaction = false;

        try {
            $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
            $createdA = $this->success(
                $admin->requestJsonApp('POST', 'api/v1/admins', $fixture->adminWritePayload('race-a')),
                201,
            );
            $createdB = $this->success(
                $admin->requestJsonApp('POST', 'api/v1/admins', $fixture->adminWritePayload('race-b')),
                201,
            );
            $targetA = (int) ($createdA['id'] ?? 0);
            $targetB = (int) ($createdB['id'] ?? 0);
            self::assertGreaterThan(0, $targetA);
            self::assertGreaterThan(0, $targetB);
            self::assertNotSame($targetA, $targetB);

            $beforeDelete = $fixture->adminDeleteSnapshot();
            $demoteIds = array_map(static fn(array $row): int => (int) $row['id'], $seedAdminRows);
            if ($demoteIds !== []) {
                self::assertTrue($db->where_in('id', $demoteIds)->update('users', ['id_roles' => $providerRoleId]));
            }
            self::assertSame(
                [$targetA, $targetB],
                array_map(
                    static fn(array $row): int => (int) $row['id'],
                    $db
                        ->order_by('id')
                        ->get_where('users', ['id_roles' => $adminRoleId])
                        ->result_array(),
                ),
            );

            $targetBBefore = $fixture->adminDeleteState($targetB);
            self::assertNotEmpty($targetBBefore['user']);
            self::assertNotEmpty($targetBBefore['settings']);
            self::assertTrue($db->trans_begin());
            $outerTransaction = true;
            get_instance()->load->model('admins_model');
            get_instance()->admins_model->delete($targetA);
            self::assertTrue($db->trans_active(), 'The first delete must retain the outer transaction.');
            self::assertSame(['user' => [], 'settings' => []], $fixture->adminDeleteState($targetA));
            self::assertSame($targetBBefore, $fixture->adminDeleteState($targetB));

            $ownerConnectionId = mysqli_thread_id($db->conn_id);
            self::assertGreaterThan(0, $ownerConnectionId);
            // DefenseCycleFixtures has already enforced the exact fresh Docker
            // credentials/environment. Only this observer uses the disposable
            // Compose root account to read MySQL lock instrumentation; the
            // application connection and its grants remain unchanged.
            $observer = get_instance()->load->database(
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
            self::assertNotSame($ownerConnectionId, mysqli_thread_id($observer->conn_id));
            self::assertTrue($observer->query('SET SESSION TRANSACTION READ ONLY'));

            $multi = curl_multi_init();
            $handle = curl_init($server->baseUrl . '/index.php/api/v1/admins/' . $targetB);
            if ($multi === false || $handle === false) {
                throw new RuntimeException('Could not initialize the concurrent admin delete request.');
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Accept: */*', 'Authorization: Bearer ' . $this->credentials['token']],
            ]);
            self::assertSame(CURLM_OK, curl_multi_add_handle($multi, $handle));

            $baselineWaitKeys = array_map(
                static fn(array $wait): string => (string) ($wait['REQUESTING_THREAD_ID'] ?? '') .
                    ':' .
                    (string) ($wait['BLOCKING_THREAD_ID'] ?? ''),
                $this->adminDeleteLockWaitRows($observer, $ownerConnectionId),
            );
            $running = 0;
            $waitObserved = false;
            $deadline = microtime(true) + 8.0;
            do {
                do {
                    $multiResult = curl_multi_exec($multi, $running);
                } while ($multiResult === CURLM_CALL_MULTI_PERFORM);
                self::assertSame(CURLM_OK, $multiResult);
                $waitObserved = $this->adminDeleteLockWaitObserved(
                    $observer,
                    $ownerConnectionId,
                    $adminRoleId,
                    $db->dbprefix('users'),
                    $baselineWaitKeys,
                );
                if ($waitObserved || $running === 0) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (true);

            self::assertTrue($waitObserved, 'The second delete must wait on the first transaction before commit.');
            self::assertTrue($db->trans_commit());
            $outerTransaction = false;

            $responseDeadline = microtime(true) + 8.0;
            do {
                do {
                    $multiResult = curl_multi_exec($multi, $running);
                } while ($multiResult === CURLM_CALL_MULTI_PERFORM);
                self::assertSame(CURLM_OK, $multiResult);
                if ($running === 0) {
                    break;
                }
                if (microtime(true) >= $responseDeadline) {
                    break;
                }
                curl_multi_select($multi, 0.05);
            } while (true);
            self::assertSame(0, $running, 'The second delete HTTP request must drain after the first commits.');
            self::assertSame(CURLE_OK, curl_errno($handle), curl_error($handle));
            $responseStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $responseBody = (string) curl_multi_getcontent($handle);
            self::assertSame(500, $responseStatus);
            self::assertStringContainsString('requires at least one admin user', $responseBody);
            self::assertSame($targetBBefore, $fixture->adminDeleteState($targetB));
            self::assertSame(
                [$targetB],
                array_map(
                    static fn(array $row): int => (int) $row['id'],
                    $db
                        ->order_by('id')
                        ->get_where('users', ['id_roles' => $adminRoleId])
                        ->result_array(),
                ),
            );
        } finally {
            if ($outerTransaction && $db->trans_active()) {
                $db->trans_rollback();
            }
            $drained = true;
            if ($handle instanceof \CurlHandle && $multi instanceof \CurlMultiHandle) {
                $drained = $this->drainConcurrentAdminDelete($multi, $handle);
            }
            if (!$drained) {
                $server->close();
            }
            if (is_resource($handle) || $handle instanceof \CurlHandle) {
                if (is_resource($multi) || $multi instanceof \CurlMultiHandle) {
                    curl_multi_remove_handle($multi, $handle);
                }
                curl_close($handle);
            }
            if (is_resource($multi) || $multi instanceof \CurlMultiHandle) {
                curl_multi_close($multi);
            }
            if (is_object($observer) && isset($observer->conn_id)) {
                $observer->close();
            }
            foreach ($seedAdminRows as $row) {
                $id = (int) $row['id'];
                $db->update('users', ['id_roles' => $row['id_roles']], ['id' => $id]);
            }
            foreach ($seedAdminRows as $row) {
                self::assertSame($row, $db->get_where('users', ['id' => (int) $row['id']])->row_array());
            }
        }
        foreach (['users' => 'id', 'user_settings' => 'id_users'] as $table => $key) {
            $beforeDelete[$table] = array_values(
                array_filter($beforeDelete[$table], static fn(array $row): bool => (int) $row[$key] !== $targetA),
            );
        }
        self::assertSame(
            $beforeDelete,
            $fixture->adminDeleteSnapshot(),
            'Only target A and its settings may disappear; all seed and sentinel data must be restored.',
        );
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

    private function invalidWriteClient(string $case): GateHttpClient
    {
        return match ($case) {
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
            'provider-basic' => $this->basicClient(
                $this->credentials['provider_username'],
                $this->credentials['password'],
            ),
            default => throw new InvalidArgumentException('Unknown invalid write authentication case.'),
        };
    }

    private function assertRejectedWrite(GateHttpResponse $response, string $case): void
    {
        self::assertSame(401, $response->statusCode, $case . ' write must be rejected.');
        self::assertNotEmpty((string) $response->header('www-authenticate'));
        self::assertStringNotContainsString($this->fixture->run, $response->body);
        $this->assertNoSyntheticSecrets($response->body);
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

    private function adminDeleteLockWaitObserved(
        object $observer,
        int $ownerConnectionId,
        int $adminRoleId,
        string $usersTable,
        array $baselineWaitKeys,
    ): bool {
        $expectedSql = sprintf(
            'SELECT `id` FROM `%s` WHERE `id_roles` = %d ORDER BY `id` ASC FOR UPDATE',
            $usersTable,
            $adminRoleId,
        );
        $normalize = static fn(string $value): string => (string) preg_replace('/\s+/', ' ', strtoupper(trim($value)));
        foreach ($this->adminDeleteLockWaitRows($observer, $ownerConnectionId) as $wait) {
            $waitKey =
                (string) ($wait['REQUESTING_THREAD_ID'] ?? '') . ':' . (string) ($wait['BLOCKING_THREAD_ID'] ?? '');
            if (in_array($waitKey, $baselineWaitKeys, true)) {
                continue;
            }
            if (
                ($wait['REQUESTING_SCHEMA'] ?? null) === \Config::DB_NAME &&
                ($wait['REQUESTING_TABLE'] ?? null) === $usersTable &&
                $normalize((string) ($wait['REQUESTING_SQL'] ?? '')) === $normalize($expectedSql)
            ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string, mixed>> */
    private function adminDeleteLockWaitRows(object $observer, int $ownerConnectionId): array
    {
        $result = $observer->query(
            'SELECT ' .
                'waits.REQUESTING_THREAD_ID, waits.BLOCKING_THREAD_ID, ' .
                'requesting_lock.OBJECT_SCHEMA AS REQUESTING_SCHEMA, ' .
                'requesting_lock.OBJECT_NAME AS REQUESTING_TABLE, ' .
                'COALESCE(requesting_statement.SQL_TEXT, requesting_thread.PROCESSLIST_INFO) AS REQUESTING_SQL ' .
                'FROM performance_schema.data_lock_waits waits ' .
                'JOIN performance_schema.data_locks requesting_lock ' .
                'ON requesting_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID ' .
                'JOIN performance_schema.data_locks blocking_lock ' .
                'ON blocking_lock.ENGINE_LOCK_ID = waits.BLOCKING_ENGINE_LOCK_ID ' .
                'JOIN performance_schema.threads requesting_thread ' .
                'ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID ' .
                'JOIN performance_schema.threads blocking_thread ' .
                'ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID ' .
                'LEFT JOIN performance_schema.events_statements_current requesting_statement ' .
                'ON requesting_statement.THREAD_ID = requesting_thread.THREAD_ID ' .
                'WHERE blocking_thread.PROCESSLIST_ID = ' .
                (int) $ownerConnectionId,
        );
        if ($result === false) {
            throw new RuntimeException('The independent lock observer could not read performance_schema.');
        }

        return $result->result_array();
    }

    private function drainConcurrentAdminDelete(\CurlMultiHandle $multi, \CurlHandle $handle): bool
    {
        $running = 0;
        $deadline = microtime(true) + 8.0;
        do {
            do {
                $multiResult = curl_multi_exec($multi, $running);
            } while ($multiResult === CURLM_CALL_MULTI_PERFORM);
            if ($multiResult !== CURLM_OK) {
                return false;
            }
            if ($running === 0) {
                return curl_errno($handle) === CURLE_OK;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            curl_multi_select($multi, 0.05);
        } while (true);
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
        self::assertSame([], array_diff(['username', 'calendarView'], array_keys($settings)));
        self::assertSame([], array_diff(array_keys($settings), ['username', 'calendarView']));
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

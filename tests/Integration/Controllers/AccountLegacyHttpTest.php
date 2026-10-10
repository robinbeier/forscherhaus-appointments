<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;
use Tests\Integration\Support\SessionFileReader;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';
require_once dirname(__DIR__) . '/Support/SessionFileReader.php';

/** Bounded HTTP/DB coverage for the classic Account controller surface. */
final class AccountLegacyHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private array $actorBefore = [];
    private array $actorSettingsBefore = [];
    private array $actorRoleBefore = [];
    private array $customerBefore = [];
    private array $customerSettingsBefore = [];

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
            $this->actorBefore = $this->fixture->row('users', $this->fixture->actorId);
            $this->actorSettingsBefore = $this->fixture->userSettingsRow($this->fixture->actorId);
            $this->actorRoleBefore = $this->actorBefore;
            $this->customerBefore = $this->fixture->row('users', $this->fixture->customerId);
            $this->customerSettingsBefore = $this->fixture->userSettingsRow($this->fixture->customerId);
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixture !== null) {
                $db = get_instance()->db;
                if ($this->actorRoleBefore !== []) {
                    $db->update('users', $this->actorBefore, [
                        'id' => $this->actorRoleBefore['id'],
                    ]);
                    $db->update('user_settings', $this->actorSettingsBefore, [
                        'id_users' => $this->actorSettingsBefore['id_users'],
                    ]);
                }
                self::assertSame(
                    $this->actorBefore,
                    $this->fixture->row('users', $this->fixture->actorId),
                    'Synthetic actor row must be restored after the test.',
                );
                self::assertSame(
                    $this->actorSettingsBefore,
                    $this->fixture->userSettingsRow($this->fixture->actorId),
                    'Synthetic actor settings must be restored after the test.',
                );
                self::assertSame(
                    $this->customerBefore,
                    $this->fixture->row('users', $this->fixture->customerId),
                    'Synthetic customer row must remain unchanged.',
                );
                self::assertSame(
                    $this->customerSettingsBefore,
                    $this->fixture->userSettingsRow($this->fixture->customerId),
                    'Synthetic customer settings must remain unchanged.',
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

    public function testAdminCanReadCanonicalAndDirectAccountAndSaveOnlyItsOwnRow(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);

        foreach (['account', 'account/index'] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
            self::assertStringContainsString('id="account-page"', $response->body, $path);
            self::assertStringContainsString('id="save-settings"', $response->body, $path);
        }

        $account = $this->accountPayload($this->actorBefore, $this->actorSettingsBefore);
        $account['id'] = (string) $fixture->customerId;
        $account['first_name'] = $fixture->run . '_updated_actor';
        $saved = $admin->post('account/save', ['account' => $account]);

        self::assertSame(200, $saved->statusCode, $saved->body);
        self::assertSame(
            $fixture->run . '_updated_actor',
            $fixture->row('users', $fixture->actorId)['first_name'] ?? null,
            'The authenticated actor may save its own account.',
        );
        self::assertSame(
            $this->customerBefore,
            $fixture->row('users', $fixture->customerId),
            'A caller-supplied account ID must not redirect the save to another account.',
        );
        self::assertSame(
            $this->customerSettingsBefore,
            $fixture->userSettingsRow($fixture->customerId),
            'A caller-supplied account ID must not mutate another account settings row.',
        );
    }

    public function testAccountMethodsAndCsrfBoundariesRejectWritesWithoutChangingRows(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        $account = $this->accountPayload($this->actorBefore, $this->actorSettingsBefore);
        $account['first_name'] = $fixture->run . '_wrong_method';
        $account['settings']['password'] = $fixture->run . '_attempted_password';

        foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            $response = $admin->requestApp($method, 'account/save', ['account' => $account], null, $method === 'POST');
            self::assertSame(405, $response->statusCode, $method . ' account/save must be rejected.');
            self::assertSame('POST', $response->header('allow'));
            self::assertSame($this->actorBefore, $fixture->row('users', $fixture->actorId));
            self::assertSame($this->actorSettingsBefore, $fixture->userSettingsRow($fixture->actorId));
        }

        $missingCsrf = $admin->post('account/save', ['account' => $account], withCsrfToken: false);
        self::assertSame(403, $missingCsrf->statusCode, $missingCsrf->body);
        self::assertSame($this->actorBefore, $fixture->row('users', $fixture->actorId));
        self::assertSame($this->actorSettingsBefore, $fixture->userSettingsRow($fixture->actorId));

        $postIndex = $admin->post('account/index', [], withCsrfToken: true);
        self::assertSame(405, $postIndex->statusCode, $postIndex->body);
        self::assertSame('GET', $postIndex->header('allow'));
    }

    public function testStoredCustomerRoleRevokesExistingAdminSessionAndPreservesRows(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('account')->statusCode);
        $destinationBefore = $this->sessionDestination($admin);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $customerRole['id']],
                    [
                        'id' => $fixture->actorId,
                    ],
                ),
            );
            $demotedActor = $fixture->row('users', $fixture->actorId);

            $observed = [];
            $leaked = [];
            foreach (['account', 'account/index'] as $path) {
                $response = $admin->get($path);
                $observed[$path] = $response->statusCode;
                $leaked[$path] = str_contains($response->body, $fixture->run);
            }
            self::assertSame(['account' => 403, 'account/index' => 403], $observed);
            self::assertSame(['account' => false, 'account/index' => false], $leaked);

            $account = $this->accountPayload($this->actorBefore, $this->actorSettingsBefore);
            $account['first_name'] = $fixture->run . '_denied';
            $account['settings']['password'] = $fixture->run . '_attempted_password';
            $deniedSave = $admin->post('account/save', ['account' => $account]);
            self::assertSame(500, $deniedSave->statusCode, $deniedSave->body);
            self::assertSame(
                ['success' => false, 'message' => 'You do not have the required permissions for this task.'],
                json_decode($deniedSave->body, true, 512, JSON_THROW_ON_ERROR),
            );
            self::assertSame($destinationBefore, $this->sessionDestination($admin));
            self::assertSame($demotedActor, $fixture->row('users', $fixture->actorId));
            self::assertSame($this->actorSettingsBefore, $fixture->userSettingsRow($fixture->actorId));
        } finally {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->actorRoleBefore['id_roles']],
                    [
                        'id' => $fixture->actorId,
                    ],
                ),
            );
        }
    }

    public function testPromotedCustomerSessionReceivesAccountSaveControlAfterStoredRolePromotion(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        $adminRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);
        self::assertNotEmpty($adminRole['id'] ?? null);

        self::assertTrue(
            get_instance()->db->update(
                'users',
                ['id_roles' => $customerRole['id']],
                [
                    'id' => $fixture->actorId,
                ],
            ),
        );
        try {
            $customerSession = $this->login($this->credentials['admin_username']);
            self::assertSame(403, $customerSession->get('account')->statusCode);
            self::assertSame(403, $customerSession->get('account/index')->statusCode);

            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $adminRole['id']],
                    [
                        'id' => $fixture->actorId,
                    ],
                ),
            );

            $responses = [];
            foreach (['account', 'account/index'] as $path) {
                $response = $customerSession->get($path);
                $responses[$path] = $response;
            }
            foreach ($responses as $path => $response) {
                self::assertSame(200, $response->statusCode, $path . ' ' . $response->body);
                self::assertStringContainsString('id="account-page"', $response->body, $path);
            }
            self::assertSame(
                ['account' => true, 'account/index' => true],
                array_map(
                    static fn(GateHttpResponse $response): bool => str_contains($response->body, 'id="save-settings"'),
                    $responses,
                ),
                'A promoted session must receive the account save control on both routes.',
            );
        } finally {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    [
                        'id_roles' => $this->actorRoleBefore['id_roles'],
                    ],
                    ['id' => $fixture->actorId],
                ),
            );
        }
    }

    /** @return array<string,mixed> */
    private function accountPayload(array $user, array $settings): array
    {
        return [
            'id' => (string) $user['id'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'mobile_number' => $user['mobile_number'] ?? '',
            'phone_number' => $user['phone_number'] ?? '',
            'address' => $user['address'] ?? '',
            'city' => $user['city'] ?? '',
            'state' => $user['state'] ?? '',
            'zip_code' => $user['zip_code'] ?? '',
            'notes' => $user['notes'] ?? '',
            'language' => $user['language'],
            'timezone' => $user['timezone'],
            'settings' => [
                'username' => $settings['username'],
                'calendar_view' => $settings['calendar_view'] ?? 'default',
            ],
        ];
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
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $path = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($path);
        $contents = SessionFileReader::read($path);
        self::assertSame(1, preg_match('/dest_url\|s:\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;
use Tests\Integration\Support\SessionFileReader;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';
require_once dirname(__DIR__) . '/Support/SessionFileReader.php';

/** Bounded real-HTTP coverage for the legacy Blocked_periods read surface. */
final class BlockedPeriodsReadHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $periodId = 0;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->server = new DefenseCycleHttpServer();
            $this->seedPeriod();
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

    public function testAdminReadContractsAndWrongVerbs(): void
    {
        $admin = $this->login($this->credentials['admin_username']);
        $marker = $this->fixture?->run;
        self::assertIsString($marker);

        $index = $admin->get('blocked_periods');
        self::assertSame(200, $index->statusCode, $index->body);
        self::assertSame(200, $admin->get('blocked_periods/index')->statusCode);

        $search = $admin->post('blocked_periods/search', ['keyword' => $marker]);
        self::assertSame(200, $search->statusCode, $search->body);
        self::assertStringContainsString($marker, $search->body);
        $searchRows = json_decode($search->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($searchRows);
        self::assertCount(1, $searchRows);
        $searchFields = array_keys($searchRows[0]);
        sort($searchFields);
        self::assertSame(['end_datetime', 'id', 'name', 'notes', 'start_datetime'], $searchFields);

        $find = $admin->post('blocked_periods/find', ['blocked_period_id' => (string) $this->periodId]);
        self::assertSame(200, $find->statusCode, $find->body);
        self::assertStringContainsString($marker, $find->body);
        $findRow = json_decode($find->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($findRow);
        $findFields = array_keys($findRow);
        sort($findFields);
        self::assertSame(['end_datetime', 'id', 'name', 'notes', 'start_datetime'], $findFields);

        $wrongVerbStatuses = [];
        foreach (
            [
                ['POST', 'blocked_periods'],
                ['GET', 'blocked_periods/search'],
                ['GET', 'blocked_periods/find'],
                ['PUT', 'blocked_periods/search'],
                ['DELETE', 'blocked_periods/find'],
            ]
            as [$method, $path]
        ) {
            $response = $admin->requestApp($method, $path, [], null, $method === 'POST');
            $wrongVerbStatuses[$method . ' ' . $path] = $response->statusCode;
        }
        self::assertSame(
            [
                'POST blocked_periods' => 405,
                'GET blocked_periods/search' => 405,
                'GET blocked_periods/find' => 405,
                'PUT blocked_periods/search' => 405,
                'DELETE blocked_periods/find' => 405,
            ],
            $wrongVerbStatuses,
        );
    }

    public function testDemotedAdminCannotReadAndDoesNotChangeOwnedStateOrSessionDestination(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        self::assertSame(200, $admin->get('about')->statusCode);
        $destinationBefore = $this->sessionDestination($admin);
        $periodBefore = $fixture->blockedPeriodRow($this->periodId);
        $roleBefore = (int) ($fixture->row('users', $fixture->actorId)['id_roles'] ?? 0);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );

            foreach (
                [
                    ['GET', 'blocked_periods', []],
                    ['GET', 'blocked_periods/index', []],
                    ['POST', 'blocked_periods/search', ['keyword' => $fixture->run]],
                    ['POST', 'blocked_periods/find', ['blocked_period_id' => (string) $this->periodId]],
                ]
                as [$method, $path, $form]
            ) {
                $response = $admin->requestApp($method, $path, $form, null, $method === 'POST');
                self::assertSame(403, $response->statusCode, $method . ' ' . $path . ': ' . $response->body);
                self::assertStringNotContainsString($fixture->run, $response->body);
            }

            self::assertSame($destinationBefore, $this->sessionDestination($admin));
            self::assertSame($periodBefore, $fixture->blockedPeriodRow($this->periodId));
        } finally {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $roleBefore], ['id' => $fixture->actorId]),
            );
        }
    }

    public function testPromotedProviderGainsReadAccessAfterLogin(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $provider = $this->login($this->credentials['provider_username']);
        $roleBefore = (int) ($fixture->row('users', $fixture->providerId)['id_roles'] ?? 0);
        $adminRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_ADMIN])
            ->row_array();
        self::assertNotEmpty($adminRole['id'] ?? null);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $adminRole['id']],
                    ['id' => $fixture->providerId],
                ),
            );
            $index = $provider->get('blocked_periods');
            self::assertSame(200, $index->statusCode, $index->body);
            self::assertSame(200, $provider->get('blocked_periods/index')->statusCode);
            $find = $provider->post('blocked_periods/find', ['blocked_period_id' => (string) $this->periodId]);
            self::assertSame(200, $find->statusCode, $find->body);
            self::assertStringContainsString($fixture->run, $find->body);
            $findRow = json_decode($find->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($findRow);
            $findFields = array_keys($findRow);
            sort($findFields);
            self::assertSame(['end_datetime', 'id', 'name', 'notes', 'start_datetime'], $findFields);
        } finally {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $roleBefore], ['id' => $fixture->providerId]),
            );
        }
    }

    public function testDemotedAdminCannotUseLegacyMutations(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login($this->credentials['admin_username']);
        $period = $fixture->blockedPeriodWritePayload('demoted-store');
        $existingPeriod = $fixture->blockedPeriodRow($this->periodId);
        $roleBefore = (int) ($fixture->row('users', $fixture->actorId)['id_roles'] ?? 0);
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole['id'] ?? null);

        try {
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['id_roles' => (int) $customerRole['id']],
                    ['id' => $fixture->actorId],
                ),
            );
            $response = $admin->post('blocked_periods/store', ['blocked_period' => $period]);
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertStringNotContainsString($fixture->run, $response->body);
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('blocked_periods', ['name' => $period['name']])
                    ->num_rows(),
            );

            $update = $fixture->blockedPeriodWritePayload('demoted-update');
            $update['id'] = $this->periodId;
            $response = $admin->post('blocked_periods/update', ['blocked_period' => $update]);
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertSame($existingPeriod, $fixture->blockedPeriodRow($this->periodId));

            $response = $admin->post('blocked_periods/destroy', ['blocked_period_id' => (string) $this->periodId]);
            self::assertSame(403, $response->statusCode, $response->body);
            self::assertSame($existingPeriod, $fixture->blockedPeriodRow($this->periodId));
        } finally {
            self::assertTrue(
                get_instance()->db->update('users', ['id_roles' => $roleBefore], ['id' => $fixture->actorId]),
            );
        }
    }

    private function seedPeriod(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $period = $fixture->blockedPeriodWritePayload('read');
        self::assertTrue(get_instance()->db->insert('blocked_periods', $period));
        $this->periodId = (int) get_instance()->db->insert_id();
        self::assertSame($period['name'], $fixture->blockedPeriodRow($this->periodId)['name'] ?? null);
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
        return $client;
    }

    private function sessionDestination(GateHttpClient $client): string
    {
        $cookieName = (string) config('sess_cookie_name');
        $sessionId = $client->getCookie($cookieName);
        self::assertIsString($sessionId);
        $ipBinding = config('sess_match_ip') ? md5('127.0.0.1') : '';
        $sessionPath = $this->server?->directory . '/sessions/' . $cookieName . $ipBinding . $sessionId;
        self::assertFileExists($sessionPath);
        $contents = SessionFileReader::read($sessionPath);
        self::assertSame(1, preg_match('/dest_url\\|s:\\d+:"([^"]*)";/', $contents, $matches));
        return $matches[1];
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP/DB regression coverage for classic blocked-period ID boundaries. */
final class BlockedPeriodsLegacyIdBoundaryHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    private int $actorRoleId = 0;
    private int $actorBlockedPeriodsMask = 0;
    private int $periodId = 0;
    private array $seededPeriodBefore = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->actorRoleId = (int) ($this->fixture->row('users', $this->fixture->actorId)['id_roles'] ?? 0);
            self::assertGreaterThan(0, $this->actorRoleId);
            $this->actorBlockedPeriodsMask =
                (int) (get_instance()
                    ->db->get_where('roles', ['id' => $this->actorRoleId])
                    ->row_array()['blocked_periods'] ?? 0);
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
            if ($this->fixture !== null && $this->actorRoleId > 0) {
                get_instance()->db->update(
                    'roles',
                    ['blocked_periods' => $this->actorBlockedPeriodsMask],
                    ['id' => $this->actorRoleId],
                );
                if ($this->periodId > 0 && $this->seededPeriodBefore !== []) {
                    get_instance()->db->update('blocked_periods', $this->seededPeriodBefore, ['id' => $this->periodId]);
                }
                get_instance()->db->update(
                    'users',
                    ['id_roles' => $this->actorRoleId],
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

    public function testStoreRejectsExistingIdWithoutOverwritingOwnedPeriodOrCreatingPayloadRow(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login();
        $before = $fixture->blockedPeriodRow($this->periodId);

        // Keep the route's add authority while excluding edit authority.
        self::assertTrue(
            (bool) get_instance()->db->update('roles', ['blocked_periods' => PRIV_ADD], ['id' => $this->actorRoleId]),
        );

        $aliasAdmin = $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
        foreach (
            [
                'existing' => $this->periodId,
                'zero-int' => 0,
                'zero-string' => '0',
                'negative' => -1,
                'non-numeric' => 'not-an-id',
            ]
            as $case => $id
        ) {
            foreach (
                [['blocked_periods/store', $admin], ['index.php/blocked_periods/store', $aliasAdmin]]
                as [$path, $client]
            ) {
                $period = $fixture->blockedPeriodWritePayload(
                    'store-' . $case . '-' . ($path[0] === 'i' ? 'alias' : 'canonical'),
                );
                $period['id'] = $id;
                $response = $client->post($path, ['blocked_period' => $period]);

                self::assertSame(400, $response->statusCode, $case . ' ' . $path . ': ' . $response->body);
                self::assertSame($before, $fixture->blockedPeriodRow($this->periodId));
                self::assertSame([], $this->rowsByName($period['name']));
            }
        }
    }

    public function testUpdateRejectsOmittedNullAndEmptyIdWithoutInserting(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $admin = $this->login();
        $before = $fixture->blockedPeriodRow($this->periodId);

        // Keep the route's edit authority while excluding add authority.
        self::assertTrue(
            (bool) get_instance()->db->update('roles', ['blocked_periods' => PRIV_EDIT], ['id' => $this->actorRoleId]),
        );

        $aliasAdmin = $this->login(new GateHttpClient($this->server->baseUrl, indexPage: ''));
        foreach (
            [
                'omitted' => '__omitted__',
                'null' => null,
                'empty' => '',
                'zero-int' => 0,
                'zero-string' => '0',
                'negative' => -1,
                'non-numeric' => 'not-an-id',
            ]
            as $case => $id
        ) {
            $period = $fixture->blockedPeriodWritePayload('update-' . $case);
            if ($id !== '__omitted__') {
                $period['id'] = $id;
            }

            foreach (
                [['blocked_periods/update', $admin], ['index.php/blocked_periods/update', $aliasAdmin]]
                as [$path, $client]
            ) {
                $response = $client->post($path, ['blocked_period' => $period]);

                self::assertSame(400, $response->statusCode, $case . ' ' . $path . ': ' . $response->body);
                self::assertSame($before, $fixture->blockedPeriodRow($this->periodId));
                self::assertSame([], $this->rowsByName($period['name']));
            }
        }
    }

    private function seedPeriod(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $period = $fixture->blockedPeriodWritePayload('existing');
        self::assertTrue(get_instance()->db->insert('blocked_periods', $period));
        $this->periodId = (int) get_instance()->db->insert_id();
        $this->seededPeriodBefore = $fixture->blockedPeriodRow($this->periodId);
        self::assertSame($period['name'], $this->seededPeriodBefore['name'] ?? null);
    }

    private function login(?GateHttpClient $client = null): GateHttpClient
    {
        $client ??= $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['admin_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    /** @return list<array<string, mixed>> */
    private function rowsByName(string $name): array
    {
        return get_instance()
            ->db->get_where('blocked_periods', ['name' => $name])
            ->result_array();
    }
}

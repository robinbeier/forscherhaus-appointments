<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Ordinary authenticated admin CRUD for backoffice Customers and Blocked_periods. */
final class BackofficeWriteHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }
        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
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

    public function testAuthenticatedAdminCanCreateUpdateAndDeleteCustomerAndBlockedPeriod(): void
    {
        $f = $this->fixture;
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $login = $client->post('login/validate', [
            'username' => $f->run . '_actor',
            'password' => $f->password,
        ]);
        self::assertSame(200, $login->statusCode);
        self::assertTrue((bool) (json_decode($login->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));

        $customer = $f->customerWritePayload('crud');
        $created = $this->json($client->post('customers/store', ['customer' => $customer]));
        $customerId = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $customerId, 'Customer create must return an ID.');
        self::assertSame($customerId, (int) ($f->row('users', $customerId)['id'] ?? 0));
        self::assertSame($customer['email'], $f->row('users', $customerId)['email']);

        $customer['id'] = $customerId;
        $customer['first_name'] = 'Updated Customer';
        $updated = $this->json($client->post('customers/update', ['customer' => $customer]));
        self::assertSame($customerId, (int) ($updated['id'] ?? 0));
        self::assertSame('Updated Customer', $f->row('users', $customerId)['first_name']);

        $period = $f->blockedPeriodWritePayload('crud');
        $createdPeriod = $this->json($client->post('blocked_periods/store', ['blocked_period' => $period]));
        $periodId = (int) ($createdPeriod['id'] ?? 0);
        self::assertGreaterThan(0, $periodId, 'Blocked-period create must return an ID.');
        self::assertSame($period['name'], $f->blockedPeriodRow($periodId)['name']);

        $period['id'] = $periodId;
        $period['notes'] = 'updated blocked period';
        $updatedPeriod = $this->json($client->post('blocked_periods/update', ['blocked_period' => $period]));
        self::assertSame($periodId, (int) ($updatedPeriod['id'] ?? 0));
        self::assertSame('updated blocked period', $f->blockedPeriodRow($periodId)['notes']);

        self::assertTrue(
            (bool) ($this->json($client->post('customers/destroy', ['customer_id' => $customerId]))['success'] ??
                false),
        );
        self::assertSame([], $f->row('users', $customerId));
        self::assertTrue(
            (bool) ($this->json($client->post('blocked_periods/destroy', ['blocked_period_id' => $periodId]))[
                'success'
            ] ?? false),
        );
        self::assertSame([], $f->blockedPeriodRow($periodId));
    }

    public function testFixtureCleanupRemovesBothHttpWritesBeforeDestroyRequests(): void
    {
        $f = $this->fixture;
        $client = $this->server->client();
        $seededSetting = get_instance()
            ->db->get_where('settings', ['name' => 'company_name'])
            ->row_array();
        self::assertNotEmpty($seededSetting);
        $seededUsers = get_instance()->db->not_like('email', $f->run)->order_by('id')->get('users')->result_array();
        self::assertNotEmpty($seededUsers);
        self::assertSame(200, $client->get('login')->statusCode);
        $login = $client->post('login/validate', ['username' => $f->run . '_actor', 'password' => $f->password]);
        self::assertSame(200, $login->statusCode);
        self::assertTrue(json_decode($login->body, true, 512, JSON_THROW_ON_ERROR)['success']);

        $customer = $f->customerWritePayload('cleanup');
        $customerResponse = $this->json($client->post('customers/store', ['customer' => $customer]));
        $customerId = (int) ($customerResponse['id'] ?? 0);
        self::assertGreaterThan(0, $customerId);
        $period = $f->blockedPeriodWritePayload('cleanup');
        $periodResponse = $this->json($client->post('blocked_periods/store', ['blocked_period' => $period]));
        self::assertGreaterThan(0, (int) ($periodResponse['id'] ?? 0));

        $f->cleanup();

        self::assertSame([], $f->row('users', $customerId));
        self::assertSame([], $f->blockedPeriodRow((int) $periodResponse['id']));
        self::assertSame($seededUsers, get_instance()->db->order_by('id')->get('users')->result_array());
        self::assertSame(
            $seededSetting,
            get_instance()
                ->db->get_where('settings', ['name' => 'company_name'])
                ->row_array(),
        );
    }

    private function json(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('exception', $data);
        return $data;
    }
}

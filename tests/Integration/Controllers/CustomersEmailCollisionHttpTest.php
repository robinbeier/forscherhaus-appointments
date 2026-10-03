<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded regression coverage for duplicate email handling on classic Customers::store. */
final class CustomersEmailCollisionHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];

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

    public function testStoreRejectsExistingCustomerEmailWithoutMutatingThatCustomer(): void
    {
        $fixture = $this->fixture;
        $client = $this->login($this->server->client());

        $controlPayload = $fixture->customerWritePayload('collision-control');
        $created = $client->post('customers/store', ['customer' => $controlPayload]);
        self::assertSame(200, $created->statusCode, $created->body);
        $createdData = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($createdData['success'] ?? false), $created->body);
        $createdId = (int) ($createdData['id'] ?? 0);
        self::assertGreaterThan(0, $createdId, $created->body);
        self::assertSame($controlPayload['email'], $fixture->row('users', $createdId)['email'] ?? null);

        $existingBefore = $fixture->row('users', $fixture->customerId);
        self::assertNotEmpty($existingBefore);
        $collisionPayload = [
            'first_name' => 'Collision Attempt',
            'last_name' => 'Must Be Rejected',
            'email' => $existingBefore['email'],
            'phone_number' => '9999999999',
            'notes' => $fixture->run . '_collision_attempt',
        ];

        $response = $client->post('customers/store', ['customer' => $collisionPayload]);

        self::assertGreaterThanOrEqual(400, $response->statusCode, $response->body);
        self::assertStringContainsString('already in use', $response->body);
        self::assertSame($existingBefore, $fixture->row('users', $fixture->customerId));
        self::assertCount(1, $this->rowsByEmail((string) $existingBefore['email']));
    }

    public function testStoreAcceptsEmptyIdPlaceholderAsCreate(): void
    {
        $fixture = $this->fixture;
        $client = $this->login($this->server->client());
        $payload = $fixture->customerWritePayload('empty-id');

        $response = $client->post('customers/store', ['customer' => ['id' => ''] + $payload]);

        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($data['success'] ?? false), $response->body);
        $createdId = (int) ($data['id'] ?? 0);
        self::assertGreaterThan(0, $createdId);
        self::assertSame($payload['email'], $fixture->row('users', $createdId)['email'] ?? null);
    }

    private function login(GateHttpClient $client): GateHttpClient
    {
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['admin_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);

        return $client;
    }

    /** @return list<array<string, mixed>> */
    private function rowsByEmail(string $email): array
    {
        return get_instance()
            ->db->get_where('users', ['email' => $email])
            ->result_array();
    }
}

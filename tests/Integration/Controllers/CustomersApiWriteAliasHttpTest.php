<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for Customers API v1 direct write aliases. */
final class CustomersApiWriteAliasHttpTest extends TestCase
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

    public function testDirectStoreAliasRejectsWrongVerbAndUnauthenticatedWriteWithoutMutation(): void
    {
        $fixture = $this->fixture;
        $admin = $this->adminClient();
        $targetPayload = $this->customerPayload($fixture->customerWritePayload('alias-store-target'));

        // Canonical routing remains the positive control for the store operation.
        $canonical = $admin->requestJsonApp('POST', 'api/v1/customers', $targetPayload);
        self::assertSame(201, $canonical->statusCode, $canonical->body);
        $customerId = (int) (json_decode($canonical->body, true, 512, JSON_THROW_ON_ERROR)['id'] ?? 0);
        self::assertGreaterThan(0, $customerId);
        $before = $fixture->row('users', $customerId);

        $directPayload = $this->customerPayload($fixture->customerWritePayload('alias-store-correct-verb'));
        $direct = $admin->requestJsonApp('POST', 'api/v1/customers_api_v1/store', $directPayload);
        self::assertSame(201, $direct->statusCode, $direct->body);
        self::assertSame(1, $this->rowsByEmail($directPayload['email']));

        $wrongVerbPayload = $this->customerPayload($fixture->customerWritePayload('alias-store-wrong-verb'));
        $wrongVerb = $admin->requestJsonApp('PUT', 'api/v1/customers_api_v1/store', $wrongVerbPayload);
        self::assertSame(0, $this->rowsByEmail($wrongVerbPayload['email']));
        self::assertSame($before, $fixture->row('users', $customerId));
        self::assertSame(405, $wrongVerb->statusCode, 'Direct store alias PUT must be rejected.');
        self::assertSame('POST', $wrongVerb->header('allow'));

        foreach (['GET', 'HEAD', 'PATCH', 'DELETE'] as $method) {
            $payload = $this->customerPayload($fixture->customerWritePayload('alias-store-' . strtolower($method)));
            $response = in_array($method, ['PATCH'], true)
                ? $admin->requestApp($method, 'api/v1/customers_api_v1/store', $payload)
                : $admin->requestApp($method, 'api/v1/customers_api_v1/store');
            self::assertSame(0, $this->rowsByEmail($payload['email']), $method . ' must not insert a row.');
            self::assertSame(405, $response->statusCode, $method . ' must be rejected.');
            self::assertSame('POST', $response->header('allow'), $method . ' must advertise POST only.');
        }

        $unauthenticatedPayload = $this->customerPayload($fixture->customerWritePayload('alias-store-no-auth'));
        $unauthenticated = $this->server
            ->client()
            ->requestJsonApp('POST', 'api/v1/customers_api_v1/store', $unauthenticatedPayload);
        self::assertSame(401, $unauthenticated->statusCode);
        self::assertNotNull($unauthenticated->header('www-authenticate'));
        self::assertSame(0, $this->rowsByEmail($unauthenticatedPayload['email']));
    }

    public function testDirectUpdateAliasRejectsWrongVerbAndUnauthenticatedWriteWithoutMutation(): void
    {
        $fixture = $this->fixture;
        $admin = $this->adminClient();
        $payload = $this->customerPayload($fixture->customerWritePayload('alias-update-target'));
        $created = $admin->requestJsonApp('POST', 'api/v1/customers', $payload);
        self::assertSame(201, $created->statusCode, $created->body);
        $customerId = (int) (json_decode($created->body, true, 512, JSON_THROW_ON_ERROR)['id'] ?? 0);
        self::assertGreaterThan(0, $customerId);
        $before = $fixture->row('users', $customerId);

        $wrongVerbPayload = $payload;
        $wrongVerbPayload['firstName'] = 'Alias Wrong Verb';
        $wrongVerb = $admin->requestJsonApp('POST', 'api/v1/customers_api_v1/update/' . $customerId, $wrongVerbPayload);
        self::assertSame($before, $fixture->row('users', $customerId));
        self::assertSame(405, $wrongVerb->statusCode, 'Direct update alias POST must be rejected.');
        self::assertSame('PUT', $wrongVerb->header('allow'));

        foreach (['GET', 'HEAD', 'PATCH', 'DELETE'] as $method) {
            $payload = $this->customerPayload($fixture->customerWritePayload('alias-update-' . strtolower($method)));
            $payload['firstName'] = 'Alias ' . $method;
            $response = in_array($method, ['PATCH'], true)
                ? $admin->requestApp($method, 'api/v1/customers_api_v1/update/' . $customerId, $payload)
                : $admin->requestApp($method, 'api/v1/customers_api_v1/update/' . $customerId);
            self::assertSame($before, $fixture->row('users', $customerId), $method . ' must not update the row.');
            self::assertSame(405, $response->statusCode, $method . ' must be rejected.');
            self::assertSame('PUT', $response->header('allow'), $method . ' must advertise PUT only.');
        }

        $unauthenticatedPayload = $payload;
        $unauthenticatedPayload['firstName'] = 'Alias Unauthenticated';
        $unauthenticated = $this->server
            ->client()
            ->requestJsonApp('PUT', 'api/v1/customers_api_v1/update/' . $customerId, $unauthenticatedPayload);
        self::assertSame(401, $unauthenticated->statusCode);
        self::assertNotNull($unauthenticated->header('www-authenticate'));
        self::assertSame($before, $fixture->row('users', $customerId));

        // Canonical routing remains the positive control for the update operation.
        $canonicalPayload = $payload;
        $canonicalPayload['firstName'] = 'Canonical Update';
        $canonical = $admin->requestJsonApp('PUT', 'api/v1/customers/' . $customerId, $canonicalPayload);
        self::assertSame(200, $canonical->statusCode, $canonical->body);
        self::assertSame('Canonical Update', $fixture->row('users', $customerId)['first_name'] ?? null);

        $directPayload = $payload;
        $directPayload['firstName'] = 'Direct Alias Update';
        $direct = $admin->requestJsonApp('PUT', 'api/v1/customers_api_v1/update/' . $customerId, $directPayload);
        self::assertSame(200, $direct->statusCode, $direct->body);
        self::assertSame('Direct Alias Update', $fixture->row('users', $customerId)['first_name'] ?? null);
    }

    /** @return array<string, mixed> */
    private function customerPayload(array $payload): array
    {
        return [
            'firstName' => $payload['first_name'],
            'lastName' => $payload['last_name'],
            'email' => $payload['email'],
            'phone' => $payload['phone_number'],
            'notes' => $payload['notes'],
        ];
    }

    private function rowsByEmail(string $email): int
    {
        $ci = &get_instance();

        return (int) $ci->db->get_where('users', ['email' => $email])->num_rows();
    }

    private function adminClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            ],
        );
    }
}

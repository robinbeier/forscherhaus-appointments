<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for Customers/Providers API v1 destroy aliases. */
final class CustomersProvidersApiDestroyAliasHttpTest extends TestCase
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

    public function testCustomerCanonicalDeleteAndDirectAliasRejectWrongVerbsWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();

        $customer = $f->customerWritePayload('destroy-alias-customer');
        $customerCreated = $this->json(
            $admin->requestJsonApp('POST', 'api/v1/customers', $this->customerPayload($customer)),
            201,
        );
        $customerId = (int) ($customerCreated['id'] ?? 0);
        self::assertGreaterThan(0, $customerId);
        self::assertSame(204, $admin->requestApp('DELETE', 'api/v1/customers/' . $customerId)->statusCode);
        self::assertSame([], $f->row('users', $customerId), 'Canonical customer DELETE must remove its row.');

        $customerAlias = $this->createCustomer($admin, 'wrong-verbs-customer');
        $path = 'api/v1/customers_api_v1/destroy/' . $customerAlias;
        $before = $this->customerState($customerAlias);
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH'] as $method) {
            $response = $admin->requestApp($method, $path, [], null, false);
            self::assertSame(405, $response->statusCode, $method . ' ' . $path);
            self::assertSame('DELETE', $response->header('allow'), $method . ' ' . $path . ' Allow header.');
            self::assertSame($before, $this->customerState($customerAlias));
        }

        $noAuth = $this->server->client();
        $response = $noAuth->requestApp('DELETE', $path);
        self::assertSame(401, $response->statusCode, 'Unauthenticated DELETE must be rejected.');
        self::assertNotNull($response->header('www-authenticate'));
        self::assertSame($before, $this->customerState($customerAlias));

        $indexless = $this->adminClient('');
        $response = $indexless->get($path);
        self::assertSame(405, $response->statusCode, 'Indexless direct alias GET must be rejected.');
        self::assertSame('DELETE', $response->header('allow'));
        self::assertSame($before, $this->customerState($customerAlias));
    }

    public function testProviderCanonicalDeleteAndDirectAliasRejectWrongVerbsWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $provider = $f->providerWritePayload('destroy-alias-provider');
        $providerCreated = $this->json($admin->requestJsonApp('POST', 'api/v1/providers', $provider), 201);
        $providerId = (int) ($f->providerWriteState($provider['email'])['user']['id'] ?? 0);
        self::assertSame($providerId, (int) ($providerCreated['id'] ?? 0));
        self::assertSame(204, $admin->requestApp('DELETE', 'api/v1/providers/' . $providerId)->statusCode);
        self::assertSame(
            ['user' => [], 'settings' => [], 'services' => [], 'appointments' => []],
            $f->providerDeleteState($providerId),
        );

        $providerAlias = $this->createProvider($admin, 'wrong-verbs-provider');
        $path = 'api/v1/providers_api_v1/destroy/' . $providerAlias;
        $before = $f->providerDeleteState($providerAlias);
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH'] as $method) {
            $response = $admin->requestApp($method, $path, [], null, false);
            self::assertSame(405, $response->statusCode, $method . ' ' . $path);
            self::assertSame('DELETE', $response->header('allow'));
            self::assertSame($before, $f->providerDeleteState($providerAlias));
        }

        $response = $this->server->client()->requestApp('DELETE', $path);
        self::assertSame(401, $response->statusCode);
        self::assertNotNull($response->header('www-authenticate'));
        self::assertSame($before, $f->providerDeleteState($providerAlias));

        $response = $this->adminClient('')->get($path);
        self::assertSame(405, $response->statusCode);
        self::assertSame('DELETE', $response->header('allow'));
        self::assertSame($before, $f->providerDeleteState($providerAlias));
    }

    private function createCustomer(GateHttpClient $client, string $case): int
    {
        $payload = $this->fixture->customerWritePayload($case);
        $created = $this->json(
            $client->requestJsonApp('POST', 'api/v1/customers', $this->customerPayload($payload)),
            201,
        );
        $id = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function createProvider(GateHttpClient $client, string $case): int
    {
        $payload = $this->fixture->providerWritePayload($case);
        $this->json($client->requestJsonApp('POST', 'api/v1/providers', $payload), 201);
        return (int) ($this->fixture->providerWriteState($payload['email'])['user']['id'] ?? 0);
    }

    private function customerState(int $id): array
    {
        return ['user' => $this->fixture->row('users', $id)];
    }

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

    private function adminClient(string $indexPage = 'index.php'): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            indexPage: $indexPage,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            ],
        );
    }

    private function json(GateHttpResponse $response, int $status): array
    {
        self::assertSame($status, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP coverage for Customers API v1 read authentication and projection. */
final class CustomersApiHttpReadTest extends TestCase
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

    public function testCustomerReadsRejectMissingInvalidAndProviderAuthenticationOnBothRouteForms(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $before = $fixture->row('users', $fixture->customerId);
        $paths = $this->readPaths($fixture->customerId);
        $clients = [
            'anonymous' => $this->server?->client(),
            'invalid-bearer' => $this->bearerClient($this->credentials['token'] . '-invalid'),
            'provider-basic' => $this->basicClient(
                $this->credentials['provider_username'],
                $this->credentials['password'],
            ),
        ];

        foreach ($clients as $case => $client) {
            self::assertNotNull($client);
            foreach ($paths as $path) {
                $response = $client->get($path);
                self::assertSame(401, $response->statusCode, $case . ' must be denied for ' . $path);
                self::assertNotNull($response->header('www-authenticate'));
                self::assertStringNotContainsString($fixture->run, $response->body);
                self::assertStringNotContainsString((string) $fixture->customerId, $response->body);
            }
        }
        self::assertSame($before, $fixture->row('users', $fixture->customerId));
    }

    public function testAdminAndGlobalBearerReadCustomersButNeverStaffAndFieldsStayEncoded(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $before = $fixture->row('users', $fixture->customerId);
        $clients = [
            'admin-basic' => $this->basicClient($this->credentials['admin_username'], $this->credentials['password']),
            'global-bearer' => $this->bearerClient($this->credentials['token']),
        ];

        foreach ($clients as $case => $client) {
            foreach (['api/v1/customers', 'api/v1/customers_api_v1/index'] as $path) {
                $response = $client->get($path, ['q' => $fixture->run]);
                $rows = $this->decodeSuccess($response);
                $this->assertOnlyFixtureCustomer($rows, $case . ' ' . $path);
            }

            foreach ([$fixture->actorId, $fixture->providerId] as $staffId) {
                foreach (['api/v1/customers/', 'api/v1/customers_api_v1/show/'] as $prefix) {
                    $response = $client->get($prefix . $staffId);
                    self::assertSame(404, $response->statusCode, $case . ' must not expose staff ID ' . $staffId);
                    self::assertStringNotContainsString($fixture->run, $response->body);
                }
            }

            $default = $this->decodeSuccess($client->get('api/v1/customers/' . $fixture->customerId));
            self::assertSame($fixture->customerId, $default['id']);
            foreach (['id_roles', 'password', 'salt', 'google_token', 'caldav_password'] as $rawField) {
                self::assertArrayNotHasKey($rawField, $default);
            }

            $fields = $client->get('api/v1/customers/' . $fixture->customerId, [
                'fields' => 'id,firstName,email',
                'with' => 'appointments',
            ]);
            $fieldData = $this->decodeSuccess($fields);
            self::assertSame(['id', 'firstName', 'email'], array_keys($fieldData));
            self::assertSame($fixture->customerId, $fieldData['id']);
            self::assertSame($fixture->run . '_customer@synthetic.invalid', $fieldData['email']);
            foreach (['first_name', 'last_name', 'id_roles', 'notes', 'ldap_dn', 'custom_field_1'] as $rawField) {
                self::assertArrayNotHasKey($rawField, $fieldData);
            }

            $aliasFields = $client->get('api/v1/customers_api_v1/show/' . $fixture->customerId, [
                'fields' => 'id,firstName,email',
            ]);
            self::assertSame(['id', 'firstName', 'email'], array_keys($this->decodeSuccess($aliasFields)));
        }
        self::assertSame($before, $fixture->row('users', $fixture->customerId));
    }

    public function testDirectAliasRejectsNonGetMethodsWithoutBodyOrMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $before = $fixture->row('users', $fixture->customerId);
        $admin = $this->basicClient($this->credentials['admin_username'], $this->credentials['password']);
        $unauthenticated = $this->server?->client();
        self::assertNotNull($unauthenticated);
        $unauthenticatedPost = $unauthenticated->requestJsonApp('POST', 'api/v1/customers_api_v1/index', []);
        self::assertSame(401, $unauthenticatedPost->statusCode);
        self::assertNotNull($unauthenticatedPost->header('www-authenticate'));
        self::assertStringNotContainsString($fixture->run, $unauthenticatedPost->body);
        self::assertStringNotContainsString((string) $fixture->customerId, $unauthenticatedPost->body);
        self::assertSame($before, $fixture->row('users', $fixture->customerId));
        $requests = [
            'POST index' => fn(): GateHttpResponse => $admin->requestJsonApp(
                'POST',
                'api/v1/customers_api_v1/index',
                [],
            ),
            'PUT show' => fn(): GateHttpResponse => $admin->requestJsonApp(
                'PUT',
                'api/v1/customers_api_v1/show/' . $fixture->customerId,
                [],
            ),
            'PATCH index' => fn(): GateHttpResponse => $admin->requestApp('PATCH', 'api/v1/customers_api_v1/index'),
            'DELETE show' => fn(): GateHttpResponse => $admin->requestApp(
                'DELETE',
                'api/v1/customers_api_v1/show/' . $fixture->customerId,
            ),
            'HEAD index' => fn(): GateHttpResponse => $admin->requestApp('HEAD', 'api/v1/customers_api_v1/index'),
        ];
        foreach ($requests as $case => $request) {
            $response = $request();
            self::assertSame(405, $response->statusCode, $case . ' must be rejected.');
            self::assertSame('GET', $response->header('allow'), $case . ' must advertise GET only.');
            self::assertSame('', $response->body, $case . ' must not emit customer data.');
            self::assertSame($before, $fixture->row('users', $fixture->customerId));
        }

        $options = $admin->requestApp('OPTIONS', 'api/v1/customers_api_v1/index');
        self::assertSame(200, $options->statusCode);
        self::assertSame('', $options->body);
        self::assertSame($before, $fixture->row('users', $fixture->customerId));
    }

    /** @return list<string> */
    private function readPaths(int $customerId): array
    {
        return [
            'api/v1/customers',
            'api/v1/customers/' . $customerId,
            'api/v1/customers_api_v1/index',
            'api/v1/customers_api_v1/show/' . $customerId,
        ];
    }

    private function assertOnlyFixtureCustomer(array $rows, string $case): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $matches = array_values(
            array_filter(
                $rows,
                static fn(mixed $row): bool => is_array($row) &&
                    ($row['email'] ?? null) === $fixture->run . '_customer@synthetic.invalid',
            ),
        );
        self::assertCount(1, $matches, $case . ' must contain exactly one fixture customer.');
        self::assertSame($fixture->customerId, (int) ($matches[0]['id'] ?? 0));
        foreach ($rows as $row) {
            if (!is_array($row) || !str_contains((string) ($row['email'] ?? ''), $fixture->run)) {
                continue;
            }
            self::assertSame($fixture->customerId, (int) ($row['id'] ?? 0));
        }
    }

    private function decodeSuccess(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse(array_key_exists('exception', $data));

        return $data;
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

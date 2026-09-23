<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded local HTTP coverage for the Customers API v1 write paths. */
final class CustomersApiHttpWriteTest extends TestCase
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

    public function testDuplicateEmailPostDoesNotMutateExistingCustomer(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $original = $this->customerPayload($f->customerWritePayload('duplicate-email'));

        $created = $this->success($admin->requestJsonApp('POST', 'api/v1/customers', $original), 201);
        $id = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $id, 'Initial customer creation must return a positive ID.');
        $before = $f->row('users', $id);
        self::assertSame($original['email'], $before['email'] ?? null);

        $duplicate = $original;
        $duplicate['firstName'] = 'Duplicate Attempt';
        $duplicate['lastName'] = 'Must Be Rejected';
        $duplicate['notes'] = $f->run . '_duplicate_attempt';
        $response = $admin->requestJsonApp('POST', 'api/v1/customers', $duplicate);

        self::assertNotSame(
            201,
            $response->statusCode,
            'A POST with an existing customer email must not report a new customer.',
        );
        self::assertSame($before, $f->row('users', $id), 'Duplicate POST must not update the existing customer.');
        self::assertCount(
            1,
            $this->rowsByEmail($original['email']),
            'Duplicate POST must not create a second customer row.',
        );
    }

    public function testPutRejectsBodyIdThatDiffersFromUriWithoutMutatingEitherCustomer(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $uriCustomerPayload = $this->customerPayload($f->customerWritePayload('put-uri-target'));
        $bodyCustomerPayload = $this->customerPayload($f->customerWritePayload('put-body-target'));

        $uriCreated = $this->success($admin->requestJsonApp('POST', 'api/v1/customers', $uriCustomerPayload), 201);
        $bodyCreated = $this->success($admin->requestJsonApp('POST', 'api/v1/customers', $bodyCustomerPayload), 201);
        $uriId = (int) ($uriCreated['id'] ?? 0);
        $bodyId = (int) ($bodyCreated['id'] ?? 0);
        self::assertGreaterThan(0, $uriId);
        self::assertGreaterThan(0, $bodyId);
        self::assertNotSame($uriId, $bodyId);

        $uriBefore = $f->row('users', $uriId);
        $bodyBefore = $f->row('users', $bodyId);
        // Keep the body email aligned with the body ID so a permissive implementation
        // cannot hide the URI/body target confusion behind a uniqueness error.
        $conflictingPayload = $bodyCustomerPayload;
        $conflictingPayload['id'] = $bodyId;
        $conflictingPayload['firstName'] = 'Conflicting Target';
        $conflictingPayload['notes'] = $f->run . '_conflicting_target';

        $response = $admin->requestJsonApp('PUT', 'api/v1/customers/' . $uriId, $conflictingPayload);

        self::assertContains(
            $response->statusCode,
            [400, 409, 422],
            'A PUT body ID that differs from the URI ID must be rejected.',
        );
        self::assertSame($uriBefore, $f->row('users', $uriId), 'URI target must remain unchanged.');
        self::assertSame($bodyBefore, $f->row('users', $bodyId), 'Conflicting body target must remain unchanged.');
    }

    public function testUnauthenticatedWritesAreRejectedWithoutMutation(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $targetPayload = $this->customerPayload($f->customerWritePayload('auth-target'));
        $created = $this->success($admin->requestJsonApp('POST', 'api/v1/customers', $targetPayload), 201);
        $id = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        $before = $f->row('users', $id);

        $unauthenticated = $this->server->client();
        $post = $unauthenticated->requestJsonApp(
            'POST',
            'api/v1/customers',
            $this->customerPayload($f->customerWritePayload('auth-post')),
        );
        self::assertSame(401, $post->statusCode, 'Unauthenticated POST must be rejected.');
        self::assertNotNull($post->header('www-authenticate'));

        $update = $targetPayload;
        $update['firstName'] = 'Unauthenticated Change';
        $put = $unauthenticated->requestJsonApp('PUT', 'api/v1/customers/' . $id, $update);
        self::assertSame(401, $put->statusCode, 'Unauthenticated PUT must be rejected.');
        self::assertNotNull($put->header('www-authenticate'));

        $delete = $unauthenticated->requestApp('DELETE', 'api/v1/customers/' . $id);
        self::assertSame(401, $delete->statusCode, 'Unauthenticated DELETE must be rejected.');
        self::assertNotNull($delete->header('www-authenticate'));
        self::assertSame($before, $f->row('users', $id), 'Unauthenticated requests must not mutate state.');
    }

    public function testAuthorizedDeleteRemovesCustomerAndIsIdempotentlyNotFound(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $payload = $this->customerPayload($f->customerWritePayload('delete'));
        $created = $this->success($admin->requestJsonApp('POST', 'api/v1/customers', $payload), 201);
        $id = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $id);

        $deleted = $admin->requestApp('DELETE', 'api/v1/customers/' . $id);
        self::assertSame(204, $deleted->statusCode, 'Authorized DELETE must return 204.');
        self::assertSame([], $f->row('users', $id), 'Authorized DELETE must remove the customer row.');
        self::assertSame(404, $admin->requestApp('DELETE', 'api/v1/customers/' . $id)->statusCode);
    }

    public function testBearerCustomerIdBoundariesAndDelete(): void
    {
        $f = $this->fixture;
        $admin = $this->adminClient();
        $bearer = $this->bearerClient();
        $payload = $this->customerPayload($f->customerWritePayload('bearer-boundary'));
        $created = $this->success($admin->requestJsonApp('POST', 'api/v1/customers', $payload), 201);
        $customerId = (int) ($created['id'] ?? 0);
        $staffId = $f->actorId;
        self::assertGreaterThan(0, $customerId);
        self::assertGreaterThan(0, $staffId);
        self::assertNotSame($customerId, $staffId);

        $matchingIdUpdate = $payload;
        $matchingIdUpdate['id'] = $customerId;
        $matchingIdUpdate['firstName'] = 'Bearer Updated';
        $matchingIdUpdate['notes'] = $f->run . '_bearer_updated';
        $updated = $this->success(
            $bearer->requestJsonApp('PUT', 'api/v1/customers/' . $customerId, $matchingIdUpdate),
            200,
        );
        self::assertSame($customerId, (int) ($updated['id'] ?? 0));
        self::assertSame('Bearer Updated', $f->row('users', $customerId)['first_name'] ?? null);

        $customerBeforeStaffUri = $f->row('users', $customerId);
        $staffBefore = $f->row('users', $staffId);
        $staffUriResponse = $bearer->requestJsonApp('PUT', 'api/v1/customers/' . $staffId, $matchingIdUpdate);
        self::assertSame(404, $staffUriResponse->statusCode, 'A staff ID must not be a Customer URI target.');
        self::assertSame($customerBeforeStaffUri, $f->row('users', $customerId));
        self::assertSame($staffBefore, $f->row('users', $staffId));

        $customerBeforeMismatch = $f->row('users', $customerId);
        $mismatch = $matchingIdUpdate;
        $mismatch['id'] = $staffId;
        $mismatchResponse = $bearer->requestJsonApp('PUT', 'api/v1/customers/' . $customerId, $mismatch);
        self::assertSame(400, $mismatchResponse->statusCode, 'A staff body ID must not override the Customer URI.');
        self::assertSame($customerBeforeMismatch, $f->row('users', $customerId));
        self::assertSame($staffBefore, $f->row('users', $staffId));

        self::assertSame(204, $bearer->requestApp('DELETE', 'api/v1/customers/' . $customerId)->statusCode);
        self::assertSame([], $f->row('users', $customerId));
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

    /** @return list<array<string, mixed>> */
    private function rowsByEmail(string $email): array
    {
        $ci = &get_instance();
        return $ci->db->get_where('users', ['email' => $email])->result_array();
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

    private function bearerClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: ['Authorization' => 'Bearer ' . $this->credentials['token']],
        );
    }

    private function success(GateHttpResponse $response, int $status): array
    {
        self::assertSame($status, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}

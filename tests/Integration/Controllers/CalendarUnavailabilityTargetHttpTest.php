<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Regression coverage for provider-only calendar unavailability writes. */
final class CalendarUnavailabilityTargetHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
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

    public function testAuthorizedProviderCreateSucceedsThroughCanonicalRoute(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->authenticatedClient();
        $payload = $this->payload($fixture->providerId, '_provider');

        $response = $client->post('calendar/save_unavailability', ['unavailability' => $payload]);
        self::assertSame(200, $response->statusCode, $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($body['success'] ?? false), $response->body);

        $row = get_instance()
            ->db->get_where('appointments', [
                'notes' => $payload['notes'],
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
            ])
            ->row_array();
        self::assertNotEmpty($row);
        self::assertSame($payload['start_datetime'], $row['start_datetime']);
        self::assertSame($payload['end_datetime'], $row['end_datetime']);
    }

    public function testCustomerAndUnknownProviderIdsAreRejectedWithoutPartialMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->authenticatedClient();
        $before = $this->mutationSnapshot();

        foreach (
            [
                'customer' => [$fixture->customerId, '_customer'],
                'unknown' => [$this->nonexistentUserId(), '_unknown'],
            ]
            as $name => [$providerId, $suffix]
        ) {
            $response = $client->post('calendar/save_unavailability', [
                'unavailability' => $this->payload($providerId, $suffix),
            ]);
            self::assertSame(403, $response->statusCode, $name . ': ' . $response->body);
            $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertFalse((bool) ($body['success'] ?? true), $name . ': ' . $response->body);
            self::assertSame($before, $this->mutationSnapshot(), $name . ' changed persisted state.');
        }

        $aliasClient = $this->authenticatedClient(false);
        $response = $aliasClient->post('backend_api/ajax_save_unavailability', [
            'unavailability' => $this->payload($fixture->customerId, '_alias_customer'),
        ]);
        self::assertSame(303, $response->statusCode, $response->body);
        self::assertStringEndsWith('/calendar/save_unavailability', (string) $response->header('location'));
        self::assertSame($before, $this->mutationSnapshot());
    }

    private function authenticatedClient(bool $followRedirects = true): GateHttpClient
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $followRedirects
            ? $this->server?->client()
            : new GateHttpClient($this->server->baseUrl, additionalHeaders: ['X-FH-Test' => 'rob-800']);
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function payload(int $providerId, string $suffix): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        return [
            'start_datetime' => '2035-09-10 10:00:00',
            'end_datetime' => '2035-09-10 10:30:00',
            'notes' => $fixture->run . $suffix,
            'id_users_provider' => $providerId,
            'is_unavailability' => true,
        ];
    }

    private function mutationSnapshot(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        return [
            'appointments' => $db->get_where('appointments', ['notes LIKE' => $fixture->run . '%'])->result_array(),
            'users' => $db->get_where('users', ['notes' => $fixture->run])->result_array(),
            'provider_settings' => $fixture->userSettingsRow($fixture->providerId),
            'customer_settings' => $fixture->userSettingsRow($fixture->customerId),
        ];
    }

    private function nonexistentUserId(): int
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $max = get_instance()->db->select_max('id')->get('users')->row_array()['id'] ?? 0;
        return max((int) $max, $fixture->actorId, $fixture->providerId, $fixture->customerId) + 1000;
    }
}

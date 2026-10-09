<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Regression coverage for cross-type calendar appointment mutations (ROB-802). */
final class CalendarCrossTypeMutationHttpTest extends TestCase
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

    public function testSaveAppointmentKeepsNewAppointmentOrdinaryWhenClientClaimsUnavailability(): void
    {
        $fixture = $this->requireFixture();
        $client = $this->authenticatedClient();
        $before = $this->appointmentSnapshot();

        $valid = $client->post('calendar/save_appointment', [
            'appointment_data' => $this->appointmentPayload('ordinary', false),
            'customer_data' => [],
        ]);
        self::assertSame(200, $valid->statusCode, $valid->body);
        self::assertSame(['success' => true], $this->json($valid));

        $ordinary = $this->rowByNotes($fixture->run . '_ordinary');
        self::assertNotEmpty($ordinary);
        self::assertSame(0, (int) $ordinary['is_unavailability']);
        self::assertSame($fixture->providerId, (int) $ordinary['id_users_provider']);
        self::assertSame($fixture->customerId, (int) $ordinary['id_users_customer']);
        self::assertSame($fixture->serviceId, (int) $ordinary['id_services']);

        $beforeAttack = $this->appointmentSnapshot();
        $attack = $client->post('calendar/save_appointment', [
            'appointment_data' => $this->appointmentPayload('new-unavailability', true),
            'customer_data' => [],
        ]);
        self::assertSame(403, $attack->statusCode, $attack->body);
        $body = $this->json($attack);
        self::assertFalse((bool) ($body['success'] ?? true), $attack->body);
        self::assertSame($beforeAttack, $this->appointmentSnapshot());
        self::assertSame(
            $before,
            array_values(
                array_filter(
                    $this->appointmentSnapshot(),
                    static fn(array $row): bool => ($row['notes'] ?? '') !== $fixture->run . '_ordinary',
                ),
            ),
        );
    }

    public function testAppointmentSaveAndDeleteCannotTargetManualUnavailability(): void
    {
        $fixture = $this->requireFixture();
        $client = $this->authenticatedClient();

        $created = $client->post('calendar/save_unavailability', [
            'unavailability' => [
                'start_datetime' => '2035-09-11 10:00:00',
                'end_datetime' => '2035-09-11 10:30:00',
                'notes' => $fixture->run . '_manual',
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => true,
            ],
        ]);
        self::assertSame(200, $created->statusCode, $created->body);
        self::assertTrue((bool) ($this->json($created)['success'] ?? false), $created->body);
        $manual = $this->rowByNotes($fixture->run . '_manual');
        self::assertNotEmpty($manual);
        self::assertSame(1, (int) $manual['is_unavailability']);
        $manualBefore = $manual;

        $save = $client->post('calendar/save_appointment', [
            'appointment_data' => [
                'id' => (int) $manual['id'],
                'start_datetime' => $manual['start_datetime'],
                'end_datetime' => $manual['end_datetime'],
                'notes' => $fixture->run . '_manual-mutated',
                'id_users_provider' => $fixture->providerId,
                'id_users_customer' => $fixture->customerId,
                'id_services' => $fixture->serviceId,
                'is_unavailability' => false,
            ],
            'customer_data' => [],
        ]);
        self::assertSame(403, $save->statusCode, $save->body);
        self::assertFalse((bool) ($this->json($save)['success'] ?? true), $save->body);
        self::assertSame($manualBefore, $this->rowByNotes($fixture->run . '_manual'));
        self::assertSame([], $this->rowsByNotes($fixture->run . '_manual-mutated'));

        $delete = $client->post('calendar/delete_appointment', ['appointment_id' => (int) $manual['id']]);
        self::assertSame(403, $delete->statusCode, $delete->body);
        self::assertFalse((bool) ($this->json($delete)['success'] ?? true), $delete->body);
        self::assertSame($manualBefore, $this->rowByNotes($fixture->run . '_manual'));

        $aliasClient = new GateHttpClient($this->server->baseUrl, additionalHeaders: ['X-FH-Test' => 'rob-802']);
        self::assertSame(200, $aliasClient->get('login')->statusCode);
        $aliasLogin = $aliasClient->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $aliasLogin->statusCode, $aliasLogin->body);
        self::assertTrue((bool) ($this->json($aliasLogin)['success'] ?? false));
        $alias = $aliasClient->post('backend_api/ajax_save_appointment', [
            'appointment_data' => $this->appointmentPayload('alias', true),
            'customer_data' => [],
        ]);
        self::assertSame(303, $alias->statusCode, $alias->body);
        self::assertStringEndsWith('/calendar/save_appointment', (string) $alias->header('location'));
        self::assertSame($manualBefore, $this->rowByNotes($fixture->run . '_manual'));
    }

    private function requireFixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);
        return $this->fixture;
    }

    private function authenticatedClient(): GateHttpClient
    {
        $fixture = $this->requireFixture();
        $server = $this->server;
        self::assertNotNull($server);
        $client = $server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) ($this->json($response)['success'] ?? false), $response->body);
        return $client;
    }

    private function appointmentPayload(string $suffix, bool $isUnavailability): array
    {
        $fixture = $this->requireFixture();
        return [
            'start_datetime' => '2035-09-12 10:00:00',
            'end_datetime' => '2035-09-12 10:30:00',
            'notes' => $fixture->run . '_' . $suffix,
            'id_users_provider' => $fixture->providerId,
            'id_users_customer' => $fixture->customerId,
            'id_services' => $fixture->serviceId,
            'is_unavailability' => $isUnavailability,
        ];
    }

    /** @return array<string, mixed> */
    private function rowByNotes(string $notes): array
    {
        $rows = $this->rowsByNotes($notes);
        self::assertCount(1, $rows, 'Expected exactly one owned appointment row for ' . $notes);
        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    private function rowsByNotes(string $notes): array
    {
        return get_instance()
            ->db->get_where('appointments', ['notes' => $notes])
            ->result_array();
    }

    /** @return list<array<string, mixed>> */
    private function appointmentSnapshot(): array
    {
        $fixture = $this->requireFixture();
        return get_instance()
            ->db->order_by('id', 'ASC')
            ->get_where('appointments', ['notes LIKE' => $fixture->run . '%'])
            ->result_array();
    }

    /** @return array<string, mixed> */
    private function json(\ReleaseGate\GateHttpResponse $response): array
    {
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }
}

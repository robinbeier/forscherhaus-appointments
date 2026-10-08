<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP method and mutation regression coverage for the legacy calendar writes. */
final class CalendarMutationMethodHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $originalProviderSettings = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->originalProviderSettings = $this->fixture->userSettingsRow($this->fixture->providerId);
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
            if ($this->fixture !== null && $this->originalProviderSettings !== []) {
                get_instance()->db->update(
                    'user_settings',
                    [
                        'working_plan' => $this->originalProviderSettings['working_plan'],
                        'working_plan_exceptions' => $this->originalProviderSettings['working_plan_exceptions'] ?? '{}',
                    ],
                    ['id_users' => $this->fixture->providerId],
                );
            }
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testGetAndHeadCalendarWritesAre405AndAdvertisePostWithoutMutation(): void
    {
        $fixture = $this->fixture;
        $client = $this->authenticatedClient();
        self::assertNotNull($fixture);

        $appointment = $fixture->appointment();
        $unavailability = $this->seedUnavailability();
        $date = '2035-02-14';
        $this->seedWorkingPlanException($date);
        $before = $this->mutationSnapshot((int) $appointment['id'], (int) $unavailability['id'], $date);

        $requests = [
            ['calendar/save_appointment', ['appointment_data' => $this->appointmentPayload(), 'customer_data' => []]],
            ['calendar/delete_appointment', ['appointment_id' => (int) $appointment['id']]],
            ['calendar/save_unavailability', ['unavailability' => $this->unavailabilityPayload()]],
            ['calendar/delete_unavailability', ['unavailability_id' => (int) $unavailability['id']]],
            ['calendar/save_working_plan_exception', $this->workingPlanPayload($date)],
            ['calendar/delete_working_plan_exception', ['provider_id' => $fixture->providerId, 'date' => $date]],
        ];

        foreach ($requests as [$path, $payload]) {
            foreach (['GET', 'HEAD'] as $method) {
                $query = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
                $response = $client->requestApp($method, $path . '?' . $query);
                self::assertSame(405, $response->statusCode, $method . ' ' . $path);
                self::assertSame('POST', strtoupper((string) $response->header('allow')), $method . ' ' . $path);
                self::assertSame(
                    $before,
                    $this->mutationSnapshot((int) $appointment['id'], (int) $unavailability['id'], $date),
                );
            }
        }
    }

    public function testRepresentativePostControlsReachAllSixCalendarWritesAndLegacyAliasesRedirect(): void
    {
        $fixture = $this->fixture;
        $client = $this->authenticatedClient();
        self::assertNotNull($fixture);

        $appointment = $this->json(
            $client->post('calendar/save_appointment', [
                'appointment_data' => $this->appointmentPayload(),
                'customer_data' => [],
            ]),
        );
        self::assertTrue((bool) ($appointment['success'] ?? false));
        $appointmentRows = get_instance()
            ->db->get_where('appointments', ['id_services' => $fixture->serviceId])
            ->result_array();
        self::assertCount(1, $appointmentRows);
        $appointmentId = (int) $appointmentRows[0]['id'];

        $unavailability = $this->json(
            $client->post('calendar/save_unavailability', [
                'unavailability' => $this->unavailabilityPayload(),
            ]),
        );
        self::assertTrue((bool) ($unavailability['success'] ?? false));
        $unavailabilityId = (int) get_instance()
            ->db->order_by('id', 'DESC')
            ->get_where('appointments', [
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
            ])
            ->row_array()['id'];
        self::assertGreaterThan(0, $unavailabilityId);

        $date = '2035-02-15';
        self::assertTrue(
            (bool) ($this->json(
                $client->post('calendar/save_working_plan_exception', $this->workingPlanPayload($date)),
            )['success'] ?? false),
        );
        self::assertArrayHasKey($date, $this->workingPlanExceptions());

        self::assertTrue(
            (bool) ($this->json(
                $client->post('calendar/delete_working_plan_exception', [
                    'provider_id' => $fixture->providerId,
                    'date' => $date,
                ]),
            )['success'] ?? false),
        );
        self::assertArrayNotHasKey($date, $this->workingPlanExceptions());
        self::assertTrue(
            (bool) ($this->json(
                $client->post('calendar/delete_unavailability', [
                    'unavailability_id' => $unavailabilityId,
                ]),
            )['success'] ?? false),
        );
        self::assertSame([], $fixture->row('appointments', $unavailabilityId));
        self::assertTrue(
            (bool) ($this->json(
                $client->post('calendar/delete_appointment', [
                    'appointment_id' => $appointmentId,
                ]),
            )['success'] ?? false),
        );
        self::assertSame([], $fixture->row('appointments', $appointmentId));

        $aliasClient = $this->authenticatedClient(false);
        foreach (
            [
                'ajax_save_appointment' => 'calendar/save_appointment',
                'ajax_delete_appointment' => 'calendar/delete_appointment',
                'ajax_save_unavailability' => 'calendar/save_unavailability',
                'ajax_delete_unavailability' => 'calendar/delete_unavailability',
                'ajax_save_working_plan_exception' => 'calendar/save_working_plan_exception',
                'ajax_delete_working_plan_exception' => 'calendar/delete_working_plan_exception',
            ]
            as $alias => $target
        ) {
            foreach (['GET', 'HEAD', 'POST'] as $method) {
                $response =
                    $method === 'POST'
                        ? $aliasClient->post('backend_api/' . $alias)
                        : $aliasClient->requestApp($method, 'backend_api/' . $alias . '?id=' . $appointmentId);
                self::assertContains($response->statusCode, [301, 302, 303, 307, 308], $method . ' ' . $alias);
                self::assertStringEndsWith(
                    '/' . $target,
                    (string) $response->header('location'),
                    $method . ' ' . $alias,
                );
                self::assertSame([], $fixture->row('appointments', $appointmentId));
                self::assertSame([], $fixture->row('appointments', $unavailabilityId));
            }
        }
    }

    public function testClosingWorkingPlanExceptionChangesPublicHoursAndAuthorizedDeleteRestoresThem(): void
    {
        $fixture = $this->fixture;
        $calendar = $this->authenticatedClient();
        self::assertNotNull($fixture);

        $public = $this->server?->client();
        self::assertNotNull($public);
        self::assertSame(200, $public->get('booking')->statusCode);

        $appointment = $fixture->appointment();
        $unavailability = $this->seedUnavailability();
        $date = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days')->format('Y-m-d');
        $preservedDate = (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');
        $this->seedWorkingPlanException($preservedDate);
        $pair = [
            'provider_id' => $fixture->providerId,
            'service_id' => $fixture->serviceId,
            'selected_date' => $date,
            'manage_mode' => 'false',
            'appointment_id' => '',
        ];

        $before = $this->mutationSnapshot((int) $appointment['id'], (int) $unavailability['id'], $date);
        $availableBefore = $this->availableHours($public, $pair);
        self::assertContains('10:00', $availableBefore, 'The synthetic slot must be public before the exception.');

        $save = $calendar->post('calendar/save_working_plan_exception', [
            'provider_id' => $fixture->providerId,
            'date' => $date,
            'working_plan_exception' => [],
        ]);
        self::assertTrue((bool) ($this->json($save)['success'] ?? false));
        $expectedAfterSave = $before['working_plan_exceptions'];
        $expectedAfterSave[$date] = null;
        self::assertSame($expectedAfterSave, $this->workingPlanExceptions());
        self::assertNotContains('10:00', $this->availableHours($public, $pair));

        $afterSave = $this->mutationSnapshot((int) $appointment['id'], (int) $unavailability['id'], $date);
        self::assertSame($before['appointment'], $afterSave['appointment']);
        self::assertSame($before['unavailability'], $afterSave['unavailability']);
        self::assertSame($before['customer'], $afterSave['customer']);
        self::assertSame($before['service'], $afterSave['service']);

        $delete = $calendar->post('calendar/delete_working_plan_exception', [
            'provider_id' => $fixture->providerId,
            'date' => $date,
        ]);
        self::assertTrue((bool) ($this->json($delete)['success'] ?? false));
        self::assertSame($before['working_plan_exceptions'], $this->workingPlanExceptions());
        self::assertContains('10:00', $this->availableHours($public, $pair));

        $afterDelete = $this->mutationSnapshot((int) $appointment['id'], (int) $unavailability['id'], $date);
        self::assertSame($before['appointment'], $afterDelete['appointment']);
        self::assertSame($before['unavailability'], $afterDelete['unavailability']);
        self::assertSame($before['customer'], $afterDelete['customer']);
        self::assertSame($before['service'], $afterDelete['service']);
    }

    private function authenticatedClient(bool $followRedirects = true): GateHttpClient
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $followRedirects
            ? $this->server?->client()
            : new GateHttpClient($this->server->baseUrl, additionalHeaders: ['X-FH-Test' => 'calendar-method']);
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $login = $client->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $login->statusCode, $login->body);
        self::assertTrue((bool) (json_decode($login->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function appointmentPayload(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        return [
            'start_datetime' => date('Y-m-d 10:00:00', strtotime('+14 days')),
            'end_datetime' => date('Y-m-d 10:30:00', strtotime('+14 days')),
            'notes' => $fixture->run,
            'id_users_provider' => $fixture->providerId,
            'id_users_customer' => $fixture->customerId,
            'id_services' => $fixture->serviceId,
            'is_unavailability' => false,
        ];
    }

    private function unavailabilityPayload(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        return [
            'start_datetime' => date('Y-m-d 12:00:00', strtotime('+14 days')),
            'end_datetime' => date('Y-m-d 12:30:00', strtotime('+14 days')),
            'notes' => $fixture->run . '_unavailability',
            'id_users_provider' => $fixture->providerId,
            'is_unavailability' => true,
        ];
    }

    private function workingPlanPayload(string $date): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        return [
            'provider_id' => $fixture->providerId,
            'date' => $date,
            'working_plan_exception' => ['start' => '10:00', 'end' => '12:00', 'breaks' => []],
        ];
    }

    private function seedUnavailability(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        get_instance()->db->insert(
            'appointments',
            array_merge($this->unavailabilityPayload(), [
                'id_services' => $fixture->serviceId,
                'id_users_customer' => $fixture->customerId,
            ]),
        );
        $id = (int) get_instance()->db->insert_id();
        $row = $fixture->row('appointments', $id);
        self::assertNotEmpty($row);
        return $row;
    }

    private function seedWorkingPlanException(string $date): void
    {
        get_instance()->load->model('providers_model');
        get_instance()->providers_model->save_working_plan_exception($this->fixture->providerId, $date, [
            'start' => '09:00',
            'end' => '11:00',
            'breaks' => [],
        ]);
    }

    private function workingPlanExceptions(): array
    {
        $row = $this->fixture->userSettingsRow($this->fixture->providerId);
        return json_decode((string) ($row['working_plan_exceptions'] ?? '{}'), true) ?: [];
    }

    private function mutationSnapshot(int $appointmentId, int $unavailabilityId, string $date): array
    {
        return [
            'appointment' => $this->fixture->row('appointments', $appointmentId),
            'unavailability' => $this->fixture->row('appointments', $unavailabilityId),
            'customer' => $this->fixture->row('users', $this->fixture->customerId),
            'service' => $this->fixture->row('services', $this->fixture->serviceId),
            'working_plan_exceptions' => $this->workingPlanExceptions(),
            'date' => $date,
        ];
    }

    private function json(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode, $response->body);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @param array<string, string|int> $payload @return list<string> */
    private function availableHours(GateHttpClient $client, array $payload): array
    {
        $response = $client->post('booking/get_available_hours', $payload);
        self::assertSame(200, $response->statusCode, $response->body);
        $hours = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($hours);
        return array_values(array_map('strval', $hours));
    }
}

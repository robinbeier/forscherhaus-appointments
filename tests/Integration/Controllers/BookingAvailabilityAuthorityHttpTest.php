<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP regression for appointment-bound availability read authority. */
final class BookingAvailabilityAuthorityHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?array $providerSettingsBefore = null;

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
            if ($this->providerSettingsBefore !== null && $this->fixture !== null) {
                get_instance()->db->update(
                    'user_settings',
                    ['working_plan' => $this->providerSettingsBefore['working_plan']],
                    ['id_users' => $this->fixture->providerId],
                );
            }
            $this->fixture?->cleanup();
        }
    }

    public function testAvailabilityReadsRequireTheExactSessionAuthorityAndRemainReadOnly(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);

        $db = get_instance()->db;
        $appointment = $fixture->appointment();
        $appointmentId = (int) $appointment['id'];
        $scenario = $this->occupyEverySlot($fixture, $appointment);
        $foreign = $this->createAppointmentAt(
            $fixture,
            (new DateTimeImmutable($scenario['date']))->modify('+1 day')->format('Y-m-d'),
            $scenario['hour'],
        );
        $beforeAppointment = $fixture->row('appointments', $appointmentId);

        $untrusted = $server->client();
        $authorized = $server->client();
        self::assertSame(200, $untrusted->get('booking')->statusCode);
        self::assertSame(
            200,
            $authorized->get('booking/reschedule/' . rawurlencode((string) $appointment['hash']))->statusCode,
        );
        self::assertNotNull($untrusted->getCookie('ea_session'));
        self::assertNotNull($authorized->getCookie('ea_session'));
        self::assertNotSame($untrusted->getCookie('ea_session'), $authorized->getCookie('ea_session'));
        $beforeAuthority = $this->authorityRow($db, $appointmentId);
        self::assertNotEmpty($beforeAuthority);

        $variants = [
            'real id' => ['manage_mode' => '1', 'appointment_id' => (string) $appointmentId],
            'omitted id' => ['manage_mode' => '1'],
            'malformed id' => ['manage_mode' => '1', 'appointment_id' => 'not-an-id'],
            'foreign id' => ['manage_mode' => '1', 'appointment_id' => (string) $foreign['id']],
            'raw authority token' => [
                'manage_mode' => '1',
                'appointment_id' => (string) $appointmentId,
                'reschedule_authority' => str_repeat('A', 43),
            ],
        ];

        foreach ($variants as $name => $extra) {
            $this->assertUnavailableWithoutAuthority(
                $untrusted,
                $scenario,
                $extra,
                $name . ' must not exclude the booked slot',
            );
        }

        foreach (['omitted id', 'malformed id', 'foreign id'] as $name) {
            $this->assertUnavailableWithoutAuthority(
                $authorized,
                $scenario,
                $variants[$name],
                $name . ' must not bypass exact ID binding in an authorized session',
            );
        }

        $this->assertAvailableWithAuthority($authorized, $scenario, $appointmentId);
        $this->assertDateAvailableWithAuthority($authorized, $scenario, $appointmentId);

        self::assertSame($beforeAppointment, $fixture->row('appointments', $appointmentId));
        self::assertSame($beforeAuthority, $this->authorityRow($db, $appointmentId));
    }

    /** @param array{provider_id:int,service_id:int,date:string,hour:string,hours:list<string>} $scenario */
    private function assertUnavailableWithoutAuthority(
        GateHttpClient $client,
        array $scenario,
        array $extra,
        string $message,
    ): void {
        $available = $client->requestApp('POST', 'booking/get_available_hours', [
            'provider_id' => (string) $scenario['provider_id'],
            'service_id' => (string) $scenario['service_id'],
            'selected_date' => $scenario['date'],
            ...$extra,
        ]);
        self::assertSame(200, $available->statusCode, $available->body);
        $hours = json_decode($available->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($hours);
        self::assertNotContains($scenario['hour'], $hours, $message);

        $dates = $client->get('booking/get_unavailable_dates', [
            'provider_id' => (string) $scenario['provider_id'],
            'service_id' => (string) $scenario['service_id'],
            'selected_date' => (new DateTimeImmutable($scenario['date']))
                ->modify('first day of this month')
                ->format('Y-m-d'),
            ...$extra,
        ]);
        self::assertSame(200, $dates->statusCode, $dates->body);
        $unavailable = json_decode($dates->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($unavailable);
        if (($unavailable['is_month_unavailable'] ?? false) !== true) {
            self::assertContains($scenario['date'], $unavailable, $message);
        }
    }

    /** @param array{provider_id:int,service_id:int,date:string,hour:string} $scenario */
    private function assertAvailableWithAuthority(GateHttpClient $client, array $scenario, int $appointmentId): void
    {
        $response = $client->requestApp('POST', 'booking/get_available_hours', [
            'provider_id' => (string) $scenario['provider_id'],
            'service_id' => (string) $scenario['service_id'],
            'selected_date' => $scenario['date'],
            'manage_mode' => '1',
            'appointment_id' => (string) $appointmentId,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        $hours = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($hours);
        self::assertContains($scenario['hour'], $hours);
    }

    /** @param array{provider_id:int,service_id:int,date:string} $scenario */
    private function assertDateAvailableWithAuthority(GateHttpClient $client, array $scenario, int $appointmentId): void
    {
        $response = $client->get('booking/get_unavailable_dates', [
            'provider_id' => (string) $scenario['provider_id'],
            'service_id' => (string) $scenario['service_id'],
            'selected_date' => (new DateTimeImmutable($scenario['date']))
                ->modify('first day of this month')
                ->format('Y-m-d'),
            'manage_mode' => '1',
            'appointment_id' => (string) $appointmentId,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        $dates = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($dates);
        self::assertFalse($dates['is_month_unavailable'] ?? false);
        self::assertNotContains($scenario['date'], $dates);
    }

    /** @return array{provider_id:int,service_id:int,date:string,hour:string,hours:list<string>} */
    private function occupyEverySlot(DefenseCycleFixtures $fixture, array $appointment): array
    {
        $ci = get_instance();
        $ci->load->model('services_model');
        $ci->load->model('providers_model');
        $ci->load->library('availability');
        $date = (new DateTimeImmutable((string) $appointment['start_datetime']))->format('Y-m-d');
        $service = $ci->services_model->find($fixture->serviceId);
        $provider = $ci->providers_model->find($fixture->providerId);
        $settings = $ci->db->get_where('user_settings', ['id_users' => $fixture->providerId])->row_array();
        self::assertIsArray($settings);
        $this->providerSettingsBefore = $settings;
        $plan = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $plan[$day] = ['start' => '00:00', 'end' => '00:00', 'breaks' => []];
        }
        $plan[strtolower((new DateTimeImmutable($date))->format('l'))] = [
            'start' => '08:00',
            'end' => '08:30',
            'breaks' => [],
        ];
        self::assertTrue(
            $ci->db->update(
                'user_settings',
                ['working_plan' => json_encode($plan, JSON_THROW_ON_ERROR)],
                ['id_users' => $fixture->providerId],
            ),
        );
        $provider = $ci->providers_model->find($fixture->providerId);
        $hours = array_values($ci->availability->get_available_hours($date, $service, $provider));
        self::assertNotEmpty($hours);
        $hour = (string) $hours[0];
        $ci->db->update(
            'appointments',
            [
                'start_datetime' => $date . ' ' . $hour . ':00',
                'end_datetime' => (new DateTimeImmutable($date . ' ' . $hour . ':00'))
                    ->modify('+30 minutes')
                    ->format('Y-m-d H:i:s'),
            ],
            ['id' => (int) $appointment['id']],
        );
        foreach (array_slice($hours, 1) as $slot) {
            $this->createAppointmentAt($fixture, $date, (string) $slot);
        }

        return [
            'provider_id' => $fixture->providerId,
            'service_id' => $fixture->serviceId,
            'date' => $date,
            'hour' => $hour,
            'hours' => array_map('strval', $hours),
        ];
    }

    private function createAppointmentAt(DefenseCycleFixtures $fixture, string $date, string $hour): array
    {
        $start = new DateTimeImmutable($date . ' ' . $hour . ':00');
        $id = get_instance()->appointments_model->save([
            'start_datetime' => $start->format('Y-m-d H:i:s'),
            'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'notes' => $fixture->run,
            'is_unavailability' => false,
            'id_users_provider' => $fixture->providerId,
            'id_users_customer' => $fixture->customerId,
            'id_services' => $fixture->serviceId,
        ]);
        return get_instance()->appointments_model->find($id);
    }

    private function authorityRow(object $db, int $appointmentId): array
    {
        return $db->get_where('reschedule_authorities', ['appointment_id' => $appointmentId])->row_array() ?? [];
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP regression for public availability and booking against global blocked periods. */
final class BookingBlockedPeriodsHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?GateHttpClient $client = null;
    /** @var list<int> */
    private array $blockedPeriodIds = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->server = new DefenseCycleHttpServer();
            $this->client = $this->server->client();
            self::assertSame(200, $this->client->get('booking')->statusCode);
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
            try {
                $db = get_instance()->db;
                foreach ($this->blockedPeriodIds as $id) {
                    $db->delete('blocked_periods', ['id' => $id]);
                    self::assertSame([], $this->fixture?->blockedPeriodRow($id));
                }
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testPublicAvailabilityAndBookingAgreeAcrossAFullPartialAndBoundaryBlock(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $pair = ['provider_id' => $fixture->providerId, 'service_id' => $fixture->serviceId];
        $weekStart = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days');
        $before = $weekStart->modify('-7 days')->format('Y-m-d');
        $during = $weekStart->format('Y-m-d');
        $after = $weekStart->modify('+7 days')->format('Y-m-d');

        $beforeHours = $this->availableHours($client, $pair, $before);
        $duringHours = $this->availableHours($client, $pair, $during);
        $afterHours = $this->availableHours($client, $pair, $after);
        self::assertNotEmpty($beforeHours, 'The pre-week positive control must expose a slot.');
        self::assertNotEmpty($duringHours, 'The in-week positive control must expose a slot.');
        self::assertNotEmpty($afterHours, 'The post-week positive control must expose a slot.');

        $fullBlock = $this->insertBlockedPeriod(
            $weekStart->format('Y-m-d 00:00:00'),
            $weekStart->add(new DateInterval('P5D'))->format('Y-m-d 00:00:00'),
            'full-week',
        );
        self::assertSame([], $this->availableHours($client, $pair, $during));
        self::assertNotEmpty($this->availableHours($client, $pair, $before));
        self::assertNotEmpty($this->availableHours($client, $pair, $after));

        $db = get_instance()->db;
        self::assertTrue($db->delete('blocked_periods', ['id' => $fullBlock]));
        self::assertSame([], $fixture->blockedPeriodRow($fullBlock));
        $this->blockedPeriodIds = array_values(array_diff($this->blockedPeriodIds, [$fullBlock]));

        $targetHour = $duringHours[0];
        $targetStart = new DateTimeImmutable($during . ' ' . $targetHour . ':00');
        $targetEnd = $targetStart->add(new DateInterval('PT30M'));
        $partialBlock = $this->insertBlockedPeriod(
            $targetStart->format('Y-m-d H:i:s'),
            $targetEnd->format('Y-m-d H:i:s'),
            'partial-slot',
        );
        self::assertNotContains($targetHour, $this->availableHours($client, $pair, $during));
        $beforeCounts = $this->mutationCounts();
        $denied = $client->post('booking/register', [
            'post_data' => $this->bookingPayload($pair, $targetStart, 'blocked'),
        ]);
        self::assertSame(409, $denied->statusCode, $denied->body);
        self::assertSame($beforeCounts, $this->mutationCounts());
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('users', ['email' => $this->customerEmail('blocked')])
                ->num_rows(),
        );

        self::assertTrue($db->delete('blocked_periods', ['id' => $partialBlock]));
        self::assertSame([], $fixture->blockedPeriodRow($partialBlock));
        $this->blockedPeriodIds = array_values(array_diff($this->blockedPeriodIds, [$partialBlock]));

        $boundaryBlock = $this->insertBlockedPeriod(
            $targetEnd->format('Y-m-d H:i:s'),
            $targetEnd->add(new DateInterval('PT30M'))->format('Y-m-d H:i:s'),
            'exclusive-boundary',
        );
        $boundaryHours = $this->availableHours($client, $pair, $during);
        self::assertContains($targetHour, $boundaryHours);
        self::assertSame(
            200,
            $client->post('booking/register', [
                'post_data' => $this->bookingPayload($pair, $targetStart, 'boundary'),
            ])->statusCode,
        );
        self::assertTrue($db->delete('blocked_periods', ['id' => $boundaryBlock]));
        self::assertSame([], $fixture->blockedPeriodRow($boundaryBlock));
        $this->blockedPeriodIds = array_values(array_diff($this->blockedPeriodIds, [$boundaryBlock]));
    }

    public function testSubMinuteGlobalBlockRemovesMinuteSlotAndRejectsExactPostWithoutMutation(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $pair = ['provider_id' => $fixture->providerId, 'service_id' => $fixture->serviceId];
        $date = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days')->format('Y-m-d');
        $hours = $this->availableHours($client, $pair, $date);
        self::assertNotEmpty($hours);
        $slot = new DateTimeImmutable($date . ' ' . $hours[0] . ':00');
        $block = $this->insertBlockedPeriod(
            $slot->modify('-30 seconds')->format('Y-m-d H:i:s'),
            $slot->modify('+30 seconds')->format('Y-m-d H:i:s'),
            'sub-minute-end',
        );

        self::assertNotContains($hours[0], $this->availableHours($client, $pair, $date));
        $beforeCounts = $this->mutationCounts();
        $denied = $client->post('booking/register', ['post_data' => $this->bookingPayload($pair, $slot, 'sub-minute')]);
        self::assertSame(409, $denied->statusCode, $denied->body);
        self::assertSame($beforeCounts, $this->mutationCounts());
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('users', ['email' => $this->customerEmail('sub-minute')])
                ->num_rows(),
        );
        self::assertTrue(get_instance()->db->delete('blocked_periods', ['id' => $block]));
        self::assertSame([], $fixture->blockedPeriodRow($block));
        $this->blockedPeriodIds = array_values(array_diff($this->blockedPeriodIds, [$block]));
    }

    public function testNonzeroSecondsStartIsRejectedBeforeAvailabilityWithoutMutation(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $pair = ['provider_id' => $fixture->providerId, 'service_id' => $fixture->serviceId];
        $date = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days')->format('Y-m-d');
        $hours = $this->availableHours($client, $pair, $date);
        self::assertNotEmpty($hours);
        $secondsSlot = new DateTimeImmutable($date . ' ' . ($hours[1] ?? $hours[0]) . ':00');
        $secondsBlock = $this->insertBlockedPeriod(
            $secondsSlot->add(new DateInterval('PT30M'))->format('Y-m-d H:i:s'),
            $secondsSlot->add(new DateInterval('PT60M'))->format('Y-m-d H:i:s'),
            'seconds-overlap',
        );
        $beforeCounts = $this->mutationCounts();
        $secondsDenied = $client->post('booking/register', [
            'post_data' => $this->bookingPayload($pair, $secondsSlot->modify('+59 seconds'), 'seconds'),
        ]);
        self::assertSame(409, $secondsDenied->statusCode, $secondsDenied->body);
        self::assertSame($beforeCounts, $this->mutationCounts());
        self::assertTrue(get_instance()->db->delete('blocked_periods', ['id' => $secondsBlock]));
        self::assertSame([], $fixture->blockedPeriodRow($secondsBlock));
        $this->blockedPeriodIds = array_values(array_diff($this->blockedPeriodIds, [$secondsBlock]));
    }

    public function testNonzeroSecondsRescheduleIsRejectedBeforeAuthorityClaim(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $appointment = $fixture->appointment();
        $customer = $fixture->row('users', $fixture->customerId);
        $beforeAppointment = $fixture->row('appointments', (int) $appointment['id']);
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $beforeCounts = $this->mutationCounts();
        self::assertSame(200, $client->get('booking/reschedule/' . $appointment['hash'])->statusCode);

        $start = (new DateTimeImmutable('today'))->modify('next monday')->modify('+21 days')->setTime(8, 0, 59);
        $payload = $this->reschedulePayload($appointment, $customer, $start);
        $denied = $client->post('booking/register', ['post_data' => $payload]);
        self::assertSame(409, $denied->statusCode, $denied->body);
        self::assertSame($beforeAppointment, $fixture->row('appointments', (int) $appointment['id']));
        self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
        self::assertSame($beforeCounts, $this->mutationCounts());

        $payload['appointment']['start_datetime'] = $start->setTime(8, 0, 0)->format('Y-m-d H:i:s');
        $payload['appointment']['end_datetime'] = $start->setTime(8, 30, 0)->format('Y-m-d H:i:s');
        $success = $client->post('booking/register', ['post_data' => $payload]);
        self::assertSame(200, $success->statusCode, $success->body);
        self::assertSame(
            $payload['appointment']['start_datetime'],
            $fixture->row('appointments', (int) $appointment['id'])['start_datetime'],
        );
    }

    /** @return list<string> */
    private function availableHours(GateHttpClient $client, array $pair, string $date): array
    {
        $response = $client->post('booking/get_available_hours', [
            'provider_id' => $pair['provider_id'],
            'service_id' => $pair['service_id'],
            'selected_date' => $date,
            'manage_mode' => 'false',
            'appointment_id' => '',
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        $hours = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($hours);
        return array_values(array_map('strval', $hours));
    }

    private function insertBlockedPeriod(string $start, string $end, string $case): int
    {
        $name = $this->fixture?->run . '_booking_' . $case;
        self::assertNotEmpty($name);
        $db = get_instance()->db;
        self::assertTrue(
            $db->insert('blocked_periods', [
                'name' => $name,
                'start_datetime' => $start,
                'end_datetime' => $end,
                'notes' => $this->fixture?->run,
            ]),
        );
        $id = (int) $db->insert_id();
        self::assertGreaterThan(0, $id);
        $this->blockedPeriodIds[] = $id;
        return $id;
    }

    /** @return array{appointment:array<string,mixed>,customer:array<string,mixed>,manage_mode:bool} */
    private function bookingPayload(array $pair, DateTimeImmutable $start, string $case): array
    {
        return [
            'appointment' => [
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->add(new DateInterval('PT30M'))->format('Y-m-d H:i:s'),
                'id_services' => $pair['service_id'],
                'id_users_provider' => $pair['provider_id'],
                'location' => '',
                'notes' => $this->fixture?->run,
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Synthetic',
                'last_name' => 'Blocked ' . $case,
                'email' => $this->customerEmail($case),
                'phone_number' => '000000000',
                'address' => '',
                'city' => '',
                'zip_code' => '',
                'timezone' => 'UTC',
                'notes' => $this->fixture?->run,
            ],
            'manage_mode' => false,
        ];
    }

    /** @return array{appointment:array<string,mixed>,customer:array<string,mixed>,manage_mode:bool} */
    private function reschedulePayload(array $appointment, array $customer, DateTimeImmutable $start): array
    {
        return [
            'appointment' => [
                'id' => (int) $appointment['id'],
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => (int) $appointment['id_services'],
                'id_users_provider' => (int) $appointment['id_users_provider'],
                'location' => '',
                'notes' => $this->fixture?->run,
                'color' => '',
            ],
            'customer' => [
                'id' => (int) $customer['id'],
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'email' => $customer['email'],
                'phone_number' => $customer['phone_number'],
                'address' => $customer['address'],
                'city' => $customer['city'],
                'zip_code' => $customer['zip_code'],
                'timezone' => $customer['timezone'],
                'notes' => $customer['notes'],
            ],
            'manage_mode' => true,
        ];
    }

    private function customerEmail(string $case): string
    {
        if ($case === 'boundary') {
            return $this->fixture?->run . '_customer@synthetic.invalid';
        }
        return $this->fixture?->run . '_booking_' . $case . '@synthetic.invalid';
    }

    /** @return array{appointments:int,users:int,consents:int} */
    private function mutationCounts(): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'consents' => $db->count_all('consents'),
        ];
    }
}

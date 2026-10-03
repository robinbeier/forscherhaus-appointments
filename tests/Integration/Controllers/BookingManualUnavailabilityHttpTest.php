<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP regression for provider-owned manual unavailability in public booking. */
final class BookingManualUnavailabilityHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?GateHttpClient $client = null;
    private int $manualUnavailabilityId = 0;
    private int $positiveAppointmentId = 0;
    private int $positiveCustomerId = 0;
    private string $positiveStartDatetime = '';
    private int $baselineConsentCount = 0;
    private ?array $displayEmailSetting = null;
    private ?array $requireEmailSetting = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->baselineConsentCount = $db->count_all('consents');
            $this->displayEmailSetting = $db->get_where('settings', ['name' => 'display_email'])->row_array() ?: null;
            $this->requireEmailSetting = $db->get_where('settings', ['name' => 'require_email'])->row_array() ?: null;
            $db->update('settings', ['value' => '0'], ['name' => 'display_email']);
            $db->update('settings', ['value' => '0'], ['name' => 'require_email']);
            $this->server = new DefenseCycleHttpServer();
            $this->client = $this->server->client();
            self::assertSame(200, $this->client->get('booking')->statusCode);
        } catch (Throwable $error) {
            $this->server?->close();
            try {
                try {
                    $this->cleanupOwnedRows();
                } finally {
                    $this->restoreEmailSettings();
                }
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
            try {
                $this->cleanupOwnedRows();
            } finally {
                try {
                    $this->restoreEmailSettings();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
        }
    }

    public function testPublicBookingHonorsOwnManualUnavailabilityWithoutMutatingRows(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $pair = ['provider_id' => $fixture->providerId, 'service_id' => $fixture->serviceId];
        $date = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days')->format('Y-m-d');
        $beforeHours = $this->availableHours($client, $pair, $date);
        self::assertGreaterThanOrEqual(2, count($beforeHours), 'Need adjacent positive-control slots.');
        $targetHour = $beforeHours[0];
        $targetStart = new DateTimeImmutable($date . ' ' . $targetHour . ':00');
        $targetEnd = $targetStart->add(new DateInterval('PT30M'));
        $adjacentHour = null;
        foreach ($beforeHours as $hour) {
            if (new DateTimeImmutable($date . ' ' . $hour . ':00') >= $targetEnd) {
                $adjacentHour = $hour;
                break;
            }
        }
        self::assertNotNull($adjacentHour, 'Need a permitted slot after the manual block.');

        $this->manualUnavailabilityId = $this->insertManualUnavailability($targetStart);
        $manualBefore = $fixture->row('appointments', $this->manualUnavailabilityId);
        self::assertNotContains($targetHour, $this->availableHours($client, $pair, $date));
        self::assertContains($adjacentHour, $this->availableHours($client, $pair, $date));

        $before = $this->mutationSnapshot();
        $denied = $client->post('booking/register', [
            'post_data' => $this->bookingPayload($pair, $targetStart, 'manual-blocked'),
        ]);
        self::assertSame(409, $denied->statusCode, $denied->body);
        self::assertSame($before, $this->mutationSnapshot());
        self::assertSame($manualBefore, $fixture->row('appointments', $this->manualUnavailabilityId));
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('users', ['last_name' => 'Manual ' . $fixture->run . ' manual-blocked'])
                ->num_rows(),
        );

        $db = get_instance()->db;
        self::assertTrue($db->delete('appointments', ['id' => $this->manualUnavailabilityId]));
        self::assertSame([], $fixture->row('appointments', $this->manualUnavailabilityId));
        $this->manualUnavailabilityId = 0;
        self::assertContains($targetHour, $this->availableHours($client, $pair, $date));

        $this->positiveStartDatetime = $targetStart->format('Y-m-d H:i:s');
        $success = $client->post('booking/register', [
            'post_data' => $this->bookingPayload($pair, $targetStart, 'manual-cleared'),
        ]);
        self::assertSame(200, $success->statusCode, $success->body);
        $booked = get_instance()
            ->db->get_where('appointments', [
                'id_users_provider' => $fixture->providerId,
                'id_services' => $fixture->serviceId,
                'start_datetime' => $targetStart->format('Y-m-d H:i:s'),
                'notes' => $fixture->run,
            ])
            ->result_array();
        self::assertCount(1, $booked);
        $this->positiveAppointmentId = (int) $booked[0]['id'];
        $this->positiveCustomerId = (int) $booked[0]['id_users_customer'];
        self::assertGreaterThan(0, $this->positiveCustomerId);
        self::assertSame(
            'Manual ' . $fixture->run . ' manual-cleared',
            $fixture->row('users', $this->positiveCustomerId)['last_name'],
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

    private function insertManualUnavailability(DateTimeImmutable $start): int
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        self::assertTrue(
            $db->insert('appointments', [
                'book_datetime' => date('Y-m-d H:i:s'),
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->add(new DateInterval('PT30M'))->format('Y-m-d H:i:s'),
                'notes' => $fixture->run . '_manual_unavailability',
                'hash' => $fixture->run . '_manual_unavailability_hash',
                'is_unavailability' => 1,
                'id_users_provider' => $fixture->providerId,
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]),
        );
        $id = (int) $db->insert_id();
        self::assertGreaterThan(0, $id);
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
                'last_name' => 'Manual ' . $this->fixture?->run . ' ' . $case,
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

    /** @return array<string,mixed> */
    private function mutationSnapshot(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        return [
            'counts' => [
                'appointments' => $db->count_all('appointments'),
                'users' => $db->count_all('users'),
                'consents' => $db->count_all('consents'),
            ],
            'provider' => $fixture->row('users', $fixture->providerId),
            'customer' => $fixture->row('users', $fixture->customerId),
            'service' => $fixture->row('services', $fixture->serviceId),
            'manual' => $fixture->row('appointments', $this->manualUnavailabilityId),
        ];
    }

    private function cleanupOwnedRows(): void
    {
        $fixture = $this->fixture;
        if ($fixture === null || !isset($fixture->providerId)) {
            return;
        }
        $db = get_instance()->db;
        if ($this->manualUnavailabilityId > 0) {
            $db->delete('appointments', [
                'id' => $this->manualUnavailabilityId,
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
            ]);
            self::assertSame([], $fixture->row('appointments', $this->manualUnavailabilityId));
            $this->manualUnavailabilityId = 0;
        }
        $ownedBookings =
            $this->positiveAppointmentId > 0
                ? $db->get_where('appointments', ['id' => $this->positiveAppointmentId])->result_array()
                : $db
                    ->get_where('appointments', [
                        'id_users_provider' => $fixture->providerId,
                        'id_services' => $fixture->serviceId,
                        'start_datetime' => $this->positiveStartDatetime,
                        'notes' => $fixture->run,
                        'is_unavailability' => 0,
                    ])
                    ->result_array();
        foreach ($ownedBookings as $row) {
            if (
                (int) $row['id_users_provider'] !== $fixture->providerId ||
                (int) $row['id_services'] !== $fixture->serviceId ||
                (string) $row['notes'] !== $fixture->run ||
                (int) $row['is_unavailability'] !== 0
            ) {
                continue;
            }
            $appointmentId = (int) $row['id'];
            $customerId = (int) $row['id_users_customer'];
            $db->delete('reschedule_authorities', ['appointment_id' => $appointmentId]);
            $db->delete('appointments', [
                'id' => $appointmentId,
                'id_users_provider' => $fixture->providerId,
                'notes' => $fixture->run,
            ]);
            $db->delete('consents', [
                'first_name' => 'Synthetic',
                'last_name' => 'Manual ' . $fixture->run . ' manual-cleared',
            ]);
            $db->delete('users', [
                'id' => $customerId,
                'first_name' => 'Synthetic',
                'last_name' => 'Manual ' . $fixture->run . ' manual-cleared',
            ]);
            self::assertSame([], $fixture->row('appointments', $appointmentId));
            self::assertSame([], $fixture->row('users', $customerId));
            self::assertSame(
                0,
                $db
                    ->get_where('consents', [
                        'first_name' => 'Synthetic',
                        'last_name' => 'Manual ' . $fixture->run . ' manual-cleared',
                    ])
                    ->num_rows(),
            );
            $this->positiveAppointmentId = 0;
            $this->positiveCustomerId = 0;
        }
        $db->delete('consents', [
            'first_name' => 'Synthetic',
            'last_name' => 'Manual ' . $fixture->run . ' manual-blocked',
        ]);
        self::assertSame($this->baselineConsentCount, $db->count_all('consents'));
    }

    private function restoreEmailSettings(): void
    {
        $db = get_instance()->db;
        foreach (
            ['display_email' => $this->displayEmailSetting, 'require_email' => $this->requireEmailSetting]
            as $name => $row
        ) {
            if ($row !== null) {
                $db->update('settings', ['value' => $row['value']], ['name' => $name]);
            }
        }
    }
}

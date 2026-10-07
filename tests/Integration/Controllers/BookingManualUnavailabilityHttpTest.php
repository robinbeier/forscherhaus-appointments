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
    private int $baselineConsentCount = 0;
    private ?array $displayEmailSetting = null;
    private ?array $requireEmailSetting = null;
    private ?array $privacyPolicySetting = null;
    private ?array $termsSetting = null;

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
            $this->privacyPolicySetting =
                $db->get_where('settings', ['name' => 'display_privacy_policy'])->row_array() ?: null;
            $this->termsSetting =
                $db->get_where('settings', ['name' => 'display_terms_and_conditions'])->row_array() ?: null;
            $this->displayEmailSetting = $db->get_where('settings', ['name' => 'display_email'])->row_array() ?: null;
            $this->requireEmailSetting = $db->get_where('settings', ['name' => 'require_email'])->row_array() ?: null;
            $db->update('settings', ['value' => '1'], ['name' => 'display_privacy_policy']);
            $db->update('settings', ['value' => '0'], ['name' => 'display_terms_and_conditions']);
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
                    $this->restoreSettings();
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
                    $this->restoreSettings();
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

        $calendar = $this->authenticatedProviderClient();
        $save = $calendar->post('calendar/save_unavailability', [
            'unavailability' => [
                'start_datetime' => $targetStart->format('Y-m-d H:i:s'),
                'end_datetime' => $targetEnd->format('Y-m-d H:i:s'),
                'notes' => $fixture->run . '_manual_unavailability',
                'id_users_provider' => $fixture->providerId,
            ],
        ]);
        self::assertSame(200, $save->statusCode, $save->body);
        self::assertTrue((bool) (json_decode($save->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        $manualRows = get_instance()
            ->db->get_where('appointments', [
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
                'start_datetime' => $targetStart->format('Y-m-d H:i:s'),
                'end_datetime' => $targetEnd->format('Y-m-d H:i:s'),
                'notes' => $fixture->run . '_manual_unavailability',
            ])
            ->result_array();
        self::assertCount(1, $manualRows);
        $this->manualUnavailabilityId = (int) $manualRows[0]['id'];
        self::assertGreaterThan(0, $this->manualUnavailabilityId);
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

        $delete = $calendar->post('calendar/delete_unavailability', [
            'unavailability_id' => $this->manualUnavailabilityId,
        ]);
        self::assertSame(200, $delete->statusCode, $delete->body);
        self::assertTrue((bool) (json_decode($delete->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        self::assertSame([], $fixture->row('appointments', $this->manualUnavailabilityId));
        $this->manualUnavailabilityId = 0;
        self::assertContains($targetHour, $this->availableHours($client, $pair, $date));

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
        $positiveCustomerId = (int) $booked[0]['id_users_customer'];
        self::assertGreaterThan(0, $positiveCustomerId);
        self::assertSame(
            'Manual ' . $fixture->run . ' manual-cleared',
            $fixture->row('users', $positiveCustomerId)['last_name'],
        );
        self::assertSame(
            1,
            get_instance()
                ->db->get_where('consents', [
                    'first_name' => 'Synthetic',
                    'last_name' => 'Manual ' . $fixture->run . ' manual-cleared',
                    'type' => 'privacy-policy',
                ])
                ->num_rows(),
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

    private function authenticatedProviderClient(): GateHttpClient
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        self::assertNotNull($this->server);
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $login = $client->post('login/validate', [
            'username' => $fixture->run . '_provider',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $login->statusCode, $login->body);
        self::assertTrue((bool) (json_decode($login->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
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
        $manualQuery = [
            'id_users_provider' => $fixture->providerId,
            'is_unavailability' => 1,
            'notes' => $fixture->run . '_manual_unavailability',
        ];
        if ($this->manualUnavailabilityId > 0) {
            $manualQuery['id'] = $this->manualUnavailabilityId;
        }
        $manualRows = $db->get_where('appointments', $manualQuery)->result_array();
        foreach ($manualRows as $manualRow) {
            $manualId = (int) $manualRow['id'];
            $db->delete('appointments', [
                'id' => $manualId,
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
                'notes' => $fixture->run . '_manual_unavailability',
            ]);
            self::assertSame([], $fixture->row('appointments', $manualId));
        }
        if ($this->manualUnavailabilityId > 0) {
            self::assertSame([], $fixture->row('appointments', $this->manualUnavailabilityId));
            $this->manualUnavailabilityId = 0;
        }
        $ownedBookings = $db
            ->get_where('appointments', [
                'id_users_provider' => $fixture->providerId,
                'id_services' => $fixture->serviceId,
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
            $customer = $customerId > 0 ? $db->get_where('users', ['id' => $customerId])->row_array() : [];
            $ownedLastNames = [
                'Manual ' . $fixture->run . ' manual-blocked',
                'Manual ' . $fixture->run . ' manual-cleared',
            ];
            if (
                ($customer['first_name'] ?? null) !== 'Synthetic' ||
                !in_array($customer['last_name'] ?? null, $ownedLastNames, true)
            ) {
                continue;
            }
            $db->delete('reschedule_authorities', ['appointment_id' => $appointmentId]);
            $db->delete('appointments', [
                'id' => $appointmentId,
                'id_users_provider' => $fixture->providerId,
                'notes' => $fixture->run,
            ]);
            $db->delete('consents', [
                'first_name' => 'Synthetic',
                'last_name' => $customer['last_name'],
            ]);
            $db->delete('users', [
                'id' => $customerId,
                'first_name' => 'Synthetic',
                'last_name' => $customer['last_name'],
            ]);
            self::assertSame([], $fixture->row('appointments', $appointmentId));
            self::assertSame([], $fixture->row('users', $customerId));
            self::assertSame(
                0,
                $db
                    ->get_where('consents', [
                        'first_name' => 'Synthetic',
                        'last_name' => $customer['last_name'],
                    ])
                    ->num_rows(),
            );
        }
        foreach (['manual-blocked', 'manual-cleared'] as $case) {
            $lastName = 'Manual ' . $fixture->run . ' ' . $case;
            $db->delete('consents', ['first_name' => 'Synthetic', 'last_name' => $lastName]);
            self::assertSame(
                0,
                $db
                    ->get_where('consents', [
                        'first_name' => 'Synthetic',
                        'last_name' => $lastName,
                    ])
                    ->num_rows(),
            );
            $orphanedCustomers = $db
                ->get_where('users', [
                    'first_name' => 'Synthetic',
                    'last_name' => $lastName,
                ])
                ->result_array();
            foreach ($orphanedCustomers as $customer) {
                $customerId = (int) $customer['id'];
                self::assertSame('Synthetic', $customer['first_name']);
                self::assertSame($lastName, $customer['last_name']);
                self::assertTrue(
                    $customer['notes'] === null || $customer['notes'] === $fixture->run,
                    'An owned booking customer must retain the fixture note or the known NULL projection.',
                );
                self::assertSame(0, $db->get_where('appointments', ['id_users_customer' => $customerId])->num_rows());
                $db->delete('users', [
                    'id' => $customerId,
                    'first_name' => 'Synthetic',
                    'last_name' => $lastName,
                ]);
                self::assertSame([], $fixture->row('users', $customerId));
            }
            self::assertSame(
                0,
                $db
                    ->get_where('users', [
                        'first_name' => 'Synthetic',
                        'last_name' => $lastName,
                    ])
                    ->num_rows(),
            );
        }
        self::assertSame(
            0,
            $db
                ->get_where('appointments', [
                    'id_users_provider' => $fixture->providerId,
                    'id_services' => $fixture->serviceId,
                    'notes' => $fixture->run,
                    'is_unavailability' => 0,
                ])
                ->num_rows(),
        );
        self::assertSame($this->baselineConsentCount, $db->count_all('consents'));
    }

    private function restoreSettings(): void
    {
        $db = get_instance()->db;
        foreach (
            [
                'display_privacy_policy' => $this->privacyPolicySetting,
                'display_terms_and_conditions' => $this->termsSetting,
                'display_email' => $this->displayEmailSetting,
                'require_email' => $this->requireEmailSetting,
            ]
            as $name => $row
        ) {
            if ($row !== null) {
                $db->update('settings', ['value' => $row['value']], ['name' => $name]);
            }
        }
    }
}

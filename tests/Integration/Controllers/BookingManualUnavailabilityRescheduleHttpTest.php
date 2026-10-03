<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP/DB regression for manual unavailability during public rescheduling. */
final class BookingManualUnavailabilityRescheduleHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?GateHttpClient $client = null;
    private ?array $displayEmailSetting = null;
    private ?array $requireEmailSetting = null;
    private ?array $displayTermsSetting = null;
    private ?int $appointmentId = null;
    private ?int $manualUnavailabilityId = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->displayEmailSetting = $db->get_where('settings', ['name' => 'display_email'])->row_array() ?: null;
            $this->requireEmailSetting = $db->get_where('settings', ['name' => 'require_email'])->row_array() ?: null;
            $this->displayTermsSetting =
                $db->get_where('settings', ['name' => 'display_terms_and_conditions'])->row_array() ?: null;
            self::assertNotNull($this->displayEmailSetting);
            self::assertNotNull($this->requireEmailSetting);
            self::assertNotNull($this->displayTermsSetting);
            $db->update('settings', ['value' => '0'], ['id' => $this->displayEmailSetting['id']]);
            $db->update('settings', ['value' => '0'], ['id' => $this->requireEmailSetting['id']]);
            // Enable one consent type so the successful control proves the write path.
            $db->update('settings', ['value' => '1'], ['id' => $this->displayTermsSetting['id']]);
            $this->server = new DefenseCycleHttpServer();
            $this->client = $this->server->client();
            self::assertSame(200, $this->client->get('booking')->statusCode);
        } catch (Throwable $error) {
            $this->server?->close();
            try {
                $this->cleanupOwnedRows();
            } finally {
                try {
                    $this->restoreSettings();
                } finally {
                    $this->fixture?->cleanup();
                }
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

    public function testManualUnavailabilityBlocksAuthorizedRescheduleAndClearedTargetSucceeds(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $db = get_instance()->db;

        $appointment = $fixture->appointment();
        $this->appointmentId = (int) $appointment['id'];
        $beforeAppointment = $fixture->row('appointments', $this->appointmentId);
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $beforeService = $fixture->row('services', $fixture->serviceId);
        $consentIdentity = [
            'first_name' => (string) $beforeCustomer['first_name'],
            'last_name' => (string) $beforeCustomer['last_name'],
            'email' => (string) $beforeCustomer['email'],
            'type' => 'terms-and-conditions',
        ];
        $beforeConsentCount = $db->get_where('consents', $consentIdentity)->num_rows();

        $date = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days')->format('Y-m-d');
        self::assertSame(
            200,
            $client->get('booking/reschedule/' . rawurlencode((string) $appointment['hash']))->statusCode,
        );
        $beforeHours = $this->availableHours($client, $date, $this->appointmentId);
        self::assertGreaterThanOrEqual(2, count($beforeHours), 'Need adjacent positive-control slots.');
        $targetHour = $beforeHours[0];
        $targetStart = new DateTimeImmutable($date . ' ' . $targetHour . ':00');
        $adjacentHour = null;
        foreach ($beforeHours as $hour) {
            if (new DateTimeImmutable($date . ' ' . $hour . ':00') >= $targetStart->modify('+30 minutes')) {
                $adjacentHour = $hour;
                break;
            }
        }
        self::assertNotNull($adjacentHour, 'Need a permitted adjacent slot after the manual block.');

        $this->manualUnavailabilityId = $this->insertManualUnavailability($targetStart);
        $manualBefore = $fixture->row('appointments', $this->manualUnavailabilityId);
        self::assertNotContains($targetHour, $this->availableHours($client, $date, $this->appointmentId));
        self::assertContains($adjacentHour, $this->availableHours($client, $date, $this->appointmentId));

        $beforeCounts = $this->mutationCounts();
        $blocked = $client->post('booking/register', [
            'post_data' => $this->reschedulePayload($beforeAppointment, $beforeCustomer, $targetStart, 'blocked'),
        ]);
        self::assertSame(409, $blocked->statusCode, $blocked->body);
        self::assertSame($beforeAppointment, $fixture->row('appointments', $this->appointmentId));
        self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
        self::assertSame($beforeService, $fixture->row('services', $fixture->serviceId));
        self::assertSame($beforeConsentCount, $db->get_where('consents', $consentIdentity)->num_rows());
        self::assertSame($manualBefore, $fixture->row('appointments', $this->manualUnavailabilityId));
        self::assertSame($beforeCounts, $this->mutationCounts());
        $authority = $db->get_where('reschedule_authorities', ['appointment_id' => $this->appointmentId])->row_array();
        self::assertNotEmpty($authority);
        self::assertNotNull($authority['consumed_at']);

        // The consumed authority cannot be replayed without a new authorized GET.
        $replay = $client->post('booking/register', [
            'post_data' => $this->reschedulePayload($beforeAppointment, $beforeCustomer, $targetStart, 'replay'),
        ]);
        self::assertSame(403, $replay->statusCode, $replay->body);
        self::assertSame($beforeAppointment, $fixture->row('appointments', $this->appointmentId));
        self::assertSame($beforeConsentCount, $db->get_where('consents', $consentIdentity)->num_rows());
        self::assertSame($manualBefore, $fixture->row('appointments', $this->manualUnavailabilityId));
        self::assertSame($beforeCounts, $this->mutationCounts());

        self::assertTrue(
            $db->delete('appointments', [
                'id' => $this->manualUnavailabilityId,
                'id_users_provider' => $fixture->providerId,
                'is_unavailability' => 1,
            ]),
        );
        self::assertSame([], $fixture->row('appointments', $this->manualUnavailabilityId));
        $this->manualUnavailabilityId = null;
        self::assertSame(
            200,
            $client->get('booking/reschedule/' . rawurlencode((string) $appointment['hash']))->statusCode,
        );

        $positive = $client->post('booking/register', [
            'post_data' => $this->reschedulePayload($beforeAppointment, $beforeCustomer, $targetStart, 'positive'),
        ]);
        self::assertSame(200, $positive->statusCode, $positive->body);
        self::assertSame(
            $this->appointmentId,
            (int) (json_decode($positive->body, true, 512, JSON_THROW_ON_ERROR)['appointment_id'] ?? 0),
        );
        $updated = $fixture->row('appointments', $this->appointmentId);
        self::assertSame($targetStart->format('Y-m-d H:i:s'), $updated['start_datetime']);
        self::assertSame($fixture->run . '_positive', $updated['notes']);
        self::assertSame($beforeConsentCount + 1, $db->get_where('consents', $consentIdentity)->num_rows());
    }

    /** @return list<string> */
    private function availableHours(GateHttpClient $client, string $date, int $appointmentId): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $response = $client->post('booking/get_available_hours', [
            'provider_id' => $fixture->providerId,
            'service_id' => $fixture->serviceId,
            'selected_date' => $date,
            'manage_mode' => '1',
            'appointment_id' => (string) $appointmentId,
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
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
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

    private function reschedulePayload(
        array $appointment,
        array $customer,
        DateTimeImmutable $start,
        string $case,
    ): array {
        return [
            'appointment' => [
                'id' => (int) $appointment['id'],
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => (int) $appointment['id_services'],
                'id_users_provider' => (int) $appointment['id_users_provider'],
                'location' => '',
                'notes' => $this->fixture?->run . '_' . $case,
                'color' => '',
            ],
            'customer' => [
                'id' => (int) $customer['id'],
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'email' => $customer['email'],
                'phone_number' => $customer['phone_number'] ?: '+49123456789',
                'address' => $customer['address'] ?: 'Teststrasse 1',
                'city' => $customer['city'] ?: 'Berlin',
                'zip_code' => $customer['zip_code'] ?: '10115',
                'timezone' => $customer['timezone'] ?: 'UTC',
                'notes' => $customer['notes'] ?? '',
            ],
            'manage_mode' => true,
        ];
    }

    private function cleanupOwnedRows(): void
    {
        $fixture = $this->fixture;
        if ($fixture === null || !isset($fixture->providerId)) {
            return;
        }
        $db = get_instance()->db;
        if ($this->manualUnavailabilityId !== null) {
            $manual = $db->get_where('appointments', ['id' => $this->manualUnavailabilityId])->row_array();
            if ($manual) {
                self::assertSame($fixture->providerId, (int) $manual['id_users_provider']);
                self::assertSame(1, (int) $manual['is_unavailability']);
                self::assertSame($fixture->run . '_manual_unavailability', $manual['notes']);
                $db->delete('appointments', [
                    'id' => $this->manualUnavailabilityId,
                    'id_users_provider' => $fixture->providerId,
                    'is_unavailability' => 1,
                    'notes' => $fixture->run . '_manual_unavailability',
                ]);
            }
        }
        // Remove every run-marked appointment first, including rows from a regressed POST.
        $owned = $db
            ->where('id_users_provider', $fixture->providerId)
            ->where('id_services', $fixture->serviceId)
            ->like('notes', $fixture->run, 'after')
            ->get('appointments')
            ->result_array();
        foreach ($owned as $row) {
            $id = (int) $row['id'];
            $identity = [
                'id' => $id,
                'id_users_provider' => $fixture->providerId,
                'id_services' => $fixture->serviceId,
                'notes' => (string) $row['notes'],
                'is_unavailability' => (int) $row['is_unavailability'],
            ];
            if ($id === (int) ($this->manualUnavailabilityId ?? 0) || (int) $row['is_unavailability'] === 1) {
                $db->delete('appointments', $identity);
                self::assertSame([], $fixture->row('appointments', $id));
                continue;
            }
            $db->delete('reschedule_authorities', ['appointment_id' => $id]);
            $db->delete('appointments', $identity);
            self::assertSame([], $fixture->row('appointments', $id));
        }
        if ($this->appointmentId !== null) {
            $original = $db->get_where('appointments', ['id' => $this->appointmentId])->row_array();
            if ($original) {
                self::assertSame($fixture->providerId, (int) $original['id_users_provider']);
                self::assertSame($fixture->serviceId, (int) $original['id_services']);
                self::assertSame($fixture->customerId, (int) $original['id_users_customer']);
                $db->delete('reschedule_authorities', ['appointment_id' => $this->appointmentId]);
                $db->delete('appointments', [
                    'id' => $this->appointmentId,
                    'id_users_provider' => $fixture->providerId,
                    'id_services' => $fixture->serviceId,
                    'id_users_customer' => $fixture->customerId,
                ]);
            }
        }
        $ownedUsers = $db
            ->like('email', $fixture->run . '_', 'after')
            ->get('users')
            ->result_array();
        $ownedEmails = [$fixture->run . '_customer@synthetic.invalid'];
        foreach ($ownedUsers as $user) {
            $email = (string) ($user['email'] ?? '');
            if (
                !str_starts_with($email, $fixture->run . '_') ||
                !str_ends_with($email, '@synthetic.invalid') ||
                (string) ($user['first_name'] ?? '') !== 'Synthetic' ||
                (string) ($user['notes'] ?? '') !== $fixture->run
            ) {
                continue;
            }
            $ownedEmails[] = $email;
            $id = (int) $user['id'];
            if (in_array($id, [$fixture->actorId, $fixture->providerId, $fixture->customerId], true)) {
                continue;
            }
            if ($db->get_where('appointments', ['id_users_customer' => $id])->num_rows() === 0) {
                $db->delete('user_settings', ['id_users' => $id]);
                $db->delete('users', ['id' => $id]);
                self::assertSame(0, $db->get_where('users', ['id' => $id])->num_rows());
            }
        }
        foreach (array_unique($ownedEmails) as $email) {
            $db->delete('consents', ['email' => $email]);
            self::assertSame(0, $db->get_where('consents', ['email' => $email])->num_rows());
        }
        self::assertSame([], $fixture->row('appointments', (int) ($this->appointmentId ?? 0)) ?: []);
    }

    private function restoreSettings(): void
    {
        $db = get_instance()->db;
        foreach ([$this->displayEmailSetting, $this->requireEmailSetting, $this->displayTermsSetting] as $row) {
            if ($row !== null) {
                $db->update('settings', ['value' => $row['value']], ['id' => $row['id']]);
                self::assertSame(
                    (string) $row['value'],
                    (string) $db->get_where('settings', ['id' => $row['id']])->row('value'),
                );
            }
        }
    }

    /** @return array<string,int> */
    private function mutationCounts(): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'user_settings' => $db->count_all('user_settings'),
            'services' => $db->count_all('services'),
            'consents' => $db->count_all('consents'),
        ];
    }
}

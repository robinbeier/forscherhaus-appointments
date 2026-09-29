<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Verify that the public booking write endpoint is POST-only over real HTTP. */
final class BookingMethodHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?array $privacySetting = null;
    private ?array $termsSetting = null;
    private ?array $displayEmailSetting = null;
    private ?array $requireEmailSetting = null;
    /** @var list<string> */
    private array $ownedConsentEmails = [];
    /** @var list<int> */
    private array $baselineConsentIds = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->privacySetting =
                $db->get_where('settings', ['name' => 'display_privacy_policy'])->row_array() ?: null;
            $this->termsSetting =
                $db->get_where('settings', ['name' => 'display_terms_and_conditions'])->row_array() ?: null;
            $this->displayEmailSetting = $db->get_where('settings', ['name' => 'display_email'])->row_array() ?: null;
            $this->requireEmailSetting = $db->get_where('settings', ['name' => 'require_email'])->row_array() ?: null;
            $db->update('settings', ['value' => '1'], ['name' => 'display_privacy_policy']);
            $db->update('settings', ['value' => '0'], ['name' => 'display_terms_and_conditions']);
            $db->update('settings', ['value' => '0'], ['name' => 'display_email']);
            $db->update('settings', ['value' => '0'], ['name' => 'require_email']);
            $this->baselineConsentIds = array_map(
                static fn(array $row): int => (int) $row['id'],
                $db->get('consents')->result_array(),
            );
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                try {
                    $this->restoreConsentSettings();
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
            $db = get_instance()->db;
            foreach ($db->get('consents')->result_array() as $row) {
                if (
                    !in_array((int) $row['id'], $this->baselineConsentIds, true) ||
                    in_array((string) $row['email'], $this->ownedConsentEmails, true)
                ) {
                    $db->delete('consents', ['id' => (int) $row['id']]);
                }
            }
            $this->cleanupNameOnlyBookings();
            $this->restoreConsentSettings();
            $this->fixture?->cleanup();
        }
    }

    private function restoreConsentSettings(): void
    {
        $db = get_instance()->db;
        foreach (
            [
                'display_privacy_policy' => $this->privacySetting,
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

    public function testNewBookingCreatesOnlyPrivacyConsentWhenPrivacyIsEnabled(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $customer = $fixture->row('users', $fixture->customerId);
        $target = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days');
        $appointment = [
            'start_datetime' => $target->setTime(11, 0)->format('Y-m-d H:i:s'),
            'end_datetime' => $target->setTime(11, 30)->format('Y-m-d H:i:s'),
            'id_services' => $fixture->serviceId,
            'id_users_provider' => $fixture->providerId,
            'location' => '',
            'notes' => $fixture->run,
            'color' => '',
        ];
        unset($customer['id']);
        unset($customer['email']);
        $payload = [
            'appointment' => $appointment,
            'customer' => $customer,
            'manage_mode' => false,
        ];

        $beforeAppointmentCount = get_instance()->db->count_all('appointments');
        self::assertSame(200, $client->get('booking')->statusCode);
        $response = $client->post('booking/register', ['post_data' => $payload]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($beforeAppointmentCount + 1, get_instance()->db->count_all('appointments'));
        $booked = get_instance()
            ->db->get_where('appointments', [
                'id_services' => $fixture->serviceId,
                'start_datetime' => $appointment['start_datetime'],
                'notes' => $fixture->run,
            ])
            ->result_array();
        self::assertCount(1, $booked);
        self::assertNotSame($fixture->customerId, (int) $booked[0]['id_users_customer']);
        self::assertSame($appointment['start_datetime'], $booked[0]['start_datetime']);
        $rows = get_instance()
            ->db->get_where('consents', ['email' => '-', 'last_name' => $customer['last_name']])
            ->result_array();
        self::assertCount(1, $rows);
        self::assertSame('privacy-policy', $rows[0]['type']);
        self::assertSame($customer['first_name'], $rows[0]['first_name']);
        self::assertSame($customer['last_name'], $rows[0]['last_name']);
        self::assertSame('-', $rows[0]['email']);
        self::assertSame('127.0.0.1', $rows[0]['ip']);
    }

    private function cleanupNameOnlyBookings(): void
    {
        $fixture = $this->fixture;
        if ($fixture === null) {
            return;
        }
        $db = get_instance()->db;
        foreach ($db->get_where('appointments', ['id_services' => $fixture->serviceId])->result_array() as $row) {
            if ((int) $row['id_users_customer'] === $fixture->customerId) {
                continue;
            }
            self::assertSame($fixture->run, $row['notes']);
            $customerId = (int) $row['id_users_customer'];
            $customer = $db->get_where('users', ['id' => $customerId])->row_array();
            self::assertNotEmpty($customer);
            self::assertEmpty($customer['email']);
            $db->delete('reschedule_authorities', ['appointment_id' => (int) $row['id']]);
            $db->delete('appointments', ['id' => (int) $row['id']]);
            $db->delete('users', ['id' => $customerId]);
        }
    }

    public function testBookingRegisterCreatesNoConsentWhenPrivacyIsDisabled(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        self::assertTrue(
            get_instance()->db->update('settings', ['value' => '0'], ['name' => 'display_privacy_policy']),
        );
        $appointment = $fixture->appointment();
        $customer = $fixture->row('users', $fixture->customerId);
        $this->ownedConsentEmails[] = (string) $customer['email'];
        self::assertSame(200, $client->get('booking/reschedule/' . $appointment['hash'])->statusCode);

        $response = $client->post('booking/register', [
            'post_data' => [
                'appointment' => $appointment,
                'customer' => $customer,
                'manage_mode' => true,
            ],
        ]);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame(
            [],
            get_instance()
                ->db->get_where('consents', ['email' => $customer['email']])
                ->result_array(),
        );
    }

    public function testGetRegisterRejectsValidPublicQueryPayloadWithoutMutation(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $existingAppointment = $fixture->appointment();
        $existingCustomer = $fixture->row('users', $fixture->customerId);
        $before = $this->snapshot($existingAppointment, $fixture->customerId);
        $target = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days');
        $payload = [
            'appointment' => [
                'start_datetime' => $target->setTime(11, 0)->format('Y-m-d H:i:s'),
                'end_datetime' => $target->setTime(11, 30)->format('Y-m-d H:i:s'),
                'id_services' => $fixture->serviceId,
                'id_users_provider' => $fixture->providerId,
                'location' => '',
                'notes' => $fixture->run,
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Synthetic',
                'last_name' => $existingCustomer['last_name'],
                'email' => $existingCustomer['email'],
                'phone_number' => $existingCustomer['phone_number'],
                'address' => $existingCustomer['address'],
                'city' => $existingCustomer['city'],
                'zip_code' => $existingCustomer['zip_code'],
                'timezone' => $existingCustomer['timezone'],
                'notes' => $existingCustomer['notes'],
            ],
            'manage_mode' => false,
        ];

        $response = $client->get('booking/register', ['post_data' => $payload]);

        self::assertSame(405, $response->statusCode, $response->body);
        self::assertSame('POST', $response->header('allow'));
        self::assertSame($before, $this->snapshot($existingAppointment, $fixture->customerId));
    }

    public function testGetRegisterRejectsSessionAuthorizedReschedulePayloadWithoutMutation(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $appointment = $fixture->appointment();
        $customer = $fixture->row('users', $fixture->customerId);
        self::assertSame(200, $client->get('booking/reschedule/' . $appointment['hash'])->statusCode);
        $before = $this->snapshot($appointment, $fixture->customerId);
        $target = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days');
        $payload = [
            'appointment' => [
                'id' => (int) $appointment['id'],
                'start_datetime' => $target->setTime(11, 0)->format('Y-m-d H:i:s'),
                'end_datetime' => $target->setTime(11, 30)->format('Y-m-d H:i:s'),
                'id_services' => (int) $appointment['id_services'],
                'id_users_provider' => (int) $appointment['id_users_provider'],
                'location' => '',
                'notes' => $fixture->run,
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

        $response = $client->get('booking/register', ['post_data' => $payload]);

        self::assertSame(405, $response->statusCode, $response->body);
        self::assertSame('POST', $response->header('allow'));
        self::assertSame($before, $this->snapshot($appointment, $fixture->customerId));

        $this->ownedConsentEmails[] = (string) $customer['email'];
        $success = $client->post('booking/register', ['post_data' => $payload]);
        self::assertSame(200, $success->statusCode, $success->body);
        self::assertSame(
            $payload['appointment']['start_datetime'],
            $fixture->row('appointments', (int) $appointment['id'])['start_datetime'],
        );
    }

    /** @return array{appointments:int,users:int,consents:int,appointment:array,customer:array} */
    private function snapshot(array $appointment, int $customerId): array
    {
        $db = get_instance()->db;

        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'consents' => $db->count_all('consents'),
            'appointment' => $this->fixture?->row('appointments', (int) $appointment['id']) ?? [],
            'customer' => $this->fixture?->row('users', $customerId) ?? [],
        ];
    }
}

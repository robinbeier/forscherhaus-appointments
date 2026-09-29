<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Verify that public customer identity cannot be asserted by a caller-supplied email. */
final class PublicBookingExistingCustomerIdentityHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?GateHttpClient $client = null;
    /** @var array<string, ?array<string, mixed>> */
    private array $settings = [];
    /** @var list<int> */
    private array $ownedAppointmentIds = [];
    /** @var list<int> */
    private array $ownedCustomerIds = [];
    /** @var list<int> */
    private array $consentIdsBefore = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            foreach (
                ['display_email', 'require_email', 'display_privacy_policy', 'display_terms_and_conditions']
                as $name
            ) {
                $this->settings[$name] = $db->get_where('settings', ['name' => $name])->row_array() ?: null;
            }
            $db->update('settings', ['value' => '0'], ['name' => 'display_email']);
            $db->update('settings', ['value' => '0'], ['name' => 'require_email']);
            $db->update('settings', ['value' => '1'], ['name' => 'display_privacy_policy']);
            $db->update('settings', ['value' => '0'], ['name' => 'display_terms_and_conditions']);
            foreach ($db->get('consents')->result_array() as $row) {
                $this->consentIdsBefore[] = (int) $row['id'];
            }
            $this->server = new DefenseCycleHttpServer();
            $this->client = $this->server->client();
            self::assertSame(200, $this->client->get('booking')->statusCode);
        } catch (Throwable $error) {
            $this->server?->close();
            $this->restoreAndCleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->restoreAndCleanup();
        }
    }

    public function testHiddenEmailCannotForgeExistingCustomerAndLeavesNoPartialMutation(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $db = get_instance()->db;
        $existingAppointment = $fixture->appointment();
        $existingCustomer = $fixture->row('users', $fixture->customerId);
        $before = $this->databaseSnapshot();
        $response = $client->post('booking/register', [
            'post_data' => [
                'appointment' => $this->appointmentPayload(
                    $fixture,
                    (new DateTimeImmutable($existingAppointment['start_datetime']))->modify('+1 hour'),
                    'rob650-hidden-forged',
                ),
                'customer' => [
                    'first_name' => 'Forged',
                    'last_name' => 'Identity',
                    'email' => $existingCustomer['email'],
                    'phone_number' => '+49999999999',
                    'address' => 'Forged Street 1',
                    'city' => 'Forged City',
                    'zip_code' => '99999',
                    'timezone' => 'Europe/Berlin',
                ],
                'manage_mode' => false,
            ],
        ]);

        self::assertSame(409, $response->statusCode, $response->body);
        self::assertSame($before, $this->databaseSnapshot());
        self::assertSame([], $db->get_where('appointments', ['notes' => 'rob650-hidden-forged'])->result_array());
    }

    public function testRepeatedNameOnlyBookingsCreateIndependentCustomers(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $db = get_instance()->db;
        $existingAppointment = $fixture->appointment();
        $first = $this->nameOnlyCustomer();
        $second = $this->nameOnlyCustomer();
        $firstResponse = $client->post('booking/register', [
            'post_data' => [
                'appointment' => $this->appointmentPayload(
                    $fixture,
                    (new DateTimeImmutable($existingAppointment['start_datetime']))->modify('+1 hour'),
                    'rob650-name-only-1',
                ),
                'customer' => $first,
                'manage_mode' => false,
            ],
        ]);
        $secondResponse = $client->post('booking/register', [
            'post_data' => [
                'appointment' => $this->appointmentPayload(
                    $fixture,
                    (new DateTimeImmutable($existingAppointment['start_datetime']))->modify('+2 hours'),
                    'rob650-name-only-2',
                ),
                'customer' => $second,
                'manage_mode' => false,
            ],
        ]);

        self::assertSame(200, $firstResponse->statusCode, $firstResponse->body);
        self::assertSame(200, $secondResponse->statusCode, $secondResponse->body);
        $firstData = json_decode($firstResponse->body, true, 512, JSON_THROW_ON_ERROR);
        $secondData = json_decode($secondResponse->body, true, 512, JSON_THROW_ON_ERROR);
        $this->ownedAppointmentIds[] = (int) $firstData['appointment_id'];
        $this->ownedAppointmentIds[] = (int) $secondData['appointment_id'];
        $firstBooking = $db->get_where('appointments', ['id' => $this->ownedAppointmentIds[0]])->row_array();
        $secondBooking = $db->get_where('appointments', ['id' => $this->ownedAppointmentIds[1]])->row_array();
        $this->ownedCustomerIds[] = (int) $firstBooking['id_users_customer'];
        $this->ownedCustomerIds[] = (int) $secondBooking['id_users_customer'];
        self::assertNotSame($fixture->customerId, $this->ownedCustomerIds[0]);
        self::assertNotSame($fixture->customerId, $this->ownedCustomerIds[1]);
        self::assertNotSame($this->ownedCustomerIds[0], $this->ownedCustomerIds[1]);
        self::assertEmpty($db->get_where('users', ['id' => $this->ownedCustomerIds[0]])->row_array()['email']);
        self::assertEmpty($db->get_where('users', ['id' => $this->ownedCustomerIds[1]])->row_array()['email']);
    }

    public function testEmailEnabledDirectKnownEmailStillRequiresIssuedAuthority(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $db = get_instance()->db;
        $db->update('settings', ['value' => '1'], ['name' => 'display_email']);
        $db->update('settings', ['value' => '1'], ['name' => 'require_email']);
        $existingAppointment = $fixture->appointment();
        $existingCustomer = $fixture->row('users', $fixture->customerId);
        $submittedCustomer = $existingCustomer;
        unset($submittedCustomer['id']);
        $submittedCustomer['last_name'] = 'Forged Enabled';
        $before = $this->databaseSnapshot();
        $response = $client->post('booking/register', [
            'post_data' => [
                'appointment' => $this->appointmentPayload(
                    $fixture,
                    (new DateTimeImmutable($existingAppointment['start_datetime']))->modify('+1 hour'),
                    'rob650-enabled-forged',
                ),
                'customer' => $submittedCustomer,
                'manage_mode' => false,
            ],
        ]);

        self::assertSame(409, $response->statusCode, $response->body);
        self::assertSame($before, $this->databaseSnapshot());
    }

    public function testIssuedRescheduleLinkStillAuthorizesExistingAppointment(): void
    {
        $fixture = $this->fixture;
        $client = $this->client;
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        $appointment = $fixture->appointment();
        $customer = $fixture->row('users', $fixture->customerId);
        $page = $client->get('booking/reschedule/' . $appointment['hash']);
        self::assertSame(200, $page->statusCode, $page->body);
        $target = (new DateTimeImmutable($appointment['start_datetime']))->modify('+1 hour');
        $response = $client->post('booking/register', [
            'post_data' => [
                'appointment' => [
                    'id' => (int) $appointment['id'],
                    'start_datetime' => $target->format('Y-m-d H:i:s'),
                    'end_datetime' => $target->modify('+30 minutes')->format('Y-m-d H:i:s'),
                    'id_services' => $fixture->serviceId,
                    'id_users_provider' => $fixture->providerId,
                    'location' => '',
                    'notes' => $fixture->run,
                    'color' => '',
                ],
                'customer' => array_merge($customer, ['id' => $fixture->customerId]),
                'manage_mode' => true,
            ],
        ]);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame(
            $target->format('Y-m-d H:i:s'),
            $fixture->row('appointments', (int) $appointment['id'])['start_datetime'],
        );
    }

    /** @return array<string, mixed> */
    private function nameOnlyCustomer(): array
    {
        return [
            'first_name' => 'Parent',
            'last_name' => 'Same Name',
            'phone_number' => '+49123456789',
            'address' => 'School Street 1',
            'city' => 'Bielefeld',
            'zip_code' => '33602',
            'timezone' => 'Europe/Berlin',
        ];
    }

    /** @return array<string, mixed> */
    private function appointmentPayload(DefenseCycleFixtures $fixture, DateTimeImmutable $start, string $notes): array
    {
        return [
            'start_datetime' => $start->format('Y-m-d H:i:s'),
            'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'id_services' => $fixture->serviceId,
            'id_users_provider' => $fixture->providerId,
            'location' => '',
            'notes' => $notes,
            'color' => '',
        ];
    }

    /** @return array{appointments:list<array<string,mixed>>,customers:list<array<string,mixed>>,consents:list<array<string,mixed>>} */
    private function databaseSnapshot(): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->get('appointments')->result_array(),
            'customers' => $db->get('users')->result_array(),
            'consents' => $db->get('consents')->result_array(),
        ];
    }

    private function restoreAndCleanup(): void
    {
        $db = get_instance()->db;
        // Recover fixture-owned rows even when an assertion failed before the
        // response IDs were recorded.
        foreach ($db->get('appointments')->result_array() as $row) {
            if (str_starts_with((string) ($row['notes'] ?? ''), 'rob650-')) {
                $this->ownedAppointmentIds[] = (int) $row['id'];
                $this->ownedCustomerIds[] = (int) $row['id_users_customer'];
            }
        }
        $this->ownedAppointmentIds = array_values(array_unique($this->ownedAppointmentIds));
        $this->ownedCustomerIds = array_values(array_unique($this->ownedCustomerIds));
        foreach ($this->ownedAppointmentIds as $id) {
            $db->delete('reschedule_authorities', ['appointment_id' => $id]);
            $db->delete('appointments', ['id' => $id]);
        }
        foreach ($this->ownedCustomerIds as $id) {
            $db->delete('users', ['id' => $id]);
        }
        foreach ($db->get('consents')->result_array() as $row) {
            if (!in_array((int) $row['id'], $this->consentIdsBefore, true)) {
                $db->delete('consents', ['id' => (int) $row['id']]);
            }
        }
        foreach ($this->settings as $name => $row) {
            if ($row !== null) {
                $db->update('settings', ['value' => $row['value']], ['name' => $name]);
            }
        }
        $this->fixture?->cleanup();
    }
}

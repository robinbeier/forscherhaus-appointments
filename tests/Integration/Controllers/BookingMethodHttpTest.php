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

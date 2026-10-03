<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP coverage for the public JSON-compatible booking write path. */
final class BookingJsonBodyLimitHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?GateHttpClient $client = null;
    /** @var array<string, array<string, mixed>|null> */
    private array $emailSettings = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh synthetic defense stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            foreach (['display_email', 'require_email'] as $name) {
                $this->emailSettings[$name] = $db->get_where('settings', ['name' => $name])->row_array() ?: null;
                $db->update('settings', ['value' => '0'], ['name' => $name]);
            }
            $this->server = new DefenseCycleHttpServer();
            $this->client = $this->server->client();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->restoreEmailSettings();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->restoreEmailSettings();
            $this->cleanupOwnedBookings();
            $this->fixture?->cleanup();
        }
    }

    private function restoreEmailSettings(): void
    {
        $db = get_instance()->db;
        foreach ($this->emailSettings as $name => $row) {
            if ($row !== null) {
                $db->update('settings', ['value' => $row['value']], ['name' => $name]);
            }
        }
        $this->emailSettings = [];
    }

    public function testSmallJsonBookingControlSucceeds(): void
    {
        $fixture = $this->requireFixture();
        $client = $this->requireClient();
        $response = $client->requestRawApp(
            'POST',
            'booking/register',
            json_encode(['post_data' => $this->bookingPayload('small-control')], JSON_THROW_ON_ERROR),
            'application/json',
        );

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($fixture->run . '_small-control', $this->latestOwnedBooking()['notes'] ?? null);
    }

    public function testOversizedJsonBookingIsRejectedWith413BeforeMutation(): void
    {
        $fixture = $this->requireFixture();
        $client = $this->requireClient();
        $before = $this->mutationSnapshot();
        $payload = $this->bookingPayload('oversized');
        $payload['customer']['notes'] = str_repeat('x', 1024 * 1024);
        $rawBody = json_encode(['post_data' => $payload], JSON_THROW_ON_ERROR);
        self::assertGreaterThan(1024 * 1024, strlen($rawBody));

        $response = $client->requestRawApp('POST', 'booking/register', $rawBody, 'application/json');

        self::assertSame(413, $response->statusCode, $response->body);
        self::assertSame($before, $this->mutationSnapshot());
    }

    /** @return array<string, int> */
    private function mutationSnapshot(): array
    {
        $db = get_instance()->db;

        return [
            'appointments' => (int) $db->count_all('appointments'),
            'users' => (int) $db->count_all('users'),
        ];
    }

    /** @return array<string, mixed> */
    private function bookingPayload(string $suffix): array
    {
        $fixture = $this->requireFixture();
        $target = (new DateTimeImmutable('today'))->modify('next monday')->modify('+16 days');
        $customer = $fixture->row('users', $fixture->customerId);
        unset($customer['id'], $customer['email']);

        return [
            'appointment' => [
                'start_datetime' => $target->setTime(11, 0)->format('Y-m-d H:i:s'),
                'end_datetime' => $target->setTime(11, 30)->format('Y-m-d H:i:s'),
                'id_services' => $fixture->serviceId,
                'id_users_provider' => $fixture->providerId,
                'location' => '',
                'notes' => $fixture->run . '_' . $suffix,
                'color' => '',
            ],
            'customer' => $customer,
            'manage_mode' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function latestOwnedBooking(): array
    {
        $fixture = $this->requireFixture();

        return get_instance()
            ->db->order_by('id', 'DESC')
            ->get_where('appointments', ['id_services' => $fixture->serviceId])
            ->row_array() ?:
            [];
    }

    private function cleanupOwnedBookings(): void
    {
        $fixture = $this->fixture;
        if ($fixture === null || !isset($fixture->serviceId)) {
            return;
        }

        $db = get_instance()->db;
        foreach ($db->get_where('appointments', ['id_services' => $fixture->serviceId])->result_array() as $row) {
            if ((int) $row['id_users_customer'] === $fixture->customerId || ($row['notes'] ?? '') === $fixture->run) {
                continue;
            }
            self::assertStringStartsWith($fixture->run . '_', (string) $row['notes']);
            $customerId = (int) $row['id_users_customer'];
            $db->delete('reschedule_authorities', ['appointment_id' => (int) $row['id']]);
            $db->delete('appointments', ['id' => (int) $row['id']]);
            $db->delete('users', ['id' => $customerId]);
        }
    }

    private function requireFixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);

        return $this->fixture;
    }

    private function requireClient(): GateHttpClient
    {
        self::assertNotNull($this->client);

        return $this->client;
    }
}

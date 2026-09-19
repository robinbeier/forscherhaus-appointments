<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP regression for the policy change between authority issuance and use. */
final class RescheduleCutoffHttpTest extends TestCase
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
            $this->fixture?->cleanup();
        }
    }

    public function testIssuedAuthorityRechecksAdvanceCutoffBeforeMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $client = $this->server?->client();
        self::assertNotNull($client);

        $appointment = $fixture->appointment();
        $beforeAppointment = $fixture->row('appointments', (int) $appointment['id']);
        $beforeCustomer = $fixture->row('users', $fixture->customerId);

        // Issue while the appointment is allowed, then tighten the policy.
        $this->issueAuthority($client, $appointment['hash']);
        self::assertTrue($db->where('name', 'book_advance_timeout')->update('settings', ['value' => '21600']));

        $deniedTarget = $this->targetAppointment($beforeAppointment, 21);
        $denied = $client->post('booking/register', ['post_data' => $this->payload($deniedTarget, $beforeCustomer)]);

        // Keep the row check first so the original source demonstrates the mutation.
        self::assertSame(
            $beforeAppointment,
            $fixture->row('appointments', (int) $appointment['id']),
            'Changing the cutoff after issuance must not mutate the appointment.',
        );
        self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
        self::assertSame(403, $denied->statusCode);
        self::assertStringContainsString(lang('appointment_not_found'), $denied->body);

        // Positive control: an authority issued with the current policy remains usable.
        self::assertTrue($db->where('name', 'book_advance_timeout')->update('settings', ['value' => '0']));
        $positive = $fixture->appointment();
        $positiveBefore = $fixture->row('appointments', (int) $positive['id']);
        $this->issueAuthority($client, $positive['hash']);
        $positiveTarget = $this->targetAppointment($positiveBefore, 21);
        $success = $client->post('booking/register', ['post_data' => $this->payload($positiveTarget, $beforeCustomer)]);
        self::assertSame(200, $success->statusCode);
        $successData = json_decode($success->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((int) $positive['id'], (int) ($successData['appointment_id'] ?? 0));
        self::assertSame(
            $positiveTarget['start_datetime'],
            $fixture->row('appointments', (int) $positive['id'])['start_datetime'],
        );

        $replay = $client->post('booking/register', ['post_data' => $this->payload($positiveTarget, $beforeCustomer)]);
        self::assertSame(403, $replay->statusCode);
        self::assertSame(
            $positiveTarget['start_datetime'],
            $fixture->row('appointments', (int) $positive['id'])['start_datetime'],
        );
    }

    public function testReschedulePageUsesProviderTimezoneForAdvanceCutoff(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        $client = $this->server?->client();
        self::assertNotNull($client);

        $providerTimezone = new DateTimeZone('America/Adak');
        $start = new DateTimeImmutable('+90 minutes', $providerTimezone);
        $appointment = $fixture->appointment();

        self::assertTrue(
            $db->where('id', $fixture->providerId)->update('users', ['timezone' => $providerTimezone->getName()]),
        );
        self::assertTrue($db->where('name', 'book_advance_timeout')->update('settings', ['value' => '60']));
        self::assertTrue(
            $db->where('id', (int) $appointment['id'])->update('appointments', [
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
            ]),
        );

        $page = $client->get('booking/reschedule/' . $appointment['hash']);

        self::assertSame(200, $page->statusCode);
        self::assertStringContainsString('manage_mode', $page->body);
    }

    private function issueAuthority(object $client, string $hash): void
    {
        $page = $client->get('booking/reschedule/' . $hash);
        self::assertSame(200, $page->statusCode);
        self::assertStringContainsString('manage_mode', $page->body);
    }

    private function targetAppointment(array $appointment, int $days): array
    {
        $start = new DateTimeImmutable('+' . $days . ' days 10:00:00');
        $appointment['start_datetime'] = $start->format('Y-m-d H:i:s');
        $appointment['end_datetime'] = $start->modify('+30 minutes')->format('Y-m-d H:i:s');
        $appointment['notes'] = 'ROB585 cutoff recheck';
        $appointment['location'] = '';
        $appointment['color'] = '';
        return $appointment;
    }

    private function payload(array $appointment, array $customer): array
    {
        return [
            'appointment' => [
                'id' => (int) $appointment['id'],
                'start_datetime' => $appointment['start_datetime'],
                'end_datetime' => $appointment['end_datetime'],
                'id_services' => (int) $appointment['id_services'],
                'id_users_provider' => (int) $appointment['id_users_provider'],
                'location' => '',
                'notes' => $appointment['notes'],
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
}

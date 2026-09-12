<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpResponse;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

final class DefenseCycleOrdinaryFlowTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }
        $this->fixture = new DefenseCycleFixtures();
        $this->fixture->create();
        $this->server = new DefenseCycleHttpServer();
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    private function json(GateHttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode, 'Ordinary HTTP request must succeed.');
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('exception', $data);
        return $data;
    }

    public function testFixtureCleanupIsRepeatableAfterAnInterruptedOperation(): void
    {
        $appointment = $this->fixture->appointment();
        try {
            throw new RuntimeException('Synthetic interruption after fixture insertion.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic interruption after fixture insertion.', $error->getMessage());
        } finally {
            $this->fixture->cleanup();
        }
        $this->fixture->cleanup();
        self::assertSame([], $this->fixture->row('appointments', (int) $appointment['id']));
        self::assertSame([], $this->fixture->row('users', $this->fixture->actorId));
        self::assertSame([], $this->fixture->row('services', $this->fixture->serviceId));
    }

    public function testOrdinaryAccountCustomerAndCalendarSave(): void
    {
        $f = $this->fixture;
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        self::assertTrue(
            $this->json(
                $client->post('login/validate', [
                    'username' => $f->run . '_actor',
                    'password' => $f->password,
                ]),
            )['success'],
        );
        $account = $f->row('users', $f->actorId);
        $account['first_name'] = 'Updated';
        $account['settings'] = [
            'username' => $f->run . '_actor',
            'password' => '',
            'notifications' => 0,
            'calendar_view' => 'default',
        ];
        self::assertSame(200, $client->post('account/save', ['account' => $account])->statusCode);
        self::assertSame('Updated', $f->row('users', $f->actorId)['first_name']);

        $customer = $this->json($client->post('customers/find', ['customer_id' => $f->customerId]));
        self::assertSame($f->customerId, (int) $customer['id']);
        $customer['first_name'] = 'Customer Updated';
        self::assertTrue($this->json($client->post('customers/update', ['customer' => $customer]))['success']);
        self::assertSame('Customer Updated', $f->row('users', $f->customerId)['first_name']);

        $appointment = [
            'start_datetime' => date('Y-m-d 10:00:00', strtotime('+14 days')),
            'end_datetime' => date('Y-m-d 10:30:00', strtotime('+14 days')),
            'notes' => $f->run,
            'id_users_provider' => $f->providerId,
            'id_users_customer' => $f->customerId,
            'id_services' => $f->serviceId,
            'is_unavailability' => false,
        ];
        self::assertTrue(
            $this->json(
                $client->post('calendar/save_appointment', [
                    'appointment_data' => $appointment,
                    'customer_data' => $customer,
                ]),
            )['success'],
        );
        $ci = &get_instance();
        $created = $ci->db->get_where('appointments', ['id_services' => $f->serviceId])->result_array();
        self::assertCount(1, $created);
        $appointment = $created[0];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $appointment['hash']);
        $appointment['start_datetime'] = date('Y-m-d 11:00:00', strtotime('+14 days'));
        $appointment['end_datetime'] = date('Y-m-d 11:30:00', strtotime('+14 days'));
        self::assertTrue(
            $this->json(
                $client->post('calendar/save_appointment', [
                    'appointment_data' => $appointment,
                    'customer_data' => $customer,
                ]),
            )['success'],
        );
        $saved = $f->row('appointments', (int) $appointment['id']);
        self::assertSame($appointment['start_datetime'], $saved['start_datetime']);
        self::assertSame($appointment['hash'], $saved['hash']);
        self::assertSame(200, $client->get('logout')->statusCode);
    }

    public function testOrdinaryLegacyParentViewHasOnlyPublicData(): void
    {
        $f = $this->fixture;
        $appointment = $f->appointment(true);
        $client = $this->server->client();
        $page = $client->get('booking/reschedule/' . $appointment['hash']);
        self::assertSame(200, $page->statusCode);
        self::assertSame(1, preg_match('/const vars = (\{.*?\});/s', $page->body, $matches));
        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $providerFields = ['id', 'first_name', 'last_name', 'services', 'timezone', 'room'];
        $customerFields = [
            'id',
            'first_name',
            'last_name',
            'email',
            'phone_number',
            'address',
            'city',
            'state',
            'zip_code',
            'timezone',
            'language',
            'custom_field_1',
            'custom_field_2',
            'custom_field_3',
            'custom_field_4',
            'custom_field_5',
        ];
        foreach (['provider_data' => $providerFields, 'customer_data' => $customerFields] as $key => $expected) {
            self::assertIsArray($vars[$key]);
            $actual = array_keys($vars[$key]);
            sort($actual);
            sort($expected);
            self::assertSame($expected, $actual, 'Public projection field set: ' . $key);
        }
        self::assertSame($f->providerId, (int) $vars['provider_data']['id']);
        self::assertSame($f->customerId, (int) $vars['customer_data']['id']);
        foreach ($vars['available_providers'] as $provider) {
            self::assertSame([], array_values(array_diff(array_keys($provider), $providerFields)));
        }
        self::assertSame($appointment['hash'], $f->row('appointments', (int) $appointment['id'])['hash']);
        $customer = $vars['customer_data'];
        $customer['first_name'] = 'Legacy Updated';
        $appointment['start_datetime'] = date('Y-m-d 12:00:00', strtotime('+14 days'));
        $appointment['end_datetime'] = date('Y-m-d 12:30:00', strtotime('+14 days'));
        $result = $this->json(
            $client->post('booking/register', [
                'post_data' => [
                    'appointment' => $appointment,
                    'customer' => $customer,
                    'manage_mode' => true,
                ],
            ]),
        );
        self::assertSame((int) $appointment['id'], (int) $result['appointment_id']);
        self::assertSame($appointment['hash'], $f->row('appointments', (int) $appointment['id'])['hash']);
        self::assertSame(
            $appointment['start_datetime'],
            $f->row('appointments', (int) $appointment['id'])['start_datetime'],
        );
        self::assertSame(200, $client->get('booking/reschedule/' . $appointment['hash'])->statusCode);
        // Legacy-format compatibility, not a historical live-link claim.
    }
}

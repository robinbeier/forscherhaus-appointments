<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/**
 * Exercise the public cancellation capability against the real isolated HTTP
 * server while preserving the fixture rows needed to prove denial behavior.
 */
final class BookingCancellationHttpTest extends TestCase
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

    public function testCancellationHonorsAdvanceCutoffAndPreservesDeniedRows(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;

        self::assertTrue($db->where('name', 'book_advance_timeout')->update('settings', ['value' => '60']));

        $near = $fixture->appointment();
        $this->moveAppointment((int) $near['id'], 30);
        $near = $fixture->row('appointments', (int) $near['id']);
        $future = $fixture->appointment();
        $this->moveAppointment((int) $future['id'], 120);
        $future = $fixture->row('appointments', (int) $future['id']);
        $nearBuffer = $this->addBuffer($near);
        $futureBuffer = $this->addBuffer($future);

        $customerBefore = $fixture->row('users', $fixture->customerId);
        $providerBefore = $fixture->row('users', $fixture->providerId);
        $serviceBefore = $fixture->row('services', $fixture->serviceId);
        $client = $this->server?->client();
        self::assertNotNull($client);

        $nearResponse = $client->requestApp('POST', 'booking_cancellation/of/' . $near['hash'], [], 15);
        self::assertSame(
            $near,
            $fixture->row('appointments', (int) $near['id']),
            'The cutoff denial must leave the near-term appointment intact.',
        );
        self::assertSame($nearBuffer, $fixture->row('appointments', (int) $nearBuffer['id']));
        self::assertSame(403, $nearResponse->statusCode, 'A cancellation inside the advance cutoff must fail closed.');
        self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));
        self::assertSame($providerBefore, $fixture->row('users', $fixture->providerId));
        self::assertSame($serviceBefore, $fixture->row('services', $fixture->serviceId));

        $getResponse = $client->get('booking_cancellation/of/' . $near['hash']);
        self::assertSame(403, $getResponse->statusCode, 'Cancellation must remain POST-only.');
        self::assertSame($near, $fixture->row('appointments', (int) $near['id']));

        self::assertTrue($db->update('settings', ['value' => '1'], ['name' => 'disable_booking']));
        $disabled = $client->requestApp('POST', 'booking_cancellation/of/' . $future['hash'], [], 15);
        self::assertSame(403, $disabled->statusCode);
        self::assertSame($future, $fixture->row('appointments', (int) $future['id']));
        self::assertSame($futureBuffer, $fixture->row('appointments', (int) $futureBuffer['id']));
        self::assertTrue($db->update('settings', ['value' => '0'], ['name' => 'disable_booking']));

        $futureResponse = $client->requestApp('POST', 'booking_cancellation/of/' . $future['hash'], [], 15);
        self::assertSame(200, $futureResponse->statusCode);
        self::assertStringContainsString(lang('appointment_cancelled_title'), $futureResponse->body);
        self::assertSame([], $fixture->row('appointments', (int) $future['id']));
        self::assertSame([], $fixture->row('appointments', (int) $futureBuffer['id']));
        self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));
        self::assertSame($providerBefore, $fixture->row('users', $fixture->providerId));
        self::assertSame($serviceBefore, $fixture->row('services', $fixture->serviceId));

        $unknownResponse = $client->requestApp('POST', 'booking_cancellation/of/' . str_repeat('unknown-', 8), [], 15);
        self::assertSame(200, $unknownResponse->statusCode);
        self::assertStringContainsString(lang('appointment_not_found'), $unknownResponse->body);
        self::assertStringContainsString(lang('appointment_does_not_exist_in_db'), $unknownResponse->body);
        self::assertSame($near, $fixture->row('appointments', (int) $near['id']));
    }

    public function testFailedParentDeleteRestoresAppointmentAndBuffer(): void
    {
        $fixture = $this->fixture;
        $db = get_instance()->db;
        $appointment = $fixture->appointment();
        $buffer = $this->addBuffer($appointment);
        $before = $fixture->row('appointments', (int) $appointment['id']);
        $trigger = $fixture->run . '_deny_delete';
        // DefenseCycleFixtures enforces the disposable Docker database. This
        // separate setup connection creates/removes only the owned fault trigger;
        // application grants and server policy remain unchanged.
        $fixtureAdmin = get_instance()->load->database(
            [
                'hostname' => 'mysql',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'easyappointments',
                'dbdriver' => 'mysqli',
                'dbprefix' => $db->dbprefix,
                'pconnect' => false,
                'db_debug' => false,
                'char_set' => 'utf8mb4',
                'dbcollat' => 'utf8mb4_general_ci',
            ],
            true,
        );
        $created = false;
        try {
            self::assertTrue(
                $fixtureAdmin->query(
                    'CREATE TRIGGER `' .
                        $trigger .
                        '` BEFORE DELETE ON `' .
                        $db->dbprefix('appointments') .
                        '` ' .
                        'FOR EACH ROW BEGIN IF OLD.id = ' .
                        (int) $appointment['id'] .
                        " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic cancellation failure'; END IF; END",
                ),
            );
            $created = true;
            $response = $this->server
                ->client()
                ->requestApp('POST', 'booking_cancellation/of/' . $appointment['hash'], [], 15);
            self::assertSame(500, $response->statusCode);
            self::assertStringNotContainsString(lang('appointment_cancelled_title'), $response->body);
            self::assertSame($before, $fixture->row('appointments', (int) $appointment['id']));
            self::assertSame($buffer, $fixture->row('appointments', (int) $buffer['id']));
        } finally {
            try {
                if ($created) {
                    self::assertTrue($fixtureAdmin->query('DROP TRIGGER `' . $trigger . '`'));
                }
            } finally {
                $fixtureAdmin->close();
            }
        }
    }

    private function addBuffer(array $appointment): array
    {
        $db = get_instance()->db;
        self::assertTrue(
            $db->insert('appointments', [
                'start_datetime' => date('Y-m-d H:i:s', strtotime($appointment['start_datetime']) - 300),
                'end_datetime' => $appointment['start_datetime'],
                'is_unavailability' => 1,
                'id_users_provider' => $this->fixture->providerId,
                'id_parent_appointment' => $appointment['id'],
                'notes' => $this->fixture->run,
            ]),
        );
        return $this->fixture->row('appointments', (int) $db->insert_id());
    }

    private function moveAppointment(int $id, int $minutesFromNow): void
    {
        $start = time() + $minutesFromNow * 60;
        $db = get_instance()->db;
        self::assertTrue(
            $db->where('id', $id)->update('appointments', [
                'start_datetime' => date('Y-m-d H:i:s', $start),
                'end_datetime' => date('Y-m-d H:i:s', $start + 1800),
            ]),
        );
    }
}

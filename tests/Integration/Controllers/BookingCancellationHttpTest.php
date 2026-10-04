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
    /** @var list<int> */
    private array $ownedAppointmentIds = [];
    /** @var array<int, array{customerId:int, parentId:?int}> */
    private array $ownedAppointmentExpectations = [];

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
            $db = get_instance()->db;
            $ids = $this->ownedAppointmentIds;
            usort(
                $ids,
                fn(int $left, int $right): int => (($this->ownedAppointmentExpectations[$left]['parentId'] ?? null) ===
                null
                    ? 1
                    : 0) <=> (($this->ownedAppointmentExpectations[$right]['parentId'] ?? null) === null ? 1 : 0),
            );
            foreach ($ids as $id) {
                $row = $db->get_where('appointments', ['id' => $id])->row_array();
                if (!$row) {
                    continue;
                }
                $expected = $this->ownedAppointmentExpectations[$id] ?? null;
                if ($expected === null) {
                    throw new RuntimeException('Owned appointment identity was not registered.');
                }
                $this->assertOwnedAppointmentRow($row, $expected['customerId'], $expected['parentId']);
                $db->delete('reschedule_authorities', ['appointment_id' => $id]);
                if (!$db->delete('appointments', ['id' => $id])) {
                    throw new RuntimeException('Owned appointment cleanup failed.');
                }
                if ($db->get_where('appointments', ['id' => $id])->num_rows() !== 0) {
                    throw new RuntimeException('Owned appointment cleanup was not confirmed.');
                }
            }
            $this->fixture?->cleanup();
        }
    }

    public function testDuplicateHashCancellationFailsClosedWithoutCrossCustomerMutation(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;

        $first = $fixture->appointment();
        $this->registerOwnedAppointment($first, $fixture->customerId, null);
        $secondCustomer = $fixture->customerWritePayload('duplicate-cancellation');
        $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
        self::assertNotEmpty($customerRole);
        self::assertTrue(
            (bool) $db->insert(
                'users',
                $secondCustomer + [
                    'timezone' => 'UTC',
                    'language' => 'english',
                    'id_roles' => (int) $customerRole['id'],
                    'is_private' => 0,
                ],
            ),
        );
        $secondCustomerId = (int) $db->insert_id();
        self::assertGreaterThan(0, $secondCustomerId);

        $second = $fixture->appointment();
        $this->registerOwnedAppointment($second, $fixture->customerId, null);
        self::assertTrue(
            (bool) $db->update(
                'appointments',
                ['id_users_customer' => $secondCustomerId, 'hash' => $first['hash']],
                ['id' => (int) $second['id']],
            ),
        );
        $this->ownedAppointmentExpectations[(int) $second['id']]['customerId'] = $secondCustomerId;
        $first = $fixture->row('appointments', (int) $first['id']);
        $second = $fixture->row('appointments', (int) $second['id']);
        self::assertSame($first['hash'], $second['hash']);

        $firstBuffer = $this->addBuffer($first);
        $this->registerOwnedAppointment($firstBuffer, 0, (int) $first['id']);
        $secondBuffer = $this->addBuffer($second);
        $this->registerOwnedAppointment($secondBuffer, 0, (int) $second['id']);
        $before = [
            'appointments' => [
                $fixture->row('appointments', (int) $first['id']),
                $fixture->row('appointments', (int) $second['id']),
                $fixture->row('appointments', (int) $firstBuffer['id']),
                $fixture->row('appointments', (int) $secondBuffer['id']),
            ],
            'users' => [
                $fixture->row('users', $fixture->customerId),
                $fixture->row('users', $secondCustomerId),
                $fixture->row('users', $fixture->providerId),
            ],
            'user_settings' => $db->get_where('user_settings', ['id_users' => $fixture->providerId])->result_array(),
            'services' => [$fixture->row('services', $fixture->serviceId)],
            'services_providers' => $db
                ->get_where('services_providers', [
                    'id_users' => $fixture->providerId,
                    'id_services' => $fixture->serviceId,
                ])
                ->result_array(),
        ];

        $client = $this->server?->client();
        self::assertNotNull($client);
        $duplicateResponse = $client->requestApp('POST', 'booking_cancellation/of/' . $first['hash'], [], 15);
        self::assertSame(200, $duplicateResponse->statusCode);
        self::assertStringNotContainsString(
            '<h4 class="mb-5">' . lang('appointment_cancelled_title') . '</h4>',
            $duplicateResponse->body,
        );
        self::assertStringContainsString(
            '<h4 class="mb-5">' . lang('appointment_not_found') . '</h4>',
            $duplicateResponse->body,
        );
        self::assertStringContainsString(lang('appointment_does_not_exist_in_db'), $duplicateResponse->body);
        self::assertSame(
            $before,
            $this->cancellationSnapshot($first, $second, $firstBuffer, $secondBuffer, $secondCustomerId),
        );

        $unknownResponse = $client->requestApp('POST', 'booking_cancellation/of/' . str_repeat('unknown-', 8), [], 15);
        self::assertSame($duplicateResponse->statusCode, $unknownResponse->statusCode);
        self::assertStringNotContainsString(
            '<h4 class="mb-5">' . lang('appointment_cancelled_title') . '</h4>',
            $unknownResponse->body,
        );
        self::assertStringContainsString(
            '<h4 class="mb-5">' . lang('appointment_not_found') . '</h4>',
            $unknownResponse->body,
        );
        self::assertStringContainsString(lang('appointment_does_not_exist_in_db'), $unknownResponse->body);
        self::assertSame(
            $before,
            $this->cancellationSnapshot($first, $second, $firstBuffer, $secondBuffer, $secondCustomerId),
        );
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

        $replayResponse = $client->requestApp('POST', 'booking_cancellation/of/' . $future['hash'], [], 15);
        self::assertSame(200, $replayResponse->statusCode);
        self::assertStringContainsString(lang('appointment_not_found'), $replayResponse->body);
        self::assertStringContainsString(lang('appointment_does_not_exist_in_db'), $replayResponse->body);
        self::assertSame([], $fixture->row('appointments', (int) $future['id']));
        self::assertSame([], $fixture->row('appointments', (int) $futureBuffer['id']));
        self::assertSame($near, $fixture->row('appointments', (int) $near['id']));
        self::assertSame($nearBuffer, $fixture->row('appointments', (int) $nearBuffer['id']));
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

    public function testCancellationCutoffUsesProviderTimezone(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;

        self::assertTrue($db->update('settings', ['value' => '60'], ['name' => 'book_advance_timeout']));
        self::assertTrue(
            $db->where('id', $fixture->providerId)->update('users', [
                'timezone' => 'Pacific/Kiritimati',
            ]),
        );

        $appointment = $fixture->appointment();
        $providerTimezone = new DateTimeZone('Pacific/Kiritimati');
        $start = (new DateTimeImmutable('now', $providerTimezone))->modify('+30 minutes');
        self::assertTrue(
            $db->where('id', (int) $appointment['id'])->update('appointments', [
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
            ]),
        );
        $before = $fixture->row('appointments', (int) $appointment['id']);

        $response = $this->server
            ?->client()
            ->requestApp('POST', 'booking_cancellation/of/' . $appointment['hash'], [], 15);

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame($before, $fixture->row('appointments', (int) $appointment['id']));
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

    /** @param array<string, mixed> $appointment */
    private function registerOwnedAppointment(array $appointment, int $customerId, ?int $parentId): void
    {
        $id = (int) ($appointment['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Owned appointment fixture has no valid ID.');
        }
        $this->ownedAppointmentIds[] = $id;
        $this->ownedAppointmentExpectations[$id] = ['customerId' => $customerId, 'parentId' => $parentId];
    }

    /** @param array<string, mixed> $row */
    private function assertOwnedAppointmentRow(array $row, int $customerId, ?int $parentId): void
    {
        $fixture = $this->fixture;
        if (
            (int) ($row['id_users_provider'] ?? 0) !== $fixture->providerId ||
            ($row['notes'] ?? null) !== $fixture->run
        ) {
            throw new RuntimeException('Owned appointment provider or notes identity changed.');
        }
        if ($parentId === null) {
            if (
                (int) ($row['id_services'] ?? 0) !== $fixture->serviceId ||
                (int) ($row['id_users_customer'] ?? 0) !== $customerId ||
                (int) ($row['is_unavailability'] ?? 0) !== 0 ||
                (int) ($row['id_parent_appointment'] ?? 0) !== 0
            ) {
                throw new RuntimeException('Owned ordinary appointment identity changed.');
            }
            return;
        }
        if (
            (int) ($row['id_parent_appointment'] ?? 0) !== $parentId ||
            (int) ($row['is_unavailability'] ?? 0) !== 1 ||
            (int) ($row['id_services'] ?? 0) !== 0 ||
            (int) ($row['id_users_customer'] ?? 0) !== 0
        ) {
            throw new RuntimeException('Owned buffer appointment identity changed.');
        }
    }

    /** @return array<string, mixed> */
    private function cancellationSnapshot(
        array $first,
        array $second,
        array $firstBuffer,
        array $secondBuffer,
        int $secondCustomerId,
    ): array {
        $fixture = $this->fixture;
        $db = get_instance()->db;
        return [
            'appointments' => [
                $fixture->row('appointments', (int) $first['id']),
                $fixture->row('appointments', (int) $second['id']),
                $fixture->row('appointments', (int) $firstBuffer['id']),
                $fixture->row('appointments', (int) $secondBuffer['id']),
            ],
            'users' => [
                $fixture->row('users', $fixture->customerId),
                $fixture->row('users', $secondCustomerId),
                $fixture->row('users', $fixture->providerId),
            ],
            'user_settings' => $db->get_where('user_settings', ['id_users' => $fixture->providerId])->result_array(),
            'services' => [$fixture->row('services', $fixture->serviceId)],
            'services_providers' => $db
                ->get_where('services_providers', [
                    'id_users' => $fixture->providerId,
                    'id_services' => $fixture->serviceId,
                ])
                ->result_array(),
        ];
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\CalendarResponsibilityRaceProbe;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/CalendarResponsibilityRaceProbe.php';
require_once __DIR__ . '/Support/DefenseCycleFixtures.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class CalendarResponsibilityRaceProbeTest extends TestCase
{
    public function testRequestAttributionFailsClosedWhenTwoNewMatchingProcessesExist(): void
    {
        $processes = [
            ['Id' => 901, 'Info' => 'SELECT `id` FROM `ea_users` WHERE `id` IN (11, 22) ORDER BY `id` ASC FOR UPDATE'],
            ['Id' => 902, 'Info' => 'SELECT `id` FROM `ea_users` WHERE `id` IN (11, 22) ORDER BY `id` ASC FOR UPDATE'],
        ];

        self::assertSame(
            0,
            CalendarResponsibilityRaceProbe::attributeConnectionId($processes, [100], [200], 'ea_users', [11, 22]),
        );
    }

    public function testRequestAttributionAcceptsTheOnlyNewExactParentLockProcess(): void
    {
        $processes = [
            ['Id' => 700, 'Info' => 'SELECT `id` FROM `ea_users` WHERE `id` IN (11, 22) ORDER BY `id` ASC FOR UPDATE'],
            ['Id' => 901, 'Info' => 'SELECT `id` FROM `ea_users` WHERE `id` IN (11, 22) ORDER BY `id` ASC FOR UPDATE'],
        ];

        self::assertSame(
            901,
            CalendarResponsibilityRaceProbe::attributeConnectionId($processes, [100], [700], 'ea_users', [11, 22]),
        );
    }

    public function testRequestAttributionDoesNotAcceptGenericActorOnlyQuery(): void
    {
        self::assertSame(
            0,
            CalendarResponsibilityRaceProbe::attributeConnectionId(
                [['Id' => 901, 'Info' => 'SELECT id FROM ea_users WHERE id = 22 FOR UPDATE']],
                [],
                [],
                'ea_users',
                [11, 22],
            ),
        );
    }

    public function testRequestAttributionDoesNotAcceptSameActorWithDifferentCustomer(): void
    {
        self::assertSame(
            0,
            CalendarResponsibilityRaceProbe::attributeConnectionId(
                [
                    [
                        'Id' => 901,
                        'Info' => 'SELECT `id` FROM `ea_users` WHERE `id` IN (22, 99) ORDER BY `id` ASC FOR UPDATE',
                    ],
                ],
                [],
                [],
                'ea_users',
                [11, 22],
            ),
        );
    }

    public function testObservedParentLockWaitThenConcurrentResponsibilityChangeIsRejected(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        $fixture = new DefenseCycleFixtures();
        $server = null;
        $foreignProviderId = 0;
        try {
            $fixture->create();
            $db = get_instance()->db;
            $providerRole = $db->get_where('roles', ['slug' => 'provider'])->row_array();
            self::assertNotEmpty($providerRole);
            $db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => 'Foreign Provider',
                'email' => $fixture->run . '_foreign@synthetic.invalid',
                'phone_number' => '000000000',
                'notes' => $fixture->run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $providerRole['id'],
                'is_private' => 1,
            ]);
            $foreignProviderId = (int) $db->insert_id();
            $salt = generate_salt();
            $db->insert('user_settings', [
                'id_users' => $foreignProviderId,
                'username' => $fixture->run . '_foreign',
                'password' => hash_password($salt, bin2hex(random_bytes(24))),
                'salt' => $salt,
                'working_plan' => '{}',
                'working_plan_exceptions' => '{}',
                'notifications' => 0,
                'google_sync' => 0,
                'caldav_sync' => 0,
                'calendar_view' => 'default',
            ]);
            $db->insert('services_providers', [
                'id_users' => $foreignProviderId,
                'id_services' => $fixture->serviceId,
            ]);
            $appointment = $fixture->appointment();
            $server = new DefenseCycleHttpServer();
            $actor = $fixture->row('users', $fixture->providerId);
            $actorContext = [
                'user_id' => $fixture->providerId,
                'username' => $fixture->run . '_provider',
                'password' => $fixture->password,
                'email' => (string) $actor['email'],
                'marker' => (string) $actor['notes'],
            ];
            $raceFixture = [
                'profile' => 'calendar_race',
                'marker' => $fixture->run,
                'appointment_id' => (int) $appointment['id'],
                'foreign_provider_id' => $foreignProviderId,
                'customer_id' => $fixture->customerId,
                'service_id' => $fixture->serviceId,
            ];
            $result = (new CalendarResponsibilityRaceProbe(
                new \ReleaseGate\GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Ordinary-Probe' => '1']),
                $db,
                $server->baseUrl,
            ))->run($actorContext, $raceFixture);

            self::assertSame('verified', $result['status']);
            self::assertSame('targeted_concurrent_schedule', $result['coverage']);
            self::assertSame(403, $result['request_status']);
            self::assertTrue($result['wait_observed']);
            self::assertTrue($result['reassignment_committed']);
            self::assertTrue($result['appointment_unchanged_except_provider']);
            self::assertSame(
                $fixture->providerId,
                (int) $fixture->row('appointments', (int) $appointment['id'])['id_users_provider'],
            );

            $driftAppointment = $fixture->appointment();
            $originalStart = (string) $driftAppointment['start_datetime'];
            $driftedStart = date('Y-m-d H:i:s', strtotime($originalStart . ' +2 hours'));
            $driftError = null;
            try {
                (new CalendarResponsibilityRaceProbe(
                    new \ReleaseGate\GateHttpClient(
                        $server->baseUrl,
                        additionalHeaders: ['X-FH-Ordinary-Probe' => '1'],
                    ),
                    $db,
                    $server->baseUrl,
                ))->run(
                    [...$actorContext],
                    [...$raceFixture, 'appointment_id' => (int) $driftAppointment['id']],
                    static function (string $phase, string $outcome) use ($db, $driftAppointment, $driftedStart): void {
                        if ($phase === 'race_response' && $outcome === 'passed') {
                            $db->update(
                                'appointments',
                                ['start_datetime' => $driftedStart],
                                ['id' => $driftAppointment['id']],
                            );
                        }
                    },
                );
            } catch (\Throwable $error) {
                $driftError = $error;
            }
            self::assertInstanceOf(\Throwable::class, $driftError);
            self::assertSame(
                $driftedStart,
                (string) $fixture->row('appointments', (int) $driftAppointment['id'])['start_datetime'],
            );
            $db->update(
                'appointments',
                ['start_datetime' => $originalStart, 'id_users_provider' => $fixture->providerId],
                ['id' => $driftAppointment['id']],
            );
        } finally {
            try {
                $server?->close();
            } finally {
                if ($foreignProviderId > 0) {
                    get_instance()->db->delete('services_providers', ['id_users' => $foreignProviderId]);
                    get_instance()->db->delete('user_settings', ['id_users' => $foreignProviderId]);
                    get_instance()->db->delete('users', [
                        'id' => $foreignProviderId,
                        'notes' => $fixture->run,
                    ]);
                }
                $fixture->cleanup();
            }
        }
    }
}

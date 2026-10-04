<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\AppointmentsApiReadProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/AppointmentsApiReadProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class AppointmentsApiReadProbeTest extends TestCase
{
    public function testOwnedReadProjectionUsesSyntheticGraphAndCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-appointments-api-read-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        try {
            $actor = $ordinary->activate();
            $fixture->activate('calendar_race', $actor);
            $state = $fixture->prepareAppointmentsApi();
            $server = new DefenseCycleHttpServer();
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_synthetic');
            $result = AppointmentsApiReadProbe::forApp(
                $server->baseUrl,
                $state['api_credentials']['username'],
                $state['api_credentials']['password'],
                $fixture,
            )->run($evidence->step(...));
            $events = $evidence->read()['events'];

            self::assertSame('verified', $result['status']);
            self::assertSame('collection_show_alias_relations_fields', $result['coverage']);
            self::assertSame(
                [
                    'collection' => 200,
                    'collection_alias' => 200,
                    'show' => 200,
                    'show_alias' => 200,
                    'fields_show' => 200,
                    'fields_collection' => 200,
                ],
                $result['statuses'],
            );
            self::assertSame('absent', $result['public_booking_option']);
            self::assertSame(
                [
                    'appointments_api_read_collection',
                    'appointments_api_read_collection_alias',
                    'appointments_api_read_show',
                    'appointments_api_read_show_alias',
                    'appointments_api_read_fields_show',
                    'appointments_api_read_fields_collection',
                ],
                array_column(
                    array_values(
                        array_filter($events, static fn(array $event): bool => $event['outcome'] === 'passed'),
                    ),
                    'phase',
                ),
            );
        } finally {
            $server?->close();
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($directory . '/' . $entry);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        self::assertSame('clean', $fixture->verify());
        self::assertSame('clean', $ordinary->verify());
    }
}

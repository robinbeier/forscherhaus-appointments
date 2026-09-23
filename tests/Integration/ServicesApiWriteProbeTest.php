<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\ServicesApiWriteProbe;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/ServicesApiWriteProbe.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class ServicesApiWriteProbeTest extends TestCase
{
    public function testBoundedServicesApiMatrixUsesOwnedRowsAndCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-services-api-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('services_api', $actor);
            $server = new DefenseCycleHttpServer();
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_services_api_test');
            $result = ServicesApiWriteProbe::forApp(
                $server->baseUrl,
                $actor['username'],
                $actor['password'],
                $fixture,
            )->run($evidence->step(...));

            self::assertSame('verified', $result['status']);
            self::assertSame(400, $result['conflict_status']);
            self::assertSame(200, $result['matching_status']);
            self::assertSame(405, $result['wrong_verb_status']);
            self::assertSame(400, $result['invalid_status']);
            self::assertSame(
                [
                    ['services_api_conflict_put', 'started'],
                    ['services_api_conflict_put', 'passed'],
                    ['services_api_matching_put', 'started'],
                    ['services_api_matching_put', 'passed'],
                    ['services_api_wrong_verb_destroy', 'started'],
                    ['services_api_wrong_verb_destroy', 'passed'],
                    ['services_api_invalid_duration', 'started'],
                    ['services_api_invalid_duration', 'passed'],
                    ['services_api_invalid_attendantsNumber', 'started'],
                    ['services_api_invalid_attendantsNumber', 'passed'],
                ],
                array_map(
                    static fn(array $event): array => [$event['phase'], $event['outcome']],
                    $evidence->read()['events'],
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
    }
}

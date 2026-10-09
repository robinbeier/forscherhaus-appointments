<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\CalendarCrossTypeLiveProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\GateHttpClient;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\OrdinaryProbeSessions;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeSessions.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/CalendarCrossTypeLiveProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class CalendarCrossTypeLiveProbeTest extends TestCase
{
    public function testCrossTypeDenialsUseOwnedRowsAndCleanExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-calendar-cross-type-live-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        $sessions = null;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('unavailabilities_api', $actor);
            $server = new DefenseCycleHttpServer(recordRequests: true);
            $sessions = new OrdinaryProbeSessions($directory, $server->directory . '/sessions');
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_calendar_cross_type_test');

            $client = new GateHttpClient(
                $server->baseUrl,
                additionalHeaders: [
                    'X-FH-Ordinary-Probe' => '1',
                    'Authorization' => 'Basic ' . base64_encode($actor['username'] . ':' . $actor['password']),
                ],
            );
            $publicClient = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Ordinary-Probe' => '1']);
            $result = (new CalendarCrossTypeLiveProbe(
                $client,
                $publicClient,
                $ordinary,
                $fixture,
                $sessions->remember(...),
            ))->run($evidence->step(...));

            self::assertSame('verified', $result['status']);
            self::assertSame('calendar_cross_type_denials', $result['coverage']);
            self::assertSame(
                [
                    'create' => 403,
                    'update' => 403,
                    'delete' => 403,
                ],
                $result['statuses'],
            );
            self::assertSame(
                [
                    'calendar_cross_type_create_denial',
                    'calendar_cross_type_update_denial',
                    'calendar_cross_type_delete_denial',
                ],
                array_column(
                    array_values(
                        array_filter(
                            $evidence->read()['events'],
                            static fn(array $event): bool => $event['outcome'] === 'passed',
                        ),
                    ),
                    'phase',
                ),
            );
            self::assertSame('absent', $result['public_service_exclusion']);
            $ledger = $server->requestLedger();
            $bookingIndex = null;
            $writeIndexes = [];
            foreach ($ledger as $index => $request) {
                if ($request['method'] === 'GET' && str_contains($request['uri'], '/booking')) {
                    $bookingIndex = $index;
                }
                if ($request['method'] === 'POST' && str_contains($request['uri'], '/calendar/')) {
                    $writeIndexes[] = $index;
                }
            }
            self::assertNotNull($bookingIndex);
            self::assertCount(3, $writeIndexes);
            self::assertLessThan($writeIndexes[0], $bookingIndex, 'Public exclusion must precede every write.');
            self::assertFileExists($directory . '/sessions.json');
            $sessions->cleanup();
            self::assertFileDoesNotExist($directory . '/sessions.json');
            self::assertSame([], glob($server->directory . '/sessions/*'));
        } finally {
            if ($sessions !== null && is_file($directory . '/sessions.json')) {
                $sessions->cleanup();
            }
            $server?->close();
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }

        self::assertSame('clean', $fixture->verify());
        self::assertSame('clean', $ordinary->verify());
    }

    private function removePrivateDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($directory . '/' . $entry);
            }
        }
        rmdir($directory);
    }
}

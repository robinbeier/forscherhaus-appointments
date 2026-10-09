<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\CalendarCrossTypeLiveProbe;
use ReleaseGate\CalendarCrossTypeRequestUnconfirmed;
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

    public function testUnexpectedWriteResponseRetainsRecoveryInsteadOfClaimingDenial(): void
    {
        $this->requireIsolatedStack();
        $directory = '/var/lib/fh-calendar-cross-type-live-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        $sessions = null;
        $recoveryCalls = 0;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('unavailabilities_api', $actor);
            $before = $fixture->unavailabilitiesApiSnapshot();
            $server = new DefenseCycleHttpServer(recordRequests: true, failCalendarWrites: true);
            $sessions = new OrdinaryProbeSessions($directory, $server->directory . '/sessions');
            $client = $this->client($server, $actor);
            $publicClient = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Ordinary-Probe' => '1']);

            try {
                (new CalendarCrossTypeLiveProbe(
                    $client,
                    $publicClient,
                    $ordinary,
                    $fixture,
                    $sessions->remember(...),
                    static function () use (&$recoveryCalls): void {
                        $recoveryCalls++;
                    },
                ))->run();
                self::fail('A 502 response must not count as cross-type denial.');
            } catch (CalendarCrossTypeRequestUnconfirmed $error) {
                self::assertStringContainsString('outcome was not confirmed', $error->getMessage());
            }
            self::assertSame(1, $recoveryCalls);
            self::assertSame($before, $fixture->unavailabilitiesApiSnapshot());
            $writes = array_values(
                array_filter(
                    $server->requestLedger(),
                    static fn(array $request): bool => $request['method'] === 'POST' &&
                        str_contains($request['uri'], '/calendar/'),
                ),
            );
            self::assertCount(1, $writes, 'An unconfirmed first write must stop the remaining planned writes.');
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

    public function testMissingStoredDeletePermissionCannotProduceFalsePositive(): void
    {
        $this->requireIsolatedStack();
        $directory = '/var/lib/fh-calendar-cross-type-live-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        $roleId = 0;
        $originalMask = null;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('unavailabilities_api', $actor);
            $server = new DefenseCycleHttpServer(recordRequests: true);
            $roleId = (int) $actor['role_id'];
            $ci = &get_instance();
            $role = $ci->db->get_where('roles', ['id' => $roleId])->row_array();
            self::assertNotEmpty($role);
            $originalMask = (int) $role['appointments'];
            self::assertSame(PRIV_DELETE, $originalMask & PRIV_DELETE);
            self::assertTrue(
                $ci->db->update('roles', ['appointments' => $originalMask & ~PRIV_DELETE], ['id' => $roleId]),
            );

            try {
                (new CalendarCrossTypeLiveProbe(
                    $this->client($server, $actor),
                    new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Ordinary-Probe' => '1']),
                    $ordinary,
                    $fixture,
                ))->run();
                self::fail('A role-level delete denial must not prove the type boundary.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('lacks appointments.delete', $error->getMessage());
            }
            $writes = array_filter(
                $server->requestLedger(),
                static fn(array $request): bool => $request['method'] === 'POST' &&
                    str_contains($request['uri'], '/calendar/'),
            );
            self::assertSame([], array_values($writes), 'Permission preflight must stop before every calendar write.');
        } finally {
            if ($roleId > 0 && $originalMask !== null) {
                get_instance()->db->update('roles', ['appointments' => $originalMask], ['id' => $roleId]);
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

    private function requireIsolatedStack(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
    }

    private function client(DefenseCycleHttpServer $server, array $actor): GateHttpClient
    {
        return new GateHttpClient(
            $server->baseUrl,
            additionalHeaders: [
                'X-FH-Ordinary-Probe' => '1',
                'Authorization' => 'Basic ' . base64_encode($actor['username'] . ':' . $actor['password']),
            ],
        );
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

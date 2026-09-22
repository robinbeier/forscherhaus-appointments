<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\AppointmentsApiWriteProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/AppointmentsApiWriteProbe.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class AppointmentsApiWriteProbeTest extends TestCase
{
    public function testOwnedBasicAndBearerWriteMatrixCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-appointments-api-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        $db = get_instance()->db;
        $tokenRows = $db->get_where('settings', ['name' => 'api_token'])->result_array();
        if (count($tokenRows) !== 1 || (int) ($tokenRows[0]['id'] ?? 0) < 1) {
            self::fail('The isolated stack must contain exactly one API token setting.');
        }
        $tokenRow = $tokenRows[0];
        self::assertTrue(
            $db->update('settings', ['value' => ''], ['id' => (int) $tokenRow['id'], 'name' => 'api_token']),
        );
        try {
            $actor = $ordinary->activate();
            $fixture->activate('calendar_race', $actor);
            $queryCount = count($db->queries);
            $token = $fixture->prepareAppointmentsApiBearerToken();
            self::assertFalse(
                str_contains(json_encode(array_slice($db->queries, $queryCount), JSON_THROW_ON_ERROR), $token),
            );
            self::assertFalse(str_contains((string) $db->last_query(), $token));
            $state = $fixture->prepareAppointmentsApi();
            $journalText = (string) file_get_contents($directory . '/defense-verification.json');
            $journal = json_decode($journalText, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue(hash_equals(hash('sha256', $token), $journal['intents']['api_token']['candidate_digest']));
            self::assertFalse(str_contains($journalText, $token));
            $server = new DefenseCycleHttpServer();
            $probe = AppointmentsApiWriteProbe::forApp(
                $server->baseUrl,
                $state['api_credentials']['username'],
                $state['api_credentials']['password'],
                $token,
                $fixture,
            );
            self::assertInstanceOf(AppointmentsApiWriteProbe::class, $probe);
            $result = $probe->run();
            self::assertSame('verified', $result['status']);
            self::assertSame(21, $result['denial_cases']);
            self::assertSame(
                [
                    'basic' => ['post' => 201, 'put' => 200, 'delete' => 204, 'delete_repeat' => 404],
                    'bearer' => ['post' => 201, 'put' => 200, 'delete' => 204, 'delete_repeat' => 404],
                ],
                $result['auth_statuses'],
            );
            self::assertFalse(str_contains(json_encode($result, JSON_THROW_ON_ERROR), $token));
            self::assertNotEmpty($fixture->apiSnapshot()['sentinel']);
            $fixture->deactivate();
            $restored = $db->get_where('settings', ['id' => (int) $tokenRow['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
        } finally {
            $server?->close();
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            $db->update(
                'settings',
                ['value' => $tokenRow['value']],
                ['id' => (int) $tokenRow['id'], 'name' => 'api_token'],
            );
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

    public function testOwnedOverlapProbeUsesTwoHttpServersAndCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-appointments-api-overlap-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $primaryServer = null;
        $peerServer = null;
        $db = get_instance()->db;
        $tokenRow = $db->get_where('settings', ['name' => 'api_token'])->row_array();
        self::assertIsArray($tokenRow);
        $token = bin2hex(random_bytes(32));
        try {
            $actor = $ordinary->activate();
            $fixture->activate('calendar_race', $actor);
            $state = $fixture->prepareAppointmentsApi();
            self::assertTrue($db->update('settings', ['value' => $token], ['name' => 'api_token']));
            $primaryServer = new DefenseCycleHttpServer();
            $peerServer = new DefenseCycleHttpServer();
            $probe = AppointmentsApiWriteProbe::forApp(
                $primaryServer->baseUrl,
                $state['api_credentials']['username'],
                $state['api_credentials']['password'],
                $token,
                $fixture,
                concurrentBaseUrl: $peerServer->baseUrl,
            );
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_test_overlap');
            $result = $probe->runOverlap([$evidence, 'step']);
            self::assertSame('verified', $result['status']);
            self::assertSame('bounded_overlap', $result['coverage']);
            self::assertSame(409, $result['auth_statuses']['basic_overlap']);
            self::assertSame(409, $result['auth_statuses']['bearer_overlap']);
            self::assertSame(201, $result['auth_statuses']['adjacent_post']);
            self::assertSame([200, 409], $result['auth_statuses']['parallel_put']);
            self::assertSame(
                [
                    ['phase' => 'appointments_api_overlap_adjacency', 'outcome' => 'started'],
                    ['phase' => 'appointments_api_overlap_adjacency', 'outcome' => 'passed'],
                    ['phase' => 'appointments_api_overlap_auth_denials', 'outcome' => 'started'],
                    ['phase' => 'appointments_api_overlap_auth_denials', 'outcome' => 'passed'],
                    ['phase' => 'appointments_api_overlap_parallel_dispatch', 'outcome' => 'started'],
                    ['phase' => 'appointments_api_overlap_parallel_dispatch', 'outcome' => 'passed'],
                ],
                array_map(
                    static fn(array $event): array => [
                        'phase' => $event['phase'],
                        'outcome' => $event['outcome'],
                    ],
                    $evidence->read()['events'],
                ),
            );
            self::assertNotEmpty($fixture->apiSnapshot()['sentinel']);
        } finally {
            $peerServer?->close();
            $primaryServer?->close();
            $db->update('settings', ['value' => $tokenRow['value']], ['name' => 'api_token']);
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

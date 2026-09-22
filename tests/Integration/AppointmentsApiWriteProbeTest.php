<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\AppointmentsApiWriteProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\GateHttpClient;
use ReleaseGate\OrdinaryLiveFixture;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/AppointmentsApiWriteProbe.php';
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
        $tokenRow = $db->get_where('settings', ['name' => 'api_token'])->row_array();
        self::assertIsArray($tokenRow);
        $token = bin2hex(random_bytes(32));
        try {
            $actor = $ordinary->activate();
            $fixture->activate('calendar_race', $actor);
            $state = $fixture->prepareAppointmentsApi();
            self::assertTrue($db->update('settings', ['value' => $token], ['name' => 'api_token']));
            $server = new DefenseCycleHttpServer();
            $client = static fn(string $authorization): GateHttpClient => new GateHttpClient(
                $server->baseUrl,
                additionalHeaders: ['X-FH-Ordinary-Probe' => '1', 'Authorization' => $authorization],
            );
            $result = (new AppointmentsApiWriteProbe(
                $client(
                    'Basic ' .
                        base64_encode(
                            $state['api_credentials']['username'] . ':' . $state['api_credentials']['password'],
                        ),
                ),
                $client('Bearer ' . $token),
                $fixture,
            ))->run();
            self::assertSame('verified', $result['status']);
            self::assertSame(21, $result['denial_cases']);
            self::assertSame(
                [
                    'basic' => ['post' => 201, 'put' => 200, 'delete' => 204, 'delete_repeat' => 404],
                    'bearer' => ['post' => 201, 'put' => 200, 'delete' => 204, 'delete_repeat' => 404],
                ],
                $result['auth_statuses'],
            );
            self::assertNotEmpty($fixture->apiSnapshot()['sentinel']);
        } finally {
            $server?->close();
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

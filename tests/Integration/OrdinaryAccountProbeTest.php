<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\OrdinaryAccountProbe;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryAccountProbe.php';
require_once __DIR__ . '/Support/DefenseCycleFixtures.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class OrdinaryAccountProbeTest extends TestCase
{
    public function testOrdinarySyntheticAccountProbe(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        $fixture = new DefenseCycleFixtures();
        $server = null;
        try {
            $fixture->create();
            $server = new DefenseCycleHttpServer();
            $client = new GateHttpClient(
                $server->baseUrl,
                'index.php',
                15,
                'ordinary-account-probe/1.0',
                'csrf_cookie',
                'csrf_token',
                [
                    'X-FH-Ordinary-Probe' => '1',
                ],
            );
            $result = (new OrdinaryAccountProbe($client, \get_instance()->db))->run([
                'user_id' => $fixture->actorId,
                'username' => $fixture->run . '_actor',
                'password' => $fixture->password,
                'run_id' => $fixture->run,
            ]);
            self::assertSame('verified', $result['status']);
            self::assertSame('partial', $result['coverage']);
            self::assertSame(405, $result['get_save_status']);
            self::assertSame(200, $result['save_status']);
            self::assertSame(200, $result['logout_status']);
            self::assertSame(307, $result['post_logout_account_status']);
        } finally {
            try {
                $server?->close();
            } finally {
                $fixture->cleanup();
            }
        }
    }
}

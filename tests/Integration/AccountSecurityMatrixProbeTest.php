<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\AccountSecurityMatrixProbe;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/AccountSecurityMatrixProbe.php';
require_once __DIR__ . '/Support/DefenseCycleFixtures.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class AccountSecurityMatrixProbeTest extends TestCase
{
    public function testCompleteOwnedSyntheticMethodAndCsrfMatrix(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        $fixture = new DefenseCycleFixtures();
        $server = null;
        try {
            $fixture->create();
            $server = new DefenseCycleHttpServer();
            $user = $fixture->row('users', $fixture->actorId);
            $sessions = [];
            $events = [];
            $result = (new AccountSecurityMatrixProbe(
                static fn(): GateHttpClient => new GateHttpClient(
                    $server->baseUrl,
                    additionalHeaders: ['X-FH-Ordinary-Probe' => '1'],
                ),
                get_instance()->db,
                static function (?string $session) use (&$sessions): void {
                    if (is_string($session) && $session !== '') {
                        $sessions[$session] = true;
                    }
                },
            ))->run(
                [
                    'user_id' => $fixture->actorId,
                    'username' => $fixture->run . '_actor',
                    'password' => $fixture->password,
                    'email' => (string) $user['email'],
                    'marker' => (string) $user['notes'],
                ],
                static function (string $phase, string $outcome) use (&$events): void {
                    $events[] = [$phase, $outcome];
                },
            );

            self::assertSame('verified', $result['status']);
            self::assertSame('complete', $result['coverage']);
            self::assertSame(
                ['get' => 405, 'head' => 405, 'put' => 405, 'patch' => 405, 'delete' => 405, 'options' => 200],
                $result['method_statuses'],
            );
            self::assertSame(['missing' => 403, 'invalid' => 403], $result['csrf_statuses']);
            self::assertSame(200, $result['valid_post_status']);
            self::assertNotEmpty($sessions);
            foreach (
                [
                    'method_get',
                    'method_head',
                    'method_put',
                    'method_patch',
                    'method_delete',
                    'method_options',
                    'csrf_missing',
                    'csrf_invalid',
                    'csrf_valid_post',
                ]
                as $phase
            ) {
                self::assertContains([$phase, 'passed'], $events);
            }
        } finally {
            try {
                $server?->close();
            } finally {
                $fixture->cleanup();
            }
        }
    }
}

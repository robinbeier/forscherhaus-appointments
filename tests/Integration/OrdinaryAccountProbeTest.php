<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReleaseGate\OrdinaryAccountProbe;
use ReleaseGate\GateHttpClient;
use ReleaseGate\OrdinaryLiveFixture;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryAccountProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';

final class OrdinaryAccountProbeTest extends TestCase
{
    public static function scenarios(): array
    {
        return [
            'normal' => [false, false],
            'failure-before-logout' => [true, false],
            'missing-snapshot' => [false, true],
        ];
    }

    #[DataProvider('scenarios')]
    public function testOrdinarySyntheticAccountProbe(bool $failPostSave, bool $failSnapshot): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        $stateDirectory = '/var/lib/fh-ordinary-live-probe-' . bin2hex(random_bytes(8));
        $fixture = new OrdinaryLiveFixture($stateDirectory);
        $server = null;
        try {
            $state = $fixture->activate();
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
            $sessions = [];
            $events = [];
            $rememberCount = 0;
            $failure = null;
            $result = null;
            try {
                $result = (new OrdinaryAccountProbe($client, \get_instance()->db, static function (
                    ?string $session,
                ) use (&$sessions, &$rememberCount, $failPostSave): void {
                    $rememberCount++;
                    if ($failPostSave && $rememberCount === 5) {
                        throw new RuntimeException('synthetic diagnostic failure');
                    }
                    if ($session !== null && $session !== '') {
                        $sessions[] = $session;
                    }
                }))->run(
                    [
                        'user_id' => (int) $state['user_id'],
                        'username' => (string) $state['username'],
                        'password' => (string) $state['password'],
                        'run_id' => (string) $state['run_id'],
                        'email' => (string) $state['email'],
                        'marker' => (string) $state['marker'] . ($failSnapshot ? '-missing' : ''),
                    ],
                    static function (string $phase, string $outcome) use (&$events): void {
                        $events[] = [$phase, $outcome];
                    },
                );
            } catch (RuntimeException $error) {
                $failure = $error;
            }
            if ($failSnapshot) {
                self::assertNotNull($failure);
                self::assertSame('Synthetic account snapshot is incomplete.', $failure->getMessage());
                self::assertSame([['account_snapshot', 'started'], ['account_snapshot', 'failed']], $events);
                self::assertNull($result);
                self::assertSame(0, $rememberCount);
            } elseif ($failPostSave) {
                self::assertNotNull($failure);
                self::assertSame('synthetic diagnostic failure', $failure->getMessage());
                self::assertNull($result);
                self::assertContains(['post_save', 'failed'], $events);
                self::assertContains(['logout', 'passed'], $events);
                self::assertContains(['post_logout', 'passed'], $events);
                self::assertStringNotContainsString('synthetic diagnostic failure', json_encode($events));
            } else {
                self::assertNull($failure);
                self::assertSame('verified', $result['status']);
                self::assertSame('partial', $result['coverage']);
                self::assertSame(405, $result['get_save_status']);
                self::assertSame(200, $result['save_status']);
                self::assertSame(200, $result['logout_status']);
                self::assertSame(307, $result['post_logout_account_status']);
                self::assertNotEmpty($sessions);
                self::assertContains(['post_save', 'passed'], $events);
                self::assertContains(['logout', 'passed'], $events);
                self::assertContains(['post_logout', 'passed'], $events);
            }
        } finally {
            try {
                $server?->close();
            } finally {
                $fixture->deactivate();
            }
            foreach (['state.json', 'lifecycle.lock'] as $file) {
                $path = $stateDirectory . '/' . $file;
                if (is_file($path) && !is_link($path)) {
                    unlink($path);
                }
            }
            if (is_dir($stateDirectory) && !is_link($stateDirectory)) {
                rmdir($stateDirectory);
            }
        }
    }
}

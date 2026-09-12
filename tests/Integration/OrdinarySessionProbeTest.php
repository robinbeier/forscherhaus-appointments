<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeSessions;
use ReleaseGate\OrdinarySessionProbe;
use Tests\Integration\Support\DefenseCycleHttpServer;
use Tests\Integration\Support\OrdinaryJournalSyncFault;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeSessions.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinarySessionProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';
require_once __DIR__ . '/Support/OrdinaryJournalSyncFault.php';

final class OrdinarySessionProbeTest extends TestCase
{
    public function testSessionCleanupRejectsAReplacementAndPreservesIt(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !is_file('/.dockerenv')) {
            self::markTestSkipped('Requires the explicitly owned isolated Docker runner.');
        }
        $directory = '/var/lib/fh-ordinary-journal-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/sessions', 0700);
        $cookie = bin2hex(random_bytes(16));
        $path = $directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'owned synthetic file');
        $journal = new OrdinaryProbeSessions($directory, $directory . '/sessions');
        try {
            $journal->remember($cookie);
            rename($path, $path . '.original');
            file_put_contents($path, 'replacement synthetic file');
            $refused = false;
            try {
                $journal->cleanup();
            } catch (RuntimeException) {
                $refused = true;
            }
            self::assertTrue($refused);
            self::assertSame('replacement synthetic file', file_get_contents($path));
            unlink($path);
            rename($path . '.original', $path);
            OrdinaryJournalSyncFault::failDirectory($directory . '/sessions');
            $syncFailed = false;
            try {
                $journal->cleanup();
            } catch (RuntimeException $error) {
                $syncFailed = str_contains($error->getMessage(), 'directory synchronization');
            } finally {
                OrdinaryJournalSyncFault::disable();
            }
            self::assertTrue($syncFailed);
            self::assertFileExists($directory . '/sessions.json');
            $journal->cleanup();
            self::assertFileDoesNotExist($path);
        } finally {
            OrdinaryJournalSyncFault::disable();
            foreach (glob($directory . '/sessions/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (['sessions.json', 'sessions.json.tmp'] as $name) {
                if (is_file($directory . '/' . $name)) {
                    unlink($directory . '/' . $name);
                }
            }
            rmdir($directory . '/sessions');
            rmdir($directory);
        }
    }

    public function testActivationPreflightRejectsAnyExistingSessionJournalArtifact(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !is_file('/.dockerenv')) {
            self::markTestSkipped('Requires the explicitly owned isolated Docker runner.');
        }
        $directory = '/var/lib/fh-ordinary-preflight-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $sessionsDirectory = $directory . '/sessions';
        mkdir($sessionsDirectory, 0700);
        $journal = $directory . '/sessions.json';
        $probeSessions = new OrdinaryProbeSessions($directory, $sessionsDirectory);
        try {
            foreach ([$journal, $journal . '.tmp'] as $artifact) {
                file_put_contents($artifact, $artifact === $journal ? '{}' : 'partial synthetic journal');
                chmod($artifact, 0600);
                try {
                    $probeSessions->assertCleanBeforeActivation();
                    self::fail('An existing journal artifact must block activation.');
                } catch (RuntimeException $e) {
                    self::assertStringContainsString('recovery', strtolower($e->getMessage()));
                }
                self::assertFileExists($artifact);
                unlink($artifact);
            }
        } finally {
            foreach ([$journal, $journal . '.tmp'] as $artifact) {
                if (is_file($artifact) && !is_link($artifact)) {
                    unlink($artifact);
                }
            }
            rmdir($sessionsDirectory);
            rmdir($directory);
        }
    }

    public function testOrdinaryProviderSessionExpiresAndAllOwnedFilesAreRemoved(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !is_file('/.dockerenv')) {
            self::markTestSkipped('Requires the explicitly owned isolated Docker runner.');
        }
        $directory = '/var/lib/fh-ordinary-session-test-' . bin2hex(random_bytes(8));
        $fixture = new OrdinaryLiveFixture($directory);
        $server = null;
        $sessions = null;
        try {
            $context = $fixture->activate();
            $server = new DefenseCycleHttpServer(2);
            $sessions = new OrdinaryProbeSessions($directory, $server->directory . '/sessions');
            $client = new GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Ordinary-Probe' => '1']);
            $probe = new OrdinarySessionProbe($client, $sessions, $server->baseUrl, 2);
            $guardChecks = 0;
            $result = $probe->run($context, null, static function () use (&$guardChecks): void {
                $guardChecks++;
            });
            self::assertGreaterThanOrEqual(6, $guardChecks);
            self::assertSame('verified', $result['status']);
            self::assertTrue($result['file_present_before_expired_request']);
            self::assertTrue($result['activity_unchanged_while_idle']);
            self::assertTrue($result['rotated']);
            self::assertGreaterThanOrEqual(4, $result['elapsed_seconds']);
            $sessions->cleanup();
            self::assertSame([], glob($server->directory . '/sessions/*'));
            $sessions->cleanup();
            $waiting = false;
            $refused = false;
            try {
                $probe->run(
                    $context,
                    static function () use (&$waiting): void {
                        $waiting = true;
                    },
                    static function () use (&$waiting): void {
                        if ($waiting) {
                            throw new RuntimeException('Synthetic active release changed.');
                        }
                    },
                );
            } catch (RuntimeException $error) {
                $refused = $error->getMessage() === 'Synthetic active release changed.';
            }
            self::assertTrue($refused, 'Release change during idle must prevent successful evidence.');
            $sessions->cleanup();
            self::assertSame([], glob($server->directory . '/sessions/*'));
        } finally {
            try {
                $sessions?->cleanup();
                $server?->close();
            } finally {
                $fixture->deactivate();
                self::assertSame('clean', $fixture->verify());
                unlink($directory . '/lifecycle.lock');
                rmdir($directory);
            }
        }
    }
}

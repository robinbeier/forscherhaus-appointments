<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeSessions;
use ReleaseGate\OrdinarySessionProbe;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeSessions.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinarySessionProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

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
            $journal->cleanup();
            self::assertFileDoesNotExist($path);
        } finally {
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
            $result = $probe->run($context);
            self::assertSame('verified', $result['status']);
            self::assertTrue($result['file_present_before_expired_request']);
            self::assertTrue($result['activity_unchanged_while_idle']);
            self::assertTrue($result['rotated']);
            self::assertGreaterThanOrEqual(4, $result['elapsed_seconds']);
            $sessions->cleanup();
            self::assertSame([], glob($server->directory . '/sessions/*'));
            $sessions->cleanup();
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

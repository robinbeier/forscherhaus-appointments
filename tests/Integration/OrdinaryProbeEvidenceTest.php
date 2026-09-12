<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\OrdinaryProbeSessions;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeSessions.php';

final class OrdinaryProbeEvidenceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !is_file('/.dockerenv') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires explicitly isolated root Docker runner.');
        }
        $this->directory = '/var/lib/fh-evidence-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/sessions', 0700);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/sessions/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory . '/sessions');
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testReceiptPrecedesJournalRetirementAndPreservesFailureStep(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $evidence->step('logout', 'started');
        $evidence->step('logout', 'failed');
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = bin2hex(random_bytes(16));
        $path = $this->directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'synthetic');
        $sessions->remember($cookie);
        $sessions->cleanup(function (int $tracked, int $removed, int $absent) use ($evidence): void {
            self::assertFileExists($this->directory . '/sessions.json');
            $evidence->cleaned($tracked, $removed, $absent);
        });
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($this->directory . '/sessions.json');
        $receipt = $evidence->read();
        self::assertSame('failed', $receipt['events'][1]['outcome']);
        self::assertSame(1, $receipt['cleanup']['tracked']);
        self::assertSame(1, $receipt['cleanup']['removed']);
        self::assertSame(0, $receipt['cleanup']['remaining']);
        self::assertStringNotContainsString($cookie, file_get_contents($this->directory . '/last-evidence.json'));
        $sessions->cleanup($evidence->cleaned(...));
        self::assertSame($receipt, $evidence->read(), 'Idempotent cleanup must retain the original receipt.');
    }

    public function testFailedReceiptPublicationRetainsPrivateJournalForRetry(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = bin2hex(random_bytes(16));
        $path = $this->directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'synthetic');
        $sessions->remember($cookie);
        file_put_contents($this->directory . '/last-evidence.json.tmp', 'incomplete synthetic receipt');
        $failed = false;
        try {
            $sessions->cleanup($evidence->cleaned(...));
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertFileDoesNotExist($path);
        self::assertFileExists($this->directory . '/sessions.json');
        self::assertNull($evidence->read()['cleanup']);
        unlink($this->directory . '/last-evidence.json.tmp');
        $sessions->cleanup($evidence->cleaned(...));
        self::assertSame(1, $evidence->read()['cleanup']['already_absent']);
        self::assertFileDoesNotExist($this->directory . '/sessions.json');
    }

    public function testMissingReceiptRetainsPrivateJournal(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = bin2hex(random_bytes(16));
        $path = $this->directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'synthetic');
        $sessions->remember($cookie);
        try {
            $sessions->cleanup($evidence->cleaned(...));
            self::fail('Missing receipt must prevent journal retirement.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($path);
            self::assertFileExists($this->directory . '/sessions.json');
            self::assertFileDoesNotExist($this->directory . '/last-evidence.json');
        }
    }

    public function testHandledSessionFailureIsDistinctFromAnInterruptedOperation(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $error = new RuntimeException('private synthetic failure detail');
        try {
            $evidence->run('session', static function () use ($evidence, $error): void {
                $evidence->step('waiting', 'started');
                throw $error;
            });
            self::fail('Original operation failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        $receipt = $evidence->read();
        self::assertSame(['started', 'started', 'failed'], array_column($receipt['events'], 'outcome'));
        self::assertSame(['session', 'waiting', 'session'], array_column($receipt['events'], 'phase'));
        self::assertStringNotContainsString($error->getMessage(), json_encode($receipt));
        self::assertSame('synthetic result', $evidence->run('session', static fn() => 'synthetic result'));
        self::assertSame('passed', $evidence->read()['events'][4]['outcome']);
    }

    public function testOnlyFixedDiagnosticCodesAreAccepted(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        try {
            $evidence->step('an arbitrary exception or response', 'failed');
            self::fail('Dynamic diagnostics must not be persisted.');
        } catch (RuntimeException) {
            self::assertSame([], $evidence->read()['events']);
        }
        chmod($this->directory . '/last-evidence.json', 0644);
        self::expectException(RuntimeException::class);
        $evidence->read();
    }
}

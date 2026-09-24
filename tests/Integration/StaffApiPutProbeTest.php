<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\StaffApiPutProbe;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/StaffApiPutProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class StaffApiPutProbeTest extends TestCase
{
    public function testBoundedStaffPutProbeUsesOwnedTargetsAndCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-staff-api-put-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        $db = get_instance()->db;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('customer_boundary', $actor);
            $server = new DefenseCycleHttpServer();
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_rob606_test');

            $result = StaffApiPutProbe::forApp(
                $server->baseUrl,
                $actor['username'],
                $actor['password'],
                $db,
                $fixture,
            )->run($evidence->step(...));

            self::assertSame('verified', $result['status']);
            self::assertSame(400, $result['provider_conflict_status']);
            self::assertSame(400, $result['admin_conflict_status']);
            self::assertSame(200, $result['matching_status']);
            self::assertSame(405, $result['wrong_verb_status']);
            self::assertNotEmpty($evidence->read()['events']);
        } finally {
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
    }

    private function removePrivateDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

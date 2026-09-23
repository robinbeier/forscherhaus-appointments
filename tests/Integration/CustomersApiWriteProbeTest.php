<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\CustomersApiWriteProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/CustomersApiWriteProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class CustomersApiWriteProbeTest extends TestCase
{
    public function testConflictingAndMatchingCustomerIdsUseRealHttpAndDatabase(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-customers-api-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        $db = get_instance()->db;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('customer_boundary', $actor);
            $server = new DefenseCycleHttpServer();
            $result = CustomersApiWriteProbe::forApp(
                $server->baseUrl,
                $actor['username'],
                $actor['password'],
                $db,
                $fixture,
            )->run();

            self::assertSame('verified', $result['status']);
            self::assertSame('put_target_binding', $result['coverage']);
            self::assertSame(400, $result['conflict_status']);
            self::assertSame(200, $result['positive_status']);
        } finally {
            $server?->close();
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

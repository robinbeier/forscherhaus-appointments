<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\CustomersApiWriteProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/CustomersApiWriteProbe.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
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
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_rob599_test');
            $observe = [$evidence, 'step'];
            $result = CustomersApiWriteProbe::forApp(
                $server->baseUrl,
                $actor['username'],
                $actor['password'],
                $db,
                $fixture,
            )->run($observe);

            self::assertSame('verified', $result['status']);
            self::assertSame('put_target_binding', $result['coverage']);
            self::assertSame(400, $result['conflict_status']);
            self::assertSame(200, $result['positive_status']);
            self::assertSame(
                [
                    ['customers_api_conflict_put', 'started'],
                    ['customers_api_conflict_put', 'passed'],
                    ['customers_api_matching_put', 'started'],
                    ['customers_api_matching_put', 'passed'],
                ],
                array_map(
                    static fn(array $event): array => [$event['phase'], $event['outcome']],
                    $evidence->read()['events'],
                ),
            );

            try {
                CustomersApiWriteProbe::forApp(
                    $server->baseUrl,
                    $actor['username'],
                    'invalid-password',
                    $db,
                    $fixture,
                )->run($observe);
                self::fail('Wrong credentials must fail the conflict request.');
            } catch (\RuntimeException $error) {
                self::assertSame('Customers API conflicting body ID was not rejected.', $error->getMessage());
            }
            $events = $evidence->read()['events'];
            self::assertSame('customers_api_conflict_put', $events[5]['phase']);
            self::assertSame('failed', $events[5]['outcome']);
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

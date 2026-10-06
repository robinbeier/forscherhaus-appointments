<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\SecretariesApiAliasProbe;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/SecretariesApiAliasProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class SecretariesApiAliasProbeTest extends TestCase
{
    public function testBoundedSecretaryAliasProbeUsesOwnedTargetAndSentinelAndCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-secretaries-api-alias-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        $db = get_instance()->db;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('secretaries_api', $actor);
            $targetId = (int) ($fixture->secretariesApiSnapshot()['target']['user']['id'] ?? 0);
            $server = new DefenseCycleHttpServer(recordRequests: true);
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_rob620_test');

            $result = SecretariesApiAliasProbe::forApp(
                $server->baseUrl,
                $actor['username'],
                $actor['password'],
                $fixture,
            )->run($evidence->step(...));

            self::assertSame('verified', $result['status']);
            self::assertSame(200, $result['read_status']);
            self::assertSame(405, $result['read_wrong_verb_status']);
            self::assertSame(405, $result['store_wrong_verb_status']);
            self::assertSame(405, $result['destroy_wrong_verb_status']);
            $passed = array_values(
                array_filter(
                    $evidence->read()['events'],
                    static fn(array $event): bool => $event['outcome'] === 'passed',
                ),
            );
            self::assertSame(
                [
                    'secretaries_api_read_aliases',
                    'secretaries_api_read_method_boundaries',
                    'secretaries_api_store_alias',
                    'secretaries_api_destroy_alias',
                ],
                array_column($passed, 'phase'),
            );
            self::assertSame(
                [
                    ['method' => 'GET', 'path' => '/index.php/api/v1/secretaries'],
                    ['method' => 'GET', 'path' => '/index.php/api/v1/secretaries_api_v1/index'],
                    ['method' => 'GET', 'path' => '/index.php/api/v1/secretaries/' . $targetId],
                    ['method' => 'GET', 'path' => '/index.php/api/v1/secretaries_api_v1/show/' . $targetId],
                    ...array_map(
                        static fn(string $method): array => [
                            'method' => $method,
                            'path' => '/index.php/api/v1/secretaries_api_v1/index',
                        ],
                        ['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'],
                    ),
                    ...array_map(
                        static fn(string $method): array => [
                            'method' => $method,
                            'path' => '/index.php/api/v1/secretaries_api_v1/show/' . $targetId,
                        ],
                        ['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'],
                    ),
                    ['method' => 'PUT', 'path' => '/index.php/api/v1/secretaries_api_v1/store'],
                    ['method' => 'GET', 'path' => '/index.php/api/v1/secretaries_api_v1/destroy/' . $targetId],
                ],
                $this->requestLedger($server->requestLedger()),
            );
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
        self::assertSame('clean', $ordinary->verify());
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

    /** @return array<int,array{method:string,path:string}> */
    private function requestLedger(array $entries): array
    {
        $ledger = [];
        foreach ($entries as $entry) {
            $path = explode('?', $entry['uri'], 2)[0];
            $ledger[] = ['method' => $entry['method'], 'path' => $path];
        }
        return $ledger;
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\ServiceCategoriesApiWriteProbe;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/ServiceCategoriesApiWriteProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class ServiceCategoriesApiWriteProbeTest extends TestCase
{
    public function testBoundedServiceCategoriesProbeUsesOwnedRowsAndCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-service-categories-api-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('service_categories_api', $actor);
            $server = new DefenseCycleHttpServer();
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_service_categories_api_test');
            $result = ServiceCategoriesApiWriteProbe::forApp(
                $server->baseUrl,
                $actor['username'],
                $actor['password'],
                $fixture,
            )->run($evidence->step(...));
            self::assertSame('verified', $result['status']);
            self::assertSame(400, $result['conflict_status']);
            self::assertSame(200, $result['matching_status']);
            self::assertSame(405, $result['wrong_verb_status']);
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

    public function testPreparedRecoveryReconstructsMissingCategoryId(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-service-categories-api-recovery-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('service_categories_api', $actor);
            $missingId = (int) $state['ids']['category_b'];
            unset($state['ids']['category_b']);
            $state['phase'] = 'prepared';
            $this->writeState($directory, $state);
            $fixture->deactivate();
            self::assertSame('clean', $fixture->verify());
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('service_categories', ['id' => $missingId])
                    ->num_rows(),
            );
        } finally {
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
    }

    public function testCleanupRefusesCategoryIdentityDriftAndRetainsJournal(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-service-categories-api-drift-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $driftedId = 0;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('service_categories_api', $actor);
            $driftedId = (int) $state['ids']['category_a'];
            get_instance()->db->update('service_categories', ['name' => 'foreign-category'], ['id' => $driftedId]);
            try {
                $fixture->deactivate();
                self::fail('Cleanup accepted a drifted service category.');
            } catch (RuntimeException $error) {
                self::assertFileExists($directory . '/defense-verification.json');
                self::assertNotEmpty(
                    get_instance()
                        ->db->get_where('service_categories', ['id' => $driftedId])
                        ->row_array(),
                );
            }
        } finally {
            if ($driftedId > 0 && is_file($directory . '/defense-verification.json')) {
                $state = json_decode(
                    (string) file_get_contents($directory . '/defense-verification.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $intent = $state['intents']['service_categories']['a'];
                get_instance()->db->update(
                    'service_categories',
                    [
                        'name' => $intent['name'],
                        'description' => $intent['description'],
                    ],
                    ['id' => $driftedId],
                );
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
    }

    public function testCleanupRefusesToUnlinkAServiceOutsideTheFixture(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-service-categories-api-link-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $serviceId = 0;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('service_categories_api', $actor);
            $categoryId = (int) $state['ids']['category_a'];
            $name = 'synthetic_probe_link_' . bin2hex(random_bytes(8));
            get_instance()->db->insert('services', [
                'name' => $name,
                'duration' => 30,
                'price' => 0,
                'currency' => 'EUR',
                'description' => $name,
                'location' => 'Synthetic',
                'is_private' => 0,
                'attendants_number' => 1,
                'buffer_before' => 0,
                'buffer_after' => 0,
                'id_service_categories' => $categoryId,
            ]);
            $serviceId = (int) get_instance()->db->insert_id();
            self::assertGreaterThan(0, $serviceId);
            try {
                $fixture->deactivate();
                self::fail('Cleanup silently unlinked an outside service.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('linked service', $error->getMessage());
                self::assertFileExists($directory . '/defense-verification.json');
                self::assertSame(
                    $categoryId,
                    (int) get_instance()
                        ->db->get_where('services', ['id' => $serviceId])
                        ->row_array()['id_service_categories'],
                );
            }
        } finally {
            if ($serviceId > 0) {
                get_instance()->db->delete('services', ['id' => $serviceId]);
            }
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
    }

    public function testCleanupResumesAfterDatabaseCommitBeforeJournalRemoval(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-service-categories-api-commit-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('service_categories_api', $actor);
            $db = get_instance()->db;
            self::assertTrue($db->trans_begin());
            foreach (['a', 'b'] as $key) {
                self::assertTrue($db->delete('service_categories', ['id' => (int) $state['ids']['category_' . $key]]));
            }
            self::assertTrue($db->trans_commit());
            $state['phase'] = 'cleaning';
            $this->writeState($directory, $state);
            $fixture->deactivate();
            self::assertSame('clean', $fixture->verify());
        } finally {
            if (get_instance()->db->trans_active()) {
                get_instance()->db->trans_rollback();
            }
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
    }

    private function writeState(string $directory, array $state): void
    {
        file_put_contents($directory . '/defense-verification.json', json_encode($state, JSON_THROW_ON_ERROR) . "\n");
        chmod($directory . '/defense-verification.json', 0600);
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

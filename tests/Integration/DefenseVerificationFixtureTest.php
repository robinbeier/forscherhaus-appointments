<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';

/** Focused lifecycle contract running only in the disposable defense-cycle stack. */
final class DefenseVerificationFixtureTest extends TestCase
{
    private const ACTIVE_TRANSACTION_ERROR = 'Defense verification fixture cannot run inside an active database transaction.';

    private string $stateDirectory;
    private ?OrdinaryLiveFixture $ordinary = null;
    private ?DefenseVerificationFixture $fixture = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the explicitly isolated root Docker fixture run.');
        }
        self::assertSame('testing', ENVIRONMENT);
        $this->stateDirectory = '/var/lib/fh-defense-verification-tests-' . bin2hex(random_bytes(8));
        $this->ordinary = new OrdinaryLiveFixture($this->stateDirectory);
        $this->fixture = new DefenseVerificationFixture($this->stateDirectory);
        self::assertSame('clean', $this->ordinary->verify());
        self::assertSame('clean', $this->fixture->verify());
    }

    protected function tearDown(): void
    {
        if (!isset($this->stateDirectory)) {
            return;
        }
        if ($this->fixture !== null && is_file($this->stateDirectory . '/defense-verification.json')) {
            $this->fixture->deactivate();
        }
        if ($this->ordinary !== null && is_file($this->stateDirectory . '/state.json')) {
            $this->ordinary->deactivate();
        }
        foreach (
            [
                'defense-verification.json',
                'defense-verification.json.tmp',
                'defense-verification.lock',
                'state.json',
                'lifecycle.lock',
            ]
            as $name
        ) {
            $path = $this->stateDirectory . '/' . $name;
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->stateDirectory) && !is_link($this->stateDirectory)) {
            rmdir($this->stateDirectory);
        }
    }

    public function testCustomerBoundaryLifecycleContainsNoCredentialAndCleansAfterExpectedDestroy(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('customer_boundary', $actor);

        self::assertSame('active', $this->fixture->verify());
        self::assertArrayNotHasKey('password', $state);
        self::assertArrayNotHasKey('credential', $state);
        self::assertSame('customer_boundary', $state['profile']);

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        $this->fixture->deactivate();
    }

    public function testSecretariesPreparedRecoveryCleansRelationships(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('secretaries_api', $actor);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $journal = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        $journal['phase'] = 'prepared';
        file_put_contents($journalPath, json_encode($journal, JSON_THROW_ON_ERROR));

        $this->fixture->deactivate();

        $db = &get_instance()->db;
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(
            0,
            $db
                ->get_where('secretaries_providers', [
                    'id_users_secretary' => (int) $state['ids']['secretary_target'],
                ])
                ->num_rows(),
        );
        self::assertSame(
            0,
            $db
                ->get_where('secretaries_providers', [
                    'id_users_secretary' => (int) $state['ids']['secretary_sentinel'],
                ])
                ->num_rows(),
        );
    }

    public function testSecretariesPreparedRecoveryRejectsAnOutsiderExactRelationship(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('secretaries_api', $actor);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $originalJournal = (string) file_get_contents($journalPath);
        $journal = json_decode($originalJournal, true, 512, JSON_THROW_ON_ERROR);
        $journal['phase'] = 'prepared';
        foreach (array_keys($journal['intents']['secretary_links']) as $key) {
            $journal['intents']['secretary_links'][$key]['stage'] = 'prepared';
        }
        $db = &get_instance()->db;
        $link = [
            'id_users_secretary' => (int) $state['ids']['secretary_target'],
            'id_users_provider' => (int) $state['ids']['provider_target'],
        ];
        $db->delete('secretaries_providers', $link);
        $db->insert('secretaries_providers', $link);
        file_put_contents($journalPath, json_encode($journal, JSON_THROW_ON_ERROR));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Prepared secretary relationship provenance is unproven.');
            $this->fixture->deactivate();
        } finally {
            $db->delete('secretaries_providers', $link);
            $db->insert('secretaries_providers', $link);
            file_put_contents($journalPath, $originalJournal);
            if (is_file($journalPath)) {
                $this->fixture->deactivate();
            }
        }
    }

    public function testSecretariesDestroyAliasCompleteTargetLossReconcilesAndCleansRemainingOwnedRows(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('secretaries_api', $actor);
        $this->fixture->beginSecretariesApiDestroyAlias();
        $db = &get_instance()->db;
        $targetId = (int) $state['ids']['secretary_target'];
        $db->delete('secretaries_providers', ['id_users_secretary' => $targetId]);
        $db->delete('user_settings', ['id_users' => $targetId]);
        $db->query('DELETE FROM ' . $db->dbprefix('users') . ' WHERE id = ?', [$targetId]);
        self::assertSame(0, $db->get_where('users', ['id' => $targetId])->num_rows());

        $this->fixture->deactivate();

        self::assertSame('clean', $this->fixture->verify());
        self::assertSame('active', $this->ordinary->verify());
        self::assertSame(0, $db->get_where('users', ['id' => (int) $state['ids']['secretary_sentinel']])->num_rows());
        self::assertSame(0, $db->get_where('users', ['id' => (int) $state['ids']['provider_target']])->num_rows());
        $this->ordinary->deactivate();
        self::assertSame('clean', $this->ordinary->verify());
    }

    public function testSecretariesDestroyAliasPartialTargetLossRemainsFailClosed(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('secretaries_api', $actor);
        $this->fixture->beginSecretariesApiDestroyAlias();
        $db = &get_instance()->db;
        $db->delete('secretaries_providers', [
            'id_users_secretary' => (int) $state['ids']['secretary_target'],
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Secretary destroy alias target ownership drifted.');
            $this->fixture->deactivate();
        } finally {
            $db->insert('secretaries_providers', [
                'id_users_secretary' => (int) $state['ids']['secretary_target'],
                'id_users_provider' => (int) $state['ids']['provider_target'],
            ]);
            $this->fixture->deactivate();
        }
    }

    public function testSecretariesDestroyAliasReconciledCleaningRetryRetainsAbsenceEvidence(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('secretaries_api', $actor);
        $this->fixture->beginSecretariesApiDestroyAlias();
        $db = &get_instance()->db;
        $targetId = (int) $state['ids']['secretary_target'];
        $targetLink = [
            'id_users_secretary' => $targetId,
            'id_users_provider' => (int) $state['ids']['provider_target'],
        ];
        $db->delete('secretaries_providers', $targetLink);
        $db->delete('user_settings', ['id_users' => $targetId]);
        $db->query('DELETE FROM ' . $db->dbprefix('users') . ' WHERE id = ?', [$targetId]);

        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $journal = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        $journal['phase'] = 'cleaning';
        $journal['intents']['secretary_alias']['destroy_stage'] = 'target_absent_reconciled';
        $journal['intents']['secretary_alias']['reconciled_target_id'] = $targetId;
        $journal['intents']['secretary_alias']['reconciled_target_link'] = $targetLink;
        unset($journal['ids']['secretary_target'], $journal['usernames']['secretary_target']);
        unset($journal['links']['secretary_target'], $journal['intents']['secretary_links']['secretary_target']);
        file_put_contents($journalPath, json_encode($journal, JSON_THROW_ON_ERROR));

        $this->fixture->deactivate();

        self::assertSame('clean', $this->fixture->verify());
        self::assertSame('active', $this->ordinary->verify());
        $this->ordinary->deactivate();
        self::assertSame('clean', $this->ordinary->verify());
    }

    public function testCalendarRaceLifecycleCleansAllRelationshipsAndObjects(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);

        self::assertSame('active', $this->fixture->verify());
        self::assertSame('calendar_race', $state['profile']);
        self::assertGreaterThan(0, $state['appointment_id']);

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        $db = &get_instance()->db;
        self::assertSame(0, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(0, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(0, $db->get_where('services_providers', ['id_services' => $state['service_id']])->num_rows());
    }

    public function testAppointmentsApiBearerReusesExistingTokenOnlyInMemory(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        $existing = bin2hex(random_bytes(32));
        self::assertTrue($db->update('settings', ['value' => $existing], ['id' => (int) $row['id']]));
        try {
            $actor = $this->ordinary->activate();
            $this->fixture->activate('calendar_race', $actor);
            $returned = $this->fixture->prepareAppointmentsApiBearerToken();
            self::assertTrue(hash_equals($existing, $returned));

            $journalText = (string) file_get_contents($this->stateDirectory . '/defense-verification.json');
            $journal = json_decode($journalText, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('api_token', $journal['intents']);
            self::assertFalse(str_contains($journalText, $existing));

            $this->fixture->deactivate();
            $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($current) && hash_equals($existing, (string) ($current['value'] ?? '')));
        } finally {
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testAppointmentsApiBearerPreparationRejectsAnOuterTransactionBeforeMutation(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $row['id']]));
        $outerTransaction = false;
        try {
            $actor = $this->ordinary->activate();
            $state = $this->fixture->activate('calendar_race', $actor);
            $journalBefore = (string) file_get_contents($this->stateDirectory . '/defense-verification.json');

            self::assertTrue($db->trans_begin());
            $outerTransaction = true;
            try {
                $this->fixture->prepareAppointmentsApiBearerToken();
                self::fail('Bearer preparation must reject an active outer transaction.');
            } catch (RuntimeException $error) {
                self::assertSame(self::ACTIVE_TRANSACTION_ERROR, $error->getMessage());
            }

            self::assertTrue($db->trans_active());
            self::assertSame(
                $journalBefore,
                (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
            );
            $journal = json_decode($journalBefore, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('active', $journal['phase']);
            self::assertArrayNotHasKey('api_token', $journal['intents']);
            $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($current) && ($current['value'] ?? null) === '');
            self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());

            self::assertTrue($db->trans_rollback());
            $outerTransaction = false;
            self::assertFalse($db->trans_active());

            $candidate = $this->fixture->prepareAppointmentsApiBearerToken();
            self::assertNotSame('', $candidate);
            $this->fixture->deactivate();
            $restored = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
            self::assertSame('clean', $this->fixture->verify());
        } finally {
            if ($outerTransaction && $db->trans_active()) {
                $db->trans_rollback();
            }
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testAppointmentsApiBearerCleanupRejectsAnOuterTransactionBeforeMutation(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $row['id']]));
        $outerTransaction = false;
        try {
            $actor = $this->ordinary->activate();
            $state = $this->fixture->activate('calendar_race', $actor);
            $candidate = $this->fixture->prepareAppointmentsApiBearerToken();
            $journalBefore = (string) file_get_contents($this->stateDirectory . '/defense-verification.json');

            self::assertTrue($db->trans_begin());
            $outerTransaction = true;
            try {
                $this->fixture->deactivate();
                self::fail('Cleanup must reject an active outer transaction.');
            } catch (RuntimeException $error) {
                self::assertSame(self::ACTIVE_TRANSACTION_ERROR, $error->getMessage());
            }

            self::assertTrue($db->trans_active());
            self::assertSame(
                $journalBefore,
                (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
            );
            $journal = json_decode($journalBefore, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('active', $journal['phase']);
            $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(
                is_array($current) &&
                    is_string($current['value'] ?? null) &&
                    hash_equals($candidate, $current['value']),
            );
            self::assertSame(1, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
            self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());

            self::assertTrue($db->trans_rollback());
            $outerTransaction = false;
            self::assertFalse($db->trans_active());

            $this->fixture->deactivate();
            $restored = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
            self::assertSame('clean', $this->fixture->verify());
        } finally {
            if ($outerTransaction && $db->trans_active()) {
                $db->trans_rollback();
            }
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testTransactionOwningOperationsRejectBeforeWaitingForLifecycleLock(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $row['id']]));
        $outerTransaction = false;
        try {
            $actor = $this->ordinary->activate();
            $state = $this->fixture->activate('calendar_race', $actor);
            $this->fixture->prepareAppointmentsApi();
            $ci = &get_instance();
            $ci->load->model('appointments_model');
            $payload = $this->fixture->prepareApiAppointment('basic');
            $id = $ci->appointments_model->save($this->decodeApiPayload($payload));
            $this->fixture->confirmApiAppointmentCreated('basic', $id);
            $update = $this->fixture->prepareApiAppointmentUpdate('basic');
            $ci->appointments_model->save(['id' => $id] + $this->decodeApiPayload($update));
            $this->fixture->confirmApiAppointmentUpdated('basic');
            $this->fixture->prepareApiAppointmentDelete('basic');
            $candidate = $this->fixture->prepareAppointmentsApiBearerToken();
            $journalBefore = (string) file_get_contents($this->stateDirectory . '/defense-verification.json');

            self::assertTrue($db->trans_begin());
            $outerTransaction = true;
            $this->assertActiveTransactionFailsBeforeLifecycleLock(
                fn(): string => $this->fixture->prepareAppointmentsApiBearerToken(),
            );
            $this->assertActiveTransactionFailsBeforeLifecycleLock(function (): void {
                $this->fixture->deactivate();
            });
            $deleteCalled = false;
            $this->assertActiveTransactionFailsBeforeLifecycleLock(function () use (&$deleteCalled): void {
                $this->fixture->guardApiAppointmentDelete('basic', static function () use (&$deleteCalled): void {
                    $deleteCalled = true;
                });
            });

            self::assertFalse($deleteCalled);
            self::assertTrue($db->trans_active());
            self::assertSame(
                $journalBefore,
                (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
            );
            $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(
                is_array($current) &&
                    is_string($current['value'] ?? null) &&
                    hash_equals($candidate, $current['value']),
            );
            self::assertSame(1, $db->get_where('appointments', ['id' => $id])->num_rows());
            self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());

            self::assertTrue($db->trans_rollback());
            $outerTransaction = false;
            self::assertFalse($db->trans_active());
            $this->fixture->deactivate();
            $restored = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
            self::assertSame('clean', $this->fixture->verify());
        } finally {
            if ($outerTransaction && $db->trans_active()) {
                $db->trans_rollback();
            }
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testAppointmentsApiBearerRejectsUnsupportedDriverAfterIntentBeforeUpdate(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $row['id']]));
        try {
            $actor = $this->ordinary->activate();
            $this->fixture->activate('calendar_race', $actor);
            $proxy = new class ($db) {
                public string $dbdriver = 'unsupported';
                public object $conn_id;

                public function __construct(private readonly object $database)
                {
                    $this->conn_id = $database->conn_id;
                }

                public function __call(string $name, array $arguments): mixed
                {
                    return $this->database->$name(...$arguments);
                }
            };
            $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
            $databaseProperty->setValue($this->fixture, $proxy);
            try {
                $this->fixture->prepareAppointmentsApiBearerToken();
                self::fail('Bearer preparation must reject a non-mysqli fixture connection.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('native mysqli connection', $error->getMessage());
            } finally {
                $databaseProperty->setValue($this->fixture, $db);
            }

            $journal = json_decode(
                (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame(
                ['setting_id', 'initial_state', 'candidate_digest'],
                array_keys($journal['intents']['api_token']),
            );
            self::assertSame((int) $row['id'], $journal['intents']['api_token']['setting_id']);
            self::assertSame('empty', $journal['intents']['api_token']['initial_state']);
            self::assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/D',
                $journal['intents']['api_token']['candidate_digest'],
            );
            $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($current) && ($current['value'] ?? null) === '');
            self::assertSame('cleanup_pending', $this->fixture->verify());

            $this->fixture->deactivate();
            $restored = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
        } finally {
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testAppointmentsApiBearerRecoversFailureAfterCommittedUpdateWithoutLeak(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $row['id']]));
        try {
            $actor = $this->ordinary->activate();
            $this->fixture->activate('calendar_race', $actor);
            $proxy = new class ($db) {
                public string $dbdriver;
                public object $conn_id;
                public bool $updated = false;
                public bool $failed = false;

                public function __construct(private readonly object $database)
                {
                    $this->dbdriver = $database->dbdriver;
                    $this->conn_id = $database->conn_id;
                }

                public function trans_commit(): bool
                {
                    $row = $this->database->get_where('settings', ['name' => 'api_token'])->row_array();
                    $this->updated = is_array($row) && is_string($row['value'] ?? null) && $row['value'] !== '';
                    $result = $this->database->trans_commit();
                    if ($this->updated && !$this->failed) {
                        $this->failed = true;
                        throw new RuntimeException('Injected failure after temporary bearer commit.');
                    }
                    return $result;
                }

                public function __call(string $name, array $arguments): mixed
                {
                    return $this->database->$name(...$arguments);
                }
            };
            $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
            $databaseProperty->setValue($this->fixture, $proxy);
            $errorMessage = '';
            try {
                $this->fixture->prepareAppointmentsApiBearerToken();
                self::fail('Bearer preparation must surface the injected post-commit failure.');
            } catch (RuntimeException $error) {
                $errorMessage = $error->getMessage();
                self::assertStringContainsString('after temporary bearer commit', $errorMessage);
            } finally {
                $databaseProperty->setValue($this->fixture, $db);
            }
            self::assertTrue($proxy->updated);
            self::assertTrue($proxy->failed);

            $journalText = (string) file_get_contents($this->stateDirectory . '/defense-verification.json');
            $journal = json_decode($journalText, true, 512, JSON_THROW_ON_ERROR);
            $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($current) && is_string($current['value'] ?? null) && $current['value'] !== '');
            self::assertTrue(
                hash_equals($journal['intents']['api_token']['candidate_digest'], hash('sha256', $current['value'])),
            );
            self::assertFalse(str_contains($journalText, $current['value']));
            self::assertFalse(str_contains($errorMessage, $current['value']));

            $this->fixture->deactivate();
            $restored = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
        } finally {
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testAppointmentsApiBearerRollsBackFailureAfterUpdateBeforeCommitWithoutLeak(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $row['id']]));
        try {
            $actor = $this->ordinary->activate();
            $this->fixture->activate('calendar_race', $actor);
            $proxy = new class ($db) {
                public string $dbdriver;
                public object $conn_id;
                public bool $commitAttempted = false;
                public bool $queryCacheLeaked = false;
                public bool $lastQueryLeaked = false;
                public ?string $candidateDigest = null;
                private string $candidate = '';

                public function __construct(private readonly object $database)
                {
                    $this->dbdriver = $database->dbdriver;
                    $this->conn_id = $database->conn_id;
                }

                public function trans_commit(): bool
                {
                    $row = $this->database->get_where('settings', ['name' => 'api_token'])->row_array();
                    if (!is_array($row) || !is_string($row['value'] ?? null) || $row['value'] === '') {
                        throw new RuntimeException('Temporary bearer update was not visible before commit.');
                    }
                    $this->candidate = $row['value'];
                    $this->candidateDigest = hash('sha256', $this->candidate);
                    $this->queryCacheLeaked = str_contains(
                        json_encode($this->database->queries, JSON_THROW_ON_ERROR),
                        $this->candidate,
                    );
                    $this->lastQueryLeaked = str_contains((string) $this->database->last_query(), $this->candidate);
                    $this->commitAttempted = true;
                    throw new RuntimeException('Injected failure before temporary bearer commit.');
                }

                public function containsCandidate(string $surface): bool
                {
                    return $this->candidate !== '' && str_contains($surface, $this->candidate);
                }

                public function __call(string $name, array $arguments): mixed
                {
                    return $this->database->$name(...$arguments);
                }
            };
            $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
            $databaseProperty->setValue($this->fixture, $proxy);
            $errorMessage = '';
            try {
                $this->fixture->prepareAppointmentsApiBearerToken();
                self::fail('Bearer preparation must surface the injected pre-commit failure.');
            } catch (RuntimeException $error) {
                $errorMessage = $error->getMessage();
                self::assertStringContainsString('before temporary bearer commit', $errorMessage);
            } finally {
                $databaseProperty->setValue($this->fixture, $db);
            }
            self::assertTrue($proxy->commitAttempted);
            self::assertNotNull($proxy->candidateDigest);
            self::assertFalse($proxy->queryCacheLeaked);
            self::assertFalse($proxy->lastQueryLeaked);

            $journalText = (string) file_get_contents($this->stateDirectory . '/defense-verification.json');
            $journal = json_decode($journalText, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue(
                hash_equals($journal['intents']['api_token']['candidate_digest'], $proxy->candidateDigest),
            );
            self::assertFalse($proxy->containsCandidate($journalText));
            self::assertFalse($proxy->containsCandidate($errorMessage));
            $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($current) && ($current['value'] ?? null) === '');
            self::assertSame('cleanup_pending', $this->fixture->verify());

            $this->fixture->deactivate();
            $restored = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
        } finally {
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testAppointmentsApiBearerCleanupResumesAfterRestoreCommitBeforeFixtureCleanup(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $row['id']]));
        try {
            $actor = $this->ordinary->activate();
            $state = $this->fixture->activate('calendar_race', $actor);
            $this->fixture->prepareAppointmentsApiBearerToken();
            $proxy = new class ($db) {
                public int $transactionStarts = 0;
                public bool $restoreCommitted = false;
                public bool $fixtureCleanupRejected = false;

                public function __construct(private readonly object $database) {}

                public function trans_begin(): bool
                {
                    $this->transactionStarts++;
                    if ($this->transactionStarts === 2) {
                        $this->fixtureCleanupRejected = true;
                        throw new RuntimeException('Injected failure before remaining fixture cleanup.');
                    }
                    return $this->database->trans_begin();
                }

                public function trans_commit(): bool
                {
                    $result = $this->database->trans_commit();
                    if ($this->transactionStarts === 1 && $result) {
                        $this->restoreCommitted = true;
                    }
                    return $result;
                }

                public function __call(string $name, array $arguments): mixed
                {
                    return $this->database->$name(...$arguments);
                }
            };
            $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
            $databaseProperty->setValue($this->fixture, $proxy);
            try {
                $this->fixture->deactivate();
                self::fail('Cleanup must surface the injected post-restore failure.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('before remaining fixture cleanup', $error->getMessage());
            } finally {
                $databaseProperty->setValue($this->fixture, $db);
            }
            self::assertSame(2, $proxy->transactionStarts);
            self::assertTrue($proxy->restoreCommitted);
            self::assertTrue($proxy->fixtureCleanupRejected);
            $restored = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
            $journal = json_decode(
                (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame('cleaning', $journal['phase']);
            self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());

            $this->fixture->deactivate();
            self::assertSame('clean', $this->fixture->verify());
        } finally {
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testAppointmentsApiBearerCleanupRejectsDriftAndDuplicateThenResumes(): void
    {
        $db = &get_instance()->db;
        $row = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $row['id']]));
        $duplicateId = 0;
        try {
            $actor = $this->ordinary->activate();
            $this->fixture->activate('calendar_race', $actor);
            $candidate = $this->fixture->prepareAppointmentsApiBearerToken();
            $foreign = bin2hex(random_bytes(32));
            self::assertTrue($db->update('settings', ['value' => $foreign], ['id' => (int) $row['id']]));
            try {
                $this->fixture->deactivate();
                self::fail('Cleanup must reject a drifted temporary bearer token.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('drift', strtolower($error->getMessage()));
                self::assertFalse(str_contains($error->getMessage(), $foreign));
                self::assertFalse(str_contains($error->getMessage(), $candidate));
            }
            $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($current) && hash_equals($foreign, (string) ($current['value'] ?? '')));

            self::assertTrue($db->update('settings', ['value' => $candidate], ['id' => (int) $row['id']]));
            $secondary = get_instance()->load->database('', true);
            $proxy = new class ($db, $secondary) {
                public bool $injected = false;
                public int $duplicateId = 0;

                public function __construct(private readonly object $database, private readonly object $secondary) {}

                public function query(string $sql, mixed ...$arguments): mixed
                {
                    if (
                        !$this->injected &&
                        str_contains($sql, $this->database->dbprefix('settings')) &&
                        str_contains($sql, 'FOR UPDATE')
                    ) {
                        if (
                            !$this->secondary->insert('settings', [
                                'name' => 'api_token',
                                'value' => bin2hex(random_bytes(32)),
                            ])
                        ) {
                            throw new RuntimeException('Could not inject duplicate API token setting.');
                        }
                        $this->duplicateId = (int) $this->secondary->insert_id();
                        $this->injected = true;
                    }
                    return $this->database->query($sql, ...$arguments);
                }

                public function __call(string $name, array $arguments): mixed
                {
                    return $this->database->$name(...$arguments);
                }
            };
            $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
            $databaseProperty->setValue($this->fixture, $proxy);
            try {
                $this->fixture->deactivate();
                self::fail('Cleanup must reject an API token duplicate inserted before its locked recheck.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('drift', strtolower($error->getMessage()));
            } finally {
                $databaseProperty->setValue($this->fixture, $db);
                $secondary->close();
            }
            self::assertTrue($proxy->injected);
            $duplicateId = $proxy->duplicateId;
            self::assertGreaterThan(0, $duplicateId);
            self::assertSame(2, $db->get_where('settings', ['name' => 'api_token'])->num_rows());

            self::assertTrue($db->delete('settings', ['id' => $duplicateId, 'name' => 'api_token']));
            $duplicateId = 0;
            $this->fixture->deactivate();
            $restored = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
        } finally {
            if ($duplicateId > 0) {
                $db->delete('settings', ['id' => $duplicateId, 'name' => 'api_token']);
            }
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $current = $db->get_where('settings', ['id' => (int) $row['id'], 'name' => 'api_token'])->row_array();
                if (is_array($current) && ($current['value'] ?? null) !== '') {
                    $journal = json_decode(
                        (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                    if (
                        isset($candidate) &&
                        is_string($journal['intents']['api_token']['candidate_digest'] ?? null) &&
                        hash_equals($journal['intents']['api_token']['candidate_digest'], hash('sha256', $candidate))
                    ) {
                        $db->update('settings', ['value' => $candidate], ['id' => (int) $row['id']]);
                    }
                }
                $this->fixture->deactivate();
            }
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id'], 'name' => 'api_token']);
        }
    }

    public function testAppointmentsApiIntentRecoversInsertWhenResponseWasNotJournaled(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $this->fixture->prepareAppointmentsApi();
        $payload = $this->fixture->prepareApiAppointment('basic');
        $ci = &get_instance();
        $ci->load->model('appointments_model');
        $id = $ci->appointments_model->save([
            'start_datetime' => $payload['start'],
            'end_datetime' => $payload['end'],
            'location' => $payload['location'],
            'color' => $payload['color'],
            'status' => $payload['status'],
            'notes' => $payload['notes'],
            'is_unavailability' => 0,
            'id_users_customer' => $payload['customerId'],
            'id_users_provider' => $payload['providerId'],
            'id_services' => $payload['serviceId'],
        ]);
        self::assertGreaterThan(0, $id);

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('appointments', ['id' => $id])->num_rows());
    }

    public function testAppointmentsApiPrincipalRecoversFailureBetweenUserAndSettingsInsert(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $ci = &get_instance();
        $database = $ci->db;
        $proxy = new class ($database) {
            public bool $failed = false;

            public function __construct(private readonly object $database) {}

            public function insert(string $table, array $row): bool
            {
                if (
                    !$this->failed &&
                    $table === 'user_settings' &&
                    str_ends_with((string) ($row['username'] ?? ''), '_api_admin')
                ) {
                    $this->failed = true;
                    return false;
                }
                return $this->database->insert($table, $row);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        try {
            $this->fixture->prepareAppointmentsApi();
            self::fail('Supplemental principal creation must surface the injected settings failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('user_settings', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
        }
        self::assertTrue($proxy->failed);

        $journal = json_decode(
            (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('settings_prepared', $journal['intents']['users']['api_admin']['stage']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $journal['intents']['users']['api_admin']['settings_digest'],
        );
        self::assertArrayHasKey('api_admin', $journal['ids']);
        self::assertSame('cleanup_pending', $this->fixture->verify());

        $email = 'defense_verify_' . $state['run_id'] . '_api_admin@synthetic.invalid';
        $user = $ci->db
            ->get_where('users', [
                'email' => $email,
                'notes' => $state['marker'],
                'id_roles' => $journal['intents']['users']['api_admin']['role_id'],
            ])
            ->row_array();
        self::assertIsArray($user);
        $userId = (int) $user['id'];
        self::assertSame(0, $ci->db->get_where('user_settings', ['id_users' => $userId])->num_rows());

        $salt = generate_salt();
        $preparedUsername = $journal['intents']['users']['api_admin']['username'];
        self::assertTrue(
            $ci->db->insert('user_settings', [
                'id_users' => $userId,
                'username' => $preparedUsername,
                'password' => hash_password($salt, bin2hex(random_bytes(16))),
                'salt' => $salt,
                'working_plan' => '{}',
                'working_plan_exceptions' => '{}',
                'notifications' => 0,
                'google_sync' => 0,
                'caldav_sync' => 0,
            ]),
        );
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must reject settings that differ from the prepared digest.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('settings drifted', $error->getMessage());
        }
        self::assertSame(1, $ci->db->get_where('users', ['id' => $userId])->num_rows());
        self::assertSame(1, $ci->db->get_where('user_settings', ['id_users' => $userId])->num_rows());

        self::assertTrue($ci->db->delete('user_settings', ['id_users' => $userId, 'username' => $preparedUsername]));
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('users', ['id' => $userId])->num_rows());
    }

    public function testAppointmentsApiPrincipalRecoversFailureAfterSettingsInsertBeforeCompleteJournal(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $ci = &get_instance();
        $database = $ci->db;
        $proxy = new class ($database) {
            public bool $inserted = false;

            public function __construct(private readonly object $database) {}

            public function insert(string $table, array $row): bool
            {
                if (
                    !$this->inserted &&
                    $table === 'user_settings' &&
                    str_ends_with((string) ($row['username'] ?? ''), '_api_admin')
                ) {
                    if (!$this->database->insert($table, $row)) {
                        return false;
                    }
                    $this->inserted = true;
                    throw new RuntimeException('Injected failure after Appointments API settings insert.');
                }
                return $this->database->insert($table, $row);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        try {
            $this->fixture->prepareAppointmentsApi();
            self::fail('Supplemental principal creation must surface the post-settings failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('after Appointments API settings insert', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
        }
        self::assertTrue($proxy->inserted);

        $journalText = (string) file_get_contents($this->stateDirectory . '/defense-verification.json');
        $journal = json_decode($journalText, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('settings_prepared', $journal['intents']['users']['api_admin']['stage']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $journal['intents']['users']['api_admin']['settings_digest'],
        );
        self::assertArrayHasKey('api_admin', $journal['ids']);
        self::assertArrayNotHasKey('api_credentials', $journal);
        self::assertSame('cleanup_pending', $this->fixture->verify());

        $userId = (int) $journal['ids']['api_admin'];
        $settings = $ci->db->get_where('user_settings', ['id_users' => $userId])->row_array();
        self::assertIsArray($settings);
        self::assertStringNotContainsString((string) $settings['password'], $journalText);
        self::assertStringNotContainsString((string) $settings['salt'], $journalText);
        self::assertSame(1, $ci->db->get_where('users', ['id' => $userId, 'notes' => $state['marker']])->num_rows());

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('users', ['id' => $userId])->num_rows());
        self::assertSame(0, $ci->db->get_where('user_settings', ['id_users' => $userId])->num_rows());
    }

    public function testAppointmentsApiPrincipalRecoversFailureBeforeUserInsert(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $ci = &get_instance();
        $database = $ci->db;
        $proxy = new class ($database) {
            public bool $failed = false;

            public function __construct(private readonly object $database) {}

            public function insert(string $table, array $row): bool
            {
                if (
                    !$this->failed &&
                    $table === 'users' &&
                    str_ends_with((string) ($row['email'] ?? ''), '_api_admin@synthetic.invalid')
                ) {
                    $this->failed = true;
                    return false;
                }
                return $this->database->insert($table, $row);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        try {
            $this->fixture->prepareAppointmentsApi();
            self::fail('Supplemental principal creation must surface the injected user failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('users', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
        }
        self::assertTrue($proxy->failed);

        $journal = json_decode(
            (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('prepared', $journal['intents']['users']['api_admin']['stage']);
        self::assertArrayNotHasKey('api_admin', $journal['ids']);
        self::assertSame('cleanup_pending', $this->fixture->verify());

        $email = 'defense_verify_' . $state['run_id'] . '_api_admin@synthetic.invalid';
        $username = 'defense_verify_' . $state['run_id'] . '_api_admin';
        self::assertSame(0, $ci->db->get_where('users', ['email' => $email])->num_rows());
        self::assertSame(0, $ci->db->get_where('user_settings', ['username' => $username])->num_rows());

        self::assertTrue(
            $ci->db->insert('users', [
                'first_name' => 'Foreign partial',
                'last_name' => 'API admin',
                'email' => $email,
                'phone_number' => '000000000',
                'notes' => 'foreign-prepared-api-admin',
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => $journal['intents']['users']['api_admin']['role_id'],
                'is_private' => 1,
            ]),
        );
        $foreignUserId = (int) $ci->db->insert_id();
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must retain an unresolved user with the prepared email.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('could not be resolved exactly', $error->getMessage());
        }
        self::assertFileExists($this->stateDirectory . '/defense-verification.json');
        self::assertSame(1, $ci->db->get_where('users', ['id' => $foreignUserId, 'email' => $email])->num_rows());

        self::assertTrue(
            $ci->db->delete('users', [
                'id' => $foreignUserId,
                'email' => $email,
                'notes' => 'foreign-prepared-api-admin',
            ]),
        );

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('users', ['email' => $email])->num_rows());
        self::assertSame(0, $ci->db->get_where('user_settings', ['username' => $username])->num_rows());
    }

    public function testPreparedAppointmentsApiPrincipalRejectsSettingsInsertedBeforeUserLock(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $state = $this->fixture->prepareAppointmentsApi();
        $ci = &get_instance();
        $userId = (int) $state['ids']['api_admin'];
        $username = (string) $state['usernames']['api_admin'];
        self::assertTrue($ci->db->delete('user_settings', ['id_users' => $userId, 'username' => $username]));

        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $journal = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        $journal['intents']['users']['api_admin']['stage'] = 'prepared';
        unset($journal['ids']['api_admin'], $journal['usernames']['api_admin'], $journal['api_credentials']);
        self::assertNotFalse(
            file_put_contents($journalPath, json_encode($journal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"),
        );
        self::assertSame('cleanup_pending', $this->fixture->verify());

        $salt = generate_salt();
        $settings = [
            'id_users' => $userId,
            'username' => $username,
            'password' => hash_password($salt, bin2hex(random_bytes(16))),
            'salt' => $salt,
            'working_plan' => '{}',
            'working_plan_exceptions' => '{}',
            'notifications' => 0,
            'google_sync' => 0,
            'caldav_sync' => 0,
        ];
        $database = $ci->db;
        $secondary = $ci->load->database('', true);
        $proxy = new class ($database, $secondary, $settings) {
            public bool $injected = false;

            public function __construct(
                private readonly object $database,
                private readonly object $secondary,
                private readonly array $settings,
            ) {}

            public function query(string $sql, mixed ...$arguments): mixed
            {
                if (
                    !$this->injected &&
                    str_contains($sql, $this->database->dbprefix('users')) &&
                    str_contains($sql, 'FOR UPDATE')
                ) {
                    if (!$this->secondary->insert('user_settings', $this->settings)) {
                        throw new RuntimeException('Could not inject prepared principal settings.');
                    }
                    $this->injected = true;
                }
                return $this->database->query($sql, ...$arguments);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must reject settings inserted between its precheck and user lock.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unexpected settings', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
            $secondary->close();
        }
        self::assertTrue($proxy->injected);
        self::assertSame(1, $ci->db->get_where('users', ['id' => $userId])->num_rows());
        self::assertSame(1, $ci->db->get_where('user_settings', ['id_users' => $userId])->num_rows());

        self::assertTrue($ci->db->delete('user_settings', ['id_users' => $userId, 'username' => $username]));
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('users', ['id' => $userId])->num_rows());
    }

    public function testAppointmentsApiDeleteGuardRefusesChildBeforeCallbackAndCleanupCanResume(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $this->fixture->prepareAppointmentsApi();
        $ci = &get_instance();
        $ci->load->model('appointments_model');
        $payload = $this->fixture->prepareApiAppointment('basic');
        $id = $ci->appointments_model->save($this->decodeApiPayload($payload));
        $this->fixture->confirmApiAppointmentCreated('basic', $id);
        $update = $this->fixture->prepareApiAppointmentUpdate('basic');
        $ci->appointments_model->save(['id' => $id] + $this->decodeApiPayload($update));
        $this->fixture->confirmApiAppointmentUpdated('basic');
        $this->fixture->prepareApiAppointmentDelete('basic');

        $ci->db->insert('appointments', [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => $update['start'],
            'end_datetime' => $update['end'],
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-api-delete-child',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 1,
            'id_users_provider' => $update['providerId'],
            'id_users_customer' => null,
            'id_services' => null,
            'id_parent_appointment' => $id,
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);
        $childId = (int) $ci->db->insert_id();
        $called = false;
        try {
            $this->fixture->guardApiAppointmentDelete('basic', static function () use (&$called): void {
                $called = true;
            });
            self::fail('Delete guard must reject an unexpected child.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unexpected child', $error->getMessage());
        }
        self::assertFalse($called);
        self::assertSame(1, $ci->db->get_where('appointments', ['id' => $id])->num_rows());

        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must remain fail-closed while the child exists.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('generated appointment child', $error->getMessage());
        }
        $ci->db->delete('appointments', ['id' => $childId, 'notes' => 'foreign-api-delete-child']);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testAppointmentsApiDeleteGuardRevalidatesTargetAfterParentLocks(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $this->fixture->prepareAppointmentsApi();
        $ci = &get_instance();
        $ci->load->model('appointments_model');
        $payload = $this->fixture->prepareApiAppointment('basic');
        $id = $ci->appointments_model->save($this->decodeApiPayload($payload));
        $this->fixture->confirmApiAppointmentCreated('basic', $id);
        $update = $this->fixture->prepareApiAppointmentUpdate('basic');
        $ci->appointments_model->save(['id' => $id] + $this->decodeApiPayload($update));
        $this->fixture->confirmApiAppointmentUpdated('basic');
        $this->fixture->prepareApiAppointmentDelete('basic');

        $originalHash = (string) $ci->db->get_where('appointments', ['id' => $id])->row_array()['hash'];
        $driftedHash = str_repeat($originalHash[0] === 'a' ? 'b' : 'a', 64);
        $secondary = $ci->load->database('', true);
        $database = $ci->db;
        $proxy = new class ($database, $secondary, $id, $driftedHash) {
            public bool $injected = false;

            public function __construct(
                private readonly object $database,
                private readonly object $secondary,
                private readonly int $appointmentId,
                private readonly string $driftedHash,
            ) {}

            public function query(string $sql, mixed ...$arguments): mixed
            {
                if (
                    !$this->injected &&
                    str_contains($sql, $this->database->dbprefix('users')) &&
                    str_contains($sql, 'FOR UPDATE')
                ) {
                    if (
                        !$this->secondary->update(
                            'appointments',
                            ['hash' => $this->driftedHash],
                            ['id' => $this->appointmentId],
                        )
                    ) {
                        throw new RuntimeException('Could not inject appointment drift.');
                    }
                    $this->injected = true;
                }
                return $this->database->query($sql, ...$arguments);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        $called = false;
        try {
            $this->fixture->guardApiAppointmentDelete('basic', static function () use (&$called): void {
                $called = true;
            });
            self::fail('Delete guard must reject target drift after acquiring parent locks.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('hash drift', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
            $secondary->close();
        }
        self::assertTrue($proxy->injected);
        self::assertFalse($called);
        self::assertSame($driftedHash, $ci->db->get_where('appointments', ['id' => $id])->row_array()['hash']);

        self::assertTrue($ci->db->update('appointments', ['hash' => $originalHash], ['id' => $id]));
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    /** @return array<string,mixed> */
    private function singleApiTokenRow(): array
    {
        $rows = get_instance()
            ->db->get_where('settings', ['name' => 'api_token'])
            ->result_array();
        if (
            count($rows) !== 1 ||
            (int) ($rows[0]['id'] ?? 0) < 1 ||
            !array_key_exists('value', $rows[0]) ||
            !is_string($rows[0]['value'])
        ) {
            self::fail('The isolated stack must contain exactly one string-valued API token setting.');
        }
        return $rows[0];
    }

    private function assertActiveTransactionFailsBeforeLifecycleLock(callable $operation): void
    {
        $lockFile = $this->stateDirectory . '/defense-verification.lock';
        $command = [
            PHP_BINARY,
            '-r',
            '$handle = fopen($argv[1], "c");' .
            'if ($handle === false || !flock($handle, LOCK_EX)) { exit(2); }' .
            'fwrite(STDOUT, "locked\\n"); fflush(STDOUT); usleep(2000000);',
            $lockFile,
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 2);
        try {
            self::assertSame("locked\n", fgets($pipes[1]), 'The lifecycle-lock holder did not become ready.');
            $startedAt = hrtime(true);
            try {
                $operation();
                self::fail('The operation must reject an active outer transaction.');
            } catch (RuntimeException $error) {
                self::assertSame(self::ACTIVE_TRANSACTION_ERROR, $error->getMessage());
            }
            self::assertLessThan(
                500,
                (hrtime(true) - $startedAt) / 1_000_000,
                'The active-transaction guard waited for the lifecycle lock.',
            );
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    private function decodeApiPayload(array $payload): array
    {
        return [
            'start_datetime' => $payload['start'],
            'end_datetime' => $payload['end'],
            'location' => $payload['location'],
            'color' => $payload['color'],
            'status' => $payload['status'],
            'notes' => $payload['notes'],
            'is_unavailability' => 0,
            'id_users_customer' => $payload['customerId'],
            'id_users_provider' => $payload['providerId'],
            'id_services' => $payload['serviceId'],
        ];
    }

    public function testCleanupRefusesForeignProviderLinkWithoutDeletingService(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $providerRole = $db->get_where('roles', ['slug' => 'provider'])->row_array();
        self::assertIsArray($providerRole);
        $db->insert('users', [
            'first_name' => 'Synthetic foreign child',
            'last_name' => 'cleanup regression',
            'email' => bin2hex(random_bytes(8)) . '@synthetic.invalid',
            'phone_number' => '000000000',
            'notes' => 'foreign-defense-verification-provider-child',
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => (int) $providerRole['id'],
            'is_private' => 1,
        ]);
        $extraProviderId = (int) $db->insert_id();
        $db->insert('services_providers', [
            'id_users' => $extraProviderId,
            'id_services' => (int) $state['service_id'],
        ]);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a foreign provider relationship.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Unexpected service provider relationship', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(
            1,
            $db
                ->get_where('services_providers', [
                    'id_users' => $extraProviderId,
                    'id_services' => (int) $state['service_id'],
                ])
                ->num_rows(),
        );
        $db->delete('services_providers', [
            'id_users' => $extraProviderId,
            'id_services' => (int) $state['service_id'],
        ]);
        $db->delete('users', ['id' => $extraProviderId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testCleanupRefusesForeignAppointmentWithoutDeletingItOrService(): void
    {
        $db = &get_instance()->db;
        $tokenRow = $this->singleApiTokenRow();
        self::assertTrue($db->update('settings', ['value' => ''], ['id' => (int) $tokenRow['id']]));
        $foreignAppointmentId = 0;
        try {
            $actor = $this->ordinary->activate();
            $state = $this->fixture->activate('calendar_race', $actor);
            $this->fixture->prepareAppointmentsApiBearerToken();
            self::assertTrue(
                $db->insert('appointments', [
                    'create_datetime' => date('Y-m-d H:i:s'),
                    'update_datetime' => date('Y-m-d H:i:s'),
                    'book_datetime' => date('Y-m-d H:i:s'),
                    'start_datetime' => '2099-12-01 10:00:00',
                    'end_datetime' => '2099-12-01 10:30:00',
                    'location' => null,
                    'color' => '#6c757d',
                    'status' => 'Booked',
                    'notes' => 'foreign-defense-verification-child',
                    'hash' => bin2hex(random_bytes(32)),
                    'is_unavailability' => 0,
                    'id_users_provider' => (int) $state['foreign_provider_id'],
                    'id_users_customer' => (int) $state['customer_id'],
                    'id_services' => (int) $state['service_id'],
                    'id_parent_appointment' => null,
                    'id_google_calendar' => null,
                    'id_caldav_calendar' => null,
                ]),
            );
            $foreignAppointmentId = (int) $db->insert_id();
            try {
                $this->fixture->deactivate();
                self::fail('Cleanup must refuse foreign service children.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('service appointment relationship drifted', $error->getMessage());
            }
            $restored = $db->get_where('settings', ['id' => (int) $tokenRow['id'], 'name' => 'api_token'])->row_array();
            self::assertTrue(is_array($restored) && ($restored['value'] ?? null) === '');
            $journal = json_decode(
                (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame('cleaning', $journal['phase']);
            self::assertSame(1, $db->get_where('appointments', ['id' => $foreignAppointmentId])->num_rows());
            self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
            self::assertTrue($db->delete('appointments', ['id' => $foreignAppointmentId]));
            $foreignAppointmentId = 0;
            $this->fixture->deactivate();
            self::assertSame('clean', $this->fixture->verify());
        } finally {
            if ($foreignAppointmentId > 0) {
                $db->delete('appointments', ['id' => $foreignAppointmentId]);
            }
            if (is_file($this->stateDirectory . '/defense-verification.json')) {
                $this->fixture->deactivate();
            }
            $db->update(
                'settings',
                ['value' => $tokenRow['value']],
                ['id' => (int) $tokenRow['id'], 'name' => 'api_token'],
            );
        }
    }

    public function testCleanupRefusesForeignSecretaryRelationshipAndCanResume(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $secretaryRole = $db->get_where('roles', ['slug' => 'secretary'])->row_array();
        self::assertIsArray($secretaryRole);
        $db->insert('users', [
            'first_name' => 'Synthetic secretary',
            'last_name' => 'cleanup regression',
            'email' => bin2hex(random_bytes(8)) . '@synthetic.invalid',
            'phone_number' => '000000000',
            'notes' => 'foreign-defense-verification-secretary',
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => (int) $secretaryRole['id'],
            'is_private' => 1,
        ]);
        $secretaryId = (int) $db->insert_id();
        $db->insert('secretaries_providers', [
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_secretary' => $secretaryId,
        ]);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a foreign secretary relationship.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('secretary relationship', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(
            1,
            $db
                ->get_where('secretaries_providers', [
                    'id_users_provider' => (int) $state['foreign_provider_id'],
                    'id_users_secretary' => $secretaryId,
                ])
                ->num_rows(),
        );
        $db->delete('secretaries_providers', [
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_secretary' => $secretaryId,
        ]);
        $db->delete('users', ['id' => $secretaryId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testPreparedJournalRecoversExactRowsWhenPublishedIdsAreMissing(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $prepared = $state;
        $prepared['phase'] = 'prepared';
        unset($prepared['ids']['appointment'], $prepared['ids']['service'], $prepared['ids']['foreign_provider']);
        file_put_contents($journalPath, json_encode($prepared, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        $db = &get_instance()->db;
        self::assertSame(0, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(0, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(0, $db->get_where('users', ['id' => $state['foreign_provider_id']])->num_rows());
    }

    public function testServicesApiPreparedJournalRecoversServicesBeforeIdsArePublished(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('services_api', $actor);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $prepared = $state;
        $prepared['phase'] = 'prepared';
        unset($prepared['ids']['service_a'], $prepared['ids']['service_b']);
        file_put_contents($journalPath, json_encode($prepared, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        $db = &get_instance()->db;
        foreach (['service_a', 'service_b'] as $key) {
            self::assertSame(0, $db->get_where('services', ['id' => $state['ids'][$key]])->num_rows());
            self::assertSame(
                0,
                $db->get_where('services_providers', ['id_services' => $state['ids'][$key]])->num_rows(),
            );
        }
    }

    public function testServicesApiPreparedCleanupAcceptsOnlyJournaledLinksThatExist(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('services_api', $actor);
        $link = $state['links']['provider_service_b'];
        $db = &get_instance()->db;
        $db->delete('services_providers', $link);
        $state['phase'] = 'prepared';
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        foreach (['service_a', 'service_b'] as $key) {
            self::assertSame(0, $db->get_where('services', ['id' => $state['ids'][$key]])->num_rows());
        }
    }

    public function testServicesApiPreparedLinkAllowanceSurvivesCleaningRetry(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('services_api', $actor);
        $link = $state['links']['provider_service_b'];
        $db = &get_instance()->db;
        $db->delete('services_providers', $link);
        $state['phase'] = 'cleaning';
        $state['cleanup_origin_phase'] = 'prepared';
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        foreach (['service_a', 'service_b'] as $key) {
            self::assertSame(0, $db->get_where('services', ['id' => $state['ids'][$key]])->num_rows());
        }
    }

    public function testPreparedCleanupRetainsMissingLinkAllowanceAcrossAFailedAttempt(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $missingLink = $state['links']['foreign_service'];
        $db = &get_instance()->db;
        $db->delete('services_providers', [
            'id_users' => (int) $missingLink['id_users'],
            'id_services' => (int) $missingLink['id_services'],
        ]);
        $secretaryRole = $db->get_where('roles', ['slug' => 'secretary'])->row_array();
        self::assertIsArray($secretaryRole);
        $db->insert('users', [
            'first_name' => 'Synthetic prepared secretary',
            'last_name' => 'cleanup retry regression',
            'email' => bin2hex(random_bytes(8)) . '@synthetic.invalid',
            'phone_number' => '000000000',
            'notes' => 'foreign-prepared-cleanup-secretary',
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => (int) $secretaryRole['id'],
            'is_private' => 1,
        ]);
        $secretaryId = (int) $db->insert_id();
        $db->insert('secretaries_providers', [
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_secretary' => $secretaryId,
        ]);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state['phase'] = 'prepared';
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        try {
            $this->fixture->deactivate();
            self::fail('Prepared cleanup must still refuse an unjournaled secretary relationship.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('secretary relationship', $error->getMessage());
        }
        $persisted = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('cleaning', $persisted['phase']);
        self::assertSame('prepared', $persisted['cleanup_origin_phase']);
        $db->delete('secretaries_providers', [
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_secretary' => $secretaryId,
        ]);
        $db->delete('users', ['id' => $secretaryId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(
            0,
            $db
                ->get_where('services_providers', [
                    'id_users' => (int) $missingLink['id_users'],
                    'id_services' => (int) $missingLink['id_services'],
                ])
                ->num_rows(),
        );
    }

    public function testPreparedServiceBeforeAppointmentJournalRejectsForeignAppointmentAndResumes(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $db->delete('appointments', ['id' => (int) $state['appointment_id']]);
        $db->insert('appointments', [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => '2099-12-02 10:00:00',
            'end_datetime' => '2099-12-02 10:30:00',
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-prepared-appointment',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 0,
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_customer' => (int) $state['customer_id'],
            'id_services' => (int) $state['service_id'],
            'id_parent_appointment' => null,
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);
        $foreignAppointmentId = (int) $db->insert_id();
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state['phase'] = 'prepared';
        unset($state['ids']['appointment'], $state['intents']['appointment']);
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);
        try {
            $this->fixture->deactivate();
            self::fail('Prepared cleanup must reject an unjournaled service appointment.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('appointment relationship', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('appointments', ['id' => $foreignAppointmentId])->num_rows());
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        $db->delete('appointments', ['id' => $foreignAppointmentId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testCleanupRefusesGeneratedBufferChildAndResumesAfterRemoval(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $db->insert('appointments', [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => '2099-12-03 10:00:00',
            'end_datetime' => '2099-12-03 10:30:00',
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-generated-buffer-child',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 1,
            'id_users_provider' => (int) $state['actor_id'],
            'id_users_customer' => null,
            'id_services' => null,
            'id_parent_appointment' => (int) $state['appointment_id'],
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);
        $childId = (int) $db->insert_id();
        $db->delete('appointments', ['id' => (int) $state['appointment_id']]);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state['phase'] = 'prepared';
        unset($state['ids']['appointment'], $state['intents']['appointment']);
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a generated appointment child.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('generated appointment child', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('appointments', ['id' => $childId])->num_rows());
        self::assertSame(0, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        $db->delete('appointments', ['id' => $childId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testPreparedMissingAppointmentIdWithReboundParentFailsClosed(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $parent = $db->get_where('appointments', ['id' => (int) $state['appointment_id']])->row_array();
        self::assertIsArray($parent);
        $db->update(
            'appointments',
            [
                'id_users_provider' => (int) $state['foreign_provider_id'],
            ],
            ['id' => (int) $state['appointment_id']],
        );
        $db->insert('appointments', [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => '2099-12-04 10:00:00',
            'end_datetime' => '2099-12-04 10:30:00',
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-rebound-buffer',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 1,
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_customer' => null,
            'id_services' => null,
            'id_parent_appointment' => (int) $state['appointment_id'],
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);
        $childId = (int) $db->insert_id();
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state['phase'] = 'prepared';
        unset($state['ids']['appointment']);
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);
        try {
            $this->fixture->deactivate();
            self::fail('Unreconstructed appointment intent must block cleanup.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Appointment intent', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(1, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(1, $db->get_where('appointments', ['id' => $childId])->num_rows());
        $db->delete('appointments', ['id' => (int) $state['appointment_id']]);
        try {
            $this->fixture->deactivate();
            self::fail('An orphaned buffer must not resolve an unreconstructed appointment intent.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Appointment intent', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(0, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(1, $db->get_where('appointments', ['id' => $childId])->num_rows());
        $db->insert('appointments', $parent);
        $db->delete('appointments', ['id' => $childId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testOwnershipDriftRefusesCleanupUntilExactStateIsRestored(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $db->update('services', ['description' => 'foreign'], ['id' => $state['service_id']]);
        self::assertSame('cleanup_pending', $this->fixture->verify());

        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a drifted synthetic service.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('drift', strtolower($error->getMessage()));
        } finally {
            $db->update(
                'services',
                ['description' => $state['marker']],
                ['id' => $state['service_id'], 'description' => 'foreign'],
            );
        }

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testInterruptedTemporaryJournalBlocksVerificationAndActivation(): void
    {
        $temporary = $this->stateDirectory . '/defense-verification.json.tmp';
        file_put_contents($temporary, 'synthetic interrupted journal');
        chmod($temporary, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        try {
            $this->fixture->assertCleanBeforeActivation();
            self::fail('An interrupted temporary journal must block activation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('already exists', $error->getMessage());
        }
        self::assertSame('synthetic interrupted journal', file_get_contents($temporary));

        unlink($temporary);
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testMissingJournalDoesNotHideReservedSyntheticRows(): void
    {
        $db = &get_instance()->db;
        $role = $db->get_where('roles', ['slug' => 'customer'])->row_array();
        $marker = 'defense-verification:' . bin2hex(random_bytes(16));
        $email = bin2hex(random_bytes(16)) . '@synthetic.invalid';
        $db->insert('users', [
            'first_name' => 'Synthetic',
            'last_name' => 'Orphan',
            'email' => $email,
            'phone_number' => '000000000',
            'notes' => $marker,
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => (int) $role['id'],
            'is_private' => 1,
        ]);
        $id = (int) $db->insert_id();
        try {
            self::assertSame('cleanup_pending', $this->fixture->verify());
            $this->expectException(RuntimeException::class);
            $this->fixture->assertCleanBeforeActivation();
        } finally {
            $db->delete('users', ['id' => $id, 'email' => $email, 'notes' => $marker]);
        }
    }

    public function testUnconfirmedCalendarRequestRetainsFixtureForExplicitRecovery(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $this->fixture->retainForRecovery('calendar_request_termination_unconfirmed');

        self::assertSame('cleanup_pending', $this->fixture->verify());
        try {
            $this->fixture->deactivate();
            self::fail('Automatic cleanup must not release an unconfirmed request fixture.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('explicit request recovery', $error->getMessage());
        }

        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('recovery_required', $state['phase']);
        self::assertSame('calendar_request_termination_unconfirmed', $state['recovery_reason']);

        // This simulates the separate operator decision only inside the disposable test stack.
        $state['phase'] = 'active';
        unset($state['recovery_reason']);
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }
}

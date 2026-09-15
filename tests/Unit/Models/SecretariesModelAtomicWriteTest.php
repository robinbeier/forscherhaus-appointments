<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use InvalidArgumentException;
use Providers_model;
use RuntimeException;
use Tests\TestCase;
use Secretaries_model;

require_once APPPATH . 'models/Secretaries_model.php';

final class SecretariesModelAtomicWriteTest extends TestCase
{
    private Secretaries_model $secretariesModel;

    private Providers_model $providersModel;

    /** @var list<int> */
    private array $createdUserIds = [];

    /** @var array<string, string> */
    private array $ownedIdentities = [];

    protected function setUp(): void
    {
        parent::setUp();

        get_instance()->load->model('secretaries_model');
        get_instance()->load->model('providers_model');
        $this->secretariesModel = get_instance()->secretaries_model;
        $this->providersModel = get_instance()->providers_model;
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupOwnedFixtures();
            $this->assertOwnedFixturesAbsent();
        } finally {
            parent::tearDown();
        }
    }

    public function test_partial_fixture_cleanup_is_bounded_to_owned_identities(): void
    {
        $owned = $this->secretaryData([]);
        $ownedId = 0;
        try {
            $db = get_instance()->db;
            self::assertTrue(
                $db->insert('users', [
                    'first_name' => $owned['first_name'],
                    'last_name' => $owned['last_name'],
                    'email' => $owned['email'],
                    'id_roles' => $this->secretariesModel->get_secretary_role_id(),
                    'create_datetime' => date('Y-m-d H:i:s'),
                    'update_datetime' => date('Y-m-d H:i:s'),
                ]),
            );
            $ownedId = (int) $db->insert_id();
            self::assertGreaterThan(0, $ownedId);
            throw new RuntimeException('simulated fixture creation failure');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated fixture creation failure', $exception->getMessage());
        }

        $sentinelData = $this->secretaryData([]);
        $sentinel = 0;
        $db = get_instance()->db;
        try {
            $sentinel = $this->secretariesModel->save($sentinelData);
            self::assertGreaterThan(0, $sentinel);
            unset($this->ownedIdentities[$sentinelData['email']]);
            $sentinelSnapshot = $this->snapshot($sentinel);
            self::assertNotEmpty($sentinelSnapshot['user']);
            self::assertNotEmpty($sentinelSnapshot['settings']);
            $this->cleanupOwnedFixtures();
            self::assertSame(0, $db->get_where('users', ['id' => $ownedId])->num_rows());
            self::assertSame($sentinelSnapshot, $this->snapshot($sentinel));
        } finally {
            if ($sentinel > 0) {
                $db->delete('user_settings', ['id_users' => $sentinel]);
                $db->delete('users', ['id' => $sentinel]);
                self::assertSame(0, $db->get_where('users', ['id' => $sentinel])->num_rows());
                self::assertSame(0, $db->get_where('user_settings', ['id_users' => $sentinel])->num_rows());
            }
        }
    }

    private function cleanupOwnedFixtures(): void
    {
        $db = get_instance()->db;
        if ($db->trans_active()) {
            self::assertTrue($db->trans_rollback());
            self::assertFalse($db->trans_active());
        }
        foreach ($this->ownedIdentities as $email => $roleSlug) {
            $row = $db
                ->select('users.id')
                ->from('users')
                ->join('roles', 'roles.id = users.id_roles')
                ->where(['users.email' => $email, 'roles.slug' => $roleSlug])
                ->get()
                ->row_array();
            if ($row) {
                $this->createdUserIds[] = (int) $row['id'];
            }
        }
        foreach ($this->createdUserIds as $userId) {
            $db->where('id_users_provider', $userId)
                ->or_where('id_users_secretary', $userId)
                ->delete('secretaries_providers');
            $db->delete('user_settings', ['id_users' => $userId]);
            $db->delete('users', ['id' => $userId]);
        }
    }

    private function assertOwnedFixturesAbsent(): void
    {
        $db = get_instance()->db;
        foreach ($this->ownedIdentities as $email => $roleSlug) {
            self::assertSame(
                0,
                $db
                    ->select('users.id')
                    ->from('users')
                    ->join('roles', 'roles.id = users.id_roles')
                    ->where(['users.email' => $email, 'roles.slug' => $roleSlug])
                    ->get()
                    ->num_rows(),
            );
        }
        foreach (array_unique($this->createdUserIds) as $userId) {
            self::assertSame(0, $db->get_where('users', ['id' => $userId])->num_rows());
            self::assertSame(0, $db->get_where('user_settings', ['id_users' => $userId])->num_rows());
            self::assertSame(
                0,
                $db
                    ->where('id_users_provider', $userId)
                    ->or_where('id_users_secretary', $userId)
                    ->get('secretaries_providers')
                    ->num_rows(),
            );
        }
    }

    public function test_create_update_and_empty_provider_clear_persist_atomically(): void
    {
        $providerId = $this->createProvider();
        $secretary = $this->secretaryData([$providerId]);
        $secretaryId = $this->secretariesModel->save($secretary);
        $this->createdUserIds[] = $secretaryId;

        $stored = $this->secretariesModel->find($secretaryId);
        $this->assertSame([$providerId], $stored['providers']);
        $this->assertSame('Atomic secretary', $stored['first_name']);
        $this->assertSame('atomic-notes', $stored['notes']);

        $stored['notes'] = 'updated-notes';
        $stored['settings'] = ['username' => 'secretary-updated'];
        $stored['providers'] = [];
        $this->secretariesModel->save($stored);

        $updated = $this->secretariesModel->find($secretaryId);
        $this->assertSame('updated-notes', $updated['notes']);
        $this->assertSame('secretary-updated', $updated['settings']['username']);
        $this->assertSame([], $updated['providers']);
        $this->assertFalse(get_instance()->db->trans_active());
    }

    public function test_decimal_string_provider_ids_are_deduplicated_and_canonicalized(): void
    {
        $providerId = $this->createProvider();
        $secretary = $this->secretaryData([(string) $providerId, (string) $providerId]);
        $secretaryId = $this->secretariesModel->save($secretary);
        $this->createdUserIds[] = $secretaryId;

        $this->assertSame([$providerId], $this->secretariesModel->find($secretaryId)['providers']);
        $this->assertSame(
            1,
            get_instance()
                ->db->get_where('secretaries_providers', ['id_users_secretary' => $secretaryId])
                ->num_rows(),
        );
    }

    public function test_invalid_provider_collection_role_and_missing_provider_fail_before_mutation(): void
    {
        $secretary = $this->secretaryData([]);
        foreach (['invalid collection', ['invalid'], [0], [1.5], [true], ['01']] as $invalidProviders) {
            $secretary['providers'] = $invalidProviders;
            $this->assertValidationFailure($secretary);
        }

        $wrongRoleId = $this->secretariesModel->save($this->secretaryData([]));
        $this->createdUserIds[] = $wrongRoleId;
        $secretary['providers'] = [$wrongRoleId];
        $this->assertValidationFailure($secretary);

        $secretary['providers'] = [PHP_INT_MAX];
        $this->assertValidationFailure($secretary);

        $this->assertSame(
            0,
            get_instance()
                ->db->get_where('users', ['email' => $secretary['email']])
                ->num_rows(),
        );
    }

    public function test_wrong_target_role_and_role_changes_leave_owned_records_unchanged(): void
    {
        $providerId = $this->createProvider();
        $beforeProvider = $this->snapshot($providerId);
        $wrongTarget = $this->secretaryData([]);
        $wrongTarget['id'] = $providerId;
        $this->assertValidationFailure($wrongTarget);
        $this->assertSame($beforeProvider, $this->snapshot($providerId));

        $secretaryId = $this->secretariesModel->save($this->secretaryData([$providerId]));
        $this->createdUserIds[] = $secretaryId;
        $beforeSecretary = $this->snapshot($secretaryId);
        $changed = $this->secretariesModel->find($secretaryId);
        $changed['id_roles'] = $beforeProvider['user']['id_roles'];
        $changed['notes'] = 'must not change role';
        $this->assertValidationFailure($changed);
        $this->assertSame($beforeSecretary, $this->snapshot($secretaryId));
        $this->assertFalse(get_instance()->db->trans_active());
    }

    public function test_api_decode_omitted_providers_preserves_base_but_direct_save_omission_clears(): void
    {
        $providerId = $this->createProvider();
        $secretary = $this->secretaryData([$providerId]);
        $secretaryId = $this->secretariesModel->save($secretary);
        $this->createdUserIds[] = $secretaryId;

        $base = $this->secretariesModel->find($secretaryId);
        $apiUpdate = ['notes' => 'api omission'];
        $this->secretariesModel->api_decode($apiUpdate, $base);
        $this->assertSame([$providerId], $apiUpdate['providers']);
        $this->secretariesModel->save($apiUpdate);
        $this->assertSame([$providerId], $this->secretariesModel->find($secretaryId)['providers']);

        $direct = $this->secretariesModel->find($secretaryId);
        unset($direct['providers']);
        $direct['notes'] = 'direct omission';
        $this->secretariesModel->save($direct);
        $this->assertSame([], $this->secretariesModel->find($secretaryId)['providers']);
    }

    public function test_settings_failure_after_mutation_rolls_back_insert_and_update(): void
    {
        $model = $this->failingModel('settings');
        $insert = $this->secretaryData([]);
        try {
            $model->save($insert);
            $this->fail('Expected injected settings failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected secretary settings failure.', $exception->getMessage());
        } finally {
            $this->assertFalse(get_instance()->db->trans_active());
            $this->assertSame(
                0,
                get_instance()
                    ->db->get_where('users', ['email' => $insert['email']])
                    ->num_rows(),
            );
        }

        $secretaryId = $this->secretariesModel->save($this->secretaryData([]));
        $this->createdUserIds[] = $secretaryId;
        $before = $this->snapshot($secretaryId);
        $changed = $this->secretariesModel->find($secretaryId);
        $changed['notes'] = 'must roll back';
        $changed['settings']['username'] = 'secretary-settings-rollback';
        try {
            $model->save($changed);
            $this->fail('Expected injected settings failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected secretary settings failure.', $exception->getMessage());
        }
        $this->assertFalse(get_instance()->db->trans_active());
        $this->assertSame($before, $this->snapshot($secretaryId));
    }

    public function test_relation_failure_after_replacement_rolls_back_update(): void
    {
        $providerId = $this->createProvider();
        $secretaryId = $this->secretariesModel->save($this->secretaryData([$providerId]));
        $this->createdUserIds[] = $secretaryId;
        $before = $this->snapshot($secretaryId);

        $model = $this->failingModel('relation');
        $changed = $this->secretariesModel->find($secretaryId);
        $changed['notes'] = 'must roll back';
        $changed['providers'] = [];
        try {
            $model->save($changed);
            $this->fail('Expected injected relation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected secretary relation failure.', $exception->getMessage());
        }

        $this->assertFalse(get_instance()->db->trans_active());
        $this->assertSame($before, $this->snapshot($secretaryId));
    }

    public function test_standalone_success_and_failure_close_their_transactions(): void
    {
        $providerId = $this->createProvider();
        $secretaryId = $this->secretariesModel->save($this->secretaryData([$providerId]));
        $this->createdUserIds[] = $secretaryId;
        $this->assertFalse(get_instance()->db->trans_active());

        try {
            $changed = $this->secretariesModel->find($secretaryId);
            $changed['providers'] = [];
            $this->failingModel('relation')->save($changed);
            $this->fail('Expected injected relation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected secretary relation failure.', $exception->getMessage());
        }
        $this->assertFalse(get_instance()->db->trans_active());
    }

    public function test_outer_transaction_retains_ownership_on_success_and_failure(): void
    {
        $providerId = $this->createProvider();
        $db = get_instance()->db;
        $open = $db->trans_begin();
        $this->assertTrue($open);
        try {
            $secretaryId = $this->secretariesModel->save($this->secretaryData([$providerId]));
            $this->createdUserIds[] = $secretaryId;
            $this->assertTrue($db->trans_active());
            $db->trans_rollback();
            $this->assertSame(0, $db->get_where('users', ['id' => $secretaryId])->num_rows());
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }

        $open = $db->trans_begin();
        $this->assertTrue($open);
        try {
            $model = $this->failingModel('settings');
            $insert = $this->secretaryData([]);
            try {
                $model->save($insert);
                $this->fail('Expected injected settings failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected secretary settings failure.', $exception->getMessage());
                $this->assertTrue($db->trans_active());
            }
            $this->assertSame(1, $db->get_where('users', ['email' => $insert['email']])->num_rows());
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }
        $this->assertFalse($db->trans_active());
        $this->assertSame(0, $db->get_where('users', ['email' => $insert['email']])->num_rows());
    }

    public function test_public_provider_setter_validates_before_replacement_and_respects_outer_owner(): void
    {
        $providerId = $this->createProvider();
        $secretaryId = $this->secretariesModel->save($this->secretaryData([$providerId]));
        $this->createdUserIds[] = $secretaryId;
        $db = get_instance()->db;

        $this->secretariesModel->set_provider_ids($secretaryId, [(string) $providerId]);
        $this->assertSame([$providerId], $this->secretariesModel->get_provider_ids($secretaryId));
        $this->assertFalse($db->trans_active());
        $before = $this->snapshot($secretaryId)['providers'];

        $wrongRoleId = $this->secretariesModel->save($this->secretaryData([]));
        $this->createdUserIds[] = $wrongRoleId;
        $open = $db->trans_begin();
        $this->assertTrue($open);
        try {
            try {
                $this->secretariesModel->set_provider_ids($secretaryId, [$wrongRoleId, PHP_INT_MAX]);
                $this->fail('Expected provider assignment validation failure.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('synthetic-secretary-password', $exception->getMessage());
                $this->assertTrue($db->trans_active());
            }
            $this->assertSame($before, $this->snapshot($secretaryId)['providers']);
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }
        $this->assertFalse($db->trans_active());
    }

    /** @param list<int|string> $providerIds */
    private function secretaryData(array $providerIds): array
    {
        $email = 'secretary-atomic-' . bin2hex(random_bytes(4)) . '@example.org';
        $this->ownedIdentities[$email] = DB_SLUG_SECRETARY;
        return [
            'first_name' => 'Atomic secretary',
            'last_name' => 'Synthetic',
            'email' => $email,
            'notes' => 'atomic-notes',
            'providers' => $providerIds,
            'settings' => [
                'username' => 'secretary-' . bin2hex(random_bytes(4)),
                'password' => 'synthetic-secretary-password',
            ],
        ];
    }

    private function createProvider(): int
    {
        $email = 'provider-atomic-' . bin2hex(random_bytes(4)) . '@example.org';
        $this->ownedIdentities[$email] = DB_SLUG_PROVIDER;
        $provider = [
            'first_name' => 'Synthetic provider',
            'last_name' => 'Atomic',
            'email' => $email,
            'services' => [],
            'settings' => [
                'username' => 'provider-' . bin2hex(random_bytes(4)),
                'password' => 'synthetic-provider-password',
            ],
        ];
        $id = $this->providersModel->save($provider);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function assertValidationFailure(array $secretary): void
    {
        try {
            $this->secretariesModel->save($secretary);
            $this->fail('Expected secretary validation failure.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringNotContainsString('synthetic-secretary-password', $exception->getMessage());
        }
    }

    private function failingModel(string $failurePoint): FailingSecretariesModel
    {
        $model = new FailingSecretariesModel();
        $model->failurePoint = $failurePoint;
        return $model;
    }

    /** @return array{user: array<string,mixed>, settings: array<string,mixed>, providers: list<array<string,mixed>>} */
    private function snapshot(int $secretaryId): array
    {
        $db = get_instance()->db;
        return [
            'user' => $db->get_where('users', ['id' => $secretaryId])->row_array(),
            'settings' => $db->get_where('user_settings', ['id_users' => $secretaryId])->row_array(),
            'providers' => $db
                ->order_by('id_users_provider')
                ->get_where('secretaries_providers', ['id_users_secretary' => $secretaryId])
                ->result_array(),
        ];
    }
}

final class FailingSecretariesModel extends Secretaries_model
{
    public string $failurePoint;

    public function set_settings(int $secretary_id, array $settings): void
    {
        parent::set_settings($secretary_id, $settings);
        if ($this->failurePoint === 'settings') {
            throw new RuntimeException('Injected secretary settings failure.');
        }
    }

    public function set_provider_ids(int $secretary_id, array $provider_ids): void
    {
        parent::set_provider_ids($secretary_id, $provider_ids);
        if ($this->failurePoint === 'relation') {
            throw new RuntimeException('Injected secretary relation failure.');
        }
    }
}

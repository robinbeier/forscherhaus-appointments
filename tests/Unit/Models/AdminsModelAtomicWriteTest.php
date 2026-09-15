<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use RuntimeException;
use Tests\TestCase;
use Admins_model;

require_once APPPATH . 'models/Admins_model.php';

final class AdminsModelAtomicWriteTest extends TestCase
{
    private Admins_model $adminsModel;

    /** @var list<int> */
    private array $createdUserIds = [];

    /** @var array<string, string> */
    private array $ownedIdentities = [];

    protected function setUp(): void
    {
        parent::setUp();
        get_instance()->load->model('admins_model');
        $this->adminsModel = get_instance()->admins_model;
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

    public function test_partial_fixture_cleanup_is_bounded_to_owned_identity(): void
    {
        $owned = $this->adminData();
        $ownedId = 0;
        try {
            $db = get_instance()->db;
            self::assertTrue(
                $db->insert('users', [
                    'first_name' => $owned['first_name'],
                    'last_name' => $owned['last_name'],
                    'email' => $owned['email'],
                    'id_roles' => $this->adminsModel->get_admin_role_id(),
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

        $sentinelData = $this->adminData();
        $sentinel = 0;
        $db = get_instance()->db;
        try {
            $sentinel = $this->adminsModel->save($sentinelData);
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
        foreach (array_unique($this->createdUserIds) as $userId) {
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

    public function test_create_and_update_persist_user_and_settings_atomically(): void
    {
        $admin = $this->adminData();
        $adminId = $this->adminsModel->save($admin);
        $this->createdUserIds[] = $adminId;
        $stored = $this->adminsModel->find($adminId);
        $this->assertSame('Atomic admin', $stored['first_name']);
        $this->assertSame('atomic-notes', $stored['notes']);
        $this->assertSame($admin['settings']['username'], $stored['settings']['username']);

        $stored['notes'] = 'updated-notes';
        $updatedUsername = 'admin-update-' . bin2hex(random_bytes(4));
        $stored['settings']['username'] = $updatedUsername;
        $this->adminsModel->save($stored);
        $updated = $this->adminsModel->find($adminId);
        $this->assertSame('updated-notes', $updated['notes']);
        $this->assertSame($updatedUsername, $updated['settings']['username']);
        $this->assertFalse(get_instance()->db->trans_active());
    }

    public function test_omitted_password_preserves_existing_password_and_salt(): void
    {
        $adminId = $this->adminsModel->save($this->adminData());
        $this->createdUserIds[] = $adminId;
        $db = get_instance()->db;
        $before = $db->get_where('user_settings', ['id_users' => $adminId])->row_array();
        $changed = $this->adminsModel->find($adminId);
        $this->assertArrayNotHasKey('password', $changed['settings']);
        $changed['notes'] = 'password omitted';
        $this->adminsModel->save($changed);
        $after = $db->get_where('user_settings', ['id_users' => $adminId])->row_array();
        $this->assertSame($before['password'], $after['password']);
        $this->assertSame($before['salt'], $after['salt']);
    }

    public function test_settings_failure_after_real_mutation_rolls_back_insert_and_update(): void
    {
        $model = $this->failingModel();
        $insert = $this->adminData();
        try {
            $model->save($insert);
            $this->fail('Expected injected settings failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected admin settings failure.', $exception->getMessage());
        }
        $this->assertFalse(get_instance()->db->trans_active());
        $this->assertSame(
            0,
            get_instance()
                ->db->get_where('users', ['email' => $insert['email']])
                ->num_rows(),
        );

        $adminId = $this->adminsModel->save($this->adminData());
        $this->createdUserIds[] = $adminId;
        $before = $this->snapshot($adminId);
        $changed = $this->adminsModel->find($adminId);
        $changed['notes'] = 'must roll back';
        $changed['settings']['username'] = 'admin-settings-rollback';
        try {
            $model->save($changed);
            $this->fail('Expected injected settings failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected admin settings failure.', $exception->getMessage());
        }
        $this->assertFalse(get_instance()->db->trans_active());
        $this->assertSame($before, $this->snapshot($adminId));
    }

    public function test_standalone_success_and_failure_close_model_transactions(): void
    {
        $adminId = $this->adminsModel->save($this->adminData());
        $this->createdUserIds[] = $adminId;
        $this->assertFalse(get_instance()->db->trans_active());
        $changed = $this->adminsModel->find($adminId);
        try {
            $this->failingModel()->save($changed);
            $this->fail('Expected injected settings failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected admin settings failure.', $exception->getMessage());
        }
        $this->assertFalse(get_instance()->db->trans_active());
    }

    public function test_outer_transaction_retains_ownership_and_rollback_restores_state(): void
    {
        $db = get_instance()->db;
        $open = $db->trans_begin();
        $this->assertTrue($open);
        $admin = $this->adminData();
        try {
            $adminId = $this->adminsModel->save($admin);
            $this->createdUserIds[] = $adminId;
            $this->assertTrue($db->trans_active());
            $this->assertSame(1, $db->get_where('users', ['email' => $admin['email']])->num_rows());
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }
        $this->assertSame(0, $db->get_where('users', ['email' => $admin['email']])->num_rows());

        $open = $db->trans_begin();
        $this->assertTrue($open);
        $failed = $this->adminData();
        try {
            try {
                $this->failingModel()->save($failed);
                $this->fail('Expected injected settings failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected admin settings failure.', $exception->getMessage());
                $this->assertTrue($db->trans_active());
            }
            $this->assertSame(1, $db->get_where('users', ['email' => $failed['email']])->num_rows());
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }
        $this->assertFalse($db->trans_active());
        $this->assertSame(0, $db->get_where('users', ['email' => $failed['email']])->num_rows());
    }

    /** @return array<string, mixed> */
    private function adminData(): array
    {
        $email = 'admin-atomic-' . bin2hex(random_bytes(4)) . '@example.org';
        $this->ownedIdentities[$email] = DB_SLUG_ADMIN;
        return [
            'first_name' => 'Atomic admin',
            'last_name' => 'Synthetic',
            'email' => $email,
            'notes' => 'atomic-notes',
            'settings' => [
                'username' => 'admin-' . bin2hex(random_bytes(4)),
                'password' => 'synthetic-admin-password',
            ],
        ];
    }

    private function failingModel(): FailingAdminsModel
    {
        return new FailingAdminsModel();
    }

    /** @return array{user: array<string,mixed>, settings: array<string,mixed>} */
    private function snapshot(int $adminId): array
    {
        $db = get_instance()->db;
        return [
            'user' => $db->get_where('users', ['id' => $adminId])->row_array(),
            'settings' => $db->get_where('user_settings', ['id_users' => $adminId])->row_array(),
        ];
    }
}

final class FailingAdminsModel extends Admins_model
{
    public function set_settings(int $admin_id, array $settings): void
    {
        parent::set_settings($admin_id, $settings);
        throw new RuntimeException('Injected admin settings failure.');
    }
}

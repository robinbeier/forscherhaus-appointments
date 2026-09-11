<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use RuntimeException;
use Tests\TestCase;
use Users_model;

final class UsersModelAtomicWriteTest extends TestCase
{
    private Users_model $usersModel;

    /** @var list<int> */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        get_instance()->load->model('users_model');
        $this->usersModel = get_instance()->users_model;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUserIds as $userId) {
            get_instance()->db->delete('user_settings', ['id_users' => $userId]);
            get_instance()->db->delete('users', ['id' => $userId]);
        }

        parent::tearDown();
    }

    public function test_insert_and_update_commit_users_and_settings_together(): void
    {
        $user = $this->userData();
        $userId = $this->usersModel->save($user);
        $this->createdUserIds[] = $userId;

        $this->assertFalse(get_instance()->db->trans_active());
        $stored = $this->usersModel->find($userId);
        $this->assertSame('Atomic', $stored['first_name']);
        $this->assertStringStartsWith('atomic-insert-', $stored['settings']['username']);

        $stored['first_name'] = 'Updated';
        $stored['settings'] = ['username' => 'atomic-update'];
        $this->usersModel->save($stored);

        $updated = $this->usersModel->find($userId);
        $this->assertSame('Updated', $updated['first_name']);
        $this->assertSame('atomic-update', $updated['settings']['username']);
    }

    public function test_insert_rolls_back_user_and_settings_when_settings_mutation_fails(): void
    {
        $model = $this->failingModel();
        $user = $this->userData();
        $user['email'] = 'users-atomic-insert-failure-' . bin2hex(random_bytes(4)) . '@example.org';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Injected settings failure.');

        try {
            $model->save($user);
        } finally {
            $this->assertFalse(get_instance()->db->trans_active());
            $db = get_instance()->db;
            $this->assertSame(0, $db->get_where('users', ['email' => $user['email']])->num_rows());
            $this->assertNotNull($model->lastUserId);
            $this->assertSame(0, $db->get_where('user_settings', ['id_users' => $model->lastUserId])->num_rows());
        }
    }

    public function test_update_rolls_back_user_and_settings_when_settings_mutation_fails(): void
    {
        $userId = $this->usersModel->save($this->userData());
        $this->createdUserIds[] = $userId;
        $db = get_instance()->db;
        $beforeUser = $db->get_where('users', ['id' => $userId])->row_array();
        $beforeSettings = $db->get_where('user_settings', ['id_users' => $userId])->row_array();
        $before = $this->usersModel->find($userId);

        $model = $this->failingModel();
        $changed = $before;
        $changed['first_name'] = 'Should Roll Back';
        $changed['settings'] = ['username' => 'should-roll-back'];

        try {
            $model->save($changed);
            $this->fail('Expected injected settings failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected settings failure.', $exception->getMessage());
        }

        $this->assertFalse(get_instance()->db->trans_active());
        $this->assertSame($beforeUser, $db->get_where('users', ['id' => $userId])->row_array());
        $this->assertSame($beforeSettings, $db->get_where('user_settings', ['id_users' => $userId])->row_array());
    }

    public function test_existing_outer_transaction_remains_owned_by_caller(): void
    {
        $user = $this->userData();
        $db = get_instance()->db;

        $userId = 0;
        $transactionOpen = $db->trans_begin();
        $this->assertTrue($transactionOpen);
        try {
            $userId = $this->usersModel->save($user);
            $this->assertTrue($db->trans_active());
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
        }

        $this->assertFalse($db->trans_active());
        $this->assertSame(0, $db->get_where('users', ['id' => $userId])->num_rows());
        $this->assertSame(0, $db->get_where('user_settings', ['id_users' => $userId])->num_rows());
    }

    public function test_failure_in_existing_outer_transaction_propagates_without_inner_rollback(): void
    {
        $model = $this->failingModel();
        $db = get_instance()->db;
        $user = $this->userData();

        $transactionOpen = $db->trans_begin();
        $this->assertTrue($transactionOpen);
        try {
            $model->save($user);
            $this->fail('Expected injected settings failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected settings failure.', $exception->getMessage());
            $this->assertTrue($db->trans_active());
        } finally {
            if ($transactionOpen && $db->trans_active()) {
                $db->trans_rollback();
            }
        }

        $this->assertFalse($db->trans_active());
        $this->assertSame(0, $db->get_where('users', ['email' => $user['email']])->num_rows());
        $this->assertNotNull($model->lastUserId);
        $this->assertSame(0, $db->get_where('user_settings', ['id_users' => $model->lastUserId])->num_rows());
    }

    /** @return array<string, mixed> */
    private function userData(): array
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();

        return [
            'first_name' => 'Atomic',
            'last_name' => 'User-' . bin2hex(random_bytes(4)),
            'email' => 'users-atomic-' . bin2hex(random_bytes(4)) . '@example.org',
            'id_roles' => (int) $role['id'],
            'settings' => [
                'username' => 'atomic-insert-' . bin2hex(random_bytes(4)),
                'password' => 'synthetic-password',
            ],
        ];
    }

    private function failingModel(): FailingUsersModel
    {
        return new FailingUsersModel();
    }
}

final class FailingUsersModel extends Users_model
{
    public ?int $lastUserId = null;

    protected function set_settings(int $user_id, array $settings): void
    {
        $this->lastUserId = $user_id;
        parent::set_settings($user_id, $settings);
        throw new RuntimeException('Injected settings failure.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Admins_model;
use InvalidArgumentException;
use Providers_model;
use RuntimeException;
use Secretaries_model;
use Tests\TestCase;

require_once APPPATH . 'models/Admins_model.php';
require_once APPPATH . 'models/Providers_model.php';
require_once APPPATH . 'models/Secretaries_model.php';

final class StaffModelDeleteTest extends TestCase
{
    private Admins_model $admins;
    private Secretaries_model $secretaries;
    private Providers_model $providers;

    /** @var list<int> */
    private array $ownedIds = [];
    /** @var array<string, string> */
    private array $ownedEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        get_instance()->load->model('admins_model');
        get_instance()->load->model('secretaries_model');
        get_instance()->load->model('providers_model');
        $this->admins = get_instance()->admins_model;
        $this->secretaries = get_instance()->secretaries_model;
        $this->providers = get_instance()->providers_model;
    }

    protected function tearDown(): void
    {
        $db = get_instance()->db;
        foreach ($this->ownedEmails as $email => $role) {
            $row = $db
                ->select('users.id')
                ->from('users')
                ->join('roles', 'roles.id = users.id_roles')
                ->where(['users.email' => $email, 'roles.slug' => $role])
                ->get()
                ->row_array();
            if ($row) {
                $this->ownedIds[] = (int) $row['id'];
            }
        }
        foreach (array_unique($this->ownedIds) as $id) {
            $db->delete('secretaries_providers', ['id_users_secretary' => $id]);
            $db->delete('user_settings', ['id_users' => $id]);
            $db->delete('users', ['id' => $id]);
        }
        parent::tearDown();
    }

    public function test_admin_and_secretary_delete_remove_owned_rows_and_relations(): void
    {
        $admin = $this->adminData('delete-admin');
        $adminId = $this->admins->save($admin);
        $this->ownedIds[] = $adminId;
        $providerId = $this->providerData();
        $secretary = $this->secretaryData('delete-secretary');
        $secretary['providers'] = [$providerId];
        $secretaryId = $this->secretaries->save($secretary);
        $this->ownedIds[] = $secretaryId;
        $db = get_instance()->db;

        $this->admins->delete($adminId);
        self::assertFalse($db->trans_active());
        $this->secretaries->delete($secretaryId);
        self::assertSame(0, $db->get_where('users', ['id' => $adminId])->num_rows());
        self::assertSame(0, $db->get_where('user_settings', ['id_users' => $adminId])->num_rows());
        self::assertSame(0, $db->get_where('users', ['id' => $secretaryId])->num_rows());
        self::assertSame(0, $db->get_where('user_settings', ['id_users' => $secretaryId])->num_rows());
        self::assertSame(
            0,
            $db->get_where('secretaries_providers', ['id_users_secretary' => $secretaryId])->num_rows(),
        );
    }

    public function test_wrong_role_and_missing_targets_are_rejected_without_owned_state_change(): void
    {
        $admin = $this->adminData('wrong-admin');
        $adminId = $this->admins->save($admin);
        $this->ownedIds[] = $adminId;
        $secretary = $this->secretaryData('wrong-secretary');
        $secretaryId = $this->secretaries->save($secretary);
        $this->ownedIds[] = $secretaryId;
        $providerId = $this->providerData();
        $db = get_instance()->db;
        $beforeAdmin = $this->snapshot($adminId);
        $beforeSecretary = $this->snapshot($secretaryId);

        foreach (
            [
                [$this->admins, $secretaryId],
                [$this->secretaries, $adminId],
                [$this->admins, PHP_INT_MAX],
                [$this->secretaries, PHP_INT_MAX],
            ]
            as [$model, $id]
        ) {
            try {
                $model->delete((int) $id);
                $this->fail('Expected role-scoped delete rejection.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('deletion target was not found', $exception->getMessage());
                self::assertFalse($db->trans_active());
                // Expected validation and role-scope failures.
            }
        }
        self::assertSame($beforeAdmin, $this->snapshot($adminId));
        self::assertSame($beforeSecretary, $this->snapshot($secretaryId));
        self::assertSame(0, $db->get_where('secretaries_providers', ['id_users_provider' => $providerId])->num_rows());
    }

    public function test_admin_delete_retains_outer_transaction_ownership_and_rollback_restores_row(): void
    {
        $admin = $this->adminData('outer-admin');
        $adminId = $this->admins->save($admin);
        $this->ownedIds[] = $adminId;
        $db = get_instance()->db;
        self::assertTrue($db->trans_begin());
        try {
            $this->admins->delete($adminId);
            self::assertTrue($db->trans_active());
            self::assertSame(0, $db->get_where('users', ['id' => $adminId])->num_rows());
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }
        self::assertFalse($db->trans_active());
        self::assertSame(1, $db->get_where('users', ['id' => $adminId])->num_rows());
        self::assertSame(1, $db->get_where('user_settings', ['id_users' => $adminId])->num_rows());
    }

    public function test_last_admin_is_rejected_without_delete_or_commit(): void
    {
        $database = new class {
            public bool $active = false;
            public bool $rolledBack = false;
            public function dbprefix(string $table): string
            {
                return 'ea_' . $table;
            }
            public function trans_active(): bool
            {
                return $this->active;
            }
            public function trans_begin(): bool
            {
                $this->active = true;
                return true;
            }
            public function trans_rollback(): bool
            {
                $this->active = false;
                $this->rolledBack = true;
                return true;
            }
            public function query(string $sql, array $bindings): object
            {
                self::checkLock($sql);
                return new class {
                    public function result_array(): array
                    {
                        return [['id' => 42]];
                    }
                };
            }
            private static function checkLock(string $sql): void
            {
                if (!str_contains($sql, 'FOR UPDATE')) {
                    throw new \LogicException('Expected current locking read.');
                }
            }
            public function delete(string $table, array $where): bool
            {
                throw new \LogicException('Last admin must not be deleted.');
            }
            public function trans_commit(): bool
            {
                throw new \LogicException('Rejected delete must not commit.');
            }
        };
        $model = new class ($database) extends Admins_model {
            public function __construct(public object $db) {}
            public function get_admin_role_id(): int
            {
                return 1;
            }
        };
        try {
            $model->delete(42);
            self::fail('Expected last-admin rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('requires at least one admin user', $exception->getMessage());
        }
        self::assertTrue($database->rolledBack);
        self::assertFalse($database->active);
    }

    /** @return array<string, mixed> */
    private function adminData(string $case): array
    {
        $email = $case . '-' . bin2hex(random_bytes(4)) . '@synthetic.invalid';
        $this->ownedEmails[$email] = DB_SLUG_ADMIN;
        return [
            'first_name' => 'Synthetic',
            'last_name' => $case,
            'email' => $email,
            'notes' => $case,
            'settings' => [
                'username' => $case . '-' . bin2hex(random_bytes(4)),
                'password' => 'synthetic-admin-password',
            ],
        ];
    }

    private function providerData(): int
    {
        $email = 'provider-' . bin2hex(random_bytes(4)) . '@synthetic.invalid';
        $this->ownedEmails[$email] = DB_SLUG_PROVIDER;
        $id = $this->providers->save([
            'services' => [],
            'first_name' => 'Synthetic',
            'last_name' => 'Provider',
            'email' => $email,
            'settings' => [
                'username' => 'provider-' . bin2hex(random_bytes(4)),
                'password' => 'synthetic-provider-password',
            ],
        ]);
        $this->ownedIds[] = $id;
        return $id;
    }

    private function secretaryData(string $case): array
    {
        $email = $case . '-' . bin2hex(random_bytes(4)) . '@synthetic.invalid';
        $this->ownedEmails[$email] = DB_SLUG_SECRETARY;
        return [
            'first_name' => 'Synthetic',
            'last_name' => $case,
            'email' => $email,
            'notes' => $case,
            'providers' => [],
            'settings' => [
                'username' => $case . '-' . bin2hex(random_bytes(4)),
                'password' => 'synthetic-secretary-password',
            ],
        ];
    }

    /** @return array{user: array<string,mixed>, settings: array<string,mixed>, relations: array<int,array<string,mixed>>} */
    private function snapshot(int $id): array
    {
        $db = get_instance()->db;
        return [
            'user' => $db->get_where('users', ['id' => $id])->row_array(),
            'settings' => $db->get_where('user_settings', ['id_users' => $id])->row_array(),
            'relations' => $db->get_where('secretaries_providers', ['id_users_secretary' => $id])->result_array(),
        ];
    }
}

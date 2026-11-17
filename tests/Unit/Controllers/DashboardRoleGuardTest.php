<?php

namespace Tests\Unit\Controllers;

use Dashboard;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'controllers/Dashboard.php';

final class DashboardRoleGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        session(['role_slug' => null, 'user_id' => null]);
    }

    public function testDashboardRoleGuardLocksActorBeforePostWorkAndCommits(): void
    {
        session(['role_slug' => DB_SLUG_PROVIDER, 'user_id' => 41]);
        $db = new DashboardRoleGuardFakeDatabase(DB_SLUG_PROVIDER);
        $controller = new class extends Dashboard {
            public function __construct() {}

            public function guard(string $role): ?bool
            {
                return $this->beginDashboardRoleGuard($role);
            }

            public function commit(?bool $guard): void
            {
                $this->commitDashboardRoleGuard($guard);
            }
        };
        $controller->db = $db;

        self::assertTrue($controller->guard(DB_SLUG_PROVIDER));
        self::assertCount(2, $db->queries);
        self::assertStringContainsString('FOR UPDATE', $db->queries[0]);
        self::assertTrue($db->transactionOpen);

        $controller->commit(true);
        self::assertFalse($db->transactionOpen);
        self::assertSame(1, $db->commits);
    }

    public function testDashboardRoleGuardRollsBackWhenStoredRoleChanged(): void
    {
        session(['role_slug' => DB_SLUG_PROVIDER, 'user_id' => 41]);
        $db = new DashboardRoleGuardFakeDatabase(DB_SLUG_CUSTOMER);
        $controller = new class extends Dashboard {
            public function __construct() {}

            public function guard(string $role): ?bool
            {
                return $this->beginDashboardRoleGuard($role);
            }
        };
        $controller->db = $db;

        self::assertFalse($controller->guard(DB_SLUG_PROVIDER));
        self::assertFalse($db->transactionOpen);
        self::assertSame(1, $db->rollbacks);
    }

    public function testDashboardRoleGuardRollsBackWhenLockQueryFails(): void
    {
        session(['role_slug' => DB_SLUG_PROVIDER, 'user_id' => 41]);
        $db = new DashboardRoleGuardFakeDatabase(DB_SLUG_PROVIDER, true);
        $controller = new class extends Dashboard {
            public function __construct() {}

            public function guard(string $role): ?bool
            {
                return $this->beginDashboardRoleGuard($role);
            }
        };
        $controller->db = $db;

        $this->expectException(\RuntimeException::class);
        try {
            $controller->guard(DB_SLUG_PROVIDER);
        } finally {
            self::assertFalse($db->transactionOpen);
            self::assertSame(1, $db->rollbacks);
        }
    }
}

final class DashboardRoleGuardFakeDatabase
{
    public bool $transactionOpen = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    /** @var list<string> */
    public array $queries = [];

    public function __construct(private readonly string $role, private readonly bool $queryFails = false)
    {
    }

    public function trans_begin(): bool
    {
        $this->transactionOpen = true;
        return true;
    }

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function query(string $sql, array $bindings): object
    {
        $this->queries[] = $sql;
        if ($this->queryFails) {
            throw new \RuntimeException('synthetic query failure');
        }

        $role = $this->role;
        $actorQuery = count($this->queries) === 1;

        return new class ($role, $actorQuery) {
            public function __construct(private readonly string $role, private readonly bool $actorQuery) {}

            public function row_array(): array
            {
                return $this->actorQuery ? ['id_roles' => 1] : ['slug' => $this->role];
            }
        };
    }

    public function trans_commit(): bool
    {
        $this->transactionOpen = false;
        $this->commits++;
        return true;
    }

    public function trans_rollback(): bool
    {
        $this->transactionOpen = false;
        $this->rollbacks++;
        return true;
    }
}

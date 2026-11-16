<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'helpers/permission_helper.php';

/** Regression coverage for transaction-scoped actor permission checks. */
final class PermissionHelperTransactionTest extends TestCase
{
    public function testLocksUsersThenRoleAndAcceptsTheRequestedBit(): void
    {
        $database = new PermissionHelperTransactionDbDouble(
            ['id_roles' => 7],
            ['users' => PRIV_VIEW | PRIV_ADD | PRIV_EDIT | PRIV_DELETE],
        );
        $CI = get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            self::assertTrue(can_with_locked_role('edit', PRIV_USERS, 42));
        } finally {
            $CI->db = $originalDb;
        }

        self::assertCount(2, $database->queries);
        self::assertStringContainsString('FROM `ea_users`', $database->queries[0]);
        self::assertStringContainsString('FOR UPDATE', $database->queries[0]);
        self::assertStringContainsString('FROM `ea_roles`', $database->queries[1]);
        self::assertStringContainsString('FOR UPDATE', $database->queries[1]);
    }

    public function testMissingOrRevokedRolePermissionFailsClosed(): void
    {
        foreach ([[], ['users' => PRIV_VIEW]] as $role) {
            $database = new PermissionHelperTransactionDbDouble(['id_roles' => 7], $role);
            $CI = get_instance();
            $originalDb = $CI->db;
            $CI->db = $database;

            try {
                self::assertFalse(can_with_locked_role('edit', PRIV_USERS, 42));
            } finally {
                $CI->db = $originalDb;
            }
        }
    }

    public function testOutsideTransactionDoesNotClaimAProtectedPermission(): void
    {
        $database = new PermissionHelperTransactionDbDouble(['id_roles' => 7], ['users' => PRIV_EDIT], false);
        $CI = get_instance();
        $originalDb = $CI->db;
        $CI->db = $database;

        try {
            self::assertFalse(can_with_locked_role('edit', PRIV_USERS, 42));
        } finally {
            $CI->db = $originalDb;
        }

        self::assertSame([], $database->queries);
    }
}

final class PermissionHelperTransactionDbDouble
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct(
        private readonly array $actor,
        private readonly array $role,
        private readonly bool $transactionActive = true,
    ) {}

    public function trans_active(): bool
    {
        return $this->transactionActive;
    }

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function query(string $sql, array $bindings = []): PermissionHelperTransactionQueryDouble
    {
        $this->queries[] = $sql;
        return new PermissionHelperTransactionQueryDouble(str_contains($sql, 'ea_users') ? $this->actor : $this->role);
    }
}

final class PermissionHelperTransactionQueryDouble
{
    public function __construct(private readonly array $row) {}

    public function row_array(): array
    {
        return $this->row;
    }
}

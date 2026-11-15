<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Account;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once APPPATH . 'controllers/Account.php';

/** Regression coverage for the transaction-time self-account permission check. */
final class AccountPermissionRevocationTest extends TestCase
{
    public function testCurrentRolePermissionIsReadUnderActorLock(): void
    {
        $database = new AccountPermissionRevocationDatabase(PRIV_EDIT);
        $controller = (new \ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $controller->db = $database;

        $method = new ReflectionMethod(Account::class, 'hasCurrentAccountPermission');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, 42));
        self::assertCount(2, $database->queries);
        self::assertStringContainsString('`ea_users`', $database->queries[0]);
        self::assertStringContainsString('`id`, `id_roles`', $database->queries[0]);
        self::assertStringContainsString('FOR UPDATE', $database->queries[0]);
        self::assertStringContainsString('`ea_roles`', $database->queries[1]);
        self::assertStringContainsString('`user_settings`', $database->queries[1]);
        self::assertStringContainsString('FOR UPDATE', $database->queries[1]);
        self::assertSame([42], $database->bindings[0]);
        self::assertSame([7], $database->bindings[1]);
    }

    public function testRevokedRoleAndUnknownRoleCannotAuthorizeAccountSave(): void
    {
        $method = new ReflectionMethod(Account::class, 'hasCurrentAccountPermission');
        $method->setAccessible(true);

        foreach ([0, PRIV_EDIT | 16] as $mask) {
            $database = new AccountPermissionRevocationDatabase($mask);
            $controller = (new \ReflectionClass(Account::class))->newInstanceWithoutConstructor();
            $controller->db = $database;

            self::assertFalse($method->invoke($controller, 42), 'mask ' . $mask);
        }
    }

    public function testSessionStateIsUpdatedOnlyAfterCommit(): void
    {
        $source = file_get_contents(APPPATH . 'controllers/Account.php');
        self::assertIsString($source);
        $commit = strpos($source, 'trans_commit()');
        $session = preg_match("/session\\(\\[\\s*'user_email'/", $source, $matches, PREG_OFFSET_CAPTURE)
            ? $matches[0][1]
            : false;
        self::assertNotFalse($commit);
        self::assertNotFalse($session);
        self::assertGreaterThan($commit, $session);
    }
}

final class AccountPermissionRevocationDatabase
{
    /** @var list<string> */
    public array $queries = [];

    /** @var list<list<int>> */
    public array $bindings = [];

    public function __construct(private int $userSettingsMask) {}

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function query(string $sql, array $bindings): AccountPermissionRevocationQuery
    {
        $this->queries[] = $sql;
        $this->bindings[] = array_map('intval', $bindings);

        if (str_contains($sql, '`ea_users`')) {
            return new AccountPermissionRevocationQuery(['id' => 42, 'id_roles' => 7]);
        }

        return new AccountPermissionRevocationQuery([
            'user_settings' => $this->userSettingsMask,
        ]);
    }
}

final class AccountPermissionRevocationQuery
{
    public function __construct(private array $row) {}

    public function row_array(): array
    {
        return $this->row;
    }
}

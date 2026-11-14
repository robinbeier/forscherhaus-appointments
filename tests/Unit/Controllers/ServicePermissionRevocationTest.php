<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Service_categories;
use Services;

require_once APPPATH . 'controllers/Services.php';
require_once APPPATH . 'controllers/Service_categories.php';

/**
 * Regression coverage for the actor/role lock used by all six backoffice
 * service and service-category write paths.
 */
final class ServicePermissionRevocationTest extends TestCase
{
    #[DataProvider('controllerProvider')]
    public function testEachWritePermissionRechecksTheLockedRole(
        string $controllerClass,
        string $action,
        int $permission,
    ): void {
        $database = new ServicePermissionRevocationDatabase($permission);
        $controller = (new \ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
        $controller->db = $database;

        $method = new ReflectionMethod($controllerClass, 'hasCurrentServicePermission');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, 42, $action));
        self::assertSame(['actor_lock', 'role_lock'], $database->events);
        self::assertCount(2, $database->queries);
        self::assertStringContainsString('FOR UPDATE', $database->queries[0]);
        self::assertStringContainsString('FOR UPDATE', $database->queries[1]);
        self::assertSame(42, $database->bindings[0][0]);
        self::assertSame(7, $database->bindings[1][0]);
    }

    #[DataProvider('controllerProvider')]
    public function testRevokedRoleIsRejectedAfterActorAndRoleLocks(
        string $controllerClass,
        string $action,
        int $permission,
    ): void {
        $database = new ServicePermissionRevocationDatabase(0);
        $controller = (new \ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
        $controller->db = $database;

        $method = new ReflectionMethod($controllerClass, 'hasCurrentServicePermission');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($controller, 42, $action));
        self::assertSame(['actor_lock', 'role_lock'], $database->events);
    }

    public function testInvalidRoleMaskCannotAuthorizeServiceWrite(): void
    {
        foreach ([Services::class, Service_categories::class] as $controllerClass) {
            foreach ([PRIV_EDIT | 16, -1] as $mask) {
                $database = new ServicePermissionRevocationDatabase($mask);
                $controller = (new \ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
                $controller->db = $database;
                $method = new ReflectionMethod($controllerClass, 'hasCurrentServicePermission');
                $method->setAccessible(true);
                self::assertFalse($method->invoke($controller, 42, 'edit'));
            }
        }
    }

    /** @return array<string, array{class-string, string, int}> */
    public static function controllerProvider(): array
    {
        return [
            'service create' => [Services::class, 'add', PRIV_ADD],
            'service update' => [Services::class, 'edit', PRIV_EDIT],
            'service delete' => [Services::class, 'delete', PRIV_DELETE],
            'category create' => [Service_categories::class, 'add', PRIV_ADD],
            'category update' => [Service_categories::class, 'edit', PRIV_EDIT],
            'category delete' => [Service_categories::class, 'delete', PRIV_DELETE],
        ];
    }
}

final class ServicePermissionRevocationDatabase
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var list<list<int>> */
    public array $bindings = [];

    public function __construct(private int $servicesMask) {}

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function query(string $sql, array $bindings): ServicePermissionRevocationQuery
    {
        $this->queries[] = $sql;
        $this->bindings[] = array_map('intval', $bindings);
        if (str_contains($sql, '`ea_users`')) {
            $this->events[] = 'actor_lock';
            return new ServicePermissionRevocationQuery(['id_roles' => 7]);
        }

        $this->events[] = 'role_lock';
        return new ServicePermissionRevocationQuery(['services' => $this->servicesMask]);
    }
}

final class ServicePermissionRevocationQuery
{
    public function __construct(private array $row) {}

    public function row_array(): array
    {
        return $this->row;
    }
}

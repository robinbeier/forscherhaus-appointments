<?php

namespace Tests\Unit\Libraries;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Reschedule_authority;

require_once APPPATH . 'libraries/Reschedule_authority.php';

final class RescheduleAuthorityLockOrderTest extends TestCase
{
    public function testNormalCreationLocksExistingCustomerAndProviderInAscendingUserIdOrder(): void
    {
        $reflection = new ReflectionClass(Reschedule_authority::class);
        $authority = $reflection->newInstanceWithoutConstructor();
        $database = new RescheduleAuthorityLockOrderFakeDatabase();
        $databaseProperty = new ReflectionProperty(Reschedule_authority::class, 'db');
        $databaseProperty->setValue($authority, $database);

        $authority->lockCreationTarget(20, 30, 10);

        $this->assertNotEmpty($database->queries);
        $this->assertStringContainsString('FROM `ea_users`', $database->queries[0]['sql']);
        $this->assertSame([10, 20], $database->queries[0]['bindings']);
    }
}

final class RescheduleAuthorityLockOrderFakeDatabase
{
    /**
     * @var array<int, array{sql:string,bindings:array<int, mixed>}>
     */
    public array $queries = [];

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): RescheduleAuthorityLockOrderFakeQuery
    {
        $this->queries[] = [
            'sql' => $sql,
            'bindings' => $bindings,
        ];

        $rowCount = 0;

        if (
            str_contains($sql, 'FROM `ea_users`') ||
            str_contains($sql, 'FROM `ea_services`') ||
            str_contains($sql, 'FROM `ea_user_settings`')
        ) {
            $rowCount = count($bindings);
        }

        return new RescheduleAuthorityLockOrderFakeQuery($rowCount);
    }

    /**
     * @param array<string, int> $where
     */
    public function get_where(string $table, array $where): RescheduleAuthorityLockOrderFakeQuery
    {
        return new RescheduleAuthorityLockOrderFakeQuery(1);
    }
}

final class RescheduleAuthorityLockOrderFakeQuery
{
    public function __construct(private readonly int $rowCount) {}

    public function num_rows(): int
    {
        return $this->rowCount;
    }
}

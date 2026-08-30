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
        $database = new RescheduleAuthorityLockOrderFakeDatabase();
        $authority = $this->createAuthority($database);

        $authority->lockCreationTarget(20, 30, 10);

        $this->assertNotEmpty($database->queries);
        $this->assertStringContainsString('FROM `ea_users`', $database->queries[0]['sql']);
        $this->assertSame([10, 20], $database->queries[0]['bindings']);
    }

    public function testNormalCreationIdentityLockIsOpaqueAndCaseCanonical(): void
    {
        $database = new RescheduleAuthorityLockOrderFakeDatabase();
        $authority = $this->createAuthority($database);

        $firstLock = $authority->acquireCreationIdentityLock(' Case-Test@Example.Invalid ');
        $authority->releaseCreationIdentityLock($firstLock);
        $secondLock = $authority->acquireCreationIdentityLock('case-test@example.invalid');
        $authority->releaseCreationIdentityLock($secondLock);

        $this->assertNotNull($firstLock);
        $this->assertSame($firstLock, $secondLock);
        $this->assertMatchesRegularExpression('/^ea:public-create:[a-f0-9]{40}$/D', $firstLock);
        $this->assertLessThanOrEqual(64, strlen($firstLock));
        $this->assertStringNotContainsString('case-test', $firstLock);

        $acquireQueries = array_values(
            array_filter($database->queries, static fn(array $query): bool => str_contains($query['sql'], 'GET_LOCK')),
        );

        $this->assertCount(2, $acquireQueries);
        $this->assertSame(10, $acquireQueries[0]['bindings'][1]);
    }

    private function createAuthority(RescheduleAuthorityLockOrderFakeDatabase $database): Reschedule_authority
    {
        $reflection = new ReflectionClass(Reschedule_authority::class);
        $authority = $reflection->newInstanceWithoutConstructor();
        $databaseProperty = new ReflectionProperty(Reschedule_authority::class, 'db');
        $databaseProperty->setValue($authority, $database);

        return $authority;
    }
}

final class RescheduleAuthorityLockOrderFakeDatabase
{
    public string $database = 'unit-test';

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

        $row = [];

        if (str_contains($sql, 'GET_LOCK')) {
            $row = ['acquired' => 1];
        } elseif (str_contains($sql, 'RELEASE_LOCK')) {
            $row = ['released' => 1];
        }

        return new RescheduleAuthorityLockOrderFakeQuery($rowCount, $row);
    }

    /**
     * @param array<string, int> $where
     */
    public function get_where(string $table, array $where): RescheduleAuthorityLockOrderFakeQuery
    {
        return new RescheduleAuthorityLockOrderFakeQuery(1, []);
    }
}

final class RescheduleAuthorityLockOrderFakeQuery
{
    /**
     * @param array<string, mixed> $row
     */
    public function __construct(private readonly int $rowCount, private readonly array $row) {}

    public function num_rows(): int
    {
        return $this->rowCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function row_array(): array
    {
        return $this->row;
    }
}

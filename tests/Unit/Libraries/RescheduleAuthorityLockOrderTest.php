<?php

namespace Tests\Unit\Libraries;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RescheduleAuthorityClaim;
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

    public function testVerifyLockedStateLocksAllParentsBeforeAppointment(): void
    {
        $database = new RescheduleAuthorityLockOrderFakeDatabase();
        $database->appointmentSnapshot = [
            'id' => 99,
            'id_users_customer' => 20,
            'id_users_provider' => 30,
            'id_services' => 40,
            'is_unavailability' => 0,
        ];
        $database->lockedAppointment = $database->appointmentSnapshot;
        $authority = $this->createAuthority($database);

        try {
            $authority->verifyLockedState(new RescheduleAuthorityClaim(99, 999, 'snapshot'), 10, 50);
            $this->fail('Expected the mismatched claim to be rejected.');
        } catch (\RescheduleAuthorityException $exception) {
            $this->assertSame('canonical-identity-mismatch', $exception->getMessage());
        }

        $this->assertCount(6, $database->queries);
        foreach ($database->queries as $query) {
            $this->assertStringContainsString('FOR UPDATE', $query['sql']);
        }
        $this->assertStringContainsString('FROM `ea_users`', $database->queries[0]['sql']);
        $this->assertSame([10, 20, 30], $database->queries[0]['bindings']);
        $this->assertStringContainsString('FROM `ea_services`', $database->queries[1]['sql']);
        $this->assertSame([40, 50], $database->queries[1]['bindings']);
        $this->assertStringContainsString('FROM `ea_user_settings`', $database->queries[2]['sql']);
        $this->assertSame([10, 30], $database->queries[2]['bindings']);
        $this->assertStringContainsString('FROM `ea_services_providers`', $database->queries[3]['sql']);
        $this->assertSame([10], $database->queries[3]['bindings']);
        $this->assertStringContainsString('FROM `ea_services_providers`', $database->queries[4]['sql']);
        $this->assertSame([30], $database->queries[4]['bindings']);
        $this->assertStringContainsString('FROM `ea_appointments`', $database->queries[5]['sql']);
        $this->assertSame([99], $database->queries[5]['bindings']);
    }

    public function testVerifyLockedStateRejectsStructuralSnapshotDriftAfterParentLocks(): void
    {
        $database = new RescheduleAuthorityLockOrderFakeDatabase();
        $database->appointmentSnapshot = [
            'id' => 99,
            'id_users_customer' => 20,
            'id_users_provider' => 30,
            'id_services' => 40,
            'is_unavailability' => 0,
        ];
        $database->lockedAppointment = [...$database->appointmentSnapshot, 'id_users_provider' => 31];
        $authority = $this->createAuthority($database);

        $this->expectException(\RescheduleAuthorityException::class);
        $this->expectExceptionMessage('canonical-identity-mismatch');
        $authority->verifyLockedState(new RescheduleAuthorityClaim(99, 20, 'snapshot'), 30, 40);
    }

    public function testVerifyLockedStateUsesCurrentReadsForAllPostLockValidation(): void
    {
        $database = new RescheduleAuthorityCurrentReadFakeDatabase();
        $authority = $this->createAuthority($database);
        $reflection = new ReflectionClass(Reschedule_authority::class);
        $loadState = $reflection->getMethod('loadStateFromAppointment');
        $currentState = $loadState->invoke($authority, $database->lockedAppointment, true);
        $database->queries = [];

        $state = $authority->verifyLockedState(
            new RescheduleAuthorityClaim(99, 20, $currentState->snapshotDigest),
            30,
            40,
        );

        $this->assertSame($currentState->snapshotDigest, $state->snapshotDigest);
        $appointmentQueryIndex = null;
        foreach ($database->queries as $index => $query) {
            if (str_contains($query['sql'], 'FROM `ea_appointments`')) {
                $appointmentQueryIndex = $index;
                break;
            }
        }

        $this->assertNotNull($appointmentQueryIndex);
        foreach (array_slice($database->queries, $appointmentQueryIndex + 1) as $query) {
            $this->assertStringContainsString('FOR UPDATE', $query['sql']);
        }
    }

    public function testProviderOverlapUsesStrictBoundsForBackToBackAppointments(): void
    {
        $database = new RescheduleAuthorityLockOrderFakeDatabase();
        $authority = $this->createAuthority($database);

        $authority->providerHasOverlap(30, '2035-01-01 10:00:00', '2035-01-01 10:25:00', null);

        $query = end($database->queries);
        $this->assertIsArray($query);
        $this->assertStringContainsString('`start_datetime` < ?', $query['sql']);
        $this->assertStringContainsString('`end_datetime` > ?', $query['sql']);
    }

    private function createAuthority(object $database): Reschedule_authority
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

    /** @var array<string, mixed> */
    public array $appointmentSnapshot = [];

    /** @var array<string, mixed> */
    public array $lockedAppointment = [];

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

        if (str_contains($sql, 'FROM `ea_appointments`')) {
            $row = $this->lockedAppointment;
            $rowCount = $row === [] ? 0 : 1;
        } elseif (str_contains($sql, 'GET_LOCK')) {
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
        if ($table === 'appointments') {
            return new RescheduleAuthorityLockOrderFakeQuery(
                $this->appointmentSnapshot === [] ? 0 : 1,
                $this->appointmentSnapshot,
            );
        }

        return new RescheduleAuthorityLockOrderFakeQuery(1, []);
    }
}

final class RescheduleAuthorityCurrentReadFakeDatabase
{
    public string $database = 'unit-test';

    /** @var array<int, array{sql:string,bindings:array<int, mixed>}> */
    public array $queries = [];

    /** @var array<string, mixed> */
    public array $appointmentSnapshot = [
        'id' => 99,
        'id_users_customer' => 20,
        'id_users_provider' => 30,
        'id_services' => 40,
        'is_unavailability' => 0,
    ];

    /** @var array<string, mixed> */
    public array $lockedAppointment = [
        'id' => 99,
        'hash' => 'current-hash',
        'start_datetime' => '2035-01-01 09:00:00',
        'end_datetime' => '2035-01-01 09:25:00',
        'location' => 'current',
        'notes' => 'current',
        'color' => '#ffffff',
        'status' => 'booked',
        'is_unavailability' => 0,
        'id_users_provider' => 30,
        'id_users_customer' => 20,
        'id_services' => 40,
        'update_datetime' => '2035-01-01 08:00:00',
    ];

    /** @var array<string, array<int, array<string, mixed>> */
    private array $currentRows = [
        'users' => [
            20 => ['id' => 20, 'first_name' => 'Current customer', 'id_roles' => 3, 'update_datetime' => '2'],
            30 => ['id' => 30, 'first_name' => 'Current provider', 'id_roles' => 2, 'update_datetime' => '2'],
        ],
        'services' => [40 => ['id' => 40, 'name' => 'Current service', 'duration' => 25, 'update_datetime' => '2']],
        'user_settings' => [30 => ['id_users' => 30, 'working_plan' => '{}', 'working_plan_exceptions' => '{}']],
    ];

    /** @var array<string, array<int, array<string, mixed>> */
    private array $staleRows = [
        'users' => [20 => ['id' => 20, 'first_name' => 'Stale customer']],
        'services' => [40 => ['id' => 40, 'name' => 'Stale service']],
        'user_settings' => [30 => ['id_users' => 30, 'working_plan' => '{"stale":true}']],
    ];

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): RescheduleAuthorityCurrentReadFakeQuery
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

        if (str_contains($sql, 'FROM `ea_appointments`')) {
            return new RescheduleAuthorityCurrentReadFakeQuery(1, $this->lockedAppointment);
        }

        if (str_contains($sql, 'FROM `ea_services_providers`')) {
            $rows = [['id_services' => 40]];
            return new RescheduleAuthorityCurrentReadFakeQuery(count($rows), [], $rows);
        }

        foreach (array_keys($this->currentRows) as $table) {
            if (!str_contains($sql, 'FROM `ea_' . $table . '`')) {
                continue;
            }

            $rows = $this->currentRows[$table];
            if (str_contains($sql, 'WHERE `id` = ?') || str_contains($sql, 'WHERE `id_users` = ?')) {
                $row = $rows[(int) ($bindings[0] ?? 0)] ?? [];
                return new RescheduleAuthorityCurrentReadFakeQuery($row === [] ? 0 : 1, $row);
            }

            return new RescheduleAuthorityCurrentReadFakeQuery(count($bindings), []);
        }

        return new RescheduleAuthorityCurrentReadFakeQuery(0, []);
    }

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    /**
     * @param array<string, int> $where
     */
    public function get_where(string $table, array $where): RescheduleAuthorityCurrentReadFakeQuery
    {
        if ($table === 'appointments') {
            return new RescheduleAuthorityCurrentReadFakeQuery(1, $this->appointmentSnapshot);
        }

        $row = $this->staleRows[$table][(int) array_values($where)[0]] ?? [];
        return new RescheduleAuthorityCurrentReadFakeQuery($row === [] ? 0 : 1, $row);
    }
}

final class RescheduleAuthorityCurrentReadFakeQuery
{
    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(
        private readonly int $rowCount,
        private readonly array $row,
        private readonly array $rows = [],
    ) {}

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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function result_array(): array
    {
        return $this->rows;
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

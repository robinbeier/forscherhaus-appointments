<?php

namespace Tests\Unit\Libraries;

use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame(
            $firstLock,
            Reschedule_authority::creationIdentityLockName('case-test@example.invalid', 'unit-test'),
        );

        $acquireQueries = array_values(
            array_filter($database->queries, static fn(array $query): bool => str_contains($query['sql'], 'GET_LOCK')),
        );

        $this->assertCount(2, $acquireQueries);
        $this->assertSame(10, $acquireQueries[0]['bindings'][1]);
    }

    public function testIssueRejectsStalePresentedAppointmentHashBeforeMintingAuthority(): void
    {
        $database = new RescheduleAuthorityCurrentReadFakeDatabase();
        $database->appointmentSnapshot['hash'] = 'current-hash';
        $database->lockedAppointment['hash'] = 'current-hash';
        $authority = $this->createAuthority($database);
        session([
            'public_reschedule_authority_context' => str_repeat('c', 43),
        ]);

        try {
            $authority->issue(99, 'stale-hash');
            $this->fail('Expected a stale appointment capability to be rejected.');
        } catch (\RescheduleAuthorityException $exception) {
            $this->assertSame('stale-appointment-capability', $exception->getMessage());
        } finally {
            session([
                'public_reschedule_authority_context' => null,
                'public_reschedule_authority' => null,
            ]);
        }

        $this->assertSame([], $database->writes);
    }

    public function testPrivacyTokenWriterRunsWithLockedCustomerBeforeAuthorityCommit(): void
    {
        $database = new RescheduleAuthorityCurrentReadFakeDatabase();
        $authority = $this->createAuthority($database);
        session([
            'public_reschedule_authority_context' => str_repeat('c', 43),
        ]);

        try {
            $token = $authority->issueWithPrivacyToken(99, 'current-hash', static function (int $customerId) use (
                $database,
            ): string {
                self::assertTrue($database->transactionActive);
                $database->events[] = 'token:' . $customerId;

                return 'privacy-token';
            });

            $this->assertSame('privacy-token', $token);
            $this->assertSame(['begin', 'insert', 'token:20', 'commit'], $database->events);
        } finally {
            session([
                'public_reschedule_authority_context' => null,
                'public_reschedule_authority' => null,
            ]);
        }
    }

    public function testStaleAppointmentCannotInvokePrivacyTokenWriter(): void
    {
        $database = new RescheduleAuthorityCurrentReadFakeDatabase();
        $database->lockedAppointment['hash'] = 'rotated-hash';
        $authority = $this->createAuthority($database);
        session([
            'public_reschedule_authority_context' => str_repeat('c', 43),
        ]);

        try {
            $authority->issueWithPrivacyToken(99, 'current-hash', static function (): string {
                throw new \LogicException('writer must not run for stale authority');
            });
            $this->fail('Expected a stale appointment capability to be rejected.');
        } catch (\RescheduleAuthorityException $exception) {
            $this->assertSame('stale-appointment-capability', $exception->getMessage());
        } finally {
            session([
                'public_reschedule_authority_context' => null,
                'public_reschedule_authority' => null,
            ]);
        }

        $this->assertSame([], $database->writes);
    }

    public function testPrivacyTokenWriterFailureCommitsAuthorityButReturnsFalse(): void
    {
        $database = new RescheduleAuthorityCurrentReadFakeDatabase();
        $authority = $this->createAuthority($database);
        session([
            'public_reschedule_authority_context' => str_repeat('c', 43),
        ]);

        try {
            $token = $authority->issueWithPrivacyToken(99, 'current-hash', static fn(int $customerId): bool => false);

            $this->assertFalse($token);
            $this->assertSame(['begin', 'insert', 'commit'], $database->events);
        } finally {
            session([
                'public_reschedule_authority_context' => null,
                'public_reschedule_authority' => null,
            ]);
        }
    }

    public function testPrivacyTokenWriterExceptionRollsBackAuthority(): void
    {
        $database = new RescheduleAuthorityCurrentReadFakeDatabase();
        $authority = $this->createAuthority($database);
        session([
            'public_reschedule_authority_context' => str_repeat('c', 43),
        ]);

        try {
            try {
                $authority->issueWithPrivacyToken(99, 'current-hash', static function (): string {
                    throw new \RuntimeException('synthetic callback failure');
                });
                $this->fail('Expected the token writer failure to abort authority issuance.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('synthetic callback failure', $exception->getMessage());
            }

            $this->assertSame(['begin', 'insert', 'rollback'], $database->events);
        } finally {
            session([
                'public_reschedule_authority_context' => null,
                'public_reschedule_authority' => null,
            ]);
        }
    }

    public function testProjectionRollbackWriterRunsWhenAuthorityCommitFails(): void
    {
        $database = new RescheduleAuthorityCurrentReadFakeDatabase();
        $database->failCommit = true;
        $authority = $this->createAuthority($database);
        $rollbackCalled = false;
        session([
            'public_reschedule_authority_context' => str_repeat('c', 43),
        ]);

        try {
            $authority->issueWithPrivacyTokenAndProjection(
                99,
                'current-hash',
                static fn(int $customerId): string => 'privacy-token',
                static function () use (&$rollbackCalled): void {
                    $rollbackCalled = true;
                },
            );
            $this->fail('Expected the authority commit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Could not persist reschedule authority.', $exception->getMessage());
        } finally {
            session([
                'public_reschedule_authority_context' => null,
                'public_reschedule_authority' => null,
            ]);
        }

        $this->assertTrue($rollbackCalled);
        $this->assertSame(['begin', 'insert', 'commit', 'rollback'], $database->events);
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
        $this->assertStringContainsString('FOR UPDATE', $query['sql']);
    }

    #[DataProvider('customerOverlapIntervals')]
    public function testCustomerOverlapUsesStrictIntervalIntersection(
        string $existing_start,
        string $existing_end,
        string $requested_start,
        string $requested_end,
        bool $expected,
    ): void {
        $database = new RescheduleAuthorityCustomerOverlapFakeDatabase([
            [
                'id' => 501,
                'id_users_customer' => 20,
                'start_datetime' => $existing_start,
                'end_datetime' => $existing_end,
            ],
        ]);
        $authority = $this->createAuthority($database);

        $this->assertSame($expected, $authority->customerHasOverlap(20, $requested_start, $requested_end, 999));

        $query = $database->queries[0] ?? null;
        $this->assertIsArray($query);
        $this->assertStringContainsString('`start_datetime` < ?', $query['sql']);
        $this->assertStringContainsString('`end_datetime` > ?', $query['sql']);
        $this->assertStringContainsString('FOR UPDATE', $query['sql']);
        $this->assertSame([20, $requested_end, $requested_start, 999], $query['bindings']);
    }

    /**
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function customerOverlapIntervals(): array
    {
        return [
            'existing interval overlaps on the left' => [
                '2035-01-01 09:00:00',
                '2035-01-01 10:00:00',
                '2035-01-01 09:30:00',
                '2035-01-01 10:30:00',
                true,
            ],
            'existing interval overlaps on the right' => [
                '2035-01-01 10:00:00',
                '2035-01-01 11:00:00',
                '2035-01-01 09:30:00',
                '2035-01-01 10:30:00',
                true,
            ],
            'requested interval contains existing interval' => [
                '2035-01-01 10:00:00',
                '2035-01-01 11:00:00',
                '2035-01-01 09:00:00',
                '2035-01-01 12:00:00',
                true,
            ],
            'existing interval contains requested interval' => [
                '2035-01-01 09:00:00',
                '2035-01-01 12:00:00',
                '2035-01-01 10:00:00',
                '2035-01-01 11:00:00',
                true,
            ],
            'back to back interval before request is allowed' => [
                '2035-01-01 08:00:00',
                '2035-01-01 09:00:00',
                '2035-01-01 09:00:00',
                '2035-01-01 10:00:00',
                false,
            ],
            'back to back interval after request is allowed' => [
                '2035-01-01 10:00:00',
                '2035-01-01 11:00:00',
                '2035-01-01 09:00:00',
                '2035-01-01 10:00:00',
                false,
            ],
        ];
    }

    public function testAdvanceCutoffUsesProviderTimezoneWhenPhpDefaultTimezoneDiffers(): void
    {
        $authority = $this->createAuthority(new RescheduleAuthorityLockOrderFakeDatabase());
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');

        try {
            $now = new \DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('UTC'));

            $this->assertTrue(
                $authority->isAdvanceCutoffReached('2026-01-15 09:30:00', 60, 'Asia/Tokyo', $now),
                'The provider-local 09:30 slot is inside the one-hour cutoff at 09:00 Tokyo.',
            );
            $this->assertFalse(
                $authority->isAdvanceCutoffReached('2026-01-15 10:00:00', 60, 'Asia/Tokyo', $now),
                'The provider-local 10:00 slot is exactly at the cutoff and remains allowed.',
            );
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testAdvanceCutoffFailsClosedForInvalidTimezoneTimeOrTimeout(): void
    {
        $authority = $this->createAuthority(new RescheduleAuthorityLockOrderFakeDatabase());
        $now = new \DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('UTC'));

        $this->assertTrue($authority->isAdvanceCutoffReached('2026-01-15 10:00:00', 60, 'Invalid/Timezone', $now));
        $this->assertTrue($authority->isAdvanceCutoffReached('2026-03-29 02:30:00', 60, 'Europe/Berlin', $now));
        $this->assertTrue($authority->isAdvanceCutoffReached('2026-01-15 10:00:00', -1, 'Europe/Berlin', $now));
    }

    public function testAdvanceCutoffFailsClosedForAmbiguousProviderFallBackTime(): void
    {
        $authority = $this->createAuthority(new RescheduleAuthorityLockOrderFakeDatabase());
        $now = new \DateTimeImmutable('2026-10-24 10:00:00', new \DateTimeZone('Europe/Berlin'));

        $this->assertTrue(
            $authority->isAdvanceCutoffReached('2026-10-25 02:30:00', 60, 'Europe/Berlin', $now),
        );
    }

    public function testAdvanceCutoffMatchesProviderWallClockAcrossDstTransition(): void
    {
        $authority = $this->createAuthority(new RescheduleAuthorityLockOrderFakeDatabase());
        $timezone = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('2026-03-29 01:30:00', $timezone);

        $this->assertFalse(
            $authority->isAdvanceCutoffReached('2026-03-29 04:00:00', 120, $timezone->getName(), $now),
            'Availability advances 120 provider-local wall-clock minutes to 03:30, so 04:00 must remain usable.',
        );
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

    /** @var list<string> */
    public array $writes = [];

    /** @var list<string> */
    public array $events = [];

    public bool $transactionActive = false;

    public bool $failCommit = false;

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
            30 => [
                'id' => 30,
                'first_name' => 'Current provider',
                'id_roles' => 2,
                'timezone' => 'UTC',
                'update_datetime' => '2',
            ],
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

    public function trans_begin(): bool
    {
        $this->transactionActive = true;
        $this->events[] = 'begin';

        return true;
    }

    public function trans_commit(): bool
    {
        $this->transactionActive = false;
        $this->events[] = 'commit';

        return !$this->failCommit;
    }

    public function trans_rollback(): bool
    {
        $this->transactionActive = false;
        $this->events[] = 'rollback';

        return true;
    }

    public function delete(string $table, array $where = []): bool
    {
        $this->writes[] = 'delete';
        return true;
    }

    public function insert(string $table, array $data): bool
    {
        $this->writes[] = 'insert';
        $this->events[] = 'insert';
        return true;
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

final class RescheduleAuthorityCustomerOverlapFakeDatabase
{
    public string $database = 'unit-test';

    /** @var list<array{sql:string,bindings:array<int, mixed>}> */
    public array $queries = [];

    /**
     * @param array<int, array{id:int, id_users_customer:int, start_datetime:string, end_datetime:string}> $rows
     */
    public function __construct(private readonly array $rows) {}

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): RescheduleAuthorityCustomerOverlapFakeQuery
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

        $customer_id = (int) ($bindings[0] ?? 0);
        $requested_end = (string) ($bindings[1] ?? '');
        $requested_start = (string) ($bindings[2] ?? '');
        $exclude_id = isset($bindings[3]) ? (int) $bindings[3] : null;
        $matches = array_filter(
            $this->rows,
            static fn(array $row): bool => (int) $row['id_users_customer'] === $customer_id &&
                ($exclude_id === null || (int) $row['id'] !== $exclude_id) &&
                $row['start_datetime'] < $requested_end &&
                $row['end_datetime'] > $requested_start,
        );

        return new RescheduleAuthorityCustomerOverlapFakeQuery(count($matches));
    }
}

final class RescheduleAuthorityCustomerOverlapFakeQuery
{
    public function __construct(private readonly int $rowCount) {}

    public function num_rows(): int
    {
        return $this->rowCount;
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

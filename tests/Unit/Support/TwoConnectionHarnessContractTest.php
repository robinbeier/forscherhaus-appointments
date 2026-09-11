<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Integration\Support\TwoConnectionHarness;

require_once APPPATH . '../tests/Integration/Support/TwoConnectionHarness.php';

final class TwoConnectionHarnessContractTest extends TestCase
{
    public function testRunsWithIndependentConnectionsAndRestoresDatabase(): void
    {
        $CI = &\get_instance();
        $original = $CI->db;
        $owned = [new HarnessFakeDatabase(), new HarnessFakeDatabase()];
        $connections = $owned;
        $harness = new TwoConnectionHarness(static function () use (&$connections): object {
            return array_shift($connections);
        });

        $evidence = $harness->run(function (object $primary, object $secondary, callable $checkpoint) use (
            $harness,
        ): string {
            self::assertNotSame($primary, $secondary);
            $harness->markBranch('benign-branch');
            $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
            $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
            $checkpoint(TwoConnectionHarness::AFTER_PARENT_LOCKS);
            $checkpoint(TwoConnectionHarness::BEFORE_WRITE);
            $checkpoint(TwoConnectionHarness::BEFORE_COMMIT);

            return 'completed';
        }, 'benign-branch');

        self::assertSame('completed', $evidence['result']);
        self::assertSame('benign-branch', $evidence['branch']);
        self::assertSame($harness->trace(), $evidence['trace']);
        $harness->assertBranch('benign-branch');
        $harness->assertTrace([
            TwoConnectionHarness::BEFORE_AUTHORITY,
            TwoConnectionHarness::AFTER_AUTHORITY,
            TwoConnectionHarness::AFTER_PARENT_LOCKS,
            TwoConnectionHarness::BEFORE_WRITE,
            TwoConnectionHarness::BEFORE_COMMIT,
        ]);
        self::assertSame($original, $CI->db);
        self::assertSame(['rolled_back', 'closed'], $owned[0]->events);
        self::assertSame(['rolled_back', 'closed'], $owned[1]->events);
    }

    public function testRejectsOutOfOrderCheckpointsAndStillCleansUp(): void
    {
        $CI = &\get_instance();
        $original = $CI->db;
        $owned = [new HarnessFakeDatabase(), new HarnessFakeDatabase()];
        $connections = $owned;
        $harness = new TwoConnectionHarness(static function () use (&$connections): object {
            return array_shift($connections);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Out-of-order checkpoint');
        try {
            $harness->run(function (object $primary, object $secondary, callable $checkpoint) use ($harness): void {
                $harness->markBranch('default');
                $checkpoint(TwoConnectionHarness::AFTER_AUTHORITY);
            });
        } finally {
            self::assertSame($original, $CI->db);
            self::assertSame(['rolled_back', 'closed'], $owned[0]->events);
            self::assertSame(['rolled_back', 'closed'], $owned[1]->events);
        }
    }

    public function testRejectsUnknownCheckpoint(): void
    {
        $owned = [new HarnessFakeDatabase(), new HarnessFakeDatabase()];
        $connections = $owned;
        $harness = new TwoConnectionHarness(static function () use (&$connections): object {
            return array_shift($connections);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown checkpoint');
        $harness->run(function (object $primary, object $secondary, callable $checkpoint) use ($harness): void {
            $harness->markBranch('default');
            $checkpoint('not-a-checkpoint');
        });
    }

    public function testRollsBackAndClosesBothConnectionsWhenScenarioThrows(): void
    {
        $owned = [new HarnessFakeDatabase(), new HarnessFakeDatabase()];
        $connections = $owned;
        $harness = new TwoConnectionHarness(static function () use (&$connections): object {
            return array_shift($connections);
        });

        try {
            $harness->run(function (object $primary, object $secondary, callable $checkpoint) use ($harness): void {
                $harness->markBranch('failure-branch');
                $checkpoint(TwoConnectionHarness::BEFORE_AUTHORITY);
                throw new RuntimeException('scenario failed');
            }, 'failure-branch');
            self::fail('Expected scenario exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('scenario failed', $exception->getMessage());
        }

        $harness->assertBranch('failure-branch');
        $harness->assertTrace([TwoConnectionHarness::BEFORE_AUTHORITY]);
        self::assertSame(['rolled_back', 'closed'], $owned[0]->events);
        self::assertSame(['rolled_back', 'closed'], $owned[1]->events);
    }

    public function testSecondFactoryFailureCleansUpFirstConnection(): void
    {
        $first = new HarnessFakeDatabase();
        $calls = 0;
        $harness = new TwoConnectionHarness(function () use (&$calls, $first): object {
            $calls++;
            if ($calls === 1) {
                return $first;
            }

            throw new RuntimeException('factory failed');
        });

        $this->expectExceptionMessage('factory failed');
        try {
            $harness->run(static function (): void {});
        } finally {
            self::assertSame(['rolled_back', 'closed'], $first->events);
        }
    }

    public function testRejectsFactoryReturningOriginalDatabaseWithoutClosingIt(): void
    {
        $CI = &\get_instance();
        $original = $CI->db;
        $calls = 0;
        $harness = new TwoConnectionHarness(function () use (&$calls, $original): object {
            $calls++;

            return $original;
        });

        $this->expectExceptionMessage('current CI database connection');
        try {
            $harness->run(static function (): void {});
        } finally {
            self::assertSame($original, $CI->db);
        }
    }

    public function testRejectsSharedUnderlyingConnectionHandle(): void
    {
        $handle = new \stdClass();
        $owned = [new HarnessFakeDatabaseWithHandle($handle), new HarnessFakeDatabaseWithHandle($handle)];
        $connections = $owned;
        $harness = new TwoConnectionHarness(static function () use (&$connections): object {
            return array_shift($connections);
        });

        $this->expectExceptionMessage('independent connection handles');
        try {
            $harness->run(function () use ($harness): void {
                $harness->markBranch('default');
            });
        } finally {
            self::assertSame(['rolled_back', 'closed'], $owned[0]->events);
            self::assertSame([], $owned[1]->events);
        }
    }

    public function testCleanupContinuesWhenFirstConnectionRollbackFails(): void
    {
        $first = new HarnessFakeDatabase(1, true);
        $second = new HarnessFakeDatabase();
        $owned = [$first, $second];
        $connections = $owned;
        $harness = new TwoConnectionHarness(static function () use (&$connections): object {
            return array_shift($connections);
        });

        $this->expectExceptionMessage('rollback failed');
        try {
            $harness->run(function () use ($harness): void {
                $harness->markBranch('default');
            });
        } finally {
            self::assertSame(['rollback failed', 'closed'], $first->events);
            self::assertSame(['rolled_back', 'closed'], $second->events);
        }
    }

    public function testRejectsMissingBranchMarker(): void
    {
        $owned = [new HarnessFakeDatabase(), new HarnessFakeDatabase()];
        $connections = $owned;
        $harness = new TwoConnectionHarness(static function () use (&$connections): object {
            return array_shift($connections);
        });

        $this->expectExceptionMessage('did not mark the expected branch');
        $harness->run(static function (): void {}, 'required-branch');
    }

    public function testRollsBackNestedTransactionLevels(): void
    {
        $owned = [new HarnessFakeDatabase(3), new HarnessFakeDatabase()];
        $connections = $owned;
        $harness = new TwoConnectionHarness(static function () use (&$connections): object {
            return array_shift($connections);
        });

        $harness->run(function () use ($harness): void {
            $harness->markBranch('default');
        });

        self::assertSame(['rolled_back', 'rolled_back', 'rolled_back', 'closed'], $owned[0]->events);
    }
}

class HarnessFakeDatabase
{
    /** @var list<string> */
    public array $events = [];

    private int $activeLevels;

    public function __construct(int $activeLevels = 1, private bool $throwOnRollback = false)
    {
        $this->activeLevels = $activeLevels;
    }

    public function trans_active(): bool
    {
        return $this->activeLevels > 0;
    }

    public function trans_rollback(): void
    {
        if ($this->throwOnRollback) {
            $this->events[] = 'rollback failed';
            throw new RuntimeException('rollback failed');
        }

        $this->events[] = 'rolled_back';
        $this->activeLevels--;
    }

    public function close(): void
    {
        $this->events[] = 'closed';
    }
}

final class HarnessFakeDatabaseWithHandle extends HarnessFakeDatabase
{
    public function __construct(public object $conn_id)
    {
        parent::__construct();
    }
}

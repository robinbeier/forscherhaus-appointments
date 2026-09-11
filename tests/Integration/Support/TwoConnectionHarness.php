<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use RuntimeException;
use Throwable;

/**
 * Runs a deterministic two-connection scenario against the current CI instance.
 *
 * This is a coordination primitive for integration tests. Checkpoints are
 * deliberately sequential callbacks; they do not provide concurrency by
 * themselves.
 */
final class TwoConnectionHarness
{
    public const BEFORE_AUTHORITY = 'before_authority';
    public const AFTER_AUTHORITY = 'after_authority';
    public const AFTER_PARENT_LOCKS = 'after_parent_locks';
    public const BEFORE_WRITE = 'before_write';
    public const BEFORE_COMMIT = 'before_commit';

    /** @var list<string> */
    private const CHECKPOINTS = [
        self::BEFORE_AUTHORITY,
        self::AFTER_AUTHORITY,
        self::AFTER_PARENT_LOCKS,
        self::BEFORE_WRITE,
        self::BEFORE_COMMIT,
    ];

    private const MAX_ROLLBACK_LEVELS = 32;

    /** @var callable():object */
    private $connectionFactory;

    /** @var list<string> */
    private array $lastTrace = [];

    private string $lastBranch = '';

    private string $expectedBranch = '';

    /**
     * @param (callable():object)|null $connectionFactory
     */
    public function __construct(?callable $connectionFactory = null)
    {
        $this->connectionFactory =
            $connectionFactory ??
            static function (): object {
                $CI = &\get_instance();

                return $CI->load->database('', true);
            };
    }

    /**
     * @param callable(object, object, callable(string):void):mixed $scenario
     * @return array{branch:string,result:mixed,trace:list<string>}
     */
    public function run(callable $scenario, string $branch = 'default'): array
    {
        if ($branch === '') {
            throw new RuntimeException('Two-connection branch labels must not be empty.');
        }

        $CI = &\get_instance();
        $originalDatabase = $CI->db;
        $connections = [];
        $this->lastTrace = [];
        $this->lastBranch = '';
        $this->expectedBranch = $branch;
        $scenarioResult = null;
        $scenarioThrowable = null;

        try {
            for ($index = 0; $index < 2; $index++) {
                $connection = ($this->connectionFactory)();
                if ($connection === $originalDatabase) {
                    throw new RuntimeException(
                        'The two-connection harness cannot own the current CI database connection.',
                    );
                }

                if ($connections !== [] && $connection === $connections[0]) {
                    throw new RuntimeException('The two-connection harness requires independent database objects.');
                }

                $handle = $this->connectionHandle($connection);
                if ($handle !== null) {
                    if ($handle === $this->connectionHandle($originalDatabase)) {
                        throw new RuntimeException(
                            'The two-connection harness cannot own the current CI connection handle.',
                        );
                    }

                    if ($connections !== [] && $handle === $this->connectionHandle($connections[0])) {
                        throw new RuntimeException(
                            'The two-connection harness requires independent connection handles.',
                        );
                    }
                }

                $connections[] = $connection;
            }

            $checkpoint = function (string $name): void {
                $expected = self::CHECKPOINTS[count($this->lastTrace)] ?? null;
                if ($expected === null || !in_array($name, self::CHECKPOINTS, true)) {
                    throw new RuntimeException(
                        sprintf('Unknown checkpoint %s; trace is [%s].', $name, implode(', ', $this->lastTrace)),
                    );
                }

                if ($name !== $expected) {
                    throw new RuntimeException(
                        sprintf(
                            'Out-of-order checkpoint %s; expected %s after [%s].',
                            $name,
                            $expected,
                            implode(', ', $this->lastTrace),
                        ),
                    );
                }

                $this->lastTrace[] = $name;
            };

            $CI->db = $connections[0];

            $scenarioResult = $scenario($connections[0], $connections[1], $checkpoint);
            if ($this->lastBranch === '') {
                throw new RuntimeException(sprintf('Scenario did not mark the expected branch %s.', $branch));
            }
        } catch (Throwable $throwable) {
            $scenarioThrowable = $throwable;
        } finally {
            $cleanupThrowable = null;
            foreach ($connections as $connection) {
                try {
                    try {
                        if (method_exists($connection, 'trans_active')) {
                            for (
                                $level = 0;
                                $level < self::MAX_ROLLBACK_LEVELS && $connection->trans_active();
                                $level++
                            ) {
                                $connection->trans_rollback();
                            }

                            if ($connection->trans_active()) {
                                throw new RuntimeException('Could not roll back all active transaction levels.');
                            }
                        }
                    } finally {
                        if (method_exists($connection, 'close')) {
                            $connection->close();
                        }
                    }
                } catch (Throwable $throwable) {
                    $cleanupThrowable ??= $throwable;
                }
            }
            try {
                $CI->db = $originalDatabase;
            } catch (Throwable $throwable) {
                $cleanupThrowable ??= $throwable;
            }
            if ($scenarioThrowable === null && $cleanupThrowable !== null) {
                $scenarioThrowable = $cleanupThrowable;
            }
        }

        if ($scenarioThrowable !== null) {
            throw $scenarioThrowable;
        }

        return [
            'branch' => $this->lastBranch,
            'result' => $scenarioResult,
            'trace' => $this->lastTrace,
        ];
    }

    /** @return list<string> */
    public function trace(): array
    {
        return $this->lastTrace;
    }

    public function branch(): string
    {
        return $this->lastBranch;
    }

    public function markBranch(string $branch): void
    {
        if ($branch === '' || $branch !== $this->expectedBranch) {
            throw new RuntimeException(
                sprintf('Unexpected branch marker %s; expected %s.', $branch, $this->expectedBranch),
            );
        }

        $this->lastBranch = $branch;
    }

    /**
     * @param list<string> $expected
     */
    public function assertTrace(array $expected): void
    {
        if ($this->lastTrace !== $expected) {
            throw new RuntimeException(
                sprintf(
                    'Checkpoint trace mismatch for branch %s: expected [%s], got [%s].',
                    $this->lastBranch,
                    implode(', ', $expected),
                    implode(', ', $this->lastTrace),
                ),
            );
        }
    }

    public function assertBranch(string $expected): void
    {
        if ($this->lastBranch !== $expected) {
            throw new RuntimeException(
                sprintf('Branch evidence mismatch: expected %s, got %s.', $expected, $this->lastBranch),
            );
        }
    }

    private function connectionHandle(object $connection): ?string
    {
        if (!property_exists($connection, 'conn_id') || !is_object($connection->conn_id)) {
            return null;
        }

        if (property_exists($connection->conn_id, 'thread_id')) {
            return get_class($connection->conn_id) . '#thread-' . (string) $connection->conn_id->thread_id;
        }

        return get_class($connection->conn_id) . '#' . spl_object_id($connection->conn_id);
    }
}

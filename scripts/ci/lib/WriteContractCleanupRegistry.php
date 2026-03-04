<?php

declare(strict_types=1);

namespace CiContract;

use Throwable;

final class WriteContractCleanupRegistry
{
    /**
     * @var array<int, array{resource:string,id:int|string}>
     */
    private array $created = [];

    /**
     * @var array<int, array{resource:string,id:int|string,cleanup:callable}>
     */
    private array $cleanupHandlers = [];

    /**
     * @var array<int, callable>
     */
    private array $fallbackSweepers = [];

    /**
     * @var array<int, array{resource:string,id:int|string}>
     */
    private array $deleted = [];

    /**
     * @var array<int, array{resource:string,id:int|string,error:string,exception:string}>
     */
    private array $failures = [];

    public function register(string $resource, int|string $id, callable $cleanup): void
    {
        if ($resource === '' || (is_string($id) && trim($id) === '')) {
            return;
        }

        $entry = [
            'resource' => $resource,
            'id' => $id,
        ];

        $this->created[] = $entry;
        $this->cleanupHandlers[] = array_merge($entry, ['cleanup' => $cleanup]);
    }

    public function addFallbackSweeper(callable $sweeper): void
    {
        $this->fallbackSweepers[] = $sweeper;
    }

    /**
     * @return array{
     *   created:array<int, array{resource:string,id:int|string}>,
     *   deleted:array<int, array{resource:string,id:int|string}>,
     *   failures:array<int, array{resource:string,id:int|string,error:string,exception:string}>
     * }
     */
    public function cleanup(): array
    {
        for ($index = count($this->cleanupHandlers) - 1; $index >= 0; $index--) {
            $entry = $this->cleanupHandlers[$index];

            try {
                $result = $entry['cleanup']($entry['id'], $entry['resource']);

                if ($result === false) {
                    $this->recordFailure(
                        $entry['resource'],
                        $entry['id'],
                        'Cleanup callback returned false.',
                        'CleanupReturnFalse',
                    );

                    continue;
                }

                $this->recordDeleted($entry['resource'], $entry['id']);
            } catch (Throwable $e) {
                $this->recordFailure($entry['resource'], $entry['id'], $e->getMessage(), get_class($e));
            }
        }

        foreach ($this->fallbackSweepers as $sweeper) {
            try {
                $deletedEntries = $sweeper();

                if (!is_array($deletedEntries)) {
                    continue;
                }

                foreach ($deletedEntries as $deleted) {
                    if (!is_array($deleted)) {
                        continue;
                    }

                    $resource = $deleted['resource'] ?? null;
                    $id = $deleted['id'] ?? null;

                    if (!is_string($resource) || $resource === '') {
                        continue;
                    }

                    if (!is_int($id) && !is_string($id)) {
                        continue;
                    }

                    if (is_string($id) && trim($id) === '') {
                        continue;
                    }

                    $this->recordDeleted($resource, $id);
                }
            } catch (Throwable $e) {
                $this->recordFailure('fallback_sweeper', 'n/a', $e->getMessage(), get_class($e));
            }
        }

        return [
            'created' => $this->created,
            'deleted' => $this->deleted,
            'failures' => $this->failures,
        ];
    }

    /**
     * @return array<int, array{resource:string,id:int|string}>
     */
    public function createdResources(): array
    {
        return $this->created;
    }

    /**
     * @return array<int, array{resource:string,id:int|string}>
     */
    public function deletedResources(): array
    {
        return $this->deleted;
    }

    /**
     * @return array<int, array{resource:string,id:int|string,error:string,exception:string}>
     */
    public function cleanupFailures(): array
    {
        return $this->failures;
    }

    private function recordDeleted(string $resource, int|string $id): void
    {
        $key = $resource . ':' . (string) $id;

        foreach ($this->deleted as $entry) {
            $entryKey = $entry['resource'] . ':' . (string) $entry['id'];
            if ($entryKey === $key) {
                return;
            }
        }

        $this->deleted[] = [
            'resource' => $resource,
            'id' => $id,
        ];
    }

    private function recordFailure(string $resource, int|string $id, string $error, string $exception): void
    {
        $this->failures[] = [
            'resource' => $resource,
            'id' => $id,
            'error' => $error,
            'exception' => $exception,
        ];
    }
}

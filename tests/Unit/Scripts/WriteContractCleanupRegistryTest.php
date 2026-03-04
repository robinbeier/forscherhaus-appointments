<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CiContract\WriteContractCleanupRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ci/lib/WriteContractCleanupRegistry.php';

final class WriteContractCleanupRegistryTest extends TestCase
{
    public function testCleanupRunsInReverseRegistrationOrderAndTracksDeletedResources(): void
    {
        $registry = new WriteContractCleanupRegistry();
        $executionOrder = [];

        $registry->register('customers', 101, static function (int|string $id, string $resource) use (
            &$executionOrder,
        ): bool {
            $executionOrder[] = $resource . ':' . $id;

            return true;
        });

        $registry->register('appointments', 202, static function (int|string $id, string $resource) use (
            &$executionOrder,
        ): bool {
            $executionOrder[] = $resource . ':' . $id;

            return true;
        });

        $summary = $registry->cleanup();

        self::assertSame(['appointments:202', 'customers:101'], $executionOrder);
        self::assertCount(2, $summary['created']);
        self::assertCount(2, $summary['deleted']);
        self::assertCount(0, $summary['failures']);
    }

    public function testCleanupCollectsFailuresFromCallbacksAndFalseReturns(): void
    {
        $registry = new WriteContractCleanupRegistry();

        $registry->register('appointments', 303, static function (): bool {
            return false;
        });

        $registry->register('customers', 404, static function (): bool {
            throw new RuntimeException('delete failed');
        });

        $summary = $registry->cleanup();

        self::assertCount(2, $summary['created']);
        self::assertCount(0, $summary['deleted']);
        self::assertCount(2, $summary['failures']);
        self::assertSame('customers', $summary['failures'][0]['resource']);
        self::assertSame('appointments', $summary['failures'][1]['resource']);
    }

    public function testCleanupExecutesFallbackSweeperAndDeduplicatesDeletedResources(): void
    {
        $registry = new WriteContractCleanupRegistry();

        $registry->register('appointments', 505, static function (): bool {
            return true;
        });

        $registry->addFallbackSweeper(static function (): array {
            return [['resource' => 'appointments', 'id' => 505], ['resource' => 'customers', 'id' => 606]];
        });

        $summary = $registry->cleanup();

        self::assertCount(2, $summary['deleted']);
        self::assertSame('appointments', $summary['deleted'][0]['resource']);
        self::assertSame(505, $summary['deleted'][0]['id']);
        self::assertSame('customers', $summary['deleted'][1]['resource']);
        self::assertSame(606, $summary['deleted'][1]['id']);
    }
}

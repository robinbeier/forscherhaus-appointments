<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ci/lib/LdapFixtureCleanup.php';

final class DashboardIntegrationSmokeCleanupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!defined('INTEGRATION_SMOKE_EXIT_SUCCESS')) {
            define('INTEGRATION_SMOKE_EXIT_SUCCESS', 0);
        }
        if (!defined('INTEGRATION_SMOKE_EXIT_RUNTIME_ERROR')) {
            define('INTEGRATION_SMOKE_EXIT_RUNTIME_ERROR', 2);
        }
    }

    public function testCleanupFailureTurnsAnOtherwiseSuccessfulSmokeIntoRuntimeFailure(): void
    {
        $cleanup = $this->cleanupFunction();
        $exitCode = 0;
        $failure = null;

        $cleanup(
            static function (): void {
                throw new RuntimeException('synthetic cleanup failure');
            },
            $exitCode,
            $failure,
        );

        self::assertSame(2, $exitCode);
        self::assertSame('LDAP guardrail fixture cleanup failed.', $failure['message']);
        self::assertSame(RuntimeException::class, $failure['exception']);
    }

    public function testCleanupFailureIsRetainedAlongsideAnExistingSmokeFailure(): void
    {
        $cleanup = $this->cleanupFunction();
        $exitCode = 1;
        $failure = ['message' => 'synthetic check failure', 'exception' => 'GateAssertionException'];

        $cleanup(
            static function (): void {
                throw new RuntimeException('synthetic cleanup failure');
            },
            $exitCode,
            $failure,
        );

        self::assertSame(1, $exitCode);
        self::assertSame('synthetic check failure', $failure['message']);
        self::assertSame('LDAP guardrail fixture cleanup failed.', $failure['cleanup_error']['message']);
    }

    private function cleanupFunction(): callable
    {
        return Closure::fromCallable('dashboardIntegrationSmokeRunLdapFixtureCleanup');
    }
}

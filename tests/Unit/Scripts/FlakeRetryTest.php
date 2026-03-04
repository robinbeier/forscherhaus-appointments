<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CiContract\ContractAssertionException;
use CiContract\FlakeRetry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ci/lib/OpenApiContractValidator.php';
require_once __DIR__ . '/../../../scripts/ci/lib/FlakeRetry.php';

final class FlakeRetryTest extends TestCase
{
    public function testDecideAllowsRetryForTransientRuntimeError(): void
    {
        $decision = FlakeRetry::decide(
            new RuntimeException('HTTP 503 Service Unavailable'),
            1,
            1,
            static fn(\Throwable $e): bool => $e instanceof ContractAssertionException,
        );

        self::assertTrue($decision['retry']);
        self::assertSame('transient_runtime', $decision['classification']);
    }

    public function testDecideStopsRetryAfterRetryBudgetIsExhausted(): void
    {
        $decision = FlakeRetry::decide(
            new RuntimeException('Gateway timeout while waiting for mysql'),
            2,
            1,
            static fn(\Throwable $e): bool => $e instanceof ContractAssertionException,
        );

        self::assertFalse($decision['retry']);
        self::assertSame('transient_runtime_retry_exhausted', $decision['classification']);
    }

    public function testDecideNeverRetriesContractMismatch(): void
    {
        $decision = FlakeRetry::decide(
            new ContractAssertionException('Expected status 201, got 500'),
            1,
            1,
            static fn(\Throwable $e): bool => $e instanceof ContractAssertionException,
        );

        self::assertFalse($decision['retry']);
        self::assertSame('contract_mismatch', $decision['classification']);
    }

    public function testDecideDoesNotRetryNonTransientRuntimeErrors(): void
    {
        $decision = FlakeRetry::decide(
            new RuntimeException('Payload field type mismatch'),
            1,
            1,
            static fn(\Throwable $e): bool => $e instanceof ContractAssertionException,
        );

        self::assertFalse($decision['retry']);
        self::assertSame('non_transient_runtime', $decision['classification']);
    }

    public function testIsTransientRuntimeErrorRecognizesSupportedPatterns(): void
    {
        self::assertTrue(FlakeRetry::isTransientRuntimeError('cURL error 28: Operation timed out'));
        self::assertTrue(FlakeRetry::isTransientRuntimeError('Connection reset by peer'));
        self::assertTrue(FlakeRetry::isTransientRuntimeError('HTTP 502 Bad Gateway'));
        self::assertFalse(FlakeRetry::isTransientRuntimeError('Expected JSON type "array", got "object"'));
    }
}

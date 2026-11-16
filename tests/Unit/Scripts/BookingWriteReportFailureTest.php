<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CiContract\BookingWriteReportFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ci/lib/BookingWriteReportFailure.php';

final class BookingWriteReportFailureTest extends TestCase
{
    public function testSuccessfulChecksBecomeRuntimeFailureWhenReportPublicationFails(): void
    {
        $exitCode = 0;
        $failure = null;

        BookingWriteReportFailure::apply($exitCode, 2, $failure, new RuntimeException('occupied path'));

        self::assertSame(2, $exitCode);
        self::assertSame('runtime_error', $failure['classification']);
        self::assertStringContainsString('occupied path', $failure['message']);
    }

    public function testExistingCheckFailureBecomesRuntimeFailureWhenReportPublicationFails(): void
    {
        $exitCode = 1;
        $failure = ['classification' => 'contract_mismatch'];

        BookingWriteReportFailure::apply($exitCode, 2, $failure, new RuntimeException('occupied path'));

        self::assertSame(2, $exitCode);
        self::assertSame('runtime_error', $failure['classification']);
        self::assertStringContainsString('occupied path', $failure['message']);
    }
}

<?php

declare(strict_types=1);

namespace CiContract;

use Throwable;

final class BookingWriteReportFailure
{
    /**
     * @param array<string, mixed>|null $failure
     */
    public static function apply(int &$exitCode, int $runtimeErrorCode, ?array &$failure, Throwable $error): void
    {
        $failure = [
            'message' => 'Report publication failed: ' . $error->getMessage(),
            'exception' => get_class($error),
            'classification' => 'runtime_error',
        ];
        $exitCode = $runtimeErrorCode;
    }
}

<?php

declare(strict_types=1);

namespace CiContract;

use Throwable;

final class FlakeRetry
{
    /**
     * @param callable(Throwable):bool $isContractMismatch
     * @return array{retry:bool,classification:string,reason:string}
     */
    public static function decide(
        Throwable $error,
        int $attemptNumber,
        int $maxRetries,
        callable $isContractMismatch,
    ): array {
        $reason = trim($error->getMessage()) !== '' ? trim($error->getMessage()) : get_class($error);

        if ($isContractMismatch($error)) {
            return [
                'retry' => false,
                'classification' => 'contract_mismatch',
                'reason' => $reason,
            ];
        }

        if (!self::isTransientRuntimeError($reason)) {
            return [
                'retry' => false,
                'classification' => 'non_transient_runtime',
                'reason' => $reason,
            ];
        }

        $canRetry = $attemptNumber <= $maxRetries;

        return [
            'retry' => $canRetry,
            'classification' => $canRetry ? 'transient_runtime' : 'transient_runtime_retry_exhausted',
            'reason' => $reason,
        ];
    }

    public static function isTransientRuntimeError(string $message): bool
    {
        $normalized = strtolower(trim($message));

        if ($normalized === '') {
            return false;
        }

        $patterns = [
            '/\b502\b/',
            '/\b503\b/',
            '/\b504\b/',
            '/\bgateway timeout\b/',
            '/\btimeout\b/',
            '/\btimed out\b/',
            '/\bconnection reset\b/',
            '/\bconnection refused\b/',
            '/\bcould not connect\b/',
            '/\bfailed to connect\b/',
            '/\btemporarily unavailable\b/',
            '/\bcurl error 28\b/',
            '/\brecv failure\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

/**
 * Run the LDAP fixture cleanup and fail closed when it cannot be verified.
 *
 * @param callable|null $cleanup
 * @param array<string, mixed>|null $failure
 */
function dashboardIntegrationSmokeRunLdapFixtureCleanup(?callable $cleanup, int &$exitCode, ?array &$failure): void
{
    if ($cleanup === null) {
        return;
    }

    try {
        $cleanup();
    } catch (Throwable $exception) {
        $message = 'LDAP guardrail fixture cleanup failed.';
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        $cleanupFailure = [
            'message' => $message,
            'exception' => get_class($exception),
        ];

        if ($exitCode === INTEGRATION_SMOKE_EXIT_SUCCESS || $failure === null) {
            $exitCode = INTEGRATION_SMOKE_EXIT_RUNTIME_ERROR;
            $failure = $cleanupFailure;
            return;
        }

        $failure['cleanup_error'] = $cleanupFailure;
    }
}

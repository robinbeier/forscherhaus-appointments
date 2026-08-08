<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReleaseGate\CustomersUiSmokeBrowserFlowException;
use ReleaseGate\CustomersUiSmokeContract;
use ReleaseGate\GateAssertionException;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/CustomersUiSmokeContract.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/CustomersUiSmokeGateRuntime.php';

use function ReleaseGate\customersUiSmokeFinalizeCleanup;
use function ReleaseGate\customersUiSmokeStorageStatesRemoved;
use function ReleaseGate\customersUiSmokeTemporaryArtifactsRemoved;
use function ReleaseGate\customersUiSmokeValidateBrowserResult;
use function ReleaseGate\customersUiSmokeWithStorageState;

final class CustomersUiSmokeGateRuntimeTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = CustomersUiSmokeContract::createPrivateTempDirectory();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDirectory)) {
            return;
        }

        foreach (scandir($this->tempDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->tempDirectory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
                continue;
            }

            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        }

        rmdir($this->tempDirectory);
    }

    public function testBrowserResultAcceptsOnlyKnownBooleansAndNonNegativeCounts(): void
    {
        $result = $this->validBrowserResult();

        self::assertSame($result, customersUiSmokeValidateBrowserResult($result));
    }

    public function testBrowserFlowExceptionCarriesOnlyValidatedSafeDetails(): void
    {
        $result = $this->validBrowserResult();
        $result['ok'] = false;
        $result['initial_search_empty'] = false;

        $exception = new CustomersUiSmokeBrowserFlowException(customersUiSmokeValidateBrowserResult($result));

        self::assertSame($result, $exception->safeDetails);
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    /** @param callable(array<string, bool|int>): array<string, mixed> $mutate */
    #[DataProvider('invalidBrowserResultProvider')]
    public function testBrowserResultRejectsUnsafeOrInvalidFields(callable $mutate, string $expectedMessage): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage($expectedMessage);

        customersUiSmokeValidateBrowserResult($mutate($this->validBrowserResult()));
    }

    /**
     * @return iterable<string, array{0: callable(array<string, bool|int>): array<string, mixed>, 1: string}>
     */
    public static function invalidBrowserResultProvider(): iterable
    {
        yield 'unknown PII-like string' => [
            static function (array $result): array {
                $result['username'] = 'real-person@example.test';

                return $result;
            },
            'unexpected field set',
        ];
        yield 'known boolean encoded as string' => [
            static function (array $result): array {
                $result['page_loaded'] = 'yes';

                return $result;
            },
            'non-boolean field',
        ];
        yield 'negative counter' => [
            static function (array $result): array {
                $result['flow_error_count'] = -1;

                return $result;
            },
            'invalid count',
        ];
        yield 'counter encoded as string' => [
            static function (array $result): array {
                $result['search_response_count'] = '1';

                return $result;
            },
            'invalid count',
        ];
    }

    public function testStorageStateIsRemovedWhenBrowserStepFailsAfterWrite(): void
    {
        $capturedStatePath = '';

        try {
            customersUiSmokeWithStorageState(
                $this->tempDirectory,
                'admin',
                [['name' => 'ea-cookie', 'value' => 'token', 'domain' => 'example.test', 'path' => '/']],
                static function (string $statePath) use (&$capturedStatePath): array {
                    $capturedStatePath = $statePath;
                    self::assertFileExists($statePath);

                    throw new RuntimeException('synthetic browser failure');
                },
            );

            self::fail('The synthetic browser failure should escape the storage-state helper.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic browser failure', $exception->getMessage());
        }

        self::assertNotSame('', $capturedStatePath);
        self::assertFileDoesNotExist($capturedStatePath);
        self::assertTrue(customersUiSmokeFinalizeCleanup([], $this->tempDirectory, static function (): void {}));
        self::assertTrue(customersUiSmokeTemporaryArtifactsRemoved($this->tempDirectory));
    }

    public function testStorageStateSuccessIsReportedOnlyAfterRemoval(): void
    {
        $capturedStatePath = '';

        $details = customersUiSmokeWithStorageState(
            $this->tempDirectory,
            'admin',
            [['name' => 'ea-cookie', 'value' => 'token', 'domain' => 'example.test', 'path' => '/']],
            static function (string $statePath) use (&$capturedStatePath): array {
                $capturedStatePath = $statePath;
                self::assertFileExists($statePath);

                return ['browser_closed' => true];
            },
        );

        self::assertFileDoesNotExist($capturedStatePath);
        self::assertTrue($details['storage_state_removed']);
    }

    public function testStorageStateSetupFailureAfterWriteStillRemovesFile(): void
    {
        try {
            customersUiSmokeWithStorageState(
                $this->tempDirectory,
                'admin',
                [['name' => 'ea-cookie', 'value' => 'token', 'domain' => 'example.test', 'path' => '/']],
                static fn(string $statePath): array => ['browser_closed' => is_file($statePath)],
                static fn(string $statePath, int $mode): bool => false,
            );

            self::fail('A synthetic chmod failure must escape the storage-state helper.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Customers UI smoke browser storage state could not be written safely.',
                $exception->getMessage(),
            );
        }

        self::assertFileDoesNotExist($this->tempDirectory . '/admin-storage-state.json');
    }

    public function testStorageStateSymlinkIsUnlinkedWithoutTouchingTarget(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'fh-customers-ui-smoke-target-');
        self::assertIsString($outside);

        try {
            $details = customersUiSmokeWithStorageState(
                $this->tempDirectory,
                'admin',
                [['name' => 'ea-cookie', 'value' => 'token', 'domain' => 'example.test', 'path' => '/']],
                static function (string $statePath) use ($outside): array {
                    self::assertTrue(unlink($statePath));
                    self::assertTrue(symlink($outside, $statePath));

                    return ['browser_closed' => true];
                },
            );

            self::assertTrue($details['storage_state_removed']);
            self::assertFileExists($outside);
            self::assertFalse(is_link($this->tempDirectory . '/admin-storage-state.json'));
        } finally {
            unlink($outside);
        }
    }

    public function testCallbackAndCleanupFailurePrioritizesCleanupAndReportsArtifactsRemaining(): void
    {
        $capturedStatePath = '';

        try {
            customersUiSmokeWithStorageState(
                $this->tempDirectory,
                'admin',
                [['name' => 'ea-cookie', 'value' => 'token', 'domain' => 'example.test', 'path' => '/']],
                static function (string $statePath) use (&$capturedStatePath): array {
                    $capturedStatePath = $statePath;
                    self::assertTrue(unlink($statePath));
                    self::assertTrue(mkdir($statePath, 0700));

                    throw new RuntimeException('synthetic browser failure');
                },
            );

            self::fail('A remaining storage-state path must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Customers UI smoke browser storage state could not be removed.',
                $exception->getMessage(),
            );
        }

        self::assertFalse(customersUiSmokeFinalizeCleanup([], $this->tempDirectory, static function (): void {}));
        self::assertFalse(customersUiSmokeStorageStatesRemoved($this->tempDirectory));
        self::assertFalse(customersUiSmokeTemporaryArtifactsRemoved($this->tempDirectory));
        self::assertTrue(rmdir($capturedStatePath));
    }

    public function testUnrelatedArtifactFailsCleanupWithoutChangingRemovedStorageStateStatus(): void
    {
        $details = customersUiSmokeWithStorageState(
            $this->tempDirectory,
            'admin',
            [['name' => 'ea-cookie', 'value' => 'token', 'domain' => 'example.test', 'path' => '/']],
            static fn(string $statePath): array => ['browser_closed' => is_file($statePath)],
        );
        self::assertTrue($details['storage_state_removed']);

        self::assertTrue(mkdir($this->tempDirectory . '/unrelated-artifact', 0700));
        self::assertFalse(customersUiSmokeFinalizeCleanup([], $this->tempDirectory, static function (): void {}));
        self::assertTrue(customersUiSmokeStorageStatesRemoved($this->tempDirectory));
        self::assertFalse(customersUiSmokeTemporaryArtifactsRemoved($this->tempDirectory));
    }

    public function testFinalizeCleanupReturnsFalseWhenClosingRemainingSessionFails(): void
    {
        $closedSessions = [];

        $cleanupOk = customersUiSmokeFinalizeCleanup(['admin' => 'cui-a-123'], $this->tempDirectory, static function (
            string $sessionId,
        ) use (&$closedSessions): void {
            $closedSessions[] = $sessionId;

            throw new RuntimeException('close failed');
        });

        self::assertFalse($cleanupOk);
        self::assertSame(['cui-a-123'], $closedSessions);
        self::assertDirectoryDoesNotExist($this->tempDirectory);
    }

    public function testFinalizeCleanupReturnsFalseWhenTemporaryArtifactsCannotBeFullyRemoved(): void
    {
        mkdir($this->tempDirectory . '/leftover', 0700);

        $cleanupOk = customersUiSmokeFinalizeCleanup([], $this->tempDirectory, static function (): void {});

        self::assertFalse($cleanupOk);
        self::assertDirectoryExists($this->tempDirectory);
        self::assertDirectoryExists($this->tempDirectory . '/leftover');
    }

    /**
     * @return array<string, bool|int>
     */
    private function validBrowserResult(): array
    {
        return [
            'ok' => true,
            'network_policy_installed' => true,
            'page_loaded' => true,
            'initial_search_empty' => true,
            'synthetic_search_empty' => true,
            'empty_state_visible' => true,
            'script_vars_safe' => true,
            'dom_safe' => true,
            'response_bodies_safe' => true,
            'search_response_count' => 2,
            'blocked_request_count' => 0,
            'page_error_count' => 0,
            'console_error_count' => 0,
            'flow_error_count' => 0,
        ];
    }
}

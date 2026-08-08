<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\CustomersUiSmokeContract;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/CustomersUiSmokeContract.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/CustomersUiSmokeGateRuntime.php';

use function ReleaseGate\customersUiSmokeFinalizeCleanup;
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

    public function testStorageStateIsRemovedWhenBrowserStepFailsAfterWrite(): void
    {
        $capturedStatePath = '';

        try {
            customersUiSmokeWithStorageState(
                $this->tempDirectory,
                'admin',
                [['name' => 'ea-cookie', 'value' => 'token', 'domain' => 'example.test', 'path' => '/']],
                static function (string $statePath) use (&$capturedStatePath): void {
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
}

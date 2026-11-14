<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BackupDirectoryPermissionContractTest extends TestCase
{
    public function testContainerStartupKeepsBackupsPrivateAndOtherRuntimeDirectoriesUnchanged(): void
    {
        $script = file_get_contents(__DIR__ . '/../../../docker/php-fpm/start-container');
        self::assertIsString($script);
        self::assertStringContainsString('chmod 0700 storage/backups', $script);
        self::assertStringContainsString('chmod 0777 storage/{cache,logs,uploads}', $script);
        self::assertStringNotContainsString('chmod 0777 storage/{backups,cache,logs,uploads}', $script);
    }
}

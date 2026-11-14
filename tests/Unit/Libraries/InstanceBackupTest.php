<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/application/libraries/Instance.php';

final class InstanceBackupTest extends TestCase
{
    private ReflectionClass $instanceClass;

    protected function setUp(): void
    {
        $this->instanceClass = new ReflectionClass(\Instance::class);
    }

    /** @return array{0: object, 1: \ReflectionMethod} */
    private function writer(): array
    {
        $instance = $this->instanceClass->newInstanceWithoutConstructor();
        $method = $this->instanceClass->getMethod('write_backup_file');
        $method->setAccessible(true);

        return [$instance, $method];
    }

    public function testWriterDoesNotFollowPrecreatedSymlink(): void
    {
        [$instance, $writer] = $this->writer();
        $root = $this->makeTempDirectory();
        $outside = $root . '/outside';
        file_put_contents($outside, 'keep');

        // The generated name is random, so any precreated leaf must not be touched.
        symlink($outside, $root . '/easyappointments-backup-precreated.gz');
        $target = $writer->invoke($instance, $root, 'payload');

        self::assertFileExists($target);
        self::assertSame('keep', file_get_contents($outside));
        self::assertSame('payload', file_get_contents($target));
        self::assertSame(0600, fileperms($target) & 0777);
    }

    public function testWriterCreatesUniqueFilesInsteadOfOverwritingExistingBackup(): void
    {
        [$instance, $writer] = $this->writer();
        $root = $this->makeTempDirectory();
        $existing = $root . '/easyappointments-backup-2024-01-01-000000.gz';
        file_put_contents($existing, 'keep');

        $target = $writer->invoke($instance, $root, 'payload');

        self::assertNotSame($existing, $target);
        self::assertSame('keep', file_get_contents($existing));
    }

    public function testExclusiveHandleRefusesExistingLeaf(): void
    {
        $instance = $this->instanceClass->newInstanceWithoutConstructor();
        $method = $this->instanceClass->getMethod('create_backup_handle');
        $method->setAccessible(true);
        $root = $this->makeTempDirectory();
        $existing = $root . '/existing.gz';
        file_put_contents($existing, 'keep');

        self::assertFalse($method->invoke($instance, $existing));
        self::assertSame('keep', file_get_contents($existing));
    }

    public function testBackupPathValidationRejectsSymlinkDirectory(): void
    {
        [$instance] = $this->writer();
        $root = $this->makeTempDirectory();
        $real = $root . '/real';
        $link = $root . '/link';
        mkdir($real, 0700);
        symlink($real, $link);
        $validator = $this->instanceClass->getMethod('validate_backup_path');
        $validator->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $validator->invoke($instance, $link);
    }

    public function testBackupPathValidationRejectsSymlinkedAncestor(): void
    {
        [$instance] = $this->writer();
        $root = $this->makeTempDirectory();
        $real = $root . '/real';
        $link = $root . '/link';
        mkdir($real . '/backups', 0700, true);
        symlink($real, $link);
        $validator = $this->instanceClass->getMethod('validate_backup_path');
        $validator->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $validator->invoke($instance, $link . '/backups');
    }

    public function testBackupPathValidationRejectsRelativeSymlinkedAncestor(): void
    {
        [$instance] = $this->writer();
        $root = $this->makeTempDirectory();
        $real = $root . '/real';
        $link = $root . '/link';
        mkdir($real . '/backups', 0700, true);
        symlink($real, $link);
        $validator = $this->instanceClass->getMethod('validate_backup_path');
        $oldCwd = getcwd();
        self::assertIsString($oldCwd);

        try {
            chdir($root);
            $this->expectException(InvalidArgumentException::class);
            $validator->invoke($instance, 'link/backups');
        } finally {
            chdir($oldCwd);
        }
    }

    public function testCheckoutOwnerExceptionIsLimitedToDefaultBackupPath(): void
    {
        if (!defined('APPPATH')) {
            self::markTestSkipped('APPPATH is not defined by the application bootstrap');
        }

        $instance = $this->instanceClass->newInstanceWithoutConstructor();
        $owner = $this->instanceClass->getMethod('trusted_checkout_owner');
        $default = realpath(rtrim(APPPATH, '/\\') . '/../storage/backups');
        $checkout = realpath(rtrim(APPPATH, '/\\') . '/..');
        self::assertIsString($default);
        self::assertIsString($checkout);
        $identity = lstat($checkout);
        self::assertIsArray($identity);
        self::assertSame($identity['uid'], $owner->invoke($instance, $default));

        $custom = $this->makeTempDirectory();
        try {
            self::assertNull($owner->invoke($instance, $custom));
        } finally {
            rmdir($custom);
        }
    }

    public function testBackupPathValidationRejectsWorldWritableDirectory(): void
    {
        [$instance] = $this->writer();
        $root = $this->makeTempDirectory();
        chmod($root, 0777);
        $validator = $this->instanceClass->getMethod('validate_backup_path');
        $validator->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $validator->invoke($instance, $root);
    }

    public function testWriterRejectsUnwritableDestination(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can write mode-restricted fixture');
        }

        [$instance, $writer] = $this->writer();
        $root = $this->makeTempDirectory();
        chmod($root, 0500);

        $this->expectException(RuntimeException::class);
        $writer->invoke($instance, $root, 'payload');
    }

    private function makeTempDirectory(): string
    {
        $base = realpath(sys_get_temp_dir());
        self::assertIsString($base);
        $root = $base . '/instance-backup-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);

        return $root;
    }
}

<?php

namespace Tests\Unit\Core;

use EA_Log;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'core/EA_Log.php';

final class EaLogTest extends TestCase
{
    public function testWriteLogUsesUtcFilenameAndTimestampAndRestoresCallerTimezone(): void
    {
        $timezone = $this->timezoneWithDifferentDateFromUtc();
        $originalTimezone = date_default_timezone_get();
        $logPath = sys_get_temp_dir() . '/ea-log-' . bin2hex(random_bytes(8));
        mkdir($logPath, 0700, true);
        $logger = new EA_Log();
        $this->setLoggerProperty($logger, '_log_path', $logPath . DIRECTORY_SEPARATOR);

        date_default_timezone_set($timezone);
        try {
            $utcBefore = gmdate('Y-m-d H:i:s');
            self::assertTrue($logger->write_log('error', 'synthetic timezone boundary entry'));
            $utcAfter = gmdate('Y-m-d H:i:s');
            $logFiles = array_map(
                fn(string $timestamp): string => $logPath . '/log-' . substr($timestamp, 0, 10) . '.php',
                array_unique([$utcBefore, $utcAfter]),
            );
            $logFile = current(array_filter($logFiles, 'is_file'));
            self::assertIsString($logFile);
            $contents = (string) file_get_contents($logFile);
            self::assertMatchesRegularExpression(
                '/ERROR - (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) --> synthetic timezone boundary entry/',
                $contents,
            );
            preg_match(
                '/ERROR - (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) --> synthetic timezone boundary entry/',
                $contents,
                $matches,
            );
            self::assertGreaterThanOrEqual($utcBefore, $matches[1]);
            self::assertLessThanOrEqual($utcAfter, $matches[1]);
            self::assertSame($timezone, date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
            $this->removeDirectory($logPath);
        }
    }

    public function testLoggerFailureStillRestoresCallerTimezone(): void
    {
        $logger = new EA_Log();
        $originalTimezone = date_default_timezone_get();
        $timezone = $this->timezoneWithDifferentDateFromUtc();
        $this->setLoggerProperty(
            $logger,
            '_log_path',
            sys_get_temp_dir() . '/missing-ea-log-' . bin2hex(random_bytes(8)) . '/',
        );
        date_default_timezone_set($timezone);

        try {
            self::assertFalse($logger->write_log('error', 'failed synthetic entry'));
            self::assertSame($timezone, date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    private function timezoneWithDifferentDateFromUtc(): string
    {
        $utcDate = gmdate('Y-m-d');
        foreach (['Pacific/Kiritimati', 'Etc/GMT+12', 'Pacific/Honolulu'] as $timezone) {
            if ((new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d') !== $utcDate) {
                return $timezone;
            }
        }

        throw new \RuntimeException('No available test timezone currently crosses the UTC date boundary.');
    }

    private function setLoggerProperty(EA_Log $logger, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty(EA_Log::class, $property);
        $reflection->setValue($logger, $value);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory), ['.', '..']) as $entry) {
            unlink($directory . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($directory);
    }
}

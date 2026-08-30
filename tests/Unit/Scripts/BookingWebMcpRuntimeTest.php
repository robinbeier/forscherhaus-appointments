<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BookingWebMcpRuntimeTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function testJavaScriptRuntimeContract(): void
    {
        if (!is_file($this->repositoryRoot . '/node_modules/moment-timezone/package.json')) {
            $this->markTestSkipped('Frontend dependencies are unavailable in this PHP-only test shard.');
        }

        $process = proc_open(
            ['node', '--test', 'tests/JavaScript/booking_webmcp.test.js'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repositoryRoot,
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, trim($stdout . PHP_EOL . $stderr));
        self::assertSame(1, preg_match('/(?:#|ℹ) pass (\d+)/', $stdout, $matches));
        self::assertGreaterThanOrEqual(32, (int) $matches[1]);
        self::assertMatchesRegularExpression('/(?:#|ℹ) fail 0/', $stdout);
    }

    public function testFeatureFlagDefaultsFailClosedAndAdapterContainsNoWriteEndpoints(): void
    {
        $sampleConfig = $this->read('config-sample.php');
        $appConfig = $this->read('application/config/config.php');
        $adapter = $this->read('assets/js/pages/booking_webmcp.js');

        self::assertStringContainsString('const WEBMCP_BOOKING_PILOT_ENABLED = false;', $sampleConfig);
        self::assertStringContainsString("defined('Config::WEBMCP_BOOKING_PILOT_ENABLED')", $appConfig);

        foreach (
            [
                'booking/register',
                'booking_confirmation',
                'booking_cancellation',
                'delete_personal_information',
                'privacy/delete',
            ]
            as $forbiddenPath
        ) {
            self::assertStringNotContainsString($forbiddenPath, $adapter, $forbiddenPath);
        }
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->repositoryRoot . '/' . $relativePath);
        self::assertIsString($contents);
        return $contents;
    }
}

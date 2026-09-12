<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Zero_surprise_canary_fixture.php';

final class ZeroSurpriseCanaryFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('FH_CANARY_INTEGRATION') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the explicitly isolated root Docker fixture run.');
        }
        self::assertSame('testing', ENVIRONMENT);
        self::assertSame('clean', (new Zero_surprise_canary_fixture())->run('verify'));
    }

    public function testActivateVerifyAndCompleteCleanup(): void
    {
        $fixture = new Zero_surprise_canary_fixture();
        try {
            self::assertSame('active', $fixture->run('activate'));
            self::assertSame('active', $fixture->run('verify'));
            $state = json_decode(
                file_get_contents(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $ci = &get_instance();
            $settings = $ci->db->get_where('user_settings', ['id_users' => $state['provider_id']])->row_array();
            self::assertSame(
                ['start' => '08:00', 'end' => '18:00', 'breaks' => []],
                json_decode($settings['working_plan'], true)['monday'],
            );
            self::assertSame(0, (int) $settings['notifications']);
            self::assertSame(0, (int) $settings['caldav_sync']);
            self::assertSame(600, $state['expires_at'] - $state['created_at']);
        } finally {
            self::assertSame('clean', $fixture->run('deactivate'));
        }
        self::assertSame('clean', $fixture->run('verify'));
        self::assertFileDoesNotExist(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
    }

    public function testFailedCommitLeavesRecoverableJournalAndNoRows(): void
    {
        $ci = &get_instance();
        $database = $ci->db;
        $ci->db = new class ($database) {
            public function __construct(private object $database) {}
            public function trans_commit(): bool
            {
                return false;
            }
            public function __call(string $name, array $args): mixed
            {
                return $this->database->$name(...$args);
            }
        };
        $fixture = new Zero_surprise_canary_fixture();
        $failure = null;
        try {
            $fixture->run('activate');
        } catch (RuntimeException $e) {
            $failure = $e;
        } finally {
            $ci->db = $database;
        }
        try {
            self::assertNotNull($failure);
            self::assertStringContainsString('commit', $failure->getMessage());
            self::assertSame('cleanup_pending', $fixture->run('verify'));
        } finally {
            self::assertSame('clean', $fixture->run('deactivate'));
        }
        self::assertSame('clean', $fixture->run('verify'));
        self::assertFileDoesNotExist(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
    }
}

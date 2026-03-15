<?php

namespace Tests\Unit\Scripts;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

define('ZERO_SURPRISE_REPLAY_TEST_MODE', true);
require_once __DIR__ . '/../../../scripts/release-gate/zero_surprise_replay.php';

class ZeroSurpriseReplayTest extends TestCase
{
    public function testBuildAppReadinessUrlUsesIndexPageWhenPresent(): void
    {
        $actual = buildAppReadinessUrl([
            'base_url' => 'https://example.invalid/',
            'index_page' => 'index.php',
        ]);

        $this->assertSame('https://example.invalid/index.php/login', $actual);
    }

    public function testBuildAppReadinessUrlOmitsIndexPageWhenEmpty(): void
    {
        $actual = buildAppReadinessUrl([
            'base_url' => 'https://example.invalid/',
            'index_page' => '',
        ]);

        $this->assertSame('https://example.invalid/login', $actual);
    }

    public function testBuildReplayGateSeedSqlHashesCredentialsAndEscapesUsername(): void
    {
        $sql = buildReplayGateSeedSql([
            'username' => "Gate'User",
            'password' => 'secret-pass',
            'release_id' => 'ea_20260320_1200',
            'timezone' => 'Europe/Berlin',
        ]);

        $this->assertStringContainsString("SET @zs_username = 'Gate\\'User';", $sql);
        $this->assertStringContainsString("SET @zs_email = 'zs+gate-user@gate.invalid';", $sql);
        $this->assertStringContainsString('INSERT INTO ea_user_settings', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertMatchesRegularExpression("/SET @zs_password_hash = '[a-f0-9]{64}';/", $sql);
        $this->assertMatchesRegularExpression("/SET @zs_salt = '[a-f0-9]{64}';/", $sql);
    }

    public function testBuildReplayGateSeedSqlRejectsMissingCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Replay gate account sync requires non-empty username and password.');

        buildReplayGateSeedSql([
            'username' => '',
            'password' => '',
        ]);
    }
}

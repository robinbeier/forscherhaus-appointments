<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseCredentials;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseCredentials.php';

class ZeroSurpriseCredentialsTest extends TestCase
{
    public function testResolveAppliesCliOverridesBeforeIniAndProfileDefaults(): void
    {
        $credentialsFile = $this->writeTempIni(
            <<<'INI'
            base_url = http://ini.example
            index_page = index.php
            username = ini-user
            password = ini-pass
            start_date = 2026-01-01
            end_date = 2026-01-31
            booking_search_days = 7
            retry_count = 0
            max_pdf_duration_ms = 25000
            timezone = Europe/London
            pdf_health_url = http://ini.example/healthz
            INI
            ,
        );

        try {
            $resolved = ZeroSurpriseCredentials::resolve(
                $credentialsFile,
                'school-day-default',
                [
                    'base_url' => 'http://cli.example',
                    'start_date' => '2026-02-01',
                    'end_date' => '2026-02-28',
                    'booking_search_days' => '10',
                    'timezone' => 'Europe/Berlin',
                ],
                new DateTimeImmutable('2026-03-20T08:00:00Z'),
            );

            $this->assertSame('http://cli.example', $resolved['base_url']);
            $this->assertSame('index.php', $resolved['index_page']);
            $this->assertSame('ini-user', $resolved['username']);
            $this->assertSame('ini-pass', $resolved['password']);
            $this->assertSame('2026-02-01', $resolved['start_date']);
            $this->assertSame('2026-02-28', $resolved['end_date']);
            $this->assertSame(10, $resolved['booking_search_days']);
            $this->assertSame(0, $resolved['retry_count']);
            $this->assertSame(25000, $resolved['max_pdf_duration_ms']);
            $this->assertSame('Europe/Berlin', $resolved['timezone']);
            $this->assertSame('http://ini.example/healthz', $resolved['pdf_health_url']);
        } finally {
            @unlink($credentialsFile);
        }
    }

    public function testResolveFallsBackToProfileDefaultsWhenIniOmitsWindowAndTuning(): void
    {
        $credentialsFile = $this->writeTempIni(
            <<<'INI'
            base_url = http://localhost
            index_page =
            username = administrator
            password = administrator
            INI
            ,
        );

        try {
            $resolved = ZeroSurpriseCredentials::resolve(
                $credentialsFile,
                'school-day-default',
                [],
                new DateTimeImmutable('2026-03-20T08:00:00Z'),
            );

            $this->assertSame('school-day-default', $resolved['profile_name']);
            $this->assertSame('', $resolved['index_page']);
            $this->assertSame('2026-02-18', $resolved['start_date']);
            $this->assertSame('2026-03-20', $resolved['end_date']);
            $this->assertSame(14, $resolved['booking_search_days']);
            $this->assertSame(1, $resolved['retry_count']);
            $this->assertSame(30000, $resolved['max_pdf_duration_ms']);
            $this->assertSame('Europe/Berlin', $resolved['timezone']);
            $this->assertNull($resolved['pdf_health_url']);
        } finally {
            @unlink($credentialsFile);
        }
    }

    public function testResolveSupportsExplicitCliCredentialsWithoutIniFile(): void
    {
        $resolved = ZeroSurpriseCredentials::resolve(
            null,
            'school-day-default',
            [
                'base_url' => 'http://cli-only.example',
                'index_page' => '',
                'username' => 'administrator',
                'password' => '  keep-spaces  ',
            ],
            new DateTimeImmutable('2026-03-20T08:00:00Z'),
        );

        $this->assertNull($resolved['credentials_file']);
        $this->assertSame('http://cli-only.example', $resolved['base_url']);
        $this->assertSame('', $resolved['index_page']);
        $this->assertSame('administrator', $resolved['username']);
        $this->assertSame('  keep-spaces  ', $resolved['password']);
        $this->assertSame('2026-02-18', $resolved['start_date']);
        $this->assertSame('2026-03-20', $resolved['end_date']);
    }

    private function writeTempIni(string $contents): string
    {
        $path = sys_get_temp_dir() . '/zero-surprise-creds-' . bin2hex(random_bytes(4)) . '.ini';
        file_put_contents($path, $contents . PHP_EOL);

        return $path;
    }
}

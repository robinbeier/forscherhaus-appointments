<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/release-gate/lib/PlaywrightCookieRecords.php';

class PlaywrightCookieRecordsTest extends TestCase
{
    public function testNormalizeCookieRecordsForPlaywrightPreservesScopedCookieMetadata(): void
    {
        $normalized = normalizeCookieRecordsForPlaywright(
            [
                [
                    'name' => 'ea_session',
                    'value' => 'abc123',
                    'domain' => 'example.test',
                    'path' => '/app/',
                    'secure' => true,
                    'httpOnly' => true,
                ],
                [
                    'name' => 'csrf_cookie',
                    'value' => 'token-123',
                    'url' => 'https://example.test/app/index.php/',
                    'sameSite' => 'Lax',
                ],
            ],
            'https://example.test/app/index.php/dashboard',
        );

        self::assertSame(
            [
                [
                    'name' => 'ea_session',
                    'value' => 'abc123',
                    'domain' => 'example.test',
                    'path' => '/app/',
                    'secure' => true,
                    'httpOnly' => true,
                ],
                [
                    'name' => 'csrf_cookie',
                    'value' => 'token-123',
                    'url' => 'https://example.test/app/index.php/',
                    'sameSite' => 'Lax',
                ],
            ],
            $normalized,
        );
    }

    public function testNormalizeCookieRecordsForPlaywrightBuildsFallbackUrlFromTargetPath(): void
    {
        $normalized = normalizeCookieRecordsForPlaywright(
            [
                [
                    'name' => 'csrf_cookie',
                    'value' => 'token-123',
                    'path' => '/app/index.php/',
                ],
                [
                    'name' => 'locale',
                    'value' => 'de',
                ],
            ],
            'https://example.test/app/index.php/dashboard',
        );

        self::assertSame('https://example.test/app/index.php/', $normalized[0]['url']);
        self::assertSame('https://example.test/app/index.php/', $normalized[1]['url']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class BookingConfirmationDownloadSnippetTest extends TestCase
{
    public function testBookingConfirmationDownloadSnippetEmitsRepoOwnedSentinelPayload(): void
    {
        $snippet = file_get_contents(
            __DIR__ . '/../../../scripts/release-gate/playwright/booking_confirmation_download.js',
        );

        self::assertIsString($snippet);
        self::assertStringContainsString('__BOOKING_CONFIRMATION_PDF_GATE__', $snippet);
        self::assertStringContainsString('console.log(`${resultPrefix}${JSON.stringify(payload)}`);', $snippet);
        self::assertStringNotContainsString('### Result', $snippet);
        self::assertNotFalse(strpos($snippet, 'const emitResult = (payload) => {'));
        self::assertNotFalse(strpos($snippet, 'if (!downloadPath) {'));
        self::assertLessThan(
            strpos($snippet, 'if (!downloadPath) {'),
            strpos($snippet, 'const emitResult = (payload) => {'),
        );
    }
}

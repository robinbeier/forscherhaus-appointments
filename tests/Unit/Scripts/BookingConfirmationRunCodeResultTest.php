<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/BookingConfirmationRunCodeResult.php';

use function ReleaseGate\parseBookingConfirmationRunCodeResult;

class BookingConfirmationRunCodeResultTest extends TestCase
{
    public function testParseBookingConfirmationRunCodeResultDecodesRepoSentinelPayload(): void
    {
        $payload = parseBookingConfirmationRunCodeResult([
            'stdout' => "debug\n__BOOKING_CONFIRMATION_PDF_GATE__{\"ok\":true,\"value\":11}\n",
        ]);

        self::assertTrue($payload['ok']);
        self::assertSame(11, $payload['value']);
    }

    public function testParseBookingConfirmationRunCodeResultDecodesQuotedSentinelPayload(): void
    {
        $payload = parseBookingConfirmationRunCodeResult([
            'stdout' => "generic [ref=e156]: \"__BOOKING_CONFIRMATION_PDF_GATE__{\\\"ok\\\":true,\\\"value\\\":4}\"\n",
        ]);

        self::assertTrue($payload['ok']);
        self::assertSame(4, $payload['value']);
    }

    public function testParseBookingConfirmationRunCodeResultFallsBackToLegacyResultSection(): void
    {
        $payload = parseBookingConfirmationRunCodeResult([
            'stdout' => "### Result\n{\"ok\":true,\"value\":7}\n### Ran Playwright code\n",
        ]);

        self::assertTrue($payload['ok']);
        self::assertSame(7, $payload['value']);
    }

    public function testParseBookingConfirmationRunCodeResultDecodesQuotedSentinelInsideLegacyResultSection(): void
    {
        $payload = parseBookingConfirmationRunCodeResult([
            'stdout' =>
                "### Result\n" .
                "generic [ref=e156]: \"__BOOKING_CONFIRMATION_PDF_GATE__{\\\"ok\\\":true,\\\"value\\\":9}\"\n" .
                "### Ran Playwright code\n",
        ]);

        self::assertTrue($payload['ok']);
        self::assertSame(9, $payload['value']);
    }

    public function testParseBookingConfirmationRunCodeResultRejectsMissingPayload(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('Could not parse booking confirmation run-code payload');

        parseBookingConfirmationRunCodeResult([
            'stdout' => "### Debug\nno payload here\n",
        ]);
    }

    public function testParseBookingConfirmationRunCodeResultRejectsInvalidJson(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('booking confirmation run-code payload is not valid JSON');

        parseBookingConfirmationRunCodeResult([
            'stdout' => "__BOOKING_CONFIRMATION_PDF_GATE__{\"ok\":true\n",
        ]);
    }

    public function testParseBookingConfirmationRunCodeResultDoesNotDecodeUnrelatedJsonWhenSentinelPayloadIsInvalid(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('booking confirmation run-code payload is not valid JSON');

        parseBookingConfirmationRunCodeResult([
            'stdout' => "generic [ref=e156]: \"__BOOKING_CONFIRMATION_PDF_GATE__{\\\"ok\\\":true\"\n{\"noise\":true}\n",
        ]);
    }

    public function testParseBookingConfirmationRunCodeResultFailsOnPlaywrightErrorSection(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('Playwright exploded');

        parseBookingConfirmationRunCodeResult([
            'stdout' => "### Error\nPlaywright exploded\n### Ran Playwright code\n",
        ]);
    }
}

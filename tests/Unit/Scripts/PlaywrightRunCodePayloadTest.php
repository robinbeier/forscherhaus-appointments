<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/PlaywrightRunCodePayload.php';

use function ReleaseGate\parsePlaywrightRunCodeJsonPayload;

class PlaywrightRunCodePayloadTest extends TestCase
{
    public function testParsePlaywrightRunCodeJsonPayloadDecodesSentinelPayload(): void
    {
        $payload = parsePlaywrightRunCodeJsonPayload(
            "debug line\n__PAYLOAD__{\"ok\":true,\"value\":3}\nmore debug\n",
            '__PAYLOAD__',
            'test run-code',
        );

        self::assertTrue($payload['ok']);
        self::assertSame(3, $payload['value']);
    }

    public function testParsePlaywrightRunCodeJsonPayloadDecodesQuotedSentinelPayload(): void
    {
        $payload = parsePlaywrightRunCodeJsonPayload(
            "generic [ref=e156]: \"__PAYLOAD__{\\\"ok\\\":true,\\\"value\\\":4}\"\n",
            '__PAYLOAD__',
            'test run-code',
        );

        self::assertTrue($payload['ok']);
        self::assertSame(4, $payload['value']);
    }

    public function testParsePlaywrightRunCodeJsonPayloadFallsBackToLegacyResultSection(): void
    {
        $payload = parsePlaywrightRunCodeJsonPayload(
            "### Result\n{\"ok\":true,\"value\":7}\n### Ran Playwright code\n",
            '__PAYLOAD__',
            'test run-code',
        );

        self::assertTrue($payload['ok']);
        self::assertSame(7, $payload['value']);
    }

    public function testParsePlaywrightRunCodeJsonPayloadDecodesQuotedSentinelInsideLegacyResultSection(): void
    {
        $payload = parsePlaywrightRunCodeJsonPayload(
            "### Result\n" .
                "generic [ref=e156]: \"__PAYLOAD__{\\\"ok\\\":true,\\\"value\\\":9}\"\n" .
                "### Ran Playwright code\n",
            '__PAYLOAD__',
            'test run-code',
        );

        self::assertTrue($payload['ok']);
        self::assertSame(9, $payload['value']);
    }

    public function testParsePlaywrightRunCodeJsonPayloadRejectsMissingPayload(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('Could not parse test run-code payload');

        parsePlaywrightRunCodeJsonPayload("### Debug\nno payload here\n", '__PAYLOAD__', 'test run-code');
    }

    public function testParsePlaywrightRunCodeJsonPayloadRejectsInvalidJson(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('test run-code payload is not valid JSON');

        parsePlaywrightRunCodeJsonPayload("__PAYLOAD__{\"ok\":true\n", '__PAYLOAD__', 'test run-code');
    }
}

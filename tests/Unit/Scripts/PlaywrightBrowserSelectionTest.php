<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/release-gate/lib/PlaywrightBrowserSelection.php';

class PlaywrightBrowserSelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PLAYWRIGHT_MCP_BROWSER');
        parent::tearDown();
    }

    public function testAppendConfiguredPlaywrightBrowserArgumentUsesConfiguredBrowserForOpen(): void
    {
        putenv('PLAYWRIGHT_MCP_BROWSER=webkit');

        $arguments = appendConfiguredPlaywrightBrowserArgument(['open', 'https://example.test']);

        self::assertSame(['open', 'https://example.test', '--browser=webkit'], $arguments);
    }

    public function testAppendConfiguredPlaywrightBrowserArgumentKeepsExplicitBrowser(): void
    {
        putenv('PLAYWRIGHT_MCP_BROWSER=webkit');

        $arguments = appendConfiguredPlaywrightBrowserArgument(['open', 'https://example.test', '--browser=firefox']);

        self::assertSame(['open', 'https://example.test', '--browser=firefox'], $arguments);
    }

    public function testAppendConfiguredPlaywrightBrowserArgumentSkipsNonOpenCommands(): void
    {
        putenv('PLAYWRIGHT_MCP_BROWSER=webkit');

        $arguments = appendConfiguredPlaywrightBrowserArgument(['snapshot']);

        self::assertSame(['snapshot'], $arguments);
    }

    public function testPrepareConfiguredPlaywrightCommandArgumentsAddsHeadedOnlyForOpen(): void
    {
        putenv('PLAYWRIGHT_MCP_BROWSER=webkit');

        self::assertSame(
            ['open', 'https://example.test', '--headed', '--browser=webkit'],
            prepareConfiguredPlaywrightCommandArguments(['open', 'https://example.test'], true),
        );
        self::assertSame(['snapshot'], prepareConfiguredPlaywrightCommandArguments(['snapshot'], true));
    }

    public function testBuildPlaywrightSessionArgumentsUsesDocumentedShortFlag(): void
    {
        self::assertSame(['-s=session-123'], buildPlaywrightSessionArguments('session-123'));
    }
}

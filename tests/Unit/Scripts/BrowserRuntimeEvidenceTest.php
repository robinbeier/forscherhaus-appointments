<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ci/lib/BrowserRuntimeEvidence.php';

use function CiRuntimeEvidence\buildDefaultBrowserRuntimeEvidenceArtifactsDir;
use function CiRuntimeEvidence\parseBrowserRuntimeEvidenceMode;
use function CiRuntimeEvidence\resolveBookingPageTargetUrl;
use function CiRuntimeEvidence\resolvePlaywrightArtifactPath;
use function CiRuntimeEvidence\shouldCollectBrowserRuntimeEvidence;

class BrowserRuntimeEvidenceTest extends TestCase
{
    public function testParseBrowserRuntimeEvidenceModeSupportsExplicitModesAndFlagForm(): void
    {
        self::assertSame('off', parseBrowserRuntimeEvidenceMode(null));
        self::assertSame('always', parseBrowserRuntimeEvidenceMode(false));
        self::assertSame('always', parseBrowserRuntimeEvidenceMode('always'));
        self::assertSame('on-failure', parseBrowserRuntimeEvidenceMode('failed'));
        self::assertSame('off', parseBrowserRuntimeEvidenceMode('0'));
    }

    public function testParseBrowserRuntimeEvidenceModeRejectsUnknownValues(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported browser evidence mode');

        parseBrowserRuntimeEvidenceMode('sometimes');
    }

    public function testShouldCollectBrowserRuntimeEvidenceMatchesModeAndSuiteStatus(): void
    {
        self::assertFalse(shouldCollectBrowserRuntimeEvidence('off', true));
        self::assertFalse(shouldCollectBrowserRuntimeEvidence('on-failure', false));
        self::assertTrue(shouldCollectBrowserRuntimeEvidence('on-failure', true));
        self::assertTrue(shouldCollectBrowserRuntimeEvidence('always', false));
    }

    public function testResolveBookingPageTargetUrlRespectsIndexPage(): void
    {
        self::assertSame('http://nginx/index.php/booking', resolveBookingPageTargetUrl('http://nginx', 'index.php'));
        self::assertSame('http://localhost/booking', resolveBookingPageTargetUrl('http://localhost', ''));
    }

    public function testResolvePlaywrightArtifactPathSupportsRelativeAndAbsoluteMarkdownLinks(): void
    {
        $output = <<<'TXT'
        ### Result
        Trace recording stopped.
        - [Trace](.playwright-cli/traces/trace-123.trace)
        - [Network log](/tmp/playwright.network)
        TXT;

        self::assertSame(
            '/repo/.playwright-cli/traces/trace-123.trace',
            resolvePlaywrightArtifactPath($output, 'Trace', '/repo'),
        );
        self::assertSame('/tmp/playwright.network', resolvePlaywrightArtifactPath($output, 'Network log', '/repo'));
        self::assertNull(resolvePlaywrightArtifactPath($output, 'Snapshot', '/repo'));
    }

    public function testBuildDefaultBrowserRuntimeEvidenceArtifactsDirUsesCiStoragePrefix(): void
    {
        $path = buildDefaultBrowserRuntimeEvidenceArtifactsDir('/repo');

        self::assertStringStartsWith('/repo/storage/logs/ci/dashboard-integration-smoke-browser-', $path);
    }
}

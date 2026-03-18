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
use function CiRuntimeEvidence\runPwcliCommand;
use function CiRuntimeEvidence\shouldCollectBrowserRuntimeEvidence;
use function CiRuntimeEvidence\shouldCollectBrowserRuntimeEvidenceForChecks;

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

    public function testShouldCollectBrowserRuntimeEvidenceForChecksUsesFailedCheckIntersection(): void
    {
        self::assertTrue(
            shouldCollectBrowserRuntimeEvidenceForChecks(
                'on-failure',
                true,
                ['booking_extract_bootstrap'],
                ['booking_page_readiness', 'booking_extract_bootstrap'],
            ),
        );
        self::assertFalse(
            shouldCollectBrowserRuntimeEvidenceForChecks(
                'on-failure',
                true,
                ['api_appointments_index'],
                ['booking_page_readiness', 'booking_extract_bootstrap'],
            ),
        );
        self::assertFalse(
            shouldCollectBrowserRuntimeEvidenceForChecks('on-failure', true, [], ['booking_page_readiness']),
        );
        self::assertTrue(shouldCollectBrowserRuntimeEvidenceForChecks('always', false, [], ['booking_page_readiness']));
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

    public function testRunPwcliCommandPinsFirefoxForOpenCommands(): void
    {
        $capturePath = tempnam(sys_get_temp_dir(), 'pwcli-capture-');
        $wrapperPath = tempnam(sys_get_temp_dir(), 'pwcli-wrapper-');
        self::assertIsString($capturePath);
        self::assertIsString($wrapperPath);

        try {
            file_put_contents(
                $wrapperPath,
                "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$@\" > " .
                    escapeshellarg($capturePath) .
                    "\n",
            );
            chmod($wrapperPath, 0777);

            $result = runPwcliCommand(
                [
                    'pwcli_path' => $wrapperPath,
                    'repo_root' => sys_get_temp_dir(),
                    'headed' => false,
                ],
                'session-123',
                ['open', 'http://example.test'],
                5,
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $capturedArgs = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedArgs);
            self::assertContains('--session', $capturedArgs);
            self::assertContains('session-123', $capturedArgs);
            self::assertContains('open', $capturedArgs);
            self::assertContains('http://example.test', $capturedArgs);
            self::assertContains('--browser=firefox', $capturedArgs);
        } finally {
            if (is_file($capturePath)) {
                unlink($capturePath);
            }

            if (is_file($wrapperPath)) {
                unlink($wrapperPath);
            }
        }
    }
}

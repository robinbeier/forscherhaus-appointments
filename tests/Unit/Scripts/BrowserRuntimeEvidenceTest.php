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

    public function testResolveBrowserCookieUrlKeepsAppSubdirectory(): void
    {
        self::assertSame(
            'https://example.test/app/',
            \CiRuntimeEvidence\resolveBrowserCookieUrl('https://example.test/app/index.php/dashboard'),
        );
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

    public function testRunPwcliCommandDefaultsToFirefoxForOpenCommands(): void
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
            self::assertContains('-s=session-123', $capturedArgs);
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

    public function testRunPwcliCommandRespectsConfiguredPlaywrightBrowserOverride(): void
    {
        $capturePath = tempnam(sys_get_temp_dir(), 'pwcli-capture-');
        $wrapperPath = tempnam(sys_get_temp_dir(), 'pwcli-wrapper-');
        self::assertIsString($capturePath);
        self::assertIsString($wrapperPath);

        $previousBrowser = getenv('PLAYWRIGHT_MCP_BROWSER');

        try {
            file_put_contents(
                $wrapperPath,
                "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$@\" > " .
                    escapeshellarg($capturePath) .
                    "\n",
            );
            chmod($wrapperPath, 0777);
            putenv('PLAYWRIGHT_MCP_BROWSER=chromium');

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
            self::assertContains('--browser=chromium', $capturedArgs);
            self::assertNotContains('--browser=firefox', $capturedArgs);
        } finally {
            if ($previousBrowser === false) {
                putenv('PLAYWRIGHT_MCP_BROWSER');
            } else {
                putenv('PLAYWRIGHT_MCP_BROWSER=' . $previousBrowser);
            }

            if (is_file($capturePath)) {
                unlink($capturePath);
            }

            if (is_file($wrapperPath)) {
                unlink($wrapperPath);
            }
        }
    }

    public function testRunPwcliCommandAddsHeadedFlagForOpen(): void
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
                    'headed' => true,
                ],
                'session-123',
                ['open', 'http://example.test'],
                5,
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $capturedArgs = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedArgs);
            self::assertContains('-s=session-123', $capturedArgs);
            self::assertContains('--headed', $capturedArgs);
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

    public function testPlaywrightCliWrapperSkipsBrowserInstallForHelp(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-help-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';
        $wrapperPath = __DIR__ . '/../../../scripts/release-gate/playwright/playwright_cli.sh';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " . escapeshellarg($capturePath) . "\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_MCP_READY_DIR=%s bash %s run-code --help',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(1, $capturedInvocations);
            self::assertStringContainsString('playwright-cli run-code --help', $capturedInvocations[0]);
            self::assertStringNotContainsString('playwright --version', $capturedInvocations[0]);
            self::assertStringNotContainsString(' install ', $capturedInvocations[0]);
        } finally {
            if (is_file($npxPath)) {
                unlink($npxPath);
            }

            if (is_file($capturePath)) {
                unlink($capturePath);
            }

            if (is_dir($binDir)) {
                rmdir($binDir);
            }

            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testPlaywrightCliWrapperPinsOutputModeToStdout(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-output-mode-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';
        $wrapperPath = __DIR__ . '/../../../scripts/release-gate/playwright/playwright_cli.sh';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf 'mode=%s args=%s\n' \"\${PLAYWRIGHT_MCP_OUTPUT_MODE:-}\" \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.0.0\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_MCP_READY_DIR=%s PLAYWRIGHT_MCP_OUTPUT_MODE=file bash %s run-code --help',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(1, $capturedInvocations);
            self::assertStringContainsString('mode=stdout', $capturedInvocations[0]);
            self::assertStringContainsString('playwright-cli run-code --help', $capturedInvocations[0]);
        } finally {
            if (is_file($npxPath)) {
                unlink($npxPath);
            }

            if (is_file($capturePath)) {
                unlink($capturePath);
            }

            if (is_dir($binDir)) {
                rmdir($binDir);
            }

            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testPlaywrightCliWrapperInstallBrowserBootstrapsWithoutForwardingPseudoCommand(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-install-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';
        $wrapperPath = __DIR__ . '/../../../scripts/release-gate/playwright/playwright_cli.sh';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.0.0\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_MCP_READY_DIR=%s bash %s install-browser',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(2, $capturedInvocations);
            self::assertStringContainsString('--package playwright playwright --version', $capturedInvocations[0]);
            self::assertStringContainsString('--package playwright playwright install', $capturedInvocations[1]);
            self::assertStringContainsString('firefox', $capturedInvocations[1]);
            self::assertStringNotContainsString('playwright-cli install-browser', implode("\n", $capturedInvocations));
        } finally {
            if (is_file($npxPath)) {
                unlink($npxPath);
            }

            if (is_file($capturePath)) {
                unlink($capturePath);
            }

            if (is_dir($binDir)) {
                rmdir($binDir);
            }

            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testRunPwcliCommandForwardsHeadedForOpenCommands(): void
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
                    'headed' => true,
                ],
                'session-123',
                ['open', 'http://example.test'],
                5,
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $capturedArgs = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedArgs);
            self::assertContains('--headed', $capturedArgs);
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

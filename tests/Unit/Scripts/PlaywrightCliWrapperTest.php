<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class PlaywrightCliWrapperTest extends TestCase
{
    private string $wrapperPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wrapperPath = __DIR__ . '/../../../scripts/release-gate/playwright/playwright_cli.sh';
    }

    public function testWrapperSkipsBrowserInstallForHelp(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-help-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

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
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(1, $capturedInvocations);
            self::assertStringContainsString(
                '--package @playwright/cli@0.1.1 playwright-cli run-code --help',
                $capturedInvocations[0],
            );
            self::assertStringContainsString('playwright-cli run-code --help', $capturedInvocations[0]);
            self::assertStringNotContainsString('playwright --version', $capturedInvocations[0]);
            self::assertStringNotContainsString(' install ', $capturedInvocations[0]);
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperInstallBrowserBootstrapsPinnedPlaywrightPackages(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-install-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';
        $npmPath = $binDir . '/npm';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.0.0\\n'\nfi\n",
        );
        chmod($npxPath, 0777);
        file_put_contents(
            $npmPath,
            "#!/usr/bin/env bash\nset -euo pipefail\n" .
                "if [[ \"\$*\" == \"view @playwright/cli@0.1.1 dependencies.playwright --json\" ]]; then\n" .
                "  printf '[\\n  \"1.59.0-alpha-1771104257000\"\\n]\\n'\n" .
                "  exit 0\n" .
                "fi\n" .
                "exit 1\n",
        );
        chmod($npmPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_MCP_READY_DIR=%s bash %s install-browser',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(2, $capturedInvocations);
            self::assertStringContainsString(
                '--package playwright@1.59.0-alpha-1771104257000 playwright --version',
                $capturedInvocations[0],
            );
            self::assertStringContainsString(
                '--package playwright@1.59.0-alpha-1771104257000 playwright install',
                $capturedInvocations[1],
            );
            self::assertStringContainsString('--with-deps', $capturedInvocations[1]);
            self::assertStringContainsString('firefox', $capturedInvocations[1]);
            self::assertStringNotContainsString('playwright-cli install-browser', implode("\n", $capturedInvocations));
        } finally {
            @unlink($npxPath);
            @unlink($npmPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperInstallBrowserEmitsBootstrapDiagnostics(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-diagnostics-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $stderrPath = $tempDir . '/stderr.log';
        $npxPath = $binDir . '/npx';
        $readyDir = $tempDir . '/ready';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s install-browser 2>%s',
            escapeshellarg($binDir),
            escapeshellarg($readyDir),
            escapeshellarg($this->wrapperPath),
            escapeshellarg($stderrPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($stderrPath);

            $stderr = file_get_contents($stderrPath);
            self::assertIsString($stderr);
            self::assertStringContainsString('[playwright-cli] browser bootstrap:', $stderr);
            self::assertStringContainsString('browser=firefox', $stderr);
            self::assertStringContainsString('cli_package=@playwright/cli@0.1.1', $stderr);
            self::assertStringContainsString('runtime_package=playwright@1.59.0-alpha-1771104257000', $stderr);
            self::assertStringContainsString('install_mode=with-deps', $stderr);
            self::assertStringContainsString('executable_path=absent', $stderr);
            self::assertStringContainsString('ready_marker=' . $readyDir . '/', $stderr);
            self::assertStringContainsString('marker=missing', $stderr);
            self::assertStringContainsString('[playwright-cli] browser install: mode=', $stderr);
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @unlink($stderrPath);
            @rmdir($binDir);
            $this->removeDirectory($readyDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperSkipsBrowserInstallWhenExecutablePathIsPresent(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-executable-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $stderrPath = $tempDir . '/stderr.log';
        $npxPath = $binDir . '/npx';
        $executablePath = $tempDir . '/firefox-esr';
        $readyDir = $tempDir . '/ready';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " . escapeshellarg($capturePath) . "\n",
        );
        file_put_contents($executablePath, "#!/usr/bin/env bash\nexit 0\n");
        chmod($npxPath, 0777);
        chmod($executablePath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_MCP_EXECUTABLE_PATH=%s PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s install-browser 2>%s',
            escapeshellarg($binDir),
            escapeshellarg($executablePath),
            escapeshellarg($readyDir),
            escapeshellarg($this->wrapperPath),
            escapeshellarg($stderrPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileDoesNotExist($capturePath);
            self::assertFileExists($stderrPath);

            $stderr = file_get_contents($stderrPath);
            self::assertIsString($stderr);
            self::assertStringContainsString('executable_path=present', $stderr);
            self::assertStringContainsString('marker=missing', $stderr);
            self::assertStringNotContainsString('[playwright-cli] browser install:', $stderr);
        } finally {
            @unlink($npxPath);
            @unlink($executablePath);
            @unlink($capturePath);
            @unlink($stderrPath);
            $this->removeDirectory($readyDir);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperFailsEarlyWhenExecutablePathIsMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-missing-executable-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $stderrPath = $tempDir . '/stderr.log';
        $npxPath = $binDir . '/npx';
        $readyDir = $tempDir . '/ready';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " . escapeshellarg($capturePath) . "\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_MCP_EXECUTABLE_PATH=%s PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s install-browser 2>%s',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/missing-firefox-esr'),
            escapeshellarg($readyDir),
            escapeshellarg($this->wrapperPath),
            escapeshellarg($stderrPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(1, $exitCode);
            self::assertFileDoesNotExist($capturePath);
            self::assertFileExists($stderrPath);

            $stderr = file_get_contents($stderrPath);
            self::assertIsString($stderr);
            self::assertStringContainsString('executable_path=missing', $stderr);
            self::assertStringContainsString('ready_marker=not-applicable', $stderr);
            self::assertStringContainsString(
                'Error: PLAYWRIGHT_MCP_EXECUTABLE_PATH is set but is not executable.',
                $stderr,
            );
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @unlink($stderrPath);
            $this->removeDirectory($readyDir);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperInstallBrowserSupportsBrowserOnlyInstallMode(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-browser-only-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $stderrPath = $tempDir . '/stderr.log';
        $npxPath = $binDir . '/npx';
        $readyDir = $tempDir . '/ready';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_INSTALL_MODE=browser-only PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s install-browser 2>%s',
            escapeshellarg($binDir),
            escapeshellarg($readyDir),
            escapeshellarg($this->wrapperPath),
            escapeshellarg($stderrPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);
            self::assertFileExists($stderrPath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(2, $capturedInvocations);
            self::assertStringContainsString('playwright install firefox', $capturedInvocations[1]);
            self::assertStringNotContainsString('--with-deps', $capturedInvocations[1]);

            $stderr = file_get_contents($stderrPath);
            self::assertIsString($stderr);
            self::assertStringContainsString('install_mode=browser-only', $stderr);
            self::assertStringContainsString('[playwright-cli] browser install: mode=browser-only', $stderr);
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @unlink($stderrPath);
            $this->removeDirectory($readyDir);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperInstallBrowserCanUseLocalPlaywrightBinary(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-local-bin-' . bin2hex(random_bytes(4));
        $nodeBinDir = $tempDir . '/node_modules/.bin';
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/playwright.log';
        $npxCapturePath = $tempDir . '/npx.log';
        $playwrightPath = $nodeBinDir . '/playwright';
        $playwrightCliPath = $nodeBinDir . '/playwright-cli';
        $npxPath = $binDir . '/npx';
        $readyDir = $tempDir . '/ready';

        mkdir($nodeBinDir, 0777, true);
        mkdir($binDir, 0777, true);
        file_put_contents(
            $playwrightPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        file_put_contents(
            $playwrightCliPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf 'unexpected playwright-cli invocation: %s\n' \"\$*\" >&2\nexit 1\n",
        );
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($npxCapturePath) .
                "\nexit 1\n",
        );
        chmod($playwrightPath, 0777);
        chmod($playwrightCliPath, 0777);
        chmod($npxPath, 0777);

        $command = sprintf(
            'cd %s && PATH=%s:$PATH PLAYWRIGHT_USE_LOCAL_BINS=1 PLAYWRIGHT_INSTALL_MODE=browser-only PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s install-browser',
            escapeshellarg($tempDir),
            escapeshellarg($binDir),
            escapeshellarg($readyDir),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);
            self::assertFileDoesNotExist($npxCapturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(2, $capturedInvocations);
            self::assertStringContainsString('--version', $capturedInvocations[0]);
            self::assertSame('install firefox', $capturedInvocations[1]);
        } finally {
            @unlink($playwrightPath);
            @unlink($playwrightCliPath);
            @unlink($npxPath);
            @unlink($capturePath);
            @unlink($npxCapturePath);
            $this->removeDirectory($readyDir);
            @rmdir($nodeBinDir);
            @rmdir(dirname($nodeBinDir));
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperDoesNotInjectEnvSessionWhenShortSessionFlagIsPresent(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-session-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_CLI_SESSION=env-session PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s -s=cli-session open https://example.test',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(3, $capturedInvocations);
            self::assertStringContainsString('-s=cli-session open https://example.test', $capturedInvocations[2]);
            self::assertStringNotContainsString('env-session', $capturedInvocations[2]);
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperStillSkipsBrowserInstallForHelpAfterShortSessionFlag(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-session-help-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " . escapeshellarg($capturePath) . "\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_MCP_READY_DIR=%s bash %s -s=cli-session run-code --help',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(1, $capturedInvocations);
            self::assertStringContainsString('playwright-cli -s=cli-session run-code --help', $capturedInvocations[0]);
            self::assertStringNotContainsString('playwright --version', $capturedInvocations[0]);
            self::assertStringNotContainsString(' install ', $capturedInvocations[0]);
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperStillInterceptsInstallBrowserAfterShortSessionFlag(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-session-install-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_CLI_SESSION=env-session PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s -s=cli-session install-browser',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(2, $capturedInvocations);
            self::assertStringContainsString(
                '--package playwright@1.59.0-alpha-1771104257000 playwright --version',
                $capturedInvocations[0],
            );
            self::assertStringContainsString(
                '--package playwright@1.59.0-alpha-1771104257000 playwright install',
                $capturedInvocations[1],
            );
            self::assertStringNotContainsString('playwright-cli', implode("\n", $capturedInvocations));
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperIgnoresLongSessionValueWhenScanningForInstallCommand(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-long-session-install-value-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s --session install-browser open https://example.test',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(3, $capturedInvocations);
            self::assertStringContainsString(
                '--package playwright@1.59.0-alpha-1771104257000 playwright install',
                $capturedInvocations[1],
            );
            self::assertStringContainsString(
                'playwright-cli --session install-browser open https://example.test',
                $capturedInvocations[2],
            );
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperIgnoresLongSessionValueWhenScanningForHelpCommand(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-long-session-help-value-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s --session help open https://example.test',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(3, $capturedInvocations);
            self::assertStringContainsString(
                '--package playwright@1.59.0-alpha-1771104257000 playwright install',
                $capturedInvocations[1],
            );
            self::assertStringContainsString(
                'playwright-cli --session help open https://example.test',
                $capturedInvocations[2],
            );
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperDoesNotInjectEnvSessionWhenLongSessionFlagIsPresent(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-long-session-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_CLI_SESSION=env-session PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s --session cli-session open https://example.test',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(3, $capturedInvocations);
            self::assertStringContainsString(
                '--session cli-session open https://example.test',
                $capturedInvocations[2],
            );
            self::assertStringNotContainsString('env-session', $capturedInvocations[2]);
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperDoesNotInjectEnvSessionWhenLongEqualsSessionFlagIsPresent(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-long-equals-session-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_CLI_SESSION=env-session PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s --session=cli-session open https://example.test',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(3, $capturedInvocations);
            self::assertStringContainsString(
                '--session=cli-session open https://example.test',
                $capturedInvocations[2],
            );
            self::assertStringNotContainsString('env-session', $capturedInvocations[2]);
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperInjectsEnvSessionUsingShortFlagWhenMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-env-session-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_CLI_SESSION=env-session PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_READY_DIR=%s bash %s open https://example.test',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(3, $capturedInvocations);
            self::assertStringContainsString(
                '--session env-session open https://example.test',
                $capturedInvocations[2],
            );
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    public function testWrapperInstallBrowserRespectsConfiguredBrowserOverride(): void
    {
        $tempDir = sys_get_temp_dir() . '/pwcli-browser-' . bin2hex(random_bytes(4));
        $binDir = $tempDir . '/bin';
        $capturePath = $tempDir . '/npx.log';
        $npxPath = $binDir . '/npx';

        mkdir($binDir, 0777, true);
        file_put_contents(
            $npxPath,
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$*\" >> " .
                escapeshellarg($capturePath) .
                "\nif [[ \"\$*\" == *\"--version\"* ]]; then\n  printf 'Version 1.59.0-alpha-1771104257000\\n'\nfi\n",
        );
        chmod($npxPath, 0777);

        $command = sprintf(
            'PATH=%s:$PATH PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000 PLAYWRIGHT_MCP_BROWSER=" WebKit " PLAYWRIGHT_MCP_READY_DIR=%s bash %s install-browser',
            escapeshellarg($binDir),
            escapeshellarg($tempDir . '/ready'),
            escapeshellarg($this->wrapperPath),
        );
        exec($command, $output, $exitCode);

        try {
            self::assertSame(0, $exitCode);
            self::assertFileExists($capturePath);

            $capturedInvocations = file($capturePath, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($capturedInvocations);
            self::assertCount(2, $capturedInvocations);
            self::assertStringContainsString('playwright install', $capturedInvocations[1]);
            self::assertStringContainsString('webkit', $capturedInvocations[1]);
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
            @rmdir($binDir);
            @rmdir($tempDir);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}

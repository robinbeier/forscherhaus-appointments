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
            self::assertStringContainsString('firefox', $capturedInvocations[1]);
            self::assertStringNotContainsString('playwright-cli install-browser', implode("\n", $capturedInvocations));
        } finally {
            @unlink($npxPath);
            @unlink($capturePath);
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
}

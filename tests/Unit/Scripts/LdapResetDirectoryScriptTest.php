<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class LdapResetDirectoryScriptTest extends TestCase
{
    public function testResetDirectoryScriptSurfacesComposeServiceInspectionFailures(): void
    {
        $workspace = sys_get_temp_dir() . '/ldap-reset-config-failure-' . bin2hex(random_bytes(8));
        $fakeBin = $workspace . '/bin';

        mkdir($fakeBin, 0777, true);

        try {
            $dockerScript = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [[ "${1:-}" != "compose" ]]; then
              exit 1
            fi
            shift

            if [[ "${1:-}" == "-p" ]]; then
              shift 2
            fi

            if [[ "${1:-}" == "config" && "${2:-}" == "--services" ]]; then
              echo "compose config exploded" >&2
              exit 23
            fi

            exit 0
            BASH;

            file_put_contents($fakeBin . '/docker', $dockerScript);
            chmod($fakeBin . '/docker', 0755);

            $result = $this->runCommand(['bash', 'scripts/ldap/reset_directory.sh'], $this->repoRoot(), [
                'PATH' => $fakeBin . ':' . getenv('PATH'),
            ]);

            self::assertSame(23, $result['exit_code']);
            self::assertStringContainsString('Failed to inspect compose services:', $result['stderr']);
            self::assertStringContainsString('compose config exploded', $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testResetDirectoryScriptRejectsUnknownLdapServiceNames(): void
    {
        $workspace = sys_get_temp_dir() . '/ldap-reset-missing-service-' . bin2hex(random_bytes(8));
        $fakeBin = $workspace . '/bin';

        mkdir($fakeBin, 0777, true);

        try {
            $dockerScript = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [[ "${1:-}" != "compose" ]]; then
              exit 1
            fi
            shift

            if [[ "${1:-}" == "-p" ]]; then
              shift 2
            fi

            if [[ "${1:-}" == "config" && "${2:-}" == "--services" ]]; then
              printf 'mysql\nnginx\n'
              exit 0
            fi

            exit 0
            BASH;

            file_put_contents($fakeBin . '/docker', $dockerScript);
            chmod($fakeBin . '/docker', 0755);

            $result = $this->runCommand(['bash', 'scripts/ldap/reset_directory.sh'], $this->repoRoot(), [
                'PATH' => $fakeBin . ':' . getenv('PATH'),
            ]);

            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('Unknown LDAP service: openldap', $result['stderr']);
            self::assertStringContainsString('Available services:', $result['stderr']);
            self::assertStringContainsString('mysql', $result['stderr']);
            self::assertStringContainsString('nginx', $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testResetDirectoryScriptRejectsOpenldapPathOverrideBeforeStoppingService(): void
    {
        $workspace = sys_get_temp_dir() . '/ldap-reset-unsafe-path-' . bin2hex(random_bytes(8));
        $fakeBin = $workspace . '/bin';
        $marker = $workspace . '/compose-actions.log';
        $victim = $workspace . '/victim.txt';

        mkdir($fakeBin, 0777, true);
        file_put_contents($victim, 'must survive');

        try {
            $dockerScript = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "${LDAP_TEST_ACTION_LOG}"
            if [[ "${1:-}" == "compose" ]]; then
              shift
              [[ "${1:-}" == "config" && "${2:-}" == "--services" ]] && { printf 'openldap\n'; exit 0; }
            fi
            exit 0
            BASH;
            file_put_contents($fakeBin . '/docker', $dockerScript);
            chmod($fakeBin . '/docker', 0755);

            $result = $this->runCommand(['bash', 'scripts/ldap/reset_directory.sh'], $this->repoRoot(), [
                'PATH' => $fakeBin . ':' . getenv('PATH'),
                'LDAP_DATABASE_DIR' => $workspace,
                'LDAP_TEST_ACTION_LOG' => $marker,
            ]);

            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('Unsafe LDAP database', $result['stderr']);
            self::assertSame('must survive', file_get_contents($victim));
            self::assertStringNotContainsString('stop openldap', file_get_contents($marker));
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testResetDirectoryScriptFailsClosedWhenMountInspectionFailsBeforeStoppingService(): void
    {
        $workspace = dirname($this->repoRoot()) . '/ldap-reset-mount-check-' . bin2hex(random_bytes(8));
        $fakeBin = $workspace . '/bin';
        $runtimeRoot = $this->repoRoot() . '/docker/ldap-runtime/' . bin2hex(random_bytes(8));
        $marker = $workspace . '/compose-actions.log';
        $mountTool = PHP_OS_FAMILY === 'Darwin' ? 'mount' : 'findmnt';

        mkdir($fakeBin, 0777, true);
        mkdir($runtimeRoot . '/database', 0777, true);
        mkdir($runtimeRoot . '/config', 0777, true);

        try {
            $dockerScript = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "${LDAP_TEST_ACTION_LOG}"
            if [[ "${1:-}" == "compose" ]]; then
              shift
              [[ "${1:-}" == "config" && "${2:-}" == "--services" ]] && { printf 'customldap\n'; exit 0; }
            fi
            exit 0
            BASH;
            $findmntScript = <<<'BASH'
            #!/usr/bin/env bash
            exit 127
            BASH;
            file_put_contents($fakeBin . '/docker', $dockerScript);
            file_put_contents($fakeBin . '/' . $mountTool, $findmntScript);
            chmod($fakeBin . '/docker', 0755);
            chmod($fakeBin . '/' . $mountTool, 0755);

            $result = $this->runCommand(['bash', 'scripts/ldap/reset_directory.sh'], $this->repoRoot(), [
                'PATH' => $fakeBin . ':' . getenv('PATH'),
                'LDAP_SERVICE_NAME' => 'customldap',
                'LDAP_DATABASE_DIR' => $runtimeRoot . '/database',
                'LDAP_CONFIG_DIR' => $runtimeRoot . '/config',
                'LDAP_SKIP_SEED_APPLY' => '1',
                'LDAP_TEST_ACTION_LOG' => $marker,
            ]);

            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('mount inspection failed', $result['stderr']);
            self::assertStringNotContainsString('stop customldap', file_get_contents($marker));
        } finally {
            $this->removeDirectory($runtimeRoot);
            $this->removeDirectory($workspace);
        }
    }

    public function testResetDirectoryScriptRejectsMountedRuntimeTargetBeforeStoppingService(): void
    {
        $workspace = dirname($this->repoRoot()) . '/ldap-reset-mounted-target-' . bin2hex(random_bytes(8));
        $fakeBin = $workspace . '/bin';
        $runtimeRoot = $this->repoRoot() . '/docker/ldap-runtime/space runtime-' . bin2hex(random_bytes(8));
        $database = $runtimeRoot . '/database';
        $marker = $workspace . '/compose-actions.log';
        $mountTool = PHP_OS_FAMILY === 'Darwin' ? 'mount' : 'findmnt';

        mkdir($fakeBin, 0777, true);
        mkdir($database, 0777, true);
        mkdir($runtimeRoot . '/config', 0777, true);
        file_put_contents($database . '/must-survive', 'mounted data');

        try {
            $dockerScript = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "${LDAP_TEST_ACTION_LOG}"
            if [[ "${1:-}" == "compose" ]]; then
              shift
              [[ "${1:-}" == "config" && "${2:-}" == "--services" ]] && { printf 'customldap\n'; exit 0; }
            fi
            exit 0
            BASH;
            $encodedDatabase = str_replace(' ', '\\x20', $database);
            $findmntScript = PHP_OS_FAMILY === 'Darwin' ? <<<BASH
            #!/usr/bin/env bash
            printf '%s\\n' 'fake-device on {$database} (fakefs)'
            BASH : <<<BASH
            #!/usr/bin/env bash
            printf '%s\\n' '{$encodedDatabase}'
            BASH;
            file_put_contents($fakeBin . '/docker', $dockerScript);
            file_put_contents($fakeBin . '/' . $mountTool, $findmntScript);
            chmod($fakeBin . '/docker', 0755);
            chmod($fakeBin . '/' . $mountTool, 0755);

            $result = $this->runCommand(['bash', 'scripts/ldap/reset_directory.sh'], $this->repoRoot(), [
                'PATH' => $fakeBin . ':' . getenv('PATH'),
                'LDAP_SERVICE_NAME' => 'customldap',
                'LDAP_DATABASE_DIR' => $database,
                'LDAP_CONFIG_DIR' => $runtimeRoot . '/config',
                'LDAP_SKIP_SEED_APPLY' => '1',
                'LDAP_TEST_ACTION_LOG' => $marker,
            ]);

            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('mount target', $result['stderr']);
            self::assertSame('mounted data', file_get_contents($database . '/must-survive'));
            self::assertStringNotContainsString('stop customldap', file_get_contents($marker));
        } finally {
            $this->removeDirectory($runtimeRoot);
            $this->removeDirectory($workspace);
        }
    }

    public function testResetDirectoryScriptResetsSafeRuntimeFixtureAfterPreflight(): void
    {
        $workspace = dirname($this->repoRoot()) . '/ldap-reset-safe-target-' . bin2hex(random_bytes(8));
        $fakeBin = $workspace . '/bin';
        $runtimeRoot = $this->repoRoot() . '/docker/ldap-runtime/' . bin2hex(random_bytes(8));
        $database = $runtimeRoot . '/database';
        $config = $runtimeRoot . '/config';
        $marker = $workspace . '/compose-actions.log';
        $mountTool = PHP_OS_FAMILY === 'Darwin' ? 'mount' : 'findmnt';

        mkdir($fakeBin, 0777, true);
        mkdir($database . '/nested', 0777, true);
        mkdir($config . '/nested', 0777, true);
        file_put_contents($database . '/nested/data.ldif', 'fixture');
        file_put_contents($config . '/nested/config.ldif', 'fixture');

        try {
            $dockerScript = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "${LDAP_TEST_ACTION_LOG}"
            if [[ "${1:-}" == "compose" ]]; then
              shift
              [[ "${1:-}" == "config" && "${2:-}" == "--services" ]] && { printf 'customldap\n'; exit 0; }
            fi
            exit 0
            BASH;
            $mountScript = <<<'BASH'
            #!/usr/bin/env bash
            exit 0
            BASH;
            file_put_contents($fakeBin . '/docker', $dockerScript);
            file_put_contents($fakeBin . '/' . $mountTool, $mountScript);
            chmod($fakeBin . '/docker', 0755);
            chmod($fakeBin . '/' . $mountTool, 0755);

            $result = $this->runCommand(['bash', 'scripts/ldap/reset_directory.sh'], $this->repoRoot(), [
                'PATH' => $fakeBin . ':' . getenv('PATH'),
                'LDAP_SERVICE_NAME' => 'customldap',
                'LDAP_DATABASE_DIR' => $database,
                'LDAP_CONFIG_DIR' => $config,
                'LDAP_SKIP_SEED_APPLY' => '1',
                'LDAP_TEST_ACTION_LOG' => $marker,
            ]);

            self::assertSame(0, $result['exit_code']);
            self::assertStringContainsString('stop customldap', file_get_contents($marker));
            self::assertStringContainsString('up -d customldap', file_get_contents($marker));
            self::assertDirectoryExists($database);
            self::assertDirectoryExists($config);
            self::assertFileDoesNotExist($database . '/nested/data.ldif');
            self::assertFileDoesNotExist($config . '/nested/config.ldif');
        } finally {
            $this->removeDirectory($runtimeRoot);
            $this->removeDirectory($workspace);
        }
    }

    public function testResetDirectoryScriptRejectsTrailingSlashSymlinkBeforeStoppingService(): void
    {
        $workspace = dirname($this->repoRoot()) . '/ldap-reset-trailing-symlink-' . bin2hex(random_bytes(8));
        $fakeBin = $workspace . '/bin';
        $runtimeRoot = $this->repoRoot() . '/docker/ldap-runtime/' . bin2hex(random_bytes(8));
        $externalRoot = $workspace . '/external';
        $link = $runtimeRoot . '/database-link';
        $marker = $workspace . '/compose-actions.log';
        $mountTool = PHP_OS_FAMILY === 'Darwin' ? 'mount' : 'findmnt';

        mkdir($fakeBin, 0777, true);
        mkdir($runtimeRoot . '/config', 0777, true);
        mkdir($externalRoot, 0777, true);
        symlink($externalRoot, $link);

        try {
            $dockerScript = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "${LDAP_TEST_ACTION_LOG}"
            if [[ "${1:-}" == "compose" ]]; then
              shift
              [[ "${1:-}" == "config" && "${2:-}" == "--services" ]] && { printf 'customldap\n'; exit 0; }
            fi
            exit 0
            BASH;
            $mountScript = <<<'BASH'
            #!/usr/bin/env bash
            exit 0
            BASH;
            file_put_contents($fakeBin . '/docker', $dockerScript);
            file_put_contents($fakeBin . '/' . $mountTool, $mountScript);
            chmod($fakeBin . '/docker', 0755);
            chmod($fakeBin . '/' . $mountTool, 0755);

            $result = $this->runCommand(['bash', 'scripts/ldap/reset_directory.sh'], $this->repoRoot(), [
                'PATH' => $fakeBin . ':' . getenv('PATH'),
                'LDAP_SERVICE_NAME' => 'customldap',
                'LDAP_DATABASE_DIR' => $link . '/',
                'LDAP_CONFIG_DIR' => $runtimeRoot . '/config',
                'LDAP_SKIP_SEED_APPLY' => '1',
                'LDAP_TEST_ACTION_LOG' => $marker,
            ]);

            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('ambiguous traversal path', $result['stderr']);
            self::assertStringNotContainsString('stop customldap', file_get_contents($marker));
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $this->removeDirectory($runtimeRoot);
            $this->removeDirectory($workspace);
        }
    }

    public function testResetDirectoryScriptRejectsEscapedLinuxMountTargetWithSpace(): void
    {
        $workspace = dirname($this->repoRoot()) . '/ldap-reset-linux-mount-' . bin2hex(random_bytes(8));
        $fakeBin = $workspace . '/bin';
        $runtimeRoot = $this->repoRoot() . '/docker/ldap-runtime/linux space-' . bin2hex(random_bytes(8));
        $database = $runtimeRoot . '/database';
        $config = $runtimeRoot . '/config';
        $marker = $workspace . '/compose-actions.log';
        $encodedDatabase = str_replace(' ', '\\x20', $database);

        mkdir($fakeBin, 0777, true);
        mkdir($database, 0777, true);
        mkdir($config, 0777, true);
        file_put_contents($database . '/must-survive', 'mounted data');

        try {
            $dockerScript = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "${LDAP_TEST_ACTION_LOG}"
            if [[ "${1:-}" == "compose" ]]; then
              shift
              [[ "${1:-}" == "config" && "${2:-}" == "--services" ]] && { printf 'customldap\n'; exit 0; }
            fi
            exit 0
            BASH;
            $unameScript = <<<'BASH'
            #!/usr/bin/env bash
            printf 'Linux\n'
            BASH;
            $statScript = <<<'BASH'
            #!/usr/bin/env bash
            printf '1\n'
            BASH;
            $findmntScript = <<<BASH
            #!/usr/bin/env bash
            printf '%s\\n' '{$encodedDatabase}'
            BASH;
            file_put_contents($fakeBin . '/docker', $dockerScript);
            file_put_contents($fakeBin . '/uname', $unameScript);
            file_put_contents($fakeBin . '/stat', $statScript);
            file_put_contents($fakeBin . '/findmnt', $findmntScript);
            chmod($fakeBin . '/docker', 0755);
            chmod($fakeBin . '/uname', 0755);
            chmod($fakeBin . '/stat', 0755);
            chmod($fakeBin . '/findmnt', 0755);

            $result = $this->runCommand(['bash', 'scripts/ldap/reset_directory.sh'], $this->repoRoot(), [
                'PATH' => $fakeBin . ':' . getenv('PATH'),
                'LDAP_SERVICE_NAME' => 'customldap',
                'LDAP_DATABASE_DIR' => $database,
                'LDAP_CONFIG_DIR' => $config,
                'LDAP_SKIP_SEED_APPLY' => '1',
                'LDAP_TEST_ACTION_LOG' => $marker,
            ]);

            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('mount target', $result['stderr']);
            self::assertSame('mounted data', file_get_contents($database . '/must-survive'));
            self::assertStringNotContainsString('stop customldap', file_get_contents($marker));
        } finally {
            $this->removeDirectory($runtimeRoot);
            $this->removeDirectory($workspace);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, ?string $cwd = null, array $env = []): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, array_merge($_ENV, $env));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}

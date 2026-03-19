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

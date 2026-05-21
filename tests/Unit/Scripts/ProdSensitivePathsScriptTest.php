<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProdSensitivePathsScriptTest extends TestCase
{
    public function testSensitivePathCheckFailsOnPublicHttpSuccessWithoutPrintingPaths(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-sensitive-paths-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/prod_sensitive_paths.sh; prod_sensitive_paths_check_all https://example.test; printf "failures=%s\n" "$PROD_SENSITIVE_PATH_FAILURES"',
                ],
                $this->repoRoot(),
                $this->commandEnv($stubBin, $curlLog, 200),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('sensitive_path.storage_root=200', $result['stdout']);
            self::assertStringContainsString('failures=8', $result['stdout']);
            self::assertStringContainsString('FAIL sensitive_path.storage_root public_http_200', $result['stderr']);
            self::assertStringNotContainsString('/storage/', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('/config.php', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('sensitive body', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testSensitivePathCheckPassesOnDeniedResponses(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-sensitive-paths-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/prod_sensitive_paths.sh; prod_sensitive_paths_check_all https://example.test; printf "failures=%s\n" "$PROD_SENSITIVE_PATH_FAILURES"',
                ],
                $this->repoRoot(),
                $this->commandEnv($stubBin, $curlLog, 403),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('sensitive_path.storage_root=403', $result['stdout']);
            self::assertStringContainsString('failures=0', $result['stdout']);
            self::assertSame('', $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testProdDoctorStreamsSensitivePathHelper(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-sensitive-paths-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);
            $this->writeSshStub($stubBin);

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_doctor.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                $this->commandEnv($stubBin, $curlLog, 403),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('sensitive_path.storage_root=403', $result['stdout']);
            self::assertStringContainsString('sensitive_path_failures=0', $result['stdout']);
            self::assertStringNotContainsString('helper_missing', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('/storage/', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testProdValidateStreamsSensitivePathHelper(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-sensitive-paths-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);
            $this->writeSshStub($stubBin);

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_validate_after_change.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                $this->commandEnv($stubBin, $curlLog, 403),
            );

            self::assertNotSame(127, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('sensitive_path.storage_root=403', $result['stdout']);
            self::assertStringNotContainsString('helper_missing', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('/storage/', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function writeCurlStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/curl',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            output_file=''
            url=''

            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o)
                        output_file="$2"
                        shift 2
                        ;;
                    -H)
                        shift 2
                        ;;
                    -w|--max-time)
                        shift 2
                        ;;
                    -sS)
                        shift
                        ;;
                    *)
                        url="$1"
                        shift
                        ;;
                esac
            done

            printf '%s\n' "$url" >> "${CURL_LOG:?}"
            printf 'sensitive body' > "$output_file"
            printf '%s' "${CURL_STATUS:?}"
            BASH
            ,
        );
        chmod($stubBin . '/curl', 0755);
    }

    private function writeSshStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            remote_cmd=''
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o)
                        shift 2
                        ;;
                    *)
                        remote_cmd="$1"
                        shift
                        ;;
                esac
            done

            if [[ -z "$remote_cmd" ]]; then
                remote_cmd='bash -s'
            fi

            bash -c "$remote_cmd"
            BASH
            ,
        );
        chmod($stubBin . '/ssh', 0755);
    }

    /**
     * @return array<string, string>
     */
    private function commandEnv(string $stubBin, string $curlLog, int $statusCode): array
    {
        return [
            'CURL_LOG' => $curlLog,
            'CURL_STATUS' => (string) $statusCode,
            'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
        ];
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd, array $env = []): array
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

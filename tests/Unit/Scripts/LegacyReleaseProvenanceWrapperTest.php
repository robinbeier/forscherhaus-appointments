<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class LegacyReleaseProvenanceWrapperTest extends TestCase
{
    private string $workspace;
    private string $stubBin;
    private string $sshLog;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/legacy-provenance-wrapper-' . bin2hex(random_bytes(8));
        $this->stubBin = $this->workspace . '/bin';
        $this->sshLog = $this->workspace . '/ssh.log';
        self::assertTrue(mkdir($this->stubBin, 0700, true));
        $this->writeSshStub();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function testDefaultIsReadOnlyAndUsesTheFixedInstalledHelper(): void
    {
        $result = $this->runWrapper(['--prod-ssh-target', 'operator.example']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('mode       : read-only', $result['stdout']);
        self::assertStringContainsString('"mode":"inspect"', $result['stdout']);
        self::assertSame(
            'operator.example /usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-provenance-v1 inspect',
            trim((string) file_get_contents($this->sshLog)),
        );
    }

    public function testExecuteRequiresExactConfirmationAndPassesOnlyExecute(): void
    {
        foreach (
            [
                ['--execute'],
                ['--confirm-live-write', 'ROB-467'],
                ['--confirm-live-write', 'ROB-468'],
                ['--execute', '--confirm-live-write', 'ROB-468', '--execute'],
            ]
            as $arguments
        ) {
            $result = $this->runWrapper($arguments);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('', (string) file_get_contents($this->sshLog));
        }

        $result = $this->runWrapper([
            '--prod-ssh-target',
            'operator.example',
            '--execute',
            '--confirm-live-write',
            'ROB-468',
        ]);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('mode       : execute', $result['stdout']);
        self::assertStringContainsString('"mode":"execute"', $result['stdout']);
        self::assertSame(
            'operator.example /usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-provenance-v1 execute ROB-468',
            trim((string) file_get_contents($this->sshLog)),
        );
    }

    public function testAuthorizationModesAreSeparateAndUseExactToken(): void
    {
        $inspect = $this->runWrapper(['--prod-ssh-target', 'operator.example', '--inspect-authorization']);
        self::assertSame(0, $inspect['exit_code'], $inspect['stderr']);
        self::assertStringContainsString(
            'operator.example /usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-provenance-v1 inspect-authorization',
            trim((string) file_get_contents($this->sshLog)),
        );

        foreach (
            [
                ['--provision-authorization'],
                ['--provision-authorization', '--confirm-authorization', 'ROB-468'],
                ['--provision-authorization', '--confirm-authorization', 'ROB-468-AUTHORIZATION', '--execute'],
                [
                    '--confirm-live-write',
                    'ROB-468',
                    '--provision-authorization',
                    '--confirm-authorization',
                    'ROB-468-AUTHORIZATION',
                ],
                ['--confirm-authorization', 'ROB-468-AUTHORIZATION', '--execute', '--confirm-live-write', 'ROB-468'],
            ]
            as $arguments
        ) {
            $result = $this->runWrapper($arguments);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('', (string) file_get_contents($this->sshLog));
        }

        $provision = $this->runWrapper([
            '--prod-ssh-target',
            'operator.example',
            '--provision-authorization',
            '--confirm-authorization',
            'ROB-468-AUTHORIZATION',
        ]);
        self::assertSame(0, $provision['exit_code'], $provision['stderr']);
        self::assertStringContainsString(
            'operator.example /usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-provenance-v1 provision-authorization ROB-468-AUTHORIZATION',
            trim((string) file_get_contents($this->sshLog)),
        );
    }

    public function testUnknownAndCallerSuppliedSensitiveOptionsAreRejectedWithoutSsh(): void
    {
        foreach (
            [
                ['--unknown'],
                ['--prod-ssh-target', '-oProxyCommand=caller-command'],
                ['--release-id', 'ea_caller'],
                ['--sha256', str_repeat('a', 64)],
                ['--commit', str_repeat('b', 40)],
                ['--member', 'deploy_ea.sh'],
                ['--temp-path', '/tmp/caller-temp'],
                ['--execute', '--confirm-live-write=ROB-468'],
                ['--execute', '--confirm-live-write', 'ROB-468', '--path', '/tmp/x'],
            ]
            as $arguments
        ) {
            $result = $this->runWrapper($arguments);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame('', (string) file_get_contents($this->sshLog));
        }
    }

    public function testTransportFailureIsAggregateAndExecuteIsConservativelyUnknown(): void
    {
        $inspect = $this->runWrapper(['--prod-ssh-target', 'transport.example']);
        self::assertSame(70, $inspect['exit_code']);
        self::assertStringNotContainsString('stub transport detail', $inspect['stderr'] . $inspect['stdout']);
        $inspectResult = $this->lastJsonLine($inspect['stdout']);
        self::assertSame('none', $inspectResult['mutation_outcome']);
        self::assertSame('transport_result_unavailable', $inspectResult['reason']);

        $execute = $this->runWrapper([
            '--prod-ssh-target',
            'transport.example',
            '--execute',
            '--confirm-live-write',
            'ROB-468',
        ]);
        self::assertSame(70, $execute['exit_code']);
        $executeResult = $this->lastJsonLine($execute['stdout']);
        self::assertSame('unknown', $executeResult['mutation_outcome']);
        self::assertSame('transport_result_unavailable', $executeResult['reason']);
        self::assertSame(
            ['sidecars_published' => 0, 'temp_files_created' => 0, 'temp_files_removed' => 0],
            $executeResult['mutation_counts'],
        );

        $authInspect = $this->runWrapper(['--prod-ssh-target', 'transport.example', '--inspect-authorization']);
        self::assertSame(70, $authInspect['exit_code']);
        $authInspectResult = $this->lastJsonLine($authInspect['stdout']);
        self::assertSame('none', $authInspectResult['mutation_outcome']);
        self::assertSame('inspect-authorization', $authInspectResult['mode']);

        $authProvision = $this->runWrapper([
            '--prod-ssh-target',
            'transport.example',
            '--provision-authorization',
            '--confirm-authorization',
            'ROB-468-AUTHORIZATION',
        ]);
        self::assertSame(70, $authProvision['exit_code']);
        $authProvisionResult = $this->lastJsonLine($authProvision['stdout']);
        self::assertSame('unknown', $authProvisionResult['mutation_outcome']);
        self::assertSame('provision-authorization', $authProvisionResult['mode']);
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments): array
    {
        file_put_contents($this->sshLog, '');
        $command = array_merge(['bash', 'scripts/ops/prod_legacy_release_provenance.sh'], $arguments);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            dirname(__DIR__, 3),
            array_merge($_ENV, [
                'PATH' => $this->stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                'SSH_LOG' => $this->sshLog,
            ]),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    private function writeSshStub(): void
    {
        file_put_contents(
            $this->stubBin . '/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            args=()
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o) shift 2 ;;
                    *) args+=("$1"); shift ;;
                esac
            done
            printf '%s' "${args[*]}" > "${SSH_LOG}"
            if [[ "${args[0]:-}" == "transport.example" ]]; then
                printf 'stub transport detail\n' >&2
                exit 255
            fi
            if [[ "${args[0]:-}" != "operator.example" ]]; then
                printf 'unexpected ssh target\n' >&2
                exit 1
            fi
            if [[ "${args[5]:-}" == "inspect-authorization" || "${args[5]:-}" == "provision-authorization" ]]; then
                printf '{"authorization":{"attached":0,"pending":1,"published":0},"mode":"%s","mutation_counts":{"authorization_published":0,"temp_files_created":0,"temp_files_removed":0},"mutation_outcome":"none","schema":"legacy_release_provenance_authorization_result.v1","status":"pass","targets":{"preflighted":2}}\n' "${args[5]}"
            else
                printf '{"mode":"%s","mutation_counts":{"sidecars_published":0,"temp_files_created":0,"temp_files_removed":0},"mutation_outcome":"none","schema":"legacy_release_provenance_result.v1","status":"pass","targets":{"attached":0,"pending":2,"preflighted":2,"published":0}}\n' "${args[5]:-missing}"
            fi
            BASH
            ,
        );
        self::assertTrue(chmod($this->stubBin . '/ssh', 0750));
    }

    /** @return array<string, mixed> */
    private function lastJsonLine(string $output): array
    {
        $lines = array_values(array_filter(explode("\n", trim($output))));
        $decoded = json_decode((string) end($lines), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

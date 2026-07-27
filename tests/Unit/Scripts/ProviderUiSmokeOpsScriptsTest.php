<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProviderUiSmokeOpsScriptsTest extends TestCase
{
    public function testPrincipalWrapperProvidesGlobalHelpWithoutRoot(): void
    {
        $result = $this->runCommand(
            ['bash', 'scripts/ops/provider_ui_smoke_principal.sh', '--help'],
            $this->repoRoot(),
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('Server-local, root-only lifecycle wrapper', $result['stdout']);
        self::assertStringNotContainsString('PROVIDER_UI_SMOKE_PASSWORD=', $result['stdout']);
    }

    public function testOrchestratorRejectsNonNormalizedRemotePathsBeforeNetworkAccess(): void
    {
        $result = $this->runCommand(
            ['bash', 'scripts/ops/prod_provider_ui_smoke.sh', '--app-root', '/var/www/../unsafe'],
            $this->repoRoot(),
        );

        self::assertSame(64, $result['exit_code']);
        self::assertStringContainsString('remote path is not normalized', $result['stderr']);
    }

    public function testCleanupMasksEveryHandledSignalUntilFinallyCompletes(): void
    {
        $source = file_get_contents($this->repoRoot() . '/scripts/ops/prod_provider_ui_smoke.sh');

        self::assertIsString($source);
        self::assertStringContainsString('trap - EXIT HUP INT TERM', $source);
    }

    public function testOrchestratorSelectsAndForwardsAnExplicitBrowser(): void
    {
        $source = file_get_contents($this->repoRoot() . '/scripts/ops/prod_provider_ui_smoke.sh');

        self::assertIsString($source);
        self::assertStringContainsString("DEFAULT_BROWSER='chrome'", $source);
        self::assertStringContainsString("DEFAULT_BROWSER='firefox'", $source);
        self::assertStringContainsString('export PLAYWRIGHT_MCP_BROWSER="${BROWSER}"', $source);
        self::assertStringContainsString('"--browser=${BROWSER}"', $source);
    }

    public function testGateFailureStillDeactivatesVerifiesAndDisarmsCleanupLease(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $result = $this->runOrchestrator($workspace, ['PROD_SMOKE_GATE_EXIT' => '1']);

            self::assertSame(1, $result['exit_code'], $result['stderr']);
            $events = (string) file_get_contents($workspace . '/events.log');
            self::assertMatchesRegularExpression(
                '/preflight.*verify.*arm.*activate.*credential_stream.*gate.*deactivate.*verify.*disarm/s',
                $events,
            );
            self::assertStringNotContainsString('provider-ui-test-secret', $events);
            self::assertStringNotContainsString('provider-ui-test-secret', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('HARD STOP', $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testCleanupFailureOverridesGateResultAndLeavesTimerArmed(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $result = $this->runOrchestrator($workspace, [
                'PROD_SMOKE_GATE_EXIT' => '0',
                'PROD_SMOKE_DEACTIVATE_FAIL' => '1',
            ]);

            self::assertSame(90, $result['exit_code']);
            self::assertStringContainsString('HARD STOP', $result['stderr']);
            self::assertStringContainsString('Keep the guarded application release active', $result['stderr']);

            $events = (string) file_get_contents($workspace . '/events.log');
            self::assertStringContainsString("deactivate\n", $events);
            self::assertStringNotContainsString("disarm\n", $events);
            self::assertStringNotContainsString('provider-ui-test-secret', $events);
            self::assertStringNotContainsString('provider-ui-test-secret', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runOrchestrator(string $workspace, array $extraEnv): array
    {
        $stubBin = $workspace . '/bin';
        $pwcli = $workspace . '/playwright_cli.sh';
        $events = $workspace . '/events.log';

        return $this->runCommand(
            [
                'bash',
                'scripts/ops/prod_provider_ui_smoke.sh',
                '--prod-ssh-target',
                'prod.example',
                '--base-url',
                'https://example.test',
                '--pwcli-path',
                $pwcli,
            ],
            $this->repoRoot(),
            array_merge(
                [
                    'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'PROD_SMOKE_TEST_EVENTS' => $events,
                    'PROVIDER_UI_SMOKE_PHP_BIN' => $stubBin . '/php',
                    'PROVIDER_UI_SMOKE_CURL_BIN' => $stubBin . '/curl',
                    'PROVIDER_UI_SMOKE_NPX_BIN' => $stubBin . '/npx',
                    'PROVIDER_UI_SMOKE_PDFINFO_BIN' => $stubBin . '/pdfinfo',
                    'PROVIDER_UI_SMOKE_PDFTOTEXT_BIN' => $stubBin . '/pdftotext',
                ],
                $extraEnv,
            ),
        );
    }

    private function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . '/provider-ui-smoke-ops-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';

        mkdir($stubBin, 0700, true);
        file_put_contents($workspace . '/events.log', '');

        $this->writeExecutable(
            $workspace . '/playwright_cli.sh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf 'browser_bootstrap\n' >>"${PROD_SMOKE_TEST_EVENTS}"
            BASH
            ,
        );
        $this->writeExecutable(
            $stubBin . '/curl',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf 'endpoint\n' >>"${PROD_SMOKE_TEST_EVENTS}"
            BASH
            ,
        );
        foreach (['npx', 'pdfinfo', 'pdftotext'] as $command) {
            $this->writeExecutable(
                $stubBin . '/' . $command,
                <<<'BASH'
                #!/usr/bin/env bash
                exit 0
                BASH
                ,
            );
        }
        $this->writeExecutable(
            $stubBin . '/php',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [[ "${1:-}" == '-l' ]]; then
                exit 0
            fi

            credential_input="$(cat)"
            [[ "${credential_input}" == *'PROVIDER_UI_SMOKE_USERNAME=__ea_provider_ui_smoke_v1'* ]]
            [[ "${credential_input}" == *'PROVIDER_UI_SMOKE_PASSWORD=provider-ui-test-secret'* ]]
            printf 'gate\n' >>"${PROD_SMOKE_TEST_EVENTS}"
            exit "${PROD_SMOKE_GATE_EXIT:-0}"
            BASH
            ,
        );
        $this->writeExecutable(
            $stubBin . '/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o)
                        shift 2
                        ;;
                    prod.example)
                        shift
                        ;;
                    *)
                        break
                        ;;
                esac
            done
            remote_command="${1:-}"

            if [[ "${remote_command}" == *'remote_preflight=passed'* ]]; then
                printf 'preflight\n' >>"${PROD_SMOKE_TEST_EVENTS}"
                printf 'remote_preflight=passed host_node_npm=absent cleanup_lease=inactive\n'
            elif [[ "${remote_command}" == *"provider_ui_smoke_principal.sh' 'verify'"* ]]; then
                printf 'verify\n' >>"${PROD_SMOKE_TEST_EVENTS}"
                printf 'provider_ui_smoke_wrapper action=verify result=ok\n'
            elif [[ "${remote_command}" == *'systemd-run'* ]]; then
                printf 'arm\n' >>"${PROD_SMOKE_TEST_EVENTS}"
                printf 'cleanup_lease=armed duration=10m\n'
            elif [[ "${remote_command}" == *"provider_ui_smoke_principal.sh' 'activate'"* ]]; then
                printf 'activate\n' >>"${PROD_SMOKE_TEST_EVENTS}"
                printf 'provider_ui_smoke_wrapper action=activate result=ok\n'
            elif [[ "${remote_command}" == *'exec cat --'* ]]; then
                printf 'credential_stream\n' >>"${PROD_SMOKE_TEST_EVENTS}"
                printf '%s\n' \
                    'PROVIDER_UI_SMOKE_USERNAME=__ea_provider_ui_smoke_v1' \
                    'PROVIDER_UI_SMOKE_PASSWORD=provider-ui-test-secret'
            elif [[ "${remote_command}" == *"provider_ui_smoke_principal.sh' 'deactivate'"* ]]; then
                printf 'deactivate\n' >>"${PROD_SMOKE_TEST_EVENTS}"
                if [[ "${PROD_SMOKE_DEACTIVATE_FAIL:-0}" == '1' ]]; then
                    exit 1
                fi
                printf 'provider_ui_smoke_wrapper action=deactivate result=ok\n'
            elif [[ "${remote_command}" == *'cleanup_lease=disarmed'* ]]; then
                printf 'disarm\n' >>"${PROD_SMOKE_TEST_EVENTS}"
                printf 'cleanup_lease=disarmed\n'
            else
                printf 'ERROR: unexpected ssh invocation\n' >&2
                exit 99
            fi
            BASH
            ,
        );

        return $workspace;
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0755);
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

        return [
            'exit_code' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
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
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

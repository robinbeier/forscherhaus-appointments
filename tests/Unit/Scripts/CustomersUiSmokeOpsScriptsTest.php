<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ReleaseArtifactValidator;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ReleaseArtifactValidator.php';

final class CustomersUiSmokeOpsScriptsTest extends TestCase
{
    public function testBothWrappersExposeOnlySafeHelpWithoutRemoteAccess(): void
    {
        foreach (
            [
                ['bash', 'scripts/ops/customers_ui_smoke_principals.sh', '--help'],
                ['bash', 'scripts/ops/prod_customers_ui_smoke.sh', '--help'],
            ]
            as $command
        ) {
            $result = $this->runCommand($command);
            self::assertSame(0, $result['exit_code'], $result['output']);
            self::assertStringContainsString('Customers UI smoke', $result['output']);
            self::assertStringNotContainsString('CUSTOMERS_UI_SMOKE_PASSWORD=', $result['output']);
        }
    }

    public function testOperatorWrapperRejectsTraversalBeforeSsh(): void
    {
        $result = $this->runCommand([
            'bash',
            'scripts/ops/prod_customers_ui_smoke.sh',
            '--app-root',
            '/var/www/../unsafe',
        ]);

        self::assertSame(64, $result['exit_code']);
        self::assertStringContainsString('remote path is not normalized', $result['output']);
    }

    public function testOperatorContractHashesMatchThroughRealLocalAndRemotePhpCliForms(): void
    {
        $result = $this->runOperatorContractHashHarness(dirname(__DIR__, 3));

        self::assertSame(22, $result['exit_code'], $result['output']);
        self::assertStringContainsString('independent cleanup timer could not be armed', $result['output']);
        self::assertStringContainsString('remote_hash_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringContainsString('cleanup_arm_stopped' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('contract bundle could not be hashed', $result['output']);
        self::assertStringNotContainsString('deployed Customers contract does not match', $result['output']);
    }

    public function testOperatorRemoteContractHashFailsClosedForMissingRoot(): void
    {
        $missingRoot = sys_get_temp_dir() . '/rob441-missing-' . bin2hex(random_bytes(8));
        $result = $this->runOperatorContractHashHarness($missingRoot);

        self::assertSame(20, $result['exit_code'], $result['output']);
        self::assertStringContainsString('deployed contract bundle could not be hashed', $result['output']);
        self::assertStringContainsString('remote_hash_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('cleanup_arm_stopped' . PHP_EOL, $result['ssh_log']);
    }

    public function testReleaseGateRejectsMissingConfigurationWithoutStartingRuntime(): void
    {
        $result = $this->runCommand(['php', 'scripts/release-gate/customers_ui_smoke.php']);

        self::assertSame(2, $result['exit_code']);
        self::assertSame(
            'customers_ui_smoke status=fail failure_code=runtime_error cleanup=pass' . PHP_EOL,
            $result['output'],
        );
    }

    public function testOperatorSourceArmsIndependentCleanupBeforeActivationAndAlwaysVerifiesDormant(): void
    {
        $source = $this->read('scripts/ops/prod_customers_ui_smoke.sh');
        $arm = strpos($source, 'arm_remote_cleanup');
        $activate = strrpos($source, 'remote_principal activate');

        self::assertIsInt($arm);
        self::assertIsInt($activate);
        self::assertLessThan($activate, $arm);
        self::assertStringContainsString("readonly CLEANUP_UNIT='fh-customers-ui-smoke-cleanup'", $source);
        self::assertStringContainsString('--on-active=10m', $source);
        self::assertStringContainsString('trap cleanup_remote_lease EXIT', $source);
        self::assertStringContainsString('remote_principal deactivate', $source);
        self::assertStringContainsString('remote_principal verify', $source);
        self::assertStringContainsString('exec cat -- \'${CREDENTIALS_FILE}\'', $source);
        self::assertStringNotContainsString('source "${CREDENTIALS_FILE}"', $source);
        self::assertStringNotContainsString('sshpass', $source);
    }

    public function testOperatorContractBindsCustomersRuntimeAssetsRequiredByReleaseArtifact(): void
    {
        $source = $this->read('scripts/ops/prod_customers_ui_smoke.sh');
        self::assertSame(1, preg_match('/readonly CONTRACT_PATHS=\((?<paths>.*?)\n\)/s', $source, $match));
        $pathCount = preg_match_all("/^\\s+'([^']+)'$/m", $match['paths'], $pathMatches);
        self::assertIsInt($pathCount);
        self::assertGreaterThan(0, $pathCount);

        $contractPaths = $pathMatches[1];
        $releasePaths = ReleaseArtifactValidator::requiredPaths();

        foreach (['assets/js/http/customers_http_client.min.js', 'assets/js/pages/customers.min.js'] as $runtimeAsset) {
            self::assertContains($runtimeAsset, $contractPaths);
            self::assertContains($runtimeAsset, $releasePaths);
        }
    }

    public function testPrincipalWrapperKeepsCredentialOutOfArgumentsAndLogs(): void
    {
        $source = $this->read('scripts/ops/customers_ui_smoke_principals.sh');

        self::assertStringContainsString('set +x', $source);
        self::assertStringContainsString('umask 077', $source);
        self::assertStringContainsString("chmod 0600 \"\${ACTIVE_TEMP_FILE}\"", $source);
        self::assertStringContainsString('php index.php console customers_ui_smoke', $source);
        self::assertStringContainsString('application lifecycle command returned a failure', $source);
        self::assertStringNotContainsString('cat "${CREDENTIALS_FILE}"', $source);
        self::assertStringNotContainsString('set -x', $source);
    }

    /**
     * @param list<string> $command
     * @return array{exit_code:int,output:string}
     */
    private function runCommand(array $command, ?array $environment = null): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 3), $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'output' => ($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr),
        ];
    }

    /**
     * @return array{exit_code:int,output:string,ssh_log:string}
     */
    private function runOperatorContractHashHarness(string $remoteRoot): array
    {
        $harnessDir = sys_get_temp_dir() . '/rob441-contract-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($harnessDir, 0700));
        $sshLog = $harnessDir . '/ssh.log';
        $sshPath = $harnessDir . '/ssh';
        $curlPath = $harnessDir . '/curl';
        $npxPath = $harnessDir . '/npx';
        $pwcliPath = $harnessDir . '/playwright_cli.sh';

        $this->writeExecutable(
            $sshPath,
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            remote_command="${!#}"
            if [[ "${remote_command}" == *'exec php -r'* ]]; then
                printf 'remote_hash_executed\n' >> "${ROB441_SSH_LOG}"
                exec bash -c "${remote_command}"
            fi
            if [[ "${remote_command}" == *'--on-active=10m'* ]]; then
                printf 'cleanup_arm_stopped\n' >> "${ROB441_SSH_LOG}"
                exit 75
            fi
            exit 0
            BASH
            ,
        );
        $this->writeExecutable($curlPath, "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable($npxPath, "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable($pwcliPath, "#!/usr/bin/env bash\nexit 0\n");

        $baseEnvironment = getenv();
        self::assertIsArray($baseEnvironment);
        $path = $harnessDir . PATH_SEPARATOR . ($baseEnvironment['PATH'] ?? '');

        try {
            $result = $this->runCommand(
                [
                    'bash',
                    'scripts/ops/prod_customers_ui_smoke.sh',
                    '--prod-ssh-target',
                    'root@test.invalid',
                    '--app-root',
                    $remoteRoot,
                    '--pwcli-path',
                    $pwcliPath,
                ],
                array_merge($baseEnvironment, [
                    'PATH' => $path,
                    'ROB441_SSH_LOG' => $sshLog,
                    'CUSTOMERS_UI_SMOKE_PHP_BIN' => PHP_BINARY,
                    'CUSTOMERS_UI_SMOKE_CURL_BIN' => $curlPath,
                    'CUSTOMERS_UI_SMOKE_NPX_BIN' => $npxPath,
                ]),
            );
            $sshLogContent = file_exists($sshLog) ? file_get_contents($sshLog) : '';
            self::assertIsString($sshLogContent);

            return $result + ['ssh_log' => $sshLogContent];
        } finally {
            foreach ([$sshLog, $sshPath, $curlPath, $npxPath, $pwcliPath] as $pathToRemove) {
                if (file_exists($pathToRemove)) {
                    self::assertTrue(unlink($pathToRemove));
                }
            }
            self::assertTrue(rmdir($harnessDir));
        }
    }

    private function writeExecutable(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0700));
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($content);

        return $content;
    }
}

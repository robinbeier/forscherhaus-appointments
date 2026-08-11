<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ReleaseArtifactValidator;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ReleaseArtifactValidator.php';

final class CustomersUiSmokeOpsScriptsTest extends TestCase
{
    private const CONTRACT_ASSET_FIXTURES = [
        'assets/js/http/customers_http_client.min.js' => "rob441-customers-http-client-fixture\n",
        'assets/js/pages/customers.min.js' => "rob441-customers-page-fixture\n",
    ];

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
        self::assertStringContainsString('traffic_gate_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringContainsString('endpoint_curl_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringContainsString('cleanup_arm_stopped' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('contract bundle could not be hashed', $result['output']);
        self::assertStringNotContainsString('deployed Customers contract does not match', $result['output']);
        self::assertLessThan(
            strpos($result['ssh_log'], 'traffic_gate_executed'),
            strpos($result['ssh_log'], 'remote_hash_executed'),
        );
    }

    public function testOperatorRemoteContractHashFailsClosedForMissingRoot(): void
    {
        $missingRoot = sys_get_temp_dir() . '/rob441-missing-' . bin2hex(random_bytes(8));
        $result = $this->runOperatorContractHashHarness($missingRoot);

        self::assertSame(20, $result['exit_code'], $result['output']);
        self::assertStringContainsString('deployed contract bundle could not be hashed', $result['output']);
        self::assertStringContainsString('remote_hash_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('traffic_gate_executed' . PHP_EOL, $result['ssh_log']);
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
        self::assertSame(1, preg_match('/main\(\) \{(?<body>.*?)\n\}\n\nmain "\$@"/s', $source, $match));
        $main = $match['body'];
        $contract = strpos($main, 'remote_contract_sha256');
        $traffic = strpos($main, 'remote_traffic_gate');
        $endpoint = strpos($main, 'local_endpoint_preflight');
        $arm = strpos($main, 'arm_remote_cleanup');
        $activate = strpos($main, 'remote_principal activate');

        self::assertIsInt($contract);
        self::assertIsInt($traffic);
        self::assertIsInt($endpoint);
        self::assertIsInt($arm);
        self::assertIsInt($activate);
        self::assertLessThan($traffic, $contract);
        self::assertLessThan($endpoint, $traffic);
        self::assertLessThan($arm, $endpoint);
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

    public function testTrafficGateFailurePreventsEndpointProbeTimerAndPrincipalActivation(): void
    {
        $result = $this->runOperatorContractHashHarness(dirname(__DIR__, 3), 20);

        self::assertSame(20, $result['exit_code'], $result['output']);
        self::assertStringContainsString('traffic_gate_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('endpoint_curl_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('cleanup_arm_stopped' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('principal_activate' . PHP_EOL, $result['ssh_log']);
    }

    public function testTrafficGateRejectsUnknownV1FieldsBeforeEndpointProbe(): void
    {
        $result = $this->runOperatorContractHashHarness(dirname(__DIR__, 3), 0, true);

        self::assertSame(20, $result['exit_code'], $result['output']);
        self::assertStringContainsString('traffic_gate_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('endpoint_curl_executed' . PHP_EOL, $result['ssh_log']);
        self::assertStringNotContainsString('cleanup_arm_stopped' . PHP_EOL, $result['ssh_log']);
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
    private function runOperatorContractHashHarness(
        string $remoteRoot,
        int $trafficGateExit = 0,
        bool $extraTrafficField = false,
    ): array {
        $harnessDir = sys_get_temp_dir() . '/rob441-contract-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($harnessDir, 0700));
        $fixtureRepoRoot = $harnessDir . '/repo';
        $fixtureScript = $fixtureRepoRoot . '/scripts/ops/prod_customers_ui_smoke.sh';
        $sshLog = $harnessDir . '/ssh.log';
        $sshPath = $harnessDir . '/ssh';
        $curlPath = $harnessDir . '/curl';
        $npxPath = $harnessDir . '/npx';
        $pwcliPath = $harnessDir . '/playwright_cli.sh';

        try {
            $this->materializeOperatorFixtureRepo(dirname(__DIR__, 3), $fixtureRepoRoot);

            $this->writeExecutable(
                $sshPath,
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                remote_command="${!#}"
                if [[ "${remote_command}" == *'prod_traffic_gate.sh'* && "${remote_command}" == *'--purpose customers-ui-smoke'* ]]; then
                    printf 'traffic_gate_executed\n' >> "${ROB441_SSH_LOG}"
                    if [[ "${ROB454_TRAFFIC_GATE_EXIT}" != '0' ]]; then exit "${ROB454_TRAFFIC_GATE_EXIT}"; fi
                    if [[ "${ROB454_TRAFFIC_GATE_EXTRA_FIELD}" == '1' ]]; then
                        printf '%s\n' '{"mode":"normal","schema":"traffic_gate.v1","producer_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","policy_version":"traffic_gate_policy.v1","catalog_version":"2026-08-09.1","purpose":"customers-ui-smoke","window_start_epoch":1,"window_end_epoch":91,"window_seconds":90,"log_set_sha256":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","rotation_complete":true,"parse_complete":true,"evidence_complete":true,"decision":"allow","exit_code":0,"counts":{},"future_raw_path":"/secret"}'
                    else
                        printf '%s\n' '{"mode":"normal","schema":"traffic_gate.v1","producer_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","policy_version":"traffic_gate_policy.v1","catalog_version":"2026-08-09.1","purpose":"customers-ui-smoke","window_start_epoch":1,"window_end_epoch":91,"window_seconds":90,"log_set_sha256":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","rotation_complete":true,"parse_complete":true,"evidence_complete":true,"decision":"allow","exit_code":0,"counts":{}}'
                    fi
                    exit 0
                fi
                if [[ "${remote_command}" == *'exec php -r'* ]]; then
                    printf 'remote_hash_executed\n' >> "${ROB441_SSH_LOG}"
                    exec bash -c "${remote_command}"
                fi
                if [[ "${remote_command}" == *'--on-active=10m'* ]]; then
                    printf 'cleanup_arm_stopped\n' >> "${ROB441_SSH_LOG}"
                    exit 75
                fi
                if [[ "${remote_command}" == *"'activate'"* ]]; then printf 'principal_activate\n' >> "${ROB441_SSH_LOG}"; fi
                exit 0
                BASH
                ,
            );
            $this->writeExecutable(
                $curlPath,
                "#!/usr/bin/env bash\nprintf 'endpoint_curl_executed\\n' >> \"\${ROB441_SSH_LOG}\"\nexit 0\n",
            );
            $this->writeExecutable($npxPath, "#!/usr/bin/env bash\nexit 0\n");
            $this->writeExecutable($pwcliPath, "#!/usr/bin/env bash\nexit 0\n");

            $baseEnvironment = getenv();
            self::assertIsArray($baseEnvironment);
            $path = $harnessDir . PATH_SEPARATOR . ($baseEnvironment['PATH'] ?? '');
            $effectiveRemoteRoot = $remoteRoot === dirname(__DIR__, 3) ? $fixtureRepoRoot : $remoteRoot;

            $result = $this->runCommand(
                [
                    'bash',
                    $fixtureScript,
                    '--prod-ssh-target',
                    'root@test.invalid',
                    '--app-root',
                    $effectiveRemoteRoot,
                    '--pwcli-path',
                    $pwcliPath,
                ],
                array_merge($baseEnvironment, [
                    'PATH' => $path,
                    'ROB441_SSH_LOG' => $sshLog,
                    'ROB454_TRAFFIC_GATE_EXIT' => (string) $trafficGateExit,
                    'ROB454_TRAFFIC_GATE_EXTRA_FIELD' => $extraTrafficField ? '1' : '0',
                    'CUSTOMERS_UI_SMOKE_PHP_BIN' => PHP_BINARY,
                    'CUSTOMERS_UI_SMOKE_CURL_BIN' => $curlPath,
                    'CUSTOMERS_UI_SMOKE_NPX_BIN' => $npxPath,
                ]),
            );
            $sshLogContent = file_exists($sshLog) ? file_get_contents($sshLog) : '';
            self::assertIsString($sshLogContent);

            return $result + ['ssh_log' => $sshLogContent];
        } finally {
            $this->removeTemporaryHarnessDirectory($harnessDir);
        }
    }

    private function materializeOperatorFixtureRepo(string $sourceRepoRoot, string $fixtureRepoRoot): void
    {
        $operatorScript = $this->read('scripts/ops/prod_customers_ui_smoke.sh');
        self::assertSame(1, preg_match('/readonly CONTRACT_PATHS=\((?<paths>.*?)\n\)/s', $operatorScript, $match));
        $pathCount = preg_match_all("/^\\s+'([^']+)'$/m", $match['paths'], $pathMatches);
        self::assertIsInt($pathCount);
        self::assertGreaterThan(0, $pathCount);

        $contractPaths = $pathMatches[1];
        foreach (array_keys(self::CONTRACT_ASSET_FIXTURES) as $fixturePath) {
            self::assertContains($fixturePath, $contractPaths);
        }

        $fixturePaths = array_values(
            array_unique([
                ...$contractPaths,
                'scripts/ops/prod_customers_ui_smoke.sh',
                'scripts/ops/lib/prod_common.sh',
                'scripts/release-gate/customers_ui_smoke.php',
            ]),
        );

        foreach ($fixturePaths as $relativePath) {
            self::assertSame(1, preg_match('#^[A-Za-z0-9._/-]+$#', $relativePath));
            self::assertStringNotContainsString('../', $relativePath);

            $fixturePath = $fixtureRepoRoot . '/' . $relativePath;
            $fixtureDirectory = dirname($fixturePath);
            if (!is_dir($fixtureDirectory)) {
                self::assertTrue(mkdir($fixtureDirectory, 0700, true));
            }

            if (isset(self::CONTRACT_ASSET_FIXTURES[$relativePath])) {
                $this->writeCreateOnlyFixture($fixturePath, self::CONTRACT_ASSET_FIXTURES[$relativePath]);
                continue;
            }

            $sourcePath = $sourceRepoRoot . '/' . $relativePath;
            self::assertFileExists($sourcePath);
            self::assertFalse(is_link($sourcePath));
            self::assertTrue(is_file($sourcePath));
            self::assertTrue(copy($sourcePath, $fixturePath));
        }

        $fixtureContents = array_values(self::CONTRACT_ASSET_FIXTURES);
        self::assertNotSame($fixtureContents[0], $fixtureContents[1]);
        foreach (self::CONTRACT_ASSET_FIXTURES as $relativePath => $expectedContents) {
            $actualContents = file_get_contents($fixtureRepoRoot . '/' . $relativePath);
            self::assertSame($expectedContents, $actualContents);
        }
    }

    private function writeCreateOnlyFixture(string $path, string $contents): void
    {
        self::assertFalse(file_exists($path));
        self::assertFalse(is_link($path));

        $handle = fopen($path, 'x+b');
        self::assertIsResource($handle);

        try {
            self::assertSame(strlen($contents), fwrite($handle, $contents));
        } finally {
            self::assertTrue(fclose($handle));
        }

        self::assertFalse(is_link($path));
        self::assertTrue(is_file($path));
        self::assertSame(hash('sha256', $contents), hash_file('sha256', $path));
    }

    private function removeTemporaryHarnessDirectory(string $harnessDir): void
    {
        $expectedPrefix = rtrim(sys_get_temp_dir(), '/') . '/rob441-contract-';
        if (!str_starts_with($harnessDir, $expectedPrefix) || is_link($harnessDir) || !is_dir($harnessDir)) {
            throw new \RuntimeException('Temporary Customers contract harness directory is unsafe.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($harnessDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || $item->isFile()) {
                if (!unlink($path)) {
                    throw new \RuntimeException('Temporary Customers contract harness file could not be removed.');
                }
                continue;
            }

            if (!$item->isDir() || !rmdir($path)) {
                throw new \RuntimeException('Temporary Customers contract harness directory could not be removed.');
            }
        }

        if (!rmdir($harnessDir)) {
            throw new \RuntimeException('Temporary Customers contract harness root could not be removed.');
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

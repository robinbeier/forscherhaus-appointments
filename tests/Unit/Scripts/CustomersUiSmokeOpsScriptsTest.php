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
    private function runCommand(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 3));
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

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($content);

        return $content;
    }
}

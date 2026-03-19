<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseStageConfigPreparer;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseStageConfigPreparer.php';

final class ZeroSurpriseStageConfigPreparerTest extends TestCase
{
    public function testPrepareCliPatchesBaseUrlAndDisablesRateLimitingForApplicationConfig(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $workspace = sys_get_temp_dir() . '/zs-stage-config-cli-' . bin2hex(random_bytes(4));
        $configPath = $workspace . '/config.php';
        $baseUrl = 'http://nginx';

        mkdir($workspace, 0777, true);
        copy($repoRoot . '/config-sample.php', $configPath);

        try {
            $prepareResult = $this->runCommand(
                [
                    PHP_BINARY,
                    'scripts/release-gate/prepare_zero_surprise_stage_config.php',
                    '--config=' . $configPath,
                    '--base-url=' . $baseUrl,
                ],
                $repoRoot,
            );

            $this->assertSame(0, $prepareResult['exit_code'], $prepareResult['stderr']);

            $preparedConfig = file_get_contents($configPath);
            $this->assertIsString($preparedConfig);
            $this->assertStringContainsString("const BASE_URL = '{$baseUrl}';", $preparedConfig);
            $this->assertStringContainsString('const RATE_LIMITING = false;', $preparedConfig);

            $configResult = $this->runCommand(
                [
                    PHP_BINARY,
                    '-r',
                    <<<'PHP'
                    define('BASEPATH', __DIR__);
                    define('APPPATH', rtrim(dirname($argv[2]), '/\\') . DIRECTORY_SEPARATOR);
                    function is_cli(): bool
                    {
                        return true;
                    }
                    require $argv[1];
                    $config = [];
                    require $argv[2];
                    fwrite(STDOUT, ($config['rate_limiting'] ? 'true' : 'false') . PHP_EOL);
                    PHP
                    ,
                    $configPath,
                    $repoRoot . '/application/config/config.php',
                ],
                $repoRoot,
            );

            $this->assertSame(0, $configResult['exit_code'], $configResult['stderr']);
            $this->assertSame("false\n", $configResult['stdout']);
        } finally {
            if (is_file($configPath)) {
                @unlink($configPath);
            }

            @rmdir($workspace);
        }
    }

    public function testPreparePatchesBaseUrlAndDisablesRateLimiting(): void
    {
        $path = sys_get_temp_dir() . '/zs-stage-config-' . bin2hex(random_bytes(4)) . '.php';

        file_put_contents(
            $path,
            <<<'PHP'
            <?php
            const BASE_URL = 'http://localhost';
            const DEBUG_MODE = false;
            const RATE_LIMITING = true;
            PHP
            ,
        );

        try {
            ZeroSurpriseStageConfigPreparer::prepare($path, 'http://nginx');

            $updated = file_get_contents($path);

            $this->assertIsString($updated);
            $this->assertStringContainsString("const BASE_URL = 'http://nginx';", $updated);
            $this->assertStringContainsString('const RATE_LIMITING = false;', $updated);
        } finally {
            @unlink($path);
        }
    }

    public function testPrepareInsertsRateLimitingConstantWhenMissing(): void
    {
        $path = sys_get_temp_dir() . '/zs-stage-config-missing-rate-limit-' . bin2hex(random_bytes(4)) . '.php';

        file_put_contents(
            $path,
            <<<'PHP'
            <?php
            const BASE_URL = 'http://localhost';
            const DEBUG_MODE = false;
            PHP
            ,
        );

        try {
            ZeroSurpriseStageConfigPreparer::prepare($path, 'http://nginx');

            $updated = file_get_contents($path);

            $this->assertIsString($updated);
            $this->assertStringContainsString("const BASE_URL = 'http://nginx';", $updated);
            $this->assertStringContainsString('const RATE_LIMITING = false;', $updated);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, ?string $cwd = null): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd ?? dirname(__DIR__, 3));
        $this->assertIsResource($process);

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
}

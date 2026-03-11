<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ArchitectureOwnershipMapCheckTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/architecture-ownership-map-check-' . uniqid('', true);
        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for architecture ownership map check tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testCheckAcceptsValidSingleOwnerMap(): void
    {
        $mapPath = $this->tmpDir . '/component-map.json';
        file_put_contents(
            $mapPath,
            json_encode(
                $this->buildComponentMap([
                    'secondary_handle' => '@robinbeier',
                    'ownership_mode' => 'single-owner',
                    'human_bus_factor' => 1,
                    'agent_policy' => 'conservative',
                    'manual_approval_required' => true,
                ]),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $result = $this->runCommand([
            'python3',
            'scripts/ci/check_architecture_ownership_map.py',
            '--map=' . $mapPath,
            '--skip-diff-coverage',
            '--skip-generated-docs-check',
        ]);

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Architecture/ownership map validation passed.', $result['stdout']);
    }

    public function testCheckRejectsInvalidSingleOwnerFallbackMetadata(): void
    {
        $mapPath = $this->tmpDir . '/component-map.json';
        file_put_contents(
            $mapPath,
            json_encode(
                $this->buildComponentMap([
                    'secondary_handle' => '@someoneelse',
                    'ownership_mode' => 'single-owner',
                    'human_bus_factor' => 2,
                    'agent_policy' => 'standard',
                    'manual_approval_required' => false,
                ]),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $result = $this->runCommand([
            'python3',
            'scripts/ci/check_architecture_ownership_map.py',
            '--map=' . $mapPath,
            '--skip-diff-coverage',
            '--skip-generated-docs-check',
        ]);

        self::assertSame(1, $result['exit_code']);
        $combinedOutput = $result['stdout'] . $result['stderr'];
        self::assertStringContainsString('must keep identical primary/secondary handles', $combinedOutput);
        self::assertStringContainsString('must declare human_bus_factor = 1', $combinedOutput);
        self::assertStringContainsString("must declare agent_policy = 'conservative'", $combinedOutput);
        self::assertStringContainsString('must set manual_approval_required = true', $combinedOutput);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildComponentMap(array $overrides): array
    {
        $component = array_merge(
            [
                'component_id' => 'platform-quality-tooling',
                'role' => 'Platform, CI, Release Gates',
                'summary' => 'CI workflows and automation.',
                'primary_handle' => '@robinbeier',
                'secondary_handle' => '@robinbeier',
                'ownership_mode' => 'single-owner',
                'human_bus_factor' => 1,
                'agent_policy' => 'conservative',
                'manual_approval_required' => true,
                'ownership_notes' => 'Single owner only.',
                'folder_prefixes' => ['scripts/ci/'],
                'key_files' => ['scripts/ci/check_architecture_ownership_map.py'],
                'depends_on' => [],
            ],
            $overrides,
        );

        return [
            'schema_version' => 2,
            'source' => 'docs/maps/component_ownership_map.json',
            'components' => [$component],
        ];
    }

    /**
     * @param list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
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

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($directory);
    }
}

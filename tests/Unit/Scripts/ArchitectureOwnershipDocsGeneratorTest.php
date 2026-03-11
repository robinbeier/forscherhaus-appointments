<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ArchitectureOwnershipDocsGeneratorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/architecture-ownership-docs-' . uniqid('', true);
        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for architecture ownership docs tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testGeneratorSupportsCustomPathsAndRendersSingleOwnerMetadata(): void
    {
        $mapPath = $this->tmpDir . '/component-map.json';
        $architecturePath = $this->tmpDir . '/generated/docs/architecture-map.md';
        $ownershipPath = $this->tmpDir . '/generated/docs/ownership-map.md';

        file_put_contents(
            $mapPath,
            json_encode(
                [
                    'schema_version' => 2,
                    'source' => 'docs/maps/component_ownership_map.json',
                    'components' => [
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
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $result = $this->runCommand([
            'python3',
            'scripts/docs/generate_architecture_ownership_docs.py',
            '--map=' . $mapPath,
            '--architecture-output=' . $architecturePath,
            '--ownership-output=' . $ownershipPath,
        ]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertFileExists($architecturePath);
        self::assertFileExists($ownershipPath);

        $ownershipContents = file_get_contents($ownershipPath);
        self::assertIsString($ownershipContents);
        self::assertStringContainsString('single-owner', $ownershipContents);
        self::assertStringContainsString('conservative', $ownershipContents);
        self::assertStringContainsString('Manual approval required: yes', $ownershipContents);
        self::assertStringContainsString('Ownership notes: Single owner only.', $ownershipContents);
    }

    public function testCheckModeFailsForOutdatedGeneratedOwnershipDoc(): void
    {
        $mapPath = $this->tmpDir . '/component-map.json';
        $architecturePath = $this->tmpDir . '/architecture-map.md';
        $ownershipPath = $this->tmpDir . '/ownership-map.md';

        file_put_contents(
            $mapPath,
            json_encode(
                [
                    'schema_version' => 2,
                    'source' => 'docs/maps/component_ownership_map.json',
                    'components' => [
                        [
                            'component_id' => 'shared-core',
                            'role' => 'Shared Core',
                            'summary' => 'Core reusable code.',
                            'primary_handle' => '@robinbeier',
                            'secondary_handle' => '@robinbeier',
                            'ownership_mode' => 'single-owner',
                            'human_bus_factor' => 1,
                            'agent_policy' => 'conservative',
                            'manual_approval_required' => true,
                            'ownership_notes' => 'Single owner only.',
                            'folder_prefixes' => ['application/libraries/Accounts.php'],
                            'key_files' => ['application/libraries/Accounts.php'],
                            'depends_on' => [],
                        ],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $generate = $this->runCommand([
            'python3',
            'scripts/docs/generate_architecture_ownership_docs.py',
            '--map=' . $mapPath,
            '--architecture-output=' . $architecturePath,
            '--ownership-output=' . $ownershipPath,
        ]);
        self::assertSame(0, $generate['exit_code'], $generate['stderr']);

        file_put_contents($ownershipPath, "# stale\n");

        $check = $this->runCommand([
            'python3',
            'scripts/docs/generate_architecture_ownership_docs.py',
            '--check',
            '--map=' . $mapPath,
            '--architecture-output=' . $architecturePath,
            '--ownership-output=' . $ownershipPath,
        ]);

        self::assertSame(1, $check['exit_code']);
        self::assertStringContainsString('Out-of-date generated file', $check['stdout'] . $check['stderr']);
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

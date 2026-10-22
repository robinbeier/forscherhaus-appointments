<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class NodeMinimumPolicyTest extends TestCase
{
    /** @var list<string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $this->removeTree($fixture);
        }
    }

    public function testSupportedAndRejectedNodeVersionsUseTheRealNodeEvaluator(): void
    {
        $cases = [
            '24.22.1' => true,
            '24.23.0' => true,
            '26.12.1' => true,
            '24.22.0' => false,
            '26.8.1' => false,
            '26.12.0' => false,
            '25.9.0' => false,
            '22.22.0' => false,
            '24.22.1-beta.1' => false,
        ];

        foreach ($cases as $version => $expectedSuccess) {
            $result = $this->runHelper($version, '24.0.0');
            self::assertSame($expectedSuccess ? 0 : 1, $result['exit_code'], $version . ': ' . $result['stderr']);
        }
    }

    public function testSecurityFloorAppliesEvenWithAWeakerCallerMinimum(): void
    {
        $result = $this->runHelper('24.22.0', '24.0.0');

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('security range ^24.22.1 || >=26.12.1', $result['stderr']);
    }

    public function testHigherCallerMinimumRemainsEnforced(): void
    {
        self::assertSame(1, $this->runHelper('26.12.1', '26.12.2')['exit_code']);
    }

    public function testMissingOrMalformedNodeCommandFailsClosed(): void
    {
        $missing = $this->runHelper(null);
        self::assertSame(1, $missing['exit_code']);
        self::assertStringContainsString('Missing required command: node', $missing['stderr']);

        $malformed = $this->runHelper('not-a-version');
        self::assertSame(1, $malformed['exit_code']);

        self::assertSame(1, $this->runRaw(['24.22.1', 'node-policy-test', 'extra'])['exit_code']);
        self::assertSame(1, $this->runRaw([])['exit_code']);
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runHelper(?string $version, string $minimum = '24.22.1'): array
    {
        $fixture = sys_get_temp_dir() . '/node-policy-' . bin2hex(random_bytes(6));
        $this->fixtures[] = $fixture;
        mkdir($fixture . '/bin', 0755, true);

        $realNode = (string) shell_exec('command -v node');
        $realNode = trim($realNode);
        self::assertNotSame('', $realNode);
        if ($version !== null) {
            $shim = <<<'SHIM'
            #!/bin/sh
            if [ "$1" = "-p" ]; then
                printf '%s\n' "$FH_TEST_NODE_VERSION"
            else
                exec "$FH_REAL_NODE" "$@"
            fi
            SHIM;
            file_put_contents($fixture . '/bin/node', $shim);
            chmod($fixture . '/bin/node', 0755);
        }

        $environment = [
            'PATH' => $version === null ? $fixture . '/empty' : $fixture . '/bin',
            'FH_REAL_NODE' => $realNode,
            'FH_TEST_NODE_VERSION' => $version ?? '',
        ];
        return $this->runRaw([$minimum, 'node-policy-test'], $environment);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    private function runRaw(array $arguments, array $environment = []): array
    {
        $process = proc_open(
            array_merge(['/bin/bash', dirname(__DIR__, 3) . '/scripts/ci/require_node_minimum.sh'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment === [] ? null : $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function removeTree(string $path): void
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

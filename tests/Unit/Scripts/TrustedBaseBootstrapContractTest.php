<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class TrustedBaseBootstrapContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testCanonicalParserResolvesBothDeclaredPayloadsDeterministically(): void
    {
        $contract = $this->contract();

        foreach (
            [
                'reviewer' => ['scripts/agent/run_readonly_reviewer.sh', '0500', 'reviewer'],
                'parallel' => ['scripts/agent/check_parallel_work_contract.sh', '0500', 'parallel'],
            ]
            as $payload => $expectedTail
        ) {
            [$status, $stdout, $stderr] = $this->runParser($contract, $payload);

            self::assertSame(0, $status, $stderr);
            self::assertSame('', $stderr);
            self::assertSame(
                implode("\n", [
                    '.codex/contracts/agent-workflow.json',
                    'scripts/agent/trusted_base_launcher.sh',
                    '0500',
                    'scripts/agent/lib/trusted_base_bootstrap_contract.py',
                    '0400',
                    'scripts/agent/lib/trusted_base_payload_runtime.sh',
                    '0400',
                    ...$expectedTail,
                ]),
                $stdout,
            );
        }
    }

    public function testCanonicalParserRejectsManifestDriftFailClosed(): void
    {
        $canonical = $this->contract();
        $mutators = [
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['schema_version'] = 1;
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['unexpected'] = true;
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['contract_parser']['path'] = '../untrusted.py';
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['contract_parser']['mode'] = '0500';
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['shared_runtime']['mode'] = '0500';
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['payloads']['reviewer']['environment_profile'] = 'parallel';
                return $contract;
            },
        ];

        foreach ($mutators as $mutator) {
            [$status, $stdout, $stderr] = $this->runParser($mutator($canonical), 'reviewer');

            self::assertSame(1, $status, $stdout . $stderr);
            self::assertSame('', $stdout);
            self::assertSame('', $stderr);
        }
    }

    public function testLauncherAndRuntimeInvokeAndReattestTheSameParser(): void
    {
        $launcher = (string) file_get_contents($this->repoRoot . '/scripts/agent/trusted_base_launcher.sh');
        $runtime = (string) file_get_contents($this->repoRoot . '/scripts/agent/lib/trusted_base_payload_runtime.sh');

        self::assertStringContainsString(
            "parser_repository_path='scripts/agent/lib/trusted_base_bootstrap_contract.py'",
            $launcher,
        );
        self::assertStringContainsString('trusted_python "$parser_target" "$payload_name"', $launcher);
        self::assertStringContainsString('TRUSTED_BASE_BOOTSTRAP_PARSER_BLOB="$parser_blob"', $launcher);
        self::assertStringContainsString(
            'trusted_base_assert_materialized_blob "$parser_source" "$parser_path" "$parser_mode" "$parser_blob"',
            $runtime,
        );
        self::assertStringContainsString('trusted_base_python "$parser_source" "$expected_payload_id"', $runtime);
        self::assertLessThan(
            strpos($runtime, 'trusted_base_python "$parser_source" "$expected_payload_id"'),
            strpos(
                $runtime,
                'trusted_base_assert_materialized_blob "$parser_source" "$parser_path" "$parser_mode" "$parser_blob"',
            ),
        );
    }

    public function testRuntimeValidatesEveryDeclaredTreeEntryBeforeMaterialization(): void
    {
        $runtime = (string) file_get_contents($this->repoRoot . '/scripts/agent/lib/trusted_base_payload_runtime.sh');

        self::assertStringContainsString('trusted_base_assert_declared_blob', $runtime);
        self::assertStringContainsString(
            '! "$tree_header" =~ ^100644[[:space:]]blob[[:space:]][a-f0-9]{40}$',
            $runtime,
        );
        self::assertStringContainsString('tree_path" != "$repository_path"', $runtime);

        $contractGuard = strpos($runtime, 'trusted_base_assert_declared_blob "$contract_path"');
        $contractMaterialization = strpos(
            $runtime,
            'trusted_base_git show "${trusted_base_base_sha}:${contract_path}"',
        );
        self::assertIsInt($contractGuard);
        self::assertIsInt($contractMaterialization);
        self::assertLessThan($contractMaterialization, $contractGuard);

        $pathGuard = strpos($runtime, 'trusted_base_assert_declared_blob "$path" || return 1');
        $pathMaterialization = strpos(
            $runtime,
            'trusted_base_git show "${trusted_base_base_sha}:${path}" > "$target_root/$path"',
        );
        self::assertIsInt($pathGuard);
        self::assertIsInt($pathMaterialization);
        self::assertLessThan($pathMaterialization, $pathGuard);
    }

    public function testRuntimeTreeGuardAcceptsNonExecutableBlobAndRejectsOtherTreeEntries(): void
    {
        $fixture = sys_get_temp_dir() . '/trusted-base-tree-' . bin2hex(random_bytes(8));
        $nested = $fixture . '/nested-repository';
        self::assertTrue(mkdir($fixture, 0700, true));
        self::assertTrue(mkdir($nested, 0700, true));

        try {
            $this->runGit($nested, ['init', '-q']);
            $this->runGit($nested, ['config', 'user.name', 'Trusted Base Test']);
            $this->runGit($nested, ['config', 'user.email', 'trusted-base@example.invalid']);
            file_put_contents($nested . '/nested.txt', "nested\n");
            $this->runGit($nested, ['add', 'nested.txt']);
            $this->runGit($nested, ['commit', '-qm', 'nested']);

            file_put_contents($fixture . '/regular.txt', "regular\n");
            file_put_contents($fixture . '/executable.txt', "executable\n");
            self::assertTrue(chmod($fixture . '/executable.txt', 0755));
            self::assertTrue(mkdir($fixture . '/subtree', 0700));
            file_put_contents($fixture . '/subtree/child.txt', "tree\n");
            self::assertTrue(symlink('regular.txt', $fixture . '/symlink.txt'));
            $this->runGit($fixture, ['init', '-q']);
            $this->runGit($fixture, ['config', 'user.name', 'Trusted Base Test']);
            $this->runGit($fixture, ['config', 'user.email', 'trusted-base@example.invalid']);
            $this->runGit($fixture, ['add', 'regular.txt', 'executable.txt', 'subtree', 'symlink.txt']);
            $this->runGit($fixture, ['add', 'nested-repository']);
            $this->runGit($fixture, ['commit', '-qm', 'base']);
            $base = trim($this->runGit($fixture, ['rev-parse', 'HEAD']));

            [$status, $stdout, $stderr] = $this->runTreeGuard($fixture, $base);

            self::assertSame(0, $status, $stderr);
            self::assertSame("regular=0\nexecutable=1\nsymlink=1\ntree=1\ngitlink=1\n", $stdout);
            self::assertSame('', $stderr);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    /** @return array{int, string, string} */
    private function runTreeGuard(string $fixture, string $base): array
    {
        $runtime = $this->repoRoot . '/scripts/agent/lib/trusted_base_payload_runtime.sh';
        $script = implode("\n", [
            'set -u',
            'TRUSTED_BASE_LAUNCHER=1',
            'source ' . escapeshellarg($runtime),
            'trusted_base_repo_root=' . escapeshellarg($fixture),
            'trusted_base_base_sha=' . escapeshellarg($base),
            'trusted_base_assert_declared_blob regular.txt; printf "regular=%s\\n" "$?"',
            'trusted_base_assert_declared_blob executable.txt; printf "executable=%s\\n" "$?"',
            'trusted_base_assert_declared_blob symlink.txt; printf "symlink=%s\\n" "$?"',
            'trusted_base_assert_declared_blob subtree; printf "tree=%s\\n" "$?"',
            'trusted_base_assert_declared_blob nested-repository; printf "gitlink=%s\\n" "$?"',
        ]);
        $process = proc_open(
            ['/bin/bash', '-c', $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $fixture,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function runGit(string $directory, array $arguments): string
    {
        $command = array_merge(['/usr/bin/git', '-C', $directory], $arguments);
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        return $stdout;
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
        foreach ($iterator as $path) {
            $path->isDir() && !$path->isLink() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);

        return $contract;
    }

    /** @param array<string, mixed> $contract @return array{int, string, string} */
    private function runParser(array $contract, string $payload): array
    {
        $process = proc_open(
            [
                '/usr/bin/python3',
                '-I',
                '-B',
                $this->repoRoot . '/scripts/agent/lib/trusted_base_bootstrap_contract.py',
                $payload,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}

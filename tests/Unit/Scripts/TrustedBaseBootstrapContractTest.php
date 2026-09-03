<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Forscherhaus\AgentHarness\ReadonlyReviewBundle;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/agent/lib/ReadonlyReviewBundle.php';

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

    public function testCanonicalParserRejectsAnUnknownPayloadBeforeDispatch(): void
    {
        [$status, $stdout, $stderr] = $this->runParser($this->contract(), 'unknown-payload');

        self::assertSame(1, $status, $stdout . $stderr);
        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
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
            self::assertMatchesRegularExpression(
                '/^160000 commit [a-f0-9]{40}\\tnested-repository$/D',
                trim($this->runGit($fixture, ['ls-tree', $base, 'nested-repository'])),
            );

            [$status, $stdout, $stderr] = $this->runTreeGuard($fixture, $base);

            self::assertSame(0, $status, $stderr);
            self::assertSame("regular=0\nexecutable=1\nsymlink=1\ntree=1\ngitlink=1\n", $stdout);
            self::assertSame('', $stderr);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testReviewerEvidenceUsesSterileGitMetadataAndCanonicalObjects(): void
    {
        $bundleRuntime = (string) file_get_contents(
            $this->repoRoot . '/scripts/agent/lib/readonly_reviewer_bundle_runtime.sh',
        );

        self::assertStringContainsString('GIT_CONFIG_GLOBAL=/dev/null', $bundleRuntime);
        self::assertStringContainsString('GIT_CONFIG_NOSYSTEM=1', $bundleRuntime);
        self::assertStringContainsString('GIT_ATTR_NOSYSTEM=1', $bundleRuntime);
        self::assertStringContainsString(
            'GIT_ALTERNATE_OBJECT_DIRECTORIES="$readonly_reviewer_evidence_objects"',
            $bundleRuntime,
        );
        self::assertStringContainsString('-c core.attributesFile=/dev/null', $bundleRuntime);
        self::assertStringContainsString('--git-dir="$readonly_reviewer_evidence_git_dir"', $bundleRuntime);
        self::assertStringContainsString(
            'evidence_objects="$(trusted_git rev-parse --git-path objects',
            $bundleRuntime,
        );
        self::assertStringContainsString(
            'evidence_objects_canonical="$(canonical_path "$evidence_objects")"',
            $bundleRuntime,
        );
        self::assertStringContainsString(
            'evidence_git_dir_canonical="$(canonical_path "$evidence_git_dir")"',
            $bundleRuntime,
        );
        self::assertStringContainsString('--template="$template_dir"', $bundleRuntime);
        self::assertStringContainsString('--src-prefix=a/ --dst-prefix=b/', $bundleRuntime);
        self::assertStringContainsString(
            'readonly_reviewer_bind_evidence_base_policy "$control_root" "$repo_root" "$base_sha"',
            $bundleRuntime,
        );
        self::assertStringNotContainsString('readonly_reviewer_bind_evidence_head', $bundleRuntime);
        self::assertStringContainsString('check-attr --cached -z --stdin diff', $bundleRuntime);
        self::assertStringContainsString('assert-base-diff-attributes', $bundleRuntime);
        self::assertStringContainsString('cat-file blob', $bundleRuntime);
        self::assertStringContainsString('assert-text-blob', $bundleRuntime);
        self::assertStringContainsString('diff --cached --text --numstat', $bundleRuntime);

        foreach (['diff --name-only', 'check-attr --cached', 'cat-file blob', 'show "${base_sha}:'] as $operation) {
            self::assertStringContainsString('evidence_git ' . $operation, $bundleRuntime);
        }
    }

    public function testMacOsReviewerBootstrapUsesPortableCoreutilsInvocations(): void
    {
        foreach (
            [
                'scripts/agent/trusted_base_launcher.sh',
                'scripts/agent/run_readonly_reviewer.sh',
                'scripts/agent/lib/readonly_reviewer_bundle_runtime.sh',
                'scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
            ]
            as $path
        ) {
            $source = (string) file_get_contents($this->repoRoot . '/' . $path);
            self::assertDoesNotMatchRegularExpression('/\\bchmod(?:\\s+-R)?\\s+\\S+\\s+--(?:\\s|\")/', $source, $path);
        }

        $runner = (string) file_get_contents($this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh');
        self::assertStringContainsString('/bin/cp "$codex_source" "$materialized_codex"', $runner);
        self::assertStringNotContainsString('/bin/cp --', $runner);
    }

    public function testSterileReviewerEvidenceIgnoresLocalGitDriftButPreservesCommittedAttributes(): void
    {
        $fixture = sys_get_temp_dir() . '/reviewer-evidence-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($fixture, 0700, true));

        try {
            $this->runGit($fixture, ['init', '-q']);
            $this->runGit($fixture, ['config', 'user.name', 'Reviewer Evidence Test']);
            $this->runGit($fixture, ['config', 'user.email', 'reviewer-evidence@example.invalid']);
            file_put_contents($fixture . '/.gitattributes', "committed.bin binary\n");
            file_put_contents($fixture . '/committed.bin', "base binary marker\n");
            file_put_contents($fixture . '/review.txt', "base text\n");
            $this->runGit($fixture, ['add', '.gitattributes', 'committed.bin', 'review.txt']);
            $this->runGit($fixture, ['commit', '-qm', 'base']);
            $base = trim($this->runGit($fixture, ['rev-parse', 'HEAD']));

            file_put_contents($fixture . '/committed.bin', "head binary marker\n");
            file_put_contents($fixture . '/review.txt', "head text\n");
            $this->runGit($fixture, ['add', 'committed.bin', 'review.txt']);
            $this->runGit($fixture, ['commit', '-qm', 'head']);
            $head = trim($this->runGit($fixture, ['rev-parse', 'HEAD']));

            $sourcePatchBefore = $this->runGit($fixture, [
                'diff',
                '--full-index',
                '--unified=0',
                '--no-renames',
                '--no-ext-diff',
                '--no-textconv',
                $base,
                $head,
            ]);
            $evidenceBefore = $this->runEvidenceSnapshot($fixture, $base, $head, 'before');

            file_put_contents($fixture . '/.git/info/attributes', "review.txt binary\n");
            $this->runGit($fixture, ['config', 'color.ui', 'always']);
            $this->runGit($fixture, ['config', 'diff.noprefix', 'true']);
            $this->runGit($fixture, ['config', 'diff.algorithm', 'patience']);

            $sourcePatchAfter = $this->runGit($fixture, [
                'diff',
                '--full-index',
                '--unified=0',
                '--no-renames',
                '--no-ext-diff',
                '--no-textconv',
                $base,
                $head,
            ]);
            $evidenceAfter = $this->runEvidenceSnapshot($fixture, $base, $head, 'after');

            self::assertNotSame($sourcePatchBefore, $sourcePatchAfter);
            self::assertSame($evidenceBefore, $evidenceAfter);
            self::assertSame("committed.bin\0review.txt\0", $evidenceAfter['changed_paths']);
            self::assertStringContainsString("committed.bin\0diff\0unset\0", $evidenceAfter['base_attributes']);
            self::assertStringContainsString("review.txt\0diff\0unspecified\0", $evidenceAfter['base_attributes']);
            self::assertMatchesRegularExpression(
                '/(?:^|\\x00)\\d+\\t\\d+\\treview\\.txt\\x00/',
                $evidenceAfter['numstat'],
            );
            self::assertStringContainsString("-\t-\tcommitted.bin\0", $evidenceAfter['numstat']);
            self::assertStringContainsString('diff --git a/review.txt b/review.txt', $evidenceAfter['patch']);
            self::assertStringContainsString('diff --git a/committed.bin b/committed.bin', $evidenceAfter['patch']);
            self::assertStringContainsString('+head binary marker', $evidenceAfter['patch']);
            self::assertStringNotContainsString("\033[", $evidenceAfter['patch']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testReviewerEvidenceUsesTrustedBaseAttributesWhenHeadReclassifiesBinary(): void
    {
        $fixture = sys_get_temp_dir() . '/reviewer-evidence-reclassification-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($fixture, 0700, true));

        try {
            $this->runGit($fixture, ['init', '-q']);
            $this->runGit($fixture, ['config', 'user.name', 'Reviewer Evidence Test']);
            $this->runGit($fixture, ['config', 'user.email', 'reviewer-evidence@example.invalid']);
            file_put_contents($fixture . '/.gitattributes', "payload.bin binary\n");
            file_put_contents($fixture . '/payload.bin', "base binary marker\n");
            $this->runGit($fixture, ['add', '.gitattributes', 'payload.bin']);
            $this->runGit($fixture, ['commit', '-qm', 'base']);
            $base = trim($this->runGit($fixture, ['rev-parse', 'HEAD']));

            file_put_contents($fixture . '/.gitattributes', "payload.bin text\n");
            file_put_contents($fixture . '/payload.bin', "head binary marker\n");
            $this->runGit($fixture, ['add', '.gitattributes', 'payload.bin']);
            $this->runGit($fixture, ['commit', '-qm', 'head reclassifies binary']);
            $head = trim($this->runGit($fixture, ['rev-parse', 'HEAD']));

            $evidence = $this->runEvidenceSnapshot($fixture, $base, $head, 'head-reclassification');

            self::assertSame(".gitattributes\0payload.bin\0", $evidence['changed_paths']);
            self::assertStringContainsString("payload.bin\0diff\0unset\0", $evidence['base_attributes']);
            self::assertStringContainsString('+head binary marker', $evidence['patch']);

            try {
                ReadonlyReviewBundle::assertTrustedBaseDiffAttributes(
                    $evidence['base_attributes'],
                    ReadonlyReviewBundle::changedPathsFromNul($evidence['changed_paths']),
                );
                self::fail('Head-side binary reclassification bypassed the trusted-base policy.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('trusted-base attributes', $exception->getMessage());
            }
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

    /** @return array{changed_paths: string, base_attributes: string, numstat: string, patch: string} */
    private function runEvidenceSnapshot(string $fixture, string $base, string $head, string $label): array
    {
        $controlRoot = sys_get_temp_dir() . '/reviewer-evidence-control-' . $label . '-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($controlRoot, 0700, true));
        $canonicalControlRoot = realpath($controlRoot);
        $canonicalFixture = realpath($fixture);
        self::assertIsString($canonicalControlRoot);
        self::assertIsString($canonicalFixture);
        $controlRoot = $canonicalControlRoot;
        $trustedRuntime = $this->repoRoot . '/scripts/agent/lib/trusted_base_payload_runtime.sh';
        $bundleRuntime = $this->repoRoot . '/scripts/agent/lib/readonly_reviewer_bundle_runtime.sh';
        $script = implode("\n", [
            'set -euo pipefail',
            'TRUSTED_BASE_LAUNCHER=1',
            'source ' . escapeshellarg($trustedRuntime),
            'source ' . escapeshellarg($bundleRuntime),
            'trusted_base_repo_root=' . escapeshellarg($canonicalFixture),
            'trusted_git() { trusted_base_git "$@"; }',
            'canonical_path() { trusted_base_canonical_path "$@"; }',
            'readonly_reviewer_prepare_evidence_git ' .
            escapeshellarg($controlRoot) .
            ' ' .
            escapeshellarg($canonicalFixture),
            'readonly_reviewer_bind_evidence_base_policy ' .
            escapeshellarg($controlRoot) .
            ' ' .
            escapeshellarg($canonicalFixture) .
            ' ' .
            escapeshellarg($base),
            'readonly_reviewer_evidence_git diff --name-only --no-color --no-renames --no-ext-diff --no-textconv -z ' .
            escapeshellarg($base) .
            ' ' .
            escapeshellarg($head) .
            ' > ' .
            escapeshellarg($controlRoot . '/changed-paths'),
            'readonly_reviewer_evidence_git check-attr --cached -z --stdin diff < ' .
            escapeshellarg($controlRoot . '/changed-paths') .
            ' > ' .
            escapeshellarg($controlRoot . '/base-attributes'),
            'readonly_reviewer_evidence_git read-tree ' . escapeshellarg($head),
            'readonly_reviewer_evidence_git diff --cached --text --numstat --no-color --no-renames --no-ext-diff --no-textconv -z ' .
            escapeshellarg($base) .
            ' -- > ' .
            escapeshellarg($controlRoot . '/numstat'),
            'readonly_reviewer_evidence_git diff --cached --text --full-index --unified=0 --no-color --src-prefix=a/ --dst-prefix=b/ ' .
            '--no-renames --no-ext-diff --no-textconv ' .
            escapeshellarg($base) .
            ' -- > ' .
            escapeshellarg($controlRoot . '/patch'),
        ]);
        $process = proc_open(
            ['/bin/bash', '--noprofile', '--norc', '-c', $script],
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
        try {
            self::assertSame(0, proc_close($process), $stdout . $stderr);

            return [
                'changed_paths' => (string) file_get_contents($controlRoot . '/changed-paths'),
                'base_attributes' => (string) file_get_contents($controlRoot . '/base-attributes'),
                'numstat' => (string) file_get_contents($controlRoot . '/numstat'),
                'patch' => (string) file_get_contents($controlRoot . '/patch'),
            ];
        } finally {
            $this->removeDirectory($controlRoot);
        }
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

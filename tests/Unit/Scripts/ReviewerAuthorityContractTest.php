<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Forscherhaus\AgentHarness\ReadonlyReviewerContract;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/agent/lib/ReadonlyReviewerContract.php';

class ReviewerAuthorityContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testMachineContractDeniesEveryExternalReviewerMutation(): void
    {
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);

        self::assertSame(
            [
                'file_write',
                'commit',
                'push',
                'pr_mutation',
                'pr_comment',
                'pr_review',
                'check_rerun',
                'merge',
                'linear_mutation',
                'workpad_update',
            ],
            $contract['authority']['reviewer']['denied_mutations'] ?? null,
        );
        self::assertSame(
            [
                'correctness_security' => [
                    'instructions' => '.codex/agents/reviewer-correctness.toml',
                    'model' => 'gpt-5.4',
                    'reasoning' => 'high',
                ],
                'design_maintainability' => [
                    'instructions' => '.codex/agents/reviewer-design.toml',
                    'model' => 'gpt-5.4-mini',
                    'reasoning' => 'medium',
                ],
                'tests_regression_flake' => [
                    'instructions' => '.codex/agents/reviewer-tests.toml',
                    'model' => 'gpt-5.4-mini',
                    'reasoning' => 'medium',
                ],
            ],
            $contract['authority']['reviewer']['profiles'] ?? null,
        );
        self::assertSame('review_base_commit', $contract['authority']['reviewer']['trust_anchor'] ?? null);
        self::assertSame(
            'materialized_base_blob_outside_worktree',
            $contract['authority']['reviewer']['invocation_source'] ?? null,
        );
        self::assertTrue($contract['authority']['reviewer']['requires_base_runner'] ?? false);
        self::assertSame(
            'external_bootstrap_review',
            $contract['authority']['reviewer']['runtime_configuration_change_policy'] ?? null,
        );
        self::assertSame(
            [
                '.codex/contracts/agent-workflow.json',
                '.codex/agents/reviewer-correctness.toml',
                '.codex/agents/reviewer-design.toml',
                '.codex/agents/reviewer-tests.toml',
                'scripts/agent/readonly-review-output.schema.json',
                'scripts/agent/readonly_reviewer_contract.php',
                'scripts/agent/lib/ReadonlyReviewerContract.php',
                'AGENTS.md',
                'code_review.md',
            ],
            $contract['authority']['reviewer']['trusted_base_paths'] ?? null,
        );
        $runner = (string) file_get_contents($this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh');
        self::assertStringContainsString(' trusted-paths --lens=', $runner);
        self::assertFalse($contract['authority']['reviewer']['inherits_execpolicy_rules'] ?? true);
        self::assertTrue($contract['authority']['reviewer']['output_binds_base_sha'] ?? false);
        self::assertSame(
            [
                'apps',
                'plugins',
                'browser_use',
                'browser_use_external',
                'browser_use_full_cdp_access',
                'computer_use',
                'image_generation',
                'in_app_browser',
                'memories',
                'skill_search',
                'skill_mcp_dependency_install',
                'auth_elicitation',
                'tool_call_mcp_elicitation',
                'multi_agent',
                'multi_agent_v2',
                'hooks',
            ],
            $contract['authority']['reviewer']['disabled_features'] ?? null,
        );
    }

    public function testAllFinalReviewerRolesCarryTheSamePrimaryOnlyBoundary(): void
    {
        foreach (['reviewer-correctness.toml', 'reviewer-design.toml', 'reviewer-tests.toml'] as $filename) {
            $role = (string) file_get_contents($this->repoRoot . '/.codex/agents/' . $filename);
            self::assertStringContainsString('.codex/contracts/agent-workflow.json', $role, $filename);
            self::assertStringContainsString('scripts/agent/run_readonly_reviewer.sh', $role, $filename);
            self::assertStringContainsString('Do not delegate or mutate files, Git, GitHub', $role, $filename);
            self::assertStringContainsString('Return findings only to the primary', $role, $filename);
        }
    }

    public function testRunnerStripsExternalCredentialsAndPinsReadOnlyInvocation(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/reviewer-authority-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory, 0700));
        $fixtureRepo = $temporaryDirectory . '/repo';
        self::assertTrue(mkdir($fixtureRepo . '/scripts/agent/lib', 0700, true));
        self::assertTrue(mkdir($fixtureRepo . '/.codex/agents', 0700, true));
        self::assertTrue(mkdir($fixtureRepo . '/.codex/contracts', 0700, true));
        copy(
            $this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh',
            $fixtureRepo . '/scripts/agent/run_readonly_reviewer.sh',
        );
        chmod($fixtureRepo . '/scripts/agent/run_readonly_reviewer.sh', 0700);
        copy(
            $this->repoRoot . '/scripts/agent/readonly-review-output.schema.json',
            $fixtureRepo . '/scripts/agent/readonly-review-output.schema.json',
        );
        copy(
            $this->repoRoot . '/scripts/agent/readonly_reviewer_contract.php',
            $fixtureRepo . '/scripts/agent/readonly_reviewer_contract.php',
        );
        copy(
            $this->repoRoot . '/scripts/agent/lib/ReadonlyReviewerContract.php',
            $fixtureRepo . '/scripts/agent/lib/ReadonlyReviewerContract.php',
        );
        copy(
            $this->repoRoot . '/.codex/contracts/agent-workflow.json',
            $fixtureRepo . '/.codex/contracts/agent-workflow.json',
        );
        foreach (['reviewer-correctness.toml', 'reviewer-design.toml', 'reviewer-tests.toml'] as $filename) {
            copy($this->repoRoot . '/.codex/agents/' . $filename, $fixtureRepo . '/.codex/agents/' . $filename);
        }
        file_put_contents($fixtureRepo . '/AGENTS.md', "fixture\n");
        file_put_contents($fixtureRepo . '/code_review.md', "fixture\n");
        file_put_contents($fixtureRepo . '/fixture.txt', "base\n");
        $this->runGit($fixtureRepo, ['init', '-q']);
        $this->runGit($fixtureRepo, ['config', 'user.name', 'Reviewer Test']);
        $this->runGit($fixtureRepo, ['config', 'user.email', 'reviewer-test@example.invalid']);
        $this->runGit($fixtureRepo, ['add', '.']);
        $this->runGit($fixtureRepo, ['commit', '-qm', 'base']);
        $base = $this->runGit($fixtureRepo, ['rev-parse', 'HEAD']);

        file_put_contents(
            $fixtureRepo . '/scripts/agent/run_readonly_reviewer.sh',
            "\n# untrusted head-only runner change\n",
            FILE_APPEND,
        );
        foreach (['reviewer-correctness.toml', 'reviewer-design.toml', 'reviewer-tests.toml'] as $filename) {
            file_put_contents($fixtureRepo . '/.codex/agents/' . $filename, "untrusted head profile\n");
        }
        $headContract = json_decode(
            (string) file_get_contents($fixtureRepo . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($headContract);
        foreach (array_keys($headContract['authority']['reviewer']['profiles']) as $lens) {
            $headContract['authority']['reviewer']['profiles'][$lens]['model'] = 'untrusted-model';
        }
        file_put_contents(
            $fixtureRepo . '/.codex/contracts/agent-workflow.json',
            json_encode($headContract, JSON_THROW_ON_ERROR),
        );
        file_put_contents($fixtureRepo . '/scripts/agent/readonly-review-output.schema.json', "{}\n");
        file_put_contents(
            $fixtureRepo . '/scripts/agent/readonly_reviewer_contract.php',
            "<?php fwrite(STDOUT, (string) stream_get_contents(STDIN));\n",
        );
        file_put_contents(
            $fixtureRepo . '/scripts/agent/lib/ReadonlyReviewerContract.php',
            "<?php // untrusted head validator\n",
        );
        file_put_contents($fixtureRepo . '/fixture.txt', "head\n");
        $this->runGit($fixtureRepo, ['add', '.']);
        $this->runGit($fixtureRepo, ['commit', '-qm', 'head']);
        $head = $this->runGit($fixtureRepo, ['rev-parse', 'HEAD']);

        $capturePath = $temporaryDirectory . '/capture.txt';
        $fakeCodex = $temporaryDirectory . '/codex';
        file_put_contents(
            $fakeCodex,
            <<<'BASH'
            #!/usr/bin/env bash
            {
                printf 'ARGS\n'
                printf '%s\n' "$@"
                printf 'ENV\n'
                printf 'GH_TOKEN=%s\n' "${GH_TOKEN-unset}"
                printf 'GITHUB_TOKEN=%s\n' "${GITHUB_TOKEN-unset}"
                printf 'GITHUB_PAT=%s\n' "${GITHUB_PAT-unset}"
                printf 'LINEAR_API_KEY=%s\n' "${LINEAR_API_KEY-unset}"
                printf 'LINEAR_TOKEN=%s\n' "${LINEAR_TOKEN-unset}"
                printf 'PROMPT\n'
                cat
            } > "$REVIEWER_TEST_CAPTURE"
            printf '%s\n' "$REVIEWER_TEST_RESULT"
            BASH
            ,
        );
        chmod($fakeCodex, 0700);

        $environment = $_ENV;
        $environment['PATH'] = $temporaryDirectory . ':' . (getenv('PATH') ?: '/usr/bin:/bin');
        $environment['REVIEWER_TEST_CAPTURE'] = $capturePath;
        $environment['GH_TOKEN'] = 'credential-sentinel';
        $environment['GITHUB_TOKEN'] = 'credential-sentinel';
        $environment['GITHUB_PAT'] = 'credential-sentinel';
        $environment['LINEAR_API_KEY'] = 'credential-sentinel';
        $environment['LINEAR_TOKEN'] = 'credential-sentinel';
        $lenses = [
            'correctness_security' => 'gpt-5.4',
            'design_maintainability' => 'gpt-5.4-mini',
            'tests_regression_flake' => 'gpt-5.4-mini',
        ];
        foreach ($lenses as $lens => $expectedModel) {
            $environment['REVIEWER_TEST_RESULT'] = json_encode(
                [
                    'lens' => $lens,
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            );
            [$exitCode, $stdout, $stderr] = $this->runReviewer($fixtureRepo, $environment, $lens, $base, $head);
            self::assertSame(0, $exitCode, $stderr);
            self::assertSame($environment['REVIEWER_TEST_RESULT'], trim($stdout));
            $capture = (string) file_get_contents($capturePath);
            self::assertStringContainsString("--model\n" . $expectedModel, $capture, $lens);
            self::assertStringNotContainsString('untrusted-model', $capture, $lens);
        }

        self::assertStringContainsString("--ask-for-approval\nnever", $capture);
        self::assertStringContainsString("--sandbox\nread-only", $capture);
        self::assertStringContainsString('--ignore-user-config', $capture);
        self::assertSame(1, substr_count($capture, "--ignore-rules\n"));
        self::assertStringContainsString('--ephemeral', $capture);
        self::assertStringContainsString("--color\nnever", $capture);
        self::assertSame(1, substr_count($capture, "--output-schema\n"));
        self::assertSame(
            1,
            preg_match(
                '#--output-schema\n([^\n]+)/scripts/agent/readonly-review-output\.schema\.json\n#',
                $capture,
                $trustedRootMatch,
            ),
        );
        $trustedRoot = $trustedRootMatch[1];
        self::assertStringContainsString($trustedRoot . '/AGENTS.md', $capture);
        self::assertStringContainsString($trustedRoot . '/code_review.md', $capture);
        self::assertStringNotContainsString("--json\n", $capture);
        self::assertStringContainsString('shell_environment_policy.inherit="none"', $capture);
        self::assertStringContainsString('sandbox_workspace_write.network_access=false', $capture);
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        foreach ($contract['authority']['reviewer']['disabled_features'] as $feature) {
            self::assertSame(1, substr_count($capture, "--disable\n" . $feature . "\n"), (string) $feature);
        }
        self::assertStringContainsString("GH_TOKEN=unset\n", $capture);
        self::assertStringContainsString("GITHUB_TOKEN=unset\n", $capture);
        self::assertStringContainsString("GITHUB_PAT=unset\n", $capture);
        self::assertStringContainsString("LINEAR_API_KEY=unset\n", $capture);
        self::assertStringContainsString("LINEAR_TOKEN=unset\n", $capture);
        self::assertStringNotContainsString('credential-sentinel', $capture);
        self::assertStringContainsString('/readonly-reviewer-base.', $capture);
        self::assertStringNotContainsString(
            $fixtureRepo . '/scripts/agent/readonly-review-output.schema.json',
            $capture,
        );
        self::assertStringNotContainsString($fixtureRepo . '/.codex/agents/reviewer-', $capture);

        self::assertTrue(unlink($capturePath));
        $process = proc_open(
            [
                $fixtureRepo . '/scripts/agent/run_readonly_reviewer.sh',
                '--repo-root=' . $fixtureRepo,
                '--lens=correctness_security',
                '--base-sha=' . $base,
                '--head-sha=' . $head,
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $fixtureRepo,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame('', (string) $stdout);
        self::assertStringContainsString(
            'must be materialized from the review base outside the worktree',
            (string) $stderr,
        );
        self::assertFileDoesNotExist($capturePath, 'The changed head runner must not invoke Codex.');

        $environment['REVIEWER_TEST_RESULT'] = '{"not":"a review"}';
        [$exitCode, $stdout, $stderr] = $this->runReviewer(
            $fixtureRepo,
            $environment,
            'correctness_security',
            $base,
            $head,
        );
        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('not bound to the requested base, exact head, and lens', $stderr);

        $environment['REVIEWER_TEST_RESULT'] = json_encode(
            [
                'lens' => 'correctness_security',
                'base_sha' => $base,
                'head_sha' => $head,
                'verdict' => 'no_findings',
                'findings' => [],
            ],
            JSON_THROW_ON_ERROR,
        );

        $tree = $this->runGit($fixtureRepo, ['rev-parse', 'HEAD^{tree}']);
        $unrelatedBase = $this->runGit($fixtureRepo, ['commit-tree', $tree, '-m', 'unrelated']);
        self::assertTrue(unlink($capturePath));

        $trustedRunner = $this->materializeTrustedRunner($fixtureRepo, $base);
        $process = proc_open(
            [
                $trustedRunner,
                '--repo-root=' . $fixtureRepo,
                '--lens=correctness_security',
                '--base-sha=' . $unrelatedBase,
                '--head-sha=' . $head,
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $fixtureRepo,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(1, $exitCode);
        self::assertSame('', (string) $stdout);
        self::assertStringContainsString('not the trusted copy from the review base', (string) $stderr);
        self::assertFileDoesNotExist($capturePath, 'Codex must not run for an unrelated base.');
    }

    public function testOutputValidationRejectsCodexEventStreamAndWrongExactHead(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);
        $valid = json_encode(
            [
                'lens' => 'correctness_security',
                'base_sha' => $base,
                'head_sha' => $head,
                'verdict' => 'no_findings',
                'findings' => [],
            ],
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'no_findings',
            ReadonlyReviewerContract::validateOutput($valid, 'correctness_security', $base, $head)['verdict'],
        );

        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput('{"type":"turn.completed"}', 'correctness_security', $base, $head);
    }

    public function testOutputValidationRejectsWrongExactHead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => str_repeat('b', 40),
                    'head_sha' => str_repeat('b', 40),
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            str_repeat('b', 40),
            str_repeat('a', 40),
        );
    }

    public function testOutputValidationRejectsWrongBaseSha(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => str_repeat('c', 40),
                    'head_sha' => str_repeat('a', 40),
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            str_repeat('b', 40),
            str_repeat('a', 40),
        );
    }

    public function testOutputValidationRejectsWrongLens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'design_maintainability',
                    'base_sha' => str_repeat('b', 40),
                    'head_sha' => str_repeat('a', 40),
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            str_repeat('b', 40),
            str_repeat('a', 40),
        );
    }

    public function testOutputValidationRejectsVerdictFindingMismatch(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('verdict does not match its findings');
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'no_findings',
                    'findings' => [
                        [
                            'priority' => 'P2',
                            'title' => 'Finding',
                            'file' => 'WORKFLOW.md',
                            'line' => 1,
                            'impact' => 'Impact',
                            'trigger' => 'Trigger',
                        ],
                    ],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            $base,
            $head,
        );
    }

    public function testOutputValidationRejectsFindingsVerdictWithoutFindings(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('verdict does not match its findings');
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            $base,
            $head,
        );
    }

    public function testOutputValidationRejectsMalformedFinding(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finding has unexpected fields');
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'findings',
                    'findings' => [['priority' => 'P2']],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            $base,
            $head,
        );
    }

    public function testProfileResolutionUsesStructuredMachinePolicy(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/reviewer-profile-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory . '/.codex/agents', 0700, true));
        file_put_contents(
            $temporaryDirectory . '/.codex/agents/reviewer.toml',
            "developer_instructions = \"\"\"\nmodel = 'untrusted-body-value'\n\"\"\"\n",
        );

        $resolved = ReadonlyReviewerContract::resolveInvocation(
            $temporaryDirectory,
            'tests_regression_flake',
            $this->reviewerPolicyForProfile('.codex/agents/reviewer.toml'),
        );

        self::assertSame('gpt-5.4-mini', $resolved['model']);
        self::assertSame('medium', $resolved['reasoning']);
    }

    public function testProfileResolutionRejectsAStaleTrustedPathSet(): void
    {
        $policy = $this->reviewerPolicyForProfile('.codex/agents/reviewer.toml');
        array_pop($policy['trusted_base_paths']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('trusted-base policy is invalid');
        ReadonlyReviewerContract::trustedBasePaths($policy);
    }

    /**
     * @param array<string, string> $environment
     * @return array{int, string, string}
     */
    private function runReviewer(
        string $fixtureRepo,
        array $environment,
        string $lens,
        string $base,
        string $head,
    ): array {
        $trustedRunner = $this->materializeTrustedRunner($fixtureRepo, $base);
        $process = proc_open(
            [
                $trustedRunner,
                '--repo-root=' . $fixtureRepo,
                '--lens=' . $lens,
                '--base-sha=' . $base,
                '--head-sha=' . $head,
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $fixtureRepo,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function materializeTrustedRunner(string $fixtureRepo, string $trustedBase): string
    {
        $runnerPath = sys_get_temp_dir() . '/readonly-reviewer-runner-' . bin2hex(random_bytes(8));
        $process = proc_open(
            ['git', '-C', $fixtureRepo, 'show', $trustedBase . ':scripts/agent/run_readonly_reviewer.sh'],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $runner = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertNotSame('', $runner);
        self::assertNotFalse(file_put_contents($runnerPath, $runner));
        self::assertTrue(chmod($runnerPath, 0700));

        return $runnerPath;
    }

    /** @return array<string, mixed> */
    private function reviewerPolicyForProfile(string $profile): array
    {
        return [
            'invocation_source' => 'materialized_base_blob_outside_worktree',
            'trust_anchor' => 'review_base_commit',
            'requires_base_runner' => true,
            'runtime_configuration_change_policy' => 'external_bootstrap_review',
            'trusted_base_paths' => [
                '.codex/contracts/agent-workflow.json',
                $profile,
                'scripts/agent/readonly-review-output.schema.json',
                'scripts/agent/readonly_reviewer_contract.php',
                'scripts/agent/lib/ReadonlyReviewerContract.php',
                'AGENTS.md',
                'code_review.md',
            ],
            'profiles' => [
                'correctness_security' => [
                    'instructions' => $profile,
                    'model' => 'gpt-5.4',
                    'reasoning' => 'high',
                ],
                'design_maintainability' => [
                    'instructions' => $profile,
                    'model' => 'gpt-5.4-mini',
                    'reasoning' => 'medium',
                ],
                'tests_regression_flake' => [
                    'instructions' => $profile,
                    'model' => 'gpt-5.4-mini',
                    'reasoning' => 'medium',
                ],
            ],
            'disabled_features' => ['apps'],
            'filesystem' => 'read-only',
            'network' => 'denied',
            'approval_policy' => 'never',
            'inherits_user_config' => false,
            'inherits_execpolicy_rules' => false,
            'output_binds_base_sha' => true,
            'allows_external_connectors' => false,
            'allows_delegation' => false,
        ];
    }

    /** @param list<string> $arguments */
    private function runGit(string $workingDirectory, array $arguments): string
    {
        $command = ['git', '-C', $workingDirectory, ...$arguments];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, (string) $stderr);

        return trim((string) $stdout);
    }
}

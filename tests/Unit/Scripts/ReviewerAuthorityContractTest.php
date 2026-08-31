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
                'correctness_security' => '.codex/agents/reviewer-correctness.toml',
                'design_maintainability' => '.codex/agents/reviewer-design.toml',
                'tests_regression_flake' => '.codex/agents/reviewer-tests.toml',
            ],
            $contract['authority']['reviewer']['profiles'] ?? null,
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
        file_put_contents($fixtureRepo . '/fixture.txt', "head\n");
        $this->runGit($fixtureRepo, ['add', 'fixture.txt']);
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
                printf 'LINEAR_API_KEY=%s\n' "${LINEAR_API_KEY-unset}"
                printf 'LINEAR_TOKEN=%s\n' "${LINEAR_TOKEN-unset}"
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
        $environment['LINEAR_API_KEY'] = 'credential-sentinel';
        $environment['LINEAR_TOKEN'] = 'credential-sentinel';
        $environment['REVIEWER_TEST_RESULT'] = json_encode(
            [
                'lens' => 'correctness_security',
                'head_sha' => $head,
                'verdict' => 'no_findings',
                'findings' => [],
            ],
            JSON_THROW_ON_ERROR,
        );

        $process = proc_open(
            [
                $fixtureRepo . '/scripts/agent/run_readonly_reviewer.sh',
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
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, (string) $stderr);
        self::assertSame($environment['REVIEWER_TEST_RESULT'], trim((string) $stdout));
        $capture = (string) file_get_contents($capturePath);
        self::assertStringContainsString("--ask-for-approval\nnever", $capture);
        self::assertStringContainsString("--sandbox\nread-only", $capture);
        self::assertStringContainsString('--ignore-user-config', $capture);
        self::assertStringContainsString('--ephemeral', $capture);
        self::assertStringContainsString("--color\nnever", $capture);
        self::assertStringNotContainsString("--json\n", $capture);
        self::assertStringContainsString("--model\ngpt-5.4", $capture);
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
        self::assertStringContainsString("LINEAR_API_KEY=unset\n", $capture);
        self::assertStringContainsString("LINEAR_TOKEN=unset\n", $capture);
        self::assertStringNotContainsString('credential-sentinel', $capture);

        $tree = $this->runGit($fixtureRepo, ['rev-parse', 'HEAD^{tree}']);
        $unrelatedBase = $this->runGit($fixtureRepo, ['commit-tree', $tree, '-m', 'unrelated']);
        self::assertTrue(unlink($capturePath));

        $process = proc_open(
            [
                $fixtureRepo . '/scripts/agent/run_readonly_reviewer.sh',
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
        self::assertStringContainsString('Reviewer base is not an ancestor', (string) $stderr);
        self::assertFileDoesNotExist($capturePath, 'Codex must not run for an unrelated base.');
    }

    public function testOutputValidationRejectsCodexEventStreamAndWrongExactHead(): void
    {
        $head = str_repeat('a', 40);
        $valid = json_encode(
            [
                'lens' => 'correctness_security',
                'head_sha' => $head,
                'verdict' => 'no_findings',
                'findings' => [],
            ],
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'no_findings',
            ReadonlyReviewerContract::validateOutput($valid, 'correctness_security', $head)['verdict'],
        );

        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput('{"type":"turn.completed"}', 'correctness_security', $head);
    }

    public function testOutputValidationRejectsWrongExactHead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'head_sha' => str_repeat('b', 40),
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            str_repeat('a', 40),
        );
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

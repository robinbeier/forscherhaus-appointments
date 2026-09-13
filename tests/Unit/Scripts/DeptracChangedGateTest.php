<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeptracChangedGateTest extends TestCase
{
    private array $repositories = [];

    protected function tearDown(): void
    {
        foreach ($this->repositories as $repository) {
            $this->removeTree($repository);
        }
    }

    public function testPullRequestBaseFormsIncludeEarlyPhpChangeBeforeUnrelatedTip(): void
    {
        $repo = $this->repository();
        file_put_contents($repo . '/README.md', "base\n");
        $this->commit($repo, 'base');
        self::assertSame(0, $this->execute($repo, ['git', 'branch', 'origin/main'])['exit_code']);
        file_put_contents($repo . '/application/models/Early.php', "<?php\n");
        $this->commit($repo, 'early application change');
        file_put_contents($repo . '/README.md', "tip\n");
        $this->commit($repo, 'unrelated tip');

        foreach (['main', 'origin/main'] as $base) {
            $result = $this->runGate($repo, ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_BASE_REF' => $base]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $report = json_decode(
                file_get_contents($repo . '/storage/logs/ci/deptrac-changed-gate.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertContains('application/models/Early.php', $report['changed_scope_files']);
        }
    }

    public function testMissingPullRequestBaseAndInvalidExplicitRangeFailVisibly(): void
    {
        $repo = $this->repository();
        file_put_contents($repo . '/README.md', "base\n");
        $this->commit($repo, 'base');
        file_put_contents($repo . '/application/models/Early.php', "<?php\n");
        $this->commit($repo, 'early');
        file_put_contents($repo . '/README.md', "tip\n");
        $this->commit($repo, 'tip');

        $missing = $this->runGate($repo, ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_BASE_REF' => 'missing/base']);
        self::assertNotSame(0, $missing['exit_code']);
        self::assertStringContainsString('Unable to resolve pull-request base ref', $missing['stderr']);

        $invalid = $this->runGate($repo, ['DEPTRAC_DIFF_RANGE' => 'invalid-range']);
        self::assertNotSame(0, $invalid['exit_code']);
        self::assertStringContainsString('git diff failed', $invalid['stdout'] . $invalid['stderr']);
    }

    private function repository(): string
    {
        $repo = sys_get_temp_dir() . '/deptrac-changed-' . bin2hex(random_bytes(8));
        mkdir($repo . '/scripts/ci', 0777, true);
        mkdir($repo . '/application/models', 0777, true);
        mkdir($repo . '/vendor/bin', 0777, true);
        mkdir($repo . '/storage/logs/ci', 0777, true);
        copy(
            dirname(__DIR__, 3) . '/scripts/ci/run_deptrac_changed_gate.sh',
            $repo . '/scripts/ci/run_deptrac_changed_gate.sh',
        );
        copy(dirname(__DIR__, 3) . '/scripts/ci/git_helpers.sh', $repo . '/scripts/ci/git_helpers.sh');
        file_put_contents($repo . '/deptrac.yaml', "parameters: {}\n");
        file_put_contents(
            $repo . '/vendor/bin/deptrac',
            "#!/usr/bin/env bash\nfor arg in \"\$@\"; do case \"\$arg\" in --output=*) output=\"\${arg#--output=}\";; esac; done\nprintf '%s\\n' '{\"Report\":{\"Errors\":0},\"files\":{}}' > \"\$output\"\n",
        );
        chmod($repo . '/vendor/bin/deptrac', 0755);
        $this->execute($repo, ['git', 'init', '-q']);
        $this->execute($repo, ['git', 'config', 'user.email', 'test@example.test']);
        $this->execute($repo, ['git', 'config', 'user.name', 'Test']);
        $this->repositories[] = $repo;
        return $repo;
    }

    private function commit(string $repo, string $message): void
    {
        $this->execute($repo, ['git', 'add', '-A']);
        $this->execute($repo, ['git', 'commit', '-qm', $message]);
    }

    private function runGate(string $repo, array $environment): array
    {
        return $this->execute($repo, ['bash', 'scripts/ci/run_deptrac_changed_gate.sh'], $environment);
    }

    private function execute(string $repo, array $command, array $environment = []): array
    {
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $repo,
            array_merge(getenv(), $environment),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
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
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}

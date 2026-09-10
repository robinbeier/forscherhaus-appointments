<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalFullGateSelectionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/full-gate-selection-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/scripts/ci', 0700, true);
        mkdir($this->directory . '/.github/workflows', 0700, true);
        mkdir($this->directory . '/application', 0700);
        $root = dirname(__DIR__, 3);
        symlink(
            $root . '/scripts/ci/select_local_full_gate.php',
            $this->directory . '/scripts/ci/select_local_full_gate.php',
        );
        copy($root . '/.github/workflows/ci.yml', $this->directory . '/.github/workflows/ci.yml');
        file_put_contents($this->directory . '/application/example.php', 'old');
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'fixture@example.invalid']);
        $this->git(['config', 'user.name', 'Fixture']);
        $this->git(['add', '.']);
        $this->git(['-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'base']);
        $this->git(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public static function changeStates(): array
    {
        return [['committed'], ['staged'], ['unstaged'], ['untracked'], ['renamed']];
    }

    #[DataProvider('changeStates')]
    public function testIncludesEveryLocalChangeState(string $state): void
    {
        if ($state === 'renamed') {
            $this->git(['mv', 'application/example.php', 'notes.md']);
        } elseif ($state === 'untracked') {
            file_put_contents($this->directory . "/application/with space\nand-umlaut-ä.php", 'new');
        } else {
            file_put_contents($this->directory . '/application/example.php', 'changed');
            if ($state !== 'unstaged') {
                $this->git(['add', 'application/example.php']);
            }
            if ($state === 'committed') {
                $this->git(['-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'change']);
            }
        }
        [$status, $output, $error] = $this->select();
        self::assertSame(0, $status, $error);
        self::assertSame("true\n", $output);
    }

    public function testOperationsOnlyChangeSkipsBrowserForBothBaseSpellings(): void
    {
        file_put_contents($this->directory . '/scripts/ops-change.sh', 'changed');
        foreach (['main', 'origin/main'] as $base) {
            [$status, $output, $error] = $this->select($base);
            self::assertSame(0, $status, $error);
            self::assertSame("false\n", $output);
        }
    }

    public function testUnknownBaseAndFailedDiffAbortSelection(): void
    {
        [$status] = $this->select('missing-base');
        self::assertNotSame(0, $status);
        // A valid base but no merge base must not fall back to a partial diff.
        $this->git(['checkout', '--orphan', 'unrelated']);
        $this->git(['-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'unrelated']);
        [$status] = $this->select();
        self::assertNotSame(0, $status);
    }

    private function select(string $base = 'main'): array
    {
        return $this->runCommand([
            'bash',
            '-euc',
            'source "$1"; selection="$(pre_pr_full_should_run_integration_smoke "$2")"; printf "%s\n" "$selection"',
            'selection',
            dirname(__DIR__, 3) . '/scripts/ci/lib/local_full_gate_selection.sh',
            $base,
        ]);
    }

    private function git(array $arguments): void
    {
        [$status, , $error] = $this->runCommand(['git', ...$arguments]);
        self::assertSame(0, $status, $error);
    }

    private function runCommand(array $command): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }
}

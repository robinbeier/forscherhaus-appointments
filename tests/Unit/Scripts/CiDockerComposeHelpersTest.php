<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class CiDockerComposeHelpersTest extends TestCase
{
    public function testCiDockerPhpFpmInputsChangedMatchesDockerRuntimeFiles(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            git_ci_collect_changed_paths() {
              cat "$CHANGED_PATHS_FILE"
            }

            if ci_docker_php_fpm_inputs_changed origin/main; then
              printf 'changed'
            else
              printf 'unchanged'
            fi
            BASH
            ,
            "docker/php-fpm/Dockerfile\n",
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('changed', trim($result['stdout']));
    }

    public function testCiDockerPhpFpmInputsChangedIgnoresApplicationOnlyChanges(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            git_ci_collect_changed_paths() {
              cat "$CHANGED_PATHS_FILE"
            }

            if ci_docker_php_fpm_inputs_changed origin/main; then
              printf 'changed'
            else
              printf 'unchanged'
            fi
            BASH
            ,
            "application/controllers/Dashboard.php\n",
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('unchanged', trim($result['stdout']));
    }

    public function testCiDockerPhpFpmInputsChangedMatchesComposeOverrideChanges(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            git_ci_collect_changed_paths() {
              cat "$CHANGED_PATHS_FILE"
            }

            if ci_docker_php_fpm_inputs_changed origin/main; then
              printf 'changed'
            else
              printf 'unchanged'
            fi
            BASH
            ,
            "docker/compose.ci-local.yml\n",
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('changed', trim($result['stdout']));
    }

    public function testGitCiCollectChangedPathsIncludesBranchStagedAndUnstagedFiles(): void
    {
        $tempRepo = sys_get_temp_dir() . '/ci-git-helpers-' . bin2hex(random_bytes(8));
        mkdir($tempRepo, 0777, true);

        try {
            $setup = $this->runCommand(
                [
                    'bash',
                    '-lc',
                    <<<'BASH'
                    set -euo pipefail
                    git init -b main
                    git config user.email ci@example.test
                    git config user.name "CI Test"
                    printf 'base\n' > tracked.txt
                    git add tracked.txt
                    git commit -m 'base'
                    printf 'branch\n' > branch.txt
                    git add branch.txt
                    git commit -m 'branch'
                    printf 'unstaged\n' >> tracked.txt
                    printf 'staged\n' > staged.txt
                    git add staged.txt
                    BASH
                ,
                ],
                $tempRepo,
            );
            self::assertSame(0, $setup['exit_code'], $setup['stderr']);

            $result = $this->runCommand(
                [
                    'bash',
                    '-lc',
                    'set -euo pipefail; source "$1"; git_ci_collect_changed_paths main',
                    'bash',
                    $this->repoRoot() . '/scripts/ci/git_helpers.sh',
                ],
                $tempRepo,
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);

            $paths = array_values(array_filter(array_map('trim', explode("\n", $result['stdout']))));

            self::assertContains('branch.txt', $paths);
            self::assertContains('tracked.txt', $paths);
            self::assertContains('staged.txt', $paths);
            self::assertSame($paths, array_values(array_unique($paths)));
        } finally {
            $this->removeDirectory($tempRepo);
        }
    }

    public function testCiDockerWaitForServiceExecRetriesUntilCommandSucceeds(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            sleep() { :; }
            ci_docker_compose() {
              if [[ "$1" == "exec" && "$2" == "-T" && "$3" == "php-fpm" && "$4" == "php" && "$5" == "-v" ]]; then
                attempt_file="${TMPDIR:-/tmp}/ci-docker-wait-attempts"
                attempts=0
                if [[ -f "$attempt_file" ]]; then
                  attempts="$(cat "$attempt_file")"
                fi
                attempts=$((attempts + 1))
                printf '%s' "$attempts" > "$attempt_file"
                if [[ "$attempts" -lt 3 ]]; then
                  return 1
                fi
                return 0
              fi
              return 1
            }

            attempt_file="${TMPDIR:-/tmp}/ci-docker-wait-attempts"
            rm -f "$attempt_file"
            ci_docker_wait_for_service_exec php-fpm test php -v
            cat "$attempt_file"
            rm -f "$attempt_file"
            BASH
            ,
            '',
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('3', trim($result['stdout']));
    }

    public function testCiDockerWaitForServiceExecFailsWithoutCommand(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            ci_docker_wait_for_service_exec php-fpm test
            BASH
            ,
            '',
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('requires a command', $result['stderr']);
    }

    public function testCiDockerWaitForEasyappointmentsMysqlConnectivityRetriesUntilPhpCanReachMysql(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            sleep() { :; }
            ci_docker_compose() {
              if [[ "$1" == "exec" && "$2" == "-T" && "$3" == "php-fpm" && "$4" == "php" && "$5" == "-r" ]]; then
                attempt_file="${TMPDIR:-/tmp}/ci-docker-php-mysql-attempts"
                attempts=0
                if [[ -f "$attempt_file" ]]; then
                  attempts="$(cat "$attempt_file")"
                fi
                attempts=$((attempts + 1))
                printf '%s' "$attempts" > "$attempt_file"
                if [[ "$attempts" -lt 4 ]]; then
                  return 1
                fi
                return 0
              fi
              return 1
            }

            attempt_file="${TMPDIR:-/tmp}/ci-docker-php-mysql-attempts"
            rm -f "$attempt_file"
            ci_docker_wait_for_easyappointments_mysql_connectivity test
            cat "$attempt_file"
            rm -f "$attempt_file"
            BASH
            ,
            '',
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('4', trim($result['stdout']));
    }

    public function testCiDockerWaitForEasyappointmentsMysqlConnectivityUsesConfigDrivenProbe(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            ci_docker_compose() {
              if [[ "$1" == "exec" && "$2" == "-T" && "$3" == "php-fpm" && "$4" == "php" && "$5" == "-r" ]]; then
                if [[ "$6" == *'Config::DB_HOST'* && "$6" == *'new mysqli'* ]]; then
                  return 0
                fi
              fi
              return 1
            }

            ci_docker_wait_for_easyappointments_mysql_connectivity test
            BASH
            ,
            '',
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
    }

    public function testCiDockerWaitForEasyappointmentsMysqlConnectivityTimesOutWhenPhpCannotReachMysql(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            sleep() { :; }
            ci_docker_compose() {
              return 1
            }

            ci_docker_wait_for_easyappointments_mysql_connectivity test
            BASH
            ,
            '',
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('php-fpm could not reach MySQL after 30 attempts.', $result['stderr']);
    }

    public function testCiDockerInstallSeedInstanceUsesDefaultRetryBudget(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            sleep() { :; }
            ci_docker_compose() {
              attempt_file="${TMPDIR:-/tmp}/ci-docker-seed-attempts-default"
              attempts=0
              if [[ -f "$attempt_file" ]]; then
                attempts="$(cat "$attempt_file")"
              fi
              attempts=$((attempts + 1))
              printf '%s' "$attempts" > "$attempt_file"
              return 1
            }

            attempt_file="${TMPDIR:-/tmp}/ci-docker-seed-attempts-default"
            rm -f "$attempt_file"
            ci_docker_install_seed_instance test exec -T php-fpm php index.php console install
            BASH
            ,
            '',
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('console install failed after 3 attempts.', $result['stderr']);
    }

    public function testCiDockerInstallSeedInstanceHonorsRetryOverride(): void
    {
        $result = $this->runShellScript(
            <<<'BASH'
            set -euo pipefail
            source "$REPO_ROOT/scripts/ci/docker_compose_helpers.sh"
            sleep() { :; }
            ci_docker_compose() {
              attempt_file="${TMPDIR:-/tmp}/ci-docker-seed-attempts-override"
              attempts=0
              if [[ -f "$attempt_file" ]]; then
                attempts="$(cat "$attempt_file")"
              fi
              attempts=$((attempts + 1))
              printf '%s' "$attempts" > "$attempt_file"
              return 1
            }

            attempt_file="${TMPDIR:-/tmp}/ci-docker-seed-attempts-override"
            rm -f "$attempt_file"
            CI_DOCKER_INSTALL_SEED_MAX_ATTEMPTS=5 ci_docker_install_seed_instance test exec -T php-fpm php index.php console install
            BASH
            ,
            '',
        );

        self::assertNotSame(0, $result['exit_code']);
        self::assertStringContainsString('console install failed after 5 attempts.', $result['stderr']);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runShellScript(string $script, string $changedPaths): array
    {
        $pathsFile = tempnam(sys_get_temp_dir(), 'ci-paths-');
        self::assertIsString($pathsFile);
        file_put_contents($pathsFile, $changedPaths);

        try {
            return $this->runCommand(['bash', '-lc', $script], $this->repoRoot(), [
                'REPO_ROOT' => $this->repoRoot(),
                'CHANGED_PATHS_FILE' => $pathsFile,
            ]);
        } finally {
            if (is_file($pathsFile)) {
                unlink($pathsFile);
            }
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, ?string $cwd = null, array $env = []): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd ?? $this->repoRoot(), array_merge($_ENV, $env));
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

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}

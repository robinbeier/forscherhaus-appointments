<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DefenseCycleRunnerTest extends TestCase
{
    public function testInstrumentationPreservesErrexitAndCleanupOnEveryFailure(): void
    {
        foreach (
            [
                'success' => 0,
                'real_phpunit' => 0,
                'seed_failure' => 1,
                'phpunit_failure' => 23,
                'cleanup_failure' => 41,
                'both_fail' => 23,
                'report_failure' => 0,
            ]
            as $mode => $expectedExit
        ) {
            $directory = sys_get_temp_dir() . '/defense-lifecycle-' . bin2hex(random_bytes(8));
            mkdir($directory . '/scripts/ci', 0700, true);
            mkdir($directory . '/bin', 0700);
            copy(
                dirname(__DIR__, 3) . '/scripts/ci/run_defense_cycle.sh',
                $directory . '/scripts/ci/run_defense_cycle.sh',
            );
            copy(
                dirname(__DIR__, 3) . '/scripts/ci/defense_cycle_report.py',
                $directory . '/scripts/ci/defense_cycle_report.py',
            );
            file_put_contents(
                $directory . '/bin/git',
                <<<'SH'
                #!/bin/sh
                if [ "$1" = rev-parse ]; then
                  if [ "$2" = --show-toplevel ]; then printf '%s\n' "$FIXTURE_ROOT"; else printf '%040d\n' 1; fi
                fi
                SH
                ,
            );
            file_put_contents(
                $directory . '/bin/docker',
                "#!/bin/sh\nprintf '%s\\n' '\"unix:///var/run/docker.sock\"'\n",
            );
            chmod($directory . '/bin/git', 0700);
            chmod($directory . '/bin/docker', 0700);
            file_put_contents(
                $directory . '/scripts/ci/docker_compose_helpers.sh',
                <<<'SH'
                ci_docker_claim_fresh_project() { :; }
                ci_docker_wait_for_service_exec() { :; }
                ci_docker_wait_for_mysql_readiness() { :; }
                ci_docker_wait_for_easyappointments_mysql_connectivity() { :; }
                ci_docker_install_seed_instance() {
                    printf 'seed\n' >> "$FIXTURE_ROOT/lifecycle"
                    if [[ "$FIXTURE_MODE" == seed_failure ]]; then
                        false
                        printf 'after-seed-failure\n' >> "$FIXTURE_ROOT/lifecycle"
                    fi
                }
                ci_docker_compose() {
                    if [[ "$*" == *vendor/bin/phpunit* ]]; then
                        printf 'phpunit\n' >> "$FIXTURE_ROOT/lifecycle"
                        if [[ "$FIXTURE_MODE" == real_phpunit || "$FIXTURE_MODE" == report_failure ]]; then
                            local previous="" option
                            local phpunit_command=(php "$FIXTURE_PHPUNIT" --no-configuration --do-not-cache-result --bootstrap "$FIXTURE_AUTOLOAD")
                            for option in "$@"; do
                                if [[ "$previous" == --log-junit ]]; then
                                    phpunit_command+=(--log-junit "$FIXTURE_ROOT${option#/var/www/html}")
                                fi
                                previous="$option"
                            done
                            "${phpunit_command[@]}" "$FIXTURE_ROOT/FixtureOrdinaryTest.php"
                            return $?
                        fi
                        printf '<testsuite><testcase class="Fixture" name="ordinary" time="0.01"/></testsuite>' > "$DEFENSE_CYCLE_JUNIT"
                        if [[ "$FIXTURE_MODE" == phpunit_failure || "$FIXTURE_MODE" == both_fail ]]; then return 23; fi
                    fi
                    return 0
                }
                ci_docker_cleanup_stack() {
                    printf 'cleanup\n' >> "$FIXTURE_ROOT/lifecycle"
                    if [[ "$FIXTURE_MODE" == cleanup_failure || "$FIXTURE_MODE" == both_fail ]]; then return 41; fi
                    return 0
                }
                SH
                ,
            );
            file_put_contents(
                $directory . '/FixtureOrdinaryTest.php',
                '<?php final class FixtureOrdinaryTest extends \PHPUnit\Framework\TestCase { public function testOrdinary(): void { self::assertTrue(true); } }',
            );
            if ($mode === 'report_failure') {
                mkdir($directory . '/storage/logs/ci', 0700, true);
                file_put_contents($directory . '/storage/logs/ci/defense-cycle', 'occupied path');
            }
            $environment = array_merge(getenv(), [
                'PATH' => $directory . '/bin:' . getenv('PATH'),
                'FIXTURE_ROOT' => $directory,
                'FIXTURE_MODE' => $mode,
                'FIXTURE_PHPUNIT' => dirname(__DIR__, 3) . '/vendor/bin/phpunit',
                'FIXTURE_AUTOLOAD' => dirname(__DIR__, 3) . '/vendor/autoload.php',
            ]);
            foreach (
                [
                    'CI_DOCKER_COMPOSE_PROJECT_NAME',
                    'COMPOSE_PROJECT_NAME',
                    'COMPOSE_FILE',
                    'EA_MYSQL_DATA_PATH',
                    'EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH',
                    'DOCKER_HOST',
                    'DOCKER_CONTEXT',
                    'EA_LOCAL_CI_PORTLESS_COMPOSE',
                ]
                as $key
            ) {
                unset($environment[$key]);
            }
            try {
                $process = proc_open(
                    ['/bin/bash', 'scripts/ci/run_defense_cycle.sh'],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    $directory,
                    $environment,
                );
                self::assertIsResource($process);
                stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame($expectedExit, proc_close($process), $mode . ': ' . $error);
                $events = file_get_contents($directory . '/lifecycle');
                self::assertSame(1, substr_count($events, 'cleanup'), $mode);
                self::assertStringNotContainsString('after-seed-failure', $events);
                if ($mode === 'seed_failure') {
                    self::assertStringNotContainsString('phpunit', $events);
                }
                if ($mode === 'report_failure') {
                    self::assertStringContainsString('summary report unavailable', $error);
                } else {
                    $paths = glob($directory . '/storage/logs/ci/defense-cycle/*.summary.json');
                    self::assertCount(1, $paths, $mode);
                    $report = json_decode(file_get_contents($paths[0]), true, flags: JSON_THROW_ON_ERROR);
                    self::assertSame(
                        in_array($mode, ['success', 'real_phpunit'], true) ? 'passed' : 'failed',
                        $report['overall_status'],
                    );
                    self::assertSame(
                        in_array($mode, ['cleanup_failure', 'both_fail'], true) ? 'failed' : 'passed',
                        $report['cleanup']['status'],
                    );
                    foreach ($report['phases'] as $phase) {
                        self::assertIsInt($phase['duration_ms']);
                        self::assertGreaterThanOrEqual(0, $phase['duration_ms']);
                    }
                }
            } finally {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($items as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($directory);
            }
        }
    }

    public function testCallerRuntimeOverridesAreRejectedBeforeDockerRuns(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/defense-runner-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/docker', "#!/bin/sh\necho docker-called\nexit 97\n");
        chmod($directory . '/docker', 0700);
        try {
            foreach (
                [
                    'CI_DOCKER_COMPOSE_PROJECT_NAME',
                    'COMPOSE_PROJECT_NAME',
                    'COMPOSE_FILE',
                    'EA_MYSQL_DATA_PATH',
                    'EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH',
                    'EA_LOCAL_CI_PORTLESS_COMPOSE',
                    'DOCKER_HOST',
                    'DOCKER_CONTEXT',
                ]
                as $key
            ) {
                $environment = array_merge(getenv(), [
                    'PATH' => $directory . ':' . getenv('PATH'),
                    $key => 'caller-value',
                ]);
                $process = proc_open(
                    ['/bin/bash', 'scripts/ci/run_defense_cycle.sh'],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    $root,
                    $environment,
                );
                self::assertIsResource($process);
                $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(1, proc_close($process), $key);
                self::assertStringContainsString('Refusing caller-owned Docker runtime configuration.', $output);
                self::assertStringNotContainsString('docker-called', $output);
            }
        } finally {
            unlink($directory . '/docker');
            rmdir($directory);
        }
    }

    public function testRejectsUnavailableDockerContextBeforeLifecycleCommands(): void
    {
        $this->assertDockerContextRejected('exit 41', 'Refusing unavailable Docker context endpoint.');
    }

    public function testRejectsNonLocalDockerContextBeforeLifecycleCommands(): void
    {
        $this->assertDockerContextRejected(
            "printf '%s\\n' '\"ssh://remote.example\"'",
            'Refusing non-local Docker context endpoint.',
        );
    }

    public function testRejectsMalformedDockerContextBeforeLifecycleCommands(): void
    {
        $this->assertDockerContextRejected("printf '%s\\n' malformed", 'Refusing non-local Docker context endpoint.');
    }

    public function testRejectsNullDockerContextBeforeLifecycleCommands(): void
    {
        $this->assertDockerContextRejected("printf '%s\\n' null", 'Refusing non-local Docker context endpoint.');
    }

    public function testRejectsEmptyDockerContextBeforeLifecycleCommands(): void
    {
        $this->assertDockerContextRejected(':', 'Refusing non-local Docker context endpoint.');
    }

    public function testPinsLocalDockerContextEndpointForLifecycleCommands(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/defense-endpoint-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents(
            $directory . '/docker',
            <<<'SH'
            #!/bin/sh
            if [ "$1" = context ] && [ "$2" = inspect ]; then
                printf '%s\n' '"unix:///var/run/docker.sock"'
                exit 0
            fi
            printf 'pinned:%s\n' "${DOCKER_HOST-unset}" >> "$DEFENSE_ENDPOINT_LOG"
            exit 97
            SH
            ,
        );
        chmod($directory . '/docker', 0700);
        $log = $directory . '/events';
        try {
            $environment = array_merge(getenv(), [
                'PATH' => $directory . ':' . getenv('PATH'),
                'DEFENSE_ENDPOINT_LOG' => $log,
            ]);
            $process = proc_open(
                ['/bin/bash', 'scripts/ci/run_defense_cycle.sh'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                $environment,
            );
            self::assertIsResource($process);
            stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(1, proc_close($process), $error);
            self::assertSame("pinned:unix:///var/run/docker.sock\n", file_get_contents($log));
        } finally {
            unlink($directory . '/docker');
            if (is_file($log)) {
                unlink($log);
            }
            rmdir($directory);
        }
    }

    private function assertDockerContextRejected(string $mockBody, string $expectedMessage): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/defense-endpoint-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $mock = <<<'SH'
        #!/bin/sh
        if [ "$1" = context ] && [ "$2" = inspect ]; then
        __MOCK_BODY__
        exit 0
        fi
        echo lifecycle-called
        exit 97
        SH;
        file_put_contents($directory . '/docker', str_replace('__MOCK_BODY__', $mockBody, $mock));
        chmod($directory . '/docker', 0700);
        try {
            $process = proc_open(
                ['/bin/bash', 'scripts/ci/run_defense_cycle.sh'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                array_merge(getenv(), ['PATH' => $directory . ':' . getenv('PATH')]),
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(1, proc_close($process));
            self::assertStringContainsString($expectedMessage, $output);
            self::assertStringNotContainsString('lifecycle-called', $output);
        } finally {
            unlink($directory . '/docker');
            rmdir($directory);
        }
    }

    public function testFullGateKeepsParentRuntimeOverridesButDoesNotPassThemToIndependentChild(): void
    {
        $root = dirname(__DIR__, 3);
        $script = file_get_contents($root . '/scripts/ci/pre_pr_full.sh');
        $start = strpos($script, 'echo_section "Ordinary defense-cycle HTTP and session tests"');
        $end = strpos($script, 'echo_section "Run quick pre-PR gate"', $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $snippet = substr($script, $start, $end - $start);
        $directory = sys_get_temp_dir() . '/defense-parent-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents(
            $directory . '/bash',
            <<<'SH'
            #!/bin/sh
            printf 'child:%s:%s:%s:%s:%s:%s\n' "${CI_DOCKER_COMPOSE_PROJECT_NAME-unset}" "${COMPOSE_PROJECT_NAME-unset}" "${COMPOSE_FILE-unset}" "${EA_MYSQL_DATA_PATH-unset}" "${EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH-unset}" "${EA_LOCAL_CI_PORTLESS_COMPOSE-unset}"
            SH
            ,
        );
        chmod($directory . '/bash', 0700);
        try {
            $program =
                "set -eu\necho_section() { :; }\n" .
                $snippet .
                '\nprintf "parent:%s:%s:%s:%s:%s:%s\\n" "$CI_DOCKER_COMPOSE_PROJECT_NAME" "$COMPOSE_PROJECT_NAME" "$COMPOSE_FILE" "$EA_MYSQL_DATA_PATH" "$EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH" "$EA_LOCAL_CI_PORTLESS_COMPOSE"';
            $program = str_replace('\\n' . 'printf', "\nprintf", $program);
            $process = proc_open(
                ['/bin/bash', '-c', $program],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                array_merge(getenv(), [
                    'PATH' => $directory . ':' . getenv('PATH'),
                    'CI_DOCKER_COMPOSE_PROJECT_NAME' => 'parent-project',
                    'COMPOSE_PROJECT_NAME' => 'parent-compose',
                    'COMPOSE_FILE' => 'parent-compose-file',
                    'EA_MYSQL_DATA_PATH' => 'parent-data',
                    'EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH' => 'parent-override',
                    'EA_LOCAL_CI_PORTLESS_COMPOSE' => '0',
                ]),
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame(
                "child:unset:unset:unset:unset:unset:unset\nparent:parent-project:parent-compose:parent-compose-file:parent-data:parent-override:0\n",
                $output,
            );
        } finally {
            unlink($directory . '/bash');
            rmdir($directory);
        }
    }
}

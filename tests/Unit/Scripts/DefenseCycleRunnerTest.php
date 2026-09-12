<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DefenseCycleRunnerTest extends TestCase
{
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

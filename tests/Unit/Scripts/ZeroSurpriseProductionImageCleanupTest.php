<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ZeroSurpriseProductionImageCleanupTest extends TestCase
{
    public function testPythonBehaviorMatrixPasses(): void
    {
        $root = dirname(__DIR__, 3);
        $result = $this->runProcess(
            [
                'python3',
                '-I',
                '-B',
                'tests/Unit/Scripts/zero_surprise_image_cleanup_v1_test.py',
                'scripts/ops/libexec/zero_surprise_image_cleanup_v1.py',
            ],
            $root,
        );

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Ran 8 tests', $result['stderr']);
        self::assertStringContainsString('OK', $result['stderr']);
    }

    public function testWrapperIsReadOnlyByDefaultAndStreamsExactRuntime(): void
    {
        $workspace = $this->workspace();
        try {
            $environment = $this->stubSsh($workspace);
            $result = $this->runWrapper([], $environment);

            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertStringContainsString('mode       : read-only', $result['stdout']);
            self::assertStringContainsString('"mode":"dry-run"', $result['stdout']);
            self::assertStringContainsString(
                "/usr/bin/python3 -I -B - 'dry-run'",
                (string) file_get_contents($environment['ROB458_SSH_LOG']),
            );
            self::assertSame(
                filesize(dirname(__DIR__, 3) . '/scripts/ops/libexec/zero_surprise_image_cleanup_v1.py'),
                (int) trim((string) file_get_contents($environment['ROB458_STDIN_LOG'])),
            );
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testExecuteRequiresExactConfirmationBeforeSsh(): void
    {
        $workspace = $this->workspace();
        try {
            $environment = $this->stubSsh($workspace);
            foreach (
                [['--execute'], ['--execute', '--confirm-live-write', 'ROB-449'], ['--confirm-live-write', 'ROB-458']]
                as $arguments
            ) {
                self::assertSame(1, $this->runWrapper($arguments, $environment)['exit']);
            }
            self::assertFileDoesNotExist($environment['ROB458_SSH_LOG']);

            $result = $this->runWrapper(['--execute', '--confirm-live-write', 'ROB-458'], $environment);
            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertStringContainsString('mode       : live-write', $result['stdout']);
            self::assertStringContainsString(
                "/usr/bin/python3 -I -B - 'execute'",
                (string) file_get_contents($environment['ROB458_SSH_LOG']),
            );
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testWrapperPreservesValidatedRetryableBlock(): void
    {
        $workspace = $this->workspace();
        try {
            $environment = $this->stubSsh($workspace, 'blocked');
            $result = $this->runWrapper([], $environment);
            self::assertSame(75, $result['exit'], $result['stderr']);
            self::assertStringContainsString('"status":"blocked"', $result['stdout']);
            self::assertStringContainsString('"reason":"global_lock_busy"', $result['stdout']);
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testValidatorRejectsNoncanonicalExtraOrContradictoryReports(): void
    {
        $valid = $this->report();
        self::assertSame(0, $this->validate($valid, 'dry-run', 0)['exit']);

        $extra = $valid;
        $extra['unexpected'] = true;
        self::assertSame(1, $this->validate($extra, 'dry-run', 0)['exit']);

        $contradictory = $valid;
        $contradictory['deleted_count'] = 1;
        $contradictory['mutation_performed'] = true;
        self::assertSame(1, $this->validate($contradictory, 'dry-run', 0)['exit']);

        $noncanonical = json_encode($valid, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        self::assertSame(1, $this->validateBytes($noncanonical, 'dry-run', 0)['exit']);
    }

    public function testContractNeverUsesBroadOrForcedPruneAndKeepsAggregateOutput(): void
    {
        $root = dirname(__DIR__, 3);
        $helper = (string) file_get_contents($root . '/scripts/ops/libexec/zero_surprise_image_cleanup_v1.py');
        $wrapper = (string) file_get_contents($root . '/scripts/ops/prod_zero_surprise_image_cleanup.sh');

        self::assertStringContainsString('self.docker(["image", "rm", image_id], 120)', $helper);
        self::assertStringNotContainsString('self.docker(["image", "prune"', $helper);
        self::assertStringNotContainsString('self.docker(["system", "prune"', $helper);
        self::assertStringNotContainsString('"--force"', $helper);
        self::assertStringContainsString('MAX_PROJECTS = 32', $helper);
        self::assertStringContainsString('MAX_IMAGES = 64', $helper);
        self::assertStringContainsString('--confirm-live-write ROB-458', $wrapper);
        self::assertStringContainsString('tuple(record) != REPORT_KEYS', $helper);
        self::assertNotFalse(strpos($helper, 'def validate_report('));
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'schema' => 'zero_surprise_image_cleanup.v1',
            'mode' => 'dry-run',
            'status' => 'pass',
            'reason' => null,
            'project_count' => 2,
            'candidate_count' => 3,
            'candidate_virtual_bytes' => 1234,
            'deleted_count' => 0,
            'free_bytes_before' => 1000,
            'free_bytes_after' => 1000,
            'freed_bytes' => 0,
            'cap_exceeded' => false,
            'mutation_performed' => false,
        ];
    }

    /** @param array<string, mixed> $record @return array{exit:int,stdout:string,stderr:string} */
    private function validate(array $record, string $mode, int $exit): array
    {
        return $this->validateBytes(
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            $mode,
            $exit,
        );
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function validateBytes(string $bytes, string $mode, int $exit): array
    {
        return $this->runProcess(
            [
                '/usr/bin/python3',
                '-I',
                '-B',
                'scripts/ops/libexec/zero_surprise_image_cleanup_v1.py',
                'validate',
                $mode,
                (string) $exit,
            ],
            dirname(__DIR__, 3),
            null,
            $bytes,
        );
    }

    /** @return array{ROB458_SSH_LOG:string,ROB458_STDIN_LOG:string,PATH:string,ROB458_SCENARIO:string} */
    private function stubSsh(string $workspace, string $scenario = 'pass'): array
    {
        $bin = $workspace . '/bin';
        mkdir($bin, 0777, true);
        $sshLog = $workspace . '/ssh.log';
        $stdinLog = $workspace . '/stdin.log';
        $script = <<<'BASH'
        #!/usr/bin/env bash
        set -eu
        bytes="$(wc -c | tr -d ' ')"
        printf '%s\n' "$*" > "${ROB458_SSH_LOG}"
        printf '%s\n' "$bytes" > "${ROB458_STDIN_LOG}"
        if [[ "$*" == *"'execute'"* ]]; then mode=execute; else mode=dry-run; fi
        if [[ "${ROB458_SCENARIO}" == blocked ]]; then
            printf '{"schema":"zero_surprise_image_cleanup.v1","mode":"%s","status":"blocked","reason":"global_lock_busy","project_count":0,"candidate_count":0,"candidate_virtual_bytes":0,"deleted_count":0,"free_bytes_before":null,"free_bytes_after":null,"freed_bytes":null,"cap_exceeded":false,"mutation_performed":false}\n' "$mode"
            exit 75
        fi
        printf '{"schema":"zero_surprise_image_cleanup.v1","mode":"%s","status":"pass","reason":null,"project_count":0,"candidate_count":0,"candidate_virtual_bytes":0,"deleted_count":0,"free_bytes_before":1000,"free_bytes_after":1000,"freed_bytes":0,"cap_exceeded":false,"mutation_performed":false}\n' "$mode"
        BASH;
        file_put_contents($bin . '/ssh', $script . "\n");
        chmod($bin . '/ssh', 0755);
        return [
            'ROB458_SSH_LOG' => $sshLog,
            'ROB458_STDIN_LOG' => $stdinLog,
            'ROB458_SCENARIO' => $scenario,
            'PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
        ];
    }

    /** @param list<string> $arguments @param array<string, string> $environment */
    private function runWrapper(array $arguments, array $environment): array
    {
        return $this->runProcess(
            array_merge(
                ['bash', 'scripts/ops/prod_zero_surprise_image_cleanup.sh', '--prod-ssh-target', 'prod.example'],
                $arguments,
            ),
            dirname(__DIR__, 3),
            $environment,
        );
    }

    /** @param list<string> $command @param array<string, string>|null $environment @return array{exit:int,stdout:string,stderr:string} */
    private function runProcess(array $command, string $cwd, ?array $environment = null, string $stdin = ''): array
    {
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $cwd,
            $environment === null ? null : array_merge($_ENV, $environment),
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }

    private function workspace(): string
    {
        $path = sys_get_temp_dir() . '/rob458-cleanup-' . bin2hex(random_bytes(8));
        mkdir($path, 0777, true);
        return $path;
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

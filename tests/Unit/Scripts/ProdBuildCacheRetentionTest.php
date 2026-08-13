<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProdBuildCacheRetentionTest extends TestCase
{
    public function testDryRunReportsAggregatePolicyWithoutPruning(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace);
            $result = $this->runScript([], $environment);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('mode       : read-only', $result['stdout']);
            self::assertStringContainsString('schema=prod_build_cache_retention.v1', $result['stdout']);
            self::assertStringContainsString('mode=dry-run', $result['stdout']);
            self::assertStringContainsString('min_age_hours=168', $result['stdout']);
            self::assertStringContainsString('keep_storage_bytes=2147483648', $result['stdout']);
            self::assertStringContainsString('broad_prune_allowed=no', $result['stdout']);
            self::assertStringContainsString('cache.record_count=12', $result['stdout']);
            self::assertStringContainsString('cache.total_bytes=4249000000', $result['stdout']);
            self::assertStringContainsString('cache.reclaimable_bytes=3459000000', $result['stdout']);
            self::assertStringContainsString('deletion_performed=no', $result['stdout']);
            self::assertStringContainsString('cleanup_candidate=observe', $result['stdout']);
            self::assertStringContainsString('status=pass', $result['stdout']);

            $commands = (string) file_get_contents($environment['DOCKER_LOG']);
            self::assertStringContainsString('builder prune --help', $commands);
            self::assertDoesNotMatchRegularExpression('/builder prune .*--force/', $commands);
            self::assertStringNotContainsString('image prune', $commands);
            self::assertStringNotContainsString('container prune', $commands);
            self::assertStringNotContainsString('volume prune', $commands);
            self::assertStringNotContainsString('system prune', $commands);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testExecuteRequiresExactLiveWriteConfirmationBeforeSsh(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace);

            foreach (
                [['--execute'], ['--execute', '--confirm-live-write', 'ROB-449'], ['--confirm-live-write', 'ROB-450']]
                as $arguments
            ) {
                $result = $this->runScript($arguments, $environment);
                self::assertSame(1, $result['exit_code']);
            }

            self::assertFileDoesNotExist($environment['SSH_LOG']);
            self::assertSame('', (string) file_get_contents($environment['DOCKER_LOG']));
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testExecuteUsesOnlyBoundedBuilderPruneAndVerifiesProtectedInventories(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace, [
                'CACHE_DF_AFTER' => $this->cacheDf('9', '2.1GB', '100MB (4%)'),
            ]);
            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('mode       : live-write', $result['stdout']);
            self::assertStringContainsString('mode=execute', $result['stdout']);
            self::assertStringContainsString('deletion_performed=yes', $result['stdout']);
            self::assertStringContainsString('cleanup_lock=acquired', $result['stdout']);
            self::assertStringContainsString('prune_exit_code=0', $result['stdout']);
            self::assertStringContainsString('cache.record_count=9', $result['stdout']);
            self::assertStringContainsString('cache.total_bytes=2100000000', $result['stdout']);
            self::assertStringContainsString('cache.reclaimable_bytes=100000000', $result['stdout']);
            self::assertStringContainsString('cache.freed_bytes=2149000000', $result['stdout']);
            self::assertStringContainsString('protected_inventory_unchanged=yes', $result['stdout']);
            self::assertStringContainsString('status=pass', $result['stdout']);

            $commands = (string) file_get_contents($environment['DOCKER_LOG']);
            self::assertSame(
                1,
                preg_match_all('/^builder prune --force --filter until=168h --keep-storage 2147483648$/m', $commands),
            );
            self::assertDoesNotMatchRegularExpression('/builder prune .*--all/', $commands);
            self::assertStringNotContainsString('system prune', $commands);
            self::assertStringNotContainsString('image prune', $commands);
            self::assertStringNotContainsString('container prune', $commands);
            self::assertStringNotContainsString('volume prune', $commands);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testExecuteSupportsReservedSpaceWithoutWeakeningPolicy(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace, [
                'PRUNE_SPACE_FLAG' => 'reserved-space',
            ]);
            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('prune_space_flag=reserved-space', $result['stdout']);
            self::assertStringContainsString(
                'builder prune --force --filter until=168h --reserved-space 2147483648',
                (string) file_get_contents($environment['DOCKER_LOG']),
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testUnsupportedPruneCapabilityFailsBeforeMutation(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace, [
                'PRUNE_SPACE_FLAG' => 'unsupported',
            ]);
            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);

            self::assertSame(2, $result['exit_code']);
            self::assertStringContainsString('status=blocked', $result['stdout']);
            self::assertStringContainsString('reason=prune_capability_unsupported', $result['stdout']);
            self::assertStringContainsString('deletion_performed=no', $result['stdout']);
            self::assertDoesNotMatchRegularExpression(
                '/builder prune .*--force/',
                (string) file_get_contents($environment['DOCKER_LOG']),
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testActiveProductionWorkFailsClosedBeforeMutation(): void
    {
        $workspace = $this->workspace();

        try {
            $procRoot = $workspace . '/proc';
            mkdir($procRoot . '/123', 0777, true);
            file_put_contents($procRoot . '/123/cmdline', "docker\0compose\0up\0");
            $environment = $this->prepareStubs($workspace, [
                'BUILD_CACHE_RETENTION_PROC_ROOT' => $procRoot,
            ]);
            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);

            self::assertSame(75, $result['exit_code']);
            self::assertStringContainsString('status=blocked', $result['stdout']);
            self::assertStringContainsString('reason=active_production_work', $result['stdout']);
            self::assertStringContainsString('deletion_performed=no', $result['stdout']);
            self::assertDoesNotMatchRegularExpression(
                '/builder prune .*--force/',
                (string) file_get_contents($environment['DOCKER_LOG']),
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testIdleBuildkitDaemonIsIgnoredButActiveBuildctlIsBlocked(): void
    {
        $workspace = $this->workspace();

        try {
            $procRoot = $workspace . '/proc';
            mkdir($procRoot . '/123', 0777, true);
            file_put_contents($procRoot . '/123/cmdline', "buildkitd\0--root\0/run/buildkit\0");
            $environment = $this->prepareStubs($workspace, [
                'BUILD_CACHE_RETENTION_PROC_ROOT' => $procRoot,
            ]);

            $idle = $this->runScript([], $environment);
            self::assertSame(0, $idle['exit_code'], $idle['stderr']);
            self::assertStringContainsString('activity_state=clear', $idle['stdout']);

            file_put_contents($procRoot . '/123/cmdline', "buildctl\0build\0--frontend\0dockerfile.v0\0");
            $active = $this->runScript([], $environment);
            self::assertSame(75, $active['exit_code']);
            self::assertStringContainsString('reason=active_production_work', $active['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testUnknownActivityStateFailsClosedBeforeInventoryOrMutation(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace, [
                'BUILD_CACHE_RETENTION_PROC_ROOT' => $workspace . '/missing-proc',
            ]);
            $result = $this->runScript([], $environment);

            self::assertSame(2, $result['exit_code']);
            self::assertStringContainsString('reason=activity_unknown', $result['stdout']);
            self::assertStringContainsString('deletion_performed=no', $result['stdout']);
            self::assertDoesNotMatchRegularExpression(
                '/builder prune .*--force/',
                (string) file_get_contents($environment['DOCKER_LOG']),
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testPruneFailureIsReportedAsAttemptedWithoutRawOutput(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace, [
                'PRUNE_FAIL' => '1',
                'PRUNE_STDOUT' => 'secret-prune-failure-output',
            ]);
            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);

            self::assertSame(2, $result['exit_code']);
            self::assertStringContainsString('reason=prune_failed', $result['stdout']);
            self::assertStringContainsString('deletion_performed=attempted', $result['stdout']);
            self::assertStringNotContainsString('secret-prune-failure-output', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testConcurrentExecuteIsRejectedByCleanupLock(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec('command -v flock')) === '') {
            self::markTestSkipped('Linux flock is required.');
        }

        $workspace = $this->workspace();
        $holder = null;
        $holderPipes = [];

        try {
            $environment = $this->prepareStubs($workspace);

            $holder = proc_open(
                ['flock', $environment['BUILD_CACHE_RETENTION_LOCK_DIR'], 'sleep', '10'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $holderPipes,
                $workspace,
            );
            self::assertIsResource($holder);
            usleep(200_000);

            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);

            self::assertSame(75, $result['exit_code']);
            self::assertStringContainsString('reason=cleanup_lock_busy', $result['stdout']);
            self::assertStringContainsString('deletion_performed=no', $result['stdout']);
            self::assertDoesNotMatchRegularExpression(
                '/builder prune .*--force/',
                (string) file_get_contents($environment['DOCKER_LOG']),
            );
        } finally {
            if (is_resource($holder)) {
                proc_terminate($holder);
                foreach ($holderPipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($holder);
            }
            $this->removeDirectory($workspace);
        }
    }

    public function testUnsafeCleanupLockDirectoryFailsBeforePrune(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace);
            chmod($environment['BUILD_CACHE_RETENTION_LOCK_DIR'], 0777);

            $wrongMode = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);
            self::assertSame(2, $wrongMode['exit_code']);
            self::assertStringContainsString('reason=cleanup_lock_unsafe', $wrongMode['stdout']);
            self::assertDoesNotMatchRegularExpression(
                '/builder prune .*--force/',
                (string) file_get_contents($environment['DOCKER_LOG']),
            );

            rmdir($environment['BUILD_CACHE_RETENTION_LOCK_DIR']);
            symlink($workspace, $environment['BUILD_CACHE_RETENTION_LOCK_DIR']);
            $symlink = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);
            self::assertSame(2, $symlink['exit_code']);
            self::assertStringContainsString('reason=cleanup_lock_unsafe', $symlink['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testBusyCanonicalProductionChangeLockBlocksPrune(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec('command -v flock')) === '') {
            self::markTestSkipped('Linux flock is required.');
        }

        $workspace = $this->workspace();
        $holder = null;
        $holderPipes = [];

        try {
            $environment = $this->prepareStubs($workspace);
            touch($environment['BUILD_CACHE_RETENTION_GLOBAL_LOCK_PATH']);
            chmod($environment['BUILD_CACHE_RETENTION_GLOBAL_LOCK_PATH'], 0600);
            $holder = proc_open(
                ['flock', $environment['BUILD_CACHE_RETENTION_GLOBAL_LOCK_PATH'], 'sleep', '10'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $holderPipes,
                $workspace,
            );
            self::assertIsResource($holder);
            usleep(200_000);

            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);

            self::assertSame(75, $result['exit_code']);
            self::assertStringContainsString('reason=active_production_work', $result['stdout']);
            self::assertStringContainsString('deletion_performed=no', $result['stdout']);
            self::assertDoesNotMatchRegularExpression(
                '/builder prune .*--force/',
                (string) file_get_contents($environment['DOCKER_LOG']),
            );
        } finally {
            if (is_resource($holder)) {
                proc_terminate($holder);
                foreach ($holderPipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($holder);
            }
            $this->removeDirectory($workspace);
        }
    }

    public function testInvalidInventoryAndProtectedInventoryChangeFailClosed(): void
    {
        $workspace = $this->workspace();

        try {
            $invalid = $this->prepareStubs($workspace, [
                'CACHE_DF_BEFORE' => "not-json\n",
            ]);
            $result = $this->runScript([], $invalid);
            self::assertSame(2, $result['exit_code']);
            self::assertStringContainsString('reason=cache_inventory_invalid', $result['stdout']);

            file_put_contents($invalid['DOCKER_LOG'], '');
            file_put_contents($invalid['DF_COUNTER'], '0');
            $changed = array_merge($invalid, [
                'CACHE_DF_BEFORE' => $this->cacheDf('12', '4.249GB', '3.459GB (81%)'),
                'MUTATE_PROTECTED_INVENTORY' => '1',
            ]);
            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $changed);
            self::assertSame(2, $result['exit_code']);
            self::assertStringContainsString('reason=protected_inventory_changed', $result['stdout']);
            self::assertStringContainsString('deletion_performed=yes', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testOutputNeverContainsDockerObjectNamesOrRawPruneOutput(): void
    {
        $workspace = $this->workspace();

        try {
            $environment = $this->prepareStubs($workspace, [
                'IMAGE_LIST' => "sha256:secret-image-name\n",
                'CONTAINER_LIST' => "secret-customer-container\n",
                'VOLUME_LIST' => "secret-database-volume\n",
                'PRUNE_STDOUT' => 'secret-cache-record-id',
            ]);
            $result = $this->runScript(['--execute', '--confirm-live-write', 'ROB-450'], $environment);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $combined = $result['stdout'] . $result['stderr'];
            self::assertStringNotContainsString('secret-image-name', $combined);
            self::assertStringNotContainsString('secret-customer-container', $combined);
            self::assertStringNotContainsString('secret-database-volume', $combined);
            self::assertStringNotContainsString('secret-cache-record-id', $combined);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function prepareStubs(string $workspace, array $overrides = []): array
    {
        $bin = $workspace . '/bin';
        mkdir($bin, 0777, true);
        $dockerLog = $workspace . '/docker.log';
        $sshLog = $workspace . '/ssh.log';
        $counter = $workspace . '/df-counter';
        $lockDirectory = $workspace . '/build-cache-lock';
        file_put_contents($dockerLog, '');
        file_put_contents($counter, '0');
        mkdir($lockDirectory, 0700);

        file_put_contents(
            $bin . '/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >>"${SSH_LOG}"
            remote_cmd=''
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o)
                        shift 2
                        ;;
                    *)
                        remote_cmd="$1"
                        shift
                        ;;
                esac
            done
            [[ -n "$remote_cmd" ]] || remote_cmd='bash -s'
            bash -c "$remote_cmd"
            BASH
            ,
        );
        chmod($bin . '/ssh', 0755);

        file_put_contents(
            $bin . '/docker',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >>"${DOCKER_LOG}"

            case "$*" in
                info)
                    exit 0
                    ;;
                'builder prune --help')
                    printf '%s\n' '      --force' '      --filter filter'
                    case "${PRUNE_SPACE_FLAG:-keep-storage}" in
                        keep-storage) printf '%s\n' '      --keep-storage bytes' ;;
                        reserved-space) printf '%s\n' '      --reserved-space bytes' ;;
                        unsupported) : ;;
                    esac
                    ;;
                'builder prune --force --filter until=168h --keep-storage 2147483648'|\
                'builder prune --force --filter until=168h --reserved-space 2147483648')
                    if [[ "${PRUNE_FAIL:-0}" != '0' ]]; then
                        printf '%s\n' "${PRUNE_STDOUT:-prune failed}" >&2
                        exit 1
                    fi
                    printf '%s\n' "${PRUNE_STDOUT:-Total reclaimed space: 2.149GB}"
                    ;;
                "system df --format {{json .}}")
                    count="$(cat "${DF_COUNTER}")"
                    if [[ "$count" == '0' ]]; then
                        printf '%s' "${CACHE_DF_BEFORE}"
                    else
                        printf '%s' "${CACHE_DF_AFTER}"
                    fi
                    printf '%s' "$((count + 1))" >"${DF_COUNTER}"
                    ;;
                'image ls --all --quiet --no-trunc')
                    if [[ "${MUTATE_PROTECTED_INVENTORY:-0}" == '1' && "$(cat "${DF_COUNTER}")" -gt 1 ]]; then
                        printf '%s\n' 'sha256:changed-image'
                    else
                        printf '%s' "${IMAGE_LIST}"
                    fi
                    ;;
                'container ls --all --quiet --no-trunc')
                    printf '%s' "${CONTAINER_LIST}"
                    ;;
                'volume ls --quiet')
                    printf '%s' "${VOLUME_LIST}"
                    ;;
                *)
                    printf 'unexpected docker command\n' >&2
                    exit 97
                    ;;
            esac
            BASH
            ,
        );
        chmod($bin . '/docker', 0755);

        file_put_contents(
            $bin . '/stat',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [[ "$#" -eq 3 && "$1" == '-Lc' && "$2" == '%F|%a|%u|%h' ]]; then
                metadata="$(/usr/bin/stat "$@")"
                IFS='|' read -r file_type mode _owner links <<<"$metadata"
                printf '%s|%s|0|%s\n' "$file_type" "$mode" "$links"
                exit 0
            fi

            if [[ "$#" -eq 3 && "$1" == '-Lc' && "$2" == '%F|%a|%u|%d|%i' ]]; then
                metadata="$(/usr/bin/stat "$@")"
                IFS='|' read -r file_type mode _owner device inode <<<"$metadata"
                printf '%s|%s|0|%s|%s\n' "$file_type" "$mode" "$device" "$inode"
                exit 0
            fi

            if [[ "$#" -eq 3 && "$1" == '-Lc' && "$2" == '%F|%a|%u|%h|%d|%i' ]]; then
                metadata="$(/usr/bin/stat "$@")"
                IFS='|' read -r file_type mode _owner links device inode <<<"$metadata"
                printf '%s|%s|0|%s|%s|%s\n' "$file_type" "$mode" "$links" "$device" "$inode"
                exit 0
            fi

            exec /usr/bin/stat "$@"
            BASH
            ,
        );
        chmod($bin . '/stat', 0755);

        if (trim((string) shell_exec('command -v sha256sum')) === '') {
            file_put_contents(
                $bin . '/sha256sum',
                <<<'BASH'
                #!/usr/bin/env bash
                exec shasum -a 256 "$@"
                BASH
                ,
            );
            chmod($bin . '/sha256sum', 0755);
        }

        $environment = [
            'PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
            'DOCKER_LOG' => $dockerLog,
            'SSH_LOG' => $sshLog,
            'DF_COUNTER' => $counter,
            'BUILD_CACHE_RETENTION_LOCK_DIR' => $lockDirectory,
            'BUILD_CACHE_RETENTION_GLOBAL_LOCK_PATH' => $workspace . '/global-production-change.lock',
            'CACHE_DF_BEFORE' => $this->cacheDf('12', '4.249GB', '3.459GB (81%)'),
            'CACHE_DF_AFTER' => $this->cacheDf('12', '4.249GB', '3.459GB (81%)'),
            'IMAGE_LIST' => "sha256:image-a\nsha256:image-b\n",
            'CONTAINER_LIST' => "container-a\ncontainer-b\n",
            'VOLUME_LIST' => "volume-a\nvolume-b\n",
        ];

        return array_merge($environment, $overrides);
    }

    private function cacheDf(string $count, string $size, string $reclaimable): string
    {
        return json_encode(
            [
                'Type' => 'Images',
                'TotalCount' => '2',
                'Size' => '1GB',
                'Reclaimable' => '0B (0%)',
            ],
            JSON_THROW_ON_ERROR,
        ) .
            "\n" .
            json_encode(
                [
                    'Type' => 'Build Cache',
                    'TotalCount' => $count,
                    'Size' => $size,
                    'Reclaimable' => $reclaimable,
                ],
                JSON_THROW_ON_ERROR,
            ) .
            "\n";
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runScript(array $arguments, array $environment): array
    {
        return $this->runCommand(
            array_merge(
                ['bash', 'scripts/ops/prod_build_cache_retention.sh', '--prod-ssh-target', 'prod.example'],
                $arguments,
            ),
            $environment,
        );
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, array $environment): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot(),
            array_merge($_ENV, $environment),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function workspace(): string
    {
        $workspace = sys_get_temp_dir() . '/prod-build-cache-retention-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace, 0777, true));

        return $workspace;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
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
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}

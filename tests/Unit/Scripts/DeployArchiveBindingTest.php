<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeployArchiveBindingTest extends TestCase
{
    public function testNonDryCliRequiresIndependentArchiveDigest(): void
    {
        $result = $this->runShell(
            'bash deploy_ea.sh --rel ea_contract --require-zero-surprise 0 --zero-surprise-canary-enabled 0 2>&1',
        );

        self::assertSame(30, $result['exit_code']);
        self::assertStringContainsString('--archive-sha256 is required', $result['stdout']);
    }

    public function testNonDryBindingCopiesAndVerifiesPinnedArchiveBeforeExtraction(): void
    {
        $script = <<<'BASH'
        set -eu
        if [[ "$(id -u)" == "0" ]]; then
          fixture="$(mktemp -d /root/fh-deploy-archive-test.XXXXXX)"
        else
          fixture="$(mktemp -d)"
        fi
        trap 'rm -rf "$fixture"' EXIT
        archive="$fixture/release.tar.gz"
        bound="$fixture/stage.archive-bound.tar.gz"
        mkdir -p "$fixture/stage"
        printf 'trusted release bytes\n' > "$archive"
        expected="$(sha256sum -- "$archive" | awk '{print $1}')"
        source ./deploy_ea.sh
        DRYRUN=0
        ARCHIVE="$archive"
        BOUND_ARCHIVE="$bound"
        ARCHIVE_SHA256="$expected"
        bind_archive_snapshot
        cmp -- "$archive" "$bound"
        printf 'changed source bytes\n' > "$archive"
        cmp -- "$bound" <(printf 'trusted release bytes\n')
        cleanup_bound_archive
        test ! -e "$bound"
        BASH;

        $result = $this->runShell($script);

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testBindingRejectsMismatchedPinnedDigestWithoutCreatingSnapshot(): void
    {
        $script = <<<'BASH'
        set -eu
        fixture="$(mktemp -d)"
        trap 'rm -rf "$fixture"' EXIT
        archive="$fixture/release.tar.gz"
        bound="$fixture/stage.archive-bound.tar.gz"
        mkdir -p "$fixture/stage"
        printf 'release bytes\n' > "$archive"
        source ./deploy_ea.sh
        DRYRUN=0
        ARCHIVE="$archive"
        BOUND_ARCHIVE="$bound"
        ARCHIVE_SHA256="$(printf '0%.0s' {1..64})"
        set +e
        ( bind_archive_snapshot )
        status=$?
        set -e
        test "$status" -eq 1
        test ! -e "$bound"
        BASH;

        $result = $this->runShell($script);

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testBindingCleansPartialSnapshotWhenInstallFails(): void
    {
        $script = <<<'BASH'
        set -eu
        fixture="$(mktemp -d)"
        trap 'rm -rf "$fixture"' EXIT
        archive="$fixture/release.tar.gz"
        bound="$fixture/stage.archive-bound.tar.gz"
        mkdir -p "$fixture/stage"
        printf 'release bytes\n' > "$archive"
        source ./deploy_ea.sh
        cat() { printf 'partial bytes\n'; return 1; }
        DRYRUN=0
        ARCHIVE="$archive"
        BOUND_ARCHIVE="$bound"
        ARCHIVE_SHA256="$(sha256sum -- "$archive" | awk '{print $1}')"
        set +e
        ( bind_archive_snapshot )
        status=$?
        set -e
        test "$status" -eq 1
        test ! -e "$bound"
        BASH;

        $result = $this->runShell($script);

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testPreexistingSnapshotIsNeverRemovedByBindingFailure(): void
    {
        $script = <<<'BASH'
        set -eu
        fixture="$(mktemp -d)"
        trap 'rm -rf "$fixture"' EXIT
        archive="$fixture/release.tar.gz"
        bound="$fixture/stage.archive-bound.tar.gz"
        mkdir -p "$fixture/stage"
        printf 'release bytes\n' > "$archive"
        printf 'preexisting bytes\n' > "$bound"
        source ./deploy_ea.sh
        DRYRUN=0
        ARCHIVE="$archive"
        BOUND_ARCHIVE="$bound"
        ARCHIVE_SHA256="$(sha256sum -- "$archive" | awk '{print $1}')"
        set +e
        ( bind_archive_snapshot )
        status=$?
        set -e
        test "$status" -eq 1
        test "$(cat -- "$bound")" = 'preexisting bytes'
        BASH;

        $result = $this->runShell($script);

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    /** @return array{stdout:string,stderr:string,exit_code:int} */
    private function runShell(string $script): array
    {
        $process = proc_open(
            ['bash', '-c', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
            'exit_code' => proc_close($process),
        ];
    }
}

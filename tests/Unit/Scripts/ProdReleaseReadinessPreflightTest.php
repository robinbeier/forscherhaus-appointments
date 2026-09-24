<?php
declare(strict_types=1);
namespace Tests\Unit\Scripts;
use PHPUnit\Framework\TestCase;

final class ProdReleaseReadinessPreflightTest extends TestCase
{
    private string $root = '';
    private string $app = '';
    private string $lockPath = '';
    /** @var list<string> */ private array $helpers = [];

    protected function setUp(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            self::markTestSkipped('root fixture required');
        }
        $this->root = '/root/release-readiness-test-' . bin2hex(random_bytes(6));
        $this->app = $this->root . '/app';
        mkdir($this->app, 0755, true);
        mkdir($this->root . '/locks', 0700, true);
        chmod($this->root . '/locks', 0700);
        mkdir($this->root . '/ordinary', 0700, true);
        mkdir($this->root . '/bin', 0755, true);
        chmod($this->root, 0700);
        file_put_contents($this->app . '/_RELEASE', "ea_active  2020-01-01T00:00:00Z\n");
        chmod($this->app . '/_RELEASE', 0644);
        $this->lockPath = $this->root . '/locks/fh-production-change.lock';
        file_put_contents($this->lockPath, '');
        chmod($this->lockPath, 0600);
        $helperBindings = [
            '/root/deploy_ea.sh',
            '/usr/local/libexec/fh-backup-set-producer-v1',
            '/usr/local/libexec/fh-backup-timer-transition-v1',
            '/usr/local/libexec/fh/deployment_dump_attestation_v1.py',
        ];
        $sourceFiles = [
            dirname(__DIR__, 3) . '/deploy_ea.sh',
            dirname(__DIR__, 3) . '/scripts/ops/libexec/backup_set_producer_v1.py',
            dirname(__DIR__, 3) . '/scripts/ops/libexec/backup_timer_transition_v1.py',
            dirname(__DIR__, 3) . '/scripts/ops/libexec/deployment_dump_attestation_v1.py',
        ];
        $helperFixtures = [
            $this->root . '/deploy_ea.sh',
            $this->root . '/backup-set-producer-v1',
            $this->root . '/backup-timer-transition-v1',
            $this->root . '/deployment_dump_attestation_v1.py',
        ];
        foreach ($helperFixtures as $index => $path) {
            file_put_contents($path, file_get_contents($sourceFiles[$index]));
            chmod($path, 0555);
            $this->helpers[] = $helperBindings[$index] . '=' . hash_file('sha256', $sourceFiles[$index]);
        }
        file_put_contents(
            $this->root . '/bin/systemctl',
            <<<'SH'
            #!/bin/sh
            unit="$*"; name="$2"
            case "$name" in
              fh-defense-ordinary-cleanup.timer) printf 'LoadState=not-found\nActiveState=inactive\nSubState=dead\nUnitFileState=\nResult=success\n' ;;
              fh-release-archive-dump-retention.timer) printf 'LoadState=loaded\nActiveState=inactive\nSubState=dead\nUnitFileState=disabled\nResult=success\n' ;;
              *) if [ "${SYSTEMCTL_MODE:-ok}" = timer_bad ] && [ "$name" = fh-backup-set-continuity.timer ]; then printf 'LoadState=loaded\nActiveState=inactive\nSubState=dead\nUnitFileState=enabled\nResult=success\n'; else printf 'LoadState=loaded\nActiveState=active\nSubState=waiting\nUnitFileState=enabled\nResult=success\n'; fi ;;
            esac
            exit 0
            SH
            ,
        );
        chmod($this->root . '/bin/systemctl', 0755);
        file_put_contents($this->root . '/bin/git', "#!/bin/sh\nexit 0\n");
        chmod($this->root . '/bin/git', 0755);
        $ssh = sprintf(
            <<<'SH'
            #!/usr/bin/env bash
            while [[ $# -gt 0 && "$1" != bash ]]; do shift; done
            [[ "$1" == bash ]] || exit 97
            shift 3
            base=("${@:1:8}")
            base[0]=%s
            base[2]=%s
            base[3]=%s
            shift 8
            mapped=()
            for spec in "$@"; do
              case "$spec" in
                /root/deploy_ea.sh=*) mapped+=("%s=${spec#*=}") ;;
                /usr/local/libexec/fh-backup-set-producer-v1=*) mapped+=("%s=${spec#*=}") ;;
                /usr/local/libexec/fh-backup-timer-transition-v1=*) mapped+=("%s=${spec#*=}") ;;
                /usr/local/libexec/fh/deployment_dump_attestation_v1.py=*) mapped+=("%s=${spec#*=}") ;;
                *) mapped+=("$spec") ;;
              esac
            done
            exec bash -s -- "${base[@]}" "${mapped[@]}"
            SH
            ,
            var_export($this->app, true),
            var_export($this->lockPath, true),
            var_export($this->root . '/ordinary/request-unconfirmed', true),
            $helperFixtures[0],
            $helperFixtures[1],
            $helperFixtures[2],
            $helperFixtures[3],
        );
        file_put_contents($this->root . '/bin/ssh', $ssh);
        chmod($this->root . '/bin/ssh', 0755);
    }

    protected function tearDown(): void
    {
        if ($this->root === '') {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        @rmdir($this->root);
    }

    public function testRemotePayloadSuccessAndSecretFreeOutput(): void
    {
        [$status, $out, $err] = $this->executePreflight();
        self::assertSame(0, $status, $out . $err);
        self::assertStringContainsString('status=passed', $out);
        self::assertStringNotContainsString('2020-01-01', $out . $err);
    }

    public function testWrongMarkerAndHelperHashFailClosed(): void
    {
        file_put_contents($this->app . '/_RELEASE', "ea_other  2020-01-01T00:00:00Z\n");
        chmod($this->app . '/_RELEASE', 0644);
        [$status, $out] = $this->executePreflight();
        self::assertSame(20, $status);
        self::assertStringContainsString('marker_release_mismatch', $out);
        file_put_contents($this->app . '/_RELEASE', "ea_active  2020-01-01T00:00:00Z\n");
        chmod($this->app . '/_RELEASE', 0644);
        $fixture = $this->root . '/deploy_ea.sh';
        file_put_contents($fixture, 'changed');
        chmod($fixture, 0555);
        [$status, $out] = $this->executePreflight();
        self::assertSame(20, $status);
        self::assertStringContainsString('helper_hash_mismatch', $out);
        file_put_contents($fixture, file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh'));
        chmod($fixture, 0555);
        $timerHelper = $this->root . '/backup-timer-transition-v1';
        file_put_contents($timerHelper, 'changed');
        chmod($timerHelper, 0555);
        [$status, $out] = $this->executePreflight();
        self::assertSame(20, $status);
        self::assertStringContainsString('helper_hash_mismatch', $out);
    }

    public function testLockAndTimerContradictionsFailClosed(): void
    {
        chmod($this->lockPath, 0644);
        [$status, $out] = $this->executePreflight();
        self::assertSame(20, $status);
        self::assertStringContainsString('lock_identity_invalid', $out);
        chmod($this->lockPath, 0600);
        [$status, $out] = $this->executePreflight(['SYSTEMCTL_MODE' => 'timer_bad']);
        self::assertSame(20, $status);
        self::assertStringContainsString('timer_not_active', $out);
    }

    public function testNonCanonicalBindingsAreRejectedBeforeAnySshCall(): void
    {
        $sentinel = $this->root . '/ssh-called';
        file_put_contents($this->root . '/bin/ssh', "#!/bin/sh\ntouch " . escapeshellarg($sentinel) . "\nexit 0\n");
        chmod($this->root . '/bin/ssh', 0755);
        foreach (
            [['--prod-ssh-target', 'root@other-host'], ['--app-root', $this->app], ['--lock-path', $this->lockPath]]
            as $binding
        ) {
            $args = [
                'bash',
                'scripts/ops/prod_release_readiness_preflight.sh',
                '--expected-active-release',
                'ea_active',
                $binding[0],
                $binding[1],
            ];
            $pipes = [];
            $process = proc_open(
                $args,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 3),
                array_merge(getenv(), ['PATH' => $this->root . '/bin:' . getenv('PATH')]),
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertNotSame(0, proc_close($process), $binding[0]);
            self::assertFileDoesNotExist($sentinel, $binding[0]);
        }
    }

    public function testMarkerWithoutFinalNewlineUsesTheBoundedMarkerContract(): void
    {
        file_put_contents($this->app . '/_RELEASE', 'ea_active  2020-01-01T00:00:00Z');
        chmod($this->app . '/_RELEASE', 0644);
        [$status, $out, $err] = $this->executePreflight();
        self::assertSame(20, $status);
        self::assertStringContainsString('result_class=marker_format_unknown', $out);
    }

    public function testMalformedMarkerAndTransportFailureHaveClosedResultClasses(): void
    {
        file_put_contents($this->app . '/_RELEASE', "ea_active  2020-01-01T00:00:00Z extra\n");
        chmod($this->app . '/_RELEASE', 0644);
        [$status, $out] = $this->executePreflight();
        self::assertSame(20, $status);
        self::assertStringContainsString('result_class=marker_format_unknown', $out);

        file_put_contents($this->root . '/bin/ssh', "#!/bin/sh\nprintf 'private-transport-detail\\n' >&2\nexit 255\n");
        chmod($this->root . '/bin/ssh', 0755);
        [$status, $out, $err] = $this->executePreflight();
        self::assertSame(20, $status);
        self::assertStringContainsString('result_class=transport_or_receipt_unknown', $out);
        self::assertStringContainsString('source_marker=unverified', $out);
        self::assertStringNotContainsString('private-transport-detail', $out . $err);
    }

    /** @return array{int,string,string} */
    private function executePreflight(array $extraEnv = []): array
    {
        $args = [
            'bash',
            'scripts/ops/prod_release_readiness_preflight.sh',
            '--prod-ssh-target',
            'root@booking-server',
            '--expected-active-release',
            'ea_active',
        ];
        $pipes = [];
        $p = proc_open(
            $args,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            array_merge(getenv(), ['PATH' => $this->root . '/bin:' . getenv('PATH')], $extraEnv),
        );
        self::assertIsResource($p);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }
}

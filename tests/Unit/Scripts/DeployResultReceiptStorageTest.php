<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeployResultV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ops/lib/DeployResultV1.php';

final class DeployResultReceiptStorageTest extends TestCase
{
    private string $protectedDirectory;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Secure deploy-result storage requires Linux root.');
        }

        $this->protectedDirectory = '/root/fh-deploy-result-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->protectedDirectory, 0700));
        self::assertTrue(chmod($this->protectedDirectory, 0700));
    }

    protected function tearDown(): void
    {
        if (!isset($this->protectedDirectory) || !is_dir($this->protectedDirectory)) {
            return;
        }
        foreach (glob($this->protectedDirectory . '/*') ?: [] as $path) {
            if (is_dir($path) && !is_link($path)) {
                foreach (glob($path . '/*') ?: [] as $child) {
                    @unlink($child);
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        foreach (glob($this->protectedDirectory . '/.*.tmp') ?: [] as $temporary) {
            @unlink($temporary);
        }
        @chmod($this->protectedDirectory, 0700);
        @rmdir($this->protectedDirectory);
    }

    /** @param array{string,int,string,int} $case */
    #[DataProvider('outcomeProvider')]
    public function testPublishesEveryExactOutcomeAtomically(
        string $outcome,
        int $exitCode,
        string $phase,
        int $rollbackActive,
    ): void {
        $target = $this->protectedDirectory . '/result.json';
        $result = $this->runFinalize($target, $exitCode, $phase, $rollbackActive);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(
            ['schema' => 'deploy_result.v1', 'outcome' => $outcome, 'exit_code' => $exitCode],
            DeployResultV1::decode((string) file_get_contents($target)),
        );
        $stat = lstat($target);
        self::assertIsArray($stat);
        self::assertSame(0, $stat['uid']);
        self::assertSame(0, $stat['gid']);
        self::assertSame(0600, $stat['mode'] & 07777);
        self::assertSame(1, $stat['nlink']);
    }

    /** @return iterable<string,array{string,int,string,int}> */
    public static function outcomeProvider(): iterable
    {
        yield 'success' => ['succeeded', 0, 'switch_complete', 0];
        yield 'pre-switch failure' => ['failed_pre_switch', 30, 'before_switch', 0];
        yield 'internal rollback succeeded' => ['internal_rollback_succeeded', 30, 'switch_complete', 1];
        yield 'rollback failed' => ['rollback_failed_or_unverifiable', 31, 'switch_complete', 1];
        yield 'switch partial' => ['switch_recovery_required', 32, 'switch_partial', 0];
        yield 'pre-switch interrupted' => ['interrupted_pre_switch', 143, 'before_switch', 0];
    }

    public function testSigtermPublishesObservedInterruptedPreSwitchReceipt(): void
    {
        $target = $this->protectedDirectory . '/signal.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            deploy_result_trap_install
            deploy_result_receipt_prepare
            kill -TERM $$
            BASH
            ,
            [$target],
        );

        self::assertSame(143, $result['exit_code'], $result['stderr']);
        self::assertSame(
            'interrupted_pre_switch',
            DeployResultV1::decode((string) file_get_contents($target))['outcome'],
        );
    }

    #[DataProvider('stableControlPathProvider')]
    public function testReceiptFollowsRealStableSwitchAndRollbackControlPaths(
        string $body,
        int $expectedExitCode,
        string $expectedOutcome,
    ): void {
        $target = $this->protectedDirectory . '/control-path.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            deploy_result_receipt_prepare
            deploy_result_trap_install
            DRYRUN=0
            APP=/fixed/active
            PREV=/fixed/previous
            STAGE_ROOT=/fixed/stage
            eval "$2"
            BASH
            ,
            [$target, $body],
        );

        self::assertSame($expectedExitCode, $result['exit_code'], $result['stderr']);
        self::assertSame($expectedOutcome, DeployResultV1::decode((string) file_get_contents($target))['outcome']);
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function stableControlPathProvider(): iterable
    {
        yield 'first move failure' => [
            <<<'BASH'
            deploy_result_path_exists() {
              case "$1" in /fixed/active|/fixed/stage) return 0;; esac
              return 1
            }
            mv() { return 1; }
            perform_atomic_switch
            BASH
            ,
            30,
            'failed_pre_switch',
        ];
        yield 'second move failure' => [
            <<<'BASH'
            move_count=0
            mv() {
              move_count=$((move_count + 1))
              [[ "$move_count" -eq 1 ]]
            }
            perform_atomic_switch
            BASH
            ,
            32,
            'switch_recovery_required',
        ];
        yield 'completed switch success' => [
            <<<'BASH'
            mv() { return 0; }
            perform_atomic_switch
            exit 0
            BASH
            ,
            0,
            'succeeded',
        ];
        yield 'post-switch rollback success' => [
            <<<'BASH'
            mv() { return 0; }
            rollback_after_failure() { deploy_result_exit 30; }
            perform_atomic_switch
            false
            BASH
            ,
            30,
            'internal_rollback_succeeded',
        ];
        yield 'post-switch rollback unverifiable' => [
            <<<'BASH'
            mv() { return 0; }
            rollback_after_failure() { deploy_result_exit 31; }
            perform_atomic_switch
            false
            BASH
            ,
            31,
            'rollback_failed_or_unverifiable',
        ];
    }

    /** @param callable(string):void $createAttack */
    #[DataProvider('existingLeafAttackProvider')]
    public function testPreExistingLeafIsHardStopAndNeverOverwritten(string $attack): void
    {
        $protectedSource = $this->protectedDirectory . '/protected-source';
        self::assertSame(6, file_put_contents($protectedSource, 'marker'));
        self::assertTrue(chmod($protectedSource, 0600));
        $target = $this->protectedDirectory . '/result.json';
        match ($attack) {
            'regular' => self::assertTrue(copy($protectedSource, $target)),
            'symlink' => self::assertTrue(symlink($protectedSource, $target)),
            'hardlink' => self::assertTrue(link($protectedSource, $target)),
        };

        $before = file_get_contents($protectedSource);
        $result = $this->runPrepare($target);

        self::assertNotSame(0, $result['exit_code']);
        self::assertSame($before, file_get_contents($protectedSource));
        if ($attack === 'regular') {
            self::assertSame('marker', file_get_contents($target));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function existingLeafAttackProvider(): iterable
    {
        yield 'regular' => ['regular'];
        yield 'symlink' => ['symlink'];
        yield 'hardlink' => ['hardlink'];
    }

    /** @param callable(string):string $mutate */
    #[DataProvider('unsafePathProvider')]
    public function testUnsafeOrNonCanonicalPathsAreRejected(string $relativeTarget): void
    {
        $target = str_replace('__DIR__', $this->protectedDirectory, $relativeTarget);
        $result = $this->runPrepare($target);

        self::assertNotSame(0, $result['exit_code']);
    }

    /** @return iterable<string,array{string}> */
    public static function unsafePathProvider(): iterable
    {
        yield 'relative' => ['relative.json'];
        yield 'dot component' => ['__DIR__/./result.json'];
        yield 'parent component' => ['__DIR__/nested/../result.json'];
        yield 'double slash' => ['__DIR__//result.json'];
        yield 'trailing slash' => ['__DIR__/result.json/'];
        yield 'control character' => ["__DIR__/result\n.json"];
    }

    public function testSymlinkedOrWritableAncestorIsRejected(): void
    {
        $real = $this->protectedDirectory . '/real';
        self::assertTrue(mkdir($real, 0700));
        self::assertTrue(chmod($real, 0700));
        $link = $this->protectedDirectory . '/linked';
        self::assertTrue(symlink($real, $link));
        self::assertNotSame(0, $this->runPrepare($link . '/result.json')['exit_code']);

        self::assertTrue(unlink($link));
        self::assertTrue(chmod($real, 0770));
        self::assertNotSame(0, $this->runPrepare($real . '/result.json')['exit_code']);
    }

    public function testPinnedAncestorDriftPreventsPublication(): void
    {
        $target = $this->protectedDirectory . '/result.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            deploy_result_receipt_prepare
            chmod 0750 "$(dirname "$1")"
            DEPLOY_RESULT_PHASE=before_switch
            deploy_result_finalize 30
            BASH
            ,
            [$target],
        );
        self::assertTrue(chmod($this->protectedDirectory, 0700));

        self::assertSame(74, $result['exit_code']);
        self::assertFileDoesNotExist($target);
        self::assertStringContainsString('could not be durably published', $result['stderr']);
    }

    public function testLeafCreatedAfterPreparationIsNeverOverwritten(): void
    {
        $target = $this->protectedDirectory . '/result.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            deploy_result_receipt_prepare
            builtin printf 'stale' > "$1"
            chmod 0600 "$1"
            DEPLOY_RESULT_PHASE=before_switch
            deploy_result_finalize 30
            BASH
            ,
            [$target],
        );

        self::assertSame(74, $result['exit_code']);
        self::assertSame('stale', file_get_contents($target));
        self::assertStringContainsString('could not be durably published', $result['stderr']);
    }

    public function testAncestorReplacementAfterPreparationIsRejected(): void
    {
        $runDirectory = $this->protectedDirectory . '/run';
        self::assertTrue(mkdir($runDirectory, 0700));
        self::assertTrue(chmod($runDirectory, 0700));
        $target = $runDirectory . '/result.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            deploy_result_receipt_prepare
            parent="$(dirname "$1")"
            mv "$parent" "${parent}-old"
            mkdir -m 0700 "$parent"
            DEPLOY_RESULT_PHASE=before_switch
            deploy_result_finalize 30
            BASH
            ,
            [$target],
        );

        self::assertSame(74, $result['exit_code']);
        self::assertFileDoesNotExist($target);
        self::assertStringContainsString('could not be durably published', $result['stderr']);
    }

    public function testNonRootPublisherCannotCreateReceipt(): void
    {
        if (!is_executable('/usr/sbin/runuser')) {
            self::markTestSkipped('runuser is required for the non-root publisher check.');
        }
        $target = $this->protectedDirectory . '/result.json';
        $result = $this->runCommand([
            '/usr/sbin/runuser',
            '-u',
            'www-data',
            '--',
            'bash',
            '-c',
            'source ./deploy_ea.sh; DEPLOY_RESULT_RECEIPT_PATH="$1"; deploy_result_receipt_prepare',
            'bash',
            $target,
        ]);

        self::assertNotSame(0, $result['exit_code']);
        self::assertFileDoesNotExist($target);
        self::assertStringNotContainsString($target, $result['stdout'] . $result['stderr']);
    }

    /** @param bool $expectsReceipt */
    #[DataProvider('crashPointProvider')]
    public function testCrashBoundariesNeverExposePartialReceipt(string $crashPoint, bool $expectsReceipt): void
    {
        $target = $this->protectedDirectory . '/result.json';
        $result = $this->runFinalize($target, 30, 'before_switch', 0, $crashPoint);

        self::assertSame(74, $result['exit_code']);
        self::assertSame($expectsReceipt, is_file($target));
        if ($expectsReceipt) {
            self::assertSame(
                'failed_pre_switch',
                DeployResultV1::decode((string) file_get_contents($target))['outcome'],
            );
        }
    }

    /** @return iterable<string,array{string,bool}> */
    public static function crashPointProvider(): iterable
    {
        yield 'after temp create' => ['after_temp_create', false];
        yield 'after file fsync' => ['after_file_fsync', false];
        yield 'after publish' => ['after_publish', true];
        yield 'after parent fsync' => ['after_parent_fsync', true];
    }

    public function testParallelPublishersCannotClobberFirstReceipt(): void
    {
        $target = $this->protectedDirectory . '/parallel.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            fingerprint="$(deploy_result_receipt_storage prepare "$1")"
            barrier="$1.barrier"
            first='{"schema":"deploy_result.v1","outcome":"failed_pre_switch","exit_code":30}'$'\n'
            second='{"schema":"deploy_result.v1","outcome":"switch_recovery_required","exit_code":32}'$'\n'
            deploy_result_receipt_storage publish "$1" "$fingerprint" "$first" "" "" "$barrier" &
            first_pid=$!
            deploy_result_receipt_storage publish "$1" "$fingerprint" "$second" "" "" "$barrier" &
            second_pid=$!
            for _ in $(seq 1 500); do
              ready_count="$(find "$(dirname "$barrier")" -maxdepth 1 -name "$(basename "$barrier").ready.*" | wc -l)"
              [[ "$ready_count" -eq 2 ]] && break
              sleep 0.01
            done
            [[ "${ready_count:-0}" -eq 2 ]]
            : > "$barrier"
            set +e
            wait "$first_pid"; first_status=$?
            wait "$second_pid"; second_status=$?
            set -e
            builtin printf '%d,%d\n' "$first_status" "$second_status"
            [[ "$first_status,$second_status" == "0,1" || "$first_status,$second_status" == "1,0" ]]
            ! compgen -G "$(dirname "$1")/.deploy-result.*.tmp" >/dev/null
            BASH
            ,
            [$target],
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $statuses = trim($result['stdout']);
        self::assertContains($statuses, ['0,1', '1,0']);
        self::assertSame(
            $statuses === '0,1' ? 'failed_pre_switch' : 'switch_recovery_required',
            DeployResultV1::decode((string) file_get_contents($target))['outcome'],
        );
        self::assertStringNotContainsString($target, $result['stdout'] . $result['stderr']);
    }

    #[DataProvider('caughtPublicationFailureProvider')]
    public function testCaughtPublicationFailureReturns74AndRemovesOwnedLeafWhenProvable(
        string $failurePoint,
        bool $expectsCandidate,
    ): void {
        $target = $this->protectedDirectory . '/secret-token-failed-publication.json';
        $result = $this->runFinalize($target, 30, 'before_switch', 0, '', $failurePoint);

        self::assertSame(74, $result['exit_code'], $result['stderr']);
        self::assertSame($expectsCandidate, is_file($target));
        if ($expectsCandidate) {
            self::assertSame(
                'failed_pre_switch',
                DeployResultV1::decode((string) file_get_contents($target))['outcome'],
            );
        }
        self::assertStringNotContainsString($target, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('secret-token', $result['stdout'] . $result['stderr']);
        self::assertSame([], glob($this->protectedDirectory . '/.deploy-result.*.tmp') ?: []);
    }

    public function testCleanupRefusesAReplacedRootOwnedLeaf(): void
    {
        $target = $this->protectedDirectory . '/replaced.json';
        $result = $this->runFinalize($target, 30, 'before_switch', 0, '', 'replace_target_identity');

        self::assertSame(74, $result['exit_code'], $result['stderr']);
        self::assertSame("replacement\n", file_get_contents($target));
        self::assertSame([], glob($this->protectedDirectory . '/.deploy-result.*.tmp') ?: []);
    }

    #[DataProvider('publicationFailureTimingProvider')]
    public function testPublicationFailurePreservesObservedDeployTiming(
        int $deployExit,
        string $phase,
        int $rollbackActive,
        string $phaseStatus,
        string $timingOutcome,
    ): void {
        $target = $this->protectedDirectory . '/timing.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            DEPLOY_RESULT_PHASE="$2"
            DEPLOY_RESULT_ROLLBACK_ACTIVE="$3"
            DEPLOY_RESULT_RECEIPT_TEST_FAILURE_POINT=parent_fsync
            deploy_result_receipt_prepare
            deploy_result_trap_install
            deploy_timing_init deploy 0 receipt_test
            deploy_result_finish_with_timing "$4" "$5" "$6"
            BASH
            ,
            [$target, $phase, (string) $rollbackActive, (string) $deployExit, $phaseStatus, $timingOutcome],
        );

        self::assertSame(74, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('"outcome":"' . $timingOutcome . '"', $result['stdout']);
        self::assertStringContainsString('"exit_code":' . $deployExit, $result['stdout']);
        self::assertStringNotContainsString('"exit_code":74', $result['stdout']);
    }

    /** @return iterable<string,array{int,string,int,string,string}> */
    public static function publicationFailureTimingProvider(): iterable
    {
        yield 'success' => [0, 'switch_complete', 0, 'ok', 'succeeded'];
        yield 'pre-switch failure' => [30, 'before_switch', 0, 'failed', 'failed_pre_switch'];
        yield 'partial switch' => [32, 'switch_partial', 0, 'failed', 'failed_switch_recovery_required'];
        yield 'rollback success' => [30, 'switch_complete', 1, 'ok', 'rollback_succeeded'];
        yield 'rollback failure' => [31, 'switch_complete', 1, 'failed', 'rollback_failed'];
    }

    public function testRollbackIncidentIsEmittedBeforeReceiptPublicationFailure(): void
    {
        $target = $this->protectedDirectory . '/rollback-reporting.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            DEPLOY_RESULT_RECEIPT_TEST_FAILURE_POINT=parent_fsync
            DEPLOY_RESULT_PHASE=switch_complete
            DRYRUN=0
            APP=/root/fh-active
            PREV=/root/fh-previous
            REL=receipt-test
            WEBUSER=www-data
            CURRENT_SCRIPT_PATH=/root/deploy_ea.sh
            deploy_result_receipt_prepare
            deploy_result_trap_install
            deploy_timing_begin_rollback() { :; }
            bash() { return 0; }
            restart_renderer_service() { return 0; }
            probe_renderer_health() { return 0; }
            reload_services() { return 0; }
            probe_deep_health_contract() { return 0; }
            emit_zero_surprise_incident() { builtin printf 'incident-emitted\n'; }
            rollback_after_failure 'redacted failure'
            BASH
            ,
            [$target],
        );

        self::assertSame(74, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "incident-emitted\n"));
    }

    #[DataProvider('exitTrapPublicationFailureProvider')]
    public function testExitTrapPreservesObservedTimingWhenPublicationFails(
        string $body,
        string $expectedOutcome,
        int $expectedActionExit,
        int $expectedRollbackCount,
    ): void {
        $target = $this->protectedDirectory . '/exit-trap.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            DEPLOY_RESULT_RECEIPT_TEST_FAILURE_POINT=parent_fsync
            deploy_result_receipt_prepare
            deploy_result_trap_install
            deploy_timing_init deploy 0 receipt_exit_trap
            eval "$2"
            BASH
            ,
            [$target, $body],
        );

        self::assertSame(74, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('"outcome":"' . $expectedOutcome . '"', $result['stdout']);
        self::assertStringContainsString('"exit_code":' . $expectedActionExit, $result['stdout']);
        self::assertSame($expectedRollbackCount, substr_count($result['stdout'], "rollback-start\n"));
    }

    /** @return iterable<string,array{string,string,int,int}> */
    public static function exitTrapPublicationFailureProvider(): iterable
    {
        yield 'production success finalization before epilogue' => [
            <<<'BASH'
            DEPLOY_RESULT_PHASE=switch_complete
            deploy_result_finalize 0
            BASH
            ,
            'succeeded',
            0,
            0,
        ];
        yield 'production verified rollback finalization before reporting' => [
            <<<'BASH'
            DEPLOY_RESULT_PHASE=switch_complete
            DEPLOY_RESULT_ROLLBACK_ACTIVE=1
            builtin printf 'rollback-start\n'
            deploy_result_finalize 30
            BASH
            ,
            'rollback_succeeded',
            30,
            1,
        ];
        yield 'unhandled pre-switch failure' => ['false', 'failed_pre_switch', 30, 0];
        yield 'unhandled partial-switch failure' => [
            <<<'BASH'
            DEPLOY_RESULT_PHASE=switch_partial
            false
            BASH
            ,
            'failed_switch_recovery_required',
            32,
            0,
        ];
        yield 'unhandled post-switch failure with verified rollback' => [
            <<<'BASH'
            DEPLOY_RESULT_PHASE=switch_complete
            rollback_after_failure() {
              builtin printf 'rollback-start\n'
              DEPLOY_RESULT_ROLLBACK_ACTIVE=1
              deploy_result_finish_with_timing 30 ok rollback_succeeded
            }
            false
            BASH
            ,
            'rollback_succeeded',
            30,
            1,
        ];
        yield 'unhandled post-switch failure with unverifiable rollback' => [
            <<<'BASH'
            DEPLOY_RESULT_PHASE=switch_complete
            rollback_after_failure() {
              builtin printf 'rollback-start\n'
              DEPLOY_RESULT_ROLLBACK_ACTIVE=1
              deploy_result_finish_with_timing 31 failed rollback_failed
            }
            false
            BASH
            ,
            'rollback_failed',
            31,
            1,
        ];
    }

    #[DataProvider('signalDuringPublicationProvider')]
    public function testSignalDuringPublicationCannotReplaceStableOrExit74Result(
        string $failurePoint,
        int $expectedExit,
    ): void {
        $target = $this->protectedDirectory . '/signal-publication.json';
        $barrier = $this->protectedDirectory . '/publication-barrier';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            DEPLOY_RESULT_RECEIPT_TEST_BARRIER_PATH="$2"
            DEPLOY_RESULT_RECEIPT_TEST_FAILURE_POINT="$3"
            DEPLOY_RESULT_PHASE=before_switch
            deploy_result_receipt_prepare
            deploy_result_trap_install
            (
              ready=0
              for _ in $(seq 1 500); do
                if compgen -G "$2.ready.*" >/dev/null; then
                  ready=1
                  break
                fi
                sleep 0.01
              done
              if [[ "$ready" -ne 1 ]]; then
                : > "$2.timeout"
                : > "$2"
                exit 0
              fi
              kill -TERM $$
              : > "$2"
            ) &
            deploy_result_exit 30
            BASH
            ,
            [$target, $barrier, $failurePoint],
        );

        self::assertSame($expectedExit, $result['exit_code'], $result['stderr']);
        self::assertSame(0, substr_count($result['stdout'], 'rollback'));
        self::assertFileDoesNotExist($barrier . '.timeout');
        self::assertSame($failurePoint === '', is_file($target));
        if ($failurePoint === '') {
            self::assertSame(
                'failed_pre_switch',
                DeployResultV1::decode((string) file_get_contents($target))['outcome'],
            );
        }
        self::assertSame([], glob($this->protectedDirectory . '/.deploy-result.*.tmp') ?: []);
    }

    /** @return iterable<string,array{string,int}> */
    public static function signalDuringPublicationProvider(): iterable
    {
        yield 'successful publication preserves stable result' => ['', 30];
        yield 'publication failure preserves abnormal result' => ['parent_fsync', 74];
    }

    /** @return iterable<string,array{string,bool}> */
    public static function caughtPublicationFailureProvider(): iterable
    {
        yield 'receipt file fsync' => ['file_fsync', false];
        yield 'parent fsync with successful cleanup' => ['parent_fsync', false];
        yield 'post-publish identity with successful cleanup' => ['post_publish_identity', false];
        yield 'cleanup failure leaves non-authoritative candidate' => ['parent_fsync_cleanup', true];
    }

    public function testResultPublicationFailureCannotBecomeAReceiptPair(): void
    {
        $this->expectException(\RuntimeException::class);

        DeployResultV1::create('failed_pre_switch', 74);
    }

    #[DataProvider('noReceiptStableExitProvider')]
    public function testNoResultFilePreservesExistingStableExit(int $exitCode, string $phase): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_PHASE="$2"
            deploy_result_exit "$1"
            BASH
            ,
            [(string) $exitCode, $phase],
        );

        self::assertSame($exitCode, $result['exit_code'], $result['stderr']);
    }

    /** @return iterable<string,array{int,string}> */
    public static function noReceiptStableExitProvider(): iterable
    {
        yield 'success' => [0, 'switch_complete'];
        yield 'pre-switch failure' => [30, 'before_switch'];
        yield 'rollback failed' => [31, 'switch_complete'];
        yield 'partial switch' => [32, 'switch_partial'];
        yield 'pre-switch interruption' => [143, 'before_switch'];
    }

    public function testRepeatedOrConflictingFinalizationCannotRewriteReceipt(): void
    {
        $target = $this->protectedDirectory . '/result.json';
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            deploy_result_receipt_prepare
            DEPLOY_RESULT_PHASE=before_switch
            deploy_result_finalize 30
            first="$(sha256sum "$1" | cut -d' ' -f1)"
            deploy_result_finalize 30
            ! deploy_result_finalize 31
            second="$(sha256sum "$1" | cut -d' ' -f1)"
            [[ "$first" == "$second" ]]
            BASH
            ,
            [$target],
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('failed_pre_switch', DeployResultV1::decode((string) file_get_contents($target))['outcome']);
    }

    public function testDryRunReceiptRequestFailsBeforeActionsAndLeavesNoReceipt(): void
    {
        $target = $this->protectedDirectory . '/result.json';
        $result = $this->runCommand(['bash', 'deploy_ea.sh', '--dry-run', '--result-file', $target]);

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertFileDoesNotExist($target);
        self::assertStringContainsString('cannot be used with --dry-run', $result['stdout']);
    }

    public function testDiagnosticsNeverLeakRejectedResultPath(): void
    {
        $target = $this->protectedDirectory . '/secret-token-host-command-path';
        self::assertSame(6, file_put_contents($target, 'marker'));
        self::assertTrue(chmod($target, 0600));

        $result = $this->runCommand(['bash', 'deploy_ea.sh', '--result-file', $target]);

        self::assertSame(30, $result['exit_code']);
        self::assertStringNotContainsString($target, $result['stdout'] . $result['stderr']);
        self::assertSame('marker', file_get_contents($target));
    }

    public function testWriterContainsMandatoryAtomicDurabilityBoundaries(): void
    {
        $script = file_get_contents(__DIR__ . '/../../../deploy_ea.sh');

        self::assertIsString($script);
        self::assertStringContainsString("fopen(\$temporary, 'x+b')", $script);
        self::assertStringContainsString('fflush($stream)', $script);
        self::assertStringContainsString('fsync($stream)', $script);
        self::assertStringContainsString('link($temporary, $target)', $script);
        self::assertStringNotContainsString('rename($temporary, $target)', $script);
        self::assertStringContainsString('fsync($directoryStream)', $script);
    }

    /** @return array{stdout:string,stderr:string,exit_code:int} */
    private function runPrepare(string $target): array
    {
        return $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            deploy_result_receipt_prepare
            BASH
            ,
            [$target],
        );
    }

    /** @return array{stdout:string,stderr:string,exit_code:int} */
    private function runFinalize(
        string $target,
        int $exitCode,
        string $phase,
        int $rollbackActive,
        string $crashPoint = '',
        string $failurePoint = '',
    ): array {
        return $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DEPLOY_RESULT_RECEIPT_PATH="$1"
            DEPLOY_RESULT_PHASE="$2"
            DEPLOY_RESULT_ROLLBACK_ACTIVE="$3"
            DEPLOY_RESULT_RECEIPT_TEST_CRASH_POINT="$5"
            DEPLOY_RESULT_RECEIPT_TEST_FAILURE_POINT="$6"
            DEPLOY_RESULT_RECEIPT_TEST_BARRIER_PATH=""
            deploy_result_receipt_prepare
            deploy_result_finalize "$4"
            BASH
            ,
            [$target, $phase, (string) $rollbackActive, (string) $exitCode, $crashPoint, $failurePoint],
        );
    }

    /** @param list<string> $arguments @return array{stdout:string,stderr:string,exit_code:int} */
    private function runShell(string $script, array $arguments = []): array
    {
        return $this->runCommand(['bash', '-c', $script, 'bash', ...$arguments]);
    }

    /** @param list<string> $command @return array{stdout:string,stderr:string,exit_code:int} */
    private function runCommand(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
            'exit_code' => $exitCode,
        ];
    }
}

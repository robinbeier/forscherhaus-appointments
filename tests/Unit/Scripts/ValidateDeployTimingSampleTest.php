<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeployTimingSampleValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/validate_deploy_timing_sample.php';

final class ValidateDeployTimingSampleTest extends TestCase
{
    public function testDeployTimingDefaultUsesProtectedVarLibStateDirectory(): void
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        unset FH_DEPLOY_TIMING_DIR
        source "$1"
        builtin printf '%s\n' "$DEPLOY_TIMING_DIR"
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame("/var/lib/fh-deploy-timing\n", $result['stdout']);
    }

    public function testTimingDurabilityHelperFailsClosedForAnUnresolvableConfiguredDirectory(): void
    {
        $script = <<<'BASH'
        source "$1"
        set +e
        DEPLOY_TIMING_AUTHORITATIVE_ACTIVE=1
        DEPLOY_TIMING_FILE="/definitely-missing-timing-root/018f6f52-4c87-4d4e-8b19-6a66e6e1af25.jsonl"
        deploy_timing_fsync_authoritative_source
        builtin printf 'status=%s\n' "$?"
        BASH;

        $result = $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame("status=1\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testValidSuccessfulSampleIsAccepted(): void
    {
        $result = DeployTimingSampleValidator::validateLines($this->validLines());

        self::assertSame('018f6f52-4c87-4d4e-8b19-6a66e6e1af25', $result['run_id']);
        self::assertSame(60, $result['total_ms']);
        self::assertSame(6, $result['records']);
    }

    public function testHistoricallyObservedSummaryOverheadsAreAcceptedThroughThirtyMilliseconds(): void
    {
        foreach ([0, 10, 20, 30] as $overheadMs) {
            $result = DeployTimingSampleValidator::validateLines($this->linesWithSummaryTotal(50 + $overheadMs));

            self::assertSame(50 + $overheadMs, $result['total_ms']);
        }
    }

    public function testSummaryOverheadBoundaryRejectsThirtyOneMilliseconds(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unattributed timing exceeds 30 ms');
        DeployTimingSampleValidator::validateLines($this->linesWithSummaryTotal(81));
    }

    public function testArbitrarilyLargeSummaryMismatchIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unattributed timing exceeds 30 ms');
        DeployTimingSampleValidator::validateLines($this->linesWithSummaryTotal(999));
    }

    public function testPhaseDurationCannotExceedItsElapsedWindow(): void
    {
        $lines = $this->validLines();
        $event = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($event);
        $event['duration_ms'] = 11;
        $lines[2] = json_encode($event, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('phase duration exceeds its elapsed_ms window');
        DeployTimingSampleValidator::validateLines($lines);
    }

    public function testRealDuplicatePostdeployAndSummaryCaptureIsRejected(): void
    {
        $lines = $this->validLines();
        $lines[] = $lines[4];
        $lines[] = $lines[5];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly six records');
        DeployTimingSampleValidator::validateLines($lines);
    }

    public function testMissingRecordIsRejected(): void
    {
        $lines = $this->validLines();
        unset($lines[2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly six records');
        DeployTimingSampleValidator::validateLines(array_values($lines));
    }

    public function testMixedRunsAreRejected(): void
    {
        $lines = $this->validLines();
        $event = json_decode($lines[3], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($event);
        $event['run_id'] = '118f6f52-4c87-4d4e-8b19-6a66e6e1af25';
        $lines[3] = json_encode($event, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mixes multiple run_id');
        DeployTimingSampleValidator::validateLines($lines);
    }

    public function testOutOfOrderPhasesAreRejected(): void
    {
        $lines = $this->validLines();
        $second = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        $third = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($second);
        self::assertIsArray($third);
        [$second['phase'], $third['phase']] = [$third['phase'], $second['phase']];
        $lines[1] = json_encode($second, JSON_THROW_ON_ERROR);
        $lines[2] = json_encode($third, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('out of order');
        DeployTimingSampleValidator::validateLines($lines);
    }

    public function testUnexpectedFieldIsRejectedInsteadOfRetainingSensitiveContext(): void
    {
        $lines = $this->validLines();
        $event = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($event);
        $event['context'] = 'SENSITIVE_CUSTOMER_MARKER';
        $lines[0] = json_encode($event, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected fields');
        DeployTimingSampleValidator::validateLines($lines);
    }

    public function testAuthoritativeFileAcceptsZeroOrOneFinalNewline(): void
    {
        $this->requireRootLinuxForSourceProtection();

        foreach (['', PHP_EOL] as $suffix) {
            [$directory, $file] = $this->createProtectedTimingFixture(implode(PHP_EOL, $this->validLines()) . $suffix);

            try {
                $result = DeployTimingSampleValidator::validateFile($file);

                self::assertSame(6, $result['records']);
            } finally {
                $this->removeTimingFixture($directory);
            }
        }
    }

    public function testAuthoritativeFileRejectsAnAdditionalTrailingBlankRecord(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture(
            implode(PHP_EOL, $this->validLines()) . PHP_EOL . PHP_EOL,
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('empty record');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testAuthoritativeFileRejectsAnInternalBlankRecord(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $lines = $this->validLines();
        array_splice($lines, 3, 0, ['']);
        [$directory, $file] = $this->createProtectedTimingFixture(implode(PHP_EOL, $lines) . PHP_EOL);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('empty record');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testNonRootOwnedAuthoritativeFileIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture();

        try {
            self::assertTrue(chown($file, 65534));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('root-owned mode 0600');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testEmptyAuthoritativeFilePathIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('absolute regular non-symlink file');
        DeployTimingSampleValidator::validateFile('');
    }

    public function testRelativeAuthoritativeFilePathIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [, $file] = $this->createProtectedTimingFixture();
        $relative = ltrim($file, '/');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('absolute regular non-symlink file');
            DeployTimingSampleValidator::validateFile($relative);
        } finally {
            $this->removeTimingFixture(dirname($file));
        }
    }

    public function testNonRegularAuthoritativeFilePathIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory] = $this->createProtectedTimingFixture();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('absolute regular non-symlink file');
            DeployTimingSampleValidator::validateFile($directory);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testGroupAndWorldWritableAuthoritativeFileIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture();

        try {
            self::assertTrue(chmod($file, 0622));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('root-owned mode 0600');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testSymlinkedAuthoritativeFileIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture();
        $symlink = $directory . '/alias.jsonl';

        try {
            self::assertTrue(symlink($file, $symlink));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('absolute regular non-symlink file');
            DeployTimingSampleValidator::validateFile($symlink);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testNonCanonicalAuthoritativeFilePathIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture();
        $nonCanonical = $directory . '/../' . basename($directory) . '/' . basename($file);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('canonical and symlink-free');
            DeployTimingSampleValidator::validateFile($nonCanonical);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testHardlinkedAuthoritativeFileIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture();
        $hardlink = $directory . '/hardlink.jsonl';

        try {
            self::assertTrue(link($file, $hardlink));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('with one hardlink');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testNonRootOwnedTimingSourceDirectoryIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture();

        try {
            self::assertTrue(chown($directory, 65534));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ancestors must be root-controlled');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testWritableTimingSourceDirectoryIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture();

        try {
            self::assertTrue(chmod($directory, 0722));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ancestors must be root-controlled');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testTimingSourceDirectoryWithoutExactMode0700IsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$directory, $file] = $this->createProtectedTimingFixture();

        try {
            self::assertTrue(chmod($directory, 0750));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('directory must use mode 0700');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testNonRootOwnedTimingSourceAncestorIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$root, $ancestor, , $file] = $this->createNestedProtectedTimingFixture();

        try {
            self::assertTrue(chown($ancestor, 65534));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ancestors must be root-controlled');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($root);
        }
    }

    public function testWritableTimingSourceAncestorIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$root, $ancestor, , $file] = $this->createNestedProtectedTimingFixture();

        try {
            self::assertTrue(chmod($ancestor, 0722));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ancestors must be root-controlled');
            DeployTimingSampleValidator::validateFile($file);
        } finally {
            $this->removeTimingFixture($root);
        }
    }

    public function testSymlinkedTimingSourceAncestorIsRejected(): void
    {
        $this->requireRootLinuxForSourceProtection();
        [$root, $ancestor, $directory, $file] = $this->createNestedProtectedTimingFixture();
        $alias = $root . '/alias';
        $aliasedFile = $alias . '/' . basename($directory) . '/' . basename($file);

        try {
            self::assertTrue(symlink($ancestor, $alias));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('canonical and symlink-free');
            DeployTimingSampleValidator::validateFile($aliasedFile);
        } finally {
            $this->removeTimingFixture($root);
        }
    }

    public function testDeployScriptWritesOneRootProtectedAuthoritativeRecordPerEvent(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec('id -u')) !== '0') {
            self::markTestSkipped('Root on Linux is required to verify the authoritative source protection contract.');
        }

        $directory = '/rob445-deploy-timing-' . bin2hex(random_bytes(6));
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_DIR="$2"
        DEPLOY_TIMING_AUTHORITATIVE_ENABLED=1
        deploy_timing_init deploy 0 preparation_artifact
        deploy_detail_init 0
        deploy_detail_emit_subphase predeploy stage_permissions ok none 1 1
        deploy_timing_transition predeploy
        deploy_detail_emit_subphase predeploy zero_surprise_replay ok none 1 2
        deploy_timing_transition permissions_stage
        deploy_detail_emit_subphase permissions_stage final_permissions ok none 1 3
        deploy_timing_transition switch
        deploy_timing_transition postdeploy_validation
        deploy_timing_finish ok succeeded 0
        builtin printf 'DEPLOY_TIMING_DURABLE=%s\n' "$DEPLOY_TIMING_DURABLE"
        BASH;

        try {
            $result = $this->runCommand([
                'bash',
                '-c',
                $script,
                'bash',
                dirname(__DIR__, 3) . '/deploy_ea.sh',
                $directory,
            ]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString("DEPLOY_TIMING_DURABLE=1\n", $result['stdout']);

            $files = glob($directory . '/*.jsonl');
            self::assertIsArray($files);
            self::assertCount(1, $files);
            $validated = DeployTimingSampleValidator::validateFile($files[0]);
            self::assertSame(6, $validated['records']);

            $stdoutLines = array_values(
                array_filter(
                    preg_split('/\R/', $result['stdout']) ?: [],
                    static fn(string $line): bool => str_starts_with($line, 'DEPLOY_TIMING '),
                ),
            );
            self::assertCount(6, $stdoutLines);
            $detailLines = array_values(
                array_filter(
                    preg_split('/\R/', $result['stdout']) ?: [],
                    static fn(string $line): bool => str_starts_with($line, 'DEPLOY_DETAIL '),
                ),
            );
            self::assertCount(3, $detailLines);
            foreach ($stdoutLines as $index => $line) {
                $event = json_decode(substr($line, strlen('DEPLOY_TIMING ')), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($index + 1, $event['sequence'] ?? null);
                self::assertSame($validated['run_id'], $event['run_id'] ?? null);
            }
        } finally {
            if (isset($files) && is_array($files)) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testDeployTimingPreparationAcceptsAnExistingProtectedDirectoryWithoutChangingIt(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $directory = '/rob445-deploy-timing-existing-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(chmod($directory, 0700));
        $before = $this->directoryAuthorityMetadata($directory);

        try {
            $result = $this->runTimingPreparation($directory);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('accepted', $result['stdout']);
            self::assertSame($before, $this->directoryAuthorityMetadata($directory));
            $files = glob($directory . '/*.jsonl');
            self::assertIsArray($files);
            self::assertCount(1, $files);
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testDeployTimingPreparationRejectsExistingNonRootDirectoryWithoutChangingIt(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $directory = '/rob445-deploy-timing-owner-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(chown($directory, 65534));
        $before = $this->directoryAuthorityMetadata($directory);

        try {
            $result = $this->runTimingPreparation($directory);

            self::assertStringContainsString('rejected', $result['stdout']);
            self::assertSame($before, $this->directoryAuthorityMetadata($directory));
            self::assertSame([], glob($directory . '/*.jsonl'));
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testDeployTimingPreparationRejectsExistingUnsafeModeWithoutChangingIt(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $directory = '/rob445-deploy-timing-mode-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(chmod($directory, 0755));
        $before = $this->directoryAuthorityMetadata($directory);

        try {
            $result = $this->runTimingPreparation($directory);

            self::assertStringContainsString('rejected', $result['stdout']);
            self::assertSame($before, $this->directoryAuthorityMetadata($directory));
            self::assertSame([], glob($directory . '/*.jsonl'));
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testDeployTimingPreparationRejectsSymlinkedDirectoryWithoutChangingIt(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $target = '/rob445-deploy-timing-target-' . bin2hex(random_bytes(6));
        $alias = '/rob445-deploy-timing-alias-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($target, 0700));
        self::assertTrue(chmod($target, 0700));
        self::assertTrue(symlink($target, $alias));
        $before = $this->directoryAuthorityMetadata($target);

        try {
            $result = $this->runTimingPreparation($alias);

            self::assertStringContainsString('rejected', $result['stdout']);
            self::assertTrue(is_link($alias));
            self::assertSame($target, readlink($alias));
            self::assertSame($before, $this->directoryAuthorityMetadata($target));
            self::assertSame([], glob($target . '/*.jsonl'));
        } finally {
            if (is_link($alias)) {
                unlink($alias);
            }
            $this->removeTimingFixture($target);
        }
    }

    public function testDeployTimingPreparationRejectsNonCanonicalDirectoryWithoutChangingIt(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $directory = '/rob445-deploy-timing-canonical-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(chmod($directory, 0755));
        $nonCanonical = $directory . '/../' . basename($directory);
        $before = $this->directoryAuthorityMetadata($directory);

        try {
            $result = $this->runTimingPreparation($nonCanonical);

            self::assertStringContainsString('rejected', $result['stdout']);
            self::assertSame($before, $this->directoryAuthorityMetadata($directory));
            self::assertSame([], glob($directory . '/*.jsonl'));
        } finally {
            $this->removeTimingFixture($directory);
        }
    }

    public function testDeployTimingPreparationCreatesAMissingCanonicalDirectoryRootOnly(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $directory = '/rob445-deploy-timing-missing-' . bin2hex(random_bytes(6));

        try {
            $result = $this->runTimingPreparation($directory);

            self::assertStringContainsString('accepted', $result['stdout']);
            self::assertDirectoryExists($directory);
            self::assertSame(['uid' => 0, 'mode' => 0700], $this->directoryAuthorityMetadata($directory));
            $files = glob($directory . '/*.jsonl');
            self::assertIsArray($files);
            self::assertCount(1, $files);
            $fileStat = lstat($files[0]);
            self::assertIsArray($fileStat);
            self::assertSame(0, $fileStat['uid']);
            self::assertSame(0600, $fileStat['mode'] & 0777);
            self::assertSame(1, $fileStat['nlink']);
        } finally {
            if (is_dir($directory)) {
                $this->removeTimingFixture($directory);
            }
        }
    }

    public function testDeployTimingPreparationAcceptsMissingLeafBelowProtectedVarLibAncestor(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $ancestor = '/var/lib';
        $directory = $ancestor . '/rob445-deploy-timing-' . bin2hex(random_bytes(6));
        $ancestorMetadata = $this->directoryAuthorityMetadata($ancestor);
        self::assertSame(0, $ancestorMetadata['uid']);
        self::assertSame(0, $ancestorMetadata['mode'] & 0022);

        try {
            $result = $this->runTimingPreparation($directory);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('accepted', $result['stdout']);
            self::assertSame(['uid' => 0, 'mode' => 0700], $this->directoryAuthorityMetadata($directory));
            $files = glob($directory . '/*.jsonl');
            self::assertIsArray($files);
            self::assertCount(1, $files);
            $fileStat = lstat($files[0]);
            self::assertIsArray($fileStat);
            self::assertSame(0, $fileStat['uid']);
            self::assertSame(0600, $fileStat['mode'] & 0777);
            self::assertSame(1, $fileStat['nlink']);
        } finally {
            if (is_dir($directory)) {
                $this->removeTimingFixture($directory);
            }
        }
    }

    public function testDeployTimingPreparationRejectsAMissingNonCanonicalDirectory(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $parent = '/rob445-deploy-timing-parent-' . bin2hex(random_bytes(6));
        $target = '/rob445-deploy-timing-new-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($parent, 0700));
        $nonCanonical = $parent . '/../' . basename($target);

        try {
            $result = $this->runTimingPreparation($nonCanonical);

            self::assertStringContainsString('rejected', $result['stdout']);
            self::assertDirectoryDoesNotExist($target);
        } finally {
            if (is_dir($target)) {
                $this->removeTimingFixture($target);
            }
            $this->removeTimingFixture($parent);
        }
    }

    public function testDeployTimingPreparationRejectsAMissingDirectoryBelowWritableParent(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $parent = '/rob445-deploy-timing-parent-' . bin2hex(random_bytes(6));
        $directory = $parent . '/timing';
        self::assertTrue(mkdir($parent, 0700));
        self::assertTrue(chmod($parent, 0722));
        $before = $this->directoryAuthorityMetadata($parent);

        try {
            $result = $this->runTimingPreparation($directory);

            self::assertStringContainsString('rejected', $result['stdout']);
            self::assertDirectoryDoesNotExist($directory);
            self::assertSame($before, $this->directoryAuthorityMetadata($parent));
        } finally {
            $this->removeTimingFixture($parent);
        }
    }

    public function testDeployTimingInitializationDisablesCaptureBelowGroupWritableLogAncestor(): void
    {
        $this->requireRootLinuxForSourceProtection();
        $root = '/rob445-deploy-timing-log-parent-' . bin2hex(random_bytes(6));
        $logAncestor = $root . '/var/log';
        $directory = $logAncestor . '/fh-deploy-timing';
        self::assertTrue(mkdir($logAncestor, 0775, true));
        self::assertTrue(chmod($root, 0700));
        self::assertTrue(chmod($root . '/var', 0755));
        self::assertTrue(chmod($logAncestor, 0775));
        $before = $this->directoryAuthorityMetadata($logAncestor);

        try {
            $result = $this->runTimingInitialization($directory);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame("authoritative_active=0\nauthoritative_file=\n", $result['stdout']);
            self::assertSame(
                1,
                substr_count(
                    $result['stderr'],
                    'Authoritative deploy timing source unavailable; this run is invalid for baseline use.',
                ),
            );
            self::assertDirectoryDoesNotExist($directory);
            self::assertSame(['uid' => 0, 'mode' => 0775], $before);
            self::assertSame($before, $this->directoryAuthorityMetadata($logAncestor));
        } finally {
            $this->removeTimingFixture($root);
        }
    }

    /**
     * @return list<string>
     */
    private function validLines(): array
    {
        $runId = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
        $phases = ['preparation_artifact', 'predeploy', 'permissions_stage', 'switch', 'postdeploy_validation'];
        $lines = [];

        foreach ($phases as $index => $phase) {
            $lines[] = json_encode(
                [
                    'schema' => 'deploy_timing.v1',
                    'run_id' => $runId,
                    'sequence' => $index + 1,
                    'event' => 'phase',
                    'mode' => 'deploy',
                    'phase' => $phase,
                    'status' => 'ok',
                    'duration_ms' => 10,
                    'elapsed_ms' => ($index + 1) * 10,
                    'dry_run' => false,
                ],
                JSON_THROW_ON_ERROR,
            );
        }

        $lines[] = json_encode(
            [
                'schema' => 'deploy_timing.v1',
                'run_id' => $runId,
                'sequence' => 6,
                'event' => 'summary',
                'mode' => 'deploy',
                'outcome' => 'succeeded',
                'exit_code' => 0,
                'total_ms' => 60,
                'dry_run' => false,
            ],
            JSON_THROW_ON_ERROR,
        );

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function linesWithSummaryTotal(int $totalMs): array
    {
        $lines = $this->validLines();
        $summary = json_decode($lines[5], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        $summary['total_ms'] = $totalMs;
        $lines[5] = json_encode($summary, JSON_THROW_ON_ERROR);

        return $lines;
    }

    private function requireRootLinuxForSourceProtection(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || trim((string) shell_exec('id -u')) !== '0') {
            self::markTestSkipped('Root on Linux is required to verify the authoritative source protection contract.');
        }
    }

    /**
     * @return array{string,string}
     */
    private function createProtectedTimingFixture(?string $contents = null): array
    {
        $directory = '/rob445-timing-source-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(chmod($directory, 0700));
        $file = $directory . '/sample.jsonl';
        self::assertNotFalse(file_put_contents($file, $contents ?? implode(PHP_EOL, $this->validLines()) . PHP_EOL));
        self::assertTrue(chmod($file, 0600));

        return [$directory, $file];
    }

    /**
     * @return array{string,string,string,string}
     */
    private function createNestedProtectedTimingFixture(): array
    {
        $root = '/rob445-timing-source-' . bin2hex(random_bytes(6));
        $ancestor = $root . '/ancestor';
        $directory = $ancestor . '/source';
        self::assertTrue(mkdir($directory, 0700, true));
        self::assertTrue(chmod($root, 0700));
        self::assertTrue(chmod($ancestor, 0700));
        self::assertTrue(chmod($directory, 0700));
        $file = $directory . '/sample.jsonl';
        self::assertNotFalse(file_put_contents($file, implode(PHP_EOL, $this->validLines()) . PHP_EOL));
        self::assertTrue(chmod($file, 0600));

        return [$root, $ancestor, $directory, $file];
    }

    /**
     * @return array{uid:int,mode:int}
     */
    private function directoryAuthorityMetadata(string $directory): array
    {
        clearstatcache(true, $directory);
        $stat = lstat($directory);
        self::assertIsArray($stat);

        return ['uid' => $stat['uid'], 'mode' => $stat['mode'] & 0777];
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runTimingPreparation(string $directory): array
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_DIR="$2"
        DEPLOY_TIMING_AUTHORITATIVE_ENABLED=1
        DEPLOY_TIMING_DRY_RUN=false
        DEPLOY_TIMING_RUN_ID="018f6f52-4c87-4d4e-8b19-6a66e6e1af25"
        if deploy_timing_prepare_authoritative_source; then
          builtin printf 'accepted\n'
        else
          builtin printf 'rejected\n'
        fi
        BASH;

        return $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh', $directory]);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runTimingInitialization(string $directory): array
    {
        $script = <<<'BASH'
        set -Eeuo pipefail
        source "$1"
        DEPLOY_TIMING_DIR="$2"
        DEPLOY_TIMING_AUTHORITATIVE_ENABLED=1
        deploy_timing_init deploy 0 preparation_artifact
        builtin printf 'authoritative_active=%s\n' "$DEPLOY_TIMING_AUTHORITATIVE_ACTIVE"
        builtin printf 'authoritative_file=%s\n' "$DEPLOY_TIMING_FILE"
        deploy_timing_disable
        trap - EXIT
        BASH;

        return $this->runCommand(['bash', '-c', $script, 'bash', dirname(__DIR__, 3) . '/deploy_ea.sh', $directory]);
    }

    private function removeTimingFixture(string $directory): void
    {
        $entries = scandir($directory);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . '/' . $entry;
                if (is_link($path) || is_file($path)) {
                    unlink($path);
                } elseif (is_dir($path)) {
                    $this->removeTimingFixture($path);
                }
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    /**
     * @param list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 3),
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
}

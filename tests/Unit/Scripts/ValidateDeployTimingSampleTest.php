<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeployTimingSampleValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/validate_deploy_timing_sample.php';

final class ValidateDeployTimingSampleTest extends TestCase
{
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
        deploy_timing_transition predeploy
        deploy_timing_transition permissions_stage
        deploy_timing_transition switch
        deploy_timing_transition postdeploy_validation
        deploy_timing_finish ok succeeded 0
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
    private function createProtectedTimingFixture(): array
    {
        $directory = '/rob445-timing-source-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(chmod($directory, 0700));
        $file = $directory . '/sample.jsonl';
        self::assertNotFalse(file_put_contents($file, implode(PHP_EOL, $this->validLines()) . PHP_EOL));
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

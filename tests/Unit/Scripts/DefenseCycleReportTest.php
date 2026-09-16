<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DefenseCycleReportTest extends TestCase
{
    public function testReceiptKeepsOnlyExecutionMetadataAndSeparatesSkippedTests(): void
    {
        $report = $this->report(
            '<testsuite><testcase class="A" name="ok" time="0.25" assertions="3"/><testcase classname="A" name="skip"><skipped>private reason</skipped></testcase><system-out>private output</system-out></testsuite>',
        );
        self::assertSame(25, $report['phases'][0]['duration_ms']);
        self::assertSame('passed_with_skips', $report['overall_status']);
        self::assertSame(
            ['assertions', 'class', 'duration', 'name', 'status'],
            array_keys($report['junit']['tests'][0]),
        );
        self::assertSame(3, $report['junit']['tests'][0]['assertions']);
        self::assertSame('A', $report['junit']['tests'][0]['class']);
        self::assertSame('skipped', $report['junit']['tests'][1]['status']);
        self::assertSame(1, $report['junit']['counts']['passed']);
        self::assertSame(1, $report['junit']['counts']['skipped']);
        self::assertStringNotContainsString('private', json_encode($report));
        self::assertSame('owned_docker_stack', $report['cleanup']['scope']);
    }

    public function testFailureAndErrorCannotBecomePassingOrExposePayloads(): void
    {
        foreach (['failure', 'error'] as $kind) {
            $report = $this->report(
                '<testsuite><testcase class="A" name="bad"><skipped/><' .
                    $kind .
                    ' message="private message">private payload</' .
                    $kind .
                    '></testcase></testsuite>',
            );
            self::assertSame('failed', $report['overall_status']);
            self::assertSame($kind, $report['junit']['tests'][0]['status']);
            self::assertStringNotContainsString('private', json_encode($report));
        }
    }

    public function testMissingMalformedEmptyAndInvalidJUnitNeverPass(): void
    {
        foreach (
            [
                null,
                '<testsuite>',
                '<testsuite/>',
                '<testsuite><testcase class="A" name="x" time="NaN"/></testsuite>',
                '<testsuite><testcase class="A" name="x" time="-1"/></testsuite>',
            ]
            as $xml
        ) {
            $report = $this->report($xml);
            self::assertSame('unavailable', $report['junit']['status']);
            self::assertSame('failed', $report['overall_status']);
        }
    }

    public function testMissingPhaseAndBadSourceCannotProduceCompleteReceipt(): void
    {
        $xml = '<testsuite><testcase class="A" name="ok"/></testsuite>';
        foreach ([['events' => 'phpunit|100|started' . PHP_EOL], ['commit' => 'unknown']] as $override) {
            self::assertSame('failed', $this->report($xml, $override)['overall_status']);
        }
        $report = $this->report($xml, ['runner' => 23, 'cleanup' => 41]);
        self::assertSame('failed', $report['overall_status']);
        self::assertSame(23, $report['runner_exit_code']);
        self::assertSame(41, $report['cleanup']['exit_code']);
    }

    private function report(?string $xml, array $override = []): array
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/defense-report-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $events = '';
        foreach (
            [
                'compose_start',
                'php_ready',
                'synthetic_config',
                'mysql_ready',
                'php_ready_after_mysql',
                'app_db_ready',
                'seed_install',
                'phpunit',
                'cleanup',
            ]
            as $phase
        ) {
            $events .= "$phase|100|started\n$phase|125|passed\n";
        }
        file_put_contents($directory . '/events', $override['events'] ?? $events);
        if ($xml !== null) {
            file_put_contents($directory . '/junit.xml', $xml);
        }
        try {
            $process = proc_open(
                [
                    'python3',
                    $root . '/scripts/ci/defense_cycle_report.py',
                    '--events',
                    $directory . '/events',
                    '--junit',
                    $directory . '/junit.xml',
                    '--output',
                    $directory . '/summary.json',
                    '--commit',
                    $override['commit'] ?? str_repeat('a', 40),
                    '--dirty',
                    'false',
                    '--runner-status',
                    (string) ($override['runner'] ?? 0),
                    '--cleanup-status',
                    (string) ($override['cleanup'] ?? 0),
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
            );
            self::assertIsResource($process);
            stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            return json_decode(file_get_contents($directory . '/summary.json'), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }
}

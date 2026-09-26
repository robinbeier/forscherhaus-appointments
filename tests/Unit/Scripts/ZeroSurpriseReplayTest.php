<?php

namespace Tests\Unit\Scripts;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseImageCleanup;
use ReleaseGate\ZeroSurpriseReport;

define('ZERO_SURPRISE_REPLAY_TEST_MODE', true);
require_once __DIR__ . '/../../../scripts/release-gate/zero_surprise_replay.php';

class ZeroSurpriseReplayTest extends TestCase
{
    public function testExternalStepPassesPasswordOnlyThroughStdin(): void
    {
        $password = "synthetic quote' and newline\n";
        $result = runExternalStep(
            [PHP_BINARY, '-r', 'echo hash("sha256", stream_get_contents(STDIN));'],
            dirname(__DIR__, 3),
            5,
            $password,
        );
        self::assertSame('pass', $result['status']);
        self::assertSame(hash('sha256', $password), $result['stdout_tail']);
        self::assertStringNotContainsString($password, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testReplayTeardownRecordsComposeAndImageCleanup(): void
    {
        $report = $this->report();
        $downCommands = [];
        $downRunner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use (
            &$downCommands,
        ): array {
            $downCommands[] = $command;

            return [
                'exit_code' => 0,
                'stdout' => '',
                'stderr' => '',
                'duration_ms' => 4.0,
                'timed_out' => false,
            ];
        };

        $result = runReplayTeardown(
            '/repo',
            'zs-release-20260813t010203z-abcd',
            $report,
            $downRunner,
            $this->cleaner(),
        );
        $data = $report->toArray();

        $this->assertFalse($result['runtime_failed']);
        $this->assertSame(
            [
                'docker',
                'compose',
                '-p',
                'zs-release-20260813t010203z-abcd',
                '-f',
                'docker-compose.yml',
                '-f',
                'docker/compose.zero-surprise.yml',
                'down',
                '-v',
                '--remove-orphans',
            ],
            $downCommands[0],
        );
        $this->assertSame(['compose_cleanup', 'image_cleanup'], array_column($data['steps'], 'name'));
        $this->assertSame(['pass', 'pass'], array_column($data['steps'], 'status'));
        $this->assertSame(0, $data['summary']['exit_code']);
    }

    public function testReplayTeardownStillRunsImageCleanupWhenComposeDownFails(): void
    {
        $report = $this->report();
        $downRunner = static fn(array $command, string $workingDirectory, int $timeoutSeconds): array => [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'secret-bearing docker failure',
            'duration_ms' => 4.0,
            'timed_out' => false,
        ];

        $result = runReplayTeardown(
            '/repo',
            'zs-release-20260813t010203z-abcd',
            $report,
            $downRunner,
            $this->cleaner(),
        );
        $data = $report->toArray();

        $this->assertTrue($result['runtime_failed']);
        $this->assertSame(['fail', 'pass'], array_column($data['steps'], 'status'));
        $this->assertSame(2, $data['summary']['exit_code']);
        $this->assertSame('Zero-surprise compose cleanup failed closed.', $data['failure']['message']);
        $this->assertStringNotContainsString('secret-bearing', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testReplayTeardownStillRunsImageCleanupWhenComposeRunnerThrows(): void
    {
        $report = $this->report();
        $downRunner = static function (array $command, string $workingDirectory, int $timeoutSeconds): array {
            throw new \RuntimeException('secret-bearing process failure');
        };

        $result = runReplayTeardown(
            '/repo',
            'zs-release-20260813t010203z-abcd',
            $report,
            $downRunner,
            $this->cleaner(),
        );
        $data = $report->toArray();

        $this->assertTrue($result['runtime_failed']);
        $this->assertSame(['fail', 'pass'], array_column($data['steps'], 'status'));
        $this->assertSame(2, $data['summary']['exit_code']);
        $this->assertStringNotContainsString('secret-bearing', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testReplayTeardownFailsClosedWhenImageInventoryFails(): void
    {
        $report = $this->report();
        $downRunner = static fn(array $command, string $workingDirectory, int $timeoutSeconds): array => [
            'exit_code' => 0,
            'stdout' => '',
            'stderr' => '',
            'duration_ms' => 4.0,
            'timed_out' => false,
        ];
        $imageRunner = static fn(array $command, string $workingDirectory, int $timeoutSeconds): array => [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'secret-bearing inventory failure',
            'duration_ms' => 1.0,
            'timed_out' => false,
        ];
        $cleaner = new ZeroSurpriseImageCleanup($imageRunner, static fn(string $path): int => 100);

        $result = runReplayTeardown('/repo', 'zs-release-20260813t010203z-abcd', $report, $downRunner, $cleaner);
        $data = $report->toArray();

        $this->assertTrue($result['runtime_failed']);
        $this->assertSame(['pass', 'fail'], array_column($data['steps'], 'status'));
        $this->assertSame('docker_storage_root_unavailable', $data['steps'][1]['details']['reason']);
        $this->assertSame(2, $data['summary']['exit_code']);
        $this->assertStringNotContainsString('secret-bearing', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testBuildAppReadinessUrlUsesIndexPageWhenPresent(): void
    {
        $actual = buildAppReadinessUrl([
            'base_url' => 'https://example.invalid/',
            'index_page' => 'index.php',
        ]);

        $this->assertSame('https://example.invalid/index.php/login', $actual);
    }

    public function testBuildAppReadinessUrlOmitsIndexPageWhenEmpty(): void
    {
        $actual = buildAppReadinessUrl([
            'base_url' => 'https://example.invalid/',
            'index_page' => '',
        ]);

        $this->assertSame('https://example.invalid/login', $actual);
    }

    public function testBuildReplayGateSeedSqlHashesCredentialsAndEscapesUsername(): void
    {
        $sql = buildReplayGateSeedSql([
            'username' => "Gate'User",
            'password' => 'secret-pass',
            'release_id' => 'ea_20260320_1200',
            'timezone' => 'Europe/Berlin',
        ]);

        $this->assertStringContainsString("SET @zs_username = 'Gate\\'User';", $sql);
        $this->assertStringContainsString("SET @zs_email = 'zs+gate-user@gate.invalid';", $sql);
        $this->assertStringContainsString('INSERT INTO ea_user_settings', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertMatchesRegularExpression("/SET @zs_password_hash = '[a-f0-9]{64}';/", $sql);
        $this->assertMatchesRegularExpression("/SET @zs_salt = '[a-f0-9]{64}';/", $sql);
    }

    public function testBuildReplayGateSeedSqlRejectsMissingCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Replay gate account sync requires non-empty username and password.');

        buildReplayGateSeedSql([
            'username' => '',
            'password' => '',
        ]);
    }

    public function testNoLiveSlotFallbackRequiresExactContractMismatchAndConfiguredWindow(): void
    {
        $report = [
            'failure' => [
                'classification' => 'contract_mismatch',
                'message' => 'No booking hours available across 12 provider/service pairs in 35-day window.',
            ],
            'state' => [
                'cleanup' => [
                    'created' => [],
                    'deleted' => [],
                    'failures' => [],
                ],
            ],
        ];

        $initialFailure = ['status' => 'fail', 'exit_code' => 1, 'timed_out' => false];
        self::assertTrue(isNoLiveSlotFailure($report, ['booking_search_days' => 35], $initialFailure));
        self::assertFalse(isNoLiveSlotFailure($report, ['booking_search_days' => 14], $initialFailure));
        self::assertFalse(
            isNoLiveSlotFailure(['failure' => $report['failure']], ['booking_search_days' => 35], $initialFailure),
        );
        self::assertFalse(
            isNoLiveSlotFailure(
                [
                    'failure' => [
                        'classification' => 'runtime_error',
                        'message' => $report['failure']['message'],
                    ],
                ],
                ['booking_search_days' => 35],
                $initialFailure,
            ),
        );
        self::assertFalse(
            isNoLiveSlotFailure(
                $report,
                ['booking_search_days' => 35],
                ['status' => 'fail', 'exit_code' => 2, 'timed_out' => false],
            ),
        );
        self::assertFalse(
            isNoLiveSlotFailure(
                [
                    'failure' => [
                        'classification' => 'contract_mismatch',
                        'message' => 'No booking hours available across 12 provider/service pairs in 35-day window',
                    ],
                ],
                ['booking_search_days' => 35],
                $initialFailure,
            ),
        );
    }

    public function testBlockedWindowCoverageRequiresAFullSinglePeriod(): void
    {
        self::assertSame('full', classifyBlockedWindowCoverage("full\n", 0, false));
        self::assertSame('partial', classifyBlockedWindowCoverage("partial\n", 0, false));
        self::assertSame('absent', classifyBlockedWindowCoverage("absent\n", 0, false));
        self::assertSame('unknown', classifyBlockedWindowCoverage("full\npartial\n", 0, false));
        self::assertSame('unknown', classifyBlockedWindowCoverage('full', 1, false));
        self::assertSame('unknown', classifyBlockedWindowCoverage('full', 0, true));
    }

    public function testBlockedWindowCoverageSqlUsesProductionPrefixAndExclusiveEndBoundary(): void
    {
        $sql = buildBlockedWindowCoverageSql(
            new \DateTimeImmutable('2026-09-26 00:00:00'),
            new \DateTimeImmutable('2026-10-31 00:00:00'),
        );

        self::assertStringContainsString('FROM ea_blocked_periods', $sql);
        self::assertStringContainsString("start_datetime <= '2026-09-26 00:00:00'", $sql);
        self::assertStringContainsString("end_datetime >= '2026-10-31 00:00:00'", $sql);
        self::assertStringContainsString("end_datetime > '2026-09-26 00:00:00'", $sql);
        self::assertStringContainsString("start_datetime < '2026-10-31 00:00:00'", $sql);
        self::assertStringNotContainsString('FROM blocked_periods', $sql);
    }

    public function testBlockedPeriodSnapshotParserKeepsIdentitiesInMemoryAndRejectsAmbiguity(): void
    {
        $snapshot = parseFullWindowBlockedPeriodSnapshot("17\t2026-10-25 00:00:00\t2026-11-29 00:00:00\n", 0, false);
        self::assertSame([['id' => 17, 'start' => '2026-10-25 00:00:00', 'end' => '2026-11-29 00:00:00']], $snapshot);
        self::assertSame([], parseFullWindowBlockedPeriodSnapshot('', 0, false));
        self::assertNull(parseFullWindowBlockedPeriodSnapshot("17\t2026-10-25 00:00:00\tsecret\n", 0, false));
        self::assertNull(
            parseFullWindowBlockedPeriodSnapshot("17\t2026-10-25 00:00:00\t2026-11-29 00:00:00\n", 1, false),
        );
        self::assertNull(
            parseFullWindowBlockedPeriodSnapshot("17\t2026-02-30 00:00:00\t2026-11-29 00:00:00\n", 0, false),
        );
    }

    public function testBlockedPeriodSnapshotSqlUsesActualPrefixedTableAndBoundaries(): void
    {
        $sql = buildFullWindowBlockedPeriodSnapshotSql(
            new \DateTimeImmutable('2026-10-25 00:00:00'),
            new \DateTimeImmutable('2026-11-29 00:00:00'),
        );
        self::assertStringContainsString('SELECT id, start_datetime, end_datetime FROM ea_blocked_periods', $sql);
        self::assertStringContainsString("start_datetime <= '2026-10-25 00:00:00'", $sql);
        self::assertStringContainsString("end_datetime >= '2026-11-29 00:00:00'", $sql);
        self::assertStringNotContainsString('FROM blocked_periods', $sql);
    }

    public function testBlockedPeriodBaselineComparisonFailsOnAnyIdentityOrBoundaryDrift(): void
    {
        $baseline = [['id' => 17, 'start' => '2026-10-25 00:00:00', 'end' => '2026-11-29 00:00:00']];
        self::assertSame('baseline_match', classifyBlockedPeriodSnapshotComparison($baseline, $baseline));
        self::assertSame(
            'baseline_drift',
            classifyBlockedPeriodSnapshotComparison($baseline, [
                ['id' => 18, 'start' => '2026-10-25 00:00:00', 'end' => '2026-11-29 00:00:00'],
            ]),
        );
        self::assertSame(
            'baseline_drift',
            classifyBlockedPeriodSnapshotComparison($baseline, [
                ['id' => 17, 'start' => '2026-10-26 00:00:00', 'end' => '2026-11-29 00:00:00'],
            ]),
        );
        self::assertSame('baseline_unavailable', classifyBlockedPeriodSnapshotComparison(null, $baseline));
    }

    public function testBlockedWindowRemovalCountsRequirePositiveMatchingCounts(): void
    {
        self::assertSame(
            ['full_count' => 2, 'deleted_count' => 2],
            parseBlockedWindowRemovalCounts("full_count=2\ndeleted_count=2\n", 0, false),
        );
        self::assertFalse(
            validateBlockedWindowRemovalCounts(
                parseBlockedWindowRemovalCounts("full_count=2\ndeleted_count=1\n", 0, false),
            ),
        );
        self::assertFalse(
            validateBlockedWindowRemovalCounts(
                parseBlockedWindowRemovalCounts("full_count=0\ndeleted_count=0\n", 0, false),
            ),
        );
        self::assertNull(parseBlockedWindowRemovalCounts("full_count=2\n", 0, false));
        self::assertNull(parseBlockedWindowRemovalCounts("full_count=2\ndeleted_count=2\n", 1, false));
        self::assertNull(parseBlockedWindowRemovalCounts("full_count=2\ndeleted_count=2\n", 0, true));
    }

    public function testNormalRealSlotPathDoesNotSelectFixtureFallback(): void
    {
        $report = [
            'failure' => [
                'classification' => 'contract_mismatch',
                'message' => 'booking contract mismatch after a real slot was selected.',
            ],
            'state' => ['cleanup' => ['created' => [], 'deleted' => [], 'failures' => []]],
        ];

        self::assertFalse(
            isNoLiveSlotFailure(
                $report,
                ['booking_search_days' => 35],
                ['status' => 'fail', 'exit_code' => 1, 'timed_out' => false],
            ),
        );
    }

    private function report(): ZeroSurpriseReport
    {
        return new ZeroSurpriseReport(
            'ea_20260813_0102',
            'zs-release-20260813t010203z-abcd',
            [],
            sys_get_temp_dir() . '/unused-zero-surprise-report.json',
        );
    }

    private function cleaner(): ZeroSurpriseImageCleanup
    {
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds): array {
            if ($command === ['docker', 'info', '--format', '{{json .DockerRootDir}}']) {
                return [
                    'exit_code' => 0,
                    'stdout' => '"/tmp"' . PHP_EOL,
                    'stderr' => '',
                    'duration_ms' => 1.0,
                    'timed_out' => false,
                ];
            }

            return [
                'exit_code' => 0,
                'stdout' => '',
                'stderr' => '',
                'duration_ms' => 1.0,
                'timed_out' => false,
            ];
        };

        return new ZeroSurpriseImageCleanup($runner, static fn(string $path): int => 100);
    }
}

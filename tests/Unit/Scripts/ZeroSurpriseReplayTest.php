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
    public function testMigration074RehearsalRunsExactUpDownUpAndPreservesAggregateRows(): void
    {
        $phase = 0;
        $commands = [];
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use (&$phase, &$commands): array {
            $commands[] = implode(' ', $command);
            $joined = end($commands);
            if (str_contains($joined, 'console migrate')) {
                $phase++;
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (str_contains($joined, 'SHOW TABLES')) {
                return ['exit_code' => 0, 'stdout' => "ea_appointments\nea_migrations\n", 'stderr' => ''];
            }
            if (str_contains($joined, 'SELECT version')) {
                return ['exit_code' => 0, 'stdout' => ($phase === 1 || $phase === 3 ? "74\n" : "73\n"), 'stderr' => ''];
            }
            $indexed = $phase === 1 || $phase === 3;
            return [
                'exit_code' => 0,
                'stdout' => "5\t" . ($indexed ? "1\thash\t1\t1\t64" : "0\t\t-1\t-1\t-1") . "\n",
                'stderr' => '',
            ];
        };

        $result = runMigration074Rehearsal(['docker', 'compose'], '/repo', $runner);

        self::assertSame('pass', $result['status']);
        self::assertSame(0, $result['exit_code']);
        self::assertSame(
            ['migrate_up', 'migrate_down', 'migrate_up'],
            array_values(array_filter(array_column($result['substeps'], 'name'), static fn(string $name): bool => str_starts_with($name, 'migrate_'))),
        );
        self::assertSame(3, $phase);
    }

    public function testMigration074RehearsalFailsClosedOnMigrationFailure(): void
    {
        $phase = 0;
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use (&$phase): array {
            $joined = implode(' ', $command);
            if (str_contains($joined, 'console migrate')) {
                $phase++;
                return ['exit_code' => $phase === 2 ? 1 : 0, 'stdout' => '', 'stderr' => 'redacted'];
            }
            if (str_contains($joined, 'SHOW TABLES')) {
                return ['exit_code' => 0, 'stdout' => "ea_appointments\nea_migrations\n", 'stderr' => ''];
            }
            if (str_contains($joined, 'SELECT version')) {
                return ['exit_code' => 0, 'stdout' => ($phase === 1 ? "74\n" : "73\n"), 'stderr' => ''];
            }
            return ['exit_code' => 0, 'stdout' => ($phase === 1 ? "5\t1\thash\t1\t1\t64\n" : "5\t0\t\t-1\t-1\t-1\n"), 'stderr' => ''];
        };

        $result = runMigration074Rehearsal(['docker', 'compose'], '/repo', $runner);

        self::assertSame('fail', $result['status']);
        self::assertSame(2, $result['exit_code']);
        self::assertSame(['migration_state', 'migrate_up', 'migration_state', 'migrate_down'], array_column($result['substeps'], 'name'));
    }

    public function testMigration074BaselineAndStateChecksRejectUnsafeInputsAndDrift(): void
    {
        $baseline = [
            'appointments_table' => true,
            'migrations_table' => true,
            'version' => 73,
            'row_count' => 5,
            'index_count' => 0,
        ];
        self::assertTrue(migration074BaselineIsSafe($baseline));
        self::assertFalse(migration074BaselineIsSafe([...$baseline, 'version' => 72]));
        self::assertFalse(migration074BaselineIsSafe([...$baseline, 'index_count' => 1]));
        self::assertFalse(migration074BaselineIsSafe([...$baseline, 'appointments_table' => false]));

        $indexed = [...$baseline, 'version' => 74, 'index_count' => 1, 'column' => 'hash', 'non_unique' => 1, 'seq_in_index' => 1, 'sub_part' => 64];
        self::assertTrue(migration074StateMatches($indexed, 74, $baseline));
        self::assertFalse(migration074StateMatches([...$indexed, 'row_count' => 6], 74, $baseline));
        self::assertFalse(migration074StateMatches([...$indexed, 'sub_part' => 32], 74, $baseline));
        self::assertTrue(migration074StateMatches($baseline, 73, $baseline));
    }

    public function testMigration074InvariantClassifiesSuccessfulDiagnosticAsNonReleaseEvidence(): void
    {
        $invariants = collectMigration074Invariants(['status' => 'pass']);
        self::assertSame('pass', $invariants['migration_074_rehearsal']['status']);
        self::assertSame('unverified', $invariants['migration_074_rehearsal']['details']['input_attestation']);
        self::assertFalse($invariants['migration_074_rehearsal']['details']['release_evidence']);
    }

    public function testPredeployGateRunsUpDownUpForVersion073BeforeContinuing(): void
    {
        $migrationCalls = 0;
        $commands = [];
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use (&$migrationCalls, &$commands): array {
            $joined = implode(' ', $command);
            $commands[] = $joined;
            if (str_contains($joined, 'console migrate')) {
                $migrationCalls++;
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (str_contains($joined, 'SHOW TABLES')) {
                return ['exit_code' => 0, 'stdout' => "ea_appointments\nea_migrations\n", 'stderr' => ''];
            }
            if (str_contains($joined, 'SELECT version')) {
                return ['exit_code' => 0, 'stdout' => ($migrationCalls === 1 || $migrationCalls === 3 ? "74\n" : "73\n"), 'stderr' => ''];
            }
            $indexed = $migrationCalls === 1 || $migrationCalls === 3;
            return ['exit_code' => 0, 'stdout' => "5\t" . ($indexed ? "1\thash\t1\t1\t64" : "0\t\t-1\t-1\t-1") . "\n", 'stderr' => ''];
        };

        $result = runMigration074PredeployGate(['docker', 'compose'], '/repo', $runner);

        self::assertSame('pass', $result['status']);
        self::assertSame(3, $migrationCalls);
        self::assertSame(1, preg_match('/console migrate up.*console migrate down.*console migrate up/s', implode("\n", $commands)));
        self::assertStringNotContainsString('/private', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testPredeployGateKeepsVersion074PathWithoutRunningMigration(): void
    {
        $migrationCalls = 0;
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use (&$migrationCalls): array {
            $joined = implode(' ', $command);
            if (str_contains($joined, 'console migrate')) {
                $migrationCalls++;
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (str_contains($joined, 'SHOW TABLES')) {
                return ['exit_code' => 0, 'stdout' => "ea_appointments\nea_migrations\n", 'stderr' => ''];
            }
            if (str_contains($joined, 'SELECT version')) {
                return ['exit_code' => 0, 'stdout' => "74\n", 'stderr' => ''];
            }
            return ['exit_code' => 0, 'stdout' => "5\t1\thash\t1\t1\t64\n", 'stderr' => ''];
        };

        $result = runMigration074PredeployGate(['docker', 'compose'], '/repo', $runner);

        self::assertSame('pass', $result['status']);
        self::assertSame(0, $migrationCalls);
        self::assertSame('not_required', $result['substeps'][1]['category']);
    }

    public function testPredeployGateRejectsUnsupportedOrMalformedSchemaStates(): void
    {
        foreach ([
            ['version' => 72, 'index_count' => 0, 'column' => null, 'non_unique' => null, 'seq_in_index' => null, 'sub_part' => null],
            ['version' => null, 'index_count' => 0, 'column' => null, 'non_unique' => null, 'seq_in_index' => null, 'sub_part' => null],
            ['version' => 74, 'index_count' => 1, 'column' => 'hash', 'non_unique' => 1, 'seq_in_index' => 1, 'sub_part' => 32],
        ] as $invalid) {
            $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use ($invalid): array {
                $joined = implode(' ', $command);
                if (str_contains($joined, 'SHOW TABLES')) {
                    return ['exit_code' => 0, 'stdout' => "ea_appointments\nea_migrations\n", 'stderr' => ''];
                }
                if (str_contains($joined, 'SELECT version')) {
                    return ['exit_code' => 0, 'stdout' => ($invalid['version'] === null ? "not-a-version\n" : $invalid['version'] . "\n"), 'stderr' => ''];
                }
                return ['exit_code' => 0, 'stdout' => "5\t{$invalid['index_count']}\t{$invalid['column']}\t{$invalid['non_unique']}\t{$invalid['seq_in_index']}\t{$invalid['sub_part']}\n", 'stderr' => ''];
            };

            $result = runMigration074PredeployGate(['docker', 'compose'], '/repo', $runner);

            self::assertSame('fail', $result['status']);
            self::assertSame(ZERO_SURPRISE_EXIT_ASSERTION_FAILURE, $result['exit_code']);
        }
    }

    public function testPredeployMigrationProjectionRedactsFailureCommandAndOutputInSerializedReport(): void
    {
        $projected = sanitizePredeployMigrationSubsteps([[
            'name' => 'migrate_up',
            'status' => 'fail',
            'exit_code' => 1,
            'command' => 'docker compose --dump-file=/private/backup.sql',
            'stdout_tail' => 'secret stdout',
            'stderr_tail' => 'password=top-secret',
        ]]);
        $report = new ZeroSurpriseReport('synthetic', 'zs-synthetic', [], '/tmp/synthetic.json', 'predeploy');
        $report->addStep('restore_dump', 'fail', 2, 1.0, ['details' => ['substeps' => $projected]]);
        $serialized = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('/private/backup.sql', $serialized);
        self::assertStringNotContainsString('top-secret', $serialized);
        self::assertStringContainsString('migrate_up', $serialized);
        self::assertStringContainsString('"exit_code":1', $serialized);
    }

    public function testPredeployGateFailsClosedWhenVersionCannotBeEstablished(): void
    {
        $runner = static fn(array $command, string $workingDirectory, int $timeoutSeconds): array =>
            str_contains(implode(' ', $command), 'SELECT version')
                ? ['exit_code' => 1, 'stdout' => '', 'stderr' => 'private database detail']
                : ['exit_code' => 0, 'stdout' => "ea_appointments\nea_migrations\n", 'stderr' => ''];

        $result = runMigration074PredeployGate(['docker', 'compose'], '/repo', $runner);

        self::assertSame('fail', $result['status']);
        self::assertSame(ZERO_SURPRISE_EXIT_RUNTIME_ERROR, $result['exit_code']);
        self::assertStringNotContainsString('private database detail', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testTopLevelInvariantSelectionUsesDiagnosticModeAndReportMode(): void
    {
        $selected = collectReplayInvariants(
            ['rehearse_migration_074' => true],
            ['status' => 'pass'],
            null,
            null,
        );
        self::assertArrayHasKey('migration_074_rehearsal', $selected);
        self::assertArrayNotHasKey('overbooking', $selected);

        $report = new ZeroSurpriseReport('synthetic', 'zs-synthetic', [], '/tmp/synthetic.json', 'migration074-diagnostic');
        self::assertSame('migration074-diagnostic', $report->toArray()['meta']['mode']);
        $normal = new ZeroSurpriseReport('synthetic', 'zs-synthetic', [], '/tmp/synthetic.json');
        self::assertSame('predeploy', $normal->toArray()['meta']['mode']);
    }

    public function testDiagnosticRestoreProjectionDropsEarlyFailurePathsAndOutput(): void
    {
        $details = diagnosticRestoreReportDetails(['rehearse_migration_074' => true], [
            'details' => [
            'failed_substep' => 'compose_up',
            'substeps' => [[
                'name' => 'compose_up',
                'status' => 'fail',
                'exit_code' => 2,
                'command' => 'docker compose --dump-file=/secret/backup.sql',
                'stderr_tail' => 'password=top-secret /private/path',
            ]],
            ],
        ]);
        $report = new ZeroSurpriseReport('synthetic', 'zs-synthetic', [], '/tmp/synthetic.json', 'migration074-diagnostic');
        $report->addStep('restore_dump', 'fail', 2, 1.0, ['details' => $details]);
        $serialized = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('/secret/backup.sql', $serialized);
        self::assertStringNotContainsString('top-secret', $serialized);
        self::assertStringContainsString('compose_up', $serialized);
        self::assertStringContainsString('"exit_code":2', $serialized);

        $ordinary = diagnosticRestoreReportDetails(['rehearse_migration_074' => false], ['details' => ['command' => '/secret/backup.sql']]);
        self::assertSame('/secret/backup.sql', $ordinary['command']);
    }

    public function testReplayOverlayUsesVerifiedMariaDbRestoreImageAndCredentials(): void
    {
        $overlay = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/compose.zero-surprise-replay.yml');
        $restoreVerifier = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scripts/ops/libexec/deployment_dump_attestation_v1.py',
        );
        self::assertSame(1, preg_match("/^IMAGE = '([^']+)'$/m", $restoreVerifier, $restoreMatch));

        self::assertSame(1, preg_match("/image:\s*'([^']+)'/", $overlay, $overlayMatch));
        self::assertSame($restoreMatch[1] ?? null, $overlayMatch[1] ?? null);
        self::assertStringContainsString('MARIADB_ROOT_PASSWORD: secret', $overlay);
        self::assertStringContainsString('MARIADB_DATABASE: easyappointments', $overlay);
        self::assertStringContainsString('MARIADB_USER: user', $overlay);
        self::assertStringContainsString('MARIADB_PASSWORD: password', $overlay);
        self::assertStringNotContainsString('build:', $overlay);
        $generalOverlay = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/compose.zero-surprise.yml');
        self::assertStringContainsString('zero_surprise_mysql_data:/var/lib/mysql', $generalOverlay);
        self::assertStringContainsString('ports: !reset []', $generalOverlay);
    }

    public function testHttpReadinessDiagnosticsRetainOnlyNumericFailureOutcomes(): void
    {
        self::assertSame(
            ['curl_exit_code' => 22, 'http_status_code' => 500],
            httpReadinessDiagnostics([
                'exit_code' => 22,
                'stdout' => '500',
                'stderr' => 'private runtime detail',
            ]),
        );
        self::assertSame(
            ['curl_exit_code' => 6, 'http_status_code' => 0],
            httpReadinessDiagnostics(['exit_code' => 6, 'stdout' => '000']),
        );
        self::assertSame(
            ['curl_exit_code' => null, 'http_status_code' => null],
            httpReadinessDiagnostics(['exit_code' => '22', 'stdout' => '500 private runtime detail']),
        );
    }

    public function testReplayRuntimePreflightRequiresDigestPinnedImagesAndCapturesBuildIdentities(): void
    {
        $commands = [];
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use (
            &$commands,
        ): array {
            $commands[] = $command;
            $joined = implode(' ', $command);
            if (str_ends_with($joined, 'config --format json')) {
                return [
                    'exit_code' => 0,
                    'stdout' => json_encode([
                        'services' => [
                            'mysql' => ['image' => 'mysql@sha256:' . str_repeat('a', 64)],
                            'nginx' => ['image' => 'nginx@sha256:' . str_repeat('b', 64)],
                            'php-fpm' => ['build' => ['context' => '.']],
                            'pdf-renderer' => ['build' => ['context' => '.']],
                        ],
                    ]),
                    'stderr' => '',
                ];
            }
            if (str_contains($joined, 'ps --all -q php-fpm')) {
                return ['exit_code' => 0, 'stdout' => "php-container\n", 'stderr' => ''];
            }
            if (str_contains($joined, 'ps --all -q pdf-renderer')) {
                return ['exit_code' => 0, 'stdout' => "pdf-container\n", 'stderr' => ''];
            }
            if (str_contains($joined, 'inspect --format')) {
                return ['exit_code' => 0, 'stdout' => 'sha256:' . str_repeat('c', 64) . "\n", 'stderr' => ''];
            }

            return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        };

        $result = preflightReplayRuntime('/repo', ['docker', 'compose'], $runner);

        self::assertSame('pass', $result['status']);
        self::assertSame(['php-fpm', 'pdf-renderer'], array_keys($result['image_ids']));
        self::assertStringContainsString(
            '--pull=false',
            implode("\n", array_map(static fn(array $command): string => implode(' ', $command), $commands)),
        );
        self::assertContains(
            '--all',
            array_merge(...array_map(static fn(array $command): array => $command, $commands)),
        );
    }

    public function testReplayRuntimePreflightFailsClosedOnMutableImageBeforeBuild(): void
    {
        $commands = [];
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use (
            &$commands,
        ): array {
            $commands[] = $command;

            return [
                'exit_code' => 0,
                'stdout' => json_encode([
                    'services' => [
                        'mysql' => ['image' => 'mysql:8.4'],
                        'nginx' => ['image' => 'nginx@sha256:' . str_repeat('b', 64)],
                        'php-fpm' => ['build' => ['context' => '.']],
                        'pdf-renderer' => ['build' => ['context' => '.']],
                    ],
                ]),
                'stderr' => '',
            ];
        };

        $result = preflightReplayRuntime('/repo', ['docker', 'compose'], $runner);

        self::assertSame('fail', $result['status']);
        self::assertSame('runtime_preflight_image_not_digest_pinned', $result['category']);
        self::assertCount(1, $commands);
        self::assertStringNotContainsString('build', implode(' ', $commands[0]));
    }

    public function testReplayStopsBeforeDumpImportWhenStartedImageIdentityDrifts(): void
    {
        $commands = [];
        $postStart = false;
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds) use (
            &$commands,
            &$postStart,
        ): array {
            $commands[] = $command;
            $joined = implode(' ', $command);
            if (str_contains($joined, 'config --format json')) {
                return [
                    'exit_code' => 0,
                    'stdout' => json_encode([
                        'services' => [
                            'mysql' => ['image' => 'mysql@sha256:' . str_repeat('a', 64)],
                            'nginx' => ['image' => 'nginx@sha256:' . str_repeat('b', 64)],
                            'php-fpm' => ['build' => ['context' => '.']],
                            'pdf-renderer' => ['build' => ['context' => '.']],
                        ],
                    ]),
                    'stderr' => '',
                ];
            }
            if (str_contains($joined, 'ps --all -q')) {
                return ['exit_code' => 0, 'stdout' => "created-container\n", 'stderr' => ''];
            }
            if (str_contains($joined, 'ps -q')) {
                $postStart = true;
                return ['exit_code' => 0, 'stdout' => "running-container\n", 'stderr' => ''];
            }
            if (str_contains($joined, 'inspect --format')) {
                return [
                    'exit_code' => 0,
                    'stdout' => 'sha256:' . str_repeat($postStart ? 'd' : 'c', 64) . "\n",
                    'stderr' => '',
                ];
            }

            return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        };

        $result = runRestoreDumpStep('/repo', ['docker', 'compose'], ['dump_file' => '/tmp/synthetic.sql'], $runner);

        self::assertSame('fail', $result['status']);
        self::assertSame('runtime_identity_recheck', $result['details']['failed_substep']);
        self::assertSame(
            [],
            array_values(
                array_filter(
                    $commands,
                    static fn(array $command): bool => str_contains(implode(' ', $command), 'mysqladmin'),
                ),
            ),
        );
        self::assertSame(
            [],
            array_values(
                array_filter(
                    $commands,
                    static fn(array $command): bool => str_contains(implode(' ', $command), 'easyappointments'),
                ),
            ),
        );
    }

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
                '-f',
                'docker/compose.zero-surprise-replay.yml',
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
        $this->assertMatchesRegularExpression(
            "/SET @zs_password_hash = '" . preg_quote('$argon2id$', '/') . "[^']+';/",
            $sql,
        );
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

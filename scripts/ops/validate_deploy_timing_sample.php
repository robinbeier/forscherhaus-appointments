<?php
declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

final class DeployTimingSampleValidator
{
    private const PHASES = [
        'preparation_artifact',
        'predeploy',
        'permissions_stage',
        'switch',
        'postdeploy_validation',
    ];

    /**
     * @return array{run_id:string,total_ms:int,records:int}
     */
    public static function validateFile(string $path): array
    {
        self::assertRootProtectedSource($path);

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('timing source is empty or unreadable');
        }

        $lines = preg_split('/\R/', rtrim($contents, "\r\n"));
        if (!is_array($lines) || in_array('', $lines, true)) {
            throw new RuntimeException('timing source contains an empty record');
        }

        return self::validateLines($lines);
    }

    /**
     * @param list<string> $lines
     * @return array{run_id:string,total_ms:int,records:int}
     */
    public static function validateLines(array $lines): array
    {
        if (count($lines) !== 6) {
            throw new RuntimeException('successful timing sample must contain exactly six records');
        }

        $events = [];
        foreach ($lines as $index => $line) {
            try {
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException(sprintf('timing record %d is not valid JSON', $index + 1));
            }
            if (!is_array($event) || array_is_list($event)) {
                throw new RuntimeException(sprintf('timing record %d must be a JSON object', $index + 1));
            }
            $events[] = $event;
        }

        $runId = $events[0]['run_id'] ?? null;
        if (
            !is_string($runId) ||
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $runId) !== 1
        ) {
            throw new RuntimeException('timing sample has an invalid run_id');
        }

        $previousElapsed = -1;
        foreach ($events as $index => $event) {
            $expectedSequence = $index + 1;
            if (($event['schema'] ?? null) !== 'deploy_timing.v1') {
                throw new RuntimeException(sprintf('timing record %d has an unexpected schema', $expectedSequence));
            }
            if (($event['run_id'] ?? null) !== $runId) {
                throw new RuntimeException('timing source mixes multiple run_id values');
            }
            if (($event['sequence'] ?? null) !== $expectedSequence) {
                throw new RuntimeException('timing sequence is missing, duplicated, or out of order');
            }
            if (($event['mode'] ?? null) !== 'deploy' || ($event['dry_run'] ?? null) !== false) {
                throw new RuntimeException('timing sample is not a real deploy run');
            }

            if ($index < 5) {
                self::assertExactKeys($event, [
                    'schema',
                    'run_id',
                    'sequence',
                    'event',
                    'mode',
                    'phase',
                    'status',
                    'duration_ms',
                    'elapsed_ms',
                    'dry_run',
                ]);
                if (($event['event'] ?? null) !== 'phase' || ($event['phase'] ?? null) !== self::PHASES[$index]) {
                    throw new RuntimeException('timing phases are missing, duplicated, or out of order');
                }
                if (($event['status'] ?? null) !== 'ok') {
                    throw new RuntimeException('successful timing sample contains a failed phase');
                }
                self::assertNonNegativeInteger($event['duration_ms'] ?? null, 'duration_ms');
                self::assertNonNegativeInteger($event['elapsed_ms'] ?? null, 'elapsed_ms');
                if ($event['elapsed_ms'] < $previousElapsed) {
                    throw new RuntimeException('elapsed_ms is not monotonic');
                }
                $previousElapsed = $event['elapsed_ms'];
                continue;
            }

            self::assertExactKeys($event, [
                'schema',
                'run_id',
                'sequence',
                'event',
                'mode',
                'outcome',
                'exit_code',
                'total_ms',
                'dry_run',
            ]);
            if (($event['event'] ?? null) !== 'summary' || ($event['outcome'] ?? null) !== 'succeeded') {
                throw new RuntimeException('timing sample must end with exactly one successful summary');
            }
            if (($event['exit_code'] ?? null) !== 0) {
                throw new RuntimeException('timing summary exit_code must be zero');
            }
            self::assertNonNegativeInteger($event['total_ms'] ?? null, 'total_ms');
            if ($event['total_ms'] < $previousElapsed) {
                throw new RuntimeException('total_ms precedes the final phase');
            }
        }

        return ['run_id' => $runId, 'total_ms' => $events[5]['total_ms'], 'records' => 6];
    }

    private static function assertRootProtectedSource(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path)) {
            throw new RuntimeException('timing source must be an absolute regular non-symlink file');
        }

        $canonical = realpath($path);
        if ($canonical === false || $canonical !== $path) {
            throw new RuntimeException('timing source path must be canonical and symlink-free');
        }

        $stat = lstat($path);
        if (
            !is_array($stat) ||
            ($stat['uid'] ?? null) !== 0 ||
            (($stat['mode'] ?? 0) & 0777) !== 0600 ||
            ($stat['nlink'] ?? null) !== 1
        ) {
            throw new RuntimeException('timing source must be root-owned mode 0600 with one hardlink');
        }

        $sourceDirectory = dirname($path);
        $cursor = $sourceDirectory;
        while (true) {
            if (is_link($cursor) || !is_dir($cursor)) {
                throw new RuntimeException('timing source ancestor must be a non-symlink directory');
            }
            $directoryStat = lstat($cursor);
            if (
                !is_array($directoryStat) ||
                ($directoryStat['uid'] ?? null) !== 0 ||
                (($directoryStat['mode'] ?? 0) & 0022) !== 0
            ) {
                throw new RuntimeException('timing source ancestors must be root-controlled');
            }
            if ($cursor === $sourceDirectory && (($directoryStat['mode'] ?? 0) & 0777) !== 0700) {
                throw new RuntimeException('timing source directory must use mode 0700');
            }
            if ($cursor === DIRECTORY_SEPARATOR) {
                break;
            }
            $cursor = dirname($cursor);
        }
    }

    /**
     * @param array<string,mixed> $event
     * @param list<string> $expected
     */
    private static function assertExactKeys(array $event, array $expected): void
    {
        $actual = array_keys($event);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('timing record contains missing or unexpected fields');
        }
    }

    private static function assertNonNegativeInteger(mixed $value, string $field): void
    {
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException($field . ' must be a non-negative integer');
        }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $file = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--file=')) {
            $file = substr($argument, strlen('--file='));
            continue;
        }
        fwrite(STDERR, "Usage: php scripts/ops/validate_deploy_timing_sample.php --file=/absolute/path.jsonl\n");
        exit(2);
    }
    if (!is_string($file) || $file === '') {
        fwrite(STDERR, "Usage: php scripts/ops/validate_deploy_timing_sample.php --file=/absolute/path.jsonl\n");
        exit(2);
    }

    try {
        $result = DeployTimingSampleValidator::validateFile($file);
        fwrite(
            STDOUT,
            json_encode(
                [
                    'schema' => 'deploy_timing_validation.v1',
                    'valid' => true,
                    'run_id' => $result['run_id'],
                    'records' => $result['records'],
                    'total_ms' => $result['total_ms'],
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ) . PHP_EOL,
        );
        exit(0);
    } catch (RuntimeException | JsonException $exception) {
        fwrite(STDERR, 'INVALID: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

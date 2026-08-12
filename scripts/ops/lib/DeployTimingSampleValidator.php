<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

final class DeployTimingSampleValidator
{
    private const MAX_UNATTRIBUTED_MS = 30;
    private const PHASES = [
        'preparation_artifact',
        'predeploy',
        'permissions_stage',
        'switch',
        'postdeploy_validation',
    ];

    /** @return array{run_id:string,total_ms:int,records:int,exit_code:int,outcome:string} */
    public static function validateBytes(string $contents): array
    {
        if (
            $contents === '' ||
            strlen($contents) > 1_048_576 ||
            str_contains($contents, "\0") ||
            str_contains($contents, "\r") ||
            !str_ends_with($contents, "\n")
        ) {
            throw new RuntimeException('timing source bytes are invalid');
        }
        $contents = substr($contents, 0, -1);
        $lines = explode("\n", $contents);
        if (in_array('', $lines, true)) {
            throw new RuntimeException('timing source contains an empty record');
        }
        return self::validateLines($lines);
    }

    /** @param list<string> $lines @return array{run_id:string,total_ms:int,records:int,exit_code:int,outcome:string} */
    public static function validateLines(array $lines): array
    {
        if (count($lines) !== 6) {
            throw new RuntimeException('successful timing sample must contain exactly six records');
        }
        $events = [];
        foreach ($lines as $index => $line) {
            try {
                $event = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException(sprintf('timing record %d is not valid JSON', $index + 1));
            }
            if (!is_array($event) || array_is_list($event)) {
                throw new RuntimeException('timing record must be an object');
            }
            $events[] = $event;
        }
        $runId = $events[0]['run_id'] ?? null;
        self::assertUuidV4($runId);
        $previousElapsed = 0;
        $phaseDurationTotal = 0;
        foreach ($events as $index => $event) {
            $sequence = $index + 1;
            if (($event['schema'] ?? null) !== 'deploy_timing.v1') {
                throw new RuntimeException(sprintf('timing record %d has an unexpected schema', $sequence));
            }
            if (($event['run_id'] ?? null) !== $runId) {
                throw new RuntimeException('timing source mixes multiple run_id values');
            }
            if (($event['sequence'] ?? null) !== $sequence) {
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
                if (
                    ($event['event'] ?? null) !== 'phase' ||
                    ($event['phase'] ?? null) !== self::PHASES[$index] ||
                    ($event['status'] ?? null) !== 'ok'
                ) {
                    throw new RuntimeException('timing phases are missing, failed, or out of order');
                }
                self::assertNonNegative($event['duration_ms'] ?? null);
                self::assertNonNegative($event['elapsed_ms'] ?? null);
                if ($event['elapsed_ms'] < $previousElapsed) {
                    throw new RuntimeException('elapsed_ms is not monotonic');
                }
                if ($event['duration_ms'] > $event['elapsed_ms'] - $previousElapsed) {
                    throw new RuntimeException('phase duration exceeds its elapsed_ms window');
                }
                $phaseDurationTotal += $event['duration_ms'];
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
            if (
                ($event['event'] ?? null) !== 'summary' ||
                ($event['outcome'] ?? null) !== 'succeeded' ||
                ($event['exit_code'] ?? null) !== 0
            ) {
                throw new RuntimeException('timing sample must end with exactly one successful summary');
            }
            self::assertNonNegative($event['total_ms'] ?? null);
            if ($event['total_ms'] < $previousElapsed) {
                throw new RuntimeException('total_ms precedes the final phase');
            }
            if ($event['total_ms'] - $phaseDurationTotal > self::MAX_UNATTRIBUTED_MS) {
                throw new RuntimeException(sprintf('unattributed timing exceeds %d ms', self::MAX_UNATTRIBUTED_MS));
            }
        }
        return [
            'run_id' => $runId,
            'total_ms' => $events[5]['total_ms'],
            'records' => 6,
            'exit_code' => 0,
            'outcome' => 'succeeded',
        ];
    }

    /** @param array<string,mixed> $event @param list<string> $keys */
    private static function assertExactKeys(array $event, array $keys): void
    {
        $actual = array_keys($event);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new RuntimeException('timing record contains missing or unexpected fields');
        }
    }
    private static function assertUuidV4(mixed $value): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1
        ) {
            throw new RuntimeException('timing sample has an invalid run_id');
        }
    }
    private static function assertNonNegative(mixed $value): void
    {
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('timing value must be non-negative');
        }
    }

    private static function assertRootProtectedSource(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path) || realpath($path) !== $path) {
            throw new RuntimeException('timing source must be an absolute regular non-symlink file');
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

    /** @return array{run_id:string,total_ms:int,records:int,exit_code:int,outcome:string} */
    public static function validateFile(string $path): array
    {
        self::assertRootProtectedSource($path);
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('timing source is empty or unreadable');
        }
        if (!str_ends_with($bytes, "\n")) {
            $bytes .= "\n";
        }
        return self::validateBytes($bytes);
    }
}

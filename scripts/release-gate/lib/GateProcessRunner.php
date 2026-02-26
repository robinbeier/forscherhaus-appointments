<?php

declare(strict_types=1);

namespace ReleaseGate;

use InvalidArgumentException;
use RuntimeException;

final class GateProcessRunner
{
    /**
     * @param array<int, string> $command
     * @param array<string, string>|null $environment
     *
     * @return array{
     *   command:string,
     *   exit_code:int,
     *   stdout:string,
     *   stderr:string,
     *   duration_ms:float,
     *   timed_out:bool
     * }
     */
    public static function run(
        array $command,
        ?string $workingDirectory = null,
        ?array $environment = null,
        int $timeoutSeconds = 60,
    ): array {
        if ($command === []) {
            throw new InvalidArgumentException('Command must not be empty.');
        }

        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Timeout must be a positive integer.');
        }

        $startedAt = microtime(true);
        $timedOut = false;
        $stdout = '';
        $stderr = '';

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $environment);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start process: ' . self::formatCommand($command));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            $running = is_array($status) && ($status['running'] ?? false);
            $elapsed = microtime(true) - $startedAt;

            if ($elapsed >= $timeoutSeconds) {
                $timedOut = true;
                break;
            }

            $read = [];

            if (is_resource($pipes[1]) && !feof($pipes[1])) {
                $read[] = $pipes[1];
            }

            if (is_resource($pipes[2]) && !feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read !== []) {
                $seconds = 0;
                $microseconds = 0;

                if ($running) {
                    $remaining = max(0.0, $timeoutSeconds - $elapsed);
                    $seconds = (int) floor($remaining);
                    $microseconds = (int) (($remaining - $seconds) * 1_000_000);
                }

                $write = null;
                $except = null;
                $selected = @stream_select($read, $write, $except, $seconds, $microseconds);

                if ($selected === false) {
                    break;
                }

                if ($selected === 0 && !$running) {
                    break;
                }

                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);

                    if (!is_string($chunk) || $chunk === '') {
                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            } elseif (!$running) {
                break;
            } else {
                usleep(10_000);
            }

            if (!$running && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }

        if ($timedOut) {
            @proc_terminate($process, 15);
            usleep(100_000);

            $status = proc_get_status($process);
            $stillRunning = is_array($status) && ($status['running'] ?? false);

            if ($stillRunning) {
                @proc_terminate($process, 9);
            }
        }

        $stdoutRemainder = stream_get_contents($pipes[1]);
        if (is_string($stdoutRemainder) && $stdoutRemainder !== '') {
            $stdout .= $stdoutRemainder;
        }

        $stderrRemainder = stream_get_contents($pipes[2]);
        if (is_string($stderrRemainder) && $stderrRemainder !== '') {
            $stderr .= $stderrRemainder;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($timedOut) {
            $exitCode = 124;
        }

        return [
            'command' => self::formatCommand($command),
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'timed_out' => $timedOut,
        ];
    }

    /**
     * @param array<int, string> $command
     */
    private static function formatCommand(array $command): string
    {
        return implode(
            ' ',
            array_map(static function (string $arg): string {
                if ($arg === '') {
                    return "''";
                }

                return preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $arg) === 1 ? $arg : escapeshellarg($arg);
            }, $command),
        );
    }
}

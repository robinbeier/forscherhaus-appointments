<?php

declare(strict_types=1);

final class GithubPrWriteProcessRunner
{
    public function __construct(private readonly int $maximumOutputBytes)
    {
        if ($maximumOutputBytes < 1) {
            throw new InvalidArgumentException('Child process output limit must be positive.');
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(array $command, string $stdin, array $environment): array
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment, [
            'bypass_shell' => true,
        ]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the bounded child process.');
        }

        foreach ($pipes as $pipe) {
            if (!is_resource($pipe) || !stream_set_blocking($pipe, false)) {
                $this->terminate($process, $pipes);
                throw new RuntimeException('Unable to configure bounded child process pipes.');
            }
        }

        $stdinOffset = 0;
        $stdinLength = strlen($stdin);
        $stdinOpen = true;
        $stdoutOpen = true;
        $stderrOpen = true;
        $stdout = '';
        $stderr = '';
        $processClosed = false;

        try {
            while ($stdinOpen || $stdoutOpen || $stderrOpen) {
                if ($stdinOpen && $stdinOffset >= $stdinLength) {
                    fclose($pipes[0]);
                    $stdinOpen = false;
                }

                $read = [];
                if ($stdoutOpen) {
                    $read[] = $pipes[1];
                }
                if ($stderrOpen) {
                    $read[] = $pipes[2];
                }
                $write = $stdinOpen ? [$pipes[0]] : [];
                $except = [];

                if ($read === [] && $write === []) {
                    break;
                }
                $ready = @stream_select($read, $write, $except, 1);
                if ($ready === false) {
                    throw new RuntimeException('Unable to coordinate bounded child process pipes.');
                }
                if ($ready === 0) {
                    continue;
                }

                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read bounded child process output.');
                    }
                    if ($chunk === '') {
                        if (!feof($pipe)) {
                            continue;
                        }
                        if ($pipe === $pipes[1]) {
                            fclose($pipes[1]);
                            $stdoutOpen = false;
                        } else {
                            fclose($pipes[2]);
                            $stderrOpen = false;
                        }
                        continue;
                    }

                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                        if (strlen($stdout) > $this->maximumOutputBytes) {
                            throw new RuntimeException('Child process output exceeded the bounded size.');
                        }
                    } else {
                        $stderr .= $chunk;
                        if (strlen($stderr) > $this->maximumOutputBytes) {
                            throw new RuntimeException('Child process output exceeded the bounded size.');
                        }
                    }
                }

                if ($write !== []) {
                    $written = @fwrite($pipes[0], substr($stdin, $stdinOffset, 8192));
                    if ($written === false) {
                        throw new RuntimeException('Unable to deliver bounded child input.');
                    }
                    $stdinOffset += $written;
                }
            }

            $exitCode = proc_close($process);
            $processClosed = true;
        } catch (Throwable $exception) {
            $this->terminate($process, $pipes);
            $processClosed = true;
            throw $exception;
        } finally {
            if (!$processClosed) {
                $this->terminate($process, $pipes);
            }
        }

        return [
            'exit_code' => is_int($exitCode) ? $exitCode : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function terminate($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        @proc_terminate($process);
        proc_close($process);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/** Small, side-effect-bounded gates shared by Linux root/host contract tests. */
final class RootHostTestPrerequisites
{
    public static function requiredProfile(?array $env = null): bool
    {
        $value = ($env ?? $_ENV)['FH_ROOT_HOST_TESTS_REQUIRED'] ?? getenv('FH_ROOT_HOST_TESTS_REQUIRED');
        return $value === '1';
    }

    /** Resolve SIGKILL without relying on the PHP constant being defined. */
    public static function signalNumber(string $name, ?int $defined = null): ?int
    {
        if ($defined !== null) {
            return $defined;
        }
        if ($name === 'SIGKILL') {
            return 9;
        }
        return null;
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function signalCheck(?int $defined = null, ?bool $killAvailable = null): array
    {
        if (!($killAvailable ?? function_exists('posix_kill'))) {
            return self::classify(false, 'process_signal_missing', 'PHP POSIX signal support is required.');
        }
        $number = self::signalNumber('SIGKILL', $defined);
        return $number === 9
            ? self::classify(true, 'signal_available', 'SIGKILL is available as signal 9.')
            : self::classify(false, 'signal_unavailable', 'SIGKILL does not resolve to Linux signal 9.', false);
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function classify(bool $ok, string $code, string $message, bool $localSkippable = true): array
    {
        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'local_skippable' => $localSkippable];
    }

    /** @param array{ok:bool,code:string,message:string,local_skippable?:bool} $check */
    public static function outcome(array $check, bool $required): string
    {
        if ($check['ok']) {
            return 'pass';
        }
        return $required || !($check['local_skippable'] ?? true) ? 'fail' : 'skip';
    }

    /** Apply the local-skip/required-profile-fail contract. */
    public static function enforce(TestCase $test, array $check, ?bool $required = null): void
    {
        $requiredProfile = $required ?? self::requiredProfile();
        $outcome = self::outcome($check, $requiredProfile);
        if ($outcome === 'pass') {
            return;
        }
        if ($outcome === 'fail') {
            $suffix = $requiredProfile
                ? ' Required Linux root/host profile is unavailable.'
                : ' Existing local root/host prerequisite is unsafe or unusable.';
            $test::fail($check['message'] . $suffix);
        }
        $test->markTestSkipped($check['message'] . ' Unsupported local Docker-Desktop profile.');
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function processRuntimeCheck(): array
    {
        if (!function_exists('proc_open') || !function_exists('posix_geteuid')) {
            return self::classify(
                false,
                'process_runtime_missing',
                'PHP proc_open and POSIX identity support are required.',
            );
        }
        return self::classify(
            true,
            'process_runtime_available',
            'PHP process and POSIX identity support is available.',
        );
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function pythonRuntimeCheck(): array
    {
        if (!is_file('/usr/bin/python3') || !is_executable('/usr/bin/python3')) {
            return self::classify(
                false,
                'python_missing',
                'Exact Python runtime /usr/bin/python3 is missing or not executable.',
            );
        }
        return self::classify(true, 'python_available', 'Exact Python runtime is available.');
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function dockerBinaryCheck(string $path = '/usr/bin/docker'): array
    {
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return self::classify(false, 'docker_binary_missing', "Exact Docker binary $path is missing.");
        }
        $trustedAncestors = true;
        foreach (['/', '/usr', '/usr/bin'] as $directory) {
            $directoryStat = @lstat($directory);
            $trustedAncestors =
                $trustedAncestors &&
                is_array($directoryStat) &&
                ($directoryStat['mode'] & 0170000) === 0040000 &&
                $directoryStat['uid'] === 0 &&
                $directoryStat['gid'] === 0 &&
                ($directoryStat['mode'] & 0022) === 0;
        }
        $trusted =
            ($stat['mode'] & 0170000) === 0100000 &&
            ($stat['mode'] & 0777) === 0755 &&
            $stat['uid'] === 0 &&
            $stat['gid'] === 0 &&
            $stat['nlink'] === 1 &&
            is_executable($path) &&
            $trustedAncestors;
        return $trusted
            ? self::classify(true, 'docker_binary_available', 'Exact trusted Docker binary is available.')
            : self::classify(
                false,
                'docker_binary_untrusted',
                "Existing Docker binary $path does not satisfy the exact trust precondition.",
                false,
            );
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function dockerSocketCheck(string $path = '/var/run/docker.sock'): array
    {
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return self::classify(false, 'docker_socket_missing', "Docker socket $path is missing.");
        }
        if (($stat['mode'] & 0170000) !== 0140000 || !is_readable($path) || !is_writable($path)) {
            return self::classify(
                false,
                'docker_socket_unusable',
                "Existing Docker socket $path is not a usable Unix socket.",
                false,
            );
        }
        return self::classify(true, 'docker_socket_available', 'Docker socket is a usable Unix socket.');
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function dockerDaemonCheck(string $binary = '/usr/bin/docker'): array
    {
        $command = [$binary, 'version', '--format', '{{.Server.Version}}'];
        try {
            $process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, [
                'DOCKER_HOST' => 'unix:///var/run/docker.sock',
                'LC_ALL' => 'C',
                'PATH' => '/usr/bin:/bin',
            ]);
        } catch (\Throwable) {
            $process = false;
        }
        if (!is_resource($process)) {
            return self::dockerDaemonFromResult(false, 127, '');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return self::dockerDaemonFromResult(true, $exit, $stdout);
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function dockerDaemonFromResult(bool $started, int $exit, string $stdout): array
    {
        return $started && $exit === 0 && trim($stdout) !== ''
            ? self::classify(
                true,
                'docker_daemon_available',
                'Docker daemon is reachable through the exact Docker binary.',
            )
            : self::classify(
                false,
                'docker_daemon_unreachable',
                'Docker daemon is not reachable through the exact binary and socket.',
                false,
            );
    }

    /** Probe www-data ownership in a disposable leaf, before touching fixed roots. */
    public static function ownershipCheck(int $uid, int $gid, ?string $parent = null): array
    {
        $leaf = rtrim($parent ?? sys_get_temp_dir(), '/') . '/.fh-root-host-ownership-' . bin2hex(random_bytes(8));
        if (!@mkdir($leaf, 0700)) {
            return self::classify(
                false,
                'ownership_probe_failed',
                'Could not create the temporary ownership probe leaf.',
            );
        }
        try {
            @chown($leaf, $uid);
            @chgrp($leaf, $gid);
            $stat = @lstat($leaf);
            return self::ownershipFromIdentity(
                is_array($stat) ? $stat['uid'] : null,
                is_array($stat) ? $stat['gid'] : null,
                $uid,
                $gid,
            );
        } finally {
            @rmdir($leaf);
        }
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function ownershipFromIdentity(?int $actualUid, ?int $actualGid, int $uid, int $gid): array
    {
        return $actualUid === $uid && $actualGid === $gid
            ? self::classify(true, 'ownership_supported', 'The filesystem supports the required www-data ownership.')
            : self::classify(
                false,
                'ownership_unsupported',
                'The filesystem cannot represent the required www-data ownership.',
            );
    }

    /** @return array{state:string,start_time:string}|null */
    public static function linuxProcessObservation(int $pid): ?array
    {
        $bytes = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($bytes)) {
            return null;
        }
        $closing = strrpos($bytes, ')');
        if ($closing === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($bytes, $closing + 1)));
        if (!is_array($fields) || !isset($fields[0], $fields[19])) {
            return null;
        }
        return ['state' => $fields[0], 'start_time' => $fields[19]];
    }

    /** @param array{state:string,start_time:string}|null $observation */
    public static function originalProcessIsRunning(?array $observation, string $startTime): bool
    {
        return is_array($observation) &&
            $observation['start_time'] === $startTime &&
            !in_array($observation['state'], ['X', 'Z'], true);
    }

    /** Check the exact setpriv capability contract using a disposable www-data-owned file. */
    public static function capabilitySemantics(int $uid, int $gid): array
    {
        if (!is_executable('/usr/bin/setpriv') || !is_executable('/usr/bin/python3')) {
            return self::classify(
                false,
                'capability_probe_unavailable',
                'The setpriv/python capability probe is unavailable.',
            );
        }
        $leaf = sys_get_temp_dir() . '/fh-root-host-capability-' . bin2hex(random_bytes(8));
        if (!@file_put_contents($leaf, 'x')) {
            return self::classify(false, 'capability_probe_failed', 'Could not create the capability probe leaf.');
        }
        try {
            if (!@chown($leaf, $uid) || !@chgrp($leaf, $gid) || !@chmod($leaf, 0400)) {
                return self::classify(false, 'capability_probe_failed', 'Could not prepare the capability probe leaf.');
            }
            $run = static function (bool $fowner) use ($leaf): int {
                $caps = '-all,+dac_override' . ($fowner ? ',+fowner' : '');
                $p = @proc_open(
                    [
                        '/usr/bin/setpriv',
                        '--bounding-set=' . $caps,
                        '--inh-caps=-all',
                        '--ambient-caps=-all',
                        '/usr/bin/python3',
                        '-c',
                        'import os; os.chmod(os.environ["FH_CAP_LEAF"], 0o600)',
                    ],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                    null,
                    ['FH_CAP_LEAF' => $leaf],
                );
                if (!is_resource($p)) {
                    return 127;
                }
                fclose($pipes[0]);
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                return proc_close($p);
            };
            @chmod($leaf, 0400);
            $without = $run(false);
            @chmod($leaf, 0400);
            $with = $run(true);
            return self::capabilitySemanticsFromExitCodes($without, $with);
        } finally {
            @unlink($leaf);
        }
    }

    /** @return array{ok:bool,code:string,message:string,local_skippable:bool} */
    public static function capabilitySemanticsFromExitCodes(int $withoutFowner, int $withFowner): array
    {
        return $withoutFowner !== 0 && $withFowner === 0
            ? self::classify(
                true,
                'capability_semantics_supported',
                'setpriv capability semantics match the production contract.',
            )
            : self::classify(
                false,
                'capability_semantics_unsupported',
                'setpriv capability semantics do not match the production contract.',
            );
    }
}

<?php

declare(strict_types=1);

const HOST_RUNNER_GLOBAL_LOCK_FD = 198;
const HOST_RUNNER_RUN_LOCK_FD = 199;

/** @return never */
function deploymentHostRunnerUsage(): void
{
    fwrite(STDERR, "deployment host runner usage invalid\n");
    exit(64);
}

if ($argc === 2 && preg_match('/^--internal-lock-probe-ms=([1-9][0-9]{1,3})$/D', $argv[1], $match) === 1) {
    $milliseconds = (int) $match[1];
    if ($milliseconds < 50 || $milliseconds > 5_000 || PHP_OS_FAMILY !== 'Linux') {
        deploymentHostRunnerUsage();
    }
    $links = [];
    foreach ([HOST_RUNNER_GLOBAL_LOCK_FD, HOST_RUNNER_RUN_LOCK_FD] as $descriptor) {
        $link = @readlink('/proc/self/fd/' . $descriptor);
        if (!is_string($link) || $link === '') {
            fwrite(STDERR, "deployment host runner lock boundary invalid\n");
            exit(70);
        }
        $links[] = $link;
    }
    fwrite(STDOUT, sprintf("php=%d global=%s run=%s\n", getmypid(), $links[0], $links[1]));
    fflush(STDOUT);
    usleep($milliseconds * 1_000);
    fwrite(STDOUT, "done\n");
    fflush(STDOUT);
    exit(0);
}

deploymentHostRunnerUsage();

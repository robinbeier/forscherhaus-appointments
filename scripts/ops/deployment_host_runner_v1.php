<?php

declare(strict_types=1);

use Ops\DeploymentHostRunnerCliEnvelopeV1;

const HOST_RUNNER_GLOBAL_LOCK_FD = 198;
const HOST_RUNNER_RUN_LOCK_FD = 199;

/** @return never */
function deploymentHostRunnerUsage(): void
{
    fwrite(STDERR, "deployment host runner usage invalid\n");
    exit(64);
}

/** @return never */
function deploymentHostRunnerInternalFailure(): void
{
    fwrite(STDERR, "deployment host runner rejected\n");
    exit(70);
}

function deploymentHostRunnerReadEnvelope(): string
{
    $bytes = stream_get_contents(STDIN, 65_537);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 65_536) {
        deploymentHostRunnerInternalFailure();
    }
    return $bytes;
}

if ($argc === 2 && in_array($argv[1], ['--internal-envelope-validate', '--internal-envelope-dispatch', '--internal-envelope-probe'], true)) {
    require_once __DIR__ . '/lib/DeploymentHostRunnerCliV1.php';
    try {
        $envelope = DeploymentHostRunnerCliEnvelopeV1::decode(deploymentHostRunnerReadEnvelope());
        if ($argv[1] === '--internal-envelope-validate') {
            exit(0);
        }
        $links = [];
        foreach ([HOST_RUNNER_GLOBAL_LOCK_FD, HOST_RUNNER_RUN_LOCK_FD] as $descriptor) {
            $link = @readlink('/proc/self/fd/' . $descriptor);
            if (!is_string($link) || $link === '') {
                deploymentHostRunnerInternalFailure();
            }
            $links[] = $link;
        }
        if ($argv[1] === '--internal-envelope-dispatch') {
            $application = new Ops\DeploymentHostRunnerCliApplicationV1(
                new Ops\HelperBackedHostRunnerStorage(),
            );
            $response = match ($envelope['action']) {
                'deploy' => $application->deploy($envelope),
                'post-gates' => $application->postGates($envelope),
                'recovery' => $application->recovery($envelope),
                'reconcile' => $application->reconcile($envelope),
            };
            Ops\DeploymentHostRunnerContractV1::validateResponse($response);
            fwrite(STDOUT, Ops\DeploymentHostRunnerContractV1::encodeFile($response));
            fflush(STDOUT);
            exit($response['disposition'] === 'rejected' ? $response['result_exit_code'] : 0);
        }
        $summary = [
            'action' => $envelope['action'],
            'execution_input_sha256' => $envelope['execution_input_bytes'] === null ? null : hash('sha256', $envelope['execution_input_bytes']),
            'global_lock_sha256' => hash('sha256', $links[0]),
            'intent_sha256' => $envelope['intent_sha256'],
            'report_sha256' => $envelope['report_bytes'] === null ? null : hash('sha256', $envelope['report_bytes']),
            'request_sha256' => $envelope['request_bytes'] === null ? null : hash('sha256', $envelope['request_bytes']),
            'run_id' => $envelope['run_id'],
            'run_lock_sha256' => hash('sha256', $links[1]),
        ];
        fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        fflush(STDOUT);
        exit(0);
    } catch (Throwable) {
        deploymentHostRunnerInternalFailure();
    }
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

if ($argc === 4 && in_array($argv[1], ['--action=deploy', '--action=post-gates', '--action=recovery'], true)) {
    $action = substr($argv[1], strlen('--action='));
    $expectedSecond = $action === 'post-gates' ? '--report-file=' : '--execution-input-file=';
    if (!str_starts_with($argv[2], '--request-file=') || !str_starts_with($argv[3], $expectedSecond)) {
        deploymentHostRunnerUsage();
    }
    $requestPath = substr($argv[2], strlen('--request-file='));
    $secondPath = substr($argv[3], strlen($expectedSecond));
    if ($requestPath === '' || $secondPath === '') {
        deploymentHostRunnerUsage();
    }
    pcntl_exec('/usr/bin/env', [
        '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', __DIR__ . '/libexec/deployment_host_runner_fs_v1.py',
        'supervise-cli', '/var/lib/fh-deploy-orchestrator',
        $action, $requestPath, $secondPath,
    ]);
    deploymentHostRunnerInternalFailure();
}

if (
    $argc === 4 && $argv[1] === '--action=reconcile' &&
    str_starts_with($argv[2], '--run-id=') && str_starts_with($argv[3], '--intent-sha256=')
) {
    $runId = substr($argv[2], strlen('--run-id='));
    $intentSha256 = substr($argv[3], strlen('--intent-sha256='));
    if ($runId === '' || $intentSha256 === '') {
        deploymentHostRunnerUsage();
    }
    pcntl_exec('/usr/bin/env', [
        '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
        '/usr/bin/python3', '-I', '-B', __DIR__ . '/libexec/deployment_host_runner_fs_v1.py',
        'supervise-cli', '/var/lib/fh-deploy-orchestrator',
        'reconcile', $runId, $intentSha256,
    ]);
    deploymentHostRunnerInternalFailure();
}

deploymentHostRunnerUsage();

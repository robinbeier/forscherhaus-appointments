#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ZeroSurpriseIncidentNotifier.php';

use ReleaseGate\ZeroSurpriseIncidentNotifier;

const ZERO_SURPRISE_INCIDENT_NOTIFY_EXIT_SUCCESS = 0;
const ZERO_SURPRISE_INCIDENT_NOTIFY_EXIT_FAILURE = 1;
const ZERO_SURPRISE_INCIDENT_NOTIFY_EXIT_RUNTIME = 2;

try {
    $options = parseOptions();

    if (($options['help'] ?? false) === true) {
        printUsage();
        exit(ZERO_SURPRISE_INCIDENT_NOTIFY_EXIT_SUCCESS);
    }

    $notifier = new ZeroSurpriseIncidentNotifier();
    $result = $notifier->notify($options['webhook_file'], [
        'event' => $options['event'],
        'severity' => $options['severity'],
        'release_id' => $options['release_id'],
        'reason' => $options['reason'],
        'rollback_result' => $options['rollback_result'],
        'report_path' => $options['report_path'],
        'report_root' => $options['report_root'],
        'log_path' => $options['log_path'],
        'breakglass_used' => $options['breakglass_used'],
        'ticket' => $options['ticket'],
        'timeout_seconds' => $options['timeout_seconds'],
    ]);

    if (($result['ok'] ?? false) === true) {
        fwrite(STDOUT, '[OK] Zero-surprise incident notification delivered.' . PHP_EOL);
        exit(ZERO_SURPRISE_INCIDENT_NOTIFY_EXIT_SUCCESS);
    }

    fwrite(
        STDERR,
        '[FAIL] Zero-surprise incident notification failed: ' . (string) ($result['error'] ?? 'unknown') . PHP_EOL,
    );
    exit(ZERO_SURPRISE_INCIDENT_NOTIFY_EXIT_FAILURE);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] Zero-surprise incident notification runtime error: ' . $exception->getMessage() . PHP_EOL);
    exit(ZERO_SURPRISE_INCIDENT_NOTIFY_EXIT_RUNTIME);
}

/**
 * @return array<string, mixed>
 */
function parseOptions(): array
{
    $options = getopt('', [
        'help',
        'webhook-file:',
        'event:',
        'severity:',
        'release-id:',
        'reason:',
        'rollback-result::',
        'report-path::',
        'report-root::',
        'log-path::',
        'breakglass-used::',
        'ticket::',
        'timeout-seconds::',
    ]);

    if (!is_array($options)) {
        throw new InvalidArgumentException('Failed to parse CLI options.');
    }

    if (array_key_exists('help', $options)) {
        return ['help' => true];
    }

    $timeoutSecondsRaw = getOptionalOption($options, 'timeout-seconds', 10);
    if (
        !is_int($timeoutSecondsRaw) &&
        !(is_string($timeoutSecondsRaw) && preg_match('/^\d+$/', trim($timeoutSecondsRaw)) === 1)
    ) {
        throw new InvalidArgumentException('Option --timeout-seconds must be a positive integer.');
    }

    $timeoutSeconds = (int) $timeoutSecondsRaw;
    if ($timeoutSeconds <= 0) {
        throw new InvalidArgumentException('Option --timeout-seconds must be a positive integer.');
    }

    return [
        'help' => false,
        'webhook_file' => trim(getRequiredOption($options, 'webhook-file')),
        'event' => trim(getRequiredOption($options, 'event')),
        'severity' => trim(getRequiredOption($options, 'severity')),
        'release_id' => trim(getRequiredOption($options, 'release-id')),
        'reason' => trim(getRequiredOption($options, 'reason')),
        'rollback_result' => trim((string) getOptionalOption($options, 'rollback-result', '')),
        'report_path' => trim((string) getOptionalOption($options, 'report-path', '')),
        'report_root' => trim((string) getOptionalOption($options, 'report-root', '')),
        'log_path' => trim((string) getOptionalOption($options, 'log-path', '')),
        'breakglass_used' => trim((string) getOptionalOption($options, 'breakglass-used', '0')),
        'ticket' => trim((string) getOptionalOption($options, 'ticket', '')),
        'timeout_seconds' => $timeoutSeconds,
    ];
}

/**
 * @param array<string, mixed> $options
 */
function getRequiredOption(array $options, string $name): string
{
    if (!array_key_exists($name, $options)) {
        throw new InvalidArgumentException('Missing required option --' . $name . '.');
    }

    $value = $options[$name];
    $resolved = is_array($value) ? (string) end($value) : (string) $value;
    if (trim($resolved) === '') {
        throw new InvalidArgumentException('Option --' . $name . ' must not be empty.');
    }

    return $resolved;
}

/**
 * @param array<string, mixed> $options
 */
function getOptionalOption(array $options, string $name, mixed $default): mixed
{
    if (!array_key_exists($name, $options)) {
        return $default;
    }

    $value = $options[$name];

    return is_array($value) ? end($value) : $value;
}

function printUsage(): void
{
    $lines = [
        'Zero-Surprise Incident Notify',
        '',
        'Usage:',
        '  php scripts/release-gate/zero_surprise_incident_notify.php [options]',
        '',
        'Required:',
        '  --webhook-file=PATH            INI file with incident webhook configuration',
        '  --event=VALUE                  Event name (for example zero_surprise_canary_failed)',
        '  --severity=VALUE               Severity (for example warning or critical)',
        '  --release-id=VALUE             Release identifier',
        '  --reason=VALUE                 Human-readable incident reason',
        '',
        'Optional:',
        '  --rollback-result=VALUE        Rollback result summary',
        '  --report-path=PATH             Absolute report path',
        '  --report-root=PATH             Base path used for {relative_path}',
        '  --log-path=PATH                Deploy log path',
        '  --breakglass-used=0|1          Whether breakglass was used',
        '  --ticket=VALUE                 Incident or approval ticket',
        '  --timeout-seconds=N            HTTP timeout in seconds (default: 10)',
        '  --help                         Show this help',
    ];

    fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
}


<?php

declare(strict_types=1);

use Ops\TrafficGateV1;

require_once __DIR__ . '/lib/TrafficGateV1.php';

const TRAFFIC_GATE_INVOCATION_EXIT = 64;

function trafficGateUsage(): void
{
    fwrite(
        STDOUT,
        <<<'USAGE'
        Usage:
          php scripts/ops/traffic_gate_v1.php --purpose customers-ui-smoke|deploy \
            --mode normal|no-business-traffic --window-seconds N --output-json PATH \
            [--log-dir PATH] [--catalog PATH]

        Exit codes: 0 allow/advisory, 20 traffic hard stop, 21 invalid/incomplete
        evidence, 64 invalid invocation.
        USAGE. PHP_EOL,
    );
}

/**
 * @param list<string> $argv
 * @return array<string, string>
 */
function trafficGateParseArguments(array $argv): array
{
    $options = [];
    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $argument = $argv[$index];
        if ($argument === '-h' || $argument === '--help') {
            trafficGateUsage();
            exit(0);
        }
        if (!str_starts_with($argument, '--')) {
            throw new InvalidArgumentException('invalid invocation');
        }
        if (str_contains($argument, '=')) {
            [$name, $value] = explode('=', substr($argument, 2), 2);
        } else {
            $name = substr($argument, 2);
            $index++;
            $value = $argv[$index] ?? '';
            if (str_starts_with($value, '--')) {
                throw new InvalidArgumentException('invalid invocation');
            }
        }
        if (!in_array($name, ['purpose', 'mode', 'window-seconds', 'output-json', 'log-dir', 'catalog'], true)) {
            throw new InvalidArgumentException('invalid invocation');
        }
        if ($value === '' || isset($options[$name])) {
            throw new InvalidArgumentException('invalid invocation');
        }
        $options[$name] = $value;
    }

    foreach (['purpose', 'mode', 'window-seconds', 'output-json'] as $required) {
        if (!isset($options[$required])) {
            throw new InvalidArgumentException('invalid invocation');
        }
    }
    $options['log-dir'] ??= '/var/log/apache2';
    $options['catalog'] ??= __DIR__ . '/config/traffic_gate_catalog.v1.json';

    return $options;
}

/**
 * @param array<string, mixed> $report
 */
function trafficGateWriteReport(string $path, array $report): void
{
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || is_link($path) || is_dir($path)) {
        throw new RuntimeException('traffic gate output is invalid');
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('traffic gate output directory is unavailable');
    }
    if (is_link($directory) || !is_writable($directory)) {
        throw new RuntimeException('traffic gate output directory is unsafe');
    }
    $temporary = tempnam($directory, '.traffic-gate-');
    if ($temporary === false) {
        throw new RuntimeException('traffic gate output could not be staged');
    }
    try {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !chmod($temporary, 0600)) {
            throw new RuntimeException('traffic gate output could not be written');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('traffic gate output could not be published');
        }
    } finally {
        if (file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

/** @param list<string>|null $runtimePaths */
function trafficGateProducerSha256(string $catalogPath, ?array $runtimePaths = null): string
{
    $paths = $runtimePaths ?? [__DIR__ . '/lib/TrafficGateV1.php', __FILE__, __DIR__ . '/prod_traffic_gate.sh'];
    $paths[] = $catalogPath;
    $hashes = [];
    foreach ($paths as $path) {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('traffic gate producer is incomplete');
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('traffic gate producer could not be fingerprinted');
        }
        $hashes[] = $hash;
    }

    return hash('sha256', implode("\n", $hashes));
}

function trafficGateMain(array $argv): int
{
    try {
        $options = trafficGateParseArguments($argv);
        if (
            !in_array($options['purpose'], TrafficGateV1::PURPOSES, true) ||
            !in_array($options['mode'], TrafficGateV1::MODES, true)
        ) {
            throw new InvalidArgumentException('invalid invocation');
        }
        if (preg_match('/^[1-9][0-9]*$/', $options['window-seconds']) !== 1) {
            throw new InvalidArgumentException('invalid invocation');
        }
        $windowSeconds = (int) $options['window-seconds'];
        if ($windowSeconds > 3600) {
            throw new InvalidArgumentException('invalid invocation');
        }

        $catalog = TrafficGateV1::loadCatalog($options['catalog']);
        $before = TrafficGateV1::captureLogSet($options['log-dir']);
        $windowStartEpoch = time();
        sleep($windowSeconds);
        $after = TrafficGateV1::captureLogSet($options['log-dir']);
        $windowEndEpoch = time();
        $report = TrafficGateV1::evaluate(
            $before,
            $after,
            $catalog,
            $options['purpose'],
            $options['mode'],
            $windowStartEpoch,
            $windowEndEpoch,
            trafficGateProducerSha256($options['catalog']),
        );
        trafficGateWriteReport($options['output-json'], $report);
        fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return $report['exit_code'];
    } catch (InvalidArgumentException) {
        fwrite(STDERR, "traffic_gate status=invalid reason=invocation\n");
        return TRAFFIC_GATE_INVOCATION_EXIT;
    } catch (Throwable) {
        fwrite(STDERR, "traffic_gate status=invalid reason=evidence\n");
        return 21;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(trafficGateMain($argv));
}

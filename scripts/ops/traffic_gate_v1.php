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
            [--log-dir PATH] [--catalog PATH] [--monitor-sources PATH]

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
        if (
            !in_array(
                $name,
                ['purpose', 'mode', 'window-seconds', 'output-json', 'log-dir', 'catalog', 'monitor-sources'],
                true,
            )
        ) {
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
    $options['monitor-sources'] ??= '/etc/fh/traffic-gate-monitor-sources.v1.json';

    return $options;
}

/**
 * @param array<string, mixed> $report
 */
function trafficGateWriteReport(string $path, array $report): void
{
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    trafficGateWriteOutputBytes($path, $json);
}

function trafficGatePrepareOutput(string $path): void
{
    trafficGateWriteOutputBytes($path, '');
}

function trafficGateWriteOutputBytes(string $path, string $contents): void
{
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || is_link($path) || is_dir($path)) {
        throw new RuntimeException('traffic gate output is invalid');
    }
    trafficGateAssertReplaceableOutput($path);
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
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !chmod($temporary, 0600)) {
            throw new RuntimeException('traffic gate output could not be written');
        }
        trafficGateAssertReplaceableOutput($path);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('traffic gate output could not be published');
        }
    } finally {
        if (file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

function trafficGateAssertReplaceableOutput(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    $stat = lstat($path);
    if (
        !is_array($stat) ||
        is_link($path) ||
        !is_file($path) ||
        !is_readable($path) ||
        !function_exists('posix_geteuid') ||
        (int) ($stat['uid'] ?? -1) !== posix_geteuid() ||
        (int) ($stat['nlink'] ?? 0) !== 1 ||
        (int) ($stat['size'] ?? -1) > 1_000_000
    ) {
        throw new RuntimeException('traffic gate output ownership is unsafe');
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('traffic gate output is unreadable');
    }
    if ($contents === '') {
        if ((((int) ($stat['mode'] ?? 0)) & 0777) !== 0600) {
            throw new RuntimeException('traffic gate output placeholder permissions are unsafe');
        }
        return;
    }
    try {
        $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('traffic gate output is not replaceable');
    }
    if (
        !is_array($decoded) ||
        array_is_list($decoded) ||
        !is_string($decoded['schema'] ?? null) ||
        preg_match('/^traffic_gate\.v[0-9]+$/', $decoded['schema']) !== 1 ||
        !is_string($decoded['decision'] ?? null) ||
        !is_int($decoded['exit_code'] ?? null) ||
        !is_int($decoded['window_end_epoch'] ?? null)
    ) {
        throw new RuntimeException('traffic gate output is not replaceable');
    }
}

/** @param list<string> $argv */
function trafficGateInvalidateStaleOutputs(array $argv): void
{
    $candidates = [];
    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        if ($argv[$index] === '--output-json') {
            if (isset($argv[$index + 1])) {
                $candidates[] = $argv[$index + 1];
            }
            $index++;
            continue;
        }
        if (str_starts_with($argv[$index], '--output-json=')) {
            $candidates[] = substr($argv[$index], strlen('--output-json='));
        }
    }
    foreach (array_unique($candidates) as $path) {
        if (
            !is_string($path) ||
            $path === '' ||
            $path[0] !== '/' ||
            str_contains($path, "\0") ||
            is_link($path) ||
            !is_file($path)
        ) {
            continue;
        }
        try {
            trafficGatePrepareOutput($path);
        } catch (Throwable) {
            // Invocation remains invalid; never expose path or exception details.
        }
    }
}

/** @param list<string>|null $runtimePaths */
function trafficGateProducerSha256(
    string $catalogPath,
    ?array $runtimePaths = null,
    ?string $monitorSourcesPath = null,
): string {
    $paths = $runtimePaths ?? [__DIR__ . '/lib/TrafficGateV1.php', __FILE__, __DIR__ . '/prod_traffic_gate.sh'];
    $paths[] = $catalogPath;
    if ($monitorSourcesPath !== null) {
        $paths[] = $monitorSourcesPath;
    }
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

/**
 * @param array<string, string> $options
 * @param array<string, mixed> $catalog
 * @param callable():int|null $clock
 * @param callable(int):void|null $sleeper
 * @param callable():int|null $activeProbe
 * @param callable():list<array{path:string,slot:string,device:int,inode:int,size:int,mtime:int}>|null $capture
 * @return array<string, mixed>
 */
function trafficGateCollectReport(
    array $options,
    array $catalog,
    string $producerSha256,
    ?callable $clock = null,
    ?callable $sleeper = null,
    ?callable $activeProbe = null,
    ?callable $capture = null,
): array {
    $clock ??= static fn(): int => time();
    $sleeper ??= static function (int $seconds): void {
        sleep($seconds);
    };
    $activeProbe ??= static fn(): int => TrafficGateV1::captureProductionActiveHttpConnections();
    $capture ??= static fn(): array => TrafficGateV1::captureLogSet($options['log-dir']);

    $windowStartEpoch = $clock();
    if ($activeProbe() !== 0) {
        throw new RuntimeException('traffic active-request boundary is not idle');
    }
    $before = $capture();
    $sleeper((int) $options['window-seconds']);
    $windowEndEpoch = $clock();
    if ($activeProbe() !== 0) {
        throw new RuntimeException('traffic active-request boundary is not idle');
    }
    $after = $capture();

    return TrafficGateV1::evaluate(
        $before,
        $after,
        $catalog,
        $options['purpose'],
        $options['mode'],
        $windowStartEpoch,
        $windowEndEpoch,
        $producerSha256,
    );
}

function trafficGateMain(array $argv): int
{
    try {
        $options = trafficGateParseArguments($argv);
        trafficGateInvalidateStaleOutputs($argv);
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
        trafficGatePrepareOutput($options['output-json']);

        $catalog = TrafficGateV1::loadCatalog($options['catalog'], $options['monitor-sources']);
        $producerSha256 = trafficGateProducerSha256($options['catalog'], null, $options['monitor-sources']);
        $report = trafficGateCollectReport($options, $catalog, $producerSha256);
        $finalCatalog = TrafficGateV1::loadCatalog($options['catalog'], $options['monitor-sources']);
        if ($catalog !== $finalCatalog) {
            throw new RuntimeException('traffic catalog changed during collection');
        }
        $finalProducerSha256 = trafficGateProducerSha256($options['catalog'], null, $options['monitor-sources']);
        if (!hash_equals($producerSha256, $finalProducerSha256)) {
            throw new RuntimeException('traffic producer changed during collection');
        }
        trafficGateWriteReport($options['output-json'], $report);
        fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return $report['exit_code'];
    } catch (InvalidArgumentException) {
        trafficGateInvalidateStaleOutputs($argv);
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

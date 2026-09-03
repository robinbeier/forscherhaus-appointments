<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

/** Local fail-closed process and ABI adapter for the canonical ownership engine. */
final class OwnershipPathRuleEngineClient
{
    private const PROTOCOL_VERSION = 1;

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public static function execute(array $request): array
    {
        $engine = getenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
        if (
            !is_string($engine) ||
            !str_starts_with($engine, '/') ||
            is_link($engine) ||
            !is_file($engine) ||
            realpath($engine) !== $engine
        ) {
            throw new \RuntimeException('Ownership path-rule engine unavailable');
        }
        $process = proc_open(
            ['/usr/bin/python3', '-I', '-B', $engine],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            [
                'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
                'LANG' => 'C',
                'LC_ALL' => 'C',
                'TMPDIR' => '/tmp',
                'PYTHONPATH' => '',
            ],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Ownership path-rule engine unavailable');
        }
        try {
            $encoded = json_encode($request, JSON_THROW_ON_ERROR);
            if (fwrite($pipes[0], $encoded) !== strlen($encoded)) {
                throw new \RuntimeException('Ownership path-rule engine input incomplete');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $process = null;
        } catch (\Throwable $exception) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
            throw $exception;
        }
        if ($exitCode !== 0 || !is_string($stdout) || $stderr !== '') {
            throw new \RuntimeException('Ownership path-rule engine failed');
        }
        $output = json_decode($stdout, true);
        $operation = $request['operation'] ?? null;
        if (
            !is_array($output) ||
            array_diff(array_keys($output), ['protocol_version', 'operation', 'result', 'extensions']) !== [] ||
            ($output['protocol_version'] ?? null) !== self::PROTOCOL_VERSION ||
            ($output['operation'] ?? null) !== $operation ||
            !is_array($output['result'] ?? null) ||
            (array_key_exists('extensions', $output) &&
                (!is_array($output['extensions']) ||
                    (array_is_list($output['extensions']) && $output['extensions'] !== [])))
        ) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        self::validateResult($operation, $output['result']);
        return $output;
    }

    /** @param array<string, mixed> $result */
    private static function validateResult(mixed $operation, array $result): void
    {
        $required = match ($operation) {
            'parse' => ['valid', 'rule'],
            'covers' => ['matches'],
            'overlap' => ['overlaps'],
            'validate_contract' => ['errors'],
            default => [],
        };
        if ($required === [] || array_diff($required, array_keys($result)) !== []) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        if ($operation === 'covers' && !is_bool($result['matches'])) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        if ($operation === 'overlap' && !is_bool($result['overlaps'])) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        if (
            $operation === 'validate_contract' &&
            (!is_array($result['errors']) ||
                !array_is_list($result['errors']) ||
                array_filter($result['errors'], 'is_string') !== $result['errors'])
        ) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        if (
            $operation === 'parse' &&
            (!is_bool($result['valid']) ||
                ($result['valid'] && (!is_array($result['rule']) || array_is_list($result['rule']))) ||
                (!$result['valid'] && $result['rule'] !== null))
        ) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
    }
}

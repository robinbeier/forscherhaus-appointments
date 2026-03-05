<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

final class ZeroSurpriseReport
{
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $steps = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $invariants = [];

    /**
     * @var array<string, string>|null
     */
    private ?array $failure = null;

    private readonly float $startedAt;

    private readonly string $startedAtUtc;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $releaseId,
        private readonly string $composeProject,
        private readonly array $config,
        private readonly string $outputPath,
        private readonly string $mode = 'predeploy',
    ) {
        $this->startedAt = microtime(true);
        $this->startedAtUtc = gmdate('c');
    }

    /**
     * @param array<string, mixed> $details
     */
    public function addStep(string $name, string $status, int $exitCode, float $durationMs, array $details = []): void
    {
        $this->assertStatus($status, 'step:' . $name);

        $record = [
            'name' => $name,
            'status' => $status,
            'exit_code' => $exitCode,
            'duration_ms' => round($durationMs, 2),
        ];

        if ($details !== []) {
            $record = array_merge($record, $details);
        }

        $this->steps[] = $record;
    }

    /**
     * @param array<string, mixed> $details
     */
    public function addInvariant(string $name, string $status, array $details = []): void
    {
        $this->assertStatus($status, 'invariant:' . $name);

        $record = [
            'status' => $status,
        ];

        if ($details !== []) {
            $record['details'] = $details;
        }

        $this->invariants[$name] = $record;
    }

    public function setFailure(string $message, string $exception, string $classification = 'runtime_error'): void
    {
        $this->failure = [
            'message' => $message,
            'exception' => $exception,
            'classification' => $classification,
        ];
    }

    public function determineExitCode(): int
    {
        $hasStepFailure = false;
        $hasInvariantFailure = false;
        $hasRuntimeFailure = false;
        $hasRecordedFailure = $this->failure !== null;

        foreach ($this->steps as $step) {
            $status = (string) ($step['status'] ?? '');
            if ($status !== self::STATUS_FAIL) {
                continue;
            }

            $hasStepFailure = true;

            $stepExitCode = (int) ($step['exit_code'] ?? 1);
            if ($stepExitCode === 2) {
                $hasRuntimeFailure = true;
            }
        }

        foreach ($this->invariants as $invariant) {
            if (($invariant['status'] ?? null) === self::STATUS_FAIL) {
                $hasInvariantFailure = true;
                break;
            }
        }

        if (!$hasStepFailure && !$hasInvariantFailure && !$hasRecordedFailure) {
            return 0;
        }

        if (($this->failure['classification'] ?? null) === 'runtime_error' || $hasRuntimeFailure) {
            return 2;
        }

        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $finishedAtUtc = gmdate('c');
        $durationMs = round((microtime(true) - $this->startedAt) * 1000, 2);
        $exitCode = $this->determineExitCode();

        $stepPassed = count(
            array_filter($this->steps, static fn(array $step): bool => ($step['status'] ?? null) === self::STATUS_PASS),
        );
        $stepFailed = count($this->steps) - $stepPassed;

        $invariantPassed = count(
            array_filter(
                $this->invariants,
                static fn(array $invariant): bool => ($invariant['status'] ?? null) === self::STATUS_PASS,
            ),
        );
        $invariantFailed = count($this->invariants) - $invariantPassed;

        $report = [
            'meta' => [
                'release_id' => $this->releaseId,
                'mode' => $this->mode,
                'compose_project' => $this->composeProject,
                'started_at_utc' => $this->startedAtUtc,
                'finished_at_utc' => $finishedAtUtc,
                'duration_ms' => $durationMs,
            ],
            'config' => $this->config,
            'steps' => $this->steps,
            'invariants' => $this->invariants,
            'summary' => [
                'passed_steps' => $stepPassed,
                'failed_steps' => $stepFailed,
                'passed_invariants' => $invariantPassed,
                'failed_invariants' => $invariantFailed,
                'exit_code' => $exitCode,
            ],
        ];

        if ($this->failure !== null) {
            $report['failure'] = $this->failure;
        }

        return $report;
    }

    public function write(): string
    {
        $report = $this->toArray();
        $directory = dirname($this->outputPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create report directory: ' . $directory);
        }

        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode zero-surprise report as JSON.');
        }

        if (file_put_contents($this->outputPath, $encoded . PHP_EOL) === false) {
            throw new RuntimeException('Could not write zero-surprise report to: ' . $this->outputPath);
        }

        return $this->outputPath;
    }

    private function assertStatus(string $status, string $context): void
    {
        if (in_array($status, [self::STATUS_PASS, self::STATUS_FAIL], true)) {
            return;
        }

        throw new RuntimeException(sprintf('%s has unsupported status "%s" (expected pass|fail).', $context, $status));
    }
}

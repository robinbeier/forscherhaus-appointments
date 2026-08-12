<?php

declare(strict_types=1);

namespace ReleaseGate;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class ZeroSurpriseReportValidator
{
    /**
     * @var string[]
     */
    public const REQUIRED_INVARIANTS = ['unexpected_5xx', 'overbooking', 'fill_rate_math', 'pdf_exports'];

    /**
     * @var string[]
     */
    private const REQUIRED_PREDEPLOY_CLEANUP_STEPS = ['compose_cleanup', 'image_cleanup'];

    /**
     * @return array{ok:bool,errors:array<int, string>,normalized:array<string, mixed>}
     */
    public function validateFile(
        string $reportPath,
        string $expectedReleaseId,
        string $expectedMode,
        int $maxAgeMinutes,
        ?DateTimeImmutable $nowUtc = null,
    ): array {
        $errors = [];
        $normalized = [
            'report_path' => $reportPath,
            'expected_release_id' => $expectedReleaseId,
            'expected_mode' => $expectedMode,
            'max_age_minutes' => $maxAgeMinutes,
        ];

        $now = $nowUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $normalized['validated_at_utc'] = $now->format('c');

        if ($maxAgeMinutes <= 0) {
            $errors[] = 'maxAgeMinutes must be a positive integer.';
        }

        if (trim($reportPath) === '') {
            $errors[] = 'Report path must not be empty.';

            return $this->buildResult($errors, $normalized);
        }

        if (!is_file($reportPath)) {
            $errors[] = 'Report file not found: ' . $reportPath;

            return $this->buildResult($errors, $normalized);
        }

        if (!is_readable($reportPath)) {
            $errors[] = 'Report file is not readable: ' . $reportPath;

            return $this->buildResult($errors, $normalized);
        }

        $raw = file_get_contents($reportPath);
        if (!is_string($raw)) {
            $errors[] = 'Failed to read report file: ' . $reportPath;

            return $this->buildResult($errors, $normalized);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $errors[] = 'Report JSON is invalid: ' . $exception->getMessage();

            return $this->buildResult($errors, $normalized);
        }

        if (!is_array($decoded)) {
            $errors[] = 'Report JSON root must be an object.';

            return $this->buildResult($errors, $normalized);
        }

        $summary = $decoded['summary'] ?? null;
        $summaryExitCode = null;
        if (!is_array($summary)) {
            $errors[] = 'Report misses object "summary".';
        } else {
            $summaryExitCode = self::toIntOrNull($summary['exit_code'] ?? null);
            if ($summaryExitCode === null) {
                $errors[] = 'summary.exit_code must be an integer.';
            } elseif ($summaryExitCode !== 0) {
                $errors[] = 'summary.exit_code must be 0, got ' . $summaryExitCode . '.';
            }
        }

        if ($summaryExitCode !== null) {
            $normalized['summary_exit_code'] = $summaryExitCode;
        }

        $meta = $decoded['meta'] ?? null;
        $releaseId = '';
        $mode = '';
        $finishedAtUtcRaw = '';

        if (!is_array($meta)) {
            $errors[] = 'Report misses object "meta".';
        } else {
            $releaseId = trim((string) ($meta['release_id'] ?? ''));
            $mode = trim((string) ($meta['mode'] ?? ''));
            $finishedAtUtcRaw = trim((string) ($meta['finished_at_utc'] ?? ''));

            if ($releaseId === '') {
                $errors[] = 'meta.release_id must be a non-empty string.';
            } elseif ($releaseId !== $expectedReleaseId) {
                $errors[] = sprintf(
                    'meta.release_id mismatch: expected "%s", got "%s".',
                    $expectedReleaseId,
                    $releaseId,
                );
            }

            if ($mode === '') {
                $errors[] = 'meta.mode must be a non-empty string.';
            } elseif ($mode !== $expectedMode) {
                $errors[] = sprintf('meta.mode mismatch: expected "%s", got "%s".', $expectedMode, $mode);
            }

            if ($finishedAtUtcRaw === '') {
                $errors[] = 'meta.finished_at_utc must be a non-empty datetime string.';
            } else {
                try {
                    $finishedAtUtc = new DateTimeImmutable($finishedAtUtcRaw);
                    $finishedAtUtc = $finishedAtUtc->setTimezone(new DateTimeZone('UTC'));
                    $ageSeconds = $now->getTimestamp() - $finishedAtUtc->getTimestamp();

                    $normalized['finished_at_utc'] = $finishedAtUtc->format('c');
                    $normalized['age_seconds'] = $ageSeconds;

                    if ($ageSeconds < 0) {
                        $errors[] = 'meta.finished_at_utc must not be in the future.';
                    } elseif ($maxAgeMinutes > 0 && $ageSeconds > $maxAgeMinutes * 60) {
                        $errors[] = sprintf(
                            'Report is too old: age %d seconds exceeds max %d minutes.',
                            $ageSeconds,
                            $maxAgeMinutes,
                        );
                    }
                } catch (\Exception $exception) {
                    $errors[] = 'meta.finished_at_utc is invalid: ' . $exception->getMessage();
                }
            }
        }

        if ($releaseId !== '') {
            $normalized['release_id'] = $releaseId;
        }
        if ($mode !== '') {
            $normalized['mode'] = $mode;
        }

        if ($expectedMode === 'predeploy') {
            $this->validatePredeployCleanupSteps($decoded['steps'] ?? null, $errors, $normalized);
        }

        $invariants = $decoded['invariants'] ?? null;
        if (!is_array($invariants)) {
            $errors[] = 'Report misses object "invariants".';
        } else {
            foreach (self::REQUIRED_INVARIANTS as $requiredInvariant) {
                if (!array_key_exists($requiredInvariant, $invariants)) {
                    $errors[] = 'Required invariant missing: ' . $requiredInvariant . '.';
                }
            }

            $invariantStatuses = [];
            foreach ($invariants as $name => $invariantPayload) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }

                if (!is_array($invariantPayload)) {
                    $errors[] = 'Invariant payload must be an object: ' . $name . '.';
                    continue;
                }

                $status = trim((string) ($invariantPayload['status'] ?? ''));
                $invariantStatuses[$name] = $status;

                if ($status !== 'pass') {
                    $errors[] = sprintf(
                        'Invariant "%s" must be pass, got "%s".',
                        $name,
                        $status !== '' ? $status : 'missing',
                    );
                }
            }

            $normalized['invariant_statuses'] = $invariantStatuses;
        }

        return $this->buildResult($errors, $normalized);
    }

    /**
     * @param array<int, string> $errors
     * @param array<string, mixed> $normalized
     * @return array{ok:bool,errors:array<int, string>,normalized:array<string, mixed>}
     */
    private function buildResult(array $errors, array $normalized): array
    {
        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'normalized' => $normalized,
        ];
    }

    /**
     * @param array<int, string> $errors
     * @param array<string, mixed> $normalized
     */
    private function validatePredeployCleanupSteps(mixed $rawSteps, array &$errors, array &$normalized): void
    {
        if (!is_array($rawSteps) || !array_is_list($rawSteps)) {
            $errors[] = 'Predeploy report misses list "steps".';

            return;
        }

        $steps = [];
        foreach ($rawSteps as $step) {
            if (!is_array($step)) {
                $errors[] = 'Predeploy step must be an object.';
                continue;
            }

            $name = $step['name'] ?? null;
            if (!is_string($name) || $name === '') {
                $errors[] = 'Predeploy step name must be a non-empty string.';
                continue;
            }

            if (isset($steps[$name])) {
                $errors[] = 'Predeploy step appears more than once: ' . $name . '.';
                continue;
            }

            $steps[$name] = $step;
        }

        foreach (self::REQUIRED_PREDEPLOY_CLEANUP_STEPS as $requiredStep) {
            if (!isset($steps[$requiredStep])) {
                $errors[] = 'Required predeploy cleanup step missing: ' . $requiredStep . '.';
                continue;
            }

            $status = $steps[$requiredStep]['status'] ?? null;
            $exitCode = self::toIntOrNull($steps[$requiredStep]['exit_code'] ?? null);
            if ($status !== 'pass' || $exitCode !== 0) {
                $errors[] = 'Predeploy cleanup step must pass with exit 0: ' . $requiredStep . '.';
            }
        }

        if (isset($steps['compose_cleanup']) && ($steps['compose_cleanup']['timed_out'] ?? null) !== false) {
            $errors[] = 'compose_cleanup.timed_out must be false.';
        }

        if (!isset($steps['image_cleanup'])) {
            return;
        }

        $details = $steps['image_cleanup']['details'] ?? null;
        if (!is_array($details)) {
            $errors[] = 'image_cleanup.details must be an object.';

            return;
        }

        $metrics = [];
        foreach (
            [
                'candidate_count',
                'deleted_count',
                'candidate_virtual_bytes',
                'free_bytes_before',
                'free_bytes_after',
                'freed_bytes',
            ]
            as $field
        ) {
            $value = self::toIntOrNull($details[$field] ?? null);
            if ($value === null || $value < 0) {
                $errors[] = 'image_cleanup.details.' . $field . ' must be a non-negative integer.';
                continue;
            }

            $metrics[$field] = $value;
        }

        if (!array_key_exists('reason', $details) || $details['reason'] !== null) {
            $errors[] = 'image_cleanup.details.reason must be null for a passing cleanup.';
        }

        if (isset($metrics['candidate_count'], $metrics['deleted_count'])) {
            if ($metrics['candidate_count'] !== $metrics['deleted_count']) {
                $errors[] = 'image_cleanup must delete every exact candidate.';
            }
        }

        if (isset($metrics['free_bytes_before'], $metrics['free_bytes_after'], $metrics['freed_bytes'])) {
            $expectedFreed = max(0, $metrics['free_bytes_after'] - $metrics['free_bytes_before']);
            if ($metrics['freed_bytes'] !== $expectedFreed) {
                $errors[] = 'image_cleanup.details.freed_bytes does not match the observed free-space delta.';
            }
        }

        if ($metrics !== []) {
            $normalized['image_cleanup'] = $metrics;
        }
    }

    private static function toIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^-?\d+$/', $trimmed) !== 1) {
            return null;
        }

        return (int) $trimmed;
    }
}

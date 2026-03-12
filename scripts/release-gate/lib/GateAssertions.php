<?php

declare(strict_types=1);

namespace ReleaseGate;

use JsonException;
use RuntimeException;

final class GateAssertionException extends RuntimeException {}

final class GateAssertions
{
    private function __construct() {}

    /**
     * @param int|int[] $expected
     */
    public static function assertStatus(int $actual, int|array $expected, string $context): void
    {
        $expectedValues = is_array($expected) ? array_values($expected) : [$expected];

        if (!in_array($actual, $expectedValues, true)) {
            throw new GateAssertionException(
                sprintf(
                    '%s failed: expected HTTP %s, got %d.',
                    $context,
                    implode(' or ', array_map(static fn(int $value): string => (string) $value, $expectedValues)),
                    $actual,
                ),
            );
        }
    }

    /**
     * @return mixed
     */
    public static function decodeJson(string $body, string $context): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GateAssertionException($context . ' returned invalid JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function assertLoginPayload(mixed $payload): void
    {
        if (!is_array($payload)) {
            throw new GateAssertionException('Login response payload must be an object.');
        }

        if (!array_key_exists('success', $payload)) {
            throw new GateAssertionException('Login response payload misses "success".');
        }

        if (!self::toBool($payload['success'], 'login.success')) {
            throw new GateAssertionException('Login was not successful.');
        }
    }

    /**
     * @return array{providers:int,booked_total:int}
     */
    public static function assertMetricsPayload(mixed $payload, bool $requireNonEmpty): array
    {
        if (!is_array($payload)) {
            throw new GateAssertionException('Dashboard metrics payload must be an array.');
        }

        if ($requireNonEmpty && count($payload) === 0) {
            throw new GateAssertionException('Dashboard metrics payload is empty but non-empty metrics were required.');
        }

        $bookedTotal = 0;

        foreach ($payload as $index => $row) {
            $context = 'metrics[' . $index . ']';

            if (!is_array($row)) {
                throw new GateAssertionException($context . ' must be an object.');
            }

            $requiredKeys = [
                'provider_id',
                'provider_name',
                'target',
                'booked',
                'open',
                'fill_rate',
                'needs_attention',
                'has_plan',
                'slots_planned',
                'slots_required',
                'has_capacity_gap',
                'after_15_slots',
                'total_offered_slots',
                'after_15_ratio',
                'after_15_percent',
                'after_15_target_met',
                'after_15_evaluable',
            ];

            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $row)) {
                    throw new GateAssertionException($context . ' misses key "' . $key . '".');
                }
            }

            $providerId = self::toInt($row['provider_id'], $context . '.provider_id');
            if ($providerId <= 0) {
                throw new GateAssertionException($context . '.provider_id must be a positive integer.');
            }

            $providerName = trim((string) ($row['provider_name'] ?? ''));
            if ($providerName === '') {
                throw new GateAssertionException($context . '.provider_name must not be empty.');
            }

            $target = self::toNonNegativeInt($row['target'], $context . '.target');
            $booked = self::toNonNegativeInt($row['booked'], $context . '.booked');
            $open = self::toNonNegativeInt($row['open'], $context . '.open');
            $fillRate = self::toNonNegativeFloat($row['fill_rate'], $context . '.fill_rate');
            $slotsPlanned = self::toNonNegativeInt($row['slots_planned'], $context . '.slots_planned');
            $slotsRequired = self::toNonNegativeInt($row['slots_required'], $context . '.slots_required');
            $hasCapacityGap = self::toBool($row['has_capacity_gap'], $context . '.has_capacity_gap');
            $after15Evaluable = self::toBool($row['after_15_evaluable'], $context . '.after_15_evaluable');

            $expectedOpen = $target > 0 ? max($target - $booked, 0) : 0;
            if ($open !== $expectedOpen) {
                throw new GateAssertionException(
                    sprintf('%s.open mismatch: expected %d, got %d.', $context, $expectedOpen, $open),
                );
            }

            $expectedFillRate = $target > 0 ? $booked / $target : 0.0;
            if (abs($fillRate - $expectedFillRate) > 0.0001) {
                throw new GateAssertionException(
                    sprintf('%s.fill_rate mismatch: expected %.6f, got %.6f.', $context, $expectedFillRate, $fillRate),
                );
            }

            $expectedGap = $slotsRequired > 0 && $slotsPlanned < $slotsRequired;
            if ($hasCapacityGap !== $expectedGap) {
                throw new GateAssertionException(
                    sprintf(
                        '%s.has_capacity_gap mismatch: expected %s, got %s.',
                        $context,
                        $expectedGap ? 'true' : 'false',
                        $hasCapacityGap ? 'true' : 'false',
                    ),
                );
            }

            self::assertAfter15Metrics($row, $context, $after15Evaluable);

            $bookedTotal += $booked;
        }

        return [
            'providers' => count($payload),
            'booked_total' => $bookedTotal,
        ];
    }

    private static function assertAfter15Metrics(array $row, string $context, bool $after15Evaluable): void
    {
        $after15Slots = self::normalizeOptionalNonNegativeInt($row['after_15_slots'], $context . '.after_15_slots');
        $totalOfferedSlots = self::normalizeOptionalNonNegativeInt(
            $row['total_offered_slots'],
            $context . '.total_offered_slots',
        );
        $after15Ratio = self::normalizeOptionalNonNegativeFloat($row['after_15_ratio'], $context . '.after_15_ratio');
        $after15Percent = self::normalizeOptionalNonNegativeFloat(
            $row['after_15_percent'],
            $context . '.after_15_percent',
        );
        $after15TargetMet = self::normalizeOptionalBool($row['after_15_target_met'], $context . '.after_15_target_met');

        if ($after15Evaluable) {
            if (
                $after15Slots === null ||
                $totalOfferedSlots === null ||
                $after15Ratio === null ||
                $after15Percent === null
            ) {
                throw new GateAssertionException(
                    $context . ' evaluable after-15 metrics must include slot counts, ratio, and percent.',
                );
            }

            if ($after15TargetMet === null) {
                throw new GateAssertionException(
                    $context . ' evaluable after-15 metrics must include after_15_target_met.',
                );
            }

            if ($totalOfferedSlots <= 0) {
                throw new GateAssertionException(
                    $context . '.total_offered_slots must be > 0 when after_15_evaluable is true.',
                );
            }

            if ($after15Slots > $totalOfferedSlots) {
                throw new GateAssertionException(
                    sprintf(
                        '%s.after_15_slots mismatch: %d cannot exceed total_offered_slots %d.',
                        $context,
                        $after15Slots,
                        $totalOfferedSlots,
                    ),
                );
            }

            $expectedRatio = $after15Slots / $totalOfferedSlots;
            if (abs($after15Ratio - $expectedRatio) > 0.0001) {
                throw new GateAssertionException(
                    sprintf(
                        '%s.after_15_ratio mismatch: expected %.6f, got %.6f.',
                        $context,
                        $expectedRatio,
                        $after15Ratio,
                    ),
                );
            }

            $expectedPercent = round($expectedRatio * 100, 1);
            if (abs($after15Percent - $expectedPercent) > 0.0001) {
                throw new GateAssertionException(
                    sprintf(
                        '%s.after_15_percent mismatch: expected %.1f, got %.6f.',
                        $context,
                        $expectedPercent,
                        $after15Percent,
                    ),
                );
            }

            $expectedTargetMet = $expectedRatio >= 0.3;
            if ($after15TargetMet !== $expectedTargetMet) {
                throw new GateAssertionException(
                    sprintf(
                        '%s.after_15_target_met mismatch: expected %s, got %s.',
                        $context,
                        $expectedTargetMet ? 'true' : 'false',
                        $after15TargetMet ? 'true' : 'false',
                    ),
                );
            }

            return;
        }

        if ($after15Ratio !== null || $after15Percent !== null || $after15TargetMet !== null) {
            throw new GateAssertionException(
                $context . ' non-evaluable after-15 metrics must leave ratio, percent, and target state null.',
            );
        }

        if ($after15Slots === null && $totalOfferedSlots === null) {
            return;
        }

        if ($after15Slots === null || $totalOfferedSlots === null) {
            throw new GateAssertionException(
                $context . ' non-evaluable after-15 metrics must provide both slot counts together or neither.',
            );
        }

        if ($after15Slots !== 0 || $totalOfferedSlots !== 0) {
            throw new GateAssertionException(
                sprintf(
                    '%s non-evaluable after-15 metrics must use null/null or 0/0 slot counts, got %d/%d.',
                    $context,
                    $after15Slots,
                    $totalOfferedSlots,
                ),
            );
        }
    }

    /**
     * @return array{slots:int,total:int}
     */
    public static function assertHeatmapPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new GateAssertionException('Dashboard heatmap payload must be an object.');
        }

        if (!isset($payload['meta']) || !is_array($payload['meta'])) {
            throw new GateAssertionException('Dashboard heatmap payload misses object "meta".');
        }

        if (!isset($payload['slots']) || !is_array($payload['slots'])) {
            throw new GateAssertionException('Dashboard heatmap payload misses array "slots".');
        }

        $meta = $payload['meta'];
        $requiredMeta = ['startDate', 'endDate', 'intervalMinutes', 'timezone', 'total', 'percentile95', 'rangeLabel'];
        foreach ($requiredMeta as $key) {
            if (!array_key_exists($key, $meta)) {
                throw new GateAssertionException('Dashboard heatmap meta misses "' . $key . '".');
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $meta['startDate'])) {
            throw new GateAssertionException('Dashboard heatmap meta.startDate must have format YYYY-MM-DD.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $meta['endDate'])) {
            throw new GateAssertionException('Dashboard heatmap meta.endDate must have format YYYY-MM-DD.');
        }

        $intervalMinutes = self::toNonNegativeInt($meta['intervalMinutes'], 'heatmap.meta.intervalMinutes');
        if ($intervalMinutes <= 0) {
            throw new GateAssertionException('Dashboard heatmap meta.intervalMinutes must be > 0.');
        }

        $total = self::toNonNegativeInt($meta['total'], 'heatmap.meta.total');
        self::toNonNegativeFloat($meta['percentile95'], 'heatmap.meta.percentile95');

        if (trim((string) ($meta['timezone'] ?? '')) === '') {
            throw new GateAssertionException('Dashboard heatmap meta.timezone must not be empty.');
        }

        if (trim((string) ($meta['rangeLabel'] ?? '')) === '') {
            throw new GateAssertionException('Dashboard heatmap meta.rangeLabel must not be empty.');
        }

        $sum = 0;

        foreach ($payload['slots'] as $index => $slot) {
            $context = 'heatmap.slots[' . $index . ']';

            if (!is_array($slot)) {
                throw new GateAssertionException($context . ' must be an object.');
            }

            foreach (['weekday', 'time', 'count', 'percent'] as $key) {
                if (!array_key_exists($key, $slot)) {
                    throw new GateAssertionException($context . ' misses key "' . $key . '".');
                }
            }

            $weekday = self::toInt($slot['weekday'], $context . '.weekday');
            if ($weekday < 1 || $weekday > 5) {
                throw new GateAssertionException($context . '.weekday must be in range 1..5.');
            }

            $time = (string) $slot['time'];
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new GateAssertionException($context . '.time must match HH:MM.');
            }

            $count = self::toNonNegativeInt($slot['count'], $context . '.count');
            $percent = self::toNonNegativeFloat($slot['percent'], $context . '.percent');

            if ($percent > 100.0) {
                throw new GateAssertionException($context . '.percent must be <= 100.');
            }

            $sum += $count;

            if ($total > 0) {
                $expectedPercent = round(($count / $total) * 100, 1);

                if (abs($percent - $expectedPercent) > 0.11) {
                    throw new GateAssertionException(
                        sprintf('%s.percent mismatch: expected %.1f, got %.1f.', $context, $expectedPercent, $percent),
                    );
                }
            } elseif (abs($percent) > 0.1) {
                throw new GateAssertionException($context . '.percent must be 0 when total is 0.');
            }
        }

        if ($sum !== $total) {
            throw new GateAssertionException(
                sprintf('Dashboard heatmap total mismatch: meta.total is %d but slots sum is %d.', $total, $sum),
            );
        }

        return [
            'slots' => count($payload['slots']),
            'total' => $total,
        ];
    }

    public static function assertPdfBinary(string $body, ?string $contentType, int $minBytes = 1024): void
    {
        self::assertContentTypeIncludes($contentType, ['application/pdf'], 'PDF export');

        $bytes = strlen($body);
        if ($bytes < $minBytes) {
            throw new GateAssertionException(
                sprintf('PDF export body is too small: expected >= %d bytes, got %d bytes.', $minBytes, $bytes),
            );
        }

        if (!str_starts_with($body, '%PDF-')) {
            throw new GateAssertionException('PDF export body does not start with %PDF-.');
        }
    }

    public static function assertZipBinary(string $body, ?string $contentType, int $minBytes = 22): void
    {
        self::assertContentTypeMatches(
            $contentType,
            [
                'application/zip',
                'application/x-zip',
                'application/x-zip-compressed',
                'multipart/x-zip',
                'application/octet-stream',
            ],
            'Teacher ZIP export',
        );

        $bytes = strlen($body);
        if ($bytes < $minBytes) {
            throw new GateAssertionException(
                sprintf('ZIP export body is too small: expected >= %d bytes, got %d bytes.', $minBytes, $bytes),
            );
        }

        $prefix = substr($body, 0, 4);
        $valid = $prefix === "PK\x03\x04" || $prefix === "PK\x05\x06" || $prefix === "PK\x07\x08";

        if (!$valid) {
            throw new GateAssertionException('ZIP export body does not start with a valid PK signature.');
        }
    }

    /**
     * @param string[] $fragments
     */
    private static function assertContentTypeIncludes(?string $contentType, array $fragments, string $context): void
    {
        if ($contentType === null || trim($contentType) === '') {
            throw new GateAssertionException($context . ' is missing a Content-Type header.');
        }

        $normalized = strtolower($contentType);

        foreach ($fragments as $fragment) {
            if (str_contains($normalized, strtolower($fragment))) {
                return;
            }
        }

        throw new GateAssertionException(
            sprintf(
                '%s returned unsupported Content-Type "%s". Expected one containing: %s.',
                $context,
                $contentType,
                implode(', ', $fragments),
            ),
        );
    }

    /**
     * @param string[] $allowedTypes
     */
    private static function assertContentTypeMatches(?string $contentType, array $allowedTypes, string $context): void
    {
        if ($contentType === null || trim($contentType) === '') {
            throw new GateAssertionException($context . ' is missing a Content-Type header.');
        }

        $type = strtolower(trim(explode(';', $contentType, 2)[0] ?? ''));
        if ($type === '') {
            throw new GateAssertionException($context . ' returned an empty Content-Type value.');
        }

        $allowed = array_map(static fn(string $value): string => strtolower(trim($value)), $allowedTypes);
        if (in_array($type, $allowed, true)) {
            return;
        }

        throw new GateAssertionException(
            sprintf(
                '%s returned unsupported Content-Type "%s". Expected one of: %s.',
                $context,
                $contentType,
                implode(', ', $allowedTypes),
            ),
        );
    }

    private static function toInt(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            throw new GateAssertionException($context . ' must be an integer.');
        }

        $normalized = trim($value);
        if ($normalized === '' || !preg_match('/^-?\d+$/', $normalized)) {
            throw new GateAssertionException($context . ' must be an integer.');
        }

        return (int) $normalized;
    }

    private static function toNonNegativeInt(mixed $value, string $context): int
    {
        $number = self::toInt($value, $context);

        if ($number < 0) {
            throw new GateAssertionException($context . ' must be >= 0.');
        }

        return $number;
    }

    private static function toNonNegativeFloat(mixed $value, string $context): float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new GateAssertionException($context . ' must be numeric.');
        }

        if (!is_numeric((string) $value)) {
            throw new GateAssertionException($context . ' must be numeric.');
        }

        $number = (float) $value;

        if ($number < 0) {
            throw new GateAssertionException($context . ' must be >= 0.');
        }

        return $number;
    }

    private static function toBool(mixed $value, string $context): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((float) $value) !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if ($normalized === 'true' || $normalized === '1') {
                return true;
            }

            if ($normalized === 'false' || $normalized === '0') {
                return false;
            }
        }

        throw new GateAssertionException($context . ' must be a boolean-like value.');
    }

    private static function normalizeOptionalNonNegativeInt(mixed $value, string $context): ?int
    {
        if ($value === null) {
            return null;
        }

        return self::toNonNegativeInt($value, $context);
    }

    private static function normalizeOptionalNonNegativeFloat(mixed $value, string $context): ?float
    {
        if ($value === null) {
            return null;
        }

        return self::toNonNegativeFloat($value, $context);
    }

    private static function normalizeOptionalBool(mixed $value, string $context): ?bool
    {
        if ($value === null) {
            return null;
        }

        return self::toBool($value, $context);
    }
}

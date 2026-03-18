<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateAssertions.php';

class DashboardReleaseGateAssertionsTest extends TestCase
{
    public function testAssertMetricsPayloadAcceptsValidRows(): void
    {
        $payload = [
            [
                'provider_id' => 11,
                'provider_name' => 'Ada Lovelace',
                'target' => 20,
                'booked' => 10,
                'open' => 10,
                'fill_rate' => 0.5,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 18,
                'slots_required' => 20,
                'has_capacity_gap' => true,
                'after_15_slots' => 4,
                'total_offered_slots' => 19,
                'after_15_ratio' => 4 / 20,
                'after_15_percent' => 20.0,
                'after_15_target_met' => false,
                'after_15_evaluable' => true,
            ],
        ];

        $summary = GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), true);

        $this->assertSame(1, $summary['providers']);
        $this->assertSame(10, $summary['booked_total']);
    }

    public function testAssertMetricsPayloadAcceptsStructuredDashboardResponse(): void
    {
        $payload = [
            'metrics' => [
                [
                    'provider_id' => 11,
                    'provider_name' => 'Ada Lovelace',
                    'target' => 20,
                    'booked' => 10,
                    'open' => 10,
                    'fill_rate' => 0.5,
                    'needs_attention' => true,
                    'has_plan' => true,
                    'slots_planned' => 18,
                    'slots_required' => 20,
                    'has_capacity_gap' => true,
                    'after_15_slots' => 4,
                    'total_offered_slots' => 19,
                    'after_15_ratio' => 4 / 20,
                    'after_15_percent' => 20.0,
                    'after_15_target_met' => false,
                    'after_15_evaluable' => true,
                ],
            ],
            'summary' => [
                'target_total' => 20,
                'booked_total' => 10,
                'open_total' => 10,
                'fill_rate' => 0.5,
                'threshold' => 0.9,
            ],
        ];

        $summary = GateAssertions::assertMetricsPayload($payload, true);

        $this->assertSame(1, $summary['providers']);
        $this->assertSame(10, $summary['booked_total']);
    }

    public function testAssertMetricsPayloadUsesAggregateOpenTotalFromSummaryProgress(): void
    {
        $payload = [
            [
                'provider_id' => 11,
                'provider_name' => 'Ada Lovelace',
                'target' => 10,
                'booked' => 12,
                'open' => 0,
                'fill_rate' => 1.2,
                'needs_attention' => false,
                'has_plan' => true,
                'slots_planned' => 10,
                'slots_required' => 10,
                'has_capacity_gap' => false,
                'after_15_slots' => 4,
                'total_offered_slots' => 10,
                'after_15_ratio' => 0.4,
                'after_15_percent' => 40.0,
                'after_15_target_met' => true,
                'after_15_evaluable' => true,
            ],
            [
                'provider_id' => 12,
                'provider_name' => 'Alan Turing',
                'target' => 10,
                'booked' => 5,
                'open' => 5,
                'fill_rate' => 0.5,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 8,
                'slots_required' => 10,
                'has_capacity_gap' => true,
                'after_15_slots' => 2,
                'total_offered_slots' => 10,
                'after_15_ratio' => 0.2,
                'after_15_percent' => 20.0,
                'after_15_target_met' => false,
                'after_15_evaluable' => true,
            ],
        ];

        $summary = GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), true);

        $this->assertSame(2, $summary['providers']);
        $this->assertSame(17, $summary['booked_total']);
    }

    public function testAssertMetricsPayloadRejectsOpenMismatch(): void
    {
        $payload = [
            [
                'provider_id' => 22,
                'provider_name' => 'Alan Turing',
                'target' => 20,
                'booked' => 12,
                'open' => 7,
                'fill_rate' => 0.6,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 20,
                'slots_required' => 20,
                'has_capacity_gap' => false,
                'after_15_slots' => 4,
                'total_offered_slots' => 19,
                'after_15_ratio' => 4 / 20,
                'after_15_percent' => 20.0,
                'after_15_target_met' => false,
                'after_15_evaluable' => true,
            ],
        ];

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('open mismatch');

        GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), false);
    }

    public function testAssertMetricsPayloadAcceptsFallbackAfter15RatiosBasedOnPlannedSlots(): void
    {
        $payload = [
            [
                'provider_id' => 23,
                'provider_name' => 'Alan Turing',
                'target' => 8,
                'booked' => 4,
                'open' => 4,
                'fill_rate' => 0.5,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 5,
                'slots_required' => 8,
                'has_capacity_gap' => true,
                'is_target_fallback' => true,
                'after_15_slots' => 2,
                'total_offered_slots' => 5,
                'after_15_ratio' => 0.4,
                'after_15_percent' => 40.0,
                'after_15_target_met' => true,
                'after_15_evaluable' => true,
            ],
        ];

        $summary = GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), true);

        $this->assertSame(1, $summary['providers']);
        $this->assertSame(4, $summary['booked_total']);
    }

    public function testAssertMetricsPayloadAcceptsFallbackAfter15RatiosBasedOnRequiredSlots(): void
    {
        $payload = [
            [
                'provider_id' => 24,
                'provider_name' => 'Donald Knuth',
                'target' => 8,
                'booked' => 4,
                'open' => 4,
                'fill_rate' => 0.5,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 5,
                'slots_required' => 8,
                'has_capacity_gap' => true,
                'is_target_fallback' => true,
                'after_15_slots' => 2,
                'total_offered_slots' => 5,
                'after_15_ratio' => 2 / 8,
                'after_15_percent' => 25.0,
                'after_15_target_met' => false,
                'after_15_evaluable' => true,
            ],
        ];

        $summary = GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), true);

        $this->assertSame(1, $summary['providers']);
        $this->assertSame(4, $summary['booked_total']);
    }

    public function testAssertMetricsPayloadRejectsCapacityGapMismatch(): void
    {
        $payload = [
            [
                'provider_id' => 33,
                'provider_name' => 'Grace Hopper',
                'target' => 20,
                'booked' => 12,
                'open' => 8,
                'fill_rate' => 0.6,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 12,
                'slots_required' => 20,
                'has_capacity_gap' => false,
                'after_15_slots' => 4,
                'total_offered_slots' => 19,
                'after_15_ratio' => 4 / 20,
                'after_15_percent' => 20.0,
                'after_15_target_met' => false,
                'after_15_evaluable' => true,
            ],
        ];

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('has_capacity_gap mismatch');

        GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), false);
    }

    public function testAssertMetricsPayloadRejectsNonIntegerProviderId(): void
    {
        $payload = [
            [
                'provider_id' => 33.5,
                'provider_name' => 'Grace Hopper',
                'target' => 20,
                'booked' => 12,
                'open' => 8,
                'fill_rate' => 0.6,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 12,
                'slots_required' => 20,
                'has_capacity_gap' => true,
                'after_15_slots' => 4,
                'total_offered_slots' => 19,
                'after_15_ratio' => 4 / 20,
                'after_15_percent' => 20.0,
                'after_15_target_met' => false,
                'after_15_evaluable' => true,
            ],
        ];

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('provider_id must be an integer');

        GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), false);
    }

    public function testAssertMetricsPayloadRejectsAfter15PercentMismatch(): void
    {
        $payload = [
            [
                'provider_id' => 44,
                'provider_name' => 'Katherine Johnson',
                'target' => 20,
                'booked' => 12,
                'open' => 8,
                'fill_rate' => 0.6,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 20,
                'slots_required' => 20,
                'has_capacity_gap' => false,
                'after_15_slots' => 4,
                'total_offered_slots' => 19,
                'after_15_ratio' => 4 / 20,
                'after_15_percent' => 22.0,
                'after_15_target_met' => false,
                'after_15_evaluable' => true,
            ],
        ];

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('after_15_percent mismatch');

        GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), false);
    }

    public function testAssertMetricsPayloadAcceptsNeutralAfter15Metrics(): void
    {
        $payload = [
            [
                'provider_id' => 55,
                'provider_name' => 'Barbara Liskov',
                'target' => 20,
                'booked' => 12,
                'open' => 8,
                'fill_rate' => 0.6,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => null,
                'slots_required' => 20,
                'has_capacity_gap' => false,
                'after_15_slots' => null,
                'total_offered_slots' => null,
                'after_15_ratio' => null,
                'after_15_percent' => null,
                'after_15_target_met' => null,
                'after_15_evaluable' => false,
            ],
            [
                'provider_id' => 56,
                'provider_name' => 'Margaret Hamilton',
                'target' => 20,
                'booked' => 12,
                'open' => 8,
                'fill_rate' => 0.6,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 0,
                'slots_required' => 20,
                'has_capacity_gap' => true,
                'after_15_slots' => 0,
                'total_offered_slots' => 0,
                'after_15_ratio' => null,
                'after_15_percent' => null,
                'after_15_target_met' => null,
                'after_15_evaluable' => false,
            ],
        ];

        $summary = GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), true);

        $this->assertSame(2, $summary['providers']);
        $this->assertSame(24, $summary['booked_total']);
    }

    public function testAssertMetricsPayloadRejectsPositiveAfter15CountsWhenNonEvaluable(): void
    {
        $payload = [
            [
                'provider_id' => 57,
                'provider_name' => 'Joan Clarke',
                'target' => 20,
                'booked' => 12,
                'open' => 8,
                'fill_rate' => 0.6,
                'needs_attention' => true,
                'has_plan' => true,
                'slots_planned' => 20,
                'slots_required' => 20,
                'has_capacity_gap' => false,
                'after_15_slots' => 4,
                'total_offered_slots' => 10,
                'after_15_ratio' => null,
                'after_15_percent' => null,
                'after_15_target_met' => null,
                'after_15_evaluable' => false,
            ],
        ];

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('must use null/null or 0/0 slot counts');

        GateAssertions::assertMetricsPayload($this->wrapMetricsPayload($payload), false);
    }

    public function testAssertHeatmapPayloadRejectsInvalidWeekday(): void
    {
        $payload = [
            'meta' => [
                'startDate' => '2026-02-01',
                'endDate' => '2026-02-05',
                'intervalMinutes' => 30,
                'timezone' => 'Europe/Berlin',
                'total' => 2,
                'percentile95' => 1.0,
                'rangeLabel' => '08:00–12:00',
            ],
            'slots' => [
                [
                    'weekday' => 6,
                    'time' => '08:00',
                    'count' => 2,
                    'percent' => 100.0,
                ],
            ],
        ];

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('weekday must be in range 1..5');

        GateAssertions::assertHeatmapPayload($payload);
    }

    public function testAssertPdfBinaryRejectsInvalidPrefix(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('does not start with %PDF-');

        GateAssertions::assertPdfBinary('NOT_A_PDF', 'application/pdf', 1);
    }

    private function wrapMetricsPayload(array $metrics): array
    {
        $targetTotal = array_sum(array_map(static fn(array $row): int => (int) ($row['target'] ?? 0), $metrics));
        $bookedTotal = array_sum(array_map(static fn(array $row): int => (int) ($row['booked'] ?? 0), $metrics));
        return [
            'metrics' => $metrics,
            'summary' => [
                'target_total' => $targetTotal,
                'booked_total' => $bookedTotal,
                'open_total' => max($targetTotal - $bookedTotal, 0),
                'fill_rate' => $targetTotal > 0 ? $bookedTotal / $targetTotal : 0.0,
                'threshold' => 0.9,
            ],
        ];
    }

    public function testAssertZipBinaryRejectsInvalidPrefix(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('does not start with a valid PK signature');

        GateAssertions::assertZipBinary('NOT_A_ZIP_FILE', 'application/zip', 1);
    }

    public function testAssertZipBinaryRejectsUnsupportedMimeType(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('unsupported Content-Type');

        GateAssertions::assertZipBinary("PK\x05\x06" . str_repeat("\x00", 18), 'application/gzip', 22);
    }
}

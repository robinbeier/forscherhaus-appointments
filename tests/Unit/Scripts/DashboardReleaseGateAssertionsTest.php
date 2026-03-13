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

        $summary = GateAssertions::assertMetricsPayload($payload, true);

        $this->assertSame(1, $summary['providers']);
        $this->assertSame(10, $summary['booked_total']);
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

        GateAssertions::assertMetricsPayload($payload, false);
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

        GateAssertions::assertMetricsPayload($payload, false);
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

        GateAssertions::assertMetricsPayload($payload, false);
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

        GateAssertions::assertMetricsPayload($payload, false);
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

        $summary = GateAssertions::assertMetricsPayload($payload, true);

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

        GateAssertions::assertMetricsPayload($payload, false);
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

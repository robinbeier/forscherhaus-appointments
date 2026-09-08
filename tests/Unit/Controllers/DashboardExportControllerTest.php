<?php

namespace Tests\Unit\Controllers;

use Dashboard_metrics;
use Dashboard_export;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard_export.php';
require_once APPPATH . 'libraries/Dashboard_metrics.php';

class DashboardExportControllerTest extends TestCase
{
    public function testProviderPreparationExportKeepsProviderAuthenticationAndSessionScope(): void
    {
        $method = new \ReflectionMethod(Dashboard_export::class, 'provider_preparation_pdf');
        $source_lines = file(APPPATH . 'controllers/Dashboard_export.php');

        $this->assertIsArray($source_lines);

        $source = implode(
            '',
            array_slice(
                $source_lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ),
        );

        $this->assertStringContainsString('$this->assertProvider();', $source);
        $this->assertStringContainsString("\$provider_id = (int) session('user_id');", $source);
        $this->assertStringContainsString('$this->loadProviderParentAppointments($provider_id,', $source);
        $this->assertStringNotContainsString("request('provider_id", $source);
        $this->assertStringNotContainsString('provider_ids', $source);
    }

    public function testProviderAppointmentLoaderKeepsBookedPeriodAndSortContract(): void
    {
        $method = new \ReflectionMethod(Dashboard_export::class, 'loadProviderParentAppointments');
        $source_lines = file(APPPATH . 'controllers/Dashboard_export.php');

        $this->assertIsArray($source_lines);

        $source = implode(
            '',
            array_slice(
                $source_lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ),
        );

        $this->assertStringContainsString("->where('appointments.id_users_provider', \$provider_id)", $source);
        $this->assertStringContainsString("->where('appointments.status', 'Booked')", $source);
        $this->assertStringContainsString("->where('appointments.start_datetime <'", $source);
        $this->assertStringContainsString("->where('appointments.end_datetime >'", $source);
        $this->assertStringContainsString("->order_by('appointments.start_datetime', 'ASC')", $source);
    }

    public function testResolveThresholdAcceptsValidValues(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $this->assertSame(0.0, $controller->callResolveThreshold(0));
        $this->assertSame(0.9, $controller->callResolveThreshold('0.9'));
        $this->assertSame(1.0, $controller->callResolveThreshold(1));
    }

    public function testResolveThresholdUsesConfiguredDefaultWhenMissing(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $this->assertSame(0.9, $controller->callResolveThreshold(null));
        $this->assertSame(0.9, $controller->callResolveThreshold(''));
    }

    public function testResolveThresholdRejectsInvalidValues(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $this->expectException(InvalidArgumentException::class);
        $controller->callResolveThreshold('1.2');
    }

    public function testNormalizeProviderIdsReturnsUniquePositiveIntegers(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $normalized = $controller->callNormalizeProviderIds([1, '2', 2, 0, -3, '', null, ' 4 ', '4']);

        $this->assertSame([1, 2, 4], $normalized);
    }

    public function testBuildTeacherPagesCreatesSingleEmptyPageWhenNoAppointments(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $teachers = [$this->createTeacherReport(0)];

        $pages = $controller->callBuildTeacherPages($teachers);

        $this->assertCount(1, $pages);
        $this->assertSame(0, $pages[0]['chunk_index']);
        $this->assertSame(1, $pages[0]['chunks_total']);
        $this->assertSame([], $pages[0]['appointments']);
        $this->assertFalse($pages[0]['has_any_appointments']);
    }

    public function testBuildTeacherPagesKeepsTenAppointmentsOnOnePage(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $teachers = [$this->createTeacherReport(10)];

        $pages = $controller->callBuildTeacherPages($teachers);

        $this->assertCount(1, $pages);
        $this->assertSame(0, $pages[0]['chunk_index']);
        $this->assertSame(1, $pages[0]['chunks_total']);
        $this->assertCount(10, $pages[0]['appointments']);
        $this->assertTrue($pages[0]['has_any_appointments']);
    }

    public function testBuildTeacherPagesUsesTwoPagesForTwentyAppointments(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $teachers = [$this->createTeacherReport(20)];

        $pages = $controller->callBuildTeacherPages($teachers);

        $this->assertCount(2, $pages);
        $this->assertCount(11, $pages[0]['appointments']);
        $this->assertCount(9, $pages[1]['appointments']);
        $this->assertSame(0, $pages[0]['chunk_index']);
        $this->assertSame(1, $pages[1]['chunk_index']);
        $this->assertSame(2, $pages[0]['chunks_total']);
        $this->assertSame(2, $pages[1]['chunks_total']);
    }

    public function testBuildTeacherPagesUsesTwoPagesForTwentyFiveAppointments(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $teachers = [$this->createTeacherReport(25)];

        $pages = $controller->callBuildTeacherPages($teachers);

        $this->assertCount(2, $pages);
        $this->assertCount(11, $pages[0]['appointments']);
        $this->assertCount(14, $pages[1]['appointments']);
        $this->assertSame(2, $pages[0]['chunks_total']);
        $this->assertSame(2, $pages[1]['chunks_total']);
    }

    public function testBuildTeacherPagesUsesThreePagesForTwentySixAppointments(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $teachers = [$this->createTeacherReport(26)];

        $pages = $controller->callBuildTeacherPages($teachers);

        $this->assertCount(3, $pages);
        $this->assertCount(11, $pages[0]['appointments']);
        $this->assertCount(14, $pages[1]['appointments']);
        $this->assertCount(1, $pages[2]['appointments']);
        $this->assertSame(3, $pages[0]['chunks_total']);
        $this->assertSame(3, $pages[1]['chunks_total']);
        $this->assertSame(3, $pages[2]['chunks_total']);
    }

    public function testBuildTeacherPagesDoesNotDuplicateTeacherAppointmentsInPagePayload(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $teachers = [$this->createTeacherReport(21)];

        $pages = $controller->callBuildTeacherPages($teachers);

        $this->assertNotEmpty($pages);

        foreach ($pages as $page) {
            $this->assertArrayNotHasKey('appointments', $page['teacher']);
            $this->assertSame('Test Teacher', $page['teacher']['provider_name']);
        }
    }

    public function testBuildProviderParentAppointmentPagesCreatesSingleEmptyPageWhenNoAppointments(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $pages = $controller->callBuildProviderParentAppointmentPages([]);

        $this->assertCount(1, $pages);
        $this->assertSame([], $pages[0]['appointments']);
        $this->assertSame(0, $pages[0]['chunk_index']);
        $this->assertSame(1, $pages[0]['chunks_total']);
        $this->assertFalse($pages[0]['has_any_appointments']);
    }

    public function testBuildProviderParentAppointmentPagesUsesProviderParentChunkSizes(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $pages = $controller->callBuildProviderParentAppointmentPages($this->createAppointments(41));

        $this->assertCount(3, $pages);
        $this->assertCount(18, $pages[0]['appointments']);
        $this->assertCount(22, $pages[1]['appointments']);
        $this->assertCount(1, $pages[2]['appointments']);
        $this->assertSame(3, $pages[0]['chunks_total']);
        $this->assertSame(3, $pages[1]['chunks_total']);
        $this->assertSame(3, $pages[2]['chunks_total']);
        $this->assertTrue($pages[0]['has_any_appointments']);
    }

    public function testBuildProviderPreparationAppointmentPagesUsesFourAppointmentsPerPage(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $pages = $controller->callBuildProviderPreparationAppointmentPages($this->createAppointments(9));

        $this->assertCount(3, $pages);
        $this->assertCount(4, $pages[0]['appointments']);
        $this->assertCount(4, $pages[1]['appointments']);
        $this->assertCount(1, $pages[2]['appointments']);
        $this->assertSame(3, $pages[0]['chunks_total']);
        $this->assertSame(3, $pages[1]['chunks_total']);
        $this->assertSame(3, $pages[2]['chunks_total']);
        $this->assertTrue($pages[0]['has_any_appointments']);
    }

    public function testBuildProviderPreparationAppointmentPagesCreatesSingleEmptyPage(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $pages = $controller->callBuildProviderPreparationAppointmentPages([]);

        $this->assertCount(1, $pages);
        $this->assertSame([], $pages[0]['appointments']);
        $this->assertSame(0, $pages[0]['chunk_index']);
        $this->assertSame(1, $pages[0]['chunks_total']);
        $this->assertFalse($pages[0]['has_any_appointments']);
    }

    public function testResolveCustomerDisplayNameForParentExportDoesNotExposeContactFallbacks(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $this->assertSame(
            'Adina Rossmeisl',
            $controller->callResolveCustomerDisplayNameForParentExport([
                'customer_first_name' => 'Adina',
                'customer_last_name' => 'Rossmeisl',
                'customer_email' => 'adina@example.test',
                'customer_phone_number' => '123456',
            ]),
        );
        $this->assertSame(
            '—',
            $controller->callResolveCustomerDisplayNameForParentExport([
                'customer_email' => 'adina@example.test',
                'customer_phone_number' => '123456',
            ]),
        );
    }

    public function testMapProviderParentAppointmentsForViewUsesCustomerNameAndDropsExtraPii(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $mapped = $controller->callMapProviderParentAppointmentsForView([
            [
                'start_datetime' => '2026-08-20 14:00:00',
                'end_datetime' => '2026-08-20 14:20:00',
                'customer_first_name' => 'Adina',
                'customer_last_name' => 'Rossmeisl',
                'customer_email' => 'adina@example.test',
                'customer_phone_number' => '123456',
                'notes' => 'Must not reach either PDF view.',
            ],
        ]);

        $this->assertCount(1, $mapped);
        $this->assertSame('Adina Rossmeisl', $mapped[0]['parent_name']);
        $this->assertSame(['parent_name', 'date', 'start', 'end'], array_keys($mapped[0]));
        $this->assertArrayNotHasKey('customer_email', $mapped[0]);
        $this->assertArrayNotHasKey('customer_phone_number', $mapped[0]);
        $this->assertArrayNotHasKey('notes', $mapped[0]);
    }

    public function testBuildSummaryTracksFallbackTargetAndThresholdCounters(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $summary = $controller->callBuildSummary(
            [
                [
                    'target' => 10,
                    'booked' => 6,
                    'booked_appointments' => 3,
                    'open' => 4,
                    'needs_attention' => true,
                    'is_target_fallback' => true,
                    'has_explicit_target' => false,
                    'has_plan' => true,
                ],
                [
                    'target' => 8,
                    'booked' => 8,
                    'booked_appointments' => 8,
                    'open' => 0,
                    'needs_attention' => false,
                    'is_target_fallback' => false,
                    'has_explicit_target' => true,
                    'has_plan' => true,
                ],
                [
                    'target' => 0,
                    'booked' => 0,
                    'booked_appointments' => 0,
                    'open' => 0,
                    'needs_attention' => false,
                    'is_target_fallback' => false,
                    'has_explicit_target' => false,
                    'has_plan' => false,
                ],
            ],
            0.9,
        );

        $this->assertSame(3, $summary['provider_count']);
        $this->assertSame(18, $summary['target_total']);
        $this->assertSame('18', $summary['target_total_formatted']);
        $this->assertSame(14, $summary['booked_total']);
        $this->assertSame('14', $summary['booked_total_formatted']);
        $this->assertSame(11, $summary['appointment_count_total']);
        $this->assertSame('11', $summary['appointment_count_total_formatted']);
        $this->assertSame(4, $summary['open_total']);
        $this->assertSame('4', $summary['open_total_formatted']);
        $this->assertSame(1, $summary['attention_count']);
        $this->assertSame(1, $summary['fallback_count']);
        $this->assertSame(1, $summary['explicit_target_count']);
        $this->assertSame(8, $summary['explicit_target_total']);
        $this->assertFalse($summary['explicit_target_complete']);
        $this->assertSame(2, $summary['without_target_count']);
        $this->assertSame(2, $summary['with_plan_count']);
        $this->assertSame(3, $summary['missing_to_threshold_total']);
        $this->assertSame('3', $summary['missing_to_threshold_total_formatted']);
        $this->assertArrayNotHasKey('missing_parents_total', $summary);
        $this->assertArrayNotHasKey('booked_distinct_total', $summary);
        $this->assertSame(1, $summary['providers_below_threshold']);
        $this->assertEqualsWithDelta(14 / 18, $summary['fill_rate'], 0.0001);
    }

    public function testBuildSummaryDoesNotInventAppointmentCountWhenAnyMetricLacksIt(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $summary = $controller->callBuildSummary(
            [['target' => 10, 'booked' => 6, 'booked_appointments' => 4], ['target' => 8, 'booked' => 8]],
            0.9,
        );

        $this->assertNull($summary['appointment_count_total']);
        $this->assertSame('—', $summary['appointment_count_total_formatted']);
    }

    public function testBuildSummaryUsesZeroAppointmentCountForEmptyMetrics(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $summary = $controller->callBuildSummary([], 0.9);

        $this->assertSame(0, $summary['appointment_count_total']);
        $this->assertSame('0', $summary['appointment_count_total_formatted']);
    }

    public function testSortPrincipalMetricsForReportPrioritizesSharedStatusReasonsAndSeverity(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $sorted = $controller->callSortPrincipalMetricsForReport([
            [
                'provider_name' => 'Capacity Only',
                'gap_to_threshold' => 8,
                'target' => 12,
                'has_plan' => true,
                'slots_planned_raw' => 10,
                'has_explicit_target' => true,
                'has_capacity_gap' => true,
                'after_15_percent' => null,
                'after_15_evaluable' => false,
                'status_reasons' => ['capacity_gap'],
            ],
            [
                'provider_name' => 'After 15 High',
                'gap_to_threshold' => 0,
                'has_explicit_target' => true,
                'after_15_percent' => 31.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['after_15_goal_missed'],
            ],
            [
                'provider_name' => 'Booking Only',
                'gap_to_threshold' => 3,
                'has_explicit_target' => true,
                'after_15_percent' => 45.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['booking_goal_missed'],
            ],
            [
                'provider_name' => 'Combined Low Gap',
                'gap_to_threshold' => 2,
                'has_explicit_target' => true,
                'after_15_percent' => 12.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed'],
            ],
            [
                'provider_name' => 'Combined High Gap',
                'gap_to_threshold' => 5,
                'has_explicit_target' => true,
                'after_15_percent' => 24.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed'],
            ],
            [
                'provider_name' => 'After 15 Low',
                'gap_to_threshold' => 0,
                'has_explicit_target' => true,
                'after_15_percent' => 11.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['after_15_goal_missed'],
            ],
            [
                'provider_name' => 'All Good',
                'gap_to_threshold' => 0,
                'has_explicit_target' => true,
                'after_15_percent' => 40.0,
                'after_15_evaluable' => true,
                'status_reasons' => [],
            ],
        ]);

        $this->assertSame(
            [
                'Capacity Only',
                'Combined High Gap',
                'Booking Only',
                'Combined Low Gap',
                'After 15 Low',
                'After 15 High',
                'All Good',
            ],
            array_column($sorted, 'provider_name'),
        );
    }

    public function testSortPrincipalMetricsUsesFullExplicitTargetGap(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $sorted = $controller->callSortPrincipalMetricsForReport([
            [
                'provider_name' => 'Nearly full',
                'has_explicit_target' => true,
                'target_raw' => 24,
                'booked_appointments_raw' => 23,
                'gap_to_threshold' => 0,
            ],
            [
                'provider_name' => 'One more needed',
                'has_explicit_target' => true,
                'target_raw' => 24,
                'booked_appointments_raw' => 22,
                'gap_to_threshold' => 0,
            ],
        ]);

        $this->assertSame('One more needed', $sorted[0]['provider_name']);
    }

    public function testPrincipalSortingPutsOfferIssuesBeforeBookingLateUnknownAndReachedRows(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $sorted = $controller->callSortPrincipalMetricsForReport([
            [
                'provider_name' => 'Reached',
                'target' => 10,
                'has_plan' => true,
                'slots_planned_raw' => 10,
                'has_explicit_target' => true,
                'status_reasons' => [],
            ],
            [
                'provider_name' => 'Booking gap',
                'target' => 10,
                'has_plan' => true,
                'slots_planned_raw' => 10,
                'has_explicit_target' => true,
                'gap_to_threshold' => 2,
                'status_reasons' => ['booking_goal_missed'],
            ],
            [
                'provider_name' => 'No plan',
                'target' => 10,
                'has_plan' => false,
                'slots_planned_raw' => null,
                'has_explicit_target' => true,
                'gap_to_threshold' => 4,
                'status_reasons' => [],
            ],
            [
                'provider_name' => 'Capacity gap',
                'target' => 10,
                'has_plan' => true,
                'slots_planned_raw' => 10,
                'has_explicit_target' => true,
                'has_capacity_gap' => true,
                'gap_to_threshold' => 4,
                'status_reasons' => [],
            ],
            [
                'provider_name' => 'Late warning',
                'target' => 10,
                'has_plan' => true,
                'slots_planned_raw' => 10,
                'has_explicit_target' => true,
                'after_15_percent' => 10.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['after_15_goal_missed'],
            ],
            [
                'provider_name' => 'Automatic target',
                'target' => 10,
                'is_target_fallback' => true,
                'has_plan' => true,
                'slots_planned_raw' => 10,
                'status_reasons' => [],
            ],
        ]);

        $this->assertSame(
            ['Capacity gap', 'No plan', 'Booking gap', 'Late warning', 'Automatic target', 'Reached'],
            array_column($sorted, 'provider_name'),
        );
    }

    public function testFormatDateCachesWeekdayFormatterPerLocaleAndTimezone(): void
    {
        if (!class_exists(IntlDateFormatter::class)) {
            $this->markTestSkipped('IntlDateFormatter is not available in this environment.');
        }

        $controller = new class extends Dashboard_export {
            public int $formatterFactoryCalls = 0;

            public function __construct() {}

            public function callFormatDate(DateTimeImmutable $date): string
            {
                return $this->formatDate($date);
            }

            protected function resolveLocale(): ?string
            {
                return 'de-DE';
            }

            protected function createWeekdayFormatter(string $locale, string $timezone): ?IntlDateFormatter
            {
                $this->formatterFactoryCalls++;

                return null;
            }
        };

        $berlinDate = new DateTimeImmutable('2026-02-20 10:00:00', new DateTimeZone('Europe/Berlin'));
        $secondBerlinDate = new DateTimeImmutable('2026-02-21 10:00:00', new DateTimeZone('Europe/Berlin'));
        $utcDate = new DateTimeImmutable('2026-02-22 10:00:00', new DateTimeZone('UTC'));

        $controller->callFormatDate($berlinDate);
        $controller->callFormatDate($secondBerlinDate);
        $this->assertSame(1, $controller->formatterFactoryCalls);

        $controller->callFormatDate($utcDate);
        $this->assertSame(2, $controller->formatterFactoryCalls);
    }

    public function testBuildPdfStreamOptionsDisablesDebugDumpByDefault(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $options = $controller->callBuildPdfStreamOptions('/tmp/dashboard-debug.html');

        $this->assertSame(['attachment' => true], $options);
    }

    public function testBuildPdfStreamOptionsEnablesDebugDumpWhenFlagIsTrue(): void
    {
        $controller = $this->createControllerWithThreshold(0.9, 'true');

        $options = $controller->callBuildPdfStreamOptions('/tmp/dashboard-debug.html');

        $this->assertSame(
            [
                'attachment' => true,
                'debug_dump_path' => '/tmp/dashboard-debug.html',
            ],
            $options,
        );
    }

    public function testBuildProviderPreparationPdfStreamOptionsUsesA4Landscape(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $options = $controller->callBuildProviderPreparationPdfStreamOptions('/tmp/provider-preparation.html');

        $this->assertSame(
            [
                'attachment' => true,
                'paper' => 'A4',
                'orientation' => 'landscape',
            ],
            $options,
        );
    }

    public function testMapMetricsForViewPreservesSharedStatusReasonsAndAfter15Fields(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $mapped = $controller->callMapMetricsForView(
            [
                [
                    'provider_id' => 42,
                    'provider_name' => 'Rebecca Schleupner',
                    'target' => 16,
                    'booked' => 0,
                    'open' => 16,
                    'fill_rate' => 0.0,
                    'has_plan' => true,
                    'has_explicit_target' => true,
                    'slots_planned' => 19,
                    'slots_required' => 16,
                    'has_capacity_gap' => false,
                    'after_15_percent' => 21.1,
                    'after_15_target_met' => false,
                    'after_15_evaluable' => true,
                    'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed'],
                ],
            ],
            0.9,
        );

        $this->assertCount(1, $mapped);
        $this->assertSame(['booking_goal_missed', 'after_15_goal_missed'], $mapped[0]['status_reasons']);
        $this->assertSame(21.1, $mapped[0]['after_15_percent']);
        $this->assertFalse($mapped[0]['after_15_target_met']);
        $this->assertTrue($mapped[0]['after_15_evaluable']);
    }

    public function testMapMetricsForViewKeepsPlannedCapacityStableWhenBookingsExist(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $mapped = $controller->callMapMetricsForView(
            [
                [
                    'provider_id' => 42,
                    'provider_name' => 'Booked Teacher',
                    'target' => 18,
                    'booked' => 6,
                    'open' => 12,
                    'fill_rate' => 6 / 18,
                    'has_plan' => true,
                    'has_explicit_target' => true,
                    'slots_planned' => 20,
                    'slots_required' => 20,
                    'has_capacity_gap' => false,
                    'after_15_percent' => 30.0,
                    'after_15_target_met' => true,
                    'after_15_evaluable' => true,
                    'status_reasons' => ['booking_goal_missed'],
                ],
            ],
            0.9,
        );

        $this->assertCount(1, $mapped);
        $this->assertSame(20, $mapped[0]['slots_planned_raw']);
        $this->assertSame('20', $mapped[0]['slots_planned_formatted']);
        $this->assertFalse($mapped[0]['has_capacity_gap']);
        $this->assertSame(['booking_goal_missed'], $mapped[0]['status_reasons']);
    }

    public function testMapMetricsForViewLabelsFallbackTargetOrigin(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $mapped = $controller->callMapMetricsForView(
            [
                [
                    'provider_id' => 42,
                    'provider_name' => 'Fallback Teacher',
                    'target' => 12,
                    'booked' => 9,
                    'open' => 3,
                    'fill_rate' => 0.75,
                    'has_plan' => true,
                    'has_explicit_target' => false,
                    'is_target_fallback' => true,
                ],
                [
                    'provider_id' => 43,
                    'provider_name' => 'Explicit Teacher',
                    'target' => 12,
                    'booked' => 12,
                    'open' => 0,
                    'fill_rate' => 1.0,
                    'has_plan' => true,
                    'has_explicit_target' => true,
                    'is_target_fallback' => false,
                ],
            ],
            0.9,
        );

        $this->assertTrue($mapped[0]['is_target_fallback']);
        $this->assertSame('Automatische Zielgröße', $mapped[0]['target_origin_label']);
        $this->assertFalse($mapped[1]['is_target_fallback']);
        $this->assertSame('Klassengröße', $mapped[1]['target_origin_label']);
    }

    public function testMapMetricsForViewPreservesReliableAppointmentCountSeparatelyFromBookedMetric(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $mapped = $controller->callMapMetricsForView(
            [
                [
                    'provider_id' => 42,
                    'provider_name' => 'Fallback Teacher',
                    'target' => 12,
                    'booked' => 9,
                    'booked_appointments' => 99,
                    'open' => 3,
                    'fill_rate' => 0.75,
                ],
            ],
            0.9,
        );

        $this->assertSame(9, $mapped[0]['booked_raw']);
        $this->assertSame(99, $mapped[0]['booked_appointments_raw']);
        $this->assertSame('99', $mapped[0]['booked_appointments_formatted']);
    }

    private function createControllerWithThreshold(float $configuredThreshold, mixed $pdfDebugDumpFlag = false): object
    {
        $dashboardMetrics = new class extends Dashboard_metrics {
            public function __construct() {}
        };

        return new class ($configuredThreshold, $pdfDebugDumpFlag, $dashboardMetrics) extends Dashboard_export {
            private float $configuredThreshold;

            private mixed $pdfDebugDumpFlag;

            public function __construct(
                float $configuredThreshold,
                mixed $pdfDebugDumpFlag,
                Dashboard_metrics $dashboardMetrics,
            ) {
                $this->configuredThreshold = $configuredThreshold;
                $this->pdfDebugDumpFlag = $pdfDebugDumpFlag;
                $this->dashboardMetrics = $dashboardMetrics;
                $this->dashboard_metrics = $dashboardMetrics;
            }

            public function callResolveThreshold(mixed $thresholdInput): float
            {
                return $this->resolveThreshold($thresholdInput);
            }

            public function callNormalizeProviderIds(mixed $providerIds): array
            {
                return $this->normalizeProviderIds($providerIds);
            }

            public function callBuildTeacherPages(array $teachers): array
            {
                return $this->buildTeacherPages($teachers);
            }

            public function callBuildProviderParentAppointmentPages(array $appointments): array
            {
                return $this->buildProviderParentAppointmentPages($appointments);
            }

            public function callBuildProviderPreparationAppointmentPages(array $appointments): array
            {
                return $this->buildProviderPreparationAppointmentPages($appointments);
            }

            public function callBuildPdfStreamOptions(string $debugDumpPath): array
            {
                return $this->buildPdfStreamOptions($debugDumpPath);
            }

            public function callBuildProviderPreparationPdfStreamOptions(string $debugDumpPath): array
            {
                return $this->buildProviderPreparationPdfStreamOptions($debugDumpPath);
            }

            public function callBuildSummary(array $metrics, float $threshold): array
            {
                return $this->buildSummary($metrics, $threshold);
            }

            public function callMapMetricsForView(array $metrics, float $threshold): array
            {
                return $this->mapMetricsForView($metrics, $threshold);
            }

            public function callSortPrincipalMetricsForReport(array $metrics): array
            {
                return $this->sortPrincipalMetricsForReport($metrics);
            }

            public function callResolveCapacityGapLabel(): string
            {
                return $this->resolveCapacityGapLabel();
            }

            public function callResolveCustomerDisplayNameForParentExport(array $appointment): string
            {
                return $this->resolveCustomerDisplayNameForParentExport($appointment);
            }

            public function callMapProviderParentAppointmentsForView(array $appointments): array
            {
                return $this->mapProviderParentAppointmentsForView($appointments);
            }

            protected function getConfiguredThreshold(): float
            {
                return $this->configuredThreshold;
            }

            protected function resolvePdfDebugDumpFlag(): mixed
            {
                return $this->pdfDebugDumpFlag;
            }
        };
    }

    private function createTeacherReport(int $appointmentsCount): array
    {
        return [
            'provider_id' => 1,
            'provider_name' => 'Test Teacher',
            'appointments' => $this->createAppointments($appointmentsCount),
        ];
    }

    private function createAppointments(int $appointmentsCount): array
    {
        $appointments = [];

        for ($index = 0; $index < $appointmentsCount; $index++) {
            $appointments[] = [
                'parent_lastname' => 'Parent ' . $index,
                'date' => '24/11/2025',
                'start' => '09:00',
                'end' => '09:30',
            ];
        }

        return $appointments;
    }
}

<?php

namespace Tests\Unit\Controllers;

use Dashboard_export;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use InvalidArgumentException;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard_export.php';

class DashboardExportControllerTest extends TestCase
{
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

    public function testBuildPrincipalPagesCreatesSingleEmptyPageWhenNoMetrics(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $pages = $controller->callBuildPrincipalPages([]);

        $this->assertCount(1, $pages);
        $this->assertSame([], $pages[0]);
    }

    public function testBuildPrincipalPagesKeepsFiveMetricsOnFirstPage(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $metrics = $this->createPrincipalMetrics(5);

        $pages = $controller->callBuildPrincipalPages($metrics);

        $this->assertCount(1, $pages);
        $this->assertCount(5, $pages[0]);
    }

    public function testBuildPrincipalPagesUsesTwoPagesForSixteenMetrics(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $metrics = $this->createPrincipalMetrics(16);

        $pages = $controller->callBuildPrincipalPages($metrics);

        $this->assertCount(2, $pages);
        $this->assertCount(5, $pages[0]);
        $this->assertCount(11, $pages[1]);
    }

    public function testBuildPrincipalPagesUsesThreePagesForTwentyMetrics(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $metrics = $this->createPrincipalMetrics(20);

        $pages = $controller->callBuildPrincipalPages($metrics);

        $this->assertCount(3, $pages);
        $this->assertCount(5, $pages[0]);
        $this->assertCount(13, $pages[1]);
        $this->assertCount(2, $pages[2]);
    }

    public function testBuildPrincipalOverviewPrecomputesCountersAndTopAttention(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $metrics = [
            [
                'provider_name' => 'Teacher A',
                'gap_to_threshold' => 4,
                'has_capacity_gap' => true,
            ],
            [
                'provider_name' => 'Teacher B',
                'gap_to_threshold' => 0,
                'has_capacity_gap' => false,
            ],
            [
                'provider_name' => 'Teacher C',
                'gap_to_threshold' => 1,
                'has_capacity_gap' => true,
            ],
        ];
        $summary = [
            'booked_distinct_total_formatted' => '10',
            'target_total_formatted' => '24',
            'fill_rate' => 0.42,
        ];

        $overview = $controller->callBuildPrincipalOverview($metrics, $summary);

        $this->assertSame(3, $overview['teachers_total']);
        $this->assertSame(2, $overview['below_count']);
        $this->assertSame(1, $overview['in_target_count']);
        $this->assertSame(5, $overview['gap_total']);
        $this->assertSame('5', $overview['gap_total_formatted']);
        $this->assertSame('1 / 3 Lehrkräfte über Ziel', $overview['in_target_label']);
        $this->assertSame('10', $overview['booked_distinct_formatted']);
        $this->assertSame('24', $overview['target_total_formatted']);
        $this->assertSame(0.42, $overview['fill_rate_value']);
        $this->assertCount(2, $overview['top_attention']);
        $this->assertSame('Teacher A', $overview['top_attention'][0]['provider_name']);
        $this->assertSame($controller->callResolveCapacityGapLabel(), $overview['capacity_gap_label']);
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

    private function createControllerWithThreshold(float $configuredThreshold, mixed $pdfDebugDumpFlag = false): object
    {
        return new class ($configuredThreshold, $pdfDebugDumpFlag) extends Dashboard_export {
            private float $configuredThreshold;

            private mixed $pdfDebugDumpFlag;

            public function __construct(float $configuredThreshold, mixed $pdfDebugDumpFlag)
            {
                $this->configuredThreshold = $configuredThreshold;
                $this->pdfDebugDumpFlag = $pdfDebugDumpFlag;
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

            public function callBuildPrincipalPages(array $metrics): array
            {
                return $this->buildPrincipalPages($metrics);
            }

            public function callBuildPdfStreamOptions(string $debugDumpPath): array
            {
                return $this->buildPdfStreamOptions($debugDumpPath);
            }

            public function callMapMetricsForView(array $metrics, float $threshold): array
            {
                return $this->mapMetricsForView($metrics, $threshold);
            }

            public function callBuildPrincipalOverview(array $metrics, array $summary): array
            {
                return $this->buildPrincipalOverview($metrics, $summary);
            }

            public function callResolveCapacityGapLabel(): string
            {
                return $this->resolveCapacityGapLabel();
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

    private function createPrincipalMetrics(int $count): array
    {
        $metrics = [];

        for ($index = 0; $index < $count; $index++) {
            $metrics[] = [
                'provider_id' => $index + 1,
                'provider_name' => 'Teacher ' . $index,
                'gap_to_threshold' => 0,
                'fill_ratio' => 1.0,
            ];
        }

        return $metrics;
    }
}

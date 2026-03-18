<?php

namespace Tests\Unit\Controllers;

use Dashboard_metrics;
use Dashboard_export;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use InvalidArgumentException;
use RuntimeException;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Throwable;
use Tests\TestCase;

require_once APPPATH . 'bootstrap/SentryBootstrap.php';
require_once APPPATH . 'controllers/Dashboard_export.php';
require_once APPPATH . 'libraries/Dashboard_metrics.php';

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

    public function testBuildPrincipalPagesKeepsThreeMetricsOnFirstPage(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $metrics = $this->createPrincipalMetrics(3);

        $pages = $controller->callBuildPrincipalPages($metrics);

        $this->assertCount(1, $pages);
        $this->assertCount(3, $pages[0]);
    }

    public function testBuildPrincipalPagesUsesTwoPagesForSixteenMetrics(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $metrics = $this->createPrincipalMetrics(16);

        $pages = $controller->callBuildPrincipalPages($metrics);

        $this->assertCount(2, $pages);
        $this->assertCount(3, $pages[0]);
        $this->assertCount(13, $pages[1]);
    }

    public function testBuildPrincipalPagesUsesThreePagesForTwentyMetrics(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $metrics = $this->createPrincipalMetrics(20);

        $pages = $controller->callBuildPrincipalPages($metrics);

        $this->assertCount(3, $pages);
        $this->assertCount(3, $pages[0]);
        $this->assertCount(13, $pages[1]);
        $this->assertCount(4, $pages[2]);
    }

    public function testBuildPrincipalOverviewPrecomputesCountersAndTopAttention(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $metrics = [
            [
                'provider_name' => 'Teacher A',
                'gap_to_threshold' => 4,
                'has_capacity_gap' => true,
                'has_plan' => true,
                'has_explicit_target' => true,
                'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed', 'capacity_gap'],
            ],
            [
                'provider_name' => 'Teacher B',
                'gap_to_threshold' => 0,
                'has_capacity_gap' => false,
                'has_plan' => true,
                'has_explicit_target' => true,
                'status_reasons' => ['after_15_goal_missed'],
            ],
            [
                'provider_name' => 'Teacher C',
                'gap_to_threshold' => 0,
                'has_capacity_gap' => true,
                'has_plan' => true,
                'has_explicit_target' => true,
                'status_reasons' => ['capacity_gap'],
            ],
            [
                'provider_name' => 'Teacher D',
                'gap_to_threshold' => 0,
                'has_capacity_gap' => false,
                'has_plan' => true,
                'has_explicit_target' => true,
                'status_reasons' => [],
            ],
            [
                'provider_name' => 'Teacher E',
                'gap_to_threshold' => 0,
                'has_capacity_gap' => false,
                'has_plan' => false,
                'has_explicit_target' => true,
                'status_reasons' => [],
            ],
        ];
        $summary = [
            'booked_distinct_total_formatted' => '10',
            'target_total_formatted' => '24',
            'fill_rate' => 0.42,
        ];

        $overview = $controller->callBuildPrincipalOverview($metrics, $summary);

        $this->assertSame(5, $overview['teachers_total']);
        $this->assertSame(1, $overview['below_count']);
        $this->assertSame(1, $overview['booking_goal_missed_count']);
        $this->assertSame(2, $overview['after_15_goal_missed_count']);
        $this->assertSame(2, $overview['capacity_gap_count']);
        $this->assertSame(3, $overview['attention_count']);
        $this->assertSame(3, $overview['in_target_count']);
        $this->assertSame(4, $overview['gap_total']);
        $this->assertSame('4', $overview['gap_total_formatted']);
        $this->assertSame('3 / 5 Lehrkräfte im Buchungsziel', $overview['in_target_label']);
        $this->assertSame('10', $overview['booked_distinct_formatted']);
        $this->assertSame('24', $overview['target_total_formatted']);
        $this->assertSame(0.42, $overview['fill_rate_value']);
        $this->assertCount(3, $overview['top_attention']);
        $this->assertSame('Teacher A', $overview['top_attention'][0]['provider_name']);
        $this->assertSame($controller->callResolveCapacityGapLabel(), $overview['capacity_gap_label']);
    }

    public function testBuildSummaryTracksFallbackTargetAndThresholdCounters(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $summary = $controller->callBuildSummary(
            [
                [
                    'target' => 10,
                    'booked' => 6,
                    'open' => 4,
                    'needs_attention' => true,
                    'is_target_fallback' => true,
                    'has_explicit_target' => false,
                    'has_plan' => true,
                ],
                [
                    'target' => 8,
                    'booked' => 8,
                    'open' => 0,
                    'needs_attention' => false,
                    'is_target_fallback' => false,
                    'has_explicit_target' => true,
                    'has_plan' => true,
                ],
                [
                    'target' => 0,
                    'booked' => 0,
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
        $this->assertSame(4, $summary['open_total']);
        $this->assertSame('4', $summary['open_total_formatted']);
        $this->assertSame(1, $summary['attention_count']);
        $this->assertSame(1, $summary['fallback_count']);
        $this->assertSame(1, $summary['explicit_target_count']);
        $this->assertSame(2, $summary['without_target_count']);
        $this->assertSame(2, $summary['with_plan_count']);
        $this->assertSame(3, $summary['missing_to_threshold_total']);
        $this->assertSame('3', $summary['missing_to_threshold_total_formatted']);
        $this->assertSame(1, $summary['providers_below_threshold']);
        $this->assertEqualsWithDelta(14 / 18, $summary['fill_rate'], 0.0001);
    }

    public function testSortPrincipalMetricsForReportPrioritizesSharedStatusReasonsAndSeverity(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $sorted = $controller->callSortPrincipalMetricsForReport([
            [
                'provider_name' => 'Capacity Only',
                'gap_to_threshold' => 8,
                'after_15_percent' => null,
                'after_15_evaluable' => false,
                'status_reasons' => ['capacity_gap'],
            ],
            [
                'provider_name' => 'After 15 High',
                'gap_to_threshold' => 0,
                'after_15_percent' => 31.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['after_15_goal_missed'],
            ],
            [
                'provider_name' => 'Booking Only',
                'gap_to_threshold' => 3,
                'after_15_percent' => 45.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['booking_goal_missed'],
            ],
            [
                'provider_name' => 'Combined Low Gap',
                'gap_to_threshold' => 2,
                'after_15_percent' => 12.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed'],
            ],
            [
                'provider_name' => 'Combined High Gap',
                'gap_to_threshold' => 5,
                'after_15_percent' => 24.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed'],
            ],
            [
                'provider_name' => 'After 15 Low',
                'gap_to_threshold' => 0,
                'after_15_percent' => 11.0,
                'after_15_evaluable' => true,
                'status_reasons' => ['after_15_goal_missed'],
            ],
            [
                'provider_name' => 'All Good',
                'gap_to_threshold' => 0,
                'after_15_percent' => 40.0,
                'after_15_evaluable' => true,
                'status_reasons' => [],
            ],
        ]);

        $this->assertSame(
            [
                'Combined High Gap',
                'Combined Low Gap',
                'Booking Only',
                'After 15 Low',
                'After 15 High',
                'Capacity Only',
                'All Good',
            ],
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

    public function testCaptureExportExceptionSendsSentryEventWithExportContext(): void
    {
        $transport = $this->createMemoryTransport();

        \Sentry\init([
            'dsn' => 'https://examplePublicKey@o0.ingest.sentry.io/1',
            'default_integrations' => false,
            'transport' => $transport,
        ]);

        try {
            $_SERVER['REQUEST_URI'] = '/index.php/dashboard/export/principal.pdf';

            $controller = new class extends Dashboard_export {
                public function __construct() {}

                public function callCaptureExportException(Throwable $exception, string $exportType): void
                {
                    $this->captureExportException($exception, $exportType);
                }
            };

            $controller->callCaptureExportException(new RuntimeException('export failed'), 'principal_pdf');

            \Sentry\flush();

            $this->assertNotNull($transport->event);
            $this->assertSame('dashboard_export', $transport->event->getTags()['area'] ?? null);
            $this->assertSame('principal_pdf', $transport->event->getTags()['export_type'] ?? null);
            $this->assertStringContainsString(
                'Dashboard_export',
                (string) ($transport->event->getExtra()['controller'] ?? ''),
            );
            $this->assertSame(
                '/index.php/dashboard/export/principal.pdf',
                $transport->event->getExtra()['request_uri'] ?? null,
            );
        } finally {
            unset($_SERVER['REQUEST_URI']);
            SentrySdk::setCurrentHub(new Hub());
        }
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

            public function callBuildPrincipalPages(array $metrics): array
            {
                return $this->buildPrincipalPages($metrics);
            }

            public function callBuildPdfStreamOptions(string $debugDumpPath): array
            {
                return $this->buildPdfStreamOptions($debugDumpPath);
            }

            public function callBuildSummary(array $metrics, float $threshold): array
            {
                return $this->buildSummary($metrics, $threshold);
            }

            public function callMapMetricsForView(array $metrics, float $threshold): array
            {
                return $this->mapMetricsForView($metrics, $threshold);
            }

            public function callBuildPrincipalOverview(array $metrics, array $summary): array
            {
                return $this->buildPrincipalOverview($metrics, $summary);
            }

            public function callSortPrincipalMetricsForReport(array $metrics): array
            {
                return $this->sortPrincipalMetricsForReport($metrics);
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

    private function createMemoryTransport(): TransportInterface
    {
        return new class implements TransportInterface {
            public ?Event $event = null;

            public function send(Event $event): Result
            {
                $this->event = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success(), $this->event);
            }
        };
    }
}

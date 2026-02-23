<?php

namespace Tests\Unit\Controllers;

use Dashboard_export;
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
        $this->assertCount(10, $pages[0]['appointments']);
        $this->assertCount(10, $pages[1]['appointments']);
        $this->assertSame(0, $pages[0]['chunk_index']);
        $this->assertSame(1, $pages[1]['chunk_index']);
        $this->assertSame(2, $pages[0]['chunks_total']);
        $this->assertSame(2, $pages[1]['chunks_total']);
    }

    public function testBuildTeacherPagesUsesThreePagesForTwentyOneAppointments(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);
        $teachers = [$this->createTeacherReport(21)];

        $pages = $controller->callBuildTeacherPages($teachers);

        $this->assertCount(3, $pages);
        $this->assertCount(10, $pages[0]['appointments']);
        $this->assertCount(10, $pages[1]['appointments']);
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

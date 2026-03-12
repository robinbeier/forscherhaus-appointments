<?php

namespace Tests\Unit\Controllers;

use Dashboard;
use Dashboard_metrics;
use DateTimeImmutable;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard.php';
require_once APPPATH . 'libraries/Dashboard_metrics.php';

class DashboardMetricsControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
        session([
            'role_slug' => null,
            'user_id' => null,
            'dashboard_conflict_threshold' => null,
        ]);
    }

    public function testMetricsPersistsRangeForAdminOnSuccess(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 11,
            'dashboard_conflict_threshold' => '0.73',
        ]);

        $_POST = [
            'start_date' => '2026-11-24',
            'end_date' => '2026-11-30',
            'statuses' => ['Booked', 'Cancelled'],
            'service_id' => '5',
            'provider_ids' => ['7', '9'],
        ];

        $expected = [
            [
                'provider_id' => 7,
                'provider_name' => 'Lehrkraft',
                'booked' => 8,
                'open' => 4,
                'target' => 12,
                'fill_rate' => 0.6667,
                'has_explicit_target' => true,
                'has_plan' => true,
                'after_15_slots' => 4,
                'total_offered_slots' => 19,
                'after_15_ratio' => 0.2105,
                'after_15_percent' => 21.1,
                'after_15_target_met' => false,
                'after_15_evaluable' => true,
                'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed'],
            ],
        ];

        $metricsLibrary = $this->createMock(Dashboard_metrics::class);
        $metricsLibrary
            ->expects($this->once())
            ->method('collect')
            ->with(
                $this->callback(
                    static fn($value) => $value instanceof DateTimeImmutable &&
                        $value->format('Y-m-d') === '2026-11-24',
                ),
                $this->callback(
                    static fn($value) => $value instanceof DateTimeImmutable &&
                        $value->format('Y-m-d') === '2026-11-30',
                ),
                [
                    'statuses' => ['Booked', 'Cancelled'],
                    'service_id' => '5',
                    'provider_ids' => ['7', '9'],
                    'threshold' => 0.73,
                ],
            )
            ->willReturn($expected);

        $controller = $this->createController();
        $controller->dashboard_metrics = $metricsLibrary;

        $controller->metrics();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertSame($expected, $response);
        $this->assertTrue($controller->persistCalled);
        $this->assertSame(11, $controller->persistUserId);
        $this->assertSame('2026-11-24', $controller->persistStartDate);
        $this->assertSame('2026-11-30', $controller->persistEndDate);
    }

    public function testMetricsDoesNotPersistRangeWhenPeriodIsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 11,
        ]);

        $_POST = [
            'start_date' => '2026-11-24',
        ];

        $metricsLibrary = $this->createMock(Dashboard_metrics::class);
        $metricsLibrary->expects($this->never())->method('collect');

        $controller = $this->createController();
        $controller->dashboard_metrics = $metricsLibrary;

        $controller->metrics();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($response['success']);
        $this->assertSame(lang('filter_period_required'), $response['message']);
        $this->assertFalse($controller->persistCalled);
    }

    public function testMetricsRejectsNonAdminWithoutPersisting(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        session([
            'role_slug' => DB_SLUG_PROVIDER,
            'user_id' => 2,
        ]);

        $_POST = [
            'start_date' => '2026-11-24',
            'end_date' => '2026-11-30',
        ];

        $metricsLibrary = $this->createMock(Dashboard_metrics::class);
        $metricsLibrary->expects($this->never())->method('collect');

        $controller = $this->createController();
        $controller->dashboard_metrics = $metricsLibrary;

        $controller->metrics();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($response['success']);
        $this->assertSame('Forbidden', $response['message']);
        $this->assertFalse($controller->persistCalled);
    }

    public function testMetricsRejectsNonPostWithoutPersisting(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 11,
        ]);

        $_POST = [
            'start_date' => '2026-11-24',
            'end_date' => '2026-11-30',
        ];

        $metricsLibrary = $this->createMock(Dashboard_metrics::class);
        $metricsLibrary->expects($this->never())->method('collect');

        $controller = $this->createController();
        $controller->dashboard_metrics = $metricsLibrary;

        $controller->metrics();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($response['success']);
        $this->assertSame('Forbidden', $response['message']);
        $this->assertFalse($controller->persistCalled);
    }

    private function createController(): object
    {
        return new class extends Dashboard {
            public bool $persistCalled = false;
            public int $persistUserId = 0;
            public string $persistStartDate = '';
            public string $persistEndDate = '';

            public function __construct() {}

            protected function persistProviderDashboardRange(
                int $provider_id,
                DateTimeImmutable $start,
                DateTimeImmutable $end,
            ): void {
                $this->persistCalled = true;
                $this->persistUserId = $provider_id;
                $this->persistStartDate = $start->format('Y-m-d');
                $this->persistEndDate = $end->format('Y-m-d');
            }

            protected function getConfiguredThreshold(): float
            {
                return 0.9;
            }
        };
    }
}

<?php

namespace Tests\Unit\Controllers;

use Dashboard;
use Dashboard_heatmap;
use DateTimeImmutable;
use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard.php';
require_once APPPATH . 'libraries/Dashboard_heatmap.php';

class DashboardHeatmapControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        $_POST = [];
        get_instance()->output->set_output('');
    }

    public function testHeatmapReturnsJsonResponse(): void
    {
        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);

        $_POST = [
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-05',
        ];

        $expected = [
            'meta' => [
                'startDate' => '2024-01-01',
                'endDate' => '2024-01-05',
                'intervalMinutes' => 30,
                'timezone' => 'Europe/Berlin',
                'total' => 0,
                'percentile95' => 0,
                'rangeLabel' => '06:00–20:00',
            ],
            'slots' => [],
        ];

        $heatmap = $this->createMock(Dashboard_heatmap::class);
        $heatmap
            ->expects($this->once())
            ->method('collect')
            ->with(
                $this->callback(
                    static fn($value) => $value instanceof DateTimeImmutable &&
                        $value->format('Y-m-d') === '2024-01-01',
                ),
                $this->callback(
                    static fn($value) => $value instanceof DateTimeImmutable &&
                        $value->format('Y-m-d') === '2024-01-05',
                ),
                ['statuses' => [], 'service_id' => null, 'provider_ids' => []],
            )
            ->willReturn($expected);

        $controller = $this->createControllerWithoutConstructor();
        $controller->dashboard_heatmap = $heatmap;

        $controller->heatmap();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertSame($expected, $response);
    }

    public function testHeatmapReturnsValidationError(): void
    {
        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);

        $_POST = [
            'end_date' => '2024-01-05',
        ];

        $controller = $this->createControllerWithoutConstructor();
        $controller->dashboard_heatmap = $this->createMock(Dashboard_heatmap::class);

        $controller->heatmap();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($response['success']);
        $this->assertSame(lang('filter_period_required'), $response['message']);
    }

    private function createControllerWithoutConstructor(): Dashboard
    {
        $reflection = new ReflectionClass(Dashboard::class);

        /** @var Dashboard $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        return $controller;
    }
}

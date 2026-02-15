<?php

namespace Tests\Unit\Controllers;

use Dashboard;
use Dashboard_metrics;
use DateTimeImmutable;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard.php';
require_once APPPATH . 'libraries/Dashboard_metrics.php';

class DashboardProviderMetricsControllerTest extends TestCase
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
        ]);
    }

    public function testProviderMetricsReturnsPayloadForProvider(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        session([
            'role_slug' => DB_SLUG_PROVIDER,
            'user_id' => 42,
        ]);

        $_POST = [
            'start_date' => '2026-11-24',
            'end_date' => '2026-11-30',
        ];

        $controller = $this->createProviderMetricsController([
            'provider_id' => 42,
            'provider_name' => 'Lehrkraft',
            'period' => [
                'start_date' => '2026-11-24',
                'end_date' => '2026-11-30',
            ],
            'progress' => [
                'booked_percent' => 50,
                'open_percent' => 50,
                'slot_info_text' => '12 von 24 Terminen gebucht',
            ],
            'metrics' => [
                'class_size' => 24,
                'class_size_formatted' => '24',
                'booked' => 12,
                'booked_formatted' => '12',
                'open' => 12,
                'open_formatted' => '12',
                'slots_planned' => 24,
                'slots_planned_formatted' => '24',
                'slots_required' => 24,
                'slots_required_formatted' => '24',
                'has_capacity_gap' => false,
            ],
            'appointments' => [],
        ]);

        $controller->provider_metrics();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertSame(42, $response['provider_id']);
        $this->assertSame('Lehrkraft', $response['provider_name']);
        $this->assertSame('2026-11-24', $response['period']['start_date']);
        $this->assertSame('2026-11-30', $response['period']['end_date']);
        $this->assertSame(42, $controller->capturedProviderId);
    }

    public function testProviderMetricsRejectsNonProvider(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);

        $_POST = [
            'start_date' => '2026-11-24',
            'end_date' => '2026-11-30',
        ];

        $controller = $this->createProviderMetricsController([]);

        $controller->provider_metrics();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($response['success']);
        $this->assertSame('Forbidden', $response['message']);
    }

    public function testProviderMetricsRejectsInvalidPeriod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        session([
            'role_slug' => DB_SLUG_PROVIDER,
            'user_id' => 42,
        ]);

        $_POST = [
            'end_date' => '2026-11-30',
        ];

        $controller = $this->createProviderMetricsController([]);

        $controller->provider_metrics();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($response['success']);
        $this->assertSame(lang('filter_period_required'), $response['message']);
        $this->assertFalse($controller->persistCalled);
    }

    public function testProviderMetricsPersistsRangePerUser(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        session([
            'role_slug' => DB_SLUG_PROVIDER,
            'user_id' => 99,
        ]);

        $_POST = [
            'start_date' => '2026-11-24',
            'end_date' => '2026-11-30',
        ];

        $controller = $this->createProviderMetricsController([
            'provider_id' => 99,
            'provider_name' => 'Lehrkraft',
            'period' => [
                'start_date' => '2026-11-24',
                'end_date' => '2026-11-30',
            ],
            'progress' => [
                'booked_percent' => 0,
                'open_percent' => 0,
                'slot_info_text' => '',
            ],
            'metrics' => [],
            'appointments' => [],
        ]);

        $controller->provider_metrics();

        $this->assertTrue($controller->persistCalled);
        $this->assertSame(99, $controller->persistProviderId);
        $this->assertSame('2026-11-24', $controller->persistStartDate);
        $this->assertSame('2026-11-30', $controller->persistEndDate);
    }

    public function testCollectProviderMetricsUsesBookedStatusAndSessionProvider(): void
    {
        $start = new DateTimeImmutable('2026-11-24');
        $end = new DateTimeImmutable('2026-11-30');

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
                ['statuses' => ['Booked'], 'provider_ids' => [77]],
            )
            ->willReturn([['provider_id' => 77]]);

        $controller = $this->createControllerWithoutConstructor();
        $controller->dashboard_metrics = $metricsLibrary;

        $result = $controller->callCollectProviderMetrics(77, $start, $end);

        $this->assertSame([['provider_id' => 77]], $result);
    }

    private function createControllerWithoutConstructor(): object
    {
        return new class extends Dashboard {
            public function __construct()
            {
            }

            public function callCollectProviderMetrics(
                int $providerId,
                DateTimeImmutable $start,
                DateTimeImmutable $end,
            ): array {
                return $this->collectProviderMetrics($providerId, $start, $end);
            }
        };
    }

    private function createProviderMetricsController(array $payload): object
    {
        return new class ($payload) extends Dashboard {
            public array $payload;
            public bool $persistCalled = false;
            public int $capturedProviderId = 0;
            public int $persistProviderId = 0;
            public string $persistStartDate = '';
            public string $persistEndDate = '';

            public function __construct(array $payload)
            {
                $this->payload = $payload;
            }

            protected function buildProviderDashboardPayload(
                int $provider_id,
                DateTimeImmutable $start,
                DateTimeImmutable $end,
            ): array {
                $this->capturedProviderId = $provider_id;

                return $this->payload;
            }

            protected function persistProviderDashboardRange(
                int $provider_id,
                DateTimeImmutable $start,
                DateTimeImmutable $end,
            ): void {
                $this->persistCalled = true;
                $this->persistProviderId = $provider_id;
                $this->persistStartDate = $start->format('Y-m-d');
                $this->persistEndDate = $end->format('Y-m-d');
            }
        };
    }
}

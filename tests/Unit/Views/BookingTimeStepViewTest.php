<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class BookingTimeStepViewTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        config([
            'html_vars' => [],
            'script_vars' => [],
            'layout' => [
                'filename' => 'test-layout',
                'sections' => [],
                'tmp' => [],
            ],
        ]);
    }

    public function testRendersNoSlotFallbackByDefaultWhenFlagMissing(): void
    {
        $output = $this->renderTimeStepWithFallbackFlag(null);

        $this->assertStringContainsString('id="no-slot-fallback"', $output);
        $this->assertStringContainsString('id="no-slot-fallback-trigger"', $output);
    }

    public function testRendersNoSlotFallbackWhenFlagEnabled(): void
    {
        $output = $this->renderTimeStepWithFallbackFlag('1');

        $this->assertStringContainsString('id="no-slot-fallback"', $output);
        $this->assertStringContainsString('id="no-slot-fallback-trigger"', $output);
    }

    public function testDoesNotRenderNoSlotFallbackWhenFlagDisabled(): void
    {
        $output = $this->renderTimeStepWithFallbackFlag('0');

        $this->assertStringNotContainsString('id="no-slot-fallback"', $output);
        $this->assertStringNotContainsString('id="no-slot-fallback-trigger"', $output);
    }

    private function renderTimeStepWithFallbackFlag(?string $fallbackFlag): string
    {
        config([
            'html_vars' => [],
            'script_vars' => [],
            'layout' => [
                'filename' => 'test-layout',
                'sections' => [],
                'tmp' => [],
            ],
        ]);

        if ($fallbackFlag !== null) {
            html_vars(['no_slot_fallback_enabled' => $fallbackFlag]);
        }

        $grouped_timezones = [
            'Etc' => [
                'UTC' => 'UTC',
            ],
        ];

        ob_start();
        include APPPATH . 'views/components/booking_time_step.php';

        return (string) ob_get_clean();
    }
}

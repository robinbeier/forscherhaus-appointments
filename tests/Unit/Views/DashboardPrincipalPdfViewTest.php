<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class DashboardPrincipalPdfViewTest extends TestCase
{
    public function testRendersAfter15ColumnAndStackedStatusBadges(): void
    {
        require_once APPPATH . 'helpers/donut_helper.php';

        $school_name = 'Forscherhaus';
        $logo_data_url = null;
        $generated_at_text = '12.03.2026, 10:00';
        $period_label = '13.04.2026 - 19.04.2026';
        $threshold_percent = '90 %';
        $threshold_ratio = 0.9;
        $summary = [
            'fill_rate_formatted' => '0,0 %',
            'booked_distinct_total_formatted' => '0',
            'target_total_formatted' => '18',
            'fill_rate' => 0.0,
        ];
        $principal_pages = [
            [
                [
                    'provider_name' => 'Adina Rossmeisl',
                    'target' => '18',
                    'target_raw' => 18,
                    'booked' => '0',
                    'booked_raw' => 0,
                    'fill_rate_percent' => '0,0 %',
                    'fill_rate_percent_value' => 0,
                    'gap_to_threshold' => 17,
                    'gap_to_threshold_formatted' => '17',
                    'slots_planned_raw' => 17,
                    'slots_required_raw' => 18,
                    'has_capacity_gap' => true,
                    'has_plan' => true,
                    'has_explicit_target' => true,
                    'after_15_percent' => 11.8,
                    'after_15_evaluable' => true,
                    'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed', 'capacity_gap'],
                ],
            ],
        ];
        $principal_overview = [
            'teachers_total' => 1,
            'below_count' => 1,
            'booking_goal_missed_count' => 1,
            'after_15_goal_missed_count' => 1,
            'capacity_gap_count' => 1,
            'attention_count' => 1,
            'in_target_count' => 0,
            'gap_total_formatted' => '17',
            'in_target_label' => '0 / 1 Lehrkräfte im Buchungsziel',
            'top_attention' => $principal_pages[0],
            'booked_distinct_formatted' => '0',
            'target_total_formatted' => '18',
            'fill_rate_value' => 0.0,
            'capacity_gap_label' => 'Kapazitätslücke',
        ];
        $metrics = $principal_pages[0];

        ob_start();
        include APPPATH . 'views/exports/dashboard_principal_pdf.php';
        $output = (string) ob_get_clean();

        $this->assertStringContainsString(lang('dashboard_principal_after_15_heading') ?: 'Nach 15:00', $output);
        $this->assertStringContainsString(
            lang('dashboard_principal_until_booking_goal') ?: 'bis Buchungsziel',
            $output,
        );
        $this->assertStringContainsString(lang('dashboard_booking_goal_missed') ?: 'Buchungsziel verfehlt', $output);
        $this->assertStringContainsString(lang('dashboard_after_15_goal_missed') ?: '15-Uhr-Vorgabe verfehlt', $output);
        $this->assertStringContainsString(lang('dashboard_slots_gap_badge') ?: 'Kapazitätslücke', $output);
        $this->assertStringContainsString('11,8&nbsp;%', $output);
        $this->assertStringContainsString('status-list', $output);
        $this->assertStringNotContainsString('provider__badge', $output);
    }
}

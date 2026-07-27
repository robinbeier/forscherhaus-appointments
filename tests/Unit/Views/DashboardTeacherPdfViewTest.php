<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class DashboardTeacherPdfViewTest extends TestCase
{
    public function testLargeCombinedReportKeepsRendererPayloadWellBelowLimit(): void
    {
        $school_name = 'Synthetic School';
        $logo_data_url = 'data:image/png;base64,' . str_repeat('A', 354_516);
        $generated_at_text = '27.07.2026, 08:00';
        $period_label = '01.07.2026 - 31.07.2026';
        $threshold_ratio = 0.9;
        $teachers = [];
        $teacher_pages = [];

        for ($teacherIndex = 1; $teacherIndex <= 30; $teacherIndex++) {
            $appointments = [];

            for ($appointmentIndex = 1; $appointmentIndex <= 25; $appointmentIndex++) {
                $appointments[] = [
                    'parent_lastname' => sprintf('Synthetic-%02d-%02d', $teacherIndex, $appointmentIndex),
                    'date' => '27.07.2026',
                    'start' => '08:00',
                    'end' => '08:20',
                ];
            }

            $teacher = [
                'provider_name' => sprintf('Synthetic Teacher %02d', $teacherIndex),
                'progress' => [
                    'booked_percent' => 90,
                    'open_percent' => 10,
                ],
                'slot_info_text' => 'Synthetic progress',
                'target_formatted' => '25',
                'booked_formatted' => '25',
                'booked_percent_formatted' => '100 %',
                'open_formatted' => '0',
                'slots_planned_formatted' => '25',
                'slots_required_formatted' => '25',
            ];
            $teachers[] = $teacher;

            $chunks = [array_slice($appointments, 0, 11), array_slice($appointments, 11)];

            foreach ($chunks as $chunkIndex => $chunk) {
                $teacher_pages[] = [
                    'teacher' => $teacher,
                    'chunk_index' => $chunkIndex,
                    'chunks_total' => count($chunks),
                    'appointments' => $chunk,
                    'has_any_appointments' => true,
                ];
            }
        }

        ob_start();
        include APPPATH . 'views/exports/dashboard_teacher_pdf.php';
        $output = (string) ob_get_clean();
        $payload = json_encode(['html' => $output], JSON_THROW_ON_ERROR);

        $this->assertSame(60, substr_count($output, '<div class="page'));
        $this->assertSame(750, substr_count($output, 'Synthetic-'));
        $this->assertLessThan(2 * 1024 * 1024, strlen($payload));
        $this->assertSame(1, substr_count($output, $logo_data_url));
        $this->assertSame(60, substr_count($output, 'class="logo header__logo"'));
        $this->assertSame(1, substr_count($output, 'logo.src = logoDataUrl'));
        $this->assertStringContainsString('logo.decode().catch', $output);
        $this->assertStringContainsString('window.chartsReady = true', $output);
    }
}

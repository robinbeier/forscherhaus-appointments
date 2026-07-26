<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class ProviderPreparationPdfViewTest extends TestCase
{
    public function testRendersLandscapePreparationSheetWithFourBlankNoteLines(): void
    {
        $school_name = 'Forscherhaus';
        $logo_data_url = null;
        $generated_at_text = '20. August 2026, 08:15 Uhr';
        $period_label = '20.–23. Aug 2026';
        $provider_name = 'Adina Rossmeisl';
        $appointment_pages = [
            [
                'chunk_index' => 0,
                'chunks_total' => 1,
                'has_any_appointments' => true,
                'appointments' => [
                    [
                        'parent_name' => 'Familie <Becker>',
                        'date' => 'Do, 20.08.2026',
                        'start' => '14:00',
                        'end' => '14:20',
                        'customer_email' => 'private@example.test',
                        'customer_phone_number' => '123456',
                        'notes' => 'PRIVATE APPOINTMENT NOTE',
                    ],
                ],
            ],
        ];

        ob_start();
        include APPPATH . 'views/exports/provider_preparation_pdf.php';
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('@page{size:A4 landscape;', $output);
        $this->assertStringContainsString('Vorbereitung Klassenleitungsgespräche', $output);
        $this->assertStringContainsString('Adina Rossmeisl', $output);
        $this->assertStringContainsString('<th class="col-name">Name</th>', $output);
        $this->assertStringContainsString('<th class="col-datetime">Datum &amp; Uhrzeit</th>', $output);
        $this->assertStringContainsString('<th class="col-notes">Notizen</th>', $output);
        $this->assertStringContainsString('Familie &lt;Becker&gt;', $output);
        $this->assertStringContainsString('Do, 20.08.2026', $output);
        $this->assertStringContainsString('14:00 - 14:20', $output);
        $this->assertSame(4, substr_count($output, 'class="notes__line"'));
        $this->assertStringNotContainsString('private@example.test', $output);
        $this->assertStringNotContainsString('123456', $output);
        $this->assertStringNotContainsString('PRIVATE APPOINTMENT NOTE', $output);
    }

    public function testRendersEmptyBookedAppointmentState(): void
    {
        $school_name = 'Forscherhaus';
        $logo_data_url = null;
        $generated_at_text = '20. August 2026, 08:15 Uhr';
        $period_label = '20.–23. Aug 2026';
        $provider_name = 'Adina Rossmeisl';
        $appointment_pages = [
            [
                'chunk_index' => 0,
                'chunks_total' => 1,
                'has_any_appointments' => false,
                'appointments' => [],
            ],
        ];

        ob_start();
        include APPPATH . 'views/exports/provider_preparation_pdf.php';
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Keine gebuchten Termine im Zeitraum.', $output);
        $this->assertSame(0, substr_count($output, 'class="notes__line"'));
    }
}

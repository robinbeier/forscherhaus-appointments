<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class ProviderParentAppointmentsPdfViewTest extends TestCase
{
    public function testRendersAcceptedParentAppointmentExportWireframe(): void
    {
        $school_name = 'Forscherhaus';
        $logo_data_url = null;
        $generated_at_text = '20. Mai 2026, 08:15 Uhr';
        $period_label = '13.–19. Apr 2026';
        $provider_name = 'Adina Rossmeisl';
        $appointment_pages = [
            [
                'chunk_index' => 0,
                'chunks_total' => 1,
                'has_any_appointments' => true,
                'appointments' => [
                    [
                        'parent_name' => 'Familie Becker',
                        'date' => 'Mo, 13.04.2026',
                        'start' => '08:00',
                        'end' => '08:25',
                    ],
                ],
            ],
        ];

        ob_start();
        include APPPATH . 'views/exports/provider_parent_appointments_pdf.php';
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Terminübersicht für Eltern', $output);
        $this->assertStringContainsString('Klassenleitungsgespräche ·', $output);
        $this->assertStringContainsString('Adina Rossmeisl', $output);
        $this->assertStringContainsString('Eingetragener Name', $output);
        $this->assertStringContainsString('Datum', $output);
        $this->assertStringContainsString('Beginn', $output);
        $this->assertStringContainsString('Ende', $output);
        $this->assertStringContainsString('Familie Becker', $output);
        $this->assertStringNotContainsString('Hinweis', $output);
        $this->assertStringNotContainsString('Sortierung', $output);
        $this->assertStringNotContainsString('Zeitraum', $output);
        $this->assertStringNotContainsString('Termine</span>', $output);
        $this->assertStringNotContainsString('Lehrkraft</span>', $output);
        $this->assertStringNotContainsString('E-Mail', $output);
        $this->assertStringNotContainsString('Telefon', $output);
        $this->assertStringNotContainsString('Notizen', $output);
    }

    public function testRendersEmptyStateWithoutExtraMetadataCards(): void
    {
        $school_name = 'Forscherhaus';
        $logo_data_url = null;
        $generated_at_text = '20. Mai 2026, 08:15 Uhr';
        $period_label = '13.–19. Apr 2026';
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
        include APPPATH . 'views/exports/provider_parent_appointments_pdf.php';
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Keine Termine im Zeitraum.', $output);
        $this->assertStringNotContainsString('Sortierung', $output);
        $this->assertStringNotContainsString('Hinweis', $output);
    }
}

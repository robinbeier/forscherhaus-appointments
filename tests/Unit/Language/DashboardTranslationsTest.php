<?php

namespace Tests\Unit\Language;

use Tests\TestCase;

class DashboardTranslationsTest extends TestCase
{
    public function testGermanDashboardHintsDescribePlannedCapacity(): void
    {
        $this->assertSame('Aus geplanter Kapazität im Arbeitsplan geschätzt.', lang('dashboard_target_fallback_hint'));
        $this->assertStringContainsString('Geplante Slots im Zeitraum', lang('dashboard_slots_gap_hint'));
        $this->assertStringContainsString('bereits gebuchte Termine', lang('dashboard_slots_gap_hint'));
    }

    public function testEnglishDashboardHintsDescribePlannedCapacity(): void
    {
        $translations = $this->loadTranslations('english');

        $this->assertSame(
            'Estimated from planned capacity in the working plan.',
            $translations['dashboard_target_fallback_hint'] ?? null,
        );
        $this->assertStringContainsString(
            'Planned slots for the period',
            $translations['dashboard_slots_gap_hint'] ?? '',
        );
        $this->assertStringContainsString(
            'already booked appointments do not reduce this planned value',
            $translations['dashboard_slots_gap_hint'] ?? '',
        );
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslations(string $language): array
    {
        $translations = [];
        $lang = [];

        require APPPATH . 'language/' . $language . '/translations_lang.php';

        $translations = $lang;

        return is_array($translations) ? $translations : [];
    }
}

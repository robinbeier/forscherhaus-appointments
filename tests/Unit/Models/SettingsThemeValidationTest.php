<?php

namespace Tests\Unit\Models;

use InvalidArgumentException;
use Settings_model;
use Tests\TestCase;

class SettingsThemeValidationTest extends TestCase
{
    private Settings_model $settingsModel;

    private array $themeSetting = [];

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->helper('asset');
        $CI->load->model('settings_model');
        $this->settingsModel = $CI->settings_model;
        $this->themeSetting = $this->settingsModel->db->get_where('settings', ['name' => 'theme'])->row_array();
        $this->settingsModel->db->trans_begin();
    }

    protected function tearDown(): void
    {
        if ($this->settingsModel->db->trans_active()) {
            $this->settingsModel->db->trans_rollback();
        }

        parent::tearDown();
    }

    public function testRejectsThemeValueThatCanBreakAStylesheetAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The selected theme is not available.');

        try {
            $this->settingsModel->save([
                'id' => (int) $this->themeSetting['id'],
                'name' => 'theme',
                'value' => 'default" onerror="alert(1)',
            ]);
        } finally {
            $stored = $this->settingsModel->db->get_where('settings', ['name' => 'theme'])->row_array();
            $this->assertSame($this->themeSetting['value'], $stored['value']);
        }
    }

    public function testAcceptsThemeShippedWithThisInstallation(): void
    {
        $this->settingsModel->save([
            'id' => (int) $this->themeSetting['id'],
            'name' => 'theme',
            'value' => 'default',
        ]);

        $stored = $this->settingsModel->db->get_where('settings', ['name' => 'theme'])->row_array();
        $this->assertSame('default', $stored['value']);
        $this->assertTrue(is_valid_theme_slug('default'));
        $this->assertFalse(is_valid_theme_slug('default" onerror="alert(1)'));
    }

    public function testLegacyMaliciousThemeValueFallsBackToDefaultStylesheet(): void
    {
        $url = theme_asset_url('default" onerror="alert(1)');

        $this->assertMatchesRegularExpression('~assets/css/themes/default(?:\.min)?\.css~', $url);
        $this->assertStringNotContainsString('onerror', $url);
        $this->assertStringNotContainsString('alert(1)', $url);
    }
}

<?php

namespace Tests\Unit\Models;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Settings_model;
use Tests\TestCase;

class SettingsModelCompanyColorTest extends TestCase
{
    private Settings_model $settingsModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('settings_model');
        $this->settingsModel = $CI->settings_model;
    }

    public function testCompanyColorAcceptsThreeAndSixDigitHexValues(): void
    {
        $this->settingsModel->validate(['name' => 'company_color', 'value' => '#abc']);
        $this->settingsModel->validate(['name' => 'company_color', 'value' => '#A1b2C3']);

        $this->assertTrue(true);
    }

    public function testSaveNormalizesThreeDigitCompanyColorBeforePersistence(): void
    {
        $setting = $this->settingsModel->query()->where('name', 'company_color')->get()->row_array();

        $this->assertNotEmpty($setting);
        $this->settingsModel->db->trans_begin();

        try {
            $this->settingsModel->save([
                'id' => $setting['id'],
                'name' => 'company_color',
                'value' => '#Ab3',
            ]);

            $this->assertSame('#AAbb33', $this->settingsModel->find((int) $setting['id'])['value']);
        } finally {
            $this->settingsModel->db->trans_rollback();
        }
    }

    #[DataProvider('invalidCompanyColorProvider')]
    public function testCompanyColorRejectsInvalidValues(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settingsModel->validate(['name' => 'company_color', 'value' => $value]);
    }

    public static function invalidCompanyColorProvider(): array
    {
        return [
            'css payload' => ['#abc;background:url(javascript:alert(1))'],
            'trailing newline' => ["#abc\n"],
            'non string' => [42],
        ];
    }
}

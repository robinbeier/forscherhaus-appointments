<?php

namespace Tests\Unit\Controllers;

use Backoffice_request_dto_factory;
use BackofficeSettingsRequestDto;
use General_settings;
use ApiSettingsUpdateDto;
use Api_request_dto_factory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Settings_api_v1;
use Settings_model;
use Tests\TestCase;

require_once APPPATH . 'controllers/General_settings.php';
require_once APPPATH . 'controllers/api/v1/Settings_api_v1.php';
require_once APPPATH . 'libraries/Backoffice_request_dto_factory.php';
require_once APPPATH . 'libraries/Api_request_dto_factory.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class GeneralSettingsBatchValidationTest extends TestCase
{
    private Settings_model $settingsModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('settings_model');
        $this->settingsModel = $CI->settings_model;
        session(['role_slug' => DB_SLUG_ADMIN, 'user_id' => 1]);
        $this->settingsModel->db->trans_begin();
    }

    protected function tearDown(): void
    {
        $this->settingsModel->db->trans_rollback();
        get_instance()->output->set_output('');
        session(['role_slug' => null, 'user_id' => null]);

        parent::tearDown();
    }

    public function testInvalidColorLeavesEarlierBatchSettingsUnchanged(): void
    {
        $companyName = $this->findSetting('company_name');
        $companyColor = $this->findSetting('company_color');
        $this->saveBatch([
            ['name' => 'company_name', 'value' => 'Temporary name'],
            ['name' => 'company_color', 'value' => '#abc; color: red'],
        ]);

        $response = json_decode(get_instance()->output->get_output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($response['success']);
        $this->assertSame('The company color must be a valid hexadecimal color.', $response['message']);
        $this->assertSame($companyName['value'], $this->findSetting('company_name')['value']);
        $this->assertSame($companyColor['value'], $this->findSetting('company_color')['value']);
    }

    public function testValidBatchPersistsAndNormalizesColor(): void
    {
        $this->saveBatch([
            ['name' => 'company_name', 'value' => 'Temporary name'],
            ['name' => 'company_color', 'value' => '#abc'],
        ]);

        $this->assertSame('', get_instance()->output->get_output());
        $this->assertSame('Temporary name', $this->findSetting('company_name')['value']);
        $this->assertSame('#aabbcc', $this->findSetting('company_color')['value']);
    }

    public function testSettingsApiUpdateAndShowReturnNormalizedCompanyColor(): void
    {
        $controller = $this->createApiController('#abc');
        $controller->update('company_color');
        $updateResponse = json_decode(get_instance()->output->get_output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['name' => 'company_color', 'value' => '#aabbcc'], $updateResponse);
        $this->assertSame('#aabbcc', $this->findSetting('company_color')['value']);

        get_instance()->output->set_output('');
        $controller->show('company_color');
        $showResponse = json_decode(get_instance()->output->get_output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['name' => 'company_color', 'value' => '#aabbcc'], $showResponse);
    }

    private function saveBatch(array $settings): void
    {
        $controller = $this->createController($settings);
        $controller->save();
    }

    private function createController(array $settings): General_settings
    {
        $factory = new class ($settings) extends Backoffice_request_dto_factory {
            public function __construct(private readonly array $settings) {}

            public function buildSettingsRequestDto(string $key = 'settings'): BackofficeSettingsRequestDto
            {
                return new BackofficeSettingsRequestDto($this->settings);
            }
        };

        $controller = new class extends General_settings {
            public Settings_model $settings_model;
            public Backoffice_request_dto_factory $backoffice_request_dto_factory;

            public function __construct() {}
        };
        $controller->settings_model = $this->settingsModel;
        $controller->backoffice_request_dto_factory = $factory;

        return $controller;
    }

    private function createApiController(string $value): Settings_api_v1
    {
        $factory = new class ($value) extends Api_request_dto_factory {
            public function __construct(private readonly string $value) {}

            public function buildSettingsUpdateDto(): ApiSettingsUpdateDto
            {
                return new ApiSettingsUpdateDto($this->value);
            }
        };

        $controller = new class extends Settings_api_v1 {
            public Api_request_dto_factory $api_request_dto_factory;

            public function __construct() {}
        };
        $controller->api_request_dto_factory = $factory;

        return $controller;
    }

    private function findSetting(string $name): array
    {
        return $this->settingsModel->query()->where('name', $name)->get()->row_array();
    }
}

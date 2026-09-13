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

    /** @var list<string> */
    private array $ownedNames = [];

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
        $db = $this->settingsModel->db;
        if ($db->trans_active()) {
            $db->trans_rollback();
        }
        foreach ($this->ownedNames as $name) {
            $db->delete('settings', ['name' => $name]);
        }
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
        $this->assertTrue($this->settingsModel->db->trans_active());
    }

    public function testStandaloneFailureRollsBackEarlierUpdateAndLaterInsert(): void
    {
        $db = $this->settingsModel->db;
        $db->trans_rollback();
        $existingName = $this->ownedName('standalone-existing');
        $newName = $this->ownedName('standalone-new');
        $this->settingsModel->save(['name' => $existingName, 'value' => 'before']);
        $before = $this->findSetting($existingName);
        try {
            $this->saveBatchWithModel(
                [['name' => $existingName, 'value' => 'changed'], ['name' => $newName, 'value' => 'must roll back']],
                new FailingGeneralSettingsModel(),
            );

            $response = json_decode(get_instance()->output->get_output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($response['success']);
            $this->assertSame($before, $this->findSetting($existingName));
            $this->assertSame(0, $db->get_where('settings', ['name' => $newName])->num_rows());
            $this->assertFalse($db->trans_active());
        } finally {
            $this->deleteOwnedNames();
        }
    }

    public function testJoinedFailureLeavesTransactionForCallerRollback(): void
    {
        $db = $this->settingsModel->db;
        $db->trans_rollback();
        $existingName = $this->ownedName('joined-existing');
        $newName = $this->ownedName('joined-new');
        $priorName = $this->ownedName('joined-prior');
        $this->settingsModel->save(['name' => $existingName, 'value' => 'before']);
        $before = $this->findSetting($existingName);
        $db->trans_begin();
        try {
            $this->settingsModel->save(['name' => $priorName, 'value' => 'caller write']);
            $this->saveBatchWithModel(
                [
                    ['name' => $existingName, 'value' => 'changed'],
                    ['name' => $newName, 'value' => 'must remain until caller rollback'],
                ],
                new FailingGeneralSettingsModel(),
            );

            $this->assertTrue($db->trans_active());
            $this->assertSame('changed', $this->findSetting($existingName)['value']);
            $this->assertSame(1, $db->get_where('settings', ['name' => $newName])->num_rows());
            $db->trans_rollback();
            $this->assertSame($before, $this->findSetting($existingName));
            $this->assertSame(0, $db->get_where('settings', ['name' => $newName])->num_rows());
            $this->assertSame(0, $db->get_where('settings', ['name' => $priorName])->num_rows());
            $this->assertFalse($db->trans_active());
        } finally {
            $this->deleteOwnedNames();
        }
    }

    public function testInitiallyAbsentDuplicateNamesRemainPreparedAsTwoRecords(): void
    {
        $name = $this->ownedName('duplicate');
        $this->saveBatch([['name' => $name, 'value' => 'first'], ['name' => $name, 'value' => 'second']]);

        $rows = $this->settingsModel->db
            ->order_by('id', 'ASC')
            ->get_where('settings', ['name' => $name])
            ->result_array();
        $this->assertCount(2, $rows);
        $this->assertSame(['first', 'second'], array_column($rows, 'value'));
    }

    public function testPreparedExistingIdWinsAcrossRenameOrdering(): void
    {
        $db = $this->settingsModel->db;
        $db->trans_rollback();
        $existingName = $this->ownedName('prepared-existing');
        $renamedName = $this->ownedName('prepared-renamed');
        $this->settingsModel->save(['name' => $existingName, 'value' => 'before']);
        $before = $this->findSetting($existingName);
        $db->trans_begin();
        try {
            $existingId = (int) $before['id'];
            $this->saveBatch([
                ['name' => $renamedName, 'id' => $existingId, 'value' => 'renamed'],
                ['name' => $existingName, 'value' => 'final'],
            ]);

            $this->assertSame(1, $db->get_where('settings', ['id' => $existingId])->num_rows());
            $this->assertSame($existingName, $this->findSetting($existingName)['name']);
            $this->assertSame('final', $this->findSetting($existingName)['value']);
            $this->assertSame($existingId, (int) $this->findSetting($existingName)['id']);
            $this->assertSame(0, $db->get_where('settings', ['name' => $renamedName])->num_rows());
            $db->trans_rollback();
            $this->assertSame($before, $this->findSetting($existingName));
            $this->assertSame(0, $db->get_where('settings', ['name' => $renamedName])->num_rows());
        } finally {
            $this->deleteOwnedNames();
        }
    }

    public function testInvalidLaterEntryDoesNotCallSave(): void
    {
        $model = new CountingGeneralSettingsModel();
        $this->saveBatchWithModel(
            [
                ['name' => 'company_name', 'value' => 'must not write'],
                ['name' => 'company_color', 'value' => '#abc; color: red'],
            ],
            $model,
        );

        $this->assertSame(0, $model->saveCount());
        $this->assertTrue($this->settingsModel->db->trans_active());
    }

    public function testEmptyBatchDoesNotOpenOwnedTransaction(): void
    {
        $db = $this->settingsModel->db;
        $db->trans_rollback();
        $this->saveBatch([]);

        $this->assertSame('', get_instance()->output->get_output());
        $this->assertFalse($db->trans_active());
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
        $this->saveBatchWithModel($settings, $this->settingsModel);
    }

    private function saveBatchWithModel(array $settings, Settings_model $settingsModel): void
    {
        $controller = $this->createController($settings, $settingsModel);
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $controller->save();
        } finally {
            if ($previousMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            }
        }
    }

    private function createController(array $settings, ?Settings_model $settingsModel = null): General_settings
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
        $controller->settings_model = $settingsModel ?? $this->settingsModel;
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

    private function ownedName(string $suffix): string
    {
        $name = 'general_settings_' . $suffix . '_' . bin2hex(random_bytes(4));
        $this->ownedNames[] = $name;
        return $name;
    }

    private function deleteOwnedNames(): void
    {
        $db = $this->settingsModel->db;
        if ($db->trans_active()) {
            $db->trans_rollback();
        }
        foreach ($this->ownedNames as $name) {
            $db->delete('settings', ['name' => $name]);
        }
        $this->ownedNames = [];
    }
}

final class FailingGeneralSettingsModel extends Settings_model
{
    private int $saveCount = 0;

    public function save(array $setting): int
    {
        $id = parent::save($setting);
        $this->saveCount++;
        if ($this->saveCount === 2) {
            throw new \RuntimeException('Injected general settings failure.');
        }
        return $id;
    }
}

final class CountingGeneralSettingsModel extends Settings_model
{
    private int $saveCount = 0;

    public function save(array $setting): int
    {
        $this->saveCount++;
        return parent::save($setting);
    }

    public function saveCount(): int
    {
        return $this->saveCount;
    }
}

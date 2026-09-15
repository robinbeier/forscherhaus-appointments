<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use InvalidArgumentException;
use RuntimeException;
use Settings_model;
use Tests\TestCase;

require_once APPPATH . 'models/Settings_model.php';

final class SettingsModelAtomicBatchTest extends TestCase
{
    private Settings_model $settingsModel;

    /** @var list<string> */
    private array $ownedNames = [];

    protected function setUp(): void
    {
        parent::setUp();
        get_instance()->load->model('settings_model');
        $this->settingsModel = get_instance()->settings_model;
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupOwnedFixtures();
            $this->assertOwnedFixturesAbsent();
        } finally {
            parent::tearDown();
        }
    }

    public function test_partial_fixture_cleanup_rolls_back_and_preserves_unowned_sentinel(): void
    {
        $ownedName = $this->ownedSettingName('partial');
        $sentinelName = 'atomic_batch_sentinel_' . bin2hex(random_bytes(4));
        $this->settingsModel->save(['name' => $ownedName, 'value' => 'owned']);
        $db = get_instance()->db;
        self::assertSame('owned', $db->get_where('settings', ['name' => $ownedName])->row_array()['value']);
        try {
            self::assertTrue($db->insert('settings', ['name' => $sentinelName, 'value' => 'sentinel']));
            $sentinelSnapshot = $db->get_where('settings', ['name' => $sentinelName])->row_array();
            self::assertNotEmpty($sentinelSnapshot);
            self::assertTrue($db->trans_begin());
            try {
                self::assertTrue(
                    $db->update('settings', ['value' => 'uncommitted sentinel change'], ['name' => $sentinelName]),
                );
                self::assertSame(
                    'uncommitted sentinel change',
                    $db->get_where('settings', ['name' => $sentinelName])->row_array()['value'],
                );
                $this->settingsModel->save(['name' => $ownedName, 'value' => 'partial update']);
                throw new RuntimeException('simulated fixture creation failure');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated fixture creation failure', $exception->getMessage());
            }
            $this->cleanupOwnedFixtures();
            self::assertFalse($db->trans_active());
            self::assertSame(0, $db->get_where('settings', ['name' => $ownedName])->num_rows());
            self::assertSame($sentinelSnapshot, $db->get_where('settings', ['name' => $sentinelName])->row_array());
        } finally {
            if ($db->trans_active()) {
                self::assertTrue($db->trans_rollback());
            }
            self::assertTrue($db->delete('settings', ['name' => $sentinelName]));
            self::assertSame(0, $db->get_where('settings', ['name' => $sentinelName])->num_rows());
        }
    }

    private function cleanupOwnedFixtures(): void
    {
        $db = get_instance()->db;
        if ($db->trans_active()) {
            self::assertTrue($db->trans_rollback());
            self::assertFalse($db->trans_active());
        }
        foreach ($this->ownedNames as $name) {
            $rows = $db->get_where('settings', ['name' => $name])->result_array();
            foreach ($rows as $row) {
                $db->delete('settings', ['id' => (int) $row['id'], 'name' => $name]);
            }
        }
    }

    private function assertOwnedFixturesAbsent(): void
    {
        $db = get_instance()->db;
        foreach ($this->ownedNames as $name) {
            self::assertSame(0, $db->get_where('settings', ['name' => $name])->num_rows());
        }
    }

    public function testBatchCreatesUpdatesInOrderAndPreservesCallerIdFallback(): void
    {
        $existingName = $this->ownedSettingName('existing');
        $fallbackName = $this->ownedSettingName('fallback');
        $newName = $this->ownedSettingName('new');
        $existingId = $this->settingsModel->save(['name' => $existingName, 'value' => 'before']);
        $this->settingsModel->save(['name' => $fallbackName, 'value' => 'anchor']);
        $fallbackId = (int) get_instance()
            ->db->get_where('settings', ['name' => $fallbackName])
            ->row_array()['id'];

        $this->settingsModel->save_batch([
            ['name' => $existingName, 'id' => 999999, 'value' => 'first'],
            ['name' => $existingName, 'value' => 'last'],
            ['name' => $newName, 'id' => $fallbackId, 'value' => 'renamed'],
        ]);

        self::assertSame('last', $this->settingsModel->find($existingId)['value']);
        self::assertSame('renamed', $this->settingsModel->find($fallbackId)['value']);
        self::assertSame($newName, $this->settingsModel->find($fallbackId)['name']);
        self::assertFalse(get_instance()->db->trans_active());
    }

    public function testInitiallyNewDuplicateNameProducesOneRowWithLastValue(): void
    {
        $name = $this->ownedSettingName('duplicate-new');
        $this->settingsModel->save_batch([['name' => $name, 'value' => 'first'], ['name' => $name, 'value' => 'last']]);

        self::assertSame(
            1,
            get_instance()
                ->db->get_where('settings', ['name' => $name])
                ->num_rows(),
        );
        self::assertSame(
            'last',
            get_instance()
                ->db->get_where('settings', ['name' => $name])
                ->row_array()['value'],
        );
    }

    public function testEmptyBatchDoesNotOpenTransactionAndColorNormalizationJoinsOuterTransaction(): void
    {
        $db = get_instance()->db;
        $this->settingsModel->save_batch([]);
        self::assertFalse($db->trans_active());
        $original = $db->get_where('settings', ['name' => 'company_color'])->row_array();
        self::assertNotEmpty($original);
        self::assertTrue($db->trans_begin());
        try {
            $this->settingsModel->save_batch([['name' => 'company_color', 'value' => '#abc']]);
            self::assertSame('#aabbcc', $db->get_where('settings', ['name' => 'company_color'])->row_array()['value']);
            $db->trans_rollback();
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }
        self::assertSame($original, $db->get_where('settings', ['name' => 'company_color'])->row_array());
    }

    public function testInvalidLaterEntryPrevalidatesBeforeAnyMutation(): void
    {
        $firstName = $this->ownedSettingName('first');
        $model = new CountingSettingsBatchModel();
        try {
            $model->save_batch([['name' => $firstName, 'value' => 'must-not-persist'], ['value' => 'invalid']]);
            self::fail('Expected invalid batch entry.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('name', $exception->getMessage());
        }

        self::assertSame(0, $model->saveCount());
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('settings', ['name' => $firstName])
                ->num_rows(),
        );
        self::assertFalse(get_instance()->db->trans_active());
    }

    public function testFailureAfterWritesRollsBackWholeStandaloneBatch(): void
    {
        $model = new FailingSettingsBatchModel();
        $existingName = $this->ownedSettingName('existing');
        $existingId = $this->settingsModel->save(['name' => $existingName, 'value' => 'before']);
        $before = get_instance()
            ->db->get_where('settings', ['id' => $existingId])
            ->row_array();
        $newName = $this->ownedSettingName('new');
        try {
            $model->save_batch([
                ['name' => $existingName, 'value' => 'changed'],
                ['name' => $newName, 'value' => 'second'],
            ]);
            self::fail('Expected injected settings failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected settings batch failure.', $exception->getMessage());
        }

        self::assertSame(2, $model->saveCount());
        self::assertSame(
            $before,
            get_instance()
                ->db->get_where('settings', ['id' => $existingId])
                ->row_array(),
        );
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('settings', ['name' => $newName])
                ->num_rows(),
        );
        self::assertFalse(get_instance()->db->trans_active());
    }

    public function testOuterTransactionRetainsOwnershipAndRollbackRemovesBatch(): void
    {
        $db = get_instance()->db;
        self::assertTrue($db->trans_begin());
        $name = $this->ownedSettingName('outer');
        try {
            $this->settingsModel->save_batch([['name' => $name, 'value' => 'outer']]);
            self::assertTrue($db->trans_active());
            self::assertSame(1, $db->get_where('settings', ['name' => $name])->num_rows());
            $db->trans_rollback();
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }
        self::assertSame(0, $db->get_where('settings', ['name' => $name])->num_rows());
        self::assertFalse($db->trans_active());
    }

    public function testJoinedFailureLeavesPriorOuterWriteForCallerRollback(): void
    {
        $db = get_instance()->db;
        self::assertTrue($db->trans_begin());
        $priorName = $this->ownedSettingName('prior');
        $firstName = $this->ownedSettingName('joined-first');
        $secondName = $this->ownedSettingName('joined-second');
        try {
            $this->settingsModel->save(['name' => $priorName, 'value' => 'prior']);
            try {
                (new FailingSettingsBatchModel())->save_batch([
                    ['name' => $firstName, 'value' => 'first'],
                    ['name' => $secondName, 'value' => 'second'],
                ]);
                self::fail('Expected injected settings batch failure.');
            } catch (RuntimeException $exception) {
                self::assertSame('Injected settings batch failure.', $exception->getMessage());
                self::assertTrue($db->trans_active());
            }
            self::assertSame(1, $db->get_where('settings', ['name' => $priorName])->num_rows());
            self::assertSame(1, $db->get_where('settings', ['name' => $firstName])->num_rows());
            $db->trans_rollback();
        } finally {
            if ($db->trans_active()) {
                $db->trans_rollback();
            }
        }
        self::assertSame(0, $db->get_where('settings', ['name' => $priorName])->num_rows());
        self::assertSame(0, $db->get_where('settings', ['name' => $firstName])->num_rows());
    }

    private function ownedSettingName(string $suffix): string
    {
        $name = 'atomic_batch_' . $suffix . '_' . bin2hex(random_bytes(4));
        $this->ownedNames[] = $name;
        return $name;
    }
}

final class FailingSettingsBatchModel extends Settings_model
{
    private int $saveCount = 0;

    public function save(array $setting): int
    {
        $id = parent::save($setting);
        $this->saveCount++;
        if ($this->saveCount === 2) {
            throw new RuntimeException('Injected settings batch failure.');
        }
        return $id;
    }

    public function saveCount(): int
    {
        return $this->saveCount;
    }
}

final class CountingSettingsBatchModel extends Settings_model
{
    private int $count = 0;

    public function save(array $setting): int
    {
        $this->count++;
        return parent::save($setting);
    }

    public function saveCount(): int
    {
        return $this->count;
    }
}

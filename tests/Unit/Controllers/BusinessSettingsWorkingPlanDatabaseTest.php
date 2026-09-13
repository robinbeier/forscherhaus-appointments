<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use BackofficeEntityPayloadRequestDto;
use Backoffice_request_dto_factory;
use Business_settings;
use Providers_model;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

require_once APPPATH . 'controllers/Business_settings.php';
require_once APPPATH . 'models/Providers_model.php';
require_once APPPATH . 'libraries/Backoffice_request_dto_factory.php';

/** Real model writes to owned synthetic providers; no real HTTP/authentication proof. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BusinessSettingsWorkingPlanDatabaseTest extends TestCase
{
    private Providers_model $providersModel;
    private array $ownedEmails = [];
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        get_instance()->load->model('providers_model');
        $this->providersModel = get_instance()->providers_model;
        session(['role_slug' => DB_SLUG_ADMIN, 'user_id' => 1]);
        for ($i = 0; $i < 2; $i++) {
            $suffix = bin2hex(random_bytes(6));
            $email = 'working-plan-' . $suffix . '@example.org';
            $this->ownedEmails[] = $email;
            $this->ids[] = $this->providersModel->save([
                'first_name' => 'Synthetic',
                'last_name' => 'Working plan',
                'email' => $email,
                'services' => [],
                'settings' => ['username' => 'plan-' . $suffix, 'password' => 'synthetic-password'],
            ]);
        }
    }

    protected function tearDown(): void
    {
        $db = get_instance()->db;
        if ($db->trans_active()) {
            $db->trans_rollback();
        }
        try {
            foreach ($this->ownedEmails as $email) {
                $rows = $db
                    ->select('users.id')
                    ->from('users')
                    ->join('roles', 'roles.id = users.id_roles')
                    ->where(['users.email' => $email, 'roles.slug' => DB_SLUG_PROVIDER])
                    ->get()
                    ->result_array();
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    self::assertTrue($db->delete('user_settings', ['id_users' => $id]));
                    self::assertTrue($db->delete('users', ['id' => $id]));
                    self::assertSame(0, $db->get_where('users', ['id' => $id])->num_rows());
                }
            }
        } finally {
            session(['role_slug' => null, 'user_id' => null]);
            get_instance()->output->set_output('');
            parent::tearDown();
        }
    }

    public function testOwnedFailureRestoresAllProviderSettings(): void
    {
        $before = $this->snapshot();
        $model = $this->invoke(['monday' => ['start' => '08:00', 'end' => '16:00']], true);
        self::assertSame(2, $model->writes);
        $response = json_decode(get_instance()->output->get_output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($response['success']);
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->providersModel->db->trans_active());
    }

    public function testOwnedSuccessChangesOnlyWorkingPlansAndIsIdempotent(): void
    {
        $before = $this->snapshot();
        $payload = ['monday' => ['start' => '08:00'], 'tuesday' => ['start' => '09:00']];
        $expected = '{"tuesday":{"start":"09:00"},"monday":{"start":"08:00"}}';
        $this->invoke($payload);
        self::assertSame('', get_instance()->output->get_output());
        self::assertFalse($this->providersModel->db->trans_active());
        $after = $this->snapshot();
        foreach ($before as $index => $row) {
            $row['working_plan'] = $expected;
            self::assertSame($row, $after[$index]);
        }
        $this->invoke($payload);
        self::assertSame('', get_instance()->output->get_output());
        self::assertSame($after, $this->snapshot());
        $this->invoke([]);
        foreach ($this->snapshot() as $row) {
            self::assertSame('{}', $row['working_plan']);
        }
    }

    public function testJoinedSuccessAndFailureRetainCallerOwnership(): void
    {
        $db = $this->providersModel->db;
        $before = $this->snapshot();
        foreach ([false, true] as $fail) {
            self::assertTrue($db->trans_begin());
            try {
                $this->providersModel->set_setting($this->ids[0], 'working_plan_exceptions', '{"synthetic":[]}');
                $model = $this->invoke(['monday' => ['start' => '10:00']], $fail);
                self::assertSame(2, $model->writes);
                self::assertTrue($db->trans_active());
                $during = $this->snapshot();
                self::assertSame('{"synthetic":[]}', $during[0]['working_plan_exceptions']);
                foreach ($during as $row) {
                    self::assertSame('{"monday":{"start":"10:00"}}', $row['working_plan']);
                }
                if ($fail) {
                    $response = json_decode(get_instance()->output->get_output(), true, flags: JSON_THROW_ON_ERROR);
                    self::assertFalse($response['success']);
                } else {
                    self::assertSame('', get_instance()->output->get_output());
                }
            } finally {
                $db->trans_rollback();
            }
            self::assertSame($before, $this->snapshot());
        }
    }

    private function snapshot(): array
    {
        return $this->providersModel->db
            ->where_in('id_users', $this->ids)
            ->order_by('id_users', 'ASC')
            ->get('user_settings')
            ->result_array();
    }

    private function invoke(array $payload, bool $fail = false): WorkingPlanOwnedProviderModel
    {
        $model = new WorkingPlanOwnedProviderModel();
        $model->fixtureIds = $this->ids;
        $model->fail = $fail;
        $factory = new class ($payload) extends Backoffice_request_dto_factory {
            public function __construct(private array $payload) {}
            public function buildEntityPayloadRequestDto(string $key): BackofficeEntityPayloadRequestDto
            {
                if ($key !== 'working_plan') {
                    throw new \RuntimeException('Unexpected DTO key.');
                }
                return new BackofficeEntityPayloadRequestDto($this->payload);
            }
        };
        $controller = new class extends Business_settings {
            public Providers_model $providers_model;
            public Backoffice_request_dto_factory $backoffice_request_dto_factory;
            public function __construct() {}
        };
        $controller->providers_model = $model;
        $controller->backoffice_request_dto_factory = $factory;
        $previous = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
        try {
            $controller->apply_global_working_plan();
        } finally {
            if ($previous === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previous;
            }
        }
        return $model;
    }
}

/** Restricts the real provider query to this test's owned IDs; it does not prove global selection. */
final class WorkingPlanOwnedProviderModel extends Providers_model
{
    public array $fixtureIds = [];
    public bool $fail = false;
    public int $writes = 0;
    public function get(
        array|string|null $where = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $order_by = null,
    ): array {
        if ($this->fixtureIds === []) {
            return [];
        }
        $providers = [];
        foreach ($this->fixtureIds as $id) {
            foreach (parent::get(['users.id' => $id]) as $provider) {
                $providers[] = $provider;
            }
        }
        return $providers;
    }
    public function set_setting(int $provider_id, string $name, mixed $value = null): void
    {
        parent::set_setting($provider_id, $name, $value);
        $this->writes++;
        if ($this->fail && $this->writes === 2) {
            throw new \RuntimeException('Synthetic working plan failure.');
        }
    }
}

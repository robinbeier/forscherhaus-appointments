<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once APPPATH . 'core/EA_Model.php';
require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Api_request_dto_factory.php';
require_once APPPATH . 'models/Service_categories_model.php';
require_once APPPATH . 'controllers/api/v1/Service_categories_api_v1.php';

/**
 * Proves the post-write failure boundary against the isolated database.
 *
 * The injected failure occurs only in this test-process model subclass, after
 * the real model has inserted or updated the row. A corrected implementation
 * must leave the database at the pre-request snapshot when the response is a
 * server error. This deliberately fails on the current baseline, which keeps
 * the post-write partial-write risk visible while the fix is developed.
 */
final class ServiceCategoriesApiPostWriteFailureTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private mixed $originalOutput = null;
    /** @var list<int> */
    private array $createdCategoryIds = [];
    /** @var list<string> */
    private array $createdCategoryNames = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        $this->originalOutput = get_instance()->output;
        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
        } catch (Throwable $error) {
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixture !== null) {
                $db = get_instance()->db;
                $transactionLeftOpen = $db->trans_active();
                if ($transactionLeftOpen) {
                    self::assertTrue($db->trans_rollback());
                }
                foreach ($this->createdCategoryIds as $id) {
                    $db->delete('service_categories', ['id' => $id]);
                    self::assertSame(0, $db->get_where('service_categories', ['id' => $id])->num_rows());
                }
                foreach ($this->createdCategoryNames as $name) {
                    $db->delete('service_categories', ['name' => $name]);
                    self::assertSame(0, $db->get_where('service_categories', ['name' => $name])->num_rows());
                }
                self::assertFalse($transactionLeftOpen, 'The service-category request left a transaction open.');
            }
        } finally {
            if ($this->originalOutput !== null) {
                get_instance()->output = $this->originalOutput;
            }
            unset($_SERVER['REQUEST_METHOD']);
            $this->fixture?->cleanup();
        }
    }

    public function testPostWriteFailureRollsBackInsertedCategory(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $name = $this->fixture->run . '_post_write_failure';
        $this->createdCategoryNames[] = $name;
        $model = $this->faultingModel();
        $controller = $this->controller($model, ['name' => $name, 'description' => 'must roll back']);

        $controller->store();

        self::assertSame(500, $this->capturedOutput()->status, $this->capturedOutput()->body);
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('service_categories', ['name' => $name])
                ->num_rows(),
            'A post-insert response failure must not leave a persisted category behind.',
        );
    }

    public function testPostWriteFailureRollsBackUpdatedCategory(): void
    {
        $db = get_instance()->db;
        $db->insert('service_categories', [
            'name' => $this->fixture->run . '_before_update',
            'description' => 'original',
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
        ]);
        $categoryId = (int) $db->insert_id();
        $this->createdCategoryIds[] = $categoryId;
        $before = $db->get_where('service_categories', ['id' => $categoryId])->row_array();
        self::assertNotEmpty($before);

        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $model = $this->faultingModel();
        $payload = [
            'name' => $this->fixture->run . '_after_update',
            'description' => 'must roll back',
        ];
        $controller = $this->controller($model, $payload);
        $controller->update($categoryId);

        self::assertSame(500, $this->capturedOutput()->status, $this->capturedOutput()->body);
        self::assertSame(
            $before,
            $db->get_where('service_categories', ['id' => $categoryId])->row_array(),
            'A post-update response failure must restore the complete original row.',
        );
    }

    private function faultingModel(): ServiceCategoriesApiPostWriteFaultModel
    {
        $model = new ServiceCategoriesApiPostWriteFaultModel();
        $model->db = get_instance()->db;
        return $model;
    }

    /** @param array<string, mixed> $payload */
    private function controller(
        ServiceCategoriesApiPostWriteFaultModel $model,
        array $payload,
    ): ServiceCategoriesApiControllerHarness {
        get_instance()->output = new ServiceCategoriesApiOutputDouble();
        $controller = new ServiceCategoriesApiControllerHarness();
        $controller->configure($model, new ServiceCategoriesApiDtoFactoryDouble($payload));
        return $controller;
    }

    private function capturedOutput(): ServiceCategoriesApiOutputDouble
    {
        self::assertInstanceOf(ServiceCategoriesApiOutputDouble::class, get_instance()->output);
        return get_instance()->output;
    }
}

final class ServiceCategoriesApiControllerHarness extends Service_categories_api_v1
{
    public object $service_categories_model;
    public Api_request_dto_factory $api_request_dto_factory;

    public function __construct() {}

    public function configure(ServiceCategoriesApiPostWriteFaultModel $model, Api_request_dto_factory $factory): void
    {
        $this->service_categories_model = $model;
        $this->api_request_dto_factory = $factory;
    }
}

final class ServiceCategoriesApiPostWriteFaultModel extends Service_categories_model
{
    public function __construct() {}

    public function find(int $service_category_id): array
    {
        throw new RuntimeException('Injected post-write response failure.');
    }
}

final class ServiceCategoriesApiDtoFactoryDouble extends Api_request_dto_factory
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload) {}

    public function buildEntityWritePayloadDto(): ApiEntityWritePayloadDto
    {
        return new ApiEntityWritePayloadDto($this->payload);
    }
}

final class ServiceCategoriesApiOutputDouble extends CI_Output
{
    public int $status = 200;
    public string $body = '';

    public function set_status_header($code = 200, $text = '')
    {
        $this->status = (int) $code;
        return $this;
    }

    public function set_content_type($mime_type, $charset = null)
    {
        return $this;
    }

    public function set_output($output)
    {
        $this->body = (string) $output;
        return $this;
    }
}

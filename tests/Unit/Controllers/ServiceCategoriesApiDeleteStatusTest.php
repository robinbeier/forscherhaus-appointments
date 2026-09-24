<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Tests\TestCase;

require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Api_request_dto_factory.php';
require_once APPPATH . 'controllers/api/v1/Service_categories_api_v1.php';

require_once APPPATH . 'core/EA_Model.php';
require_once APPPATH . 'models/Service_categories_model.php';

/**
 * The low-level delete result is part of the HTTP success contract. A false
 * database delete must not be reported as a successful 204 response.
 */
final class ServiceCategoriesApiDeleteStatusTest extends TestCase
{
    private mixed $originalOutput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalOutput = get_instance()->output;
    }

    protected function tearDown(): void
    {
        get_instance()->output = $this->originalOutput;
        unset($_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    public function testFalseLowLevelDeleteIsA500AndKeepsTheRow(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $model = new ServiceCategoriesApiDeleteFaultModel();
        get_instance()->output = new ServiceCategoriesApiDeleteOutputDouble();
        $controller = new ServiceCategoriesApiDeleteControllerHarness();
        $controller->configure($model);

        $controller->destroy(41);

        self::assertSame(500, get_instance()->output->status);
        self::assertSame(['id' => 41, 'name' => 'synthetic category', 'description' => 'must remain'], $model->row());
    }
}

final class ServiceCategoriesApiDeleteControllerHarness extends \Service_categories_api_v1
{
    public function __construct() {}

    public function configure(ServiceCategoriesApiDeleteFaultModel $model): void
    {
        $this->service_categories_model = $model;
    }
}

final class ServiceCategoriesApiDeleteFaultModel extends \Service_categories_model
{
    public function __construct()
    {
        $this->db = new ServiceCategoriesApiDeleteDbDouble();
    }

    /** @var array<string, mixed> */
    private array $category = ['id' => 41, 'name' => 'synthetic category', 'description' => 'must remain'];

    public function get(
        array|string|null $where = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $order_by = null,
    ): array {
        return [$this->category];
    }

    /** @return array<string, mixed> */
    public function row(): array
    {
        return $this->category;
    }
}

final class ServiceCategoriesApiDeleteDbDouble
{
    public function delete(string $table, array $where): bool
    {
        return false;
    }
}

final class ServiceCategoriesApiDeleteOutputDouble extends \CI_Output
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

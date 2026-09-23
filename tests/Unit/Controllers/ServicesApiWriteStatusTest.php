<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase as ApplicationTestCase;

require_once APPPATH . 'models/Services_model.php';
require_once APPPATH . 'libraries/Request_normalizer.php';
require_once APPPATH . 'libraries/Api_request_dto_factory.php';
require_once APPPATH . 'controllers/api/v1/Services_api_v1.php';

/** In-process status-boundary coverage using model, DTO, and output doubles. */
final class ServicesApiWriteStatusTest extends ApplicationTestCase
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

    public function testValidationExceptionReturns400ForStoreAndUpdateWithoutMutation(): void
    {
        foreach (['store', 'update'] as $action) {
            $result = $this->invoke($action, new \ServiceValidationException('synthetic validation failure'));

            self::assertSame(400, $result['status'], $action);
            self::assertSame(['success' => false], $result['body'], $action);
            self::assertSame(0, $result['mutations'], $action);
            self::assertSame($result['before'], $result['after'], $action);
        }
    }

    public function testNonValidationExceptionsRemain500ForStoreAndUpdateWithoutMutation(): void
    {
        foreach ([InvalidArgumentException::class, RuntimeException::class] as $exceptionClass) {
            foreach (['store', 'update'] as $action) {
                $result = $this->invoke($action, new $exceptionClass('synthetic failure'));

                self::assertSame(500, $result['status'], $exceptionClass . ' ' . $action);
                self::assertFalse($result['body']['success'] ?? true, $exceptionClass . ' ' . $action);
                self::assertSame(0, $result['mutations'], $exceptionClass . ' ' . $action);
                self::assertSame($result['before'], $result['after'], $exceptionClass . ' ' . $action);
            }
        }
    }

    /** @return array{status:int,body:array<string,mixed>,mutations:int,before:array<string,mixed>,after:array<string,mixed>} */
    private function invoke(string $action, \Throwable $failure): array
    {
        $_SERVER['REQUEST_METHOD'] = $action === 'store' ? 'POST' : 'PUT';
        $CI = &get_instance();
        $output = new ServicesApiOutputDouble();
        $CI->output = $output;

        $model = new ServicesApiModelDouble($failure);
        $factory = new ServicesApiDtoFactoryDouble(['name' => 'synthetic service', 'duration' => 30]);
        $controller = new ServicesApiControllerHarness();
        $controller->configure($model, $factory);

        $before = $model->row();
        if ($action === 'store') {
            $controller->store();
        } else {
            $controller->update(41);
        }

        return [
            'status' => $output->status,
            'body' => json_decode($output->body, true, 512, JSON_THROW_ON_ERROR),
            'mutations' => $model->mutations,
            'before' => $before,
            'after' => $model->row(),
        ];
    }
}

final class ServicesApiControllerHarness extends \Services_api_v1
{
    public function __construct() {}

    public function configure(ServicesApiModelDouble $model, \Api_request_dto_factory $factory): void
    {
        $this->services_model = $model;
        $this->api_request_dto_factory = $factory;
    }
}

final class ServicesApiDtoFactoryDouble extends \Api_request_dto_factory
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload) {}

    public function buildEntityWritePayloadDto(): \ApiEntityWritePayloadDto
    {
        return new \ApiEntityWritePayloadDto($this->payload);
    }
}

final class ServicesApiModelDouble
{
    public int $mutations = 0;

    /** @var array<string, mixed> */
    private array $service = ['id' => 41, 'name' => 'original service', 'duration' => 30];

    public function __construct(private \Throwable $failure) {}

    /** @param array<string, mixed> $where */
    public function get(array $where): array
    {
        return [$this->service];
    }

    /** @param array<string, mixed> $service */
    public function api_decode(array &$service, ?array $original = null): void {}

    /** @param array<string, mixed> $service */
    public function save(array $service, ?array $original = null): int
    {
        throw $this->failure;
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return $this->service;
    }

    /** @param array<string, mixed> $service */
    public function api_encode(array &$service): void {}

    /** @return array<string, mixed> */
    public function row(): array
    {
        return $this->service;
    }
}

final class ServicesApiOutputDouble extends \CI_Output
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

<?php

declare(strict_types=1);

define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('PRIV_SYSTEM_SETTINGS', 'system_settings');

$controllerName = $argv[1] ?? '';
$scenario = $argv[2] ?? '';
$_SERVER['REQUEST_METHOD'] = strtoupper($argv[3] ?? 'GET');
$events = [];
$status = 200;
$headers = [];
$body = '';
$saved = [];
$saved_batches = [];

function cannot(string $verb, string $permission): bool
{
    global $scenario, $events;
    if ($verb !== 'edit' || $permission !== PRIV_SYSTEM_SETTINGS) {
        throw new RuntimeException('Unexpected authorization arguments.');
    }
    $events[] = 'auth:' . $verb . ':' . $permission;
    return $scenario === 'forbidden';
}
function response(string $content = '', int $status = 200, array $headers = []): void
{
    global $events;
    $events[] = 'response';
    $GLOBALS['body'] = $content;
    $GLOBALS['status'] = $status;
    $GLOBALS['headers'] = $headers;
}
function json_exception(Throwable $exception): void
{
    global $events;
    $events[] = 'json_exception';
    $GLOBALS['status'] = 500;
    $GLOBALS['body'] = $exception->getMessage();
}
function abort(int $code, string $message, array $responseHeaders = []): never
{
    global $events;
    $events[] = 'abort:' . $code;
    $GLOBALS['status'] = $code;
    foreach ($responseHeaders as $header) {
        [$name, $value] = explode(':', $header, 2);
        $GLOBALS['headers'][trim($name)] = trim($value);
    }
    emit_probe();
    exit(0);
}
function &get_instance(): object
{
    $instance = &$GLOBALS['probe_controller'];
    return $instance;
}
function emit_probe(): void
{
    global $events, $status, $headers, $body, $saved, $saved_batches;
    echo json_encode(compact('events', 'status', 'headers', 'body', 'saved', 'saved_batches'), JSON_THROW_ON_ERROR);
}

final class ProbeQuery
{
    public function __construct(private string $name) {}

    public function where(string $column, string $value): self
    {
        if ($column !== 'name' || !in_array($value, ['synthetic_setting', 'synthetic_second'], true)) {
            throw new RuntimeException('Unexpected settings query predicate.');
        }
        $GLOBALS['query_name'] = $value;
        $this->name = $value;
        return $this;
    }
    public function get(): self
    {
        return $this;
    }
    public function row_array(): array
    {
        return ['id' => $this->name === 'synthetic_setting' ? 41 : 42, 'name' => $this->name];
    }
}
final class ProbeDatabase
{
    private bool $active = false;
    public function trans_active(): bool
    {
        return $this->active;
    }
    public function trans_begin(): bool
    {
        $GLOBALS['events'][] = 'transaction:begin';
        $this->active = true;
        return true;
    }
    public function trans_status(): bool
    {
        return true;
    }
    public function trans_commit(): bool
    {
        $GLOBALS['events'][] = 'transaction:commit';
        $this->active = false;
        return true;
    }
    public function trans_rollback(): bool
    {
        $GLOBALS['events'][] = 'transaction:rollback';
        $this->active = false;
        return true;
    }
}
final class ProbeSettingsModel
{
    public ProbeDatabase $db;
    public function __construct()
    {
        $this->db = new ProbeDatabase();
    }

    public function query(): ProbeQuery
    {
        $GLOBALS['events'][] = 'query';
        return new ProbeQuery($GLOBALS['query_name'] ?? 'synthetic_setting');
    }
    public function validate(array $setting): void {}
    public function only(array &$setting, array $fields): void
    {
        $setting = array_intersect_key($setting, array_flip($fields));
    }
    public function optional(array &$setting, array $fields): void {}
    public function save(array $setting): int
    {
        $GLOBALS['events'][] = 'save:' . $setting['name'];
        $GLOBALS['saved'][] = $setting;
        return 42;
    }
    public function save_batch(array $settings): void
    {
        global $scenario;
        $GLOBALS['events'][] = 'save_batch:' . count($settings);
        $GLOBALS['saved_batches'][] = $settings;
        if ($scenario === 'batch_failure') {
            throw new RuntimeException('synthetic batch failure');
        }
    }
}
class Backoffice_request_dto_factory
{
    public function buildSettingsRequestDto(string $key): object
    {
        global $events;
        $events[] = 'dto:' . $key;
        return (object) [
            'settings' => [
                ['name' => 'synthetic_setting', 'value' => 'synthetic-value', 'extra' => 'drop-me'],
                ['name' => 'synthetic_second', 'value' => 'second-value', 'extra' => 'drop-me-too'],
            ],
        ];
    }
}
#[AllowDynamicProperties]
class EA_Controller
{
    public ProbeLoader $load;
    public function __construct()
    {
        $this->load = new ProbeLoader($this);
    }
}
final class ProbeLoader
{
    public function __construct(private object $controller) {}
    public function model(string $name): void
    {
        if ($name === 'settings_model') {
            $this->controller->settings_model = new ProbeSettingsModel();
        } else {
            $this->controller->{$name} = new stdClass();
        }
    }
    public function library(string $name): void
    {
        if ($name === 'backoffice_request_dto_factory') {
            $this->controller->backoffice_request_dto_factory = new Backoffice_request_dto_factory();
        } else {
            $this->controller->{$name} = new stdClass();
        }
    }
}

$controllerFile = APPPATH . 'controllers/' . $controllerName . '.php';
require_once $controllerFile;
$controller = new $controllerName();
$GLOBALS['probe_controller'] = $controller;
$controller->save();
emit_probe();

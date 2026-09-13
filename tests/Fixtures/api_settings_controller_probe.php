<?php

declare(strict_types=1);

define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('PRIV_SYSTEM_SETTINGS', 'system_settings');

$scenario = $argv[1] ?? '';
$_SERVER['REQUEST_METHOD'] = strtoupper($argv[2] ?? 'GET');
$events = [];
$status = 200;
$headers = [];
$body = '';
$saved = [];

function cannot(string $verb, string $permission): bool
{
    global $scenario, $events;
    if ($verb !== 'edit' || $permission !== PRIV_SYSTEM_SETTINGS) {
        throw new RuntimeException('Unexpected authorization probe arguments.');
    }
    $events[] = 'auth_check:' . $verb . ':' . $permission;
    return $scenario === 'forbidden';
}
function response(string $content = '', int $status = 200, array $headers = []): void
{
    global $events;
    $events[] = 'response';
    $GLOBALS['status'] = $status;
    $GLOBALS['headers'] = $headers;
    $GLOBALS['body'] = $content;
}
function json_exception(Throwable $exception): void
{
    global $events, $status, $body;
    $events[] = 'json_exception';
    $status = 500;
    $body = json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
function abort(int $code, string $message, array $responseHeaders = []): never
{
    global $events, $status, $headers, $body;
    $events[] = 'abort:' . $code;
    $status = $code;
    foreach ($responseHeaders as $header) {
        [$name, $value] = explode(':', $header, 2);
        $headers[trim($name)] = trim($value);
    }
    $body = json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    emit_probe_and_exit();
}
function &get_instance(): object
{
    $instance = &$GLOBALS['probe_controller'];
    return $instance;
}

final class ProbeQuery
{
    public function where(string $column, string $value): self
    {
        global $events;
        $events[] = 'where:' . $column . ':' . $value;
        return $this;
    }
    public function get(): self
    {
        return $this;
    }
    public function row_array(): array
    {
        return ['id' => 42, 'name' => 'api_token'];
    }
}
final class ProbeSettingsModel
{
    public function query(): ProbeQuery
    {
        $GLOBALS['events'][] = 'query';
        return new ProbeQuery();
    }
    public function save(array $setting): void
    {
        global $scenario, $events, $saved;
        $events[] = 'save:' . $setting['name'];
        $saved[] = $setting;
        if ($scenario === 'write_failure') {
            throw new RuntimeException('synthetic settings failure');
        }
    }
}
final class Backoffice_request_dto_factory
{
    public function buildSettingsRequestDto(string $key): object
    {
        global $events;
        if ($key !== 'api_settings') {
            throw new RuntimeException('Unexpected DTO key.');
        }
        $events[] = 'dto:build';
        return (object) ['settings' => [['name' => 'api_token', 'value' => 'synthetic-token']]];
    }
}
final class ProbeLoader
{
    public function __construct(private object $controller) {}
    public function model(string $name): void
    {
        if ($name !== 'settings_model') {
            throw new RuntimeException('Unexpected model.');
        }
        $this->controller->settings_model = new ProbeSettingsModel();
    }
    public function library(string $name): void
    {
        if ($name === 'backoffice_request_dto_factory') {
            $this->controller->backoffice_request_dto_factory = new Backoffice_request_dto_factory();
        }
    }
}
class EA_Controller
{
    public ProbeLoader $load;
    public ?ProbeSettingsModel $settings_model = null;
    public ?Backoffice_request_dto_factory $backoffice_request_dto_factory = null;
    public function __construct()
    {
        $this->load = new ProbeLoader($this);
    }
}
function emit_probe_and_exit(): never
{
    global $events, $status, $headers, $body, $saved;
    echo json_encode(compact('events', 'status', 'headers', 'body', 'saved'), JSON_THROW_ON_ERROR);
    exit(0);
}

require_once APPPATH . 'controllers/Api_settings.php';
$controller = new Api_settings();
$GLOBALS['probe_controller'] = $controller;
$controller->save();
emit_probe_and_exit();

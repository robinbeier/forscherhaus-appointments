<?php

declare(strict_types=1);

define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('PRIV_SYSTEM_SETTINGS', 'system_settings');

$scenario = $argv[1] ?? 'success';
$_SERVER['REQUEST_METHOD'] = strtoupper($argv[2] ?? 'POST');
$events = [];
$status = 200;
$headers = [];
$body = '';

function cannot(string $verb, string $permission): bool
{
    global $scenario, $events;
    $events[] = "auth:$verb:$permission";
    return $scenario === 'forbidden';
}
function response(string $content = '', int $status = 200, array $response_headers = []): void
{
    global $events, $body, $headers;
    $events[] = 'response';
    $body = $content;
    $headers = $response_headers;
}
function json_exception(Throwable $exception): void
{
    global $events, $status, $body;
    $events[] = 'json_exception';
    $status = 500;
    $body = $exception->getMessage();
}
function abort(int $code, string $message, array $response_headers = []): never
{
    global $events, $status, $headers;
    $events[] = "abort:$code";
    $status = $code;
    foreach ($response_headers as $header) {
        [$name, $value] = explode(':', $header, 2);
        $headers[trim($name)] = trim($value);
    }
    emit_probe();
    exit(0);
}
function emit_probe(): void
{
    global $events, $status, $body, $headers;
    echo json_encode(compact('events', 'status', 'body', 'headers'), JSON_THROW_ON_ERROR);
}

final class ProbeDatabase
{
    public bool $active = false;
    public function trans_active(): bool
    {
        return $this->active;
    }
    public function trans_begin(): bool
    {
        global $events;
        $events[] = 'begin';
        $this->active = true;
        return true;
    }
    public function trans_status(): bool
    {
        return true;
    }
    public function trans_commit(): bool
    {
        global $events;
        $events[] = 'commit';
        $this->active = false;
        return true;
    }
    public function trans_rollback(): bool
    {
        global $events;
        $events[] = 'rollback';
        $this->active = false;
        return true;
    }
}
final class ProbeProvidersModel
{
    public ProbeDatabase $db;
    public function __construct()
    {
        $this->db = new ProbeDatabase();
    }
    public function get(): array
    {
        global $events, $scenario;
        $events[] = 'providers:get';
        return $scenario === 'emptyproviders' ? [] : [['id' => 1], ['id' => 2]];
    }
    public function set_setting(int $id, string $name, mixed $value = null): void
    {
        global $events, $scenario;
        $events[] = "set:$id:$name:$value";
        if (in_array($scenario, ['failure', 'joined-failure'], true) && $id === 2) {
            throw new RuntimeException('synthetic provider failure');
        }
    }
}
final class ProbeDtoFactory
{
    public function buildEntityPayloadRequestDto(string $key): object
    {
        global $events;
        $events[] = "dto:$key";
        global $scenario;
        return (object) ['payload' => $scenario === 'emptyplan' ? [] : ['1' => ['a' => 2], '2' => ['z' => 1]]];
    }
}
class Backoffice_request_dto_factory {}
class ProbeDtoFactoryTyped extends Backoffice_request_dto_factory
{
    public function buildEntityPayloadRequestDto(string $key): object
    {
        return (new ProbeDtoFactory())->buildEntityPayloadRequestDto($key);
    }
}
class EA_Controller {}
require_once APPPATH . 'controllers/Business_settings.php';
$controller = new class extends Business_settings {
    public object $providers_model;
    public object $backoffice_request_dto_factory;
    public function __construct() {}
};
$controller->providers_model = new ProbeProvidersModel();
$controller->backoffice_request_dto_factory = new ProbeDtoFactoryTyped();
if (in_array($scenario, ['joined-success', 'joined-failure'], true)) {
    $controller->providers_model->db->trans_begin();
    array_shift($events);
}
$controller->apply_global_working_plan();
emit_probe();

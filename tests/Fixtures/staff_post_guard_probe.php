<?php
declare(strict_types=1);

define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('PRIV_USERS', 'users');
$controllerName = $argv[1];
$scenario = $argv[2];
$_SERVER['REQUEST_METHOD'] = strtoupper($argv[3]);
$events = [];
$status = 200;
$headers = [];
$body = '';
$saved = [];
function cannot(string $verb, string $permission): bool
{
    global $events, $scenario;
    $events[] = "auth:$verb:$permission";
    return $scenario === 'forbidden';
}
function abort(int $code, string $message, array $responseHeaders = []): never
{
    global $events, $status, $headers;
    $events[] = "abort:$code";
    $status = $code;
    foreach ($responseHeaders as $header) {
        [$name, $value] = explode(':', $header, 2);
        $headers[trim($name)] = trim($value);
    }
    emit();
    exit(0);
}
function json_response(mixed $value): void
{
    global $events, $body;
    $events[] = 'json_response';
    $body = json_encode($value, JSON_THROW_ON_ERROR);
}
function json_exception(Throwable $error): void
{
    global $events, $status, $body;
    $events[] = 'json_exception';
    $status = 500;
    $body = $error->getMessage();
}
function emit(): void
{
    global $events, $status, $headers, $body, $saved;
    echo json_encode(compact('events', 'status', 'headers', 'body', 'saved'), JSON_THROW_ON_ERROR);
}
class EA_Controller
{
    public ProbeModel $admins_model;
    public ProbeModel $secretaries_model;
    public Backoffice_request_dto_factory $backoffice_request_dto_factory;
}
class Backoffice_request_dto_factory
{
    public function buildEntityPayloadRequestDto(string $key): object
    {
        global $events, $action;
        $events[] = "dto:$key";
        return (object) [
            'payload' => [
                ...$action === 'update' ? ['id' => 41] : [],
                'first_name' => 'Synthetic',
                'last_name' => 'Staff',
                'email' => 'staff@synthetic.invalid',
                'settings' => ['username' => 'synthetic'],
            ],
        ];
    }
    public function buildEntityIdRequestDto(string $key): object
    {
        global $events;
        $events[] = "dto:$key";
        return (object) ['id' => 41];
    }
}
class ProbeModel
{
    public function only(array &$value, array $fields): void
    {
        $GLOBALS['events'][] = 'only:' . json_encode($fields, JSON_THROW_ON_ERROR);
        $value = array_intersect_key($value, array_flip($fields));
    }
    public function optional(array &$value, array $fields): void
    {
        $events = &$GLOBALS['events'];
        $events[] = 'optional:' . json_encode($fields, JSON_THROW_ON_ERROR);
    }
    public function save(array $value): int
    {
        $GLOBALS['events'][] = 'save:' . json_encode($value, JSON_THROW_ON_ERROR);
        $GLOBALS['saved'][] = $value;
        return 42;
    }
    public function find(int $id): array
    {
        $GLOBALS['events'][] = "find:$id";
        return ['id' => $id];
    }
    public function delete(int $id): void
    {
        $GLOBALS['events'][] = "delete:$id";
    }
}
$action = $argv[4];
$model = new ProbeModel();
require_once APPPATH . 'controllers/' . $controllerName . '.php';
$controller = (new ReflectionClass($controllerName))->newInstanceWithoutConstructor();
$controller->{$controllerName === 'Admins' ? 'admins_model' : 'secretaries_model'} = $model;
$controller->backoffice_request_dto_factory = new Backoffice_request_dto_factory();
$controller->{$argv[4]}();
emit();

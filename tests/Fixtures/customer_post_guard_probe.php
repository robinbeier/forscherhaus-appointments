<?php
declare(strict_types=1);
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('PRIV_CUSTOMERS', 'customers');
define('DB_SLUG_ADMIN', 'admin');
$action = $argv[1];
$scenario = $argv[2];
$_SERVER['REQUEST_METHOD'] = strtoupper($argv[3]);
$events = [];
$status = 200;
$headers = [];
$body = '';
$saved = [];
function cannot(string $v, string $p): bool
{
    global $events, $scenario;
    $events[] = "auth:$v:$p";
    return $scenario === 'forbidden';
}
function session(string $key): mixed
{
    global $scenario, $events;
    $events[] = 'session:' . $key;
    return $key === 'user_id' ? 17 : (str_starts_with($scenario, 'nonadmin-') ? 'secretary' : DB_SLUG_ADMIN);
}
function setting(string $key): mixed
{
    $GLOBALS['events'][] = 'setting:' . $key;
    return $GLOBALS['scenario'] === 'nonadmin-limited';
}
function abort(int $code, string $message = '', array $h = []): never
{
    global $events, $status, $headers;
    $events[] = "abort:$code";
    $status = $code;
    foreach ($h as $x) {
        [$n, $v] = explode(':', $x, 2);
        $headers[trim($n)] = trim($v);
    }
    emit();
    exit(0);
}
function json_response(mixed $v, int $s = 200): void
{
    global $events, $body, $status;
    $events[] = 'json_response';
    $status = $s;
    $body = json_encode($v, JSON_THROW_ON_ERROR);
}
function json_exception(Throwable $e): void
{
    global $events, $body, $status;
    $events[] = 'json_exception';
    $status = 500;
    $body = $e->getMessage();
}
function emit(): void
{
    global $events, $status, $headers, $body, $saved;
    echo json_encode(compact('events', 'status', 'headers', 'body', 'saved'), JSON_THROW_ON_ERROR);
}
class EA_Controller
{
    public ProbeModel $customers_model;
    public ProbePermissions $permissions;
    public Backoffice_request_dto_factory $backoffice_request_dto_factory;
}
class Backoffice_request_dto_factory
{
    public function buildEntityPayloadRequestDto(string $k): object
    {
        global $events, $action, $scenario;
        $events[] = "dto:$k";
        return (object) [
            'payload' => array_filter(
                [
                    'id' => $action === 'update' || $scenario === 'existing-id' ? 41 : null,
                    'first_name' => 'Synthetic',
                    'last_name' => 'Customer',
                    'email' => 'customer@synthetic.invalid',
                ],
                static fn($v) => $v !== null,
            ),
        ];
    }
    public function buildEntityIdRequestDto(string $k): object
    {
        global $events;
        $events[] = "dto:$k";
        return (object) ['id' => 41];
    }
}
class ProbePermissions
{
    public function has_customer_access(int $u, int $c): bool
    {
        global $events, $scenario;
        $events[] = "access:$u:$c";
        return $scenario !== 'noaccess';
    }
}
class ProbeModel
{
    public function only(array &$v, array $f): void
    {
        $GLOBALS['events'][] = 'only:' . json_encode($f, JSON_THROW_ON_ERROR);
        $v = array_intersect_key($v, array_flip($f));
    }
    public function optional(array &$v, array $f): void
    {
        $GLOBALS['events'][] = 'optional:' . json_encode($f, JSON_THROW_ON_ERROR);
    }
    public function save(array $v): int
    {
        $GLOBALS['events'][] = 'save:' . json_encode($v, JSON_THROW_ON_ERROR);
        $GLOBALS['saved'][] = $v;
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
$GLOBALS['action'] = $action;
require_once APPPATH . 'controllers/Customers.php';
$c = (new ReflectionClass('Customers'))->newInstanceWithoutConstructor();
$c->customers_model = new ProbeModel();
$c->permissions = new ProbePermissions();
$c->backoffice_request_dto_factory = new Backoffice_request_dto_factory();
$c->{$action}();
emit();

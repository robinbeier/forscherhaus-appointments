<?php
declare(strict_types=1);

define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('PRIV_BLOCKED_PERIODS', 'blocked_periods');
define('SYNTHETIC_USER_ID', 9001);
$action = $argv[1];
$scenario = $argv[2];
$_SERVER['REQUEST_METHOD'] = strtoupper($argv[3]);
$events = [];
$status = 200;
$headers = [];
$body = '';
$saved = [];

function session(string $key): mixed
{
    return $key === 'user_id' ? SYNTHETIC_USER_ID : null;
}

function cannot(string $verb, string $permission, int $user_id): bool
{
    global $events, $scenario;
    $events[] = "auth:$verb:$permission:$user_id";
    return $scenario === 'forbidden' ||
        ($scenario === 'denied-after-lock' &&
            count(array_filter($events, static fn(string $event): bool => str_starts_with($event, 'auth:'))) > 1);
}

function abort(int $code, string $message = '', array $responseHeaders = []): never
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
    public ProbeModel $blocked_periods_model;
    public Backoffice_request_dto_factory $backoffice_request_dto_factory;
    public ProbeDb $db;
}

class ProbeDb
{
    public function trans_active(): bool
    {
        return false;
    }

    public function trans_begin(): bool
    {
        $GLOBALS['events'][] = 'transaction:begin';
        return true;
    }

    public function query(string $sql, array $params): ProbeQueryResult|false
    {
        $GLOBALS['events'][] = 'lock:user:' . $params[0];
        return new ProbeQueryResult();
    }

    public function trans_status(): bool
    {
        return true;
    }

    public function trans_commit(): bool
    {
        $GLOBALS['events'][] = 'transaction:commit';
        return true;
    }

    public function trans_rollback(): void
    {
        $GLOBALS['events'][] = 'transaction:rollback';
    }

    public function dbprefix(string $table): string
    {
        return $table;
    }
}

class ProbeQueryResult
{
    public function row_array(): array
    {
        return ['id' => SYNTHETIC_USER_ID];
    }
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
                'name' => 'Synthetic Block',
                'start_datetime' => '2035-01-15 09:00:00',
                'end_datetime' => $action === 'update' ? '2035-01-15 11:00:00' : '2035-01-15 10:00:00',
                'notes' => 'synthetic',
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
        $GLOBALS['events'][] = 'optional:' . json_encode($fields, JSON_THROW_ON_ERROR);
    }

    public function save(array $value): int
    {
        $GLOBALS['events'][] = 'save:' . json_encode($value, JSON_THROW_ON_ERROR);
        if ($GLOBALS['scenario'] === 'model-failure') {
            throw new RuntimeException('synthetic model failure');
        }
        $GLOBALS['saved'][] = $value;
        return (int) ($value['id'] ?? 42);
    }

    public function find(int $id): array
    {
        $GLOBALS['events'][] = "find:$id";
        return ['id' => $id];
    }

    public function delete(int $id): void
    {
        $GLOBALS['events'][] = "delete:$id";
        if ($GLOBALS['scenario'] === 'model-failure') {
            throw new RuntimeException('synthetic model failure');
        }
    }
}

$GLOBALS['action'] = $action;
require_once APPPATH . 'controllers/Blocked_periods.php';
$controller = (new ReflectionClass('Blocked_periods'))->newInstanceWithoutConstructor();
$controller->blocked_periods_model = new ProbeModel();
$controller->backoffice_request_dto_factory = new Backoffice_request_dto_factory();
$controller->db = new ProbeDb();
$controller->{$action}();
emit();

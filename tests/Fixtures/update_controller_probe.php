<?php

declare(strict_types=1);

define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('PRIV_SYSTEM_SETTINGS', 'system_settings');

$scenario = $argv[1] ?? '';
$events = [];
$viewVars = [];
$renderedView = '';
$status = 200;
$headers = [];

function session(string $name): mixed
{
    global $scenario;
    return $name === 'user_id' && $scenario !== 'anonymous' ? 17 : null;
}
function cannot(string $verb, string $permission): bool
{
    global $scenario, $events;
    if ($verb !== 'edit' || $permission !== PRIV_SYSTEM_SETTINGS) {
        throw new RuntimeException('Unexpected authorization probe arguments.');
    }
    $events[] = 'auth_check:' . $verb . ':' . $permission;
    return in_array($scenario, ['anonymous', 'forbidden'], true);
}
function redirect(string $target): void
{
    global $events, $status, $headers;
    $events[] = 'redirect:' . $target;
    $status = 302;
    $headers['Location'] = '/' . $target;
}
function abort(int $code, string $message, array $responseHeaders = []): never
{
    global $events, $status, $headers, $viewVars, $renderedView;
    $controller = $GLOBALS['probe_controller'] ?? null;
    $events[] = 'abort:' . $code;
    $status = $code;
    $headers = [];
    foreach ($responseHeaders as $header) {
        if (is_string($header) && str_contains($header, ':')) {
            [$name, $value] = explode(':', $header, 2);
            $headers[trim($name)] = trim($value);
        }
    }
    echo json_encode(
        [
            'events' => $events,
            'view' => $viewVars,
            'rendered' => $renderedView,
            'instance' => is_object($controller) && $controller->instance !== null,
            'status' => $status,
            'headers' => $headers,
        ],
        JSON_THROW_ON_ERROR,
    );
    exit(0);
}
function html_vars(array $vars): void
{
    global $viewVars;
    $viewVars = $vars;
}
function vars(string $key): mixed
{
    global $viewVars;
    return $viewVars[$key] ?? null;
}
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function asset_url(string $path): string
{
    return '/assets/' . $path;
}
function site_url(string $path): string
{
    return '/' . $path;
}
function lang(string $key): string
{
    return 'Backend';
}

final class ProbeSecurity
{
    public function get_csrf_token_name(): string
    {
        return 'probe_csrf_name';
    }
    public function get_csrf_hash(): string
    {
        return 'probe_csrf_hash';
    }
}

final class ProbeLoader
{
    public function __construct(private object $controller) {}
    public function model(string $name): void
    {
        global $events;
        $events[] = 'model:' . $name;
    }
    public function library(string $name): void
    {
        global $events;
        $events[] = 'library:' . $name;
        $this->controller->instance = new ProbeInstance();
    }
    public function view(string $name): void
    {
        global $events, $renderedView;
        $events[] = 'view:' . $name;
        ob_start();
        require APPPATH . 'views/pages/update.php';
        $renderedView = (string) ob_get_clean();
    }
}

final class ProbeInstance
{
    public function migrate(): void
    {
        global $events, $scenario;
        $events[] = 'migrate';
        if ($scenario === 'migration_failure') {
            throw new RuntimeException('<b>synthetic failure & note</b>');
        }
    }
}

class EA_Controller
{
    public ProbeLoader $load;
    public ProbeSecurity $security;
    public mixed $instance = null;
    public function __construct()
    {
        $this->security = new ProbeSecurity();
        $this->load = new ProbeLoader($this);
        $GLOBALS['events'][] = 'parent_construct';
    }
}

require_once APPPATH . 'controllers/Update.php';
$_SERVER['REQUEST_METHOD'] = strtoupper($argv[2] ?? 'GET');
$controller = new Update();
$GLOBALS['probe_controller'] = $controller;
$GLOBALS['events'][] = 'controller_constructed';
$controller->index();

echo json_encode(
    [
        'events' => $events,
        'view' => $viewVars,
        'rendered' => $renderedView,
        'instance' => $controller->instance !== null,
        'status' => $status,
        'headers' => $headers,
    ],
    JSON_THROW_ON_ERROR,
);

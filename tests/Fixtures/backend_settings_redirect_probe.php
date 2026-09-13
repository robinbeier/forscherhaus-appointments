<?php

declare(strict_types=1);

define('BASEPATH', __DIR__);

class EA_Controller {}

$_SERVER['REQUEST_METHOD'] = $argv[2];
$_POST = ['settings' => [['name' => 'synthetic', 'value' => 'example']], 'csrf_token' => 'synthetic-token'];

function redirect(string $uri = '', string $method = 'auto', ?int $code = null): never
{
    echo json_encode(
        [
            'uri' => $uri,
            'method' => $method,
            'code' => $code,
            'request_method' => $_SERVER['REQUEST_METHOD'],
            'post' => $_POST,
        ],
        JSON_THROW_ON_ERROR,
    );
    exit(0);
}

require dirname(__DIR__, 2) . '/application/controllers/Backend_api.php';
(new Backend_api())->{$argv[1]}();
throw new RuntimeException('Legacy action did not redirect.');

<?php

declare(strict_types=1);

$repoRoot = $argv[1] ?? '';
if ($repoRoot === '' || !is_dir($repoRoot)) {
    fwrite(STDERR, "missing repository root\n");
    exit(2);
}

$probeRoot = sys_get_temp_dir() . '/fh-csp-hook-probe-' . bin2hex(random_bytes(8));
mkdir($probeRoot . '/application/config', 0700, true);
mkdir($probeRoot . '/application/core', 0700, true);

register_shutdown_function(static function () use ($probeRoot): void {
    foreach (
        [$probeRoot . '/application/config/hooks.php', $probeRoot . '/application/core/Csp_report_only.php']
        as $path
    ) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    @rmdir($probeRoot . '/application/config');
    @rmdir($probeRoot . '/application/core');
    @rmdir($probeRoot . '/application');
    @rmdir($probeRoot);
});

define('BASEPATH', __DIR__);
define('APPPATH', $probeRoot . '/application/');
copy($repoRoot . '/application/config/hooks.php', APPPATH . 'config/hooks.php');
file_put_contents(
    APPPATH . 'core/Csp_report_only.php',
    <<<'PHP'
    <?php
    final class Csp_report_only
    {
        public static function load(?string $path = null): ?array
        {
            return ['enabled' => true];
        }

        public static function responseContentType(object $output, array $server): ?string
        {
            return 'text/html';
        }

        public static function policyForRequest(array $server, array $config, ?string $contentType): ?array
        {
            return ['header' => 'Content-Security-Policy-Report-Only: default-src \'self\''];
        }
    }
    PHP
    ,
);

final class MagicOutput
{
    /** @var list<string> */
    public array $headers = [];

    public function set_header(string $header): void
    {
        $this->headers[] = $header;
    }
}

final class MagicController
{
    private MagicOutput $outputService;

    public function __construct()
    {
        $this->outputService = new MagicOutput();
    }

    public function __get(string $name): mixed
    {
        return $name === 'output' ? $this->outputService : null;
    }

    public function outputHeaders(): array
    {
        return $this->outputService->headers;
    }
}

$GLOBALS['probe_controller'] = new MagicController();
function get_instance(): object
{
    return $GLOBALS['probe_controller'];
}

$hook = [];
require APPPATH . 'config/hooks.php';
$hook['post_controller']();
echo json_encode(['headers' => $GLOBALS['probe_controller']->outputHeaders()], JSON_THROW_ON_ERROR);

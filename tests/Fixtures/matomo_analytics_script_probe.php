<?php

declare(strict_types=1);

$url = (string) ($argv[1] ?? '');
$siteId = (string) ($argv[2] ?? '1');
$matomo_analytics_url = (string) ($argv[3] ?? '');

eval('class Config { public const MATOMO_ANALYTICS_URL = ' . var_export($url, true) . '; }');

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$matomo_analytics_site_id = $siteId;
require dirname(__DIR__, 2) . '/application/views/components/matomo_analytics_script.php';

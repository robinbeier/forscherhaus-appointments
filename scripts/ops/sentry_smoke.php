#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/application/bootstrap/SentryBootstrap.php';

$send = getenv('SENTRY_SMOKE_SEND') === '1';

if (!$send) {
    echo "DRY_RUN sentry_smoke=ready send=disabled enable_with=SENTRY_SMOKE_SEND=1\n";
    exit(0);
}

$environment = trim((string) (getenv('APP_ENV') ?: getenv('CI_ENV') ?: 'production'));
$releaseFile = dirname(__DIR__, 2) . '/_RELEASE';
$options = SentryBootstrap::buildOptionsFromGlobals($environment, $releaseFile, $_SERVER);

if ($options === []) {
    fwrite(STDERR, "ERROR sentry_smoke=disabled reason=missing_sentry_dsn\n");
    exit(2);
}

\Sentry\init($options);

SentryBootstrap::captureException(
    new RuntimeException('synthetic sentry smoke'),
    [
        'area' => 'sentry_smoke',
        'operation' => 'delivery_smoke',
    ],
    [
        'smoke_id' => 'ROB-383',
        'request_uri' => '/ops/sentry-smoke?token=synthetic-secret',
    ],
);

\Sentry\flush(2.0);

echo "OK sentry_smoke=sent synthetic_event=1\n";


?>

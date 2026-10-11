<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'core/Provider_ui_smoke_access_policy.php';
require_once APPPATH . 'libraries/Provider_ui_smoke_fixture.php';

final class ProviderUiSmokeTestCliAdmissionGuard implements Provider_ui_smoke_admission_guard
{
    public function enter(string $action, string $credentialFile, string $stateFile): void {}

    public function leave(): void {}
}

$action = $argv[1] ?? '';
$credentialFile = $argv[2] ?? '';
$stateFile = $argv[3] ?? '';

if (!in_array($action, ['install', 'verify', 'activate', 'deactivate', 'remove'], true)) {
    fwrite(STDERR, "invalid test lifecycle action\n");
    exit(64);
}

try {
    $fixture = new Provider_ui_smoke_fixture(new ProviderUiSmokeTestCliAdmissionGuard());
    $state = $fixture->run($action, $credentialFile, $stateFile);
    fwrite(STDOUT, 'provider_ui_smoke action=' . $action . ' state=' . $state . ' result=ok' . PHP_EOL);
} catch (Throwable) {
    fwrite(STDOUT, 'provider_ui_smoke action=' . $action . ' state=error result=error' . PHP_EOL);
    exit(1);
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class PrePrCiComposeReadinessTest extends TestCase
{
    public function testQuickGateStartsPhpFpmAndUsesExecBasedSeedReadiness(): void
    {
        $script = $this->readScript('scripts/ci/pre_pr_quick.sh');

        self::assertStringContainsString('ci_docker_compose up -d mysql php-fpm', $script);
        self::assertStringContainsString('ci_docker_wait_for_service_exec php-fpm "pre-pr-quick" php -v', $script);
        self::assertStringContainsString(
            'ci_docker_wait_for_easyappointments_mysql_connectivity "pre-pr-quick"',
            $script,
        );
        self::assertStringContainsString(
            'CI_DOCKER_INSTALL_SEED_MAX_ATTEMPTS=5 ci_docker_install_seed_instance "pre-pr-quick" exec -T php-fpm php index.php console install',
            $script,
        );
    }

    public function testFullGateUsesExecBasedSeedReadinessAndScopedRetryBudget(): void
    {
        $script = $this->readScript('scripts/ci/pre_pr_full.sh');

        self::assertStringContainsString('ci_docker_wait_for_service_exec php-fpm "pre-pr-full" php -v', $script);
        self::assertStringContainsString(
            'ci_docker_wait_for_easyappointments_mysql_connectivity "pre-pr-full"',
            $script,
        );
        self::assertStringContainsString(
            'CI_DOCKER_INSTALL_SEED_MAX_ATTEMPTS=5 ci_docker_install_seed_instance "pre-pr-full" exec -T php-fpm php index.php console install',
            $script,
        );
    }

    public function testSharedSeedInstallHelperKeepsDefaultRetryBudget(): void
    {
        $helper = $this->readScript('scripts/ci/docker_compose_helpers.sh');

        self::assertStringContainsString('local max_attempts="${CI_DOCKER_INSTALL_SEED_MAX_ATTEMPTS:-3}"', $helper);
    }

    public function testPreCommitCleansUpWhenComposeUpFailsAfterPartialStartup(): void
    {
        $script = $this->readScript('scripts/hooks/pre-commit');
        $ensureStart = $this->extractFunction($script, 'ensure_stack_started');
        $cleanup = $this->extractFunction($script, 'cleanup_stack');
        $tempDir = sys_get_temp_dir() . '/pre-commit-cleanup-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tempDir));
        $logPath = $tempDir . '/events.log';
        $fixture = $tempDir . '/fixture.sh';
        $contents = <<<BASH
        #!/usr/bin/env bash
        set -euo pipefail
        STACK_STARTED=0
        IN_CONTAINER=0
        ci_docker_compose() { echo "compose:\$*" >>"\${LOG_PATH}"; return 23; }
        ci_docker_cleanup_stack() { echo cleanup >>"\${LOG_PATH}"; }
        {$ensureStart}
        {$cleanup}
        trap cleanup_stack EXIT
        ensure_stack_started
        BASH;
        self::assertNotFalse(file_put_contents($fixture, $contents));
        chmod($fixture, 0755);

        $output = [];
        $command = 'LOG_PATH=' . escapeshellarg($logPath) . ' ' . escapeshellarg($fixture) . ' 2>&1';
        exec($command, $output, $exitCode);

        self::assertSame(23, $exitCode);
        self::assertSame(['compose:up -d --no-deps php-fpm', 'cleanup'], file($logPath, FILE_IGNORE_NEW_LINES));

        unlink($fixture);
        unlink($logPath);
        rmdir($tempDir);
    }

    public function testFullGateCleansUpWhenItsFirstPostQuickComposeFails(): void
    {
        $script = $this->readScript('scripts/ci/pre_pr_full.sh');
        $quick = strpos($script, 'bash ./scripts/ci/pre_pr_quick.sh');
        $trap = strpos($script, 'trap cleanup_stack EXIT', $quick);
        $firstCompose = strpos($script, 'ci_docker_compose run --rm php-fpm composer', $quick);

        self::assertNotFalse($quick);
        self::assertNotFalse($trap);
        self::assertNotFalse($firstCompose);
        self::assertLessThan($firstCompose, $trap);

        $cleanup = $this->extractFunction($script, 'cleanup_stack');
        $afterQuick = strpos($script, "\n", $quick) + 1;
        $afterCompose = strpos($script, "\n", $firstCompose);
        $startup = substr($script, $afterQuick, $afterCompose - $afterQuick);
        $tempDir = sys_get_temp_dir() . '/pre-pr-full-cleanup-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tempDir));
        $logPath = $tempDir . '/events.log';
        $fixture = $tempDir . '/fixture.sh';
        $contents = <<<BASH
        #!/usr/bin/env bash
        set -euo pipefail
        CI_DOCKER_COMPOSE_PROJECT_NAME=fixture-project
        PHPSTAN_REQUEST_CONTRACTS_L1_SCRIPT=phpstan:request-contracts:l1
        echo_section() { :; }
        ci_docker_compose() { echo "compose:\$*:project=\${CI_DOCKER_COMPOSE_PROJECT_NAME}" >>"\${LOG_PATH}"; return 17; }
        ci_docker_cleanup_stack() { echo "cleanup:project=\${CI_DOCKER_COMPOSE_PROJECT_NAME}" >>"\${LOG_PATH}"; }
        {$cleanup}
        {$startup}
        BASH;
        self::assertNotFalse(file_put_contents($fixture, $contents));
        chmod($fixture, 0755);

        $output = [];
        $command = 'LOG_PATH=' . escapeshellarg($logPath) . ' ' . escapeshellarg($fixture) . ' 2>&1';
        exec($command, $output, $exitCode);

        self::assertSame(17, $exitCode);
        self::assertSame(
            [
                'compose:run --rm php-fpm composer phpstan:request-contracts:l1:project=fixture-project',
                'cleanup:project=fixture-project',
            ],
            file($logPath, FILE_IGNORE_NEW_LINES),
        );

        unlink($fixture);
        unlink($logPath);
        rmdir($tempDir);
    }

    private function readScript(string $relativePath): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../' . $relativePath);

        self::assertNotFalse($contents);

        return $contents;
    }

    private function extractFunction(string $script, string $name): string
    {
        $start = strpos($script, $name . '() {');
        self::assertNotFalse($start);
        $end = strpos($script, "\n}\n", $start);
        self::assertNotFalse($end);

        return substr($script, $start, $end - $start + 3);
    }
}

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

    public function testSharedSeedInstallHelperKeepsDefaultRetryBudgetForOtherCallers(): void
    {
        $helper = $this->readScript('scripts/ci/docker_compose_helpers.sh');
        $hook = $this->readScript('scripts/hooks/pre-commit');

        self::assertStringContainsString('local max_attempts="${CI_DOCKER_INSTALL_SEED_MAX_ATTEMPTS:-3}"', $helper);
        self::assertStringNotContainsString('CI_DOCKER_INSTALL_SEED_MAX_ATTEMPTS=5', $hook);
        self::assertStringContainsString(
            'ci_docker_install_seed_instance "pre-commit" run --rm php-fpm php index.php console install',
            $hook,
        );
    }

    private function readScript(string $relativePath): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../' . $relativePath);

        self::assertNotFalse($contents);

        return $contents;
    }
}

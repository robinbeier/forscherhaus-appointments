<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\CustomersUiSmokeContract;

require_once __DIR__ . '/../../../scripts/release-gate/lib/CustomersUiSmokeContract.php';

final class CustomersUiSmokeGateContractTest extends TestCase
{
    public function testCredentialAndRoleContractMatchesApplicationPolicy(): void
    {
        $policy = $this->readRepoFile('application/core/Customers_ui_smoke_access_policy.php');
        $contract = $this->readRepoFile('scripts/release-gate/lib/CustomersUiSmokeContract.php');

        foreach ([...CustomersUiSmokeContract::USERNAMES_BY_ROLE, CustomersUiSmokeContract::SEARCH_MARKER] as $marker) {
            self::assertStringContainsString($marker, $policy);
            self::assertStringContainsString($marker, $contract);
        }

        self::assertSame(['admin', 'provider', 'secretary'], CustomersUiSmokeContract::AUTHORIZED_ROLES);
        self::assertContains('customer_filter_providers', CustomersUiSmokeContract::FORBIDDEN_KEYS);
        self::assertContains('google_client_secret', CustomersUiSmokeContract::FORBIDDEN_KEYS);
        self::assertContains('caldav_password', CustomersUiSmokeContract::FORBIDDEN_KEYS);
        self::assertContains('customer_filter_providers', CustomersUiSmokeContract::FORBIDDEN_RESPONSE_MARKERS);
        self::assertContains('webhook_secret', CustomersUiSmokeContract::FORBIDDEN_RESPONSE_MARKERS);
    }

    public function testGateUsesPrivateStorageStateAndAlwaysRemovesArtifacts(): void
    {
        $gate = $this->readRepoFile('scripts/release-gate/customers_ui_smoke.php');

        self::assertStringContainsString("['open', 'about:blank']", $gate);
        self::assertStringContainsString("['state-load', \$statePath]", $gate);
        self::assertStringContainsString("\$client->get('dashboard')", $gate);
        self::assertStringContainsString("CustomersUiSmokeContract::SEARCH_MARKER . '-not-allowed'", $gate);
        self::assertStringContainsString("putenv('PLAYWRIGHT_MCP_OUTPUT_DIR=' . \$tempDirectory)", $gate);
        self::assertStringContainsString('customersUiSmokeFinalizeCleanup(', $gate);
        self::assertStringNotContainsString("['screenshot'", $gate);
        self::assertStringNotContainsString("['network'", $gate);
        self::assertStringNotContainsString("['tracing-start'", $gate);
        self::assertStringNotContainsString("'stdout' =>", $gate);
        self::assertStringNotContainsString("'stderr' =>", $gate);
    }

    public function testPlaywrightFlowAllowsOnlyCustomersAssetsAndExactEmptySearches(): void
    {
        $snippet = $this->readRepoFile('scripts/release-gate/playwright/customers_ui_smoke.js');

        self::assertStringContainsString("context.route('**/*', routeHandler)", $snippet);
        self::assertStringContainsString("route.abort('blockedbyclient')", $snippet);
        self::assertStringContainsString("['', config.search_marker].includes(values.keyword)", $snippet);
        self::assertStringContainsString('script_vars_safe', $snippet);
        self::assertStringContainsString('dom_safe', $snippet);
        self::assertStringContainsString('response_bodies_safe', $snippet);
        self::assertStringContainsString('config.forbidden_response_markers', $snippet);
        self::assertStringContainsString('initial_search_empty', $snippet);
        self::assertStringContainsString('synthetic_search_empty', $snippet);
        self::assertStringNotContainsString('page.screenshot', $snippet);
        self::assertStringNotContainsString('context.tracing', $snippet);
        self::assertStringNotContainsString('error.message', $snippet);
        self::assertStringNotContainsString('message.text()', $snippet);
    }

    public function testPrivateTempDirectoryCleanupRejectsSymlinksAndRemovesFiles(): void
    {
        $directory = CustomersUiSmokeContract::createPrivateTempDirectory();
        $file = $directory . '/state.json';
        self::assertNotFalse(file_put_contents($file, '{}'));
        self::assertTrue(chmod($file, 0600));
        self::assertTrue(CustomersUiSmokeContract::removePrivateTempDirectory($directory));
        self::assertDirectoryDoesNotExist($directory);

        $outside = tempnam(sys_get_temp_dir(), 'fh-customers-ui-smoke-outside-');
        self::assertIsString($outside);
        $link = sys_get_temp_dir() . '/fh-customers-ui-smoke-' . bin2hex(random_bytes(4));
        self::assertTrue(symlink($outside, $link));
        self::assertFalse(CustomersUiSmokeContract::removePrivateTempDirectory($link));
        unlink($link);
        unlink($outside);
    }

    private function readRepoFile(string $relativePath): string
    {
        $content = file_get_contents(__DIR__ . '/../../../' . $relativePath);
        self::assertIsString($content);

        return $content;
    }
}

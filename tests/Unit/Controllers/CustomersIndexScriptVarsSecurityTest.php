<?php

namespace Tests\Unit\Controllers;

use Customers;
use Tests\TestCase;

require_once APPPATH . 'controllers/Customers.php';

final class CustomersIndexScriptVarsSecurityTest extends TestCase
{
    private const PROVIDER_CREDENTIAL = 'rob-434-private-caldav-credential';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'html_vars' => [],
            'script_vars' => [],
            'layout' => [
                'filename' => 'test-layout',
                'sections' => [],
                'tmp' => [],
            ],
        ]);

        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        config([
            'html_vars' => [],
            'script_vars' => [],
            'layout' => [
                'filename' => 'test-layout',
                'sections' => [],
                'tmp' => [],
            ],
        ]);

        session([
            'role_slug' => null,
            'user_id' => null,
        ]);

        parent::tearDown();
    }

    public function testIndexDoesNotExposeProviderCredentialsThroughWindowVars(): void
    {
        $controller = $this->createController(self::PROVIDER_CREDENTIAL);

        $controller->index();

        $windowVarsScript = $this->renderWindowVarsScript();

        $this->assertStringContainsString('window.vars = (function () {', $windowVarsScript);
        $this->assertStringContainsString('"role_slug":"admin"', $windowVarsScript);
        $this->assertArrayNotHasKey('customer_filter_providers', script_vars());
        $this->assertStringNotContainsString('customer_filter_providers', $windowVarsScript);
        $this->assertStringNotContainsString(self::PROVIDER_CREDENTIAL, $windowVarsScript);
    }

    private function createController(string $providerCredential): Customers
    {
        return new class ($providerCredential) extends Customers {
            public object $accounts;
            public $load;
            public object $providers_model;
            public object $roles_model;
            public object $secretaries_model;
            public object $timezones;

            public function __construct(string $providerCredential)
            {
                $this->accounts = new class {
                    public function get_user_display_name(int $userId): string
                    {
                        return 'Administrator';
                    }
                };
                $this->load = new class {
                    public function view(string $view): void {}
                };
                $this->providers_model = new class ($providerCredential) {
                    public function __construct(private readonly string $providerCredential) {}

                    public function get(
                        array|string|null $where = null,
                        ?int $limit = null,
                        ?int $offset = null,
                        ?string $orderBy = null,
                    ): array {
                        return [
                            [
                                'id' => 42,
                                'first_name' => 'Private',
                                'last_name' => 'Provider',
                                'email' => 'private-provider@example.test',
                                'settings' => [
                                    'caldav_password' => $this->providerCredential,
                                ],
                            ],
                        ];
                    }
                };
                $this->roles_model = new class {
                    public function get_permissions_by_slug(string $roleSlug): array
                    {
                        return [];
                    }
                };
                $this->secretaries_model = new class {
                    public function find(int $userId): array
                    {
                        return ['providers' => []];
                    }
                };
                $this->timezones = new class {
                    public function to_array(): array
                    {
                        return ['UTC' => 'UTC'];
                    }

                    public function to_grouped_array(): array
                    {
                        return ['Etc' => ['UTC' => 'UTC']];
                    }
                };
            }
        };
    }

    private function renderWindowVarsScript(): string
    {
        ob_start();
        include APPPATH . 'views/components/js_vars_script.php';

        return (string) ob_get_clean();
    }
}

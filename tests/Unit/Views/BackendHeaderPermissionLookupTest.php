<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\TestCase;

/** Regression coverage for the shared backend header permission snapshot. */
final class BackendHeaderPermissionLookupTest extends TestCase
{
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConfig = [
            'html_vars' => config('html_vars'),
            'script_vars' => config('script_vars'),
            'layout' => config('layout'),
        ];
        config([
            'html_vars' => ['role_slug' => DB_SLUG_PROVIDER, 'user_display_name' => 'Synthetic Admin'],
            'script_vars' => [],
            'layout' => ['filename' => 'test-layout', 'sections' => [], 'tmp' => []],
        ]);
        $admin = get_instance()
            ->db->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles')
            ->where('roles.slug', DB_SLUG_ADMIN)
            ->get()
            ->row_array();
        self::assertNotNull($admin);
        session(['role_slug' => DB_SLUG_PROVIDER, 'user_id' => (int) $admin['id']]);
    }

    protected function tearDown(): void
    {
        config($this->originalConfig);
        session(['role_slug' => null, 'user_id' => null]);

        parent::tearDown();
    }

    public function testHeaderLoadsRolePermissionsOnceForAllNavigationChecks(): void
    {
        $db = get_instance()->db;
        $before = count($db->queries);

        ob_start();
        include APPPATH . 'views/components/backend_header.php';
        $html = (string) ob_get_clean();

        $roleQueries = array_values(
            array_filter(
                array_slice($db->queries, $before),
                static fn(string $query): bool => preg_match('/\b(?:FROM|JOIN)\s+`?[a-z_]*roles`?/i', $query) === 1,
            ),
        );

        self::assertCount(1, $roleQueries, 'The header should reuse one permission lookup for its navigation checks.');
        self::assertStringContainsString('href="' . site_url('general_settings') . '"', $html);
        self::assertStringNotContainsString('href="' . site_url('account') . '"', $html);
        self::assertStringContainsString('href="' . site_url('logout') . '"', $html);
    }
}

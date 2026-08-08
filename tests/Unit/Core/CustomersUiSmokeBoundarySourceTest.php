<?php

namespace Tests\Unit\Core;

use Tests\TestCase;

final class CustomersUiSmokeBoundarySourceTest extends TestCase
{
    public function testCentralBoundaryCoversSessionLoginBasicAuthLeaseAndRouteAllowlist(): void
    {
        $source = file_get_contents(APPPATH . 'core/EA_Controller.php');
        $login = file_get_contents(APPPATH . 'controllers/Login.php');

        $this->assertIsString($source);
        $this->assertIsString($login);
        $this->assertStringContainsString('$this->enforce_customers_ui_smoke_boundary();', $source);
        $this->assertStringContainsString("\$_SERVER['PHP_AUTH_USER']", $source);
        $this->assertStringContainsString('$this->is_customers_ui_smoke_auth_username($loginUsername)', $source);
        $this->assertStringContainsString('$this->is_customers_ui_smoke_auth_username($basicAuthUsername)', $source);
        $this->assertStringContainsString('Customers_ui_smoke_access_policy::hasActiveLease', $source);
        $this->assertStringContainsString('Customers_ui_smoke_access_policy::isAllowedRoute', $source);
        $this->assertStringContainsString('session_destroy();', $source);
        $this->assertStringContainsString('!$this->is_customers_ui_smoke_auth_username($username)', $login);
    }

    public function testCustomersSearchShortCircuitsBeforeTheNormalModelSearch(): void
    {
        $source = file_get_contents(APPPATH . 'controllers/Customers.php');
        $reserved = strpos($source, 'Customers_ui_smoke_access_policy::isReservedUsername');
        $emptyResponse = strpos($source, 'json_response([]);');
        $normalSearch = strpos($source, '$this->customers_model->search(');

        $this->assertIsInt($reserved);
        $this->assertIsInt($emptyResponse);
        $this->assertIsInt($normalSearch);
        $this->assertLessThan($normalSearch, $reserved);
        $this->assertLessThan($normalSearch, $emptyResponse);
        $this->assertStringContainsString('Customers_ui_smoke_access_policy::isSafeSearchKeyword', $source);
        $this->assertStringContainsString("abort(403, 'Forbidden');", $source);
    }
}

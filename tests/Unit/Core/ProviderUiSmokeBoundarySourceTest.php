<?php

namespace Tests\Unit\Core;

use Tests\TestCase;

class ProviderUiSmokeBoundarySourceTest extends TestCase
{
    public function testControllerBoundaryCoversSessionLoginAndBasicAuthIdentities(): void
    {
        $source = file_get_contents(APPPATH . 'core/EA_Controller.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('$this->enforce_provider_ui_smoke_boundary();', $source);
        $this->assertStringContainsString("\$session_username = session('username');", $source);
        $this->assertStringContainsString('$login_username = is_string($requested_username)', $source);
        $this->assertStringContainsString("\$_SERVER['PHP_AUTH_USER']", $source);
        $this->assertStringContainsString("request('username')", $source);
        $this->assertStringContainsString(
            '$session_is_reserved = Provider_ui_smoke_access_policy::isReservedUsername($session_username)',
            $source,
        );
        $this->assertStringContainsString('$this->is_provider_ui_smoke_auth_username($login_username)', $source);
        $this->assertStringContainsString('$this->is_provider_ui_smoke_auth_username($basic_auth_username)', $source);
        $this->assertStringContainsString('Provider_ui_smoke_access_policy::hasActiveLease', $source);
        $this->assertStringContainsString('session_destroy();', $source);
        $this->assertStringContainsString("abort(403, 'Forbidden');", $source);
    }

    public function testDatabaseLoginCanonicalizesTheSessionBeforeStableIdentityValidation(): void
    {
        $controller_source = file_get_contents(APPPATH . 'core/EA_Controller.php');
        $accounts_source = file_get_contents(APPPATH . 'libraries/Accounts.php');

        $this->assertIsString($controller_source);
        $this->assertIsString($accounts_source);
        $this->assertStringContainsString("'username' => \$user_settings['username']", $accounts_source);
        $this->assertStringContainsString(
            '$principal = $this->load_provider_ui_smoke_principal((int) session(\'user_id\'));',
            $controller_source,
        );
    }

    public function testDormantOrExpiredReservedLoginCannotReachAccountsCheckLogin(): void
    {
        $controller_source = file_get_contents(APPPATH . 'core/EA_Controller.php');
        $login_source = file_get_contents(APPPATH . 'controllers/Login.php');

        $this->assertIsString($controller_source);
        $this->assertIsString($login_source);
        $this->assertStringContainsString('$this->enforce_provider_ui_smoke_boundary();', $controller_source);
        $this->assertStringContainsString('$this->accounts->check_login($username, $password);', $login_source);
    }

    public function testReservedBasicAuthIsRejectedBeforeApiAuthorization(): void
    {
        $controller_source = file_get_contents(APPPATH . 'core/EA_Controller.php');
        $api_source = file_get_contents(APPPATH . 'libraries/Api.php');

        $this->assertIsString($controller_source);
        $this->assertIsString($api_source);
        $this->assertStringContainsString(
            '$basic_auth_is_reserved = $this->is_provider_ui_smoke_auth_username($basic_auth_username)',
            $controller_source,
        );
        $this->assertStringContainsString("abort(403, 'Forbidden');", $controller_source);
        $this->assertStringContainsString("\$_SERVER['PHP_AUTH_USER']", $api_source);
    }

    public function testReservedLoginNeverFallsBackToLdapButNormalUsersStillCan(): void
    {
        $source = file_get_contents(APPPATH . 'controllers/Login.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'empty($user_data) && !$this->is_provider_ui_smoke_auth_username($username)',
            $source,
        );
        $this->assertStringContainsString(
            '$user_data = $this->ldap_client->check_login($username, $password);',
            $source,
        );
    }

    public function testPublicBookingRejectsExactReservedProviderAndServiceTargets(): void
    {
        $source = file_get_contents(APPPATH . 'controllers/Booking.php');

        $this->assertIsString($source);
        $this->assertGreaterThanOrEqual(4, substr_count($source, '$this->assertNotProviderUiSmokeBookingTarget('));
        $this->assertStringContainsString('Provider_ui_smoke_access_policy::USERNAME', $source);
        $this->assertStringContainsString('Provider_ui_smoke_access_policy::SERVICE_NAME', $source);
        $this->assertStringContainsString('Provider_ui_smoke_access_policy::SERVICE_DESCRIPTION', $source);
        $this->assertStringContainsString("abort(404, 'Not Found');", $source);
    }
}

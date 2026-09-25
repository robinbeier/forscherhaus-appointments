<?php

namespace Tests\Unit\Core;

use Tests\TestCase;

require_once APPPATH . 'core/Provider_ui_smoke_access_policy.php';

class ProviderUiSmokeBoundarySourceTest extends TestCase
{
    public function testReservedIdentityAndPaddingAreClassifiedAcrossAuthenticationSurfaces(): void
    {
        $reserved = \Provider_ui_smoke_access_policy::USERNAME;

        self::assertTrue(\Provider_ui_smoke_access_policy::isReservedIdentity($reserved, null, null));
        self::assertTrue(\Provider_ui_smoke_access_policy::isReservedIdentity(null, $reserved . '   ', null));
        self::assertTrue(\Provider_ui_smoke_access_policy::isReservedIdentity(null, null, $reserved));
        self::assertFalse(\Provider_ui_smoke_access_policy::isReservedIdentity('provider', 'provider', 'provider'));
        self::assertFalse(\Provider_ui_smoke_access_policy::isReservedIdentity(null, null, null));
    }

    public function testProviderUiPolicyAllowsOnlyTheBoundSmokeRoutesAndMethods(): void
    {
        self::assertTrue(\Provider_ui_smoke_access_policy::isAllowedRoute('Dashboard', 'index', 'GET'));
        self::assertTrue(\Provider_ui_smoke_access_policy::isAllowedRoute('Dashboard', 'provider_metrics', 'POST'));
        self::assertTrue(
            \Provider_ui_smoke_access_policy::isAllowedRoute(
                'Dashboard_export',
                'provider_parent_appointments_pdf',
                'GET',
            ),
        );
        self::assertTrue(\Provider_ui_smoke_access_policy::isLogoutRoute('Logout', 'index', 'GET'));
        self::assertFalse(\Provider_ui_smoke_access_policy::isAllowedRoute('Dashboard', 'index', 'POST'));
        self::assertFalse(\Provider_ui_smoke_access_policy::isAllowedRoute('Customers', 'index', 'GET'));
        self::assertFalse(\Provider_ui_smoke_access_policy::isLogoutRoute('Logout', 'index', 'POST'));
    }

    public function testProviderUiLeaseIsValidOnlyDuringItsBoundedWindow(): void
    {
        $issued = new \DateTimeImmutable('2026-01-01T12:00:00Z');
        $expires = $issued->modify('+60 seconds');
        $notes = \Provider_ui_smoke_access_policy::buildActiveNotes($issued, $expires);

        self::assertTrue(
            \Provider_ui_smoke_access_policy::hasActiveLease($notes, new \DateTimeImmutable('2026-01-01T12:00:30Z')),
        );
        self::assertFalse(
            \Provider_ui_smoke_access_policy::hasActiveLease($notes, new \DateTimeImmutable('2026-01-01T12:01:00Z')),
        );
        self::assertFalse(
            \Provider_ui_smoke_access_policy::hasActiveLease(
                \Provider_ui_smoke_access_policy::DORMANT_NOTES,
                new \DateTimeImmutable('2026-01-01T12:00:30Z'),
            ),
        );
    }

    public function testControllerRunsProviderBoundaryBeforeSharedSetupContinues(): void
    {
        $source = file_get_contents(APPPATH . 'core/EA_Controller.php');
        self::assertIsString($source);

        $constructorStart = strpos($source, 'public function __construct()');
        $constructorEnd = strpos($source, 'private function ensure_user_exists', $constructorStart ?: 0);
        self::assertIsInt($constructorStart);
        self::assertIsInt($constructorEnd);
        $constructor = substr($source, $constructorStart, $constructorEnd - $constructorStart);

        $accountCheck = strpos($constructor, '$this->ensure_user_exists();');
        $providerBoundary = strpos($constructor, '$this->enforce_provider_ui_smoke_boundary();');
        $sharedSetup = strpos($constructor, '$this->configure_timezone();');
        self::assertIsInt($accountCheck);
        self::assertIsInt($providerBoundary);
        self::assertIsInt($sharedSetup);
        self::assertLessThan($providerBoundary, $accountCheck);
        self::assertLessThan($sharedSetup, $providerBoundary);
    }

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

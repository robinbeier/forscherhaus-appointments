<?php

namespace Tests\Unit\Libraries;

use Customers_ui_smoke_access_policy;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

require_once APPPATH . 'core/Customers_ui_smoke_access_policy.php';

final class CustomersUiSmokeAccessPolicyTest extends TestCase
{
    public function testRoleMatrixAndReservedUsernamesAreExact(): void
    {
        $this->assertSame(
            [DB_SLUG_ADMIN, DB_SLUG_PROVIDER, DB_SLUG_SECRETARY],
            Customers_ui_smoke_access_policy::authorizedRoles(),
        );

        foreach (Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE as $role => $username) {
            $this->assertSame($role, Customers_ui_smoke_access_policy::roleForUsername($username));
            $this->assertSame($role, Customers_ui_smoke_access_policy::roleForUsername(strtoupper($username) . '   '));
            $this->assertTrue(Customers_ui_smoke_access_policy::isReservedUsername($username));
        }

        $this->assertFalse(Customers_ui_smoke_access_policy::isReservedUsername('administrator'));
        $this->assertNull(Customers_ui_smoke_access_policy::roleForUsername('__ea_customers_ui_smoke_unknown_v1'));
        $this->assertTrue(Customers_ui_smoke_access_policy::isAuthorizedRole(DB_SLUG_ADMIN));
        $this->assertFalse(Customers_ui_smoke_access_policy::isAuthorizedRole(DB_SLUG_CUSTOMER));
    }

    public function testOnlyCustomersReadRoutesAndLogoutAreAllowed(): void
    {
        $this->assertTrue(Customers_ui_smoke_access_policy::isAllowedRoute('Customers', 'index', 'GET'));
        $this->assertTrue(Customers_ui_smoke_access_policy::isAllowedRoute('Customers', 'search', 'POST'));
        $this->assertTrue(Customers_ui_smoke_access_policy::isAllowedRoute('Logout', 'index', 'GET'));
        $this->assertFalse(Customers_ui_smoke_access_policy::isAllowedRoute('Customers', 'find', 'POST'));
        $this->assertFalse(Customers_ui_smoke_access_policy::isAllowedRoute('Customers', 'store', 'POST'));
        $this->assertFalse(Customers_ui_smoke_access_policy::isAllowedRoute('Customers', 'search', 'GET'));
        $this->assertFalse(Customers_ui_smoke_access_policy::isAllowedRoute('Calendar', 'index', 'GET'));
        $this->assertFalse(Customers_ui_smoke_access_policy::isAllowedRoute('api/v1/Customers', 'index', 'GET'));
    }

    public function testOnlyEmptyAndSyntheticSearchKeywordsAreSafe(): void
    {
        $this->assertTrue(Customers_ui_smoke_access_policy::isSafeSearchKeyword(''));
        $this->assertTrue(
            Customers_ui_smoke_access_policy::isSafeSearchKeyword(Customers_ui_smoke_access_policy::SEARCH_MARKER),
        );
        $this->assertFalse(Customers_ui_smoke_access_policy::isSafeSearchKeyword('Synthetic'));
        $this->assertFalse(Customers_ui_smoke_access_policy::isSafeSearchKeyword(' '));
    }

    public function testLeaseIsRoleBoundStartedAndLimitedToTenMinutes(): void
    {
        $now = new DateTimeImmutable('2026-08-08T08:00:00Z', new DateTimeZone('UTC'));
        $notes = Customers_ui_smoke_access_policy::buildActiveNotes(
            DB_SLUG_PROVIDER,
            $now,
            $now->modify('+10 minutes'),
        );

        $this->assertTrue(Customers_ui_smoke_access_policy::hasActiveLease($notes, DB_SLUG_PROVIDER, $now));
        $this->assertFalse(Customers_ui_smoke_access_policy::hasActiveLease($notes, DB_SLUG_ADMIN, $now));
        $this->assertFalse(
            Customers_ui_smoke_access_policy::hasActiveLease($notes, DB_SLUG_PROVIDER, $now->modify('-1 second')),
        );
        $this->assertFalse(
            Customers_ui_smoke_access_policy::hasActiveLease($notes, DB_SLUG_PROVIDER, $now->modify('+10 minutes')),
        );
        $this->assertFalse(
            Customers_ui_smoke_access_policy::hasActiveLease(
                Customers_ui_smoke_access_policy::dormantNotes(DB_SLUG_PROVIDER),
                DB_SLUG_PROVIDER,
                $now,
            ),
        );
    }
}

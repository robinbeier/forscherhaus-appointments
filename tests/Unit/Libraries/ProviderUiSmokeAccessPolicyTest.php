<?php

namespace Tests\Unit\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use Provider_ui_smoke_access_policy;
use Tests\TestCase;

require_once APPPATH . 'core/Provider_ui_smoke_access_policy.php';

class ProviderUiSmokeAccessPolicyTest extends TestCase
{
    public function testRecognizesEverySupportedReservedAuthenticationSurface(): void
    {
        $username = Provider_ui_smoke_access_policy::USERNAME;

        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity($username, null, null));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity(null, $username, null));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity(null, null, $username));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity(strtoupper($username), null, null));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedUsername(strtoupper($username)));
        $this->assertFalse(Provider_ui_smoke_access_policy::isReservedIdentity('provider', null, null));
        $this->assertFalse(Provider_ui_smoke_access_policy::isReservedUsername('provider'));
    }

    public function testRecognizesMariaDbPadSpaceVariantsAcrossLoginAndSessionSurfaces(): void
    {
        $padded_username = strtoupper(Provider_ui_smoke_access_policy::USERNAME) . '   ';

        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity($padded_username, null, null));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity(null, $padded_username, null));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity(null, null, $padded_username));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedUsername($padded_username));

        $this->assertFalse(
            Provider_ui_smoke_access_policy::isReservedUsername(' ' . Provider_ui_smoke_access_policy::USERNAME),
        );
        $this->assertFalse(
            Provider_ui_smoke_access_policy::isReservedUsername(Provider_ui_smoke_access_policy::USERNAME . "\t"),
        );
        $this->assertFalse(Provider_ui_smoke_access_policy::isReservedUsername('provider   '));
    }

    public function testOnlyAllowsTheProviderDashboardExportsAndLogoutWithExactVerbs(): void
    {
        $this->assertTrue(Provider_ui_smoke_access_policy::isAllowedRoute('Dashboard', 'index', 'GET'));
        $this->assertTrue(Provider_ui_smoke_access_policy::isAllowedRoute('Dashboard', 'provider_metrics', 'POST'));
        $this->assertTrue(
            Provider_ui_smoke_access_policy::isAllowedRoute(
                'Dashboard_export',
                'provider_parent_appointments_pdf',
                'GET',
            ),
        );
        $this->assertTrue(
            Provider_ui_smoke_access_policy::isAllowedRoute('Dashboard_export', 'provider_preparation_pdf', 'GET'),
        );
        $this->assertTrue(Provider_ui_smoke_access_policy::isAllowedRoute('Logout', 'index', 'GET'));

        $this->assertFalse(Provider_ui_smoke_access_policy::isAllowedRoute('Calendar', 'index', 'GET'));
        $this->assertFalse(Provider_ui_smoke_access_policy::isAllowedRoute('Customers', 'index', 'GET'));
        $this->assertFalse(Provider_ui_smoke_access_policy::isAllowedRoute('api/v1/Customers', 'index', 'GET'));
        $this->assertFalse(Provider_ui_smoke_access_policy::isAllowedRoute('Dashboard', 'index', 'POST'));
        $this->assertFalse(Provider_ui_smoke_access_policy::isAllowedRoute('Dashboard', 'provider_metrics', 'GET'));
    }

    public function testAcceptsOnlyAStartedUnexpiredLeaseOfAtMostTenMinutes(): void
    {
        $timezone = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('2026-07-27T12:00:00Z', $timezone);
        $notes = Provider_ui_smoke_access_policy::buildActiveNotes($now, $now->modify('+10 minutes'));

        $this->assertTrue(Provider_ui_smoke_access_policy::hasActiveLease($notes, $now));
        $this->assertTrue(Provider_ui_smoke_access_policy::hasActiveLease($notes, $now->modify('+9 minutes')));
        $this->assertFalse(Provider_ui_smoke_access_policy::hasActiveLease($notes, $now->modify('+10 minutes')));
        $this->assertFalse(Provider_ui_smoke_access_policy::hasActiveLease($notes, $now->modify('-1 second')));
        $this->assertFalse(
            Provider_ui_smoke_access_policy::hasActiveLease(Provider_ui_smoke_access_policy::DORMANT_NOTES, $now),
        );
    }

    public function testRejectsMalformedAndOverlongLeases(): void
    {
        $timezone = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('2026-07-27T12:00:00Z', $timezone);

        $this->assertFalse(Provider_ui_smoke_access_policy::hasActiveLease('active', $now));
        $this->assertFalse(
            Provider_ui_smoke_access_policy::hasActiveLease(
                Provider_ui_smoke_access_policy::ACTIVE_NOTES_PREFIX . '2026-07-27T12:00:00Z:2026-07-27T12:10:01Z',
                $now,
            ),
        );
    }

    public function testRefusesToBuildAnOverlongLease(): void
    {
        $timezone = new DateTimeZone('UTC');
        $issued_at = new DateTimeImmutable('2026-07-27T12:00:00Z', $timezone);

        $this->expectException(\InvalidArgumentException::class);

        Provider_ui_smoke_access_policy::buildActiveNotes($issued_at, $issued_at->modify('+601 seconds'));
    }
}

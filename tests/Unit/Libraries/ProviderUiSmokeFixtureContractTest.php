<?php

namespace Tests\Unit\Libraries;

use Provider_ui_smoke_access_policy;
use Provider_ui_smoke_fixture;
use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/Provider_ui_smoke_access_policy.php';
require_once APPPATH . 'libraries/Provider_ui_smoke_fixture.php';

class ProviderUiSmokeFixtureContractTest extends TestCase
{
    public function testFixtureConstantsMatchTheProductionGateContract(): void
    {
        $this->assertSame('__ea_provider_ui_smoke_v1', Provider_ui_smoke_access_policy::USERNAME);
        $this->assertSame('prod-provider-ui-smoke-v1', Provider_ui_smoke_access_policy::FIXTURE_KEY);
        $this->assertSame('Synthetic', Provider_ui_smoke_fixture::PROVIDER_FIRST_NAME);
        $this->assertSame('Provider UI Smoke V1', Provider_ui_smoke_fixture::PROVIDER_LAST_NAME);
        $this->assertSame('provider-ui-smoke-v1@synthetic.invalid', Provider_ui_smoke_fixture::PROVIDER_EMAIL);
        $this->assertSame('Synthetic', Provider_ui_smoke_fixture::CUSTOMER_FIRST_NAME);
        $this->assertSame('Parent UI Smoke V1', Provider_ui_smoke_fixture::CUSTOMER_LAST_NAME);
        $this->assertSame('customer-provider-ui-smoke-v1@synthetic.invalid', Provider_ui_smoke_fixture::CUSTOMER_EMAIL);
        $this->assertSame('0000000000', Provider_ui_smoke_fixture::CUSTOMER_PHONE);
        $this->assertSame('PROD_PROVIDER_UI_SMOKE_V1_PRIVATE_NOTE_SENTINEL', Provider_ui_smoke_fixture::CUSTOMER_NOTES);
    }

    public function testFixtureUsesTheThreeFixedFilterAppointments(): void
    {
        $this->assertSame('2099-02-12 10:00:00', Provider_ui_smoke_fixture::BOOKED_INSIDE_START);
        $this->assertSame('2099-02-12 10:30:00', Provider_ui_smoke_fixture::BOOKED_INSIDE_END);
        $this->assertSame('2099-02-12 11:00:00', Provider_ui_smoke_fixture::CANCELLED_INSIDE_START);
        $this->assertSame('2099-02-12 11:30:00', Provider_ui_smoke_fixture::CANCELLED_INSIDE_END);
        $this->assertSame('2099-03-12 12:00:00', Provider_ui_smoke_fixture::BOOKED_OUTSIDE_START);
        $this->assertSame('2099-03-12 12:30:00', Provider_ui_smoke_fixture::BOOKED_OUTSIDE_END);
    }

    public function testLifecycleSourceKeepsTheDormantVerifierAndDirectWriteSafetyContract(): void
    {
        $source = file_get_contents(APPPATH . 'libraries/Provider_ui_smoke_fixture.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("'password' => null", $source);
        $this->assertStringContainsString("'salt' => null", $source);
        $this->assertStringContainsString("'notifications' => 0", $source);
        $this->assertStringContainsString("'google_sync' => 0", $source);
        $this->assertStringContainsString("'caldav_sync' => 0", $source);
        $this->assertStringContainsString('SELECT GET_LOCK(?, 10)', $source);
        $this->assertStringContainsString('$this->CI->db->trans_begin()', $source);
        $this->assertStringContainsString('$this->CI->db->trans_commit()', $source);
        $this->assertStringContainsString('$this->CI->db->trans_rollback()', $source);
        $this->assertStringContainsString('fixture_checksum', $source);
        $this->assertStringContainsString('reconstructActiveState', $source);
        $this->assertStringNotContainsString('$this->notifications', $source);
        $this->assertStringNotContainsString('$this->webhooks_client', $source);
        $this->assertStringNotContainsString('$this->synchronization', $source);
    }

    public function testActivationSnapshotsStateOnlyAfterTheLifecycleLock(): void
    {
        $source = file_get_contents(APPPATH . 'libraries/Provider_ui_smoke_fixture.php');

        $this->assertIsString($source);
        $lock_position = strpos($source, '$this->acquireLock();');
        $snapshot_position = strpos(
            $source,
            '$state_file_existed_before = file_exists($state_file) || is_link($state_file);',
        );
        $transaction_position = strpos($source, '$this->CI->db->trans_begin()');

        $this->assertIsInt($lock_position);
        $this->assertIsInt($snapshot_position);
        $this->assertIsInt($transaction_position);
        $this->assertTrue($lock_position < $snapshot_position);
        $this->assertTrue($snapshot_position < $transaction_position);
    }

    public function testConsoleExposesOnlyTheFiveBoundedLifecycleActions(): void
    {
        $source = file_get_contents(APPPATH . 'controllers/Console.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("['install', 'verify', 'activate', 'deactivate', 'remove']", $source);
        $this->assertStringContainsString(
            "'provider_ui_smoke action=' . \$action . ' state=' . \$state . ' result=ok'",
            $source,
        );
        $this->assertStringContainsString("\$GLOBALS['argv'][4]", $source);
        $this->assertStringContainsString("\$GLOBALS['argv'][5]", $source);
        $this->assertStringContainsString("isset(\$GLOBALS['argv'][6])", $source);
        $this->assertStringContainsString(
            "fwrite(STDOUT, 'provider_ui_smoke action=' . \$action . ' state=error result=error'",
            $source,
        );
    }

    public function testLifecycleRejectsBroadOrNonNormalizedProtectedPaths(): void
    {
        $reflection = new ReflectionClass(Provider_ui_smoke_fixture::class);
        $fixture = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('assertSafeAbsolutePath');

        $method->invoke($fixture, '/tmp/provider-ui-smoke/active.json');
        $this->addToAssertionCount(1);

        foreach (
            [
                '',
                '/',
                'relative/path',
                '/tmp/provider-ui-smoke/',
                '/tmp//provider-ui-smoke/active.json',
                '/tmp/./provider-ui-smoke/active.json',
                '/tmp/provider-ui-smoke/../active.json',
            ]
            as $invalid_path
        ) {
            try {
                $method->invoke($fixture, $invalid_path);
                $this->fail('An unsafe lifecycle path was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

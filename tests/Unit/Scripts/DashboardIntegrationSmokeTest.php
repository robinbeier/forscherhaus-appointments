<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;

require_once __DIR__ . '/../../../scripts/ci/lib/DashboardSummaryBrowserCheck.php';

class DashboardIntegrationSmokeTest extends TestCase
{
    public function testBuildRunCodeSnippetUsesPlaywrightFunctionSignature(): void
    {
        $snippet = dashboardSummaryBrowserBuildRunCodeSnippet($this->browserSnippetConfig());

        self::assertStringStartsWith('async (page) => {', $snippet);
        self::assertStringNotContainsString('(async () => {', $snippet);
    }

    public function testBuildRunCodeSnippetExercisesVisibleThresholdUiFlow(): void
    {
        $snippet = dashboardSummaryBrowserBuildRunCodeSnippet($this->browserSnippetConfig());

        self::assertStringContainsString("document.getElementById('login-form') !== null", $snippet);
        self::assertStringContainsString("await page.fill('#username', username)", $snippet);
        self::assertStringContainsString("await page.fill('#password', password)", $snippet);
        self::assertStringContainsString("await page.click('#login')", $snippet);
        self::assertStringContainsString('flatpickrInstance.setDate', $snippet);
        self::assertStringContainsString('requested_range_applied', $snippet);
        self::assertStringContainsString('resolveDashboardLocale', $snippet);
        self::assertStringNotContainsString('toISOString().slice(0, 10)', $snippet);
        self::assertStringContainsString('#dashboard-summary-open-total', $snippet);
        self::assertStringContainsString('#dashboard-summary-open-share', $snippet);
        self::assertStringContainsString('booked_width_matches', $snippet);
        self::assertStringContainsString(
            "document.querySelector('#dashboard-error')?.hasAttribute('hidden')",
            $snippet,
        );
        self::assertStringContainsString('bootstrap.Dropdown.getOrCreateInstance(optionsToggle).show()', $snippet);
        self::assertStringContainsString("page.click('#dashboard-threshold-button')", $snippet);
        self::assertStringContainsString("page.waitForSelector('#dashboard-threshold-input'", $snippet);
        self::assertStringContainsString("page.click('#dashboard-threshold-form button[type=\"submit\"]')", $snippet);
        self::assertStringNotContainsString("dispatchEvent(new Event('submit'", $snippet);
    }

    public function testParseRunCodeResultReturnsDecodedPayload(): void
    {
        $payload = dashboardSummaryBrowserParseRunCodeResult([
            'stdout' =>
                "__DASHBOARD_SUMMARY_BROWSER_CHECK__{\"dashboard_summary_browser_check\":true,\"ok\":true,\"fill_rate_before\":\"66,7 %\",\"expected_fill_rate\":\"66,7 %\",\"zero_state_rendered\":true}\n",
        ]);

        self::assertTrue($payload['ok']);
        self::assertSame('66,7 %', $payload['fill_rate_before']);
    }

    public function testParseRunCodeResultParsesSentinelJsonOutsideMarkdownEnvelope(): void
    {
        $payload = dashboardSummaryBrowserParseRunCodeResult([
            'stdout' =>
                "debug line\n__DASHBOARD_SUMMARY_BROWSER_CHECK__{\"dashboard_summary_browser_check\":true,\"ok\":false,\"error\":\"expected\"}\n",
        ]);

        self::assertFalse($payload['ok']);
        self::assertSame('expected', $payload['error']);
    }

    public function testParseRunCodeResultRejectsMissingResultSection(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('Could not parse dashboard summary browser payload');

        dashboardSummaryBrowserParseRunCodeResult([
            'stdout' => "### Debug\nno result payload here\n",
        ]);
    }

    public function testAssertDashboardSummaryBrowserPayloadAcceptsValidLocalizedPayload(): void
    {
        $payload = dashboardSummaryBrowserAssertPayload([
            'dashboard_summary_browser_check' => true,
            'ok' => true,
            'expected_fill_rate' => '66,7 %',
            'target_total_before' => 12,
            'open_total_before' => 4,
            'target_total_after' => 12,
            'booked_total_before' => 8,
            'booked_total_after' => 8,
            'open_total_after' => 4,
            'fill_rate_before' => '66,7 %',
            'fill_rate_after' => '66,7 %',
            'booked_share_before' => '66,7 %',
            'booked_share_after' => '66,7 %',
            'open_share_before' => '33,3 %',
            'open_share_after' => '33,3 %',
            'error_hidden_before' => true,
            'error_hidden_after' => true,
            'threshold_badge_before' => 'Schwellwert 90,0 %',
            'threshold_badge_after' => 'Schwellwert 35,0 %',
            'marker_left_before' => '90%',
            'marker_left_after' => '35%',
            'requested_range_applied_before' => true,
            'requested_range_applied_after' => true,
            'totals_match_before' => true,
            'totals_match_after' => true,
            'shares_match_before' => true,
            'shares_match_after' => true,
            'booked_width_matches_before' => true,
            'booked_width_matches_after' => true,
            'expected_threshold_text' => '35,0 %',
            'dashboard_locale' => 'de-DE',
            'zero_state_rendered' => true,
        ]);

        self::assertSame('35%', $payload['marker_left_after']);
        self::assertSame('Schwellwert 35,0 %', $payload['threshold_badge_after']);
    }

    public function testAssertDashboardSummaryBrowserPayloadRejectsUnchangedThresholdBadge(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('threshold badge did not change');

        dashboardSummaryBrowserAssertPayload([
            'dashboard_summary_browser_check' => true,
            'ok' => true,
            'expected_fill_rate' => '66,7 %',
            'target_total_before' => 12,
            'open_total_before' => 4,
            'target_total_after' => 12,
            'booked_total_before' => 8,
            'booked_total_after' => 8,
            'open_total_after' => 4,
            'fill_rate_before' => '66,7 %',
            'fill_rate_after' => '66,7 %',
            'booked_share_before' => '66,7 %',
            'booked_share_after' => '66,7 %',
            'open_share_before' => '33,3 %',
            'open_share_after' => '33,3 %',
            'error_hidden_before' => true,
            'error_hidden_after' => true,
            'threshold_badge_before' => 'Schwellwert 35,0 %',
            'threshold_badge_after' => 'Schwellwert 35,0 %',
            'marker_left_before' => '90%',
            'marker_left_after' => '35%',
            'requested_range_applied_before' => true,
            'requested_range_applied_after' => true,
            'totals_match_before' => true,
            'totals_match_after' => true,
            'shares_match_before' => true,
            'shares_match_after' => true,
            'booked_width_matches_before' => true,
            'booked_width_matches_after' => true,
            'expected_threshold_text' => '35,0 %',
            'dashboard_locale' => 'de-DE',
            'zero_state_rendered' => true,
        ]);
    }

    public function testAssertDashboardSummaryBrowserPayloadRejectsWrongLocaleFormatting(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('expected locale formatting text');

        dashboardSummaryBrowserAssertPayload([
            'dashboard_summary_browser_check' => true,
            'ok' => true,
            'expected_fill_rate' => '66,7 %',
            'target_total_before' => 12,
            'open_total_before' => 4,
            'target_total_after' => 12,
            'booked_total_before' => 8,
            'booked_total_after' => 8,
            'open_total_after' => 4,
            'fill_rate_before' => '66.7%',
            'fill_rate_after' => '66.7%',
            'booked_share_before' => '66.7%',
            'booked_share_after' => '66.7%',
            'open_share_before' => '33.3%',
            'open_share_after' => '33.3%',
            'error_hidden_before' => true,
            'error_hidden_after' => true,
            'threshold_badge_before' => 'Threshold 90.0%',
            'threshold_badge_after' => 'Threshold 35.0%',
            'marker_left_before' => '90%',
            'marker_left_after' => '35%',
            'requested_range_applied_before' => true,
            'requested_range_applied_after' => true,
            'totals_match_before' => true,
            'totals_match_after' => true,
            'shares_match_before' => true,
            'shares_match_after' => true,
            'booked_width_matches_before' => true,
            'booked_width_matches_after' => true,
            'expected_threshold_text' => '35,0 %',
            'dashboard_locale' => 'de-DE',
            'zero_state_rendered' => true,
        ]);
    }

    public function testAssertDashboardSummaryBrowserPayloadRejectsRangeDriftAfterThresholdSave(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('date range drifted');

        dashboardSummaryBrowserAssertPayload([
            'dashboard_summary_browser_check' => true,
            'ok' => true,
            'expected_fill_rate' => '66,7 %',
            'target_total_before' => 12,
            'open_total_before' => 4,
            'target_total_after' => 12,
            'booked_total_before' => 8,
            'booked_total_after' => 8,
            'open_total_after' => 4,
            'fill_rate_before' => '66,7 %',
            'fill_rate_after' => '66,7 %',
            'booked_share_before' => '66,7 %',
            'booked_share_after' => '66,7 %',
            'open_share_before' => '33,3 %',
            'open_share_after' => '33,3 %',
            'error_hidden_before' => true,
            'error_hidden_after' => true,
            'threshold_badge_before' => 'Schwellwert 90,0 %',
            'threshold_badge_after' => 'Schwellwert 35,0 %',
            'marker_left_before' => '90%',
            'marker_left_after' => '35%',
            'requested_range_applied_before' => true,
            'requested_range_applied_after' => false,
            'totals_match_before' => true,
            'totals_match_after' => true,
            'shares_match_before' => true,
            'shares_match_after' => true,
            'booked_width_matches_before' => true,
            'booked_width_matches_after' => true,
            'expected_threshold_text' => '35,0 %',
            'dashboard_locale' => 'de-DE',
            'zero_state_rendered' => true,
        ]);
    }

    public function testAssertDashboardSummaryBrowserPayloadSurfacesSnippetErrorBeforeKeyValidation(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('Threshold form was not found.');

        dashboardSummaryBrowserAssertPayload([
            'dashboard_summary_browser_check' => true,
            'ok' => false,
            'error' => 'Threshold form was not found.',
        ]);
    }

    public function testAssertDashboardSummaryBrowserPayloadRejectsVisibleErrorBanner(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('visible error banner');

        dashboardSummaryBrowserAssertPayload([
            'dashboard_summary_browser_check' => true,
            'ok' => true,
            'expected_fill_rate' => '66,7 %',
            'target_total_before' => 12,
            'open_total_before' => 4,
            'target_total_after' => 12,
            'booked_total_before' => 8,
            'booked_total_after' => 8,
            'open_total_after' => 4,
            'fill_rate_before' => '66,7 %',
            'fill_rate_after' => '66,7 %',
            'booked_share_before' => '66,7 %',
            'booked_share_after' => '66,7 %',
            'open_share_before' => '33,3 %',
            'open_share_after' => '33,3 %',
            'error_hidden_before' => false,
            'error_hidden_after' => true,
            'threshold_badge_before' => 'Schwellwert 90,0 %',
            'threshold_badge_after' => 'Schwellwert 35,0 %',
            'marker_left_before' => '90%',
            'marker_left_after' => '35%',
            'requested_range_applied_before' => true,
            'requested_range_applied_after' => true,
            'totals_match_before' => true,
            'totals_match_after' => true,
            'shares_match_before' => true,
            'shares_match_after' => true,
            'booked_width_matches_before' => true,
            'booked_width_matches_after' => true,
            'expected_threshold_text' => '35,0 %',
            'dashboard_locale' => 'de-DE',
            'zero_state_rendered' => true,
        ]);
    }

    public function testAssertDashboardSummaryBrowserPayloadAcceptsZeroStateSummary(): void
    {
        $payload = dashboardSummaryBrowserAssertPayload([
            'dashboard_summary_browser_check' => true,
            'ok' => true,
            'expected_fill_rate' => '0,0 %',
            'target_total_before' => 0,
            'open_total_before' => 0,
            'target_total_after' => 0,
            'booked_total_before' => 0,
            'booked_total_after' => 0,
            'open_total_after' => 0,
            'fill_rate_before' => '0,0 %',
            'fill_rate_after' => '0,0 %',
            'booked_share_before' => '0,0 %',
            'booked_share_after' => '0,0 %',
            'open_share_before' => '0,0 %',
            'open_share_after' => '0,0 %',
            'error_hidden_before' => true,
            'error_hidden_after' => true,
            'threshold_badge_before' => 'Schwellwert 90,0 %',
            'threshold_badge_after' => 'Schwellwert 35,0 %',
            'marker_left_before' => '90%',
            'marker_left_after' => '35%',
            'requested_range_applied_before' => true,
            'requested_range_applied_after' => true,
            'totals_match_before' => true,
            'totals_match_after' => true,
            'shares_match_before' => true,
            'shares_match_after' => true,
            'booked_width_matches_before' => true,
            'booked_width_matches_after' => true,
            'expected_threshold_text' => '35,0 %',
            'dashboard_locale' => 'de-DE',
            'zero_state_rendered' => true,
        ]);

        self::assertSame(0, $payload['target_total_before']);
        self::assertSame(0, $payload['open_total_after']);
    }

    /**
     * @return array{
     *   username:string,
     *   password:string,
     *   start_date:string,
     *   end_date:string,
     *   expected_summary:array{target_total:int,booked_total:int,open_total:int,fill_rate:float}
     * }
     */
    private function browserSnippetConfig(): array
    {
        return [
            'username' => 'admin',
            'password' => 'secret',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'expected_summary' => [
                'target_total' => 12,
                'booked_total' => 8,
                'open_total' => 4,
                'fill_rate' => 2 / 3,
            ],
        ];
    }
}

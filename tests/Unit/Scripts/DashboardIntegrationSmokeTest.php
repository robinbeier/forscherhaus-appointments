<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;

require_once __DIR__ . '/../../../scripts/ci/lib/DashboardSummaryBrowserCheck.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/PlaywrightCookieRecords.php';

use function ReleaseGate\normalizeCookieRecordsForPlaywright;

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

        self::assertStringContainsString('page.context().addCookies(', $snippet);
        self::assertStringContainsString(
            "await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 })",
            $snippet,
        );
        self::assertStringContainsString(
            'Dashboard session cookies were rejected and the browser was redirected to login.',
            $snippet,
        );
        self::assertStringContainsString('flatpickrInstance.setDate', $snippet);
        self::assertStringContainsString('requested_range_applied', $snippet);
        self::assertStringContainsString('__dashboardSummaryCheckHelpers', $snippet);
        self::assertStringContainsString('installDashboardHelpers', $snippet);
        self::assertStringContainsString('helpers.matchesSummaryState', $snippet);
        self::assertStringContainsString(
            "if (!helpers || typeof helpers.matchesSummaryState !== 'function') {",
            $snippet,
        );
        self::assertStringContainsString(
            'return window.__dashboardSummaryCheckHelpers.captureSummaryState({',
            $snippet,
        );
        self::assertStringContainsString('return window.__dashboardSummaryCheckHelpers.resolveLocale();', $snippet);
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
        self::assertStringContainsString("await page.inputValue('#dashboard-threshold-input')", $snippet);
        self::assertStringContainsString('threshold_modal_matches_before', $snippet);
        self::assertStringContainsString('Number.isFinite(thresholdModalNumberBefore)', $snippet);
        self::assertStringContainsString('Number.isFinite(expectedThresholdBefore)', $snippet);
        self::assertStringNotContainsString(
            '(Number.parseFloat(thresholdModalValueBefore) || 0) - (Number(before.expected_threshold) || 0)',
            $snippet,
        );
        self::assertStringContainsString('__DASHBOARD_SUMMARY_BROWSER_CHECK__', $snippet);
        self::assertStringContainsString("page.click('#dashboard-threshold-form button[type=\"submit\"]')", $snippet);
        self::assertStringNotContainsString("dispatchEvent(new Event('submit'", $snippet);
        self::assertStringNotContainsString(
            'const loginResponsePromise = page.waitForResponse((response) => {',
            $snippet,
        );
        self::assertStringNotContainsString("await page.fill('#username', username)", $snippet);
        self::assertStringNotContainsString("await page.fill('#password', password)", $snippet);
        self::assertStringNotContainsString("window.jQuery?._data?.(form, 'events')?.submit", $snippet);
    }

    public function testParseRunCodeResultReturnsDecodedPayloadFromSentinelOutput(): void
    {
        $payload = dashboardSummaryBrowserParseRunCodeResult([
            'stdout' =>
                "debug line\n" .
                "__DASHBOARD_SUMMARY_BROWSER_CHECK__{\"dashboard_summary_browser_check\":true,\"ok\":true,\"fill_rate_before\":\"66,7 %\",\"expected_fill_rate\":\"66,7 %\",\"zero_state_rendered\":true}\n" .
                "more debug\n",
        ]);

        self::assertTrue($payload['ok']);
        self::assertSame('66,7 %', $payload['fill_rate_before']);
    }

    public function testParseRunCodeResultFallsBackToLegacySentinelPayload(): void
    {
        $payload = dashboardSummaryBrowserParseRunCodeResult([
            'stdout' =>
                "debug line\n__DASHBOARD_SUMMARY_BROWSER_CHECK__{\"dashboard_summary_browser_check\":true,\"ok\":false,\"error\":\"expected\"}\n",
        ]);

        self::assertFalse($payload['ok']);
        self::assertSame('expected', $payload['error']);
    }

    public function testParseRunCodeResultDecodesQuotedSentinelPayload(): void
    {
        $payload = dashboardSummaryBrowserParseRunCodeResult([
            'stdout' =>
                'generic [ref=e156]: ' .
                "\"__DASHBOARD_SUMMARY_BROWSER_CHECK__{\\\"dashboard_summary_browser_check\\\":true,\\\"ok\\\":true,\\\"error\\\":null}\"\n",
        ]);

        self::assertTrue($payload['ok']);
        self::assertArrayHasKey('error', $payload);
    }

    public function testParseRunCodeResultDecodesQuotedSentinelInsideLegacyResultSection(): void
    {
        $payload = dashboardSummaryBrowserParseRunCodeResult([
            'stdout' =>
                "### Result\n" .
                'generic [ref=e156]: ' .
                "\"__DASHBOARD_SUMMARY_BROWSER_CHECK__{\\\"dashboard_summary_browser_check\\\":true,\\\"ok\\\":true,\\\"threshold_badge_before\\\":\\\"Schwellwert 90,0 %\\\"}\"\n" .
                "### Ran Playwright code\n",
        ]);

        self::assertTrue($payload['ok']);
        self::assertSame('Schwellwert 90,0 %', $payload['threshold_badge_before']);
    }

    public function testBuildRunCodeSnippetPreservesScopedCookieMetadata(): void
    {
        $config = $this->browserSnippetConfig();
        $config['session_cookies'] = [
            [
                'name' => 'ci_session',
                'value' => 'session-value',
                'domain' => 'example.test',
                'path' => '/app/',
                'secure' => true,
                'httpOnly' => true,
                'sameSite' => 'Lax',
            ],
        ];

        $snippet = dashboardSummaryBrowserBuildRunCodeSnippet($config);

        self::assertStringContainsString('"domain":"example.test"', $snippet);
        self::assertStringContainsString('"path":"/app/"', $snippet);
        self::assertStringContainsString('"secure":true', $snippet);
        self::assertStringContainsString('"httpOnly":true', $snippet);
        self::assertStringContainsString('"sameSite":"Lax"', $snippet);
        self::assertStringContainsString('await page.context().addCookies(sessionCookies);', $snippet);
        self::assertStringNotContainsString('new URL(cookieUrl)', $snippet);
    }

    public function testBuildRunCodeSnippetUsesUrlScopedCookiesForHostOnlySessionState(): void
    {
        $config = $this->browserSnippetConfig();
        $config['session_cookies'] = [
            [
                'name' => 'csrf_cookie',
                'value' => 'token-123',
                'path' => '/app/index.php/login/',
                'url' => 'https://example.test/app/index.php/login/',
                'httpOnly' => true,
            ],
        ];

        $snippet = dashboardSummaryBrowserBuildRunCodeSnippet($config);

        self::assertStringContainsString('"url":"https://example.test/app/index.php/login/"', $snippet);
        self::assertStringContainsString('await page.context().addCookies(sessionCookies);', $snippet);
    }

    public function testNormalizeCookieRecordsForPlaywrightPreservesUrlScopedHostOnlyCookies(): void
    {
        $cookies = normalizeCookieRecordsForPlaywright(
            [
                [
                    'name' => 'ci_session',
                    'value' => 'session-value',
                    'url' => 'https://example.test/app/index.php/login/',
                    'path' => '/app/index.php/login/',
                    'httpOnly' => true,
                ],
            ],
            'https://example.test/app/index.php/dashboard',
        );

        self::assertSame(
            [
                [
                    'name' => 'ci_session',
                    'value' => 'session-value',
                    'url' => 'https://example.test/app/index.php/login/',
                    'httpOnly' => true,
                ],
            ],
            $cookies,
        );
    }

    public function testNormalizeCookieRecordsForPlaywrightPreservesScopedCookieMetadata(): void
    {
        $cookies = normalizeCookieRecordsForPlaywright(
            [
                [
                    'name' => 'csrf_cookie',
                    'value' => 'token-123',
                    'domain' => 'example.test',
                    'path' => '/app/',
                    'sameSite' => 'Lax',
                    'secure' => true,
                    'httpOnly' => true,
                ],
            ],
            'https://example.test/app/index.php/dashboard',
        );

        self::assertSame(
            [
                [
                    'name' => 'csrf_cookie',
                    'value' => 'token-123',
                    'domain' => 'example.test',
                    'path' => '/app/',
                    'sameSite' => 'Lax',
                    'secure' => true,
                    'httpOnly' => true,
                ],
            ],
            $cookies,
        );
    }

    public function testNormalizeCookieRecordsForPlaywrightFallsBackToTargetUrlForPathOnlyCookies(): void
    {
        $cookies = normalizeCookieRecordsForPlaywright(
            [
                [
                    'name' => 'ci_session',
                    'value' => 'session-value',
                    'path' => '/app/index.php/login/',
                ],
            ],
            'https://example.test/app/index.php/dashboard',
        );

        self::assertSame(
            [
                [
                    'name' => 'ci_session',
                    'value' => 'session-value',
                    'url' => 'https://example.test/app/index.php/login/',
                ],
            ],
            $cookies,
        );
    }

    public function testBuildRunCodeSnippetSelectsAlternateThresholdWhenCurrentThresholdAlreadyMatchesDefault(): void
    {
        $config = $this->browserSnippetConfig();
        $config['expected_summary']['threshold'] = 0.35;

        $snippet = dashboardSummaryBrowserBuildRunCodeSnippet($config);

        self::assertStringContainsString('"updated_threshold_input":"0.90"', $snippet);
        self::assertStringContainsString('"updated_threshold_marker":"90%"', $snippet);
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
            'threshold_modal_value_before' => '0.90',
            'threshold_modal_matches_before' => true,
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
            'expected_initial_threshold' => 0.9,
            'expected_marker_after' => '35%',
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
            'threshold_modal_value_before' => '0.90',
            'threshold_modal_matches_before' => true,
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
            'expected_initial_threshold' => 0.9,
            'expected_marker_after' => '35%',
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
            'threshold_modal_value_before' => '0.90',
            'threshold_modal_matches_before' => true,
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
            'expected_initial_threshold' => 0.9,
            'expected_marker_after' => '35%',
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
            'threshold_modal_value_before' => '0.90',
            'threshold_modal_matches_before' => true,
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
            'expected_initial_threshold' => 0.9,
            'expected_marker_after' => '35%',
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
            'threshold_modal_value_before' => '0.90',
            'threshold_modal_matches_before' => true,
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
            'expected_initial_threshold' => 0.9,
            'expected_marker_after' => '35%',
            'expected_threshold_text' => '35,0 %',
            'dashboard_locale' => 'de-DE',
            'zero_state_rendered' => true,
        ]);
    }

    public function testAssertDashboardSummaryBrowserPayloadRejectsThresholdModalMismatch(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('threshold modal default did not match');

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
            'threshold_modal_value_before' => '0.55',
            'threshold_modal_matches_before' => false,
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
            'expected_initial_threshold' => 0.9,
            'expected_marker_after' => '35%',
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
            'threshold_modal_value_before' => '0.90',
            'threshold_modal_matches_before' => true,
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
            'expected_initial_threshold' => 0.9,
            'expected_marker_after' => '35%',
            'expected_threshold_text' => '35,0 %',
            'dashboard_locale' => 'de-DE',
            'zero_state_rendered' => true,
        ]);

        self::assertSame(0, $payload['target_total_before']);
        self::assertSame(0, $payload['open_total_after']);
    }

    /**
     * @return array{
     *   target_url:string,
     *   session_cookies:array<int, array<string, mixed>>,
     *   start_date:string,
     *   end_date:string,
     *   expected_summary:array{target_total:int,booked_total:int,open_total:int,fill_rate:float,threshold:float}
     * }
     */
    private function browserSnippetConfig(): array
    {
        return [
            'target_url' => 'http://nginx/index.php/dashboard',
            'session_cookies' => [
                [
                    'name' => 'ci_session',
                    'value' => 'session-value',
                ],
                [
                    'name' => 'csrf_cookie',
                    'value' => 'csrf-value',
                ],
            ],
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'expected_summary' => [
                'target_total' => 12,
                'booked_total' => 8,
                'open_total' => 4,
                'fill_rate' => 2 / 3,
                'threshold' => 0.9,
            ],
        ];
    }
}

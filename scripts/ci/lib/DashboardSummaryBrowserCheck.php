<?php

declare(strict_types=1);

require_once __DIR__ . '/../../release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../../release-gate/lib/PlaywrightCookieRecords.php';
require_once __DIR__ . '/../../release-gate/lib/PlaywrightRunCodePayload.php';

use ReleaseGate\GateAssertionException;
use function ReleaseGate\parsePlaywrightRunCodeJsonPayload;

/**
 * @param array{
 *   target_url:string,
 *   session_cookies:array<int, array<string, mixed>>,
 *   start_date:string,
 *   end_date:string,
 *   expected_summary:array{
 *     target_total:int|float|string,
 *     booked_total:int|float|string,
 *     open_total:int|float|string,
 *     fill_rate:int|float|string,
 *     threshold:int|float|string
 *   }
 * } $config
 */
function dashboardSummaryBrowserBuildRunCodeSnippet(array $config): string
{
    $currentThreshold = max(0.0, min(1.0, (float) ($config['expected_summary']['threshold'] ?? 0)));
    $updatedThreshold = abs($currentThreshold - 0.35) < 0.0001 ? 0.9 : 0.35;
    $config['expected_summary']['updated_threshold'] = $updatedThreshold;
    $config['expected_summary']['updated_threshold_input'] = number_format($updatedThreshold, 2, '.', '');
    $config['expected_summary']['updated_threshold_marker'] =
        rtrim(rtrim(number_format($updatedThreshold * 100, 1, '.', ''), '0'), '.') . '%';

    $encodedTargetUrl = json_encode(
        $config['target_url'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $encodedSessionCookies = json_encode(
        $config['session_cookies'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $encodedStartDate = json_encode(
        $config['start_date'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $encodedEndDate = json_encode(
        $config['end_date'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $encodedExpectedSummary = json_encode(
        $config['expected_summary'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    $snippet = <<<'JS'
    async (page) => {
      const resultPrefix = '__DASHBOARD_SUMMARY_BROWSER_CHECK__';
      const targetUrl = __TARGET_URL__;
      const sessionCookies = __SESSION_COOKIES__;
      const requestedStartDate = __START_DATE__;
      const requestedEndDate = __END_DATE__;
      const expectedSummary = __EXPECTED_SUMMARY__;
      const updatedThreshold = String(expectedSummary.updated_threshold_input || '0.35');
      const expectedUpdatedMarker = String(expectedSummary.updated_threshold_marker || '35%');
      const zeroSummary = {
        target_total: 0,
        booked_total: 0,
        open_total: 0,
        fill_rate: 0,
      };
      let currentStep = 'bootstrap';
      page.setDefaultTimeout(30000);
      const roundCount = (value) => Math.max(0, Math.round(Number(value) || 0));
      const clampProgress = (value) => Math.max(0, Math.min(1, Number(value) || 0));
      const formatPercent = (locale, value) =>
        new Intl.NumberFormat(locale || undefined, {
          style: 'percent',
          minimumFractionDigits: 1,
          maximumFractionDigits: 1,
        }).format(Number(value) || 0);
      const emitPayload = (payload) => {
        console.log(`${resultPrefix}${JSON.stringify(payload)}`);
        return payload;
      };
      const parseCount = (selector) => {
        const text = document.querySelector(selector)?.textContent?.trim() ?? '';
        const digits = text.replace(/[^\d]/g, '');
        return digits === '' ? 0 : Number(digits);
      };
      const captureSummaryState = async (locale, expected = expectedSummary) =>
        page.evaluate(({ locale: dashboardLocale, startDate, endDate, expectedSummaryPayload }) => {
          const roundCount = (value) => Math.max(0, Math.round(Number(value) || 0));
          const clampProgress = (value) => Math.max(0, Math.min(1, Number(value) || 0));
          const formatLocalDate = (value) => {
            const year = value.getFullYear();
            const month = String(value.getMonth() + 1).padStart(2, '0');
            const day = String(value.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
          };
          const parseCount = (selector) => {
            const text = document.querySelector(selector)?.textContent?.trim() ?? '';
            const digits = text.replace(/[^\d]/g, '');
            return digits === '' ? 0 : Number(digits);
          };
          const formatPercent = (localeValue, value) =>
            new Intl.NumberFormat(localeValue || undefined, {
              style: 'percent',
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            }).format(Number(value) || 0);
          const targetTotal = parseCount('#dashboard-summary-target-total');
          const bookedTotal = parseCount('#dashboard-summary-booked-total');
          const openTotal = parseCount('#dashboard-summary-open-total');
          const fillRateText = document.querySelector('#dashboard-summary-fill-rate')?.textContent?.trim() ?? '';
          const bookedShareText = document.querySelector('#dashboard-summary-booked-share')?.textContent?.trim() ?? '';
          const openShareText = document.querySelector('#dashboard-summary-open-share')?.textContent?.trim() ?? '';
          const bookedWidth = document.querySelector('#dashboard-summary-progress-booked')?.style.width ?? '';
          const markerLeft = document.querySelector('#dashboard-summary-progress-marker')?.style.left ?? '';
          const errorHidden = document.querySelector('#dashboard-error')?.hasAttribute('hidden') ?? false;
          const selectedDates = Array.isArray(document.querySelector('#dashboard-date-range')?._flatpickr?.selectedDates)
            ? document.querySelector('#dashboard-date-range')._flatpickr.selectedDates
            : [];
          const normalizedSelectedDates = selectedDates
            .map((value) => {
              if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
                return null;
              }

              return formatLocalDate(value);
            })
            .filter((value) => value !== null);
          const normalizedExpectedTargetTotal = roundCount(expectedSummaryPayload.target_total);
          const normalizedExpectedBookedTotal = roundCount(expectedSummaryPayload.booked_total);
          const normalizedExpectedOpenTotal = roundCount(expectedSummaryPayload.open_total);
          const normalizedExpectedFillRate = Number(expectedSummaryPayload.fill_rate) || 0;
          const normalizedExpectedOpenShare =
            normalizedExpectedTargetTotal > 0 ? normalizedExpectedOpenTotal / normalizedExpectedTargetTotal : 0;
          const expectedFillRateText = formatPercent(dashboardLocale, normalizedExpectedFillRate);
          const expectedOpenShareText = formatPercent(dashboardLocale, normalizedExpectedOpenShare);
          const expectedBookedWidth = `${(clampProgress(normalizedExpectedFillRate) * 100).toFixed(1)}%`;
          const roundedBookedWidth = bookedWidth === '' ? '' : `${(Number.parseFloat(bookedWidth) || 0).toFixed(1)}%`;
          const expectedThreshold = Number(expectedSummaryPayload.threshold) || 0;

          return {
            requested_start_date: startDate,
            requested_end_date: endDate,
            selected_start_date: normalizedSelectedDates[0] ?? '',
            selected_end_date: normalizedSelectedDates[normalizedSelectedDates.length - 1] ?? '',
            target_total: targetTotal,
            booked_total: bookedTotal,
            open_total: openTotal,
            fill_rate: fillRateText,
            booked_share: bookedShareText,
            open_share: openShareText,
            booked_width: roundedBookedWidth,
            marker_left: markerLeft,
            error_hidden: errorHidden,
            expected_target_total: normalizedExpectedTargetTotal,
            expected_booked_total: normalizedExpectedBookedTotal,
            expected_open_total: normalizedExpectedOpenTotal,
            expected_fill_rate: expectedFillRateText,
            expected_open_share: expectedOpenShareText,
            expected_booked_width: expectedBookedWidth,
            expected_threshold: expectedThreshold,
            totals_match:
              targetTotal === normalizedExpectedTargetTotal &&
              bookedTotal === normalizedExpectedBookedTotal &&
              openTotal === normalizedExpectedOpenTotal,
            shares_match:
              fillRateText === expectedFillRateText &&
              bookedShareText === expectedFillRateText &&
              openShareText === expectedOpenShareText,
            booked_width_matches: roundedBookedWidth === expectedBookedWidth,
            requested_range_applied:
              (normalizedSelectedDates[0] ?? '') === startDate &&
              (normalizedSelectedDates[normalizedSelectedDates.length - 1] ?? '') === endDate,
            threshold_badge: document.querySelector('#dashboard-summary-threshold-badge')?.textContent?.trim() ?? '',
          };
        }, {
          locale,
          startDate: requestedStartDate,
          endDate: requestedEndDate,
          expectedSummaryPayload: expected,
        });
      const seedAuthenticatedSession = async () => {
        currentStep = 'seed_authenticated_session';
        await page.context().addCookies(sessionCookies);
      };
      const openAuthenticatedDashboard = async () => {
        currentStep = 'open_authenticated_dashboard';
        await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
      };
      const ensureDashboardReady = async () => {
        currentStep = 'ensure_dashboard_ready';
        const loginFormPresent = await page.evaluate(() => document.getElementById('login-form') !== null);

        if (loginFormPresent) {
          throw new Error('Dashboard session cookies were rejected and the browser was redirected to login.');
        }

        await page.waitForSelector('#dashboard-summary-progress-track', { state: 'attached', timeout: 30000 });
      };
      const applyRequestedRange = async () => {
        currentStep = 'apply_requested_range';
        await page.evaluate(({ startDate, endDate }) => {
          const dateRange = document.querySelector('#dashboard-date-range');
          const flatpickrInstance = dateRange?._flatpickr;

          if (!flatpickrInstance || typeof flatpickrInstance.setDate !== 'function') {
            throw new Error('Dashboard date range picker was not initialized.');
          }

          const toLocalDate = (isoDate) => {
            const [year, month, day] = isoDate.split('-').map((value) => Number(value));
            return new Date(year, month - 1, day);
          };

          flatpickrInstance.setDate([toLocalDate(startDate), toLocalDate(endDate)], false);
        }, {
          startDate: requestedStartDate,
          endDate: requestedEndDate,
        });

        currentStep = 'submit_requested_range';
        await page.click('#dashboard-filters button[type="submit"]');
      };
      const waitForRenderedSummary = async () => {
        currentStep = 'wait_for_loaded_summary';
        await page.waitForSelector('#dashboard-summary-progress-track', { state: 'visible', timeout: 15000 });
        await page.waitForFunction(({ startDate, endDate, expected }) => {
          const roundCount = (value) => Math.max(0, Math.round(Number(value) || 0));
          const clampProgress = (value) => Math.max(0, Math.min(1, Number(value) || 0));
          const parseCount = (selector) => {
            const text = document.querySelector(selector)?.textContent?.trim() ?? '';
            const digits = text.replace(/[^\d]/g, '');
            return digits === '' ? 0 : Number(digits);
          };
          const formatPercent = (locale, value) =>
            new Intl.NumberFormat(locale || undefined, {
              style: 'percent',
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            }).format(Number(value) || 0);
          const fallbackLocale = 'de-DE';
          const configuredLocale = typeof vars === 'function' ? String(vars('dashboard_number_locale') || '').trim() : '';
          const candidate = configuredLocale || fallbackLocale;
          const dashboardLocale = Intl.NumberFormat.supportedLocalesOf([candidate]).length
            ? candidate
            : Intl.NumberFormat.supportedLocalesOf([fallbackLocale]).length
              ? fallbackLocale
              : 'en-US';
          const formatLocalDate = (value) => {
            const year = value.getFullYear();
            const month = String(value.getMonth() + 1).padStart(2, '0');
            const day = String(value.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
          };
          const selectedDates = Array.isArray(document.querySelector('#dashboard-date-range')?._flatpickr?.selectedDates)
            ? document.querySelector('#dashboard-date-range')._flatpickr.selectedDates
            : [];
          const normalizedSelectedDates = selectedDates
            .map((value) => {
              if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
                return null;
              }

              return formatLocalDate(value);
            })
            .filter((value) => value !== null);
          const targetTotal = parseCount('#dashboard-summary-target-total');
          const bookedTotal = parseCount('#dashboard-summary-booked-total');
          const openTotal = parseCount('#dashboard-summary-open-total');
          const fillRateText = document.querySelector('#dashboard-summary-fill-rate')?.textContent?.trim() ?? '';
          const bookedShareText = document.querySelector('#dashboard-summary-booked-share')?.textContent?.trim() ?? '';
          const openShareText = document.querySelector('#dashboard-summary-open-share')?.textContent?.trim() ?? '';
          const bookedWidth = document.querySelector('#dashboard-summary-progress-booked')?.style.width ?? '';
          const markerLeft = document.querySelector('#dashboard-summary-progress-marker')?.style.left ?? '';
          const errorHidden = document.querySelector('#dashboard-error')?.hasAttribute('hidden') ?? false;
          const summaryState = document.querySelector('#dashboard-summary-progress-track')?.getAttribute('data-summary-state') ?? '';
          const expectedTargetTotal = roundCount(expected.target_total);
          const expectedBookedTotal = roundCount(expected.booked_total);
          const expectedOpenTotal = roundCount(expected.open_total);
          const expectedFillRate = Number(expected.fill_rate) || 0;
          const expectedOpenShare = expectedTargetTotal > 0 ? expectedOpenTotal / expectedTargetTotal : 0;
          const expectedBookedWidth = `${(clampProgress(expectedFillRate) * 100).toFixed(1)}%`;
          const roundedBookedWidth = bookedWidth === '' ? '' : `${(Number.parseFloat(bookedWidth) || 0).toFixed(1)}%`;

          return (
            summaryState === 'loaded' &&
            targetTotal === expectedTargetTotal &&
            bookedTotal === expectedBookedTotal &&
            openTotal === expectedOpenTotal &&
            fillRateText === formatPercent(dashboardLocale, expectedFillRate) &&
            bookedShareText === formatPercent(dashboardLocale, expectedFillRate) &&
            openShareText === formatPercent(dashboardLocale, expectedOpenShare) &&
            roundedBookedWidth === expectedBookedWidth &&
            markerLeft !== '' &&
            errorHidden &&
            (normalizedSelectedDates[0] ?? '') === startDate &&
            (normalizedSelectedDates[normalizedSelectedDates.length - 1] ?? '') === endDate
          );
        }, { startDate: requestedStartDate, endDate: requestedEndDate, expected: expectedSummary }, { timeout: 30000 });
      };
      const applyEmptyServiceFilter = async () => {
        currentStep = 'apply_empty_service_filter';
        await page.evaluate((value) => {
          const serviceSelect = document.querySelector('#dashboard-service');

          if (!(serviceSelect instanceof HTMLSelectElement)) {
            throw new Error('Dashboard service filter was not found.');
          }

          if (!Array.from(serviceSelect.options).some((option) => option.value === value)) {
            serviceSelect.add(new Option('Smoke Empty', value, true, true));
          }

          serviceSelect.value = value;
          serviceSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }, '999999');

        currentStep = 'submit_empty_service_filter';
        await page.click('#dashboard-filters button[type="submit"]');
      };
      const waitForZeroState = async () => {
        currentStep = 'wait_for_zero_state';
        await page.waitForFunction(({ startDate, endDate, expected }) => {
          const roundCount = (value) => Math.max(0, Math.round(Number(value) || 0));
          const clampProgress = (value) => Math.max(0, Math.min(1, Number(value) || 0));
          const parseCount = (selector) => {
            const text = document.querySelector(selector)?.textContent?.trim() ?? '';
            const digits = text.replace(/[^\d]/g, '');
            return digits === '' ? 0 : Number(digits);
          };
          const fallbackLocale = 'de-DE';
          const configuredLocale = typeof vars === 'function' ? String(vars('dashboard_number_locale') || '').trim() : '';
          const candidate = configuredLocale || fallbackLocale;
          const dashboardLocale = Intl.NumberFormat.supportedLocalesOf([candidate]).length
            ? candidate
            : Intl.NumberFormat.supportedLocalesOf([fallbackLocale]).length
              ? fallbackLocale
              : 'en-US';
          const formatPercent = (locale, value) =>
            new Intl.NumberFormat(locale || undefined, {
              style: 'percent',
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            }).format(Number(value) || 0);
          const formatLocalDate = (value) => {
            const year = value.getFullYear();
            const month = String(value.getMonth() + 1).padStart(2, '0');
            const day = String(value.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
          };
          const selectedDates = Array.isArray(document.querySelector('#dashboard-date-range')?._flatpickr?.selectedDates)
            ? document.querySelector('#dashboard-date-range')._flatpickr.selectedDates
            : [];
          const normalizedSelectedDates = selectedDates
            .map((value) => {
              if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
                return null;
              }

              return formatLocalDate(value);
            })
            .filter((value) => value !== null);
          const targetTotal = parseCount('#dashboard-summary-target-total');
          const bookedTotal = parseCount('#dashboard-summary-booked-total');
          const openTotal = parseCount('#dashboard-summary-open-total');
          const fillRateText = document.querySelector('#dashboard-summary-fill-rate')?.textContent?.trim() ?? '';
          const bookedShareText = document.querySelector('#dashboard-summary-booked-share')?.textContent?.trim() ?? '';
          const openShareText = document.querySelector('#dashboard-summary-open-share')?.textContent?.trim() ?? '';
          const bookedWidth = document.querySelector('#dashboard-summary-progress-booked')?.style.width ?? '';
          const summaryState = document.querySelector('#dashboard-summary-progress-track')?.getAttribute('data-summary-state') ?? '';
          const errorHidden = document.querySelector('#dashboard-error')?.hasAttribute('hidden') ?? false;
          const expectedTargetTotal = roundCount(expected.target_total);
          const expectedBookedTotal = roundCount(expected.booked_total);
          const expectedOpenTotal = roundCount(expected.open_total);
          const expectedFillRate = Number(expected.fill_rate) || 0;
          const expectedOpenShare = expectedTargetTotal > 0 ? expectedOpenTotal / expectedTargetTotal : 0;
          const expectedBookedWidth = `${(clampProgress(expectedFillRate) * 100).toFixed(1)}%`;
          const roundedBookedWidth = bookedWidth === '' ? '' : `${(Number.parseFloat(bookedWidth) || 0).toFixed(1)}%`;

          return (
            summaryState === 'loaded' &&
            targetTotal === expectedTargetTotal &&
            bookedTotal === expectedBookedTotal &&
            openTotal === expectedOpenTotal &&
            fillRateText === formatPercent(dashboardLocale, expectedFillRate) &&
            bookedShareText === formatPercent(dashboardLocale, expectedFillRate) &&
            openShareText === formatPercent(dashboardLocale, expectedOpenShare) &&
            roundedBookedWidth === expectedBookedWidth &&
            errorHidden &&
            (normalizedSelectedDates[0] ?? '') === startDate &&
            (normalizedSelectedDates[normalizedSelectedDates.length - 1] ?? '') === endDate
          );
        }, { startDate: requestedStartDate, endDate: requestedEndDate, expected: zeroSummary }, { timeout: 30000 });
      };

      try {
        await seedAuthenticatedSession();
        await openAuthenticatedDashboard();
        await ensureDashboardReady();
        await applyRequestedRange();
        await waitForRenderedSummary();

        currentStep = 'read_dashboard_locale';
        const dashboardLocale = await page.evaluate(() => {
          if (typeof resolveDashboardLocale === 'function') {
            return resolveDashboardLocale();
          }

          const fallbackLocale = 'de-DE';
          const configuredLocale = typeof vars === 'function' ? String(vars('dashboard_number_locale') || '').trim() : '';
          const candidate = configuredLocale || fallbackLocale;

          if (Intl.NumberFormat.supportedLocalesOf([candidate]).length) {
            return candidate;
          }

          if (Intl.NumberFormat.supportedLocalesOf([fallbackLocale]).length) {
            return fallbackLocale;
          }

          return 'en-US';
        });
        const expectedThresholdText = formatPercent(
          dashboardLocale,
          Number(expectedSummary.updated_threshold) || Number(updatedThreshold) || 0,
        );

        currentStep = 'capture_before_state';
        const before = await captureSummaryState(dashboardLocale);

        currentStep = 'open_options_dropdown';
        await page.evaluate(() => {
          const optionsToggle = document.querySelector('#dashboard-options-toggle');

          if (!(optionsToggle instanceof HTMLElement)) {
            throw new Error('Dashboard options toggle was not found.');
          }

          if (typeof bootstrap === 'undefined' || !bootstrap?.Dropdown) {
            throw new Error('Bootstrap dropdown runtime was not available.');
          }

          bootstrap.Dropdown.getOrCreateInstance(optionsToggle).show();
        });
        await page.waitForSelector('.dashboard-options-dropdown.show', { state: 'visible', timeout: 5000 });
        currentStep = 'click_threshold_button';
        await page.click('#dashboard-threshold-button');
        currentStep = 'wait_for_threshold_modal';
        await page.waitForSelector('#dashboard-threshold-modal.show', { state: 'visible', timeout: 5000 });
        await page.waitForSelector('#dashboard-threshold-input', { state: 'visible', timeout: 5000 });
        currentStep = 'capture_threshold_modal_state';
        const thresholdModalValueBefore = await page.inputValue('#dashboard-threshold-input');
        const thresholdModalMatchesBefore =
          Math.abs((Number.parseFloat(thresholdModalValueBefore) || 0) - (Number(before.expected_threshold) || 0)) < 0.0001;
        currentStep = 'fill_threshold_input';
        await page.fill('#dashboard-threshold-input', updatedThreshold);
        await page.waitForSelector('#dashboard-threshold-form button[type="submit"]', { state: 'visible', timeout: 5000 });
        currentStep = 'submit_threshold_form';
        await page.click('#dashboard-threshold-form button[type="submit"]');
        await page.waitForSelector('#dashboard-threshold-modal.show', { state: 'hidden', timeout: 15000 });

        currentStep = 'wait_for_updated_marker';
        await page.waitForFunction((expectedMarker) => {
          const markerLeft = document.querySelector('#dashboard-summary-progress-marker')?.style.left ?? '';
          const badgeText = document.querySelector('#dashboard-summary-threshold-badge')?.textContent?.trim() ?? '';
          return markerLeft === expectedMarker && badgeText !== '';
        }, expectedUpdatedMarker, { timeout: 15000 });

        currentStep = 'capture_after_state';
        const after = await captureSummaryState(dashboardLocale);

        await applyEmptyServiceFilter();
        await waitForZeroState();
        currentStep = 'capture_zero_state';
        const zeroState = await captureSummaryState(dashboardLocale, zeroSummary);

        const badgeChanged = before.threshold_badge !== after.threshold_badge;
        const localeApplied = after.threshold_badge.includes(expectedThresholdText);
        const summaryStable =
          before.target_total === after.target_total &&
          before.booked_total === after.booked_total &&
          before.open_total === after.open_total &&
          before.fill_rate === after.fill_rate &&
          before.booked_share === after.booked_share &&
          before.open_share === after.open_share &&
          before.booked_width === after.booked_width;
        const zeroStateRendered =
          zeroState.totals_match &&
          zeroState.shares_match &&
          zeroState.booked_width_matches &&
          zeroState.requested_range_applied &&
          zeroState.error_hidden;

        return emitPayload({
          dashboard_summary_browser_check: true,
          ok:
            before.marker_left !== '' &&
            after.marker_left === expectedUpdatedMarker &&
            before.totals_match &&
            before.shares_match &&
            before.booked_width_matches &&
            before.requested_range_applied &&
            after.totals_match &&
            after.shares_match &&
            after.booked_width_matches &&
            after.requested_range_applied &&
            summaryStable &&
            zeroStateRendered &&
            thresholdModalMatchesBefore &&
            badgeChanged &&
            localeApplied,
          target_total_before: before.target_total,
          booked_total_before: before.booked_total,
          open_total_before: before.open_total,
          fill_rate_before: before.fill_rate,
          booked_share_before: before.booked_share,
          open_share_before: before.open_share,
          booked_width_before: before.booked_width,
          expected_fill_rate: before.expected_fill_rate,
          expected_open_share: before.expected_open_share,
          error_hidden_before: before.error_hidden,
          threshold_badge_before: before.threshold_badge,
          threshold_modal_value_before: thresholdModalValueBefore,
          threshold_modal_matches_before: thresholdModalMatchesBefore,
          marker_left_before: before.marker_left,
          requested_range_applied_before: before.requested_range_applied,
          selected_start_date_before: before.selected_start_date,
          selected_end_date_before: before.selected_end_date,
          totals_match_before: before.totals_match,
          shares_match_before: before.shares_match,
          booked_width_matches_before: before.booked_width_matches,
          target_total_after: after.target_total,
          booked_total_after: after.booked_total,
          open_total_after: after.open_total,
          fill_rate_after: after.fill_rate,
          booked_share_after: after.booked_share,
          open_share_after: after.open_share,
          booked_width_after: after.booked_width,
          error_hidden_after: after.error_hidden,
          threshold_badge_after: after.threshold_badge,
          marker_left_after: after.marker_left,
          requested_range_applied_after: after.requested_range_applied,
          selected_start_date_after: after.selected_start_date,
          selected_end_date_after: after.selected_end_date,
          totals_match_after: after.totals_match,
          shares_match_after: after.shares_match,
          booked_width_matches_after: after.booked_width_matches,
          expected_target_total: before.expected_target_total,
          expected_booked_total: before.expected_booked_total,
          expected_open_total: before.expected_open_total,
          expected_initial_threshold: before.expected_threshold,
          expected_marker_after: expectedUpdatedMarker,
          dashboard_locale: dashboardLocale,
          expected_threshold_text: expectedThresholdText,
          zero_state_rendered: zeroStateRendered,
          error: !before.totals_match
            ? `Dashboard summary totals did not match target=${before.expected_target_total}, booked=${before.expected_booked_total}, open=${before.expected_open_total}.`
            : !before.shares_match
            ? `Dashboard summary shares did not match fill=${before.expected_fill_rate} and open=${before.expected_open_share}.`
            : !before.booked_width_matches
            ? `Dashboard summary progress fill did not match ${before.expected_booked_width}.`
            : !before.requested_range_applied
            ? `Dashboard date range did not apply ${requestedStartDate} to ${requestedEndDate}.`
            : !before.error_hidden
            ? 'Dashboard error banner was visible before threshold save.'
            : before.marker_left === ''
            ? 'Summary marker was not rendered before threshold save.'
            : !thresholdModalMatchesBefore
            ? `Threshold modal default ${thresholdModalValueBefore} did not match expected ${before.expected_threshold}.`
            : !after.totals_match
              ? 'Dashboard summary totals changed after threshold save.'
            : !after.shares_match
              ? 'Dashboard summary shares changed after threshold save.'
            : !after.booked_width_matches
              ? 'Dashboard summary progress fill changed after threshold save.'
            : !after.requested_range_applied
              ? `Dashboard date range drifted after threshold save.`
            : !after.error_hidden
                ? 'Dashboard error banner became visible after threshold save.'
            : !summaryStable
              ? 'Dashboard summary KPIs changed after threshold save.'
            : !zeroStateRendered
              ? 'Dashboard zero-state summary did not render as expected.'
            : after.marker_left !== expectedUpdatedMarker
              ? 'Threshold marker did not update after save.'
            : !badgeChanged
                ? 'Threshold badge did not update after save.'
                : !localeApplied
                  ? `Threshold badge did not use locale text ${expectedThresholdText}.`
                  : null,
        });
      } catch (error) {
        return emitPayload({
          dashboard_summary_browser_check: true,
          ok: false,
          error: `${currentStep}: ${error instanceof Error ? error.message : String(error)}`,
        });
      }
    }
    JS;

    return trim(
        strtr($snippet, [
            '__TARGET_URL__' => $encodedTargetUrl,
            '__SESSION_COOKIES__' => $encodedSessionCookies,
            '__START_DATE__' => $encodedStartDate,
            '__END_DATE__' => $encodedEndDate,
            '__EXPECTED_SUMMARY__' => $encodedExpectedSummary,
        ]),
    );
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function dashboardSummaryBrowserParseRunCodeResult(array $result): array
{
    return parsePlaywrightRunCodeJsonPayload(
        (string) ($result['stdout'] ?? ''),
        '__DASHBOARD_SUMMARY_BROWSER_CHECK__',
        'dashboard summary browser',
    );
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function dashboardSummaryBrowserAssertPayload(array $payload): array
{
    if (!(bool) ($payload['ok'] ?? false)) {
        $error = trim((string) ($payload['error'] ?? 'Dashboard summary browser render failed.'));
        throw new GateAssertionException($error !== '' ? $error : 'Dashboard summary browser render failed.');
    }

    $requiredKeys = [
        'dashboard_summary_browser_check',
        'ok',
        'expected_fill_rate',
        'target_total_before',
        'open_total_before',
        'booked_total_after',
        'open_total_after',
        'target_total_after',
        'booked_total_before',
        'fill_rate_before',
        'fill_rate_after',
        'booked_share_before',
        'booked_share_after',
        'open_share_before',
        'open_share_after',
        'error_hidden_before',
        'error_hidden_after',
        'threshold_badge_before',
        'threshold_badge_after',
        'threshold_modal_value_before',
        'threshold_modal_matches_before',
        'marker_left_before',
        'marker_left_after',
        'requested_range_applied_before',
        'requested_range_applied_after',
        'totals_match_before',
        'totals_match_after',
        'shares_match_before',
        'shares_match_after',
        'booked_width_matches_before',
        'booked_width_matches_after',
        'expected_initial_threshold',
        'expected_marker_after',
        'expected_threshold_text',
        'dashboard_locale',
        'zero_state_rendered',
    ];

    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $payload)) {
            throw new GateAssertionException('Dashboard summary browser render payload misses key "' . $key . '".');
        }
    }

    if (!(bool) ($payload['dashboard_summary_browser_check'] ?? false)) {
        throw new GateAssertionException('Dashboard summary browser render payload is missing its sentinel flag.');
    }

    if (!(bool) $payload['totals_match_before']) {
        throw new GateAssertionException(
            'Dashboard summary totals did not match the requested range before threshold save.',
        );
    }

    if (!(bool) $payload['shares_match_before']) {
        throw new GateAssertionException(
            'Dashboard summary shares did not match the rendered totals before threshold save.',
        );
    }

    if (!(bool) $payload['booked_width_matches_before']) {
        throw new GateAssertionException(
            'Dashboard summary progress fill did not match the rendered totals before threshold save.',
        );
    }

    if (!(bool) $payload['requested_range_applied_before']) {
        throw new GateAssertionException(
            'Dashboard summary did not apply the requested date range before threshold save.',
        );
    }

    if (!(bool) $payload['totals_match_after']) {
        throw new GateAssertionException('Dashboard summary totals changed after threshold save.');
    }

    if (!(bool) $payload['shares_match_after']) {
        throw new GateAssertionException('Dashboard summary shares changed after threshold save.');
    }

    if (!(bool) $payload['booked_width_matches_after']) {
        throw new GateAssertionException('Dashboard summary progress fill changed after threshold save.');
    }

    if (!(bool) $payload['requested_range_applied_after']) {
        throw new GateAssertionException('Dashboard summary date range drifted after threshold save.');
    }

    if (!(bool) $payload['zero_state_rendered']) {
        throw new GateAssertionException('Dashboard summary zero state did not render as expected.');
    }

    if (!(bool) $payload['error_hidden_before']) {
        throw new GateAssertionException(
            'Dashboard summary rendered with a visible error banner before threshold save.',
        );
    }

    if (!(bool) $payload['error_hidden_after']) {
        throw new GateAssertionException(
            'Dashboard summary rendered with a visible error banner after threshold save.',
        );
    }

    $markerLeftBefore = trim((string) $payload['marker_left_before']);
    $markerLeftAfter = trim((string) $payload['marker_left_after']);
    $thresholdBadgeBefore = trim((string) $payload['threshold_badge_before']);
    $thresholdBadgeAfter = trim((string) $payload['threshold_badge_after']);
    $expectedThresholdText = trim((string) $payload['expected_threshold_text']);

    if ($markerLeftBefore === '') {
        throw new GateAssertionException('Dashboard summary marker did not render before threshold save.');
    }

    if (!(bool) $payload['threshold_modal_matches_before']) {
        throw new GateAssertionException(
            sprintf(
                'Dashboard summary threshold modal default did not match the loaded threshold (%s vs %s).',
                trim((string) $payload['threshold_modal_value_before']),
                trim((string) $payload['expected_initial_threshold']),
            ),
        );
    }

    if ($markerLeftAfter !== trim((string) $payload['expected_marker_after'])) {
        throw new GateAssertionException('Dashboard summary marker did not update to the saved threshold.');
    }

    if ($thresholdBadgeBefore === $thresholdBadgeAfter) {
        throw new GateAssertionException('Dashboard summary threshold badge did not change after threshold save.');
    }

    if ($expectedThresholdText !== '' && !str_contains($thresholdBadgeAfter, $expectedThresholdText)) {
        throw new GateAssertionException(
            'Dashboard summary threshold badge did not use the expected locale formatting text.',
        );
    }

    if ((int) $payload['target_total_before'] !== (int) $payload['target_total_after']) {
        throw new GateAssertionException('Dashboard summary total-parent count changed after threshold save.');
    }

    if ((int) $payload['booked_total_before'] !== (int) $payload['booked_total_after']) {
        throw new GateAssertionException('Dashboard summary booked count changed after threshold save.');
    }

    if ((int) $payload['open_total_before'] !== (int) $payload['open_total_after']) {
        throw new GateAssertionException('Dashboard summary open count changed after threshold save.');
    }

    if (trim((string) $payload['fill_rate_before']) !== trim((string) $payload['fill_rate_after'])) {
        throw new GateAssertionException('Dashboard summary fill-rate KPI changed after threshold save.');
    }

    if (trim((string) $payload['open_share_before']) !== trim((string) $payload['open_share_after'])) {
        throw new GateAssertionException('Dashboard summary open-share KPI changed after threshold save.');
    }

    return $payload;
}

async (page) => {
    const resultPrefix = '__PROVIDER_UI_SMOKE_GATE__';
    const config = __PROVIDER_UI_SMOKE_CONFIG__;
    const result = {
        ok: false,
        network_policy_installed: false,
        dashboard_loaded: false,
        buttons_present: false,
        script_vars_safe: false,
        primary_metrics_status_ok: false,
        primary_row_matches: false,
        preparation_downloaded: false,
        parent_downloaded: false,
        empty_metrics_status_ok: false,
        empty_state_visible: false,
        empty_preparation_downloaded: false,
        restore_metrics_status_ok: false,
        primary_row_count: 0,
        empty_row_count: 0,
        blocked_request_count: 0,
        page_error_count: 0,
        console_error_count: 0,
        flow_error_count: 0,
    };

    const emit = () => {
        return `${resultPrefix}${JSON.stringify(result)}`;
    };
    const context = page.context();
    const allowedOrigin = config.allowed_origin;
    const normalizedPath = (pathname) => {
        if (pathname.length > 1 && pathname.endsWith('/')) {
            return pathname.slice(0, -1);
        }

        return pathname;
    };
    const parseAllowedHttpUrl = (value) => {
        if (typeof value !== 'string' || !value.startsWith(allowedOrigin)) {
            return null;
        }

        const remainder = value.slice(allowedOrigin.length);
        if (remainder !== '' && !remainder.startsWith('/') && !remainder.startsWith('?')) {
            return null;
        }

        if (remainder.includes('#')) {
            return null;
        }

        const queryIndex = remainder.indexOf('?');
        const pathname = queryIndex === -1 ? remainder || '/' : remainder.slice(0, queryIndex) || '/';
        const search = queryIndex === -1 ? '' : remainder.slice(queryIndex);

        return {
            pathname,
            search,
        };
    };
    const decodeFormComponent = (value) => decodeURIComponent(value.replace(/\+/g, ' '));
    const parseFormEncoded = (value) => {
        if (typeof value !== 'string' || value === '') {
            return null;
        }

        const parsed = {};

        for (const pair of value.split('&')) {
            const separator = pair.indexOf('=');
            const rawKey = separator === -1 ? pair : pair.slice(0, separator);
            const rawValue = separator === -1 ? '' : pair.slice(separator + 1);
            let key;
            let decodedValue;

            try {
                key = decodeFormComponent(rawKey);
                decodedValue = decodeFormComponent(rawValue);
            } catch (_error) {
                return null;
            }

            if (!key || Object.prototype.hasOwnProperty.call(parsed, key)) {
                return null;
            }

            parsed[key] = decodedValue;
        }

        return parsed;
    };
    const hasExactKeys = (value, expectedKeys) => {
        if (!value || typeof value !== 'object') {
            return false;
        }

        const actualKeys = Object.keys(value).sort();
        const sortedExpectedKeys = [...expectedKeys].sort();

        return (
            actualKeys.length === sortedExpectedKeys.length &&
            actualKeys.every((key, index) => key === sortedExpectedKeys[index])
        );
    };
    const dashboardPath = normalizedPath(config.dashboard_route_path);
    const metricsPath = normalizedPath(config.metrics_route_path);
    const preparationPdfPath = normalizedPath(config.preparation_pdf_route_path);
    const parentPdfPath = normalizedPath(config.parent_pdf_route_path);
    const assetPathPrefix = config.asset_path_prefix.endsWith('/')
        ? config.asset_path_prefix
        : `${config.asset_path_prefix}/`;
    const allowedPdfRanges = new Set([
        `${config.primary_start_date}|${config.primary_end_date}`,
        `${config.empty_start_date}|${config.empty_end_date}`,
    ]);
    const allowedMetricRanges = new Set([
        `${config.restore_start_date}|${config.restore_end_date}`,
        `${config.primary_start_date}|${config.primary_end_date}`,
        `${config.empty_start_date}|${config.empty_end_date}`,
    ]);
    const isAllowedPdfQuery = (search) => {
        if (!search.startsWith('?')) {
            return false;
        }

        const params = parseFormEncoded(search.slice(1));

        return (
            hasExactKeys(params, ['start_date', 'end_date']) &&
            allowedPdfRanges.has(`${params.start_date}|${params.end_date}`)
        );
    };
    const isAllowedMetricsRequest = (parsedRequestUrl, request) => {
        if (parsedRequestUrl.search !== '') {
            return false;
        }

        const params = parseFormEncoded(request.postData() || '');

        if (!hasExactKeys(params, ['csrf_token', 'start_date', 'end_date']) || !params.csrf_token) {
            return false;
        }

        return allowedMetricRanges.has(`${params.start_date}|${params.end_date}`);
    };
    const routeHandler = async (route) => {
        const request = route.request();
        const method = request.method().toUpperCase();
        const requestUrl = request.url();

        if (requestUrl.startsWith('data:') || requestUrl.startsWith('blob:')) {
            await route.continue();
            return;
        }

        const parsedRequestUrl = parseAllowedHttpUrl(requestUrl);

        if (!parsedRequestUrl) {
            result.blocked_request_count += 1;
            await route.abort('blockedbyclient');
            return;
        }

        const path = normalizedPath(parsedRequestUrl.pathname);
        const staticAsset =
            (method === 'GET' || method === 'HEAD') &&
            (parsedRequestUrl.pathname.startsWith(assetPathPrefix) || path === normalizedPath(config.favicon_path));
        const dashboardPage = method === 'GET' && path === dashboardPath && parsedRequestUrl.search === '';
        const providerMetrics =
            method === 'POST' && path === metricsPath && isAllowedMetricsRequest(parsedRequestUrl, request);
        const providerPdf =
            method === 'GET' &&
            (path === preparationPdfPath || path === parentPdfPath) &&
            isAllowedPdfQuery(parsedRequestUrl.search);

        if (staticAsset || dashboardPage || providerMetrics || providerPdf) {
            await route.continue();
            return;
        }

        result.blocked_request_count += 1;
        await route.abort('blockedbyclient');
    };
    const pageErrorHandler = () => {
        result.page_error_count += 1;
    };
    const consoleHandler = (message) => {
        if (message && typeof message.type === 'function' && message.type() === 'error') {
            result.console_error_count += 1;
        }
    };
    const responseMatchesRange = (response, startDate, endDate) => {
        try {
            const responseUrl = parseAllowedHttpUrl(response.url());
            if (
                !responseUrl ||
                normalizedPath(responseUrl.pathname) !== metricsPath ||
                responseUrl.search !== '' ||
                response.request().method().toUpperCase() !== 'POST'
            ) {
                return false;
            }

            const params = parseFormEncoded(response.request().postData() || '');
            return (
                hasExactKeys(params, ['csrf_token', 'start_date', 'end_date']) &&
                params.start_date === startDate &&
                params.end_date === endDate
            );
        } catch (_error) {
            return false;
        }
    };
    const applyRange = async (startDate, endDate) => {
        await page.waitForFunction(
            () => {
                const input = document.querySelector('#dashboard-teacher-date-range');
                return Boolean(input && input._flatpickr && typeof input._flatpickr.setDate === 'function');
            },
            null,
            {timeout: config.open_timeout_ms},
        );

        await page.evaluate(
            ({start, end}) => {
                const parseLocalDate = (isoDate) => {
                    const parts = isoDate.split('-').map((part) => Number(part));
                    return new Date(parts[0], parts[1] - 1, parts[2]);
                };
                const input = document.querySelector('#dashboard-teacher-date-range');
                input._flatpickr.setDate([parseLocalDate(start), parseLocalDate(end)], false);
            },
            {start: startDate, end: endDate},
        );

        const [response] = await Promise.all([
            page.waitForResponse((candidate) => responseMatchesRange(candidate, startDate, endDate), {
                timeout: config.open_timeout_ms,
            }),
            page.locator('#dashboard-teacher-filters button[type="submit"]').click({
                timeout: config.open_timeout_ms,
            }),
        ]);

        return response.status() === 200;
    };
    const downloadFromButton = async (selector, targetPath) => {
        const [download] = await Promise.all([
            page.waitForEvent('download', {timeout: config.download_timeout_ms}),
            page.locator(selector).click({timeout: config.download_timeout_ms}),
        ]);

        await download.saveAs(targetPath);
        return (await download.failure()) === null;
    };
    const expectedDateDisplay = async () => {
        return page.evaluate((isoDate) => {
            const [year, month, day] = isoDate.split('-');
            const format = typeof window.vars === 'function' ? window.vars('date_format') : '';

            if (format === 'DMY') {
                return `${day}/${month}/${year}`;
            }

            if (format === 'MDY') {
                return `${month}/${day}/${year}`;
            }

            if (format === 'YMD') {
                return `${year}/${month}/${day}`;
            }

            return '';
        }, config.primary_start_date);
    };
    const expectedTimeDisplay = async (militaryTime) => {
        return page.evaluate((value) => {
            const format = typeof window.vars === 'function' ? window.vars('time_format') : '';
            if (format === 'military') {
                return value;
            }

            if (format !== 'regular') {
                return '';
            }

            const [hourText, minute] = value.split(':');
            const hour = Number(hourText);
            const normalizedHour = hour % 12 === 0 ? 12 : hour % 12;
            return `${normalizedHour}:${minute} ${hour >= 12 ? 'pm' : 'am'}`;
        }, militaryTime);
    };

    page.on('pageerror', pageErrorHandler);
    page.on('console', consoleHandler);

    try {
        await context.route('**/*', routeHandler);
        result.network_policy_installed = true;

        const response = await page.goto(config.dashboard_url, {
            waitUntil: 'domcontentloaded',
            timeout: config.open_timeout_ms,
        });
        const loadedUrl = parseAllowedHttpUrl(page.url());
        result.dashboard_loaded =
            Boolean(response) &&
            response.status() === 200 &&
            Boolean(loadedUrl) &&
            normalizedPath(loadedUrl.pathname) === dashboardPath &&
            loadedUrl.search === '' &&
            (await page.locator('#dashboard-teacher-page').count()) === 1;

        const preparationButton = page.locator('#dashboard-teacher-preparation-export');
        const parentButton = page.locator('#dashboard-teacher-parent-export');
        result.buttons_present =
            (await preparationButton.count()) === 1 &&
            (await parentButton.count()) === 1 &&
            (await preparationButton.isVisible()) &&
            (await parentButton.isVisible());

        result.script_vars_safe = await page.evaluate((forbiddenKeys) => {
            if (typeof window.vars !== 'function') {
                return false;
            }

            return forbiddenKeys.every((key) => window.vars(key) === undefined);
        }, config.forbidden_script_var_keys);

        result.primary_metrics_status_ok = await applyRange(config.primary_start_date, config.primary_end_date);

        await page.waitForFunction(
            () => document.querySelectorAll('#dashboard-teacher-table-body tr').length === 1,
            null,
            {timeout: config.open_timeout_ms},
        );

        const rows = page.locator('#dashboard-teacher-table-body tr');
        result.primary_row_count = await rows.count();
        const rowCells = result.primary_row_count === 1 ? await rows.first().locator('td').allTextContents() : [];
        const expectedDate = await expectedDateDisplay();
        const expectedStart = await expectedTimeDisplay(config.booked_start_time);
        const expectedEnd = await expectedTimeDisplay(config.booked_end_time);
        result.primary_row_matches =
            rowCells.length === 4 &&
            rowCells[0].trim() === config.customer_last_name &&
            rowCells[1].trim() === expectedDate &&
            rowCells[2].trim().toLowerCase() === expectedStart &&
            rowCells[3].trim().toLowerCase() === expectedEnd;

        result.preparation_downloaded = await downloadFromButton(
            '#dashboard-teacher-preparation-export',
            config.preparation_pdf_path,
        );
        result.parent_downloaded = await downloadFromButton('#dashboard-teacher-parent-export', config.parent_pdf_path);

        result.empty_metrics_status_ok = await applyRange(config.empty_start_date, config.empty_end_date);
        await page.waitForFunction(
            () =>
                document.querySelectorAll('#dashboard-teacher-table-body tr').length === 0 &&
                document.querySelector('#dashboard-teacher-empty')?.hidden === false,
            null,
            {timeout: config.open_timeout_ms},
        );

        result.empty_row_count = await page.locator('#dashboard-teacher-table-body tr').count();
        result.empty_state_visible = await page.locator('#dashboard-teacher-empty').isVisible();
        result.empty_preparation_downloaded = await downloadFromButton(
            '#dashboard-teacher-preparation-export',
            config.empty_preparation_pdf_path,
        );

        result.restore_metrics_status_ok = await applyRange(config.restore_start_date, config.restore_end_date);

        await page.waitForTimeout(1000);
        result.ok =
            result.network_policy_installed &&
            result.dashboard_loaded &&
            result.buttons_present &&
            result.script_vars_safe &&
            result.primary_metrics_status_ok &&
            result.primary_row_count === 1 &&
            result.primary_row_matches &&
            result.preparation_downloaded &&
            result.parent_downloaded &&
            result.empty_metrics_status_ok &&
            result.empty_row_count === 0 &&
            result.empty_state_visible &&
            result.empty_preparation_downloaded &&
            result.restore_metrics_status_ok &&
            result.blocked_request_count === 0 &&
            result.page_error_count === 0 &&
            result.console_error_count === 0 &&
            result.flow_error_count === 0;
    } catch (_error) {
        result.flow_error_count += 1;
        result.ok = false;
    }

    return emit();
};

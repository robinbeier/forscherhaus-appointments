async (page) => {
    const resultPrefix = '__CUSTOMERS_UI_SMOKE_GATE__';
    const config = __CUSTOMERS_UI_SMOKE_CONFIG__;
    const result = {
        ok: false,
        network_policy_installed: false,
        page_loaded: false,
        initial_search_empty: false,
        synthetic_search_empty: false,
        empty_state_visible: false,
        script_vars_safe: false,
        dom_safe: false,
        response_bodies_safe: false,
        search_response_count: 0,
        blocked_request_count: 0,
        page_error_count: 0,
        console_error_count: 0,
        flow_error_count: 0,
    };
    const emit = () => `${resultPrefix}${JSON.stringify(result)}`;
    const context = page.context();
    const normalizedPath = (pathname) =>
        pathname.length > 1 && pathname.endsWith('/') ? pathname.slice(0, -1) : pathname;
    const parseAllowedUrl = (value) => {
        if (typeof value !== 'string' || !value.startsWith(config.allowed_origin)) {
            return null;
        }

        const remainder = value.slice(config.allowed_origin.length);
        if (remainder !== '' && !remainder.startsWith('/') && !remainder.startsWith('?')) {
            return null;
        }

        const queryIndex = remainder.indexOf('?');
        return {
            pathname: queryIndex === -1 ? remainder || '/' : remainder.slice(0, queryIndex) || '/',
            search: queryIndex === -1 ? '' : remainder.slice(queryIndex),
        };
    };
    const parseForm = (body) => {
        if (typeof body !== 'string') {
            return null;
        }

        const values = {};
        for (const pair of body.split('&')) {
            const separator = pair.indexOf('=');
            const rawKey = separator === -1 ? pair : pair.slice(0, separator);
            const rawValue = separator === -1 ? '' : pair.slice(separator + 1);

            try {
                const key = decodeURIComponent(rawKey.replace(/\+/g, ' '));
                const value = decodeURIComponent(rawValue.replace(/\+/g, ' '));
                if (!key || Object.prototype.hasOwnProperty.call(values, key)) {
                    return null;
                }
                values[key] = value;
            } catch (_error) {
                return null;
            }
        }

        return values;
    };
    const isAllowedSearch = (request, parsedUrl) => {
        if (parsedUrl.search !== '') {
            return false;
        }

        const values = parseForm(request.postData() || '');
        if (!values || !values.csrf_token || !['', config.search_marker].includes(values.keyword)) {
            return false;
        }

        const allowedKeys = new Set(['csrf_token', 'keyword', 'limit', 'offset']);
        return Object.keys(values).every((key) => allowedKeys.has(key));
    };
    const routeHandler = async (route) => {
        const request = route.request();
        const method = request.method().toUpperCase();
        const url = request.url();

        if (url.startsWith('data:') || url.startsWith('blob:')) {
            await route.continue();
            return;
        }

        const parsed = parseAllowedUrl(url);
        if (!parsed) {
            result.blocked_request_count += 1;
            await route.abort('blockedbyclient');
            return;
        }

        const path = normalizedPath(parsed.pathname);
        const staticAsset =
            (method === 'GET' || method === 'HEAD') &&
            (parsed.pathname.startsWith(config.asset_path_prefix) || path === normalizedPath(config.favicon_path));
        const customersPage =
            method === 'GET' && path === normalizedPath(config.customers_route_path) && parsed.search === '';
        const customersSearch =
            method === 'POST' && path === normalizedPath(config.search_route_path) && isAllowedSearch(request, parsed);

        if (staticAsset || customersPage || customersSearch) {
            await route.continue();
            return;
        }

        result.blocked_request_count += 1;
        await route.abort('blockedbyclient');
    };
    const isSearchResponse = (response, expectedKeyword) => {
        const parsed = parseAllowedUrl(response.url());
        const values = parseForm(response.request().postData() || '');
        return (
            parsed &&
            normalizedPath(parsed.pathname) === normalizedPath(config.search_route_path) &&
            response.request().method().toUpperCase() === 'POST' &&
            values &&
            values.keyword === expectedKeyword
        );
    };
    const readSafeEmptySearch = async (response) => {
        const body = await response.text();
        result.search_response_count += 1;
        if (config.forbidden_response_markers.some((key) => body.includes(key))) {
            result.response_bodies_safe = false;
        }

        try {
            const decoded = JSON.parse(body);
            return response.status() === 200 && Array.isArray(decoded) && decoded.length === 0;
        } catch (_error) {
            return false;
        }
    };

    page.on('pageerror', () => {
        result.page_error_count += 1;
    });
    page.on('console', (message) => {
        if (message && typeof message.type === 'function' && message.type() === 'error') {
            result.console_error_count += 1;
        }
    });

    try {
        result.response_bodies_safe = true;
        await context.route('**/*', routeHandler);
        result.network_policy_installed = true;

        const initialSearchPromise = page.waitForResponse((response) => isSearchResponse(response, ''), {
            timeout: config.open_timeout_ms,
        });
        const pageResponse = await page.goto(config.customers_url, {
            waitUntil: 'domcontentloaded',
            timeout: config.open_timeout_ms,
        });
        result.page_loaded =
            Boolean(pageResponse) &&
            pageResponse.status() === 200 &&
            (await page.locator('#customers-page').count()) === 1;
        result.initial_search_empty = await readSafeEmptySearch(await initialSearchPromise);

        result.script_vars_safe = await page.evaluate((forbiddenKeys) => {
            if (typeof window.vars !== 'function') {
                return false;
            }

            return forbiddenKeys.every((key) => window.vars(key) === undefined);
        }, config.forbidden_keys);
        result.dom_safe = await page.evaluate(
            (forbiddenMarkers) => forbiddenMarkers.every((key) => !document.documentElement.innerHTML.includes(key)),
            config.forbidden_response_markers,
        );

        await page.locator('#filter-customers .key').fill(config.search_marker);
        const syntheticSearchPromise = page.waitForResponse(
            (response) => isSearchResponse(response, config.search_marker),
            {timeout: config.open_timeout_ms},
        );
        await page.locator('#filter-customers form').press('Enter');
        result.synthetic_search_empty = await readSafeEmptySearch(await syntheticSearchPromise);

        await page.waitForFunction(
            () =>
                document.querySelectorAll('#filter-customers .customer-row').length === 0 &&
                Boolean(document.querySelector('#filter-customers .results em')),
            null,
            {timeout: config.open_timeout_ms},
        );
        result.empty_state_visible = await page.locator('#filter-customers .results em').isVisible();
        await page.waitForTimeout(250);

        result.ok =
            result.network_policy_installed &&
            result.page_loaded &&
            result.initial_search_empty &&
            result.synthetic_search_empty &&
            result.empty_state_visible &&
            result.script_vars_safe &&
            result.dom_safe &&
            result.response_bodies_safe &&
            result.search_response_count === 2 &&
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

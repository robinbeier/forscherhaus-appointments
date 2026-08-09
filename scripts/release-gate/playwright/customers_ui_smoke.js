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
        initial_search_request_seen: false,
        initial_search_request_allowed: false,
        initial_search_response_seen: false,
        initial_search_body_read: false,
        synthetic_search_request_seen: false,
        synthetic_search_request_allowed: false,
        synthetic_search_response_seen: false,
        synthetic_search_body_read: false,
        search_response_count: 0,
        blocked_request_count: 0,
        blocked_initial_search_post_count: 0,
        blocked_synthetic_search_post_count: 0,
        blocked_navigation_count: 0,
        blocked_static_asset_count: 0,
        blocked_other_same_origin_count: 0,
        blocked_cross_origin_count: 0,
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

        const values = Object.create(null);
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
        if (!values) {
            return false;
        }

        const expectedKeys = ['csrf_token', 'keyword', 'limit', 'offset', 'order_by'];
        return (
            Object.keys(values).length === expectedKeys.length &&
            expectedKeys.every((key) => Object.prototype.hasOwnProperty.call(values, key)) &&
            values.csrf_token !== '' &&
            ['', config.search_marker].includes(values.keyword) &&
            values.limit === '20' &&
            values.offset === '' &&
            values.order_by === ''
        );
    };
    let activeSearchCategory = 'initial_search_post';
    const classifyRequest = (request, parsedUrl, method, path) => {
        if (!parsedUrl) {
            return 'cross_origin';
        }

        if (method === 'POST' && path === normalizedPath(config.search_route_path) && activeSearchCategory !== null) {
            return activeSearchCategory;
        }

        if (path === normalizedPath(config.customers_route_path)) {
            return 'navigation';
        }

        if (parsedUrl.pathname.startsWith(config.asset_path_prefix) || path === normalizedPath(config.favicon_path)) {
            return 'static_asset';
        }

        return 'other_same_origin';
    };
    const recordBlockedRequest = (category) => {
        result.blocked_request_count += 1;

        switch (category) {
            case 'initial_search_post':
                result.blocked_initial_search_post_count += 1;
                return;
            case 'synthetic_search_post':
                result.blocked_synthetic_search_post_count += 1;
                return;
            case 'navigation':
                result.blocked_navigation_count += 1;
                return;
            case 'static_asset':
                result.blocked_static_asset_count += 1;
                return;
            case 'other_same_origin':
                result.blocked_other_same_origin_count += 1;
                return;
            case 'cross_origin':
                result.blocked_cross_origin_count += 1;
                return;
        }
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
            recordBlockedRequest('cross_origin');
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
        const category = classifyRequest(request, parsed, method, path);

        if (category === 'initial_search_post') {
            result.initial_search_request_seen = true;
            result.initial_search_request_allowed = customersSearch;
        } else if (category === 'synthetic_search_post') {
            result.synthetic_search_request_seen = true;
            result.synthetic_search_request_allowed = customersSearch;
        }

        if (staticAsset || customersPage || customersSearch) {
            await route.continue();
            return;
        }

        recordBlockedRequest(category);
        await route.abort('blockedbyclient');
    };
    const isSearchResponse = (response, expectedKeyword) => {
        const parsed = parseAllowedUrl(response.url());
        const values = parseForm(response.request().postData() || '');
        const matches =
            parsed &&
            normalizedPath(parsed.pathname) === normalizedPath(config.search_route_path) &&
            response.request().method().toUpperCase() === 'POST' &&
            values &&
            values.keyword === expectedKeyword;

        if (matches && expectedKeyword === '') {
            result.initial_search_response_seen = true;
        } else if (matches && expectedKeyword === config.search_marker) {
            result.synthetic_search_response_seen = true;
        }

        return matches;
    };
    const readSafeEmptySearch = async (response, expectedKeyword) => {
        const body = await response.text();
        if (expectedKeyword === '') {
            result.initial_search_body_read = true;
        } else if (expectedKeyword === config.search_marker) {
            result.synthetic_search_body_read = true;
        }
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
        result.initial_search_empty = await readSafeEmptySearch(await initialSearchPromise, '');

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

        activeSearchCategory = 'synthetic_search_post';
        await page.locator('#filter-customers .key').fill(config.search_marker);
        const syntheticSearchPromise = page.waitForResponse(
            (response) => isSearchResponse(response, config.search_marker),
            {timeout: config.open_timeout_ms},
        );
        await page.locator('#filter-customers form').press('Enter');
        result.synthetic_search_empty = await readSafeEmptySearch(await syntheticSearchPromise, config.search_marker);
        activeSearchCategory = null;

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

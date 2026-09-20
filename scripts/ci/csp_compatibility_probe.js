'use strict';

const fs = require('node:fs');

const CANDIDATE_POLICY = [
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'none'",
    "frame-ancestors 'self'",
    "form-action 'self'",
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data:",
    "font-src 'self' data:",
    "connect-src 'self'",
    'report-uri /__csp_report_intercepted__',
].join('; ');

const LOCAL_REPORT_PATH = '/__csp_report_intercepted__';

const ALLOWED_SURFACES = new Set(['app', 'www', 'booking', 'backoffice', 'account', 'export']);
const ALLOWED_DIRECTIVES = new Set([
    'default-src',
    'base-uri',
    'object-src',
    'frame-ancestors',
    'form-action',
    'script-src',
    'style-src',
    'img-src',
    'font-src',
    'connect-src',
    'worker-src',
    'media-src',
]);
const DIRECTIVE_ALIASES = new Map([
    ['script-src-elem', 'script-src'],
    ['script-src-attr', 'script-src'],
    ['style-src-elem', 'style-src'],
    ['style-src-attr', 'style-src'],
]);

const normalizeSurface = (surface) => (ALLOWED_SURFACES.has(surface) ? surface : 'unknown');

const validateSurface = (surface) => {
    if (!ALLOWED_SURFACES.has(surface)) {
        throw new Error('CSP compatibility probe requires a supported surface.');
    }
    return surface;
};

const validateLocalTarget = (value) => {
    if (typeof value !== 'string' || value.length === 0 || value.length > 2048) {
        throw new Error('CSP compatibility probe requires a local HTTP URL.');
    }

    let parsed;
    try {
        parsed = new URL(value);
    } catch (_error) {
        throw new Error('CSP compatibility probe requires a local HTTP URL.');
    }

    if (parsed.protocol !== 'http:' || !['127.0.0.1', 'localhost', '[::1]'].includes(parsed.hostname)) {
        throw new Error('CSP compatibility probe accepts loopback HTTP targets only.');
    }

    return parsed;
};

const blockedOriginClass = (blockedUri, selfOrigin) => {
    if (typeof blockedUri !== 'string' || blockedUri === '') {
        return 'unknown';
    }
    if (blockedUri === 'inline') {
        return 'inline';
    }
    if (blockedUri === 'data:') {
        return 'data';
    }
    if (blockedUri.startsWith('moz-extension:') || blockedUri.startsWith('chrome-extension:')) {
        return 'extension';
    }
    try {
        const parsed = new URL(blockedUri);
        if (typeof selfOrigin === 'string' && parsed.origin === selfOrigin) {
            return 'self';
        }
        if (
            parsed.protocol === 'https:' &&
            ['www.googletagmanager.com', 'www.google-analytics.com'].includes(parsed.hostname)
        ) {
            return 'google-analytics';
        }
        if (['http:', 'https:'].includes(parsed.protocol)) {
            return 'unknown-external';
        }
    } catch (_error) {
        return 'unknown';
    }
    return 'unknown';
};

const requestClass = (value, allowedOrigin) => {
    if (typeof value !== 'string' || value === '') {
        return 'unknown';
    }
    if (value.startsWith('data:') || value.startsWith('blob:')) {
        return 'browser-local';
    }
    try {
        const parsed = new URL(value);
        if (parsed.protocol === 'http:' && typeof allowedOrigin === 'string' && parsed.origin === allowedOrigin) {
            return 'loopback';
        }
    } catch (_error) {
        return 'unknown';
    }
    return 'external';
};

const webSocketClass = (value, target) => {
    if (typeof value !== 'string' || value === '') {
        return 'unknown';
    }
    try {
        const parsed = new URL(value);
        const expectedTarget = target instanceof URL ? target : new URL(target);
        if (
            parsed.protocol === 'ws:' &&
            parsed.hostname === expectedTarget.hostname &&
            parsed.port === expectedTarget.port
        ) {
            return 'loopback';
        }
    } catch (_error) {
        return 'unknown';
    }
    return 'external';
};

const boundedObservationMs = (value) => {
    if (value === undefined) {
        return 500;
    }
    const parsed = Number(value);
    if (!Number.isInteger(parsed) || parsed < 0 || parsed > 5000) {
        throw new Error('Invalid CSP compatibility observation window.');
    }
    return parsed;
};

const sanitizeViolation = (violation, surface, selfOrigin) => {
    const rawDirective = typeof violation?.effectiveDirective === 'string' ? violation.effectiveDirective : 'unknown';
    const normalizedDirective = DIRECTIVE_ALIASES.get(rawDirective) || rawDirective;
    const directive = ALLOWED_DIRECTIVES.has(normalizedDirective) ? normalizedDirective : 'unknown';
    return {
        surface: normalizeSurface(surface),
        directive,
        blocked_origin: blockedOriginClass(violation?.blockedURI, selfOrigin),
        disposition: 'report',
    };
};

const makeReceipt = (surface, violations, pageLoaded, blockedRequests = [], interceptedReportCount = 0) => {
    const counts = Object.create(null);
    for (const violation of violations) {
        const key = `${violation.surface}:${violation.directive}:${violation.blocked_origin}:${violation.disposition}`;
        counts[key] = (counts[key] || 0) + 1;
    }
    const requestCounts = Object.create(null);
    for (const blockedRequest of blockedRequests) {
        requestCounts[blockedRequest] = (requestCounts[blockedRequest] || 0) + 1;
    }
    return {
        schema: 'csp_compatibility_probe.v1',
        mode: 'local_report_only',
        surface: normalizeSurface(surface),
        candidate_policy_applied: true,
        page_loaded: Boolean(pageLoaded),
        violation_count: violations.length,
        violation_classes: counts,
        blocked_request_count: blockedRequests.length,
        blocked_request_classes: requestCounts,
        intercepted_report_count: interceptedReportCount,
        report_destination_intercepted: true,
        raw_reports_persisted: false,
        production_changed: false,
    };
};

const makeFailureReceipt = (errorClass = 'probe_failed') => ({
    schema: 'csp_compatibility_probe.v1',
    mode: 'local_report_only',
    outcome: 'environment_failed',
    error_class: errorClass,
    candidate_policy_applied: false,
    page_loaded: false,
    violation_count: 0,
    violation_classes: {},
    blocked_request_count: 0,
    blocked_request_classes: {},
    intercepted_report_count: 0,
    report_destination_intercepted: false,
    raw_reports_persisted: false,
    production_changed: false,
});

const runProbe = async (input) => {
    const target = validateLocalTarget(input.url);
    validateSurface(input.surface);
    const observationMs = boundedObservationMs(input.observation_ms);
    const playwright = require('playwright');
    const browserTypes = {
        chromium: playwright.chromium,
        firefox: playwright.firefox,
        webkit: playwright.webkit,
    };
    const browserType = browserTypes[input.browser || 'chromium'];
    if (!browserType) {
        throw new Error(`Unsupported Playwright browser: ${input.browser}`);
    }

    const launchOptions = {
        headless: !Boolean(input.headed),
        timeout: Number(input.launch_timeout) > 0 ? Number(input.launch_timeout) * 1000 : 30000,
    };
    if (typeof input.executable_path === 'string' && input.executable_path !== '') {
        launchOptions.executablePath = input.executable_path;
    }
    const browser = await browserType.launch(launchOptions);
    const routeFailures = [];
    const pendingOperations = new Set();
    let interceptedReportCount = 0;
    let context;
    let page;
    try {
        context = await browser.newContext({serviceWorkers: 'block'});
        const violations = [];
        const blockedRequests = [];
        await context.addInitScript(() => {
            window.__CSP_COMPATIBILITY_VIOLATIONS__ = [];
            window.addEventListener('securitypolicyviolation', (event) => {
                window.__CSP_COMPATIBILITY_VIOLATIONS__.push({
                    effectiveDirective: event.effectiveDirective,
                    blockedURI: event.blockedURI,
                });
            });
        });
        await context.route('**/*', (route) => {
            const pending = (async () => {
                try {
                    const requestUrl = typeof route.request === 'function' ? route.request().url() : '';
                    const requestMethod = typeof route.request === 'function' ? route.request().method() : 'GET';
                    const classification = requestClass(requestUrl, target.origin);
                    if (classification === 'external' || classification === 'unknown') {
                        blockedRequests.push(classification);
                        await route.abort('blockedbyclient');
                        return;
                    }
                    if (classification === 'browser-local') {
                        await route.continue();
                        return;
                    }
                    if (requestMethod === 'POST' && new URL(requestUrl).pathname === LOCAL_REPORT_PATH) {
                        interceptedReportCount += 1;
                        await route.fulfill({status: 204, body: ''});
                        return;
                    }
                    const response = await route.fetch({maxRedirects: 0});
                    const responseStatus = typeof response.status === 'function' ? response.status() : 0;
                    if (responseStatus >= 300 && responseStatus < 400) {
                        blockedRequests.push('redirect');
                        routeFailures.push('http_redirect_blocked');
                        await route.abort('blockedbyclient');
                        return;
                    }
                    const headers = response.headers();
                    headers['content-security-policy-report-only'] = CANDIDATE_POLICY;
                    await route.fulfill({response, headers});
                } catch (_error) {
                    routeFailures.push('http_route_failed');
                    try {
                        await route.abort('failed');
                    } catch (_abortError) {
                        // The route can already be closed; retain only the fixed failure class.
                    }
                }
            })();
            pendingOperations.add(pending);
            return pending.finally(() => pendingOperations.delete(pending));
        });
        await context.routeWebSocket('**/*', (webSocketRoute) => {
            const pending = (async () => {
                try {
                    const classification = webSocketClass(webSocketRoute.url(), target);
                    if (classification !== 'loopback') {
                        blockedRequests.push(`websocket_${classification}`);
                        await webSocketRoute.close({code: 1008});
                        return;
                    }
                    webSocketRoute.connectToServer();
                } catch (_error) {
                    routeFailures.push('websocket_route_failed');
                    try {
                        await webSocketRoute.close({code: 1011});
                    } catch (_closeError) {
                        // Retain only the fixed failure class.
                    }
                }
            })();
            pendingOperations.add(pending);
            return pending.finally(() => pendingOperations.delete(pending));
        });
        page = await context.newPage();
        const response = await page.goto(target.toString(), {
            waitUntil: 'load',
            timeout: Number(input.open_timeout) > 0 ? Number(input.open_timeout) * 1000 : 30000,
        });
        await page.waitForTimeout(observationMs);
        const pageViolations = await page.evaluate(() => window.__CSP_COMPATIBILITY_VIOLATIONS__ || []);
        for (const violation of pageViolations) {
            violations.push(sanitizeViolation(violation, input.surface, target.origin));
        }
        await page.close({runBeforeUnload: false});
        page = undefined;
        await Promise.allSettled([...pendingOperations]);
        if (routeFailures.length > 0) {
            throw new Error('CSP compatibility route failed.');
        }
        return makeReceipt(
            input.surface,
            violations,
            Boolean(response && response.ok()),
            blockedRequests,
            interceptedReportCount,
        );
    } finally {
        if (page !== undefined) {
            try {
                await page.close({runBeforeUnload: false});
            } catch (_error) {
                // The CLI emits only a fixed failure receipt for any close failure.
            }
        }
        await Promise.allSettled([...pendingOperations]);
        if (context !== undefined) {
            try {
                await context.close();
            } catch (_error) {
                // Browser close below is the final local cleanup boundary.
            }
        }
        await browser.close();
    }
};

if (require.main === module) {
    let input;
    try {
        input = JSON.parse(fs.readFileSync(0, 'utf8'));
    } catch (_error) {
        process.stdout.write(`${JSON.stringify(makeFailureReceipt('invalid_input'))}\n`);
        process.exitCode = 1;
    }
    if (input !== undefined) {
        runProbe(input)
            .then((receipt) => process.stdout.write(`${JSON.stringify(receipt)}\n`))
            .catch(() => {
                process.stdout.write(`${JSON.stringify(makeFailureReceipt())}\n`);
                process.exitCode = 1;
            });
    }
}

module.exports = {
    CANDIDATE_POLICY,
    blockedOriginClass,
    makeFailureReceipt,
    makeReceipt,
    normalizeSurface,
    requestClass,
    runProbe,
    sanitizeViolation,
    validateSurface,
    validateLocalTarget,
    webSocketClass,
    boundedObservationMs,
};

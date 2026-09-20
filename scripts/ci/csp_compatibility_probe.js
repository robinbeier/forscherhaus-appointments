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
].join('; ');

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

const normalizeSurface = (surface) => (ALLOWED_SURFACES.has(surface) ? surface : 'unknown');

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

const blockedOriginClass = (blockedUri) => {
    if (typeof blockedUri !== 'string' || blockedUri === '') {
        return 'unknown';
    }
    if (blockedUri === 'inline') {
        return 'inline';
    }
    if (blockedUri === 'data:') {
        return 'data';
    }
    if (
        blockedUri.startsWith('http://127.0.0.1') ||
        blockedUri.startsWith('http://localhost') ||
        blockedUri.startsWith('http://[::1]')
    ) {
        return 'self';
    }
    if (
        blockedUri.startsWith('https://www.googletagmanager.com') ||
        blockedUri.startsWith('https://www.google-analytics.com')
    ) {
        return 'google-analytics';
    }
    if (blockedUri.startsWith('moz-extension:') || blockedUri.startsWith('chrome-extension:')) {
        return 'extension';
    }
    if (blockedUri.startsWith('http:') || blockedUri.startsWith('https:')) {
        return 'unknown-external';
    }
    return 'unknown';
};

const requestClass = (value) => {
    if (typeof value !== 'string' || value === '') {
        return 'unknown';
    }
    if (value.startsWith('data:') || value.startsWith('blob:')) {
        return 'browser-local';
    }
    try {
        const parsed = new URL(value);
        if (parsed.protocol === 'http:' && ['127.0.0.1', 'localhost', '[::1]'].includes(parsed.hostname)) {
            return 'loopback';
        }
    } catch (_error) {
        return 'unknown';
    }
    return 'external';
};

const sanitizeViolation = (violation, surface) => {
    const directive =
        typeof violation?.effectiveDirective === 'string' && ALLOWED_DIRECTIVES.has(violation.effectiveDirective)
            ? violation.effectiveDirective
            : 'unknown';
    return {
        surface: normalizeSurface(surface),
        directive,
        blocked_origin: blockedOriginClass(violation?.blockedURI),
        disposition: 'report',
    };
};

const makeReceipt = (surface, violations, pageLoaded, blockedRequests = []) => {
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
    raw_reports_persisted: false,
    production_changed: false,
});

const runProbe = async (input) => {
    const target = validateLocalTarget(input.url);
    const playwright = require('playwright');
    const browserTypes = {
        chromium: playwright.chromium,
        chrome: playwright.chromium,
        msedge: playwright.chromium,
        firefox: playwright.firefox,
        webkit: playwright.webkit,
    };
    const browserType = browserTypes[input.browser || 'chromium'];
    if (!browserType) {
        throw new Error(`Unsupported Playwright browser: ${input.browser}`);
    }

    const browser = await browserType.launch({
        headless: !Boolean(input.headed),
        timeout: Number(input.launch_timeout) > 0 ? Number(input.launch_timeout) * 1000 : 30000,
    });
    try {
        const context = await browser.newContext();
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
        await context.route('**/*', async (route) => {
            const requestUrl = typeof route.request === 'function' ? route.request().url() : '';
            const classification = requestClass(requestUrl);
            if (classification === 'external' || classification === 'unknown') {
                blockedRequests.push(classification);
                await route.abort('blockedbyclient');
                return;
            }
            const response = await route.fetch();
            const headers = response.headers();
            headers['content-security-policy-report-only'] = CANDIDATE_POLICY;
            await route.fulfill({response, headers});
        });
        const page = await context.newPage();
        const response = await page.goto(target.toString(), {
            waitUntil: 'domcontentloaded',
            timeout: Number(input.open_timeout) > 0 ? Number(input.open_timeout) * 1000 : 30000,
        });
        const pageViolations = await page.evaluate(() => window.__CSP_COMPATIBILITY_VIOLATIONS__ || []);
        for (const violation of pageViolations) {
            violations.push(sanitizeViolation(violation, input.surface));
        }
        return makeReceipt(input.surface, violations, Boolean(response && response.ok()), blockedRequests);
    } finally {
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
    validateLocalTarget,
};

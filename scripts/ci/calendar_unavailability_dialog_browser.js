'use strict';

const fs = require('node:fs');
const playwright = require('playwright');

const SAVE_PATH = '/calendar/save_unavailability';
const CALENDAR_RELOAD_PATHS = [
    '/calendar/get_calendar_appointments',
    '/calendar/get_calendar_appointments_for_table_view',
];
const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]', 'nginx']);
const BROWSER_TYPES = {
    chromium: playwright.chromium,
    chrome: playwright.chromium,
    msedge: playwright.chromium,
    firefox: playwright.firefox,
    webkit: playwright.webkit,
};

let browser = null;
let context = null;
let stage = 'input';
let cleanupPromise = null;

function closeBrowser() {
    if (!cleanupPromise) {
        cleanupPromise = (async () => {
            try {
                if (context) await context.close();
            } finally {
                if (browser) await browser.close();
            }
        })();
    }
    return cleanupPromise;
}

function onSignal(signal) {
    void closeBrowser().finally(() => process.exit(128 + signal));
}

process.once('SIGTERM', () => onSignal(15));
process.once('SIGINT', () => onSignal(2));

function fail(message) {
    const error = new Error(message);
    error.className = message.split(':', 1)[0];
    throw error;
}

function routeUrl(baseUrl, path) {
    const parsed = new URL(baseUrl);
    const prefix = parsed.pathname.replace(/\/$/, '');
    return `${parsed.origin}${prefix}/${path.replace(/^\//, '')}`;
}

function assertLoopbackBaseUrl(value) {
    let parsed;
    try {
        parsed = new URL(value);
    } catch {
        fail('input: base_url must be a valid URL');
    }

    if (parsed.protocol !== 'http:' || !LOOPBACK_HOSTS.has(parsed.hostname)) {
        fail('input: base_url must use plain HTTP on a loopback or local nginx host');
    }
    return parsed.toString().replace(/\/$/, '');
}

function parseFormPayload(postData) {
    const form = new URLSearchParams(postData || '');
    return {
        csrf: form.get('csrf_token') || '',
        provider: form.get('unavailability[id_users_provider]') || '',
        start: form.get('unavailability[start_datetime]') || '',
        end: form.get('unavailability[end_datetime]') || '',
    };
}

function getOpenTimeoutSeconds(input) {
    const seconds = Number(input.browser_open_timeout ?? 20);
    if (!Number.isSafeInteger(seconds) || seconds < 1) {
        fail('input: browser_open_timeout must be a positive integer');
    }
    return seconds;
}

async function main(input) {
    const baseUrl = assertLoopbackBaseUrl(input.base_url);
    const targetUrl = input.target_url ? assertLoopbackBaseUrl(input.target_url) : routeUrl(baseUrl, 'login');
    const browserName = input.browser || 'firefox';
    const browserType = BROWSER_TYPES[browserName];
    if (!browserType) {
        fail('input: unsupported browser');
    }
    const openTimeoutMs = getOpenTimeoutSeconds(input) * 1000;

    const executablePath = input.browser_executable_path || process.env.PLAYWRIGHT_MCP_EXECUTABLE_PATH;
    stage = 'launch';
    browser = await browserType.launch({
        headless: true,
        timeout: Math.max(30000, openTimeoutMs),
        ...(executablePath ? {executablePath} : {}),
        ...(!executablePath && (browserName === 'chrome' || browserName === 'msedge') ? {channel: browserName} : {}),
    });
    context = await browser.newContext();
    context.setDefaultTimeout(openTimeoutMs);
    context.setDefaultNavigationTimeout(openTimeoutMs);
    stage = 'auth';
    if (Array.isArray(input.session_cookies) && input.session_cookies.length > 0) {
        await context.addCookies(input.session_cookies);
    }
    const expectedOrigin = new URL(baseUrl).origin;
    const savePath = new URL(routeUrl(baseUrl, SAVE_PATH)).pathname;
    const [defaultReloadPath, tableReloadPath] = CALENDAR_RELOAD_PATHS.map(
        (path) => new URL(routeUrl(baseUrl, path)).pathname,
    );
    const reloadPaths = new Set([defaultReloadPath, tableReloadPath]);
    const loginPath = new URL(routeUrl(baseUrl, 'login/validate')).pathname;
    const postPaths = [];
    const blockedWritePaths = [];
    const saveRequests = [];
    let saveAttempt = 0;

    // Install the write boundary before any page navigation or boot request.
    await context.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const method = request.method();
        if (['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            await route.continue();
            return;
        }
        const allowedRead = method === 'POST' && url.origin === expectedOrigin && reloadPaths.has(url.pathname);
        const allowedLogin =
            method === 'POST' &&
            !input.session_cookies?.length &&
            url.origin === expectedOrigin &&
            url.pathname === loginPath;
        if (allowedRead || allowedLogin) {
            await route.continue();
            return;
        }
        blockedWritePaths.push(url.pathname);
        await route.abort('blockedbyclient');
    });
    await context.route(`**${SAVE_PATH}*`, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.method() !== 'POST' || url.origin !== expectedOrigin || url.pathname !== savePath) {
            await route.abort('blockedbyclient');
            return;
        }
        saveAttempt += 1;
        saveRequests.push(parseFormPayload(request.postData()));
        if (saveAttempt === 1) {
            await route.fulfill({status: 500, contentType: 'text/plain', body: 'synthetic failure'});
            return;
        }
        await route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({success: true})});
    });

    const page = await context.newPage();
    page.on('request', (request) => {
        if (request.method() === 'POST') {
            postPaths.push(new URL(request.url()).pathname);
        }
    });

    if (Array.isArray(input.session_cookies) && input.session_cookies.length > 0) {
        await page.goto(targetUrl, {waitUntil: 'domcontentloaded'});
        if (page.url().includes('/login') || (await page.locator('#login-form').count())) {
            fail('failure: supplied session cookies did not authenticate the calendar page');
        }
    } else {
        if (!input.username || !input.password) {
            fail('input: username and password are required without session_cookies');
        }
        await page.goto(routeUrl(baseUrl, 'login'), {waitUntil: 'domcontentloaded'});
        await page.locator('#username').fill(String(input.username));
        await page.locator('#password').fill(String(input.password));
        await Promise.all([
            page.waitForURL((url) => url.pathname.includes('/calendar'), {timeout: 15000}),
            page.locator('#login').click(),
        ]);
    }
    await page.locator('#calendar-page').waitFor({state: 'visible'});
    const calendarView = await page.evaluate(() => vars('calendar_view'));
    if (calendarView !== 'default' && calendarView !== 'table') {
        fail('dialog: unsupported calendar view');
    }
    const expectedReloadPath = calendarView === 'table' ? tableReloadPath : defaultReloadPath;

    const interactionPostPathStart = postPaths.length;
    stage = 'dialog';
    await page.locator('#calendar-actions [data-bs-toggle="dropdown"]').click();
    await page.locator('#insert-unavailability').click();
    const modal = page.locator('#unavailabilities-modal');
    await modal.waitFor({state: 'visible'});
    const provider = modal.locator('#unavailability-provider option').filter({hasText: /.+/}).first();
    await provider.waitFor({state: 'attached'});
    const providerId = await provider.getAttribute('value');
    if (!providerId) {
        fail('payload: rendered provider select had no synthetic provider option');
    }
    await modal.locator('#unavailability-provider').selectOption(providerId);

    const startDate = '2030-01-15T09:00:00';
    const endDate = '2030-01-15T10:00:00';
    await page.evaluate(
        ({start, end}) => {
            App.Utils.UI.setDateTimePickerValue($('#unavailability-start'), new Date(start));
            App.Utils.UI.setDateTimePickerValue($('#unavailability-end'), new Date(end));
        },
        {start: startDate, end: endDate},
    );

    stage = 'failure';
    const reloadRequestsBeforeFailure = postPaths.filter((path) => reloadPaths.has(path)).length;
    const firstSaveResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(SAVE_PATH) &&
            response.status() === 500,
        {timeout: 5000},
    );
    await Promise.all([firstSaveResponse, modal.locator('#save-unavailability').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {timeout: 5000});
    const errorModal = page.locator('#message-modal');
    await errorModal.waitFor({state: 'visible', timeout: 5000});
    if (!(await modal.isVisible())) {
        fail('failure: modal closed after simulated HTTP failure');
    }
    const reloadRequestsAfterFailure = postPaths.filter((path) => reloadPaths.has(path)).length;
    if (reloadRequestsAfterFailure !== reloadRequestsBeforeFailure) {
        fail('failure: calendar reload occurred after simulated HTTP failure');
    }
    await errorModal.locator('.modal-footer button').click();
    await errorModal.waitFor({state: 'hidden'});
    if (!(await modal.isVisible())) {
        fail('failure: unavailability modal closed when dismissing the error message');
    }

    stage = 'success';
    const reloadRequest = page.waitForRequest(
        (request) => request.method() === 'POST' && new URL(request.url()).pathname === expectedReloadPath,
        {timeout: 5000},
    );
    const secondSaveResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(SAVE_PATH) &&
            response.status() === 200,
        {timeout: 5000},
    );
    await Promise.all([secondSaveResponse, reloadRequest, modal.locator('#save-unavailability').click()]);
    await modal.waitFor({state: 'hidden'});

    if (saveRequests.length !== 2) {
        fail('payload: expected exactly two intercepted save attempts');
    }
    for (const payload of saveRequests) {
        if (!payload.csrf || payload.provider !== String(providerId)) {
            fail('payload: CSRF or selected provider did not reach the save request');
        }
        if (payload.start !== '2030-01-15 09:00:00' || payload.end !== '2030-01-15 10:00:00') {
            fail('payload: selected start/end did not reach the save request');
        }
    }

    const interactionPostPaths = postPaths.slice(interactionPostPathStart);
    const unexpectedWrites = interactionPostPaths.filter((path) => !path.endsWith(SAVE_PATH) && !reloadPaths.has(path));
    if (unexpectedWrites.length > 0 || blockedWritePaths.length > 0) {
        fail('payload: an unexpected write route was requested');
    }

    return {
        request_payload_verified: true,
        failure_state_verified: true,
        success_state_verified: true,
    };
}

(async () => {
    const startedAt = Date.now();
    let result = null;
    let failedClass = null;
    let timeoutId;
    try {
        const input = JSON.parse(fs.readFileSync(0, 'utf8'));
        const openTimeoutSeconds = getOpenTimeoutSeconds(input);
        const overallTimeoutMs = Math.max(90000, openTimeoutSeconds * 2000 + 35000);
        result = await Promise.race([
            main(input),
            new Promise((_, reject) => {
                timeoutId = setTimeout(() => reject(new Error('Calendar dialog check timed out')), overallTimeoutMs);
            }),
        ]);
    } catch (error) {
        failedClass = error.className || stage;
        process.exitCode = 1;
    } finally {
        clearTimeout(timeoutId);
        let cleanupVerified = true;
        let cleanupTimeoutId;
        try {
            await Promise.race([
                (async () => {
                    await closeBrowser();
                })(),
                new Promise((_, reject) => {
                    cleanupTimeoutId = setTimeout(() => reject(new Error('cleanup timed out')), 10000);
                }),
            ]);
        } catch {
            cleanupVerified = false;
            failedClass = 'cleanup';
            process.exitCode = 1;
        } finally {
            clearTimeout(cleanupTimeoutId);
        }
        if (result && cleanupVerified && !failedClass) {
            process.stdout.write(
                `${JSON.stringify({
                    ok: true,
                    ...result,
                    cleanup_verified: true,
                    duration_ms: Date.now() - startedAt,
                })}\n`,
            );
        } else {
            process.stderr.write(`${failedClass || 'cleanup'}\n`);
        }
    }
})();

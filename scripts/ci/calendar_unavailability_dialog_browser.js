'use strict';

const fs = require('node:fs');
const playwright = require('playwright');

const SAVE_PATH = '/calendar/save_unavailability';
const CALENDAR_RELOAD_PATH = '/calendar/get_calendar_appointments';
const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '::1', 'nginx']);
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

async function main() {
    const input = JSON.parse(fs.readFileSync(0, 'utf8'));
    const baseUrl = assertLoopbackBaseUrl(input.base_url);
    const targetUrl = input.target_url ? assertLoopbackBaseUrl(input.target_url) : routeUrl(baseUrl, 'login');
    const browserName = input.browser || 'firefox';
    const browserType = BROWSER_TYPES[browserName];
    if (!browserType) {
        fail('input: unsupported browser');
    }

    const executablePath = input.browser_executable_path || process.env.PLAYWRIGHT_MCP_EXECUTABLE_PATH;
    stage = 'launch';
    browser = await browserType.launch({
        headless: true,
        ...(executablePath ? {executablePath} : {}),
        ...(!executablePath && (browserName === 'chrome' || browserName === 'msedge') ? {channel: browserName} : {}),
    });
    context = await browser.newContext();
    stage = 'auth';
    if (Array.isArray(input.session_cookies) && input.session_cookies.length > 0) {
        await context.addCookies(input.session_cookies);
    }
    const page = await context.newPage();
    const postPaths = [];
    const saveRequests = [];
    let saveAttempt = 0;

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

    const interactionPostPathStart = postPaths.length;
    await page.route('**/*', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (request.method() === 'POST' && !path.endsWith(SAVE_PATH) && !path.endsWith(CALENDAR_RELOAD_PATH)) {
            await route.abort('blockedbyclient');
            return;
        }
        await route.continue();
    });
    await page.route(`**${SAVE_PATH}*`, async (route) => {
        const request = route.request();
        saveAttempt += 1;
        saveRequests.push(parseFormPayload(request.postData()));
        if (saveAttempt === 1) {
            await route.fulfill({status: 500, contentType: 'text/plain', body: 'synthetic failure'});
            return;
        }
        await route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({success: true})});
    });

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
    const reloadRequestsBeforeFailure = postPaths.filter((path) => path.endsWith(CALENDAR_RELOAD_PATH)).length;
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
    const reloadRequestsAfterFailure = postPaths.filter((path) => path.endsWith(CALENDAR_RELOAD_PATH)).length;
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
        (request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith(CALENDAR_RELOAD_PATH),
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
    const unexpectedWrites = interactionPostPaths.filter(
        (path) => !path.endsWith(SAVE_PATH) && !path.endsWith(CALENDAR_RELOAD_PATH),
    );
    if (unexpectedWrites.length > 0) {
        fail('payload: an unexpected POST write route was requested');
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
        result = await Promise.race([
            main(),
            new Promise((_, reject) => {
                timeoutId = setTimeout(() => reject(new Error('Calendar dialog check timed out')), 90000);
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
                    if (context) await context.close();
                    if (browser) await browser.close();
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

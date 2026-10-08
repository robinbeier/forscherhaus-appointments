'use strict';

const fs = require('node:fs');
const playwright = require('playwright');

const SAVE_PATH = '/calendar/save_unavailability';
const SAVE_WORKING_PLAN_EXCEPTION_PATH = '/calendar/save_working_plan_exception';
const DELETE_WORKING_PLAN_EXCEPTION_PATH = '/calendar/delete_working_plan_exception';
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
        id: form.get('unavailability[id]') || '',
        provider: form.get('unavailability[id_users_provider]') || '',
        start: form.get('unavailability[start_datetime]') || '',
        end: form.get('unavailability[end_datetime]') || '',
        notes: form.get('unavailability[notes]') || '',
    };
}

function parseDeletePayload(postData) {
    const form = new URLSearchParams(postData || '');
    return {
        csrf: form.get('csrf_token') || '',
        id: form.get('unavailability_id') || '',
    };
}

function parseWorkingPlanExceptionPayload(postData) {
    const form = new URLSearchParams(postData || '');
    return {
        csrf: form.get('csrf_token') || '',
        date: form.get('date') || '',
        provider: form.get('provider_id') || '',
        originalDate: form.get('original_date') || '',
        start: form.get('working_plan_exception[start]') || '',
        end: form.get('working_plan_exception[end]') || '',
        breaks: form.get('working_plan_exception[breaks]') || '',
    };
}

function parseWorkingPlanExceptionDeletePayload(postData) {
    const form = new URLSearchParams(postData || '');
    return {
        csrf: form.get('csrf_token') || '',
        date: form.get('date') || '',
        provider: form.get('provider_id') || '',
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
    const targetUrl = input.target_url ? assertLoopbackBaseUrl(input.target_url) : routeUrl(baseUrl, 'calendar');
    const defaultViewUrl = new URL(targetUrl);
    defaultViewUrl.searchParams.set('view', 'default');
    const browserName = input.browser || 'firefox';
    const browserType = BROWSER_TYPES[browserName];
    if (!browserType) {
        fail('input: unsupported browser');
    }
    const openTimeoutMs = getOpenTimeoutSeconds(input) * 1000;
    const interactionTimeoutMs = Math.max(5000, openTimeoutMs);

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
    const deletePath = new URL(routeUrl(baseUrl, '/calendar/delete_unavailability')).pathname;
    const [defaultReloadPath, tableReloadPath] = CALENDAR_RELOAD_PATHS.map(
        (path) => new URL(routeUrl(baseUrl, path)).pathname,
    );
    const reloadPaths = new Set([defaultReloadPath, tableReloadPath]);
    const loginPath = new URL(routeUrl(baseUrl, 'login/validate')).pathname;
    const postPaths = [];
    const blockedWritePaths = [];
    const saveRequests = [];
    const deleteRequests = [];
    const workingPlanSaveRequests = [];
    const workingPlanDeleteRequests = [];
    let syntheticFeed = false;
    let syntheticFeedBody = null;
    let saveAttempt = 0;
    let deleteAttempt = 0;
    let workingPlanSaveAttempt = 0;
    let workingPlanDeleteAttempt = 0;

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
        if (saveAttempt === 1 || saveAttempt === 3) {
            await route.fulfill({status: 500, contentType: 'text/plain', body: 'synthetic failure'});
            return;
        }
        await route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({success: true})});
    });
    await context.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.method() !== 'POST' || url.origin !== expectedOrigin || !reloadPaths.has(url.pathname)) {
            await route.fallback();
            return;
        }
        if (!syntheticFeed || !syntheticFeedBody) {
            await route.fallback();
            return;
        }
        await route.fulfill({status: 200, contentType: 'application/json', body: syntheticFeedBody});
    });
    await context.route(`**${deletePath}*`, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.method() !== 'POST' || url.origin !== expectedOrigin || url.pathname !== deletePath) {
            await route.abort('blockedbyclient');
            return;
        }
        deleteAttempt += 1;
        deleteRequests.push(parseDeletePayload(request.postData()));
        if (deleteAttempt > 1) {
            syntheticFeedBody = JSON.stringify({appointments: [], unavailabilities: [], blocked_periods: []});
        }
        await route.fulfill({
            status: deleteAttempt === 1 ? 500 : 200,
            contentType: 'application/json',
            body: JSON.stringify({success: deleteAttempt > 1}),
        });
    });
    await context.route(`**${SAVE_WORKING_PLAN_EXCEPTION_PATH}*`, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (
            request.method() !== 'POST' ||
            url.origin !== expectedOrigin ||
            url.pathname !== new URL(routeUrl(baseUrl, SAVE_WORKING_PLAN_EXCEPTION_PATH)).pathname
        ) {
            await route.abort('blockedbyclient');
            return;
        }
        workingPlanSaveAttempt += 1;
        workingPlanSaveRequests.push(parseWorkingPlanExceptionPayload(request.postData()));
        await route.fulfill({
            status: workingPlanSaveAttempt % 2 === 1 ? 500 : 200,
            contentType: 'application/json',
            body: JSON.stringify({success: workingPlanSaveAttempt % 2 === 0}),
        });
    });
    await context.route(`**${DELETE_WORKING_PLAN_EXCEPTION_PATH}*`, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (
            request.method() !== 'POST' ||
            url.origin !== expectedOrigin ||
            url.pathname !== new URL(routeUrl(baseUrl, DELETE_WORKING_PLAN_EXCEPTION_PATH)).pathname
        ) {
            await route.abort('blockedbyclient');
            return;
        }
        workingPlanDeleteAttempt += 1;
        workingPlanDeleteRequests.push(parseWorkingPlanExceptionDeletePayload(request.postData()));
        await route.fulfill({
            status: workingPlanDeleteAttempt === 1 ? 500 : 200,
            contentType: 'application/json',
            body: JSON.stringify({success: workingPlanDeleteAttempt > 1}),
        });
    });

    await context.addInitScript(() => {
        window.ROB775 = 0;
    });
    const page = await context.newPage();
    page.on('request', (request) => {
        if (request.method() === 'POST') {
            postPaths.push(new URL(request.url()).pathname);
        }
    });

    if (Array.isArray(input.session_cookies) && input.session_cookies.length > 0) {
        await page.goto(defaultViewUrl.toString(), {waitUntil: 'domcontentloaded'});
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
            page.waitForURL((url) => url.pathname.includes('/calendar'), {timeout: Math.max(15000, openTimeoutMs)}),
            page.locator('#login').click(),
        ]);
        await page.goto(defaultViewUrl.toString(), {waitUntil: 'domcontentloaded'});
    }
    await page.locator('#calendar-page').waitFor({state: 'visible'});
    const calendarView = await page.evaluate(() => vars('calendar_view'));
    if (calendarView !== 'default') {
        fail('dialog: first pass did not select the default calendar view');
    }
    const expectedReloadPath = calendarView === 'table' ? tableReloadPath : defaultReloadPath;

    const providerData = await page.evaluate(() => vars('available_providers')[0] || null);
    if (!providerData || !providerData.id) {
        fail('payload: calendar page rendered no provider fixture');
    }
    const providerId = String(providerData.id);
    const createStartValue = '2030-01-15 09:00:00';
    const createEndValue = '2030-01-15 10:00:00';
    const createNotes = 'ROB-768 synthetic manual unavailability';
    stage = 'create';
    await page.locator('#calendar-actions [data-bs-toggle="dropdown"]').click();
    await page.locator('#insert-unavailability').click();
    const modal = page.locator('#unavailabilities-modal');
    await modal.waitFor({state: 'visible'});
    await modal.locator('#unavailability-provider').selectOption(providerId);
    await page.evaluate(
        ({start, end}) => {
            App.Utils.UI.setDateTimePickerValue($('#unavailability-start'), new Date(start));
            App.Utils.UI.setDateTimePickerValue($('#unavailability-end'), new Date(end));
            $('#unavailability-notes').val('ROB-768 synthetic manual unavailability');
        },
        {start: '2030-01-15T09:00:00', end: '2030-01-15T10:00:00'},
    );
    const createReloadBeforeFailure = postPaths.filter((path) => reloadPaths.has(path)).length;
    const createFailureResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === savePath &&
            response.status() === 500,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([createFailureResponse, modal.locator('#save-unavailability').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    const createErrorModal = page.locator('#message-modal');
    await createErrorModal.waitFor({state: 'visible', timeout: interactionTimeoutMs});
    if (!(await modal.isVisible())) {
        fail('failure: modal closed after simulated create failure');
    }
    if (postPaths.filter((path) => reloadPaths.has(path)).length !== createReloadBeforeFailure) {
        fail('failure: calendar reloaded after simulated create failure');
    }
    await createErrorModal.locator('.modal-footer button').click();
    await createErrorModal.waitFor({state: 'hidden'});
    const createReloadBeforeSuccess = postPaths.filter((path) => reloadPaths.has(path)).length;
    const createSuccessResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === savePath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    const createReloadResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === expectedReloadPath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([createSuccessResponse, createReloadResponse, modal.locator('#save-unavailability').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    await modal.waitFor({state: 'hidden'});
    if (saveRequests.length !== 2) {
        fail('payload: expected exactly two intercepted create save attempts');
    }
    for (const payload of saveRequests) {
        if (
            !payload.csrf ||
            payload.id ||
            payload.provider !== providerId ||
            payload.start !== createStartValue ||
            payload.end !== createEndValue ||
            payload.notes !== createNotes
        ) {
            fail('payload: create save did not send the expected insert payload');
        }
    }
    if (postPaths.filter((path) => reloadPaths.has(path)).length !== createReloadBeforeSuccess + 1) {
        fail('success: create did not reload the calendar exactly once');
    }

    // Exercise the working-plan exception through its real modal and HTTP client. The
    // intercepted responses keep this browser-only coverage free of database writes.
    stage = 'working_plan_exception';
    const workingPlanStartedAt = Date.now();
    const workingPlanModal = page.locator('#working-plan-exceptions-modal');
    const workingPlanProvider = providerId;
    const workingPlanStart = '09:00';
    const workingPlanEnd = '17:00';
    const workingPlanEditedEnd = '18:00';
    if ((await page.locator('#select-filter-item').inputValue()) !== workingPlanProvider) {
        const providerSelectionReload = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname === expectedReloadPath &&
                response.status() === 200,
            {timeout: interactionTimeoutMs},
        );
        await Promise.all([
            providerSelectionReload,
            page.locator('#select-filter-item').selectOption(workingPlanProvider),
        ]);
        await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
            timeout: interactionTimeoutMs,
        });
    }
    const workingPlanDates = await page.evaluate(() => {
        const provider = vars('available_providers')[0] || {};
        const exceptions = JSON.parse(provider.settings?.working_plan_exceptions || '{}');
        const visibleDates = [...document.querySelectorAll('[data-date]')]
            .map((element) => element.getAttribute('data-date'))
            .filter((date) => /^\d{4}-\d{2}-\d{2}$/.test(date));
        const freeDates = [...new Set(visibleDates)].filter(
            (date) => !Object.prototype.hasOwnProperty.call(exceptions, date),
        );
        return {create: freeDates[0] || '', move: freeDates[1] || ''};
    });
    if (!workingPlanDates.create || !workingPlanDates.move || workingPlanDates.create === workingPlanDates.move) {
        fail('working_plan_exception: current calendar range has fewer than two free dates');
    }
    const workingPlanDate = workingPlanDates.create;
    const workingPlanMovedDate = workingPlanDates.move;
    const readWorkingPlanExceptions = () =>
        page.evaluate((selectedProviderId) => {
            const provider = vars('available_providers').find((item) => String(item.id) === selectedProviderId);
            return JSON.parse(provider?.settings?.working_plan_exceptions || '{}');
        }, workingPlanProvider);
    const findWorkingPlanEvent = async (date) => {
        const events = page.locator('.fc-working-plan-exception.fc-custom');
        const index = await events.evaluateAll(
            (elements, targetDate) =>
                elements.findIndex(
                    (element) => element.closest('[data-date]')?.getAttribute('data-date') === targetDate,
                ),
            date,
        );
        if (index < 0) {
            fail(`success: no working-plan event rendered for ${date}`);
        }
        return events.nth(index);
    };
    const dismissWorkingPlanErrorDialog = async () => {
        const errorModal = page.locator('#message-modal');
        if (await errorModal.isVisible().catch(() => false)) {
            await errorModal.locator('.modal-footer button').click();
            await errorModal.waitFor({state: 'hidden'});
        }
    };
    const workingPlanExceptionsBeforeCreate = await readWorkingPlanExceptions();
    const workingPlanReloadBefore = postPaths.filter((path) => reloadPaths.has(path)).length;
    const workingPlanEventsBefore = await page.locator('.fc-working-plan-exception.fc-custom').count();
    const workingPlanAuthority = await page.evaluate(() => ({
        role: vars('role_slug'),
        canEditUsers: vars('privileges')?.users?.edit === true,
    }));
    if (workingPlanAuthority.role !== 'admin' || !workingPlanAuthority.canEditUsers) {
        fail('working_plan_exception: authenticated actor is not an Admin with users.edit authority');
    }

    await page.locator('#calendar-actions [data-bs-toggle="dropdown"]').click();
    const workingPlanInsert = page.locator('#insert-working-plan-exception');
    if (!(await workingPlanInsert.isVisible())) {
        fail('working_plan_exception: Admin working-plan action is not visible');
    }
    await workingPlanInsert.click();
    await workingPlanModal.waitFor({state: 'visible'});
    await page.evaluate(
        ({date, start, end}) => {
            App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-date'), moment(date).toDate());
            App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-start'), moment(start, 'HH:mm').toDate());
            App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-end'), moment(end, 'HH:mm').toDate());
        },
        {date: workingPlanDate, start: workingPlanStart, end: workingPlanEnd},
    );
    const workingPlanCreateFailure = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname ===
                new URL(routeUrl(baseUrl, SAVE_WORKING_PLAN_EXCEPTION_PATH)).pathname &&
            response.status() === 500,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([workingPlanCreateFailure, workingPlanModal.locator('#working-plan-exceptions-save').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    await dismissWorkingPlanErrorDialog();
    if (postPaths.filter((path) => reloadPaths.has(path)).length !== workingPlanReloadBefore) {
        fail('failure: working-plan exception create reloaded the calendar');
    }
    if ((await page.locator('.fc-working-plan-exception.fc-custom').count()) !== workingPlanEventsBefore) {
        fail('failure: working-plan exception create changed the rendered events');
    }
    if (JSON.stringify(await readWorkingPlanExceptions()) !== JSON.stringify(workingPlanExceptionsBeforeCreate)) {
        fail('failure: working-plan exception create changed the backing exception map');
    }

    await page.locator('#calendar-actions [data-bs-toggle="dropdown"]').click();
    if (!(await workingPlanInsert.isVisible())) {
        fail('working_plan_exception: Admin working-plan action is not visible for the second create');
    }
    await workingPlanInsert.click();
    await workingPlanModal.waitFor({state: 'visible'});
    await page.evaluate(
        ({date, start, end}) => {
            App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-date'), moment(date).toDate());
            App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-start'), moment(start, 'HH:mm').toDate());
            App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-end'), moment(end, 'HH:mm').toDate());
        },
        {date: workingPlanDate, start: workingPlanStart, end: workingPlanEnd},
    );
    const workingPlanCreateSuccess = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname ===
                new URL(routeUrl(baseUrl, SAVE_WORKING_PLAN_EXCEPTION_PATH)).pathname &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    const workingPlanCreateReload = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === expectedReloadPath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([
        workingPlanCreateSuccess,
        workingPlanCreateReload,
        workingPlanModal.locator('#working-plan-exceptions-save').click(),
    ]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    await workingPlanModal.waitFor({state: 'hidden'});
    const workingPlanEvent = await findWorkingPlanEvent(workingPlanDate);
    await workingPlanEvent.waitFor({state: 'visible'});
    if ((await page.locator('.fc-working-plan-exception.fc-custom').count()) <= workingPlanEventsBefore) {
        fail('success: working-plan exception create did not add a rendered event');
    }
    const workingPlanRenderedDate = await workingPlanEvent.evaluate(
        (element) => element.closest('[data-date]')?.getAttribute('data-date') || '',
    );
    if (workingPlanRenderedDate !== workingPlanDate) {
        fail('success: working-plan exception create rendered on the wrong date');
    }
    const workingPlanPayloads = workingPlanSaveRequests.slice(0, 2);
    if (
        workingPlanPayloads.length !== 2 ||
        workingPlanPayloads.some(
            (payload) =>
                !payload.csrf ||
                payload.provider !== workingPlanProvider ||
                payload.date !== workingPlanDate ||
                payload.start !== workingPlanStart ||
                payload.end !== workingPlanEnd,
        )
    ) {
        fail('payload: working-plan exception create did not send provider/date/start/end');
    }
    const workingPlanExceptionsAfterCreate = await readWorkingPlanExceptions();

    await workingPlanEvent.click();
    const workingPlanPopover = page.locator('.popover').last();
    await workingPlanPopover.waitFor({state: 'visible'});
    await workingPlanPopover.locator('.edit-popover').click();
    await workingPlanModal.waitFor({state: 'visible'});
    const workingPlanOpened = await page.evaluate(() => ({
        date: moment(App.Utils.UI.getDateTimePickerValue($('#working-plan-exceptions-date'))).format('YYYY-MM-DD'),
        start: moment(App.Utils.UI.getDateTimePickerValue($('#working-plan-exceptions-start'))).format('HH:mm'),
        end: moment(App.Utils.UI.getDateTimePickerValue($('#working-plan-exceptions-end'))).format('HH:mm'),
    }));
    if (
        workingPlanOpened.date !== workingPlanDate ||
        workingPlanOpened.start !== workingPlanStart ||
        workingPlanOpened.end !== workingPlanEnd
    ) {
        fail('payload: working-plan exception edit did not prepopulate the selected date/start/end');
    }
    await page.evaluate(
        (end) => App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-end'), moment(end, 'HH:mm').toDate()),
        workingPlanEditedEnd,
    );
    const workingPlanEditFailure = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname ===
                new URL(routeUrl(baseUrl, SAVE_WORKING_PLAN_EXCEPTION_PATH)).pathname &&
            response.status() === 500,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([workingPlanEditFailure, workingPlanModal.locator('#working-plan-exceptions-save').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    await dismissWorkingPlanErrorDialog();
    if (postPaths.filter((path) => reloadPaths.has(path)).length !== workingPlanReloadBefore + 1) {
        fail('failure: working-plan exception edit reloaded the calendar');
    }
    if (
        workingPlanSaveRequests[2]?.date !== workingPlanDate ||
        workingPlanSaveRequests[2]?.originalDate !== workingPlanDate ||
        workingPlanSaveRequests[2]?.start !== workingPlanStart ||
        workingPlanSaveRequests[2]?.end !== workingPlanEditedEnd ||
        JSON.stringify(await readWorkingPlanExceptions()) !== JSON.stringify(workingPlanExceptionsAfterCreate)
    ) {
        fail('failure: working-plan exception edit changed date, times, or backing exception map');
    }
    await workingPlanEvent.waitFor({state: 'visible'});

    await workingPlanEvent.click();
    await page.locator('.popover').last().locator('.edit-popover').click();
    await workingPlanModal.waitFor({state: 'visible'});
    const workingPlanAfterFailedEdit = await page.evaluate(() => ({
        date: moment(App.Utils.UI.getDateTimePickerValue($('#working-plan-exceptions-date'))).format('YYYY-MM-DD'),
        start: moment(App.Utils.UI.getDateTimePickerValue($('#working-plan-exceptions-start'))).format('HH:mm'),
        end: moment(App.Utils.UI.getDateTimePickerValue($('#working-plan-exceptions-end'))).format('HH:mm'),
    }));
    if (
        workingPlanAfterFailedEdit.date !== workingPlanDate ||
        workingPlanAfterFailedEdit.start !== workingPlanStart ||
        workingPlanAfterFailedEdit.end !== workingPlanEnd
    ) {
        fail('failure: working-plan exception edit changed the rendered event after HTTP 500');
    }
    await page.evaluate(
        ({date, end}) => {
            App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-date'), moment(date).toDate());
            App.Utils.UI.setDateTimePickerValue($('#working-plan-exceptions-end'), moment(end, 'HH:mm').toDate());
        },
        {date: workingPlanMovedDate, end: workingPlanEditedEnd},
    );
    const workingPlanEditSuccess = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname ===
                new URL(routeUrl(baseUrl, SAVE_WORKING_PLAN_EXCEPTION_PATH)).pathname &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    const workingPlanEditReload = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === expectedReloadPath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([
        workingPlanEditSuccess,
        workingPlanEditReload,
        workingPlanModal.locator('#working-plan-exceptions-save').click(),
    ]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    const workingPlanEventAfterMove = await findWorkingPlanEvent(workingPlanMovedDate);
    await workingPlanEventAfterMove.waitFor({state: 'visible'});
    const movedWorkingPlanRenderedDate = await workingPlanEventAfterMove.evaluate(
        (element) => element.closest('[data-date]')?.getAttribute('data-date') || '',
    );
    const workingPlanEditPayload = workingPlanSaveRequests[3];
    if (
        !workingPlanEditPayload ||
        workingPlanEditPayload.provider !== workingPlanProvider ||
        workingPlanEditPayload.date !== workingPlanMovedDate ||
        workingPlanEditPayload.originalDate !== workingPlanDate ||
        workingPlanEditPayload.start !== workingPlanStart ||
        workingPlanEditPayload.end !== workingPlanEditedEnd ||
        movedWorkingPlanRenderedDate !== workingPlanMovedDate
    ) {
        fail('payload: working-plan exception edit did not move the rendered event or send the changed date/end time');
    }
    const movedWorkingPlanSettings = await page.evaluate(
        ({providerId, oldDate, newDate}) => {
            const provider = vars('available_providers').find((item) => String(item.id) === providerId);
            const exceptions = JSON.parse(provider?.settings?.working_plan_exceptions || '{}');
            return {
                oldPresent: Object.prototype.hasOwnProperty.call(exceptions, oldDate),
                newValue: exceptions[newDate],
            };
        },
        {providerId: workingPlanProvider, oldDate: workingPlanDate, newDate: workingPlanMovedDate},
    );
    if (movedWorkingPlanSettings.oldPresent || movedWorkingPlanSettings.newValue?.end !== workingPlanEditedEnd) {
        fail('success: working-plan exception edit did not update the backing exception map');
    }
    const workingPlanExceptionsAfterMove = await readWorkingPlanExceptions();

    await workingPlanEventAfterMove.click();
    const workingPlanDeleteFailure = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname ===
                new URL(routeUrl(baseUrl, DELETE_WORKING_PLAN_EXCEPTION_PATH)).pathname &&
            response.status() === 500,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([workingPlanDeleteFailure, page.locator('.popover').last().locator('.delete-popover').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    await dismissWorkingPlanErrorDialog();
    if (postPaths.filter((path) => reloadPaths.has(path)).length !== workingPlanReloadBefore + 2) {
        fail('failure: working-plan exception delete reloaded the calendar');
    }
    if (JSON.stringify(await readWorkingPlanExceptions()) !== JSON.stringify(workingPlanExceptionsAfterMove)) {
        fail('failure: working-plan exception delete changed the backing exception map');
    }
    await workingPlanEventAfterMove.waitFor({state: 'visible'});

    await workingPlanEventAfterMove.click();
    const workingPlanDeleteSuccess = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname ===
                new URL(routeUrl(baseUrl, DELETE_WORKING_PLAN_EXCEPTION_PATH)).pathname &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    const workingPlanDeleteReload = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === expectedReloadPath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([
        workingPlanDeleteSuccess,
        workingPlanDeleteReload,
        page.locator('.popover').last().locator('.delete-popover').click(),
    ]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    await page.waitForFunction(
        (date) =>
            ![...document.querySelectorAll('.fc-working-plan-exception.fc-custom')].some(
                (element) => element.closest('[data-date]')?.getAttribute('data-date') === date,
            ),
        workingPlanMovedDate,
        {timeout: interactionTimeoutMs},
    );
    if (
        workingPlanDeleteRequests.length !== 2 ||
        workingPlanDeleteRequests.some(
            (payload) =>
                !payload.csrf || payload.provider !== workingPlanProvider || payload.date !== workingPlanMovedDate,
        )
    ) {
        fail('payload: working-plan exception delete did not send provider/date');
    }

    const eventId = '769001';
    const eventStart = new Date();
    eventStart.setHours(9, 0, 0, 0);
    const eventEnd = new Date(eventStart.getTime() + 60 * 60 * 1000);
    const formatDate = (date) => {
        const pad = (value) => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    };
    const eventStartValue = formatDate(eventStart);
    const eventEndValue = formatDate(eventEnd);
    const originalNotes = 'R774<img src=x onerror=R774=1>';
    if (originalNotes.length > 30) {
        fail('payload: synthetic HTML-shaped note exceeds the event-title truncation limit');
    }
    const editedNotes = 'ROB-769 edited manual unavailability';
    const appointmentId = '775001';
    const appointmentStart = new Date(eventStart.getTime() + 2 * 60 * 60 * 1000);
    const appointmentEnd = new Date(appointmentStart.getTime() + 60 * 60 * 1000);
    const appointmentStartValue = formatDate(appointmentStart);
    const appointmentEndValue = formatDate(appointmentEnd);
    const appointmentServiceName = 'ROB-775 synthetic service';
    const appointmentCustomerFirstName = 'ROB-775 <img src=x onerror=ROB775=1> Parent';
    const appointmentCustomerLastName = 'Synthetic Customer';
    const appointmentTitle = `${appointmentServiceName} - ${appointmentCustomerFirstName} ${appointmentCustomerLastName}`;
    await page.evaluate(() => {
        window.ROB775 = 0;
    });
    syntheticFeedBody = JSON.stringify({
        appointments: [
            {
                id: appointmentId,
                start_datetime: appointmentStartValue,
                end_datetime: appointmentEndValue,
                location: '',
                notes: '',
                color: '#123456',
                status: 'Booked',
                id_users_provider: Number(providerData.id),
                id_users_customer: 775001,
                id_services: 775001,
                provider: providerData,
                service: {id: 775001, name: appointmentServiceName},
                customer: {
                    id: 775001,
                    first_name: appointmentCustomerFirstName,
                    last_name: appointmentCustomerLastName,
                    email: '',
                    phone_number: '',
                    address: '',
                    city: '',
                    state: '',
                    zip_code: '',
                    language: 'english',
                    timezone: providerData.timezone || '',
                    notes: '',
                    custom_field_1: '',
                    custom_field_2: '',
                    custom_field_3: '',
                    custom_field_4: '',
                    custom_field_5: '',
                },
            },
        ],
        unavailabilities: [
            {
                id: eventId,
                id_users_provider: Number(providerData.id),
                start_datetime: eventStartValue,
                end_datetime: eventEndValue,
                notes: originalNotes,
                id_parent_appointment: 0,
                is_unavailability: 1,
                provider: providerData,
            },
        ],
        blocked_periods: [],
    });
    const fullSyntheticFeedBody = syntheticFeedBody;
    syntheticFeed = true;
    await page.evaluate(() => {
        window.R774 = 0;
    });
    const initialSyntheticReload = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === expectedReloadPath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([initialSyntheticReload, page.locator('#reload-appointments').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });

    const appointmentEventLocator = page.locator('.fc-event').filter({hasText: appointmentTitle}).first();
    await appointmentEventLocator.waitFor({state: 'visible'});
    if (!(await appointmentEventLocator.innerText()).includes(appointmentTitle)) {
        fail('render: appointment title did not remain literal text');
    }
    await appointmentEventLocator.click();
    const appointmentPopover = page.locator('.popover').filter({hasText: appointmentCustomerLastName}).last();
    await appointmentPopover.waitFor({state: 'visible'});
    const appointmentPopoverText = await appointmentPopover.innerText();
    if (
        !appointmentPopoverText.includes(appointmentCustomerFirstName) ||
        !appointmentPopoverText.includes(appointmentCustomerLastName)
    ) {
        fail('render: appointment popover did not display the literal customer name');
    }
    if (
        (await appointmentPopover.locator('img[src="x"]').count()) !== 0 ||
        (await page.evaluate(() => window.ROB775)) !== 0
    ) {
        fail('security: appointment customer name created a DOM node or executed a handler');
    }
    await appointmentPopover.locator('.close-popover').click();
    await appointmentPopover.waitFor({state: 'hidden'});

    const interactionPostPathStart = postPaths.length;
    stage = 'dialog';
    const eventLocator = page.locator('.fc-unavailability.fc-custom').first();
    await eventLocator.waitFor({state: 'visible'});
    const eventText = await eventLocator.textContent();
    if (calendarView === 'default') {
        if (!eventText || !eventText.includes(originalNotes)) {
            fail('display: synthetic HTML-shaped note was not rendered as full event text');
        }
    } else if (eventText && eventText.includes(originalNotes)) {
        fail('display: table event title unexpectedly rendered the unavailability note');
    }
    if ((await eventLocator.locator('img').count()) !== 0) {
        fail('security: synthetic HTML-shaped note created an image DOM node');
    }
    const payloadState = await page.evaluate(() => ({
        payloadNodeCount: document.querySelectorAll('img[src="x"]').length,
        handlerExecuted: window.R774 !== 0,
    }));
    if (payloadState.payloadNodeCount !== 0 || payloadState.handlerExecuted) {
        fail('security: synthetic HTML-shaped note created a node or executed a handler');
    }
    await eventLocator.click();
    const popover = page.locator('.popover').last();
    await popover.waitFor({state: 'visible'});
    const popoverTitle = await popover.locator('.popover-header').textContent();
    if (calendarView === 'default') {
        if (!popoverTitle || !popoverTitle.includes(originalNotes)) {
            fail('display: synthetic HTML-shaped note was not rendered as full popover title text');
        }
    } else if (popoverTitle && popoverTitle.includes(originalNotes)) {
        fail('display: table popover title unexpectedly rendered the unavailability note');
    }
    const popoverPayloadState = await page.evaluate(() => ({
        payloadNodeCount: document.querySelectorAll('img[src="x"]').length,
        handlerExecuted: window.R774 !== 0,
    }));
    if (popoverPayloadState.payloadNodeCount !== 0 || popoverPayloadState.handlerExecuted) {
        fail('security: popover created a payload node or executed a handler');
    }
    await page.locator('.popover .edit-popover').click();
    await modal.waitFor({state: 'visible'});
    const openedValues = await page.evaluate(() => ({
        id: $('#unavailability-id').val(),
        provider: $('#unavailability-provider').val(),
        start: moment(App.Utils.UI.getDateTimePickerValue($('#unavailability-start'))).format('YYYY-MM-DD HH:mm:ss'),
        end: moment(App.Utils.UI.getDateTimePickerValue($('#unavailability-end'))).format('YYYY-MM-DD HH:mm:ss'),
        notes: $('#unavailability-notes').val(),
    }));
    if (
        openedValues.id !== eventId ||
        openedValues.provider !== providerId ||
        openedValues.start !== eventStartValue ||
        openedValues.end !== eventEndValue ||
        openedValues.notes !== originalNotes
    ) {
        fail('payload: existing manual unavailability did not prepopulate the edit dialog');
    }

    stage = 'failure';
    const reloadRequestsBeforeFailure = postPaths.filter((path) => reloadPaths.has(path)).length;
    const firstSaveResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(SAVE_PATH) &&
            response.status() === 500,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([firstSaveResponse, modal.locator('#save-unavailability').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    const errorModal = page.locator('#message-modal');
    await errorModal.waitFor({state: 'visible', timeout: interactionTimeoutMs});
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

    if (
        saveRequests[2].id !== eventId ||
        saveRequests[2].provider !== providerId ||
        saveRequests[2].start !== eventStartValue ||
        saveRequests[2].end !== eventEndValue ||
        saveRequests[2].notes !== originalNotes
    ) {
        fail('payload: failed edit did not send the exact existing-event payload');
    }
    await modal.locator('#unavailability-notes').fill(editedNotes);
    stage = 'success';
    const reloadResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === expectedReloadPath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    const secondSaveResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(SAVE_PATH) &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([secondSaveResponse, reloadResponse, modal.locator('#save-unavailability').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    await modal.waitFor({state: 'hidden'});

    if (saveRequests.length !== 4) {
        fail('payload: expected exactly four intercepted save attempts');
    }
    for (const payload of saveRequests.slice(2)) {
        if (!payload.csrf || payload.id !== eventId || payload.provider !== String(providerId)) {
            fail('payload: CSRF or selected provider did not reach the save request');
        }
        if (payload.start !== eventStartValue || payload.end !== eventEndValue) {
            fail('payload: selected start/end did not reach the save request');
        }
    }
    if (saveRequests[3].notes !== editedNotes) {
        fail('payload: successful edit did not send the changed notes');
    }

    stage = 'delete';
    await eventLocator.click();
    const deleteResponseFailure = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === deletePath &&
            response.status() === 500,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([deleteResponseFailure, page.locator('.popover .delete-popover').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    const reloadRequestsAfterDeleteFailure = postPaths.filter((path) => reloadPaths.has(path)).length;
    if (reloadRequestsAfterDeleteFailure !== reloadRequestsBeforeFailure + 1) {
        fail('failure: calendar reload occurred after simulated delete failure');
    }
    if (deleteRequests[0].id !== eventId || !deleteRequests[0].csrf) {
        fail('payload: failed delete did not send the exact existing-event id');
    }
    if (!(await eventLocator.isVisible())) {
        fail('failure: existing event disappeared after simulated delete failure');
    }

    await eventLocator.click();
    const deleteResponseSuccess = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === deletePath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    const deleteReloadResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === expectedReloadPath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([deleteResponseSuccess, deleteReloadResponse, page.locator('.popover .delete-popover').click()]);
    await page.waitForFunction(() => window.jQuery && jQuery.active === 0, undefined, {
        timeout: interactionTimeoutMs,
    });
    await eventLocator.waitFor({state: 'detached', timeout: interactionTimeoutMs});
    if (deleteRequests.length !== 2 || deleteRequests[1].id !== eventId || !deleteRequests[1].csrf) {
        fail('payload: successful delete did not send the exact existing-event id');
    }

    // The delete handler clears its synthetic feed after the successful delete. Restore the
    // complete captured fixture before exercising the table view in this same browser context.
    syntheticFeedBody = fullSyntheticFeedBody;
    const tableViewPostPathStart = postPaths.length;
    const tableViewUrl = routeUrl(baseUrl, 'calendar?view=table');
    const tableViewReloadResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === tableReloadPath &&
            response.status() === 200,
        {timeout: interactionTimeoutMs},
    );
    await Promise.all([tableViewReloadResponse, page.goto(tableViewUrl, {waitUntil: 'domcontentloaded'})]);
    if ((await page.evaluate(() => vars('calendar_view'))) !== 'table') {
        fail('dialog: calendar?view=table did not select the table view');
    }
    await page
        .locator('.calendar-view .fc-event')
        .filter({hasText: appointmentTitle})
        .first()
        .waitFor({state: 'visible'});
    const tableAppointmentEventLocator = page
        .locator('.calendar-view .fc-event')
        .filter({hasText: appointmentTitle})
        .first();
    if (!(await tableAppointmentEventLocator.innerText()).includes(appointmentTitle)) {
        fail('dialog: table appointment title did not remain literal text');
    }
    await tableAppointmentEventLocator.click();
    const tableAppointmentPopover = page.locator('.popover').filter({hasText: appointmentCustomerLastName}).last();
    await tableAppointmentPopover.waitFor({state: 'visible'});
    const tableAppointmentPopoverText = await tableAppointmentPopover.innerText();
    if (
        !tableAppointmentPopoverText.includes(appointmentCustomerFirstName) ||
        !tableAppointmentPopoverText.includes(appointmentCustomerLastName)
    ) {
        fail('dialog: table appointment popover did not display the literal customer name');
    }
    if (
        (await tableAppointmentPopover.locator('img[src="x"]').count()) !== 0 ||
        (await page.evaluate(() => window.ROB775)) !== 0
    ) {
        fail('security: table appointment customer name created a DOM node or executed a handler');
    }
    await tableAppointmentPopover.locator('.close-popover').click();
    await tableAppointmentPopover.waitFor({state: 'hidden'});
    const tableViewPostPaths = postPaths.slice(tableViewPostPathStart);
    if (tableViewPostPaths.some((path) => path !== tableReloadPath) || blockedWritePaths.length > 0) {
        fail('payload: table view requested an unexpected write route');
    }

    const interactionPostPaths = postPaths.slice(interactionPostPathStart);
    const unexpectedWrites = interactionPostPaths.filter(
        (path) =>
            !path.endsWith(SAVE_PATH) &&
            path !== deletePath &&
            path !== new URL(routeUrl(baseUrl, SAVE_WORKING_PLAN_EXCEPTION_PATH)).pathname &&
            path !== new URL(routeUrl(baseUrl, DELETE_WORKING_PLAN_EXCEPTION_PATH)).pathname &&
            !reloadPaths.has(path),
    );
    if (unexpectedWrites.length > 0 || blockedWritePaths.length > 0) {
        fail('payload: an unexpected write route was requested');
    }

    return {
        request_payload_verified: true,
        failure_state_verified: true,
        success_state_verified: true,
        existing_event_prepopulation_verified: true,
        edit_failure_state_verified: true,
        edit_success_reload_verified: true,
        delete_failure_state_verified: true,
        delete_success_reload_verified: true,
        working_plan_exception_create_verified: true,
        working_plan_exception_edit_verified: true,
        working_plan_exception_delete_verified: true,
        working_plan_duration_ms: Date.now() - workingPlanStartedAt,
        table_view_literal_name_verified: true,
        table_view_write_boundary_verified: true,
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
        const overallTimeoutMs = Math.max(90000, openTimeoutSeconds * 3000 + 35000);
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

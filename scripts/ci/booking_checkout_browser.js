'use strict';

const fs = require('node:fs');
const playwright = require('playwright');

const browserTypes = {
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
    error.stage = stage;
    throw error;
}

function routePath(baseUrl, path) {
    const base = new URL(baseUrl);
    const prefix = base.pathname.replace(/\/$/, '');
    return `${base.origin}${prefix}/${path.replace(/^\//, '')}`;
}

function assertLoopbackUrl(value) {
    const parsed = new URL(value);
    if (parsed.protocol !== 'http:' || !new Set(['localhost', '127.0.0.1', '[::1]', 'nginx']).has(parsed.hostname)) {
        fail('base and target URLs must use plain HTTP on localhost, loopback, or nginx.');
    }
    return parsed;
}

function parseRegisterPayload(form) {
    const rawPostData = form.get('post_data') || '';
    if (rawPostData !== '') {
        try {
            return JSON.parse(rawPostData);
        } catch {
            // jQuery serializes nested objects as bracketed form keys below.
        }
    }

    const payload = {appointment: {}, customer: {}};
    for (const [key, value] of form.entries()) {
        const match = key.match(/^post_data\[(appointment|customer)\]\[([^\]]+)\]$/);
        if (match) payload[match[1]][match[2]] = value;
    }
    return payload;
}

async function closeBrowser() {
    if (context) await context.close();
    if (browser) await browser.close();
    context = null;
    browser = null;
}

async function main() {
    const input = JSON.parse(fs.readFileSync(0, 'utf8'));
    const browserType = browserTypes[input.browser || 'firefox'];
    if (!browserType) fail(`unsupported browser: ${input.browser}`);

    stage = 'launch';
    browser = await browserType.launch({
        headless: !Boolean(input.headed),
        timeout: Number(input.open_timeout || 20) * 1000,
        ...(input.executable_path ? {executablePath: input.executable_path} : {}),
        ...(!input.executable_path && (input.browser === 'chrome' || input.browser === 'msedge')
            ? {channel: input.browser}
            : {}),
    });
    context = await browser.newContext();
    context.setDefaultTimeout(Number(input.open_timeout || 20) * 1000);
    const page = await context.newPage();
    const baseUrl = assertLoopbackUrl(input.base_url);
    const targetUrl = assertLoopbackUrl(input.target_url);
    if (baseUrl.origin !== targetUrl.origin) fail('base and target URLs must share the same origin.');
    const expectedOrigin = baseUrl.origin;
    const registerPath = new URL(routePath(input.base_url, 'booking/register')).pathname;
    const availableHoursPath = new URL(routePath(input.base_url, 'booking/get_available_hours')).pathname;
    const confirmationPrefix = new URL(routePath(input.base_url, 'booking_confirmation/of/')).pathname;
    const syntheticConfirmationPath = `${confirmationPrefix}synthetic-browser-checkout`;
    let registerPayload = null;
    let registerRequests = 0;
    let registerFulfilled = false;
    let registerRouteError = '';
    let confirmationRequests = 0;
    const blockedMutationPaths = [];

    await context.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.method() === 'POST' && url.origin === expectedOrigin && url.pathname === registerPath) {
            registerRequests += 1;
            try {
                if (registerRequests !== 1) fail('booking/register was submitted more than once.');
                const form = new URLSearchParams(request.postData() || '');
                registerPayload = parseRegisterPayload(form);
                if (!registerPayload?.appointment || !registerPayload?.customer) {
                    fail('booking/register payload omitted appointment or customer data.');
                }
                if (String(registerPayload.customer.email || '').trim() !== '') {
                    fail('name-only checkout unexpectedly submitted a parent email.');
                }
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({appointment_id: 987654, appointment_hash: 'synthetic-browser-checkout'}),
                });
                registerFulfilled = true;
            } catch (error) {
                registerRouteError = String(error.message || error);
                await route.abort('blockedbyclient').catch(() => {});
            }
            return;
        }
        if (request.method() === 'GET' && url.origin === expectedOrigin && url.pathname === syntheticConfirmationPath) {
            await route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<!doctype html><html><body><main id="synthetic-booking-confirmation">Synthetic confirmation</main></body></html>',
            });
            return;
        }
        if (request.method() === 'POST' && url.origin === expectedOrigin && url.pathname === availableHoursPath) {
            await route.continue();
            return;
        }
        if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method())) {
            blockedMutationPaths.push(url.pathname);
            await route.abort('blockedbyclient');
            return;
        }
        await route.continue();
    });

    page.on('request', (request) => {
        if (request.method() === 'GET' && new URL(request.url()).pathname === syntheticConfirmationPath) {
            confirmationRequests += 1;
        }
    });

    stage = 'open';
    await page.goto(targetUrl.toString(), {waitUntil: 'domcontentloaded'});
    if (await page.locator('#login-form').count()) fail('booking page redirected to login.');
    const serviceOptions = await page
        .locator('#select-service option')
        .evaluateAll((options) =>
            options
                .map((option) => ({value: option.value, label: option.textContent.trim()}))
                .filter((option) => option.value),
        );
    if (serviceOptions.length === 0) fail('booking page exposed no public service options.');
    const selectedService = String(input.expected_service_id);
    if (!serviceOptions.some((option) => String(option.value) === selectedService)) {
        fail('public service options did not resolve to the selected fixture service.');
    }

    stage = 'service-provider';
    const selectedProvider = String(input.expected_provider_id);
    await page.waitForFunction(() =>
        ['#wizard-frame-1', '#wizard-frame-2'].some((selector) => {
            const frame = document.querySelector(selector);
            if (!frame) return false;
            const style = window.getComputedStyle(frame);
            return style.display !== 'none' && style.visibility !== 'hidden' && frame.getClientRects().length > 0;
        }),
    );
    const frameOneVisible = await page.locator('#wizard-frame-1').isVisible();
    if (frameOneVisible) {
        await page.selectOption('#select-service', selectedService);
        await page.waitForFunction(() => {
            const select = document.querySelector('#select-provider');
            return select && [...select.options].some((option) => option.value);
        });
        const providerOptions = await page
            .locator('#select-provider option')
            .evaluateAll((options) =>
                options
                    .map((option) => ({value: option.value, label: option.textContent.trim()}))
                    .filter((option) => option.value),
            );
        if (!providerOptions.some((option) => String(option.value) === selectedProvider)) {
            fail('public provider options did not resolve to the selected fixture provider.');
        }
        await page.selectOption('#select-provider', selectedProvider);
        await page.click('#button-next-1');
    } else {
        const initializedSelection = await page.evaluate(() => ({
            service: document.querySelector('#select-service')?.value || '',
            provider: document.querySelector('#select-provider')?.value || '',
        }));
        if (
            String(initializedSelection.service) !== selectedService ||
            String(initializedSelection.provider) !== selectedProvider
        ) {
            fail('auto-skipped booking step did not initialize the expected fixture service/provider.');
        }
    }

    stage = 'slot';
    await page.waitForSelector('#wizard-frame-2', {state: 'visible'});
    await page.evaluate((expectedDate) => {
        const input = document.querySelector('#select-date');
        if (!input?._flatpickr) throw new Error('booking date picker was not initialized.');
        input._flatpickr.setDate(expectedDate, true);
    }, input.expected_date);
    await page.waitForFunction((expectedDate) => {
        const input = document.querySelector('#select-date');
        return input?._flatpickr?.selectedDates?.some((date) => {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}` === expectedDate;
        });
    }, input.expected_date);
    const slotHandle = await page.waitForFunction(() => {
        const jquery = window.jQuery || window.$;
        const buttons = Array.from(document.querySelectorAll('#available-hours .available-hour'));
        for (const [index, button] of buttons.entries()) {
            if (!button.getClientRects().length) continue;
            const value = String(
                (jquery ? jquery(button).data('value') : undefined) ?? button.dataset.value ?? '',
            ).trim();
            if (value) return {index, value};
        }
        return null;
    });
    const {index: slotIndex, value: selectedHour} = await slotHandle.jsonValue();
    await page.locator('#available-hours .available-hour').nth(slotIndex).click();
    await page.click('#button-next-2');

    stage = 'name-only';
    await page.waitForSelector('#wizard-frame-3', {state: 'visible'});
    const email = page.locator('#email');
    if ((await email.count()) && ((await email.getAttribute('class')) || '').split(/\s+/).includes('required')) {
        fail('booking configuration requires an email, so name-only checkout is unavailable.');
    }
    for (const selector of ['#first-name', '#last-name']) {
        const nameField = page.locator(selector);
        if (
            !(await nameField.isVisible()) ||
            !((await nameField.getAttribute('class')) || '').split(/\s+/).includes('required')
        ) {
            fail(`name-only fixture did not require ${selector}.`);
        }
    }
    await page.click('#button-next-3');
    if (!(await page.locator('#wizard-frame-3').isVisible()) || (await page.locator('#wizard-frame-4').isVisible())) {
        fail('blank parent names advanced the booking wizard.');
    }
    for (const selector of ['#first-name', '#last-name']) {
        if (!((await page.locator(selector).getAttribute('class')) || '').split(/\s+/).includes('is-invalid')) {
            fail(`blank ${selector} was not marked invalid.`);
        }
    }
    if (registerRequests !== 0) fail('blank parent names submitted booking/register.');
    if (await page.locator('#first-name').count()) await page.fill('#first-name', input.first_name || 'Browser');
    if (await page.locator('#last-name').count()) await page.fill('#last-name', input.last_name || 'Checkout');
    await page.click('#button-next-3');
    await page.waitForSelector('#wizard-frame-4', {state: 'visible'});
    for (const selector of ['#accept-to-terms-and-conditions', '#accept-to-privacy-policy']) {
        const checkbox = page.locator(selector);
        if ((await checkbox.count()) && !(await checkbox.isChecked())) await checkbox.check();
    }

    stage = 'register';
    try {
        await Promise.all([
            page.waitForURL((url) => url.pathname.startsWith(confirmationPrefix), {waitUntil: 'domcontentloaded'}),
            page.click('#book-appointment-submit'),
        ]);
    } catch (error) {
        const captchaVisible = await page
            .locator('.captcha-text')
            .isVisible()
            .catch(() => false);
        fail(
            `confirmation navigation failed (register_requests=${registerRequests}, register_fulfilled=${registerFulfilled}, route_error=${registerRouteError}, captcha_visible=${captchaVisible}, blocked_mutations=${blockedMutationPaths.length}): ${error.message}`,
        );
    }
    await page.waitForSelector('#synthetic-booking-confirmation', {state: 'visible'});
    if (registerRequests !== 1 || !registerPayload) fail('booking/register payload was not captured.');
    const enteredFirstName = input.first_name || 'Browser';
    const enteredLastName = input.last_name || 'Checkout';
    if (String(registerPayload.appointment.id_users_provider) !== String(selectedProvider)) {
        fail('booking/register provider payload did not match the selected provider.');
    }
    if (String(registerPayload.appointment.id_services) !== String(selectedService)) {
        fail('booking/register service payload did not match the selected service.');
    }
    if (!String(registerPayload.appointment.start_datetime || '').startsWith(`${input.expected_date} `)) {
        fail('booking/register date payload did not match the resolved fixture date.');
    }
    if (!String(registerPayload.appointment.start_datetime || '').includes(` ${selectedHour}:00`)) {
        fail('booking/register time payload did not match the selected fixture slot.');
    }
    if (
        String(registerPayload.customer.first_name || '') !== enteredFirstName ||
        String(registerPayload.customer.last_name || '') !== enteredLastName
    ) {
        fail('booking/register payload did not preserve the supplied name.');
    }
    if (confirmationRequests !== 1 || !page.url().includes('/booking_confirmation/of/synthetic-browser-checkout')) {
        fail('confirmation navigation did not reach the synthetic booking confirmation URL.');
    }
    if (blockedMutationPaths.length !== 0) {
        fail(`unexpected browser mutation requests were blocked: ${blockedMutationPaths.join(', ')}`);
    }

    return {
        ok: true,
        public_service_options_verified: serviceOptions.length > 0,
        selected_provider_verified: true,
        selected_slot_verified: true,
        fixture_pair_verified: true,
        name_only_payload_verified: true,
        blank_name_rejection_verified: true,
        register_payload_verified: true,
        confirmation_navigation_verified: true,
        register_requests: registerRequests,
        confirmation_requests: confirmationRequests,
        no_persistence_verified: registerRequests === 1 && blockedMutationPaths.length === 0,
    };
}

main()
    .then(async (payload) => {
        process.stdout.write(`${JSON.stringify(payload)}\n`);
        await closeBrowser();
    })
    .catch(async (error) => {
        process.stderr.write(`${error.stage || stage}: ${error.message || String(error)}\n`);
        await closeBrowser();
        process.exitCode = 1;
    });

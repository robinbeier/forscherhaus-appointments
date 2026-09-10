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
let stage = 'input';

const closeBrowser = async () => {
    if (browser !== null) {
        await browser.close();
        browser = null;
    }
};

const onSignal = (signal) => {
    void closeBrowser().finally(() => {
        process.exit(128 + signal);
    });
};

process.once('SIGTERM', () => onSignal(15));
process.once('SIGINT', () => onSignal(2));

const main = async () => {
    const input = JSON.parse(fs.readFileSync(0, 'utf8'));
    const browserType = browserTypes[input.browser || 'firefox'];

    if (!browserType) {
        throw new Error(`Unsupported Playwright browser: ${input.browser}`);
    }

    const launchOptions = {
        headless: !Boolean(input.headed),
        timeout: Number(input.launch_timeout) > 0 ? Number(input.launch_timeout) * 1000 : 30000,
    };
    if (input.executable_path) {
        launchOptions.executablePath = input.executable_path;
    } else if (input.browser === 'chrome' || input.browser === 'msedge') {
        launchOptions.channel = input.browser;
    }

    stage = 'launch';
    browser = await browserType.launch(launchOptions);
    let checkTimer;
    try {
        await Promise.race([
            (async () => {
                stage = 'context';
                const context = await browser.newContext();
                const page = await context.newPage();
                const runCode = eval(`(${input.snippet})`);

                if (typeof runCode !== 'function') {
                    throw new Error('Dashboard summary browser snippet did not evaluate to a function.');
                }

                stage = 'dashboard assertions';
                await runCode(page);
            })(),
            new Promise((_, reject) => {
                const timeout = Number(input.check_timeout) > 0 ? Number(input.check_timeout) * 1000 : 35000;
                checkTimer = setTimeout(() => reject(new Error('Dashboard browser check timed out.')), timeout);
            }),
        ]);
    } finally {
        clearTimeout(checkTimer);
    }
};

main()
    .catch((error) => {
        const message = error instanceof Error ? error.message : String(error);
        process.stderr.write(`Dashboard summary browser runner failed during ${stage}: ${message}\n`);
        process.exitCode = 1;
    })
    .then(() => closeBrowser())
    .catch((error) => {
        const message = error instanceof Error ? error.message : String(error);
        process.stderr.write(`Dashboard summary browser cleanup failed: ${message}\n`);
        process.exitCode = 1;
    });

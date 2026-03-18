async (page) => {
    const selector = __BOOKING_GATE_SELECTOR__;
    const timeoutMs = __BOOKING_GATE_TIMEOUT_MS__;
    const downloadPath = __BOOKING_GATE_DOWNLOAD_PATH__;

    const result = {
        ok: false,
        selector,
        timeout_ms: Number.isNaN(timeoutMs) ? null : Number(timeoutMs),
        download_path: downloadPath,
        download_suggested_filename: null,
        download_bytes: null,
        page_errors: [],
        console_errors: [],
        error: null,
    };

    if (!downloadPath) {
        result.error = 'Missing BOOKING_GATE_DOWNLOAD_PATH.';
        return result;
    }

    if (Number.isNaN(timeoutMs) || timeoutMs <= 0) {
        result.error = 'Injected timeout must be a positive integer.';
        return result;
    }

    const onPageError = (error) => {
        result.page_errors.push(error && error.message ? String(error.message) : String(error));
    };

    const onConsole = (message) => {
        if (!message || typeof message.type !== 'function') {
            return;
        }

        if (message.type() !== 'error') {
            return;
        }

        result.console_errors.push(String(message.text()));
    };

    page.on('pageerror', onPageError);
    page.on('console', onConsole);

    try {
        const button = page.locator(`${selector}:visible`).first();
        await button.waitFor({state: 'visible', timeout: timeoutMs});

        const [download] = await Promise.all([
            page.waitForEvent('download', {timeout: timeoutMs}),
            button.click({timeout: timeoutMs}),
        ]);

        await download.saveAs(downloadPath);
        result.download_suggested_filename = download.suggestedFilename();

        if (result.page_errors.length > 0 || result.console_errors.length > 0) {
            result.error =
                'JavaScript errors detected during PDF generation. ' +
                `page_errors=${JSON.stringify(result.page_errors)} ` +
                `console_errors=${JSON.stringify(result.console_errors)}`;
            return result;
        }

        result.ok = true;
        return result;
    } catch (error) {
        result.error = error && error.message ? String(error.message) : String(error);
        return result;
    } finally {
        page.off('pageerror', onPageError);
        page.off('console', onConsole);
    }
};

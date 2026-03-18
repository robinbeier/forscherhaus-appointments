async (page) => {
    const resultPrefix = '__BOOKING_CONFIRMATION_PDF_GATE__';
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
    const publishResult = async () => {
        await page.evaluate(
            (payloadText) => {
                const markerId = 'booking-confirmation-pdf-gate-result';
                let marker = document.getElementById(markerId);

                if (!(marker instanceof HTMLElement)) {
                    marker = document.createElement('pre');
                    marker.id = markerId;
                    marker.style.position = 'fixed';
                    marker.style.left = '0';
                    marker.style.bottom = '0';
                    marker.style.zIndex = '2147483647';
                    marker.style.margin = '0';
                    marker.style.padding = '0';
                    marker.style.font = '1px monospace';
                    marker.style.lineHeight = '1';
                    marker.style.background = '#fff';
                    marker.style.color = '#000';
                    marker.style.maxWidth = '1px';
                    marker.style.maxHeight = '1px';
                    marker.style.overflow = 'hidden';
                    document.body.appendChild(marker);
                }

                marker.textContent = payloadText;
            },
            `${resultPrefix}${JSON.stringify(result)}`,
        );

        return result;
    };

    if (!downloadPath) {
        result.error = 'Missing BOOKING_GATE_DOWNLOAD_PATH.';
        return publishResult();
    }

    if (Number.isNaN(timeoutMs) || timeoutMs <= 0) {
        result.error = 'Injected timeout must be a positive integer.';
        return publishResult();
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
            return publishResult();
        }

        result.ok = true;
        return publishResult();
    } catch (error) {
        result.error = error && error.message ? String(error.message) : String(error);
        return publishResult();
    } finally {
        page.off('pageerror', onPageError);
        page.off('console', onConsole);
    }
};

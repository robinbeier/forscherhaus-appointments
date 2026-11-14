import express from 'express';
import fs from 'node:fs';
import puppeteer from 'puppeteer';

const app = express();

let browser;
let browserLaunch;
const launchArgs = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--font-render-hinting=medium',
    '--disable-dev-shm-usage',
];
const chromeExecutableCandidates = [process.env.PUPPETEER_EXECUTABLE_PATH, '/usr/bin/google-chrome'].filter(Boolean);

function resolveExecutablePath() {
    for (const candidate of chromeExecutableCandidates) {
        if (typeof candidate === 'string' && fs.existsSync(candidate)) {
            return candidate;
        }
    }

    return undefined;
}

async function getBrowser() {
    if (!browser) {
        if (!browserLaunch) {
            browserLaunch = (async () => {
                const launchOptions = {args: launchArgs};
                const executablePath = resolveExecutablePath();

                if (executablePath) {
                    launchOptions.executablePath = executablePath;
                }

                const instance = await puppeteer.launch(launchOptions);
                instance.once('disconnected', () => {
                    if (browser === instance) {
                        browser = undefined;
                        browserLaunch = undefined;
                    }
                });
                browser = instance;
                return instance;
            })().catch((error) => {
                browserLaunch = undefined;
                throw error;
            });
        }
        return browserLaunch;
    }
    return browser;
}

app.get('/healthz', (_req, res) => {
    res.json({ok: true});
});

function requirePdfToken(req, res, next) {
    const expectedToken = typeof process.env.PDF_TOKEN === 'string' ? process.env.PDF_TOKEN.trim() : '';

    if (!expectedToken) {
        return res.status(503).json({error: 'renderer_token_not_configured'});
    }

    const provided = req.get('x-pdf-token') || req.get('authorization') || '';
    const normalised = provided.startsWith('Bearer ') ? provided.slice(7) : provided;

    if (normalised !== expectedToken) {
        return res.status(401).json({error: 'unauthorized'});
    }

    return next();
}

app.post('/pdf', requirePdfToken, express.json({limit: '20mb'}), async (req, res) => {
    const {html, waitFor = 'networkidle', format = 'A4', margin, orientation, landscape} = req.body || {};

    if (!html || typeof html !== 'string') {
        return res.status(400).json({error: 'Provide html'});
    }

    let page;

    try {
        const instance = await getBrowser();
        page = await instance.newPage();
        await page.emulateMediaType('screen');

        const landscapeFlag = typeof landscape === 'boolean' ? landscape : orientation === 'landscape';

        await page.setContent(html, {waitUntil: 'networkidle2', timeout: 30000});

        if (waitFor === 'chartsReady') {
            try {
                await page.waitForFunction('window.chartsReady === true', {timeout: 10000});
            } catch (error) {
                console.warn('chartsReady signal not received', error?.message);
            }
        }

        const pdf = await page.pdf({
            format,
            landscape: landscapeFlag,
            printBackground: true,
            preferCSSPageSize: true,
            margin: margin || {top: '12mm', right: '12mm', bottom: '14mm', left: '12mm'},
        });

        res.type('application/pdf').send(pdf);
    } catch (error) {
        console.error('pdf render failed', error);
        res.status(500).json({error: 'render_failed', detail: String(error)});
    } finally {
        if (page) {
            try {
                await page.close();
            } catch (error) {
                console.warn('failed to close page', error?.message);
            }
        }
    }
});

process.on('SIGTERM', async () => {
    if (browser) {
        try {
            await browser.close();
        } catch (error) {
            console.error('failed to close browser', error);
        }
    }
    process.exit(0);
});

const port = process.env.PORT || 3000;
app.listen(port, () => {
    console.log('pdf-renderer listening on', port);
});

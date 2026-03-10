import express from 'express';
import fs from 'node:fs';
import puppeteer from 'puppeteer';

const app = express();
app.use(express.json({limit: '20mb'}));

let browser;
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
        const launchOptions = {args: launchArgs};
        const executablePath = resolveExecutablePath();

        if (executablePath) {
            launchOptions.executablePath = executablePath;
        }

        browser = await puppeteer.launch(launchOptions);
    }
    return browser;
}

app.get('/healthz', (_req, res) => {
    res.json({ok: true});
});

app.post('/pdf', async (req, res) => {
    const {html, url, waitFor = 'networkidle', format = 'A4', margin, orientation, landscape} = req.body || {};

    const expectedToken = process.env.PDF_TOKEN;

    if (expectedToken) {
        const provided = req.get('x-pdf-token') || req.get('authorization') || '';
        const normalised = provided.startsWith('Bearer ') ? provided.slice(7) : provided;

        if (normalised !== expectedToken) {
            return res.status(401).json({error: 'unauthorized'});
        }
    }

    if (!html && !url) {
        return res.status(400).json({error: 'Provide html or url'});
    }

    let page;

    try {
        const instance = await getBrowser();
        page = await instance.newPage();
        await page.emulateMediaType('screen');

        const landscapeFlag = typeof landscape === 'boolean' ? landscape : orientation === 'landscape';

        if (url) {
            await page.goto(url, {waitUntil: 'networkidle0', timeout: 30000});
        } else {
            await page.setContent(html, {waitUntil: 'networkidle0', timeout: 30000});
        }

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

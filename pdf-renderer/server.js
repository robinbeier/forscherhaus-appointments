import express from 'express';
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

async function getBrowser() {
    if (!browser) {
        browser = await puppeteer.launch({args: launchArgs});
    }
    return browser;
}

app.get('/healthz', (_req, res) => {
    res.json({ok: true});
});

app.post('/pdf', async (req, res) => {
    const {html, url, waitFor = 'networkidle', format = 'A4', margin} = req.body || {};

    if (!html && !url) {
        return res.status(400).json({error: 'Provide html or url'});
    }

    try {
        const instance = await getBrowser();
        const page = await instance.newPage();
        await page.emulateMediaType('screen');

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
            printBackground: true,
            preferCSSPageSize: true,
            margin: margin || {top: '12mm', right: '12mm', bottom: '14mm', left: '12mm'},
        });

        await page.close();

        res.type('application/pdf').send(pdf);
    } catch (error) {
        console.error('pdf render failed', error);
        res.status(500).json({error: 'render_failed', detail: String(error)});
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

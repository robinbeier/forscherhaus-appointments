import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import http from 'node:http';
import test from 'node:test';

async function reservePort() {
    const server = http.createServer();
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const address = server.address();
    const port = address.port;
    await new Promise((resolve) => server.close(resolve));

    return port;
}

async function waitForRenderer(child, rendererPort) {
    const deadline = Date.now() + 15000;

    while (Date.now() < deadline) {
        if (child.exitCode !== null) {
            throw new Error(`renderer exited before becoming ready (code ${child.exitCode})`);
        }

        try {
            const response = await fetch(`http://127.0.0.1:${rendererPort}/healthz`);
            if (response.ok) {
                return;
            }
        } catch {
            // Renderer startup is still in progress.
        }

        await new Promise((resolve) => setTimeout(resolve, 100));
    }

    throw new Error('renderer did not become ready');
}

async function startRenderer() {
    const port = await reservePort();
    const child = spawn(process.execPath, ['server.js'], {
        cwd: new URL('.', import.meta.url),
        env: {
            ...process.env,
            PORT: String(port),
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    let output = '';
    child.stdout.on('data', (chunk) => {
        output += chunk;
    });
    child.stderr.on('data', (chunk) => {
        output += chunk;
    });
    await waitForRenderer(child, port);

    return {
        child,
        output: () => output,
        port,
    };
}

async function stopRenderer(child) {
    if (child.exitCode !== null) {
        return;
    }

    child.kill('SIGTERM');
    await new Promise((resolve) => child.once('exit', resolve));
}

test('renders HTML when one asset request never becomes idle', {timeout: 45000}, async () => {
    const stalledAssetPort = await reservePort();
    const stalledAssetServer = http.createServer(() => {
        // Keep one request open to reproduce the production networkidle0 stall.
    });
    await new Promise((resolve) => stalledAssetServer.listen(stalledAssetPort, '127.0.0.1', resolve));
    const renderer = await startRenderer();

    try {
        const startedAt = Date.now();
        const response = await fetch(`http://127.0.0.1:${renderer.port}/pdf`, {
            method: 'POST',
            headers: {'content-type': 'application/json'},
            body: JSON.stringify({
                html: `<html><body><h1>Ready</h1><img src="http://127.0.0.1:${stalledAssetPort}/never-finishes"></body></html>`,
            }),
            signal: AbortSignal.timeout(40000),
        });
        const body = Buffer.from(await response.arrayBuffer());
        const durationMs = Date.now() - startedAt;

        assert.equal(response.status, 200, renderer.output());
        assert.equal(body.subarray(0, 5).toString(), '%PDF-');
        assert.ok(durationMs < 10000, `render took ${durationMs}ms`);
    } finally {
        await stopRenderer(renderer.child);
        await new Promise((resolve) => stalledAssetServer.close(resolve));
    }
});

test('renders a 60-page combined report with one shared logo payload', {timeout: 45000}, async () => {
    const renderer = await startRenderer();
    const logoPng = Buffer.concat([
        Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'base64',
        ),
        Buffer.alloc(250000),
    ]);
    const logoDataUrl = `data:image/png;base64,${logoPng.toString('base64')}`;
    const pages = Array.from({length: 60}, (_, pageIndex) => {
        const rows = Array.from(
            {length: 12},
            (_, rowIndex) =>
                `<tr><td>Synthetic ${pageIndex + 1}-${rowIndex + 1}</td><td>27.07.2026</td><td>08:00</td></tr>`,
        ).join('');

        return `<section class="page"><svg class="logo" viewBox="0 0 1 1"><use href="#logo-image"></use></svg><h1>Synthetic Teacher ${
            pageIndex + 1
        }</h1><table>${rows}</table></section>`;
    }).join('');
    const html = `<!doctype html><html><head><style>
        @page{size:A4;margin:12mm}
        .page{break-after:page;min-height:250mm}
        .logo{width:120px;height:64px}
        table{width:100%;border-collapse:collapse}
        td{padding:4px;border-bottom:1px solid #ddd}
    </style></head><body><svg aria-hidden="true" width="0" height="0" style="position:absolute">
        <defs><image id="logo-image" href="${logoDataUrl}" width="1" height="1"/></defs>
    </svg>${pages}<script>window.chartsReady=true;</script></body></html>`;
    const payload = JSON.stringify({html, waitFor: 'chartsReady'});

    try {
        assert.ok(Buffer.byteLength(payload) < 2 * 1024 * 1024, 'synthetic renderer payload exceeded 2 MiB');

        const startedAt = Date.now();
        const response = await fetch(`http://127.0.0.1:${renderer.port}/pdf`, {
            method: 'POST',
            headers: {'content-type': 'application/json'},
            body: payload,
            signal: AbortSignal.timeout(40000),
        });
        const body = Buffer.from(await response.arrayBuffer());
        const durationMs = Date.now() - startedAt;

        assert.equal(response.status, 200, renderer.output());
        assert.equal(body.subarray(0, 5).toString(), '%PDF-');
        assert.ok(body.includes(Buffer.from('/Subtype /Image')), 'rendered PDF did not contain the shared logo image');
        assert.ok(durationMs < 20000, `60-page render took ${durationMs}ms`);
    } finally {
        await stopRenderer(renderer.child);
    }
});

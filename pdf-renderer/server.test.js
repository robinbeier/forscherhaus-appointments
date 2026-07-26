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

test('renders HTML when one asset request never becomes idle', {timeout: 45000}, async () => {
    const rendererPort = await reservePort();
    const stalledAssetPort = await reservePort();
    const stalledAssetServer = http.createServer(() => {
        // Keep one request open to reproduce the production networkidle0 stall.
    });
    await new Promise((resolve) => stalledAssetServer.listen(stalledAssetPort, '127.0.0.1', resolve));

    const renderer = spawn(process.execPath, ['server.js'], {
        cwd: new URL('.', import.meta.url),
        env: {
            ...process.env,
            PORT: String(rendererPort),
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    let rendererOutput = '';
    renderer.stdout.on('data', (chunk) => {
        rendererOutput += chunk;
    });
    renderer.stderr.on('data', (chunk) => {
        rendererOutput += chunk;
    });

    try {
        await waitForRenderer(renderer, rendererPort);
        const startedAt = Date.now();
        const response = await fetch(`http://127.0.0.1:${rendererPort}/pdf`, {
            method: 'POST',
            headers: {'content-type': 'application/json'},
            body: JSON.stringify({
                html: `<html><body><h1>Ready</h1><img src="http://127.0.0.1:${stalledAssetPort}/never-finishes"></body></html>`,
            }),
            signal: AbortSignal.timeout(40000),
        });
        const body = Buffer.from(await response.arrayBuffer());
        const durationMs = Date.now() - startedAt;

        assert.equal(response.status, 200, rendererOutput);
        assert.equal(body.subarray(0, 5).toString(), '%PDF-');
        assert.ok(durationMs < 10000, `render took ${durationMs}ms`);
    } finally {
        renderer.kill('SIGTERM');
        await new Promise((resolve) => renderer.once('exit', resolve));
        await new Promise((resolve) => stalledAssetServer.close(resolve));
    }
});

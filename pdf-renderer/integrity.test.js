import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {createServer} from 'node:http';
import {mkdtemp, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {install, Browser, BrowserPlatform} from '@puppeteer/browsers';
import test from 'node:test';
import {CHROME_BUILD_ID, CHROME_ARCHIVE_SHA256, CHROME_PLATFORM, assertRendererTarget, buildInstallOptions} from './install-chrome.mjs';

test('fails closed when the downloaded Chrome archive hash does not match', async () => {
    const archive = Buffer.from('not-the-pinned-chrome-archive');
    const server = createServer((_request, response) => {
        response.writeHead(200, {'content-type': 'application/zip', 'content-length': archive.length});
        response.end(archive);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const address = server.address();
    const cacheDir = await mkdtemp(join(tmpdir(), 'renderer-integrity-'));

    try {
        await assert.rejects(
            install({
                browser: Browser.CHROME,
                buildId: CHROME_BUILD_ID,
                cacheDir,
                platform: BrowserPlatform.LINUX,
                baseUrl: `http://127.0.0.1:${address.port}`,
                expectedHash: CHROME_ARCHIVE_SHA256,
            }),
            /Integrity check failed.*Expected:/s,
        );
    } finally {
        await rm(cacheDir, {recursive: true, force: true});
        await new Promise((resolve) => server.close(resolve));
    }
});

test('fails closed for unsupported host targets', () => {
    assert.throws(() => assertRendererTarget({platform: 'darwin', arch: 'arm64'}), /unsupported_renderer_platform/);
    assert.throws(() => assertRendererTarget({platform: 'linux', arch: 'arm64'}), /unsupported_renderer_platform/);
});

test('binds the wrapper to the reviewed Chrome archive and installer options', () => {
    assert.deepEqual(buildInstallOptions({cacheDir: '/tmp/renderer-cache', runtimePlatform: 'linux', runtimeArch: 'x64'}), {
        browser: Browser.CHROME,
        buildId: CHROME_BUILD_ID,
        cacheDir: '/tmp/renderer-cache',
        platform: CHROME_PLATFORM,
        expectedHash: CHROME_ARCHIVE_SHA256,
        installDeps: true,
    });
});

test('fails before network access when the cache directory is empty', async () => {
    const result = await new Promise((resolve) => execFile(process.execPath, ['install-chrome.mjs'], {
        cwd: new URL('.', import.meta.url),
        env: {...process.env, PUPPETEER_CACHE_DIR: ''},
    }, (error, stdout, stderr) => resolve({error, stdout, stderr})));

    assert.notEqual(result.error, null);
    assert.match(result.stderr, /puppeteer_cache_dir_not_configured/);
});

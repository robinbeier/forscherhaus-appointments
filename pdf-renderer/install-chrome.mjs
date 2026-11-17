import process from 'node:process';
import {Browser, BrowserPlatform, install} from '@puppeteer/browsers';
import {PUPPETEER_REVISIONS} from 'puppeteer-core/internal/revisions.js';

export const CHROME_BUILD_ID = '155.0.8059.39';
export const CHROME_ARCHIVE_SHA256 = '55672d1f392fd3e7b7a08621b6e804e6bcb39d40cf155504abb74b3a021ea8ea';
export const CHROME_PLATFORM = BrowserPlatform.LINUX;
export const CHROME_ARCHIVE_URL = `https://storage.googleapis.com/chrome-for-testing-public/${CHROME_BUILD_ID}/linux64/chrome-linux64.zip`;

export function assertRendererTarget({platform = process.platform, arch = process.arch} = {}) {
    if (platform !== 'linux' || arch !== 'x64') {
        throw new Error(`unsupported_renderer_platform:${platform}/${arch}; expected linux/x64`);
    }

    if (PUPPETEER_REVISIONS.chrome !== CHROME_BUILD_ID) {
        throw new Error(`puppeteer_chrome_revision_mismatch:${PUPPETEER_REVISIONS.chrome}; expected ${CHROME_BUILD_ID}`);
    }
}

export function assertSupportedRenderer() {
    assertRendererTarget();
}

export async function installPinnedChrome(options = {}) {
    return install(buildInstallOptions(options));
}

export function buildInstallOptions({cacheDir = process.env.PUPPETEER_CACHE_DIR, runtimePlatform = process.platform, runtimeArch = process.arch} = {}) {
    if (!cacheDir) {
        throw new Error('puppeteer_cache_dir_not_configured');
    }
    assertRendererTarget({platform: runtimePlatform, arch: runtimeArch});

    return {
        browser: Browser.CHROME,
        buildId: CHROME_BUILD_ID,
        cacheDir,
        platform: CHROME_PLATFORM,
        expectedHash: CHROME_ARCHIVE_SHA256,
        installDeps: true,
    };
}

if (import.meta.url === `file://${process.argv[1]}`) {
    await installPinnedChrome();
}

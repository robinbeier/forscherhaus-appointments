const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

const repositoryRoot = path.resolve(__dirname, '../..');
const runnerPath = path.join(repositoryRoot, 'scripts/ci/dashboard_summary_browser.js');

const runRunner = (snippet, options = {}, failure = '') => {
    const tempDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'dashboard-browser-runner-'));
    const moduleDirectory = path.join(tempDirectory, 'node_modules', 'playwright');
    const recordPath = path.join(tempDirectory, 'record.json');
    fs.mkdirSync(moduleDirectory, {recursive: true});
    const isolatedRunnerPath = path.join(tempDirectory, 'dashboard_summary_browser.js');
    fs.copyFileSync(runnerPath, isolatedRunnerPath);
    fs.writeFileSync(
        path.join(moduleDirectory, 'index.js'),
        `
    const fs = require('node:fs');
    const recordPath = process.env.PW_TEST_RECORD;
    const launch = (kind) => async (options) => {
      fs.writeFileSync(recordPath, JSON.stringify({ kind, launch: options, closed: false }));
      if (process.env.PW_TEST_FAILURE === "launch") throw new Error("synthetic launch failure");
      return {
        newContext: async () => ({ newPage: async () => ({}) }),
        close: async () => {
          if (process.env.PW_TEST_FAILURE === "close") throw new Error("synthetic close failure");
          const record = JSON.parse(fs.readFileSync(recordPath, 'utf8'));
          record.closed = true;
          fs.writeFileSync(recordPath, JSON.stringify(record));
        },
      };
    };
    module.exports = {
      chromium: { launch: launch("chromium") },
      firefox: { launch: launch("firefox") },
      webkit: { launch: launch("webkit") },
    };
  `,
    );

    try {
        const result = spawnSync(process.execPath, [isolatedRunnerPath], {
            cwd: repositoryRoot,
            input: JSON.stringify({
                snippet,
                browser: 'chromium',
                executable_path: '/usr/bin/google-chrome',
                ...options,
            }),
            env: {
                ...process.env,
                PW_TEST_RECORD: recordPath,
                PW_TEST_FAILURE: failure,
            },
            encoding: 'utf8',
            timeout: 5000,
        });

        return {result, record: JSON.parse(fs.readFileSync(recordPath, 'utf8'))};
    } finally {
        fs.rmSync(tempDirectory, {recursive: true, force: true});
    }
};

test('launches configured browser and closes it after a successful snippet', () => {
    const {result, record} = runRunner(
        `async (page) => { console.log('__DASHBOARD_SUMMARY_BROWSER_CHECK__{"ok":true}'); }`,
    );

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /__DASHBOARD_SUMMARY_BROWSER_CHECK__/);
    assert.equal(record.launch.headless, true);
    assert.equal(record.launch.executablePath, '/usr/bin/google-chrome');
    assert.equal(record.launch.timeout, 30000);
    assert.equal(record.closed, true);
});

test('propagates snippet failure and closes the browser', () => {
    const {result, record} = runRunner('async () => { throw new Error("synthetic browser failure"); }');

    assert.equal(result.status, 1);
    assert.match(result.stderr, /synthetic browser failure/);
    assert.equal(record.closed, true);
});

for (const browser of ['firefox', 'webkit', 'chrome', 'msedge']) {
    test(`preserves ${browser} selection, headed mode and launch timeout`, () => {
        const {result, record} = runRunner('async () => {}', {
            browser,
            executable_path: '',
            headed: true,
            launch_timeout: 7,
        });
        assert.equal(result.status, 0, result.stderr);
        assert.equal(record.kind, ['chrome', 'msedge'].includes(browser) ? 'chromium' : browser);
        assert.equal(record.launch.headless, false);
        assert.equal(record.launch.timeout, 7000);
        assert.equal(record.launch.channel, ['chrome', 'msedge'].includes(browser) ? browser : undefined);
        assert.equal(record.closed, true);
    });
}

test('launch and close failures cannot become successful checks', () => {
    for (const stage of ['launch', 'close']) {
        const {result} = runRunner('async () => {}', {}, stage);
        assert.equal(result.status, 1);
        assert.match(result.stderr, new RegExp(`synthetic ${stage} failure`));
    }
});

test('SIGTERM during a check closes the browser and stays nonzero', () => {
    const {result, record} = runRunner(
        'async () => { process.kill(process.pid, "SIGTERM"); await new Promise(resolve => setTimeout(resolve, 2000)); }',
    );
    assert.equal(result.status, 143, result.stderr);
    assert.equal(record.closed, true);
});

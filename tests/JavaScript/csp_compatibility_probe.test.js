const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const Module = require('node:module');
const {spawnSync} = require('node:child_process');

const probe = require('../../scripts/ci/csp_compatibility_probe.js');

test('accepts loopback HTTP targets and rejects production or non-HTTP targets', () => {
    assert.equal(probe.validateLocalTarget('http://127.0.0.1:8080/booking').hostname, '127.0.0.1');
    assert.equal(probe.validateLocalTarget('http://localhost:8080/').hostname, 'localhost');
    assert.equal(probe.validateLocalTarget('http://[::1]:8080/').hostname, '[::1]');
    assert.throws(() => probe.validateLocalTarget('https://dasforscherhaus-leg.de/'), /loopback HTTP/);
    assert.throws(() => probe.validateLocalTarget('http://192.0.2.10/'), /loopback HTTP/);
});

test('sanitizes policy violations into fixed classes without raw locations', () => {
    const raw = 'https://example.test/booking/capability?token=secret';
    const violation = probe.sanitizeViolation(
        {effectiveDirective: 'script-src', blockedURI: raw, sourceFile: raw, lineNumber: 12},
        'booking',
        'http://127.0.0.1:8080',
    );
    assert.deepEqual(violation, {
        surface: 'booking',
        directive: 'script-src',
        blocked_origin: 'unknown-external',
        disposition: 'report',
    });
    assert.equal(JSON.stringify(violation).includes(raw), false);
    assert.equal(probe.blockedOriginClass('http://[::1]:8080/private', 'http://[::1]:8080'), 'self');
    assert.equal(
        probe.blockedOriginClass('http://localhost.evil.invalid/private', 'http://localhost'),
        'unknown-external',
    );
    assert.equal(
        probe.blockedOriginClass('https://www.google-analytics.com.evil.invalid/private', 'http://localhost'),
        'unknown-external',
    );
    assert.equal(
        probe.blockedOriginClass('https://www.google-analytics.com/collect', 'http://localhost'),
        'google-analytics',
    );
});

test('receipt contains only aggregate classes and explicit non-production markers', () => {
    const raw = 'https://example.test/booking/capability?token=secret';
    const receipt = probe.makeReceipt(
        'booking',
        [probe.sanitizeViolation({effectiveDirective: 'script-src', blockedURI: raw}, 'booking', 'http://localhost')],
        true,
    );
    const serialized = JSON.stringify(receipt);
    assert.equal(receipt.schema, 'csp_compatibility_probe.v1');
    assert.equal(receipt.raw_reports_persisted, false);
    assert.equal(receipt.production_changed, false);
    assert.equal(receipt.violation_count, 1);
    assert.equal(receipt.blocked_request_count, 0);
    assert.equal(serialized.includes(raw), false);
    assert.equal(serialized.includes('token=secret'), false);
});

test('classifies loopback, browser-local, and external requests without retaining URLs', () => {
    assert.equal(probe.requestClass('http://127.0.0.1:8080/app.js', 'http://127.0.0.1:8080'), 'loopback');
    assert.equal(probe.requestClass('http://[::1]:8080/app.js', 'http://[::1]:8080'), 'loopback');
    assert.equal(probe.requestClass('http://127.0.0.1:8081/app.js', 'http://127.0.0.1:8080'), 'external');
    assert.equal(probe.requestClass('data:text/plain,local'), 'browser-local');
    assert.equal(probe.requestClass('https://external.test/private?token=secret'), 'external');
    assert.equal(probe.webSocketClass('ws://127.0.0.1:8080/socket', 'http://127.0.0.1:8080'), 'loopback');
    assert.equal(probe.webSocketClass('ws://127.0.0.1:8081/socket', 'http://127.0.0.1:8080'), 'external');
    assert.equal(probe.webSocketClass('wss://external.test/private?token=secret', 'http://127.0.0.1:8080'), 'external');
});

test('bounds the post-load observation window', () => {
    assert.equal(probe.boundedObservationMs(undefined), 500);
    assert.equal(probe.boundedObservationMs(0), 0);
    assert.equal(probe.boundedObservationMs(5000), 5000);
    assert.throws(() => probe.boundedObservationMs(-1), /Invalid/);
    assert.throws(() => probe.boundedObservationMs(5001), /Invalid/);
});

test('unknown surfaces fail closed and element directives normalize to policy classes', () => {
    const violation = probe.sanitizeViolation(
        {effectiveDirective: 'script-src-elem', blockedURI: 'inline'},
        'unrecognized',
    );
    assert.deepEqual(violation, {
        surface: 'unknown',
        directive: 'script-src',
        blocked_origin: 'inline',
        disposition: 'report',
    });
});

test('runProbe rejects an unknown surface before loading a browser', async () => {
    const originalLoad = Module._load;
    let browserLoaded = false;
    Module._load = (request, parent, isMain) => {
        if (request === 'playwright') {
            browserLoaded = true;
        }
        return originalLoad(request, parent, isMain);
    };
    try {
        await assert.rejects(
            probe.runProbe({url: 'http://127.0.0.1:8080/booking', surface: 'unrecognized'}),
            /supported surface/,
        );
        assert.equal(browserLoaded, false);
    } finally {
        Module._load = originalLoad;
    }
});

test('CLI emits a closed failure receipt without echoing a secret-bearing target', () => {
    const rawTarget = 'http://127.0.0.1:8080/booking/capability?token=secret-value';
    const result = spawnSync(process.execPath, [path.join(__dirname, '../../scripts/ci/csp_compatibility_probe.js')], {
        input: JSON.stringify({url: rawTarget, surface: 'booking', browser: 'unsupported'}),
        encoding: 'utf8',
        env: process.env,
    });
    assert.equal(result.status, 1);
    assert.equal(result.stderr, '');
    assert.equal(result.stdout.trim().split('\n').length, 1);
    const receipt = JSON.parse(result.stdout);
    assert.equal(receipt.outcome, 'environment_failed');
    assert.equal(receipt.error_class, 'probe_failed');
    assert.equal(result.stdout.includes(rawTarget), false);
    assert.equal(result.stdout.includes('secret-value'), false);
});

test('runProbe injects the candidate header, sanitizes browser violations, and closes the browser', () => {
    const tempDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'csp-probe-playwright-'));
    const moduleDirectory = path.join(tempDirectory, 'node_modules', 'playwright');
    const recordPath = path.join(tempDirectory, 'record.json');
    fs.mkdirSync(moduleDirectory, {recursive: true});
    fs.writeFileSync(
        path.join(moduleDirectory, 'index.js'),
        `const fs = require('node:fs');
const recordPath = process.env.CSP_PROBE_RECORD;
const record = {header: null, closed: false, launched: false, externalFetched: false, externalAborted: false, redirectLimit: null, serviceWorkers: null, socketClosed: false, socketConnected: false, waited: null, pageClosed: false};
const save = () => fs.writeFileSync(recordPath, JSON.stringify(record));
const browser = {
  newContext: async (options) => {
    record.serviceWorkers = options.serviceWorkers;
    const context = {
      addInitScript: async () => {},
      route: async (_pattern, handler) => { context.routeHandler = handler; },
      routeWebSocket: async (_pattern, handler) => { context.socketHandler = handler; },
      newPage: async () => ({
        goto: async () => {
          const response = { ok: () => true, status: () => 200, headers: () => ({'content-type': 'text/html'} ) };
          await context.routeHandler({
            request: () => ({url: () => 'https://external.test/private?token=secret'}),
            fetch: async () => { record.externalFetched = true; save(); return response; },
            abort: async () => { record.externalAborted = true; save(); },
          });
          await context.routeHandler({
            request: () => ({url: () => 'http://127.0.0.1:8080/app.js'}),
            fetch: async (options) => { record.redirectLimit = options.maxRedirects; save(); return response; },
            fulfill: async ({headers}) => { record.header = headers['content-security-policy-report-only']; save(); },
          });
          await context.socketHandler({
            url: () => 'wss://external.test/private?token=secret',
            close: async () => { record.socketClosed = true; save(); },
            connectToServer: () => { record.socketConnected = true; save(); },
          });
          return response;
        },
        waitForTimeout: async (value) => { record.waited = value; save(); },
        evaluate: async () => [{
          effectiveDirective: 'script-src',
          blockedURI: 'https://example.test/private/path?token=secret',
          sourceFile: 'https://example.test/private/path?token=secret',
        }],
        close: async () => { record.pageClosed = true; save(); },
      }),
    };
    return context;
  },
  close: async () => { record.closed = true; save(); },
};
const chromium = {launch: async () => { record.launched = true; save(); return browser; }};
module.exports = {chromium, firefox: chromium, webkit: chromium};
`,
    );
    const fakePlaywrightPath = path.join(moduleDirectory, 'index.js');
    const runnerPath = path.join(tempDirectory, 'run.js');
    fs.writeFileSync(
        runnerPath,
        `const Module = require('node:module');
const originalLoad = Module._load;
Module._load = (request, parent, isMain) => request === 'playwright'
  ? originalLoad(${JSON.stringify(fakePlaywrightPath)}, parent, isMain)
  : originalLoad(request, parent, isMain);
const {runProbe} = require(${JSON.stringify(path.join(__dirname, '../../scripts/ci/csp_compatibility_probe.js'))});
runProbe({url: 'http://127.0.0.1:8080/booking', surface: 'booking'})
  .then((receipt) => process.stdout.write(JSON.stringify(receipt)))
  .catch((error) => { process.stderr.write(String(error)); process.exitCode = 1; });
`,
    );
    try {
        const result = spawnSync(process.execPath, [runnerPath], {
            encoding: 'utf8',
            env: {...process.env, CSP_PROBE_RECORD: recordPath},
        });
        assert.equal(result.status, 0, result.stderr);
        const receipt = JSON.parse(result.stdout);
        const record = JSON.parse(fs.readFileSync(recordPath, 'utf8'));
        assert.equal(record.launched, true);
        assert.equal(record.closed, true);
        assert.equal(record.externalFetched, false);
        assert.equal(record.externalAborted, true);
        assert.equal(record.redirectLimit, 0);
        assert.equal(record.serviceWorkers, 'block');
        assert.equal(record.socketClosed, true);
        assert.equal(record.socketConnected, false);
        assert.equal(record.waited, 500);
        assert.equal(record.pageClosed, true);
        assert.equal(record.header, probe.CANDIDATE_POLICY);
        assert.equal(receipt.violation_count, 1);
        assert.equal(receipt.blocked_request_count, 2);
        assert.deepEqual(receipt.blocked_request_classes, {external: 1, websocket_external: 1});
        assert.deepEqual(receipt.violation_classes, {'booking:script-src:unknown-external:report': 1});
        assert.equal(result.stdout.includes('external.test'), false);
        assert.equal(result.stdout.includes('private/path'), false);
        assert.equal(result.stdout.includes('secret'), false);
    } finally {
        fs.rmSync(tempDirectory, {recursive: true, force: true});
    }
});

test('route failures are caught and emitted only as a fixed failure receipt', () => {
    const tempDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'csp-probe-route-failure-'));
    const moduleDirectory = path.join(tempDirectory, 'node_modules', 'playwright');
    fs.mkdirSync(moduleDirectory, {recursive: true});
    fs.writeFileSync(
        path.join(moduleDirectory, 'index.js'),
        `const response = {ok: () => true};
const browser = {
  newContext: async () => {
    const context = {
      addInitScript: async () => {},
      route: async (_pattern, handler) => { context.routeHandler = handler; },
      routeWebSocket: async () => {},
      newPage: async () => ({
        goto: async () => {
          await context.routeHandler({
            request: () => ({url: () => 'http://127.0.0.1:8080/private?token=secret-value'}),
            fetch: async () => {
              if (process.env.CSP_PROBE_ROUTE_SCENARIO === 'redirect') {
                return {
                  ok: () => false,
                  status: () => 302,
                  headers: () => ({location: 'https://external.test/private?token=secret-value'}),
                };
              }
              throw new Error('http://127.0.0.1:8080/private?token=secret-value');
            },
            abort: async () => {},
          });
          return response;
        },
        waitForTimeout: async () => {},
        evaluate: async () => [],
        close: async () => {},
      }),
    };
    return context;
  },
  close: async () => {},
};
const chromium = {launch: async () => browser};
module.exports = {chromium, firefox: chromium, webkit: chromium};`,
    );
    const preloadPath = path.join(tempDirectory, 'preload.js');
    fs.writeFileSync(
        preloadPath,
        `const Module = require('node:module');
const originalLoad = Module._load;
Module._load = (request, parent, isMain) => request === 'playwright'
  ? originalLoad(${JSON.stringify(path.join(moduleDirectory, 'index.js'))}, parent, isMain)
  : originalLoad(request, parent, isMain);`,
    );
    try {
        const scriptPath = path.join(__dirname, '../../scripts/ci/csp_compatibility_probe.js');
        for (const scenario of ['fetch_error', 'redirect']) {
            const result = spawnSync(process.execPath, ['--require', preloadPath, scriptPath], {
                input: JSON.stringify({url: 'http://127.0.0.1:8080/private?token=secret-value', surface: 'app'}),
                encoding: 'utf8',
                env: {...process.env, CSP_PROBE_ROUTE_SCENARIO: scenario},
            });
            assert.equal(result.status, 1, scenario);
            assert.equal(result.stderr, '', scenario);
            const receipt = JSON.parse(result.stdout);
            assert.equal(receipt.error_class, 'probe_failed', scenario);
            assert.equal(result.stdout.includes('secret-value'), false, scenario);
            assert.equal(result.stdout.includes('external.test'), false, scenario);
        }
    } finally {
        fs.rmSync(tempDirectory, {recursive: true, force: true});
    }
});

test('runProbe rejects non-loopback targets before loading a browser', () => {
    const tempDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'csp-probe-target-'));
    const runnerPath = path.join(tempDirectory, 'run.js');
    fs.writeFileSync(
        runnerPath,
        `const {runProbe} = require(${JSON.stringify(path.join(__dirname, '../../scripts/ci/csp_compatibility_probe.js'))});
runProbe({url: 'https://example.test/private?token=secret', surface: 'app'})
  .then(() => process.exitCode = 1)
  .catch(() => process.stdout.write('rejected'));
`,
    );
    try {
        const result = spawnSync(process.execPath, [runnerPath], {encoding: 'utf8'});
        assert.equal(result.status, 0);
        assert.equal(result.stdout, 'rejected');
        assert.equal(result.stdout.includes('secret'), false);
    } finally {
        fs.rmSync(tempDirectory, {recursive: true, force: true});
    }
});

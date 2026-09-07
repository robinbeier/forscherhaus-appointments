const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const test = require('node:test');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const gulpCli = path.join(repositoryRoot, 'node_modules', 'gulp', 'bin', 'gulp.js');

function runGulp(task, sourcePath, source) {
    const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'gulp-build-test-'));

    try {
        fs.copyFileSync(path.join(repositoryRoot, 'gulpfile.js'), path.join(fixture, 'gulpfile.js'));
        fs.copyFileSync(path.join(repositoryRoot, 'babel.config.json'), path.join(fixture, 'babel.config.json'));
        fs.symlinkSync(path.join(repositoryRoot, 'node_modules'), path.join(fixture, 'node_modules'), 'dir');
        const sourceDirectory = path.join(fixture, path.dirname(sourcePath));
        fs.mkdirSync(sourceDirectory, {recursive: true});
        fs.writeFileSync(path.join(fixture, sourcePath), source);

        const result = spawnSync(process.execPath, [gulpCli, task], {
            cwd: fixture,
            encoding: 'utf8',
            timeout: 30_000,
        });

        return {fixture, result};
    } catch (error) {
        fs.rmSync(fixture, {recursive: true, force: true});
        throw error;
    }
}

test('scripts compiles valid JavaScript in an isolated fixture', () => {
    const {fixture, result} = runGulp('scripts', 'assets/js/valid.js', 'const answer = () => 42;\n');

    try {
        assert.equal(result.status, 0, result.stderr || result.stdout);
        assert.equal(result.error, undefined);
        assert.equal(fs.existsSync(path.join(fixture, 'assets/js/valid.min.js')), true);
    } finally {
        fs.rmSync(fixture, {recursive: true, force: true});
    }
});

test('styles compiles valid SCSS in an isolated fixture', () => {
    const {fixture, result} = runGulp('styles', 'assets/css/valid.scss', '$color: #123;\n.valid { color: $color; }\n');

    try {
        assert.equal(result.status, 0, result.stderr || result.stdout);
        assert.equal(result.error, undefined);
        assert.equal(fs.existsSync(path.join(fixture, 'assets/css/valid.min.css')), true);
    } finally {
        fs.rmSync(fixture, {recursive: true, force: true});
    }
});

test('scripts fails with a compiler diagnostic for invalid JavaScript', () => {
    const {fixture, result} = runGulp('scripts', 'assets/js/invalid.js', 'const = ;\n');

    try {
        assert.equal(result.error, undefined);
        assert.equal(result.signal, null);
        assert.ok(result.status > 0, result.stdout);
        assert.match(`${result.stdout}\n${result.stderr}`, /Unexpected token/);
    } finally {
        fs.rmSync(fixture, {recursive: true, force: true});
    }
});

test('styles fails with a compiler diagnostic for invalid SCSS', () => {
    const {fixture, result} = runGulp('styles', 'assets/css/invalid.scss', '.invalid { color: ; }\n');

    try {
        assert.equal(result.error, undefined);
        assert.equal(result.signal, null);
        assert.ok(result.status > 0, result.stdout);
        assert.match(`${result.stdout}\n${result.stderr}`, /Expected expression/);
    } finally {
        fs.rmSync(fixture, {recursive: true, force: true});
    }
});

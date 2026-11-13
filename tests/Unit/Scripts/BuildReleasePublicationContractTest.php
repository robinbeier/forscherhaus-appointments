<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BuildReleasePublicationContractTest extends TestCase
{
    private string $workspace;
    /** @var list<string> */
    private array $temporaryReleaseIds = [];

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/fh-release-prune-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->workspace, 0700));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryReleaseIds as $release) {
            @unlink('/tmp/build_ea_' . $release . '.log');
            foreach (glob('/tmp/' . $release . '.output.*') ?: [] as $outputDirectory) {
                $this->removeTree($outputDirectory);
            }
        }
        $this->removeTree($this->workspace);
    }

    public function testBuildPublishesOnlyVerifiedTemporaryArchiveAndSidecarPair(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/build_release.sh');
        self::assertIsString($script);
        $publisher = file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/publish_existing_release.sh');
        self::assertIsString($publisher);
        self::assertStringContainsString(
            './build_release.sh --expected-commit "$(git rev-parse HEAD)" --rel ea_20251005_2000',
            $script,
        );
        self::assertStringContainsString('ARCHIVE_TEMP=".${REL}.tar.gz.upload-${REMOTE_NONCE}"', $publisher);
        self::assertStringContainsString('OUTPUT="$(cd "$OUTPUT" && pwd -P)"', $script);
        self::assertStringContainsString('STAGE="$(cd "$STAGE" && pwd -P)"', $script);
        self::assertStringContainsString('PROJECT="$(pwd -P)"', $script);
        self::assertStringContainsString(
            'PROVENANCE_TEMP=".${REL}.build-provenance.json.upload-${REMOTE_NONCE}"',
            $publisher,
        );
        self::assertStringContainsString('scp -- "$ARCHIVE" "$UPLOAD:$REMOTE_DIR/$ARCHIVE_TEMP"', $publisher);
        self::assertStringContainsString('scp -- "$PROVENANCE" "$UPLOAD:$REMOTE_DIR/$PROVENANCE_TEMP"', $publisher);
        self::assertStringContainsString('/usr/bin/python3 -I -B - --prepare "$REMOTE_DIR"', $publisher);
        self::assertStringNotContainsString('/usr/bin/install -d', $publisher);
        self::assertStringContainsString('ssh "$UPLOAD" /usr/bin/chmod 0600', $publisher);
        self::assertStringContainsString('[[ "$UPLOAD" =~ ^root@[A-Za-z0-9.-]+$ ]]', $publisher);
        self::assertStringContainsString('[[ "$REMOTE_DIR" == "/root/releases" ]]', $publisher);
        self::assertStringContainsString(
            '"$REMOTE_DIR" "$REL" "$REMOTE_NONCE" "$ARCHIVE_SHA" "$ARCHIVE_SIZE"',
            $publisher,
        );
        self::assertStringContainsString(
            '[[ "$PUBLISH_STATUS" =~ ^(published|attached):(published|attached)$ ]]',
            $publisher,
        );
        self::assertStringContainsString('trap remote_cleanup EXIT', $publisher);
        self::assertStringContainsString('< "$PROJECT/scripts/ops/libexec/publish_release_pair_v1.py"', $publisher);
        self::assertStringContainsString('bash "$PROJECT/scripts/ops/publish_existing_release.sh"', $script);
        self::assertStringContainsString('--expected-archive-sha256 "$LOCAL_SHA"', $script);
        self::assertStringContainsString('--expected-provenance-sha256 "$PROVENANCE_SHA"', $script);
        self::assertStringContainsString(
            '[[ "$ARCHIVE_SHA" == "$EXPECTED_ARCHIVE_SHA" && "$PROVENANCE_SHA" == "$EXPECTED_PROVENANCE_SHA" ]]',
            $publisher,
        );
        self::assertStringContainsString('--verify-only) VERIFY_ONLY=1', $publisher);
        self::assertStringNotContainsString('npm run build', $publisher);
        self::assertStringNotContainsString('composer install', $publisher);
        self::assertStringNotContainsString('WARNUNG: Remote-Checksumme', $script);
        self::assertStringNotContainsString("scp '\$ARCHIVE' '\${UPLOAD}':'\$REMOTE_DIR/'", $script);
        self::assertStringNotContainsString('-mindepth', $script);
        self::assertStringNotContainsString('-maxdepth', $script);
        self::assertStringContainsString('shopt -s nullglob dotglob', $script);
        self::assertStringContainsString('for child in "$directory"/*', $script);
        self::assertStringContainsString('base="${child##*/}"', $script);
        self::assertStringContainsString('rm -rf -- "$child"', $script);
        self::assertStringContainsString(
            'prune_children_except "$STAGE/docker" \'compose.zero-surprise.yml\' \'php-fpm\' \'nginx\'',
            $script,
        );
        self::assertStringContainsString('prune_children_except "$STAGE/docker/nginx" \'nginx.conf\'', $script);
        self::assertStringContainsString(
            'php "$STAGE/scripts/release-gate/validate_release_artifact.php" \\' .
                "\n" .
                '    --root="$STAGE" --print-generated-runtime-paths > "$GENERATED_ASSET_LIST"',
            $script,
        );
        self::assertStringContainsString('assets/css/*.css|assets/js/*.min.js|assets/vendor/*', $script);
        self::assertStringContainsString('^assets/[A-Za-z0-9.@_/-]+$', $script);
        self::assertStringContainsString('/usr/bin/install -m 0644 "$ASSET_SOURCE" "$ASSET_TARGET"', $script);
        self::assertStringContainsString('FRONTEND_BUILD="$(mktemp -d "/tmp/${REL}.frontend.XXXXXX")"', $script);
        self::assertStringContainsString(
            'git archive --format=tar "$EXPECTED_COMMIT" | tar -xf - -C "$FRONTEND_BUILD"',
            $script,
        );
        self::assertStringContainsString(
            'npm ci --ignore-scripts --no-audit --no-fund --prefix "$FRONTEND_BUILD"',
            $script,
        );
        self::assertStringContainsString('./node_modules/.bin/gulp build', $script);
        self::assertStringNotContainsString('npm run build', $script);
        self::assertStringContainsString('ASSET_SOURCE="$FRONTEND_BUILD/$asset_path"', $script);
        self::assertStringContainsString('[[ -f "$ASSET_SOURCE" && ! -L "$ASSET_SOURCE" ]]', $script);
        self::assertStringContainsString('[[ "$GENERATED_ASSET_COUNT" -gt 0 ]]', $script);
        self::assertStringNotContainsString('cp -R assets', $script);
    }

    public function testTarMetadataFlagsUseOptionAcceptanceNotHelpText(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/build_release.sh');
        self::assertIsString($script);
        self::assertStringContainsString('tar --no-mac-metadata --version', $script);
        self::assertStringContainsString('tar --no-xattrs --version', $script);
        self::assertStringContainsString('tar "${TAR_METADATA_ARGS[@]}" -C "$STAGE" -czf "$ARCHIVE" .', $script);
        self::assertStringNotContainsString('TAR_HELP="$(tar --help', $script);

        $probe = <<<'SH'
        #!/usr/bin/env bash
        set -euo pipefail
        TAR_METADATA_ARGS=()
        if tar --no-mac-metadata --version >/dev/null 2>&1; then
          TAR_METADATA_ARGS+=(--no-mac-metadata)
        fi
        if tar --no-xattrs --version >/dev/null 2>&1; then
          TAR_METADATA_ARGS+=(--no-xattrs)
        fi
        tar "${TAR_METADATA_ARGS[@]}" -C /tmp -czf /tmp/release-test.tar.gz .
        SH;
        $tar = <<<'SH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "$*" >>"${FAKE_TAR_LOG:?}"
        if [[ "$1" == '--no-mac-metadata' || "$1" == '--no-xattrs' ]]; then
          [[ "${FAKE_TAR_MODE:-gnu}" == 'bsdtar' ]] && exit 0
          exit 2
        fi
        exit 0
        SH;
        $probePath = $this->workspace . '/tar-probe.sh';
        $tarPath = $this->workspace . '/tar';
        $this->writeExecutable($probePath, $probe);
        $this->writeExecutable($tarPath, $tar);
        $logPath = $this->workspace . '/tar.log';

        $gnu = $this->runCommandOutput(
            'PATH=' .
                escapeshellarg($this->workspace . ':' . (getenv('PATH') ?: '')) .
                ' FAKE_TAR_MODE=gnu FAKE_TAR_LOG=' .
                escapeshellarg($logPath) .
                ' bash ' .
                escapeshellarg($probePath),
        );
        self::assertSame('', $gnu);
        $gnuCalls = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($gnuCalls);
        $gnuArchiveCall = end($gnuCalls);
        self::assertIsString($gnuArchiveCall);
        self::assertStringContainsString('-czf /tmp/release-test.tar.gz .', $gnuArchiveCall);
        self::assertStringNotContainsString('--no-mac-metadata', $gnuArchiveCall);
        self::assertStringNotContainsString('--no-xattrs', $gnuArchiveCall);

        $bsd = $this->runCommandOutput(
            'PATH=' .
                escapeshellarg($this->workspace . ':' . (getenv('PATH') ?: '')) .
                ' FAKE_TAR_MODE=bsdtar FAKE_TAR_LOG=' .
                escapeshellarg($logPath) .
                ' bash ' .
                escapeshellarg($probePath),
        );
        self::assertSame('', $bsd);
        $bsdCalls = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($bsdCalls);
        $bsdArchiveCall = end($bsdCalls);
        self::assertIsString($bsdArchiveCall);
        self::assertStringContainsString(
            '--no-mac-metadata --no-xattrs -C /tmp -czf /tmp/release-test.tar.gz .',
            $bsdArchiveCall,
        );
    }

    public function testStagingPruneRemovesDocumentationAndKeepsRuntimeFiles(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/build_release.sh');
        self::assertIsString($script);
        preg_match_all('/^\s+(?:rm -rf|rm -f)(?: --)? "\$STAGE\/[^\n]+$/m', $script, $matches);
        $pruneCommands = array_values(
            array_filter(
                $matches[0] ?? [],
                static fn(string $command): bool => str_contains($command, '"$STAGE/storage"'),
            ),
        );
        self::assertCount(1, $pruneCommands, 'Expected the staging cleanup command in build_release.sh.');
        self::assertStringContainsString('"$STAGE/docs"', $pruneCommands[0]);

        $stage = $this->workspace . '/stage';
        $source = $this->workspace . '/source';
        self::assertTrue(mkdir($stage . '/docs/ops', 0777, true));
        self::assertTrue(mkdir($stage . '/docs/security', 0777, true));
        self::assertTrue(mkdir($stage . '/docs/unitmetadata', 0777, true));
        self::assertTrue(mkdir($stage . '/application', 0777, true));
        self::assertTrue(mkdir($stage . '/scripts', 0777, true));
        self::assertTrue(mkdir($source . '/docs/ops', 0777, true));
        self::assertNotFalse(file_put_contents($stage . '/docs/ops/marker.md', 'staged docs'));
        self::assertNotFalse(file_put_contents($stage . '/docs/security/marker.md', 'staged docs'));
        self::assertNotFalse(file_put_contents($stage . '/docs/unitmetadata/marker.json', '{}'));
        self::assertNotFalse(file_put_contents($stage . '/application/runtime.php', '<?php'));
        self::assertNotFalse(file_put_contents($stage . '/scripts/runtime.sh', '#!/bin/sh'));
        self::assertNotFalse(file_put_contents($source . '/docs/ops/marker.md', 'source docs'));

        $runner = $this->workspace . '/prune.sh';
        $runnerBody = "#!/usr/bin/env bash\nset -euo pipefail\nSTAGE=" . escapeshellarg($stage) . "\n";
        $runnerBody .= $pruneCommands[0] . "\n";
        self::assertNotFalse(file_put_contents($runner, $runnerBody));
        self::assertTrue(chmod($runner, 0700));
        self::assertSame(0, $this->runCommand('bash ' . escapeshellarg($runner)));

        self::assertDirectoryDoesNotExist($stage . '/docs');
        self::assertFileExists($stage . '/application/runtime.php');
        self::assertFileExists($stage . '/scripts/runtime.sh');
        self::assertSame('source docs', file_get_contents($source . '/docs/ops/marker.md'));
    }

    public function testReleaseBuildIgnoresPoisonedWorktreeAssets(): void
    {
        $project = $this->workspace . '/isolated-build-project';
        $bin = $this->workspace . '/bin';
        self::assertTrue(mkdir($project . '/application/config', 0777, true));
        self::assertTrue(mkdir($project . '/assets/js', 0777, true));
        self::assertTrue(mkdir($project . '/scripts/release-gate', 0777, true));
        self::assertTrue(mkdir($project . '/scripts/ops', 0777, true));
        self::assertTrue(mkdir($bin, 0777, true));
        $this->copyBuildScriptForHarness($project . '/build_release.sh');
        self::assertTrue(chmod($project . '/build_release.sh', 0700));
        self::assertNotFalse(file_put_contents($project . '/application/config/config.php', '<?php'));
        self::assertNotFalse(file_put_contents($project . '/assets/js/app.js', 'tracked source'));
        self::assertNotFalse(
            file_put_contents($project . '/scripts/release-gate/validate_release_artifact.php', '<?php'),
        );
        self::assertNotFalse(file_put_contents($project . '/scripts/ops/create_release_build_provenance.php', '<?php'));
        self::assertNotFalse(file_put_contents($project . '/assets/js/app.min.js', 'poisoned ignored output'));
        self::assertTrue(mkdir($project . '/node_modules/.bin', 0777, true));
        self::assertNotFalse(file_put_contents($project . '/node_modules/.bin/gulp', 'poisoned source gulp'));
        $this->writeExecutable(
            $bin . '/npm',
            "#!/usr/bin/env bash\nset -euo pipefail\nprefix=''\nwhile [[ \$# -gt 0 ]]; do if [[ \"\$1\" == '--prefix' ]]; then prefix=\"\$2\"; shift 2; else shift; fi; done\n[[ -n \"\$prefix\" ]] || exit 1\nmkdir -p \"\$prefix/node_modules/.bin\"\nprintf '%s\\n' '#!/usr/bin/env bash' 'printf \"isolated build output\" > assets/js/app.min.js' > \"\$prefix/node_modules/.bin/gulp\"\nchmod 700 \"\$prefix/node_modules/.bin/gulp\"\n",
        );
        $this->writeExecutable($bin . '/node', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable($bin . '/composer', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable(
            $bin . '/php',
            "#!/usr/bin/env bash\nfor arg in \"\$@\"; do [[ \"\$arg\" == '--print-generated-runtime-paths' ]] && { printf '%s\\n' assets/js/app.min.js; exit 0; }; done\nexit 0\n",
        );
        $this->initializeGitFixture($project, 'build_release.sh application assets/js/app.js scripts');
        $commit = trim($this->runCommandOutput('git -C ' . escapeshellarg($project) . ' rev-parse HEAD'));
        $release = 'ea_20261113_' . bin2hex(random_bytes(4));
        $this->temporaryReleaseIds[] = $release;
        $exitCode = 0;
        $this->runCommand(
            'PATH=' .
                escapeshellarg($bin . ':' . (getenv('PATH') ?: '')) .
                ' bash ' .
                escapeshellarg($project . '/build_release.sh') .
                ' --expected-commit ' .
                escapeshellarg($commit) .
                ' --rel ' .
                escapeshellarg($release) .
                ' --project ' .
                escapeshellarg($project) .
                ' --skip-upload',
            $exitCode,
        );
        $output = (string) file_get_contents('/tmp/build_ea_' . $release . '.log');
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('[✓] Build abgeschlossen:', $output);
        preg_match('/\[✓\] Build abgeschlossen: (.+)$/m', $output, $matches);
        self::assertCount(2, $matches);
        $archive = trim($matches[1]);
        self::assertFileExists($archive);
        self::assertSame(
            'isolated build output',
            trim($this->runCommandOutput('tar -xOzf ' . escapeshellarg($archive) . ' ./assets/js/app.min.js')),
        );
        @unlink($archive);
        preg_match('/^    Provenance: (.+)$/m', $output, $provenanceMatches);
        if (isset($provenanceMatches[1])) {
            @unlink(trim($provenanceMatches[1]));
        }
        @rmdir(dirname($archive));
        @unlink('/tmp/build_ea_' . $release . '.log');
    }

    public function testReleaseBuildStopsBeforeArchiveWhenNpmCiFails(): void
    {
        $project = $this->workspace . '/failed-install-project';
        $bin = $this->workspace . '/failed-bin';
        self::assertTrue(mkdir($project . '/application/config', 0777, true));
        self::assertTrue(mkdir($project . '/assets/js', 0777, true));
        self::assertTrue(mkdir($project . '/scripts/release-gate', 0777, true));
        self::assertTrue(mkdir($project . '/scripts/ops', 0777, true));
        self::assertTrue(mkdir($bin, 0777, true));
        $this->copyBuildScriptForHarness($project . '/build_release.sh');
        self::assertTrue(chmod($project . '/build_release.sh', 0700));
        foreach (
            [
                'application/config/config.php' => '<?php',
                'assets/js/app.js' => 'tracked source',
                'scripts/release-gate/validate_release_artifact.php' => '<?php',
                'scripts/ops/create_release_build_provenance.php' => '<?php',
            ]
            as $path => $contents
        ) {
            self::assertNotFalse(file_put_contents($project . '/' . $path, $contents));
        }
        $this->writeExecutable($bin . '/npm', "#!/usr/bin/env bash\necho 'synthetic npm ci failure' >&2\nexit 42\n");
        $this->writeExecutable($bin . '/node', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable($bin . '/composer', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable($bin . '/php', "#!/usr/bin/env bash\nexit 0\n");
        $this->initializeGitFixture($project, 'build_release.sh application assets/js/app.js scripts');
        $commit = trim($this->runCommandOutput('git -C ' . escapeshellarg($project) . ' rev-parse HEAD'));
        $exitCode = 0;
        $release = 'ea_20261113_' . bin2hex(random_bytes(4));
        $this->temporaryReleaseIds[] = $release;
        $this->runCommand(
            'PATH=' .
                escapeshellarg($bin . ':' . (getenv('PATH') ?: '')) .
                ' bash ' .
                escapeshellarg($project . '/build_release.sh') .
                ' --expected-commit ' .
                escapeshellarg($commit) .
                ' --rel ' .
                escapeshellarg($release) .
                ' --project ' .
                escapeshellarg($project) .
                ' --skip-upload',
            $exitCode,
        );
        $output = (string) file_get_contents('/tmp/build_ea_' . $release . '.log');
        self::assertSame(42, $exitCode);
        self::assertStringContainsString('synthetic npm ci failure', $output);
        self::assertStringNotContainsString('[✓] Build abgeschlossen:', $output);
        foreach (glob('/tmp/' . $release . '.output.*') ?: [] as $outputDirectory) {
            self::assertSame([], glob($outputDirectory . '/*.tar.gz') ?: []);
            self::assertSame([], glob($outputDirectory . '/*.build-provenance.json') ?: []);
            rmdir($outputDirectory);
        }
        @unlink('/tmp/build_ea_' . $release . '.log');
    }

    private function writeExecutable(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0700));
    }

    private function copyBuildScriptForHarness(string $target): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/build_release.sh');
        self::assertIsString($script);
        $script = str_replace('exec > >(tee -a "$LOG") 2>&1', 'exec >"$LOG" 2>&1', $script);
        self::assertNotFalse(file_put_contents($target, $script));
    }

    private function initializeGitFixture(string $project, string $paths): void
    {
        self::assertSame(0, $this->runCommand('git init -q ' . escapeshellarg($project)));
        self::assertSame(
            0,
            $this->runCommand('git -C ' . escapeshellarg($project) . ' config user.email test@example.invalid'),
        );
        self::assertSame(0, $this->runCommand('git -C ' . escapeshellarg($project) . ' config user.name Test'));
        self::assertSame(0, $this->runCommand('git -C ' . escapeshellarg($project) . ' add ' . $paths));
        self::assertSame(0, $this->runCommand('git -C ' . escapeshellarg($project) . ' commit -qm initial'));
    }

    private function runCommand(string $command, ?int &$exitCode = null): int
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return $exitCode;
    }

    private function runCommandOutput(string $command, ?int &$exitCode = null): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return (string) $stdout . (string) $stderr;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        self::assertIsArray($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BuildReleasePublicationContractTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/fh-release-prune-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->workspace, 0700));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testBuildPublishesOnlyVerifiedTemporaryArchiveAndSidecarPair(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/build_release.sh');
        self::assertIsString($script);
        self::assertStringContainsString(
            './build_release.sh --expected-commit "$(git rev-parse HEAD)" --rel ea_20251005_2000',
            $script,
        );
        self::assertStringContainsString('ARCHIVE_TEMP=".${REL}.tar.gz.upload-${REMOTE_NONCE}"', $script);
        self::assertStringContainsString('OUTPUT="$(cd "$OUTPUT" && pwd -P)"', $script);
        self::assertStringContainsString('STAGE="$(cd "$STAGE" && pwd -P)"', $script);
        self::assertStringContainsString('PROJECT="$(pwd -P)"', $script);
        self::assertStringContainsString(
            'PROVENANCE_TEMP=".${REL}.build-provenance.json.upload-${REMOTE_NONCE}"',
            $script,
        );
        self::assertStringContainsString('scp -- "$ARCHIVE" "$UPLOAD:$REMOTE_DIR/$ARCHIVE_TEMP"', $script);
        self::assertStringContainsString('scp -- "$PROVENANCE" "$UPLOAD:$REMOTE_DIR/$PROVENANCE_TEMP"', $script);
        self::assertStringContainsString('/usr/bin/python3 -I -B - --prepare "$REMOTE_DIR"', $script);
        self::assertStringNotContainsString('/usr/bin/install -d', $script);
        self::assertStringContainsString('ssh "$UPLOAD" /usr/bin/chmod 0600', $script);
        self::assertStringContainsString('[[ "$UPLOAD" =~ ^root@[A-Za-z0-9.-]+$ ]]', $script);
        self::assertStringContainsString('[[ "$REMOTE_DIR" == "/root/releases" ]]', $script);
        self::assertStringContainsString('"$REMOTE_DIR" "$REL" "$REMOTE_NONCE" "$LOCAL_SHA" "$ARCHIVE_SIZE"', $script);
        self::assertStringContainsString(
            '[[ "$PUBLISH_STATUS" =~ ^(published|attached):(published|attached)$ ]]',
            $script,
        );
        self::assertStringContainsString("trap 'remote_cleanup; cleanup' EXIT", $script);
        self::assertStringContainsString('< scripts/ops/libexec/publish_release_pair_v1.py', $script);
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
        self::assertStringContainsString('[[ -f "$ASSET_SOURCE" && ! -L "$ASSET_SOURCE" ]]', $script);
        self::assertStringContainsString('[[ "$GENERATED_ASSET_COUNT" -gt 0 ]]', $script);
        self::assertStringNotContainsString('cp -R assets', $script);
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

    private function runCommand(string $command): int
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process);
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

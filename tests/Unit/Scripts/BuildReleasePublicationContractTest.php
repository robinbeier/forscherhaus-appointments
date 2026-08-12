<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BuildReleasePublicationContractTest extends TestCase
{
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
    }
}

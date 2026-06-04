<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ReleaseArtifactValidator;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ReleaseArtifactValidator.php';

final class ReleaseArtifactValidatorTest extends TestCase
{
    public function testMissingDirectoryPathsReturnsEmptyForCompleteArtifactTree(): void
    {
        $root = sys_get_temp_dir() . '/release-artifact-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            foreach (ReleaseArtifactValidator::requiredPaths() as $requiredPath) {
                $absolutePath = $root . '/' . $requiredPath;
                $directory = dirname($absolutePath);

                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }

                file_put_contents($absolutePath, 'ok');
            }

            self::assertSame([], ReleaseArtifactValidator::missingDirectoryPaths($root));
            self::assertSame([], ReleaseArtifactValidator::forbiddenDirectoryPaths($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMissingArchivePathsDetectsFrontendAssetGap(): void
    {
        $entries = array_filter(
            ReleaseArtifactValidator::requiredPaths(),
            static fn(string $path): bool => $path !== 'assets/vendor/jquery/jquery.min.js',
        );

        $missing = ReleaseArtifactValidator::missingArchivePaths($entries);

        self::assertSame(['assets/vendor/jquery/jquery.min.js'], $missing);
    }

    public function testForbiddenDirectoryPathsDetectsLocalBuildTree(): void
    {
        $root = sys_get_temp_dir() . '/release-artifact-forbidden-' . bin2hex(random_bytes(4));
        mkdir($root . '/build/vendor', 0777, true);

        try {
            self::assertSame(['build'], ReleaseArtifactValidator::forbiddenDirectoryPaths($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testForbiddenArchivePathsDetectsLocalBuildTree(): void
    {
        $entries = ['./application/config/config.php', './build/', './build/vendor/autoload.php'];

        self::assertSame(
            ['build', 'build/vendor/autoload.php'],
            ReleaseArtifactValidator::forbiddenArchivePaths($entries),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $childPath = $path . '/' . $entry;

            if (is_dir($childPath)) {
                $this->removeDirectory($childPath);
                continue;
            }

            @unlink($childPath);
        }

        @rmdir($path);
    }
}

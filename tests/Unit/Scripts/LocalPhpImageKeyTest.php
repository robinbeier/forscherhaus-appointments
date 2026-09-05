<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class LocalPhpImageKeyTest extends TestCase
{
    public function testIdenticalCopiedContextsProduceTheSameKeyAndOutsideFilesDoNotMatter(): void
    {
        $root = $this->temporaryDirectory();
        $copy = $this->temporaryDirectory();
        try {
            file_put_contents($root . '/Dockerfile', "FROM php:8.4-fpm-bookworm\n");
            mkdir($root . '/deep', 0777, true);
            file_put_contents($root . '/deep/input.txt', 'same');
            mkdir($copy . '/deep', 0777, true);
            file_put_contents($copy . '/Dockerfile', "FROM php:8.4-fpm-bookworm\n");
            file_put_contents($copy . '/deep/input.txt', 'same');
            $config = static fn(string $context): array => [
                'services' => [
                    'php-fpm' => [
                        'build' => ['context' => $context, 'args' => ['PHP_FPM_BASE_IMAGE' => 'php:8.4-fpm-bookworm']],
                        'platform' => 'linux/amd64',
                    ],
                ],
            ];
            $first = $this->imageKey($config($root), 'linux/amd64');
            $second = $this->imageKey($config($copy), 'linux/amd64');
            self::assertMatchesRegularExpression('/^forscherhaus-local\/php-fpm:[0-9a-f]{64}$/', $first);
            self::assertSame($first, $second);
            file_put_contents($root . '-application-outside.txt', 'ignored by context');
            self::assertSame($first, $this->imageKey($config($root), 'linux/amd64'));
        } finally {
            $this->removeDirectory($root);
            $this->removeDirectory($copy);
            @unlink($root . '-application-outside.txt');
        }
    }

    public function testDockerInputsBuildArgsTargetAndPlatformChangeTheKey(): void
    {
        $root = $this->temporaryDirectory();
        try {
            file_put_contents($root . '/Dockerfile', "FROM php:8.4-fpm-bookworm\n");
            $config = [
                'services' => [
                    'php-fpm' => [
                        'build' => [
                            'context' => $root,
                            'args' => ['PHP_FPM_BASE_IMAGE' => 'php:8.4-fpm-bookworm'],
                            'target' => 'runtime',
                        ],
                    ],
                ],
            ];
            $base = $this->imageKey($config, 'linux/amd64');
            file_put_contents($root . '/Dockerfile', "FROM php:8.5-fpm-bookworm\n");
            self::assertNotSame($base, $this->imageKey($config, 'linux/amd64'));
            file_put_contents($root . '/Dockerfile', "FROM php:8.4-fpm-bookworm\n");
            foreach (
                [
                    ['args' => ['PHP_FPM_BASE_IMAGE' => 'php:8.5-fpm-bookworm']],
                    ['args' => ['PHP_FPM_BASE_IMAGE' => 'php:8.4-fpm-bookworm', 'EXTENSION' => 'gd']],
                    ['target' => 'other'],
                ]
                as $change
            ) {
                $changed = $config;
                $changed['services']['php-fpm']['build'] = array_replace(
                    $changed['services']['php-fpm']['build'],
                    $change,
                );
                self::assertNotSame($base, $this->imageKey($changed, 'linux/amd64'));
            }
            self::assertNotSame($base, $this->imageKey($config, 'linux/arm64'));
            $explicit = $config;
            $explicit['services']['php-fpm']['platform'] = 'linux/arm64';
            self::assertSame($this->imageKey($config, 'linux/arm64'), $this->imageKey($explicit, 'linux/amd64'));
            $beforeNestedFile = $this->imageKey($config, 'linux/amd64');
            file_put_contents($root . '/nested.txt', 'new build input');
            $withNestedFile = $this->imageKey($config, 'linux/amd64');
            self::assertNotSame($beforeNestedFile, $withNestedFile);
            unlink($root . '/nested.txt');
            self::assertSame($beforeNestedFile, $this->imageKey($config, 'linux/amd64'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testUnsupportedComposeBuildInputsReturnEmpty(): void
    {
        $root = $this->temporaryDirectory();
        try {
            file_put_contents($root . '/Dockerfile', "FROM php:8.4-fpm-bookworm\n");
            $base = ['services' => ['php-fpm' => ['build' => ['context' => $root]]]];
            foreach (
                [
                    ['image' => 'custom/php'],
                    ['build' => ['context' => $root, 'secrets' => ['one']]],
                    ['build' => ['context' => $root, 'args' => ['SECRET' => null]]],
                ]
                as $override
            ) {
                $config = $base;
                $config['services']['php-fpm'] = array_merge($config['services']['php-fpm'], $override);
                self::assertSame('', $this->imageKey($config, 'linux/amd64'));
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function imageKey(array $config, string $platform): string
    {
        $command = ['python3', __DIR__ . '/../../../scripts/ci/local_php_image_key.py', '--platform', $platform];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($config, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        return trim((string) $stdout);
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/local-php-key-' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);
        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

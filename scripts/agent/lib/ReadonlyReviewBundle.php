<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

require_once __DIR__ . '/RepoPath.php';

final class ReadonlyReviewBundle
{
    /** @return list<string> */
    public static function changedPathsFromNul(string $raw): array
    {
        if ($raw !== '' && !str_ends_with($raw, "\0")) {
            throw new RuntimeException('Reviewer changed-path stream is invalid.');
        }
        $paths = $raw === '' ? [] : explode("\0", substr($raw, 0, -1));
        foreach ($paths as $path) {
            if (!RepoPath::isNormalized($path)) {
                throw new RuntimeException('Reviewer changed path is invalid.');
            }
        }
        if (count($paths) !== count(array_unique($paths))) {
            throw new RuntimeException('Reviewer changed paths are duplicated.');
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @param list<string> $expectedPaths */
    public static function assertTextDiffNumstat(string $raw, array $expectedPaths): void
    {
        if (!array_is_list($expectedPaths) || ($raw !== '' && !str_ends_with($raw, "\0"))) {
            throw new RuntimeException('Reviewer text-diff evidence is invalid.');
        }
        foreach ($expectedPaths as $path) {
            if (!is_string($path) || !RepoPath::isNormalized($path)) {
                throw new RuntimeException('Reviewer text-diff path evidence is invalid.');
            }
        }
        $paths = [];
        foreach ($raw === '' ? [] : explode("\0", substr($raw, 0, -1)) as $record) {
            $fields = explode("\t", $record, 3);
            if (count($fields) !== 3) {
                throw new RuntimeException('Reviewer text-diff evidence is invalid.');
            }
            [$added, $deleted, $path] = $fields;
            if ($added === '-' || $deleted === '-') {
                throw new RuntimeException('Reviewer binary diffs are not model-reviewable.');
            }
            if (
                preg_match('/^(?:0|[1-9][0-9]*)$/D', $added) !== 1 ||
                preg_match('/^(?:0|[1-9][0-9]*)$/D', $deleted) !== 1 ||
                !RepoPath::isNormalized($path)
            ) {
                throw new RuntimeException('Reviewer text-diff evidence is invalid.');
            }
            $paths[] = $path;
        }
        if (count($paths) !== count(array_unique($paths))) {
            throw new RuntimeException('Reviewer text-diff paths are duplicated.');
        }
        sort($paths, SORT_STRING);
        $normalizedExpected = $expectedPaths;
        sort($normalizedExpected, SORT_STRING);
        if ($paths !== $normalizedExpected) {
            throw new RuntimeException('Reviewer text-diff paths do not match the committed diff.');
        }
    }

    public static function sanitizeZeroContextPatch(string $patch): string
    {
        if (str_contains($patch, "\0") || preg_match('//u', $patch) !== 1) {
            throw new RuntimeException('Reviewer patch contains non-text content.');
        }
        foreach (preg_split('/\r?\n/', $patch) ?: [] as $line) {
            if (
                str_starts_with($line, '@@') &&
                preg_match('/^@@ -[0-9]+(?:,[0-9]+)? \+[0-9]+(?:,[0-9]+)? @@(?: .*)?$/D', $line) !== 1
            ) {
                throw new RuntimeException('Reviewer patch hunk header is invalid.');
            }
        }
        $sanitized = preg_replace('/^(@@ -[0-9]+(?:,[0-9]+)? \+[0-9]+(?:,[0-9]+)? @@)[^\r\n]*$/m', '$1', $patch);
        if (!is_string($sanitized)) {
            throw new RuntimeException('Reviewer patch could not be sanitized.');
        }

        return $sanitized;
    }

    /** @return array<string, mixed> */
    public static function buildManifest(
        string $bundleRoot,
        string $lens,
        string $baseSha,
        string $headSha,
        string $changedPathsPath,
        string $trustedPathsPath,
    ): array {
        self::assertSha($baseSha);
        self::assertSha($headSha);
        $root = self::canonicalDirectory($bundleRoot);
        $paths = self::readJsonList($changedPathsPath);
        $changedPathIndex = $root . '/changed-paths.json';
        if (
            realpath($changedPathsPath) !== $changedPathIndex ||
            !is_file($changedPathIndex) ||
            is_link($changedPathIndex)
        ) {
            throw new RuntimeException('Reviewer changed-path index is invalid.');
        }

        $policyPaths = file($trustedPathsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($policyPaths) || $policyPaths === []) {
            throw new RuntimeException('Reviewer trusted policy paths are invalid.');
        }
        $policy = [];
        foreach ($policyPaths as $path) {
            if (!RepoPath::isNormalized($path)) {
                throw new RuntimeException('Reviewer trusted policy path is invalid.');
            }
            $relativePath = 'policy/' . $path;
            $absolutePath = $root . '/' . $relativePath;
            if (!is_file($absolutePath) || is_link($absolutePath)) {
                throw new RuntimeException('Reviewer trusted policy file is invalid.');
            }
            $policy[] = [
                'path' => $relativePath,
                'bytes' => filesize($absolutePath),
                'sha256' => self::hashFile($absolutePath),
            ];
        }

        $patchPath = $root . '/review.patch';
        if (!is_file($patchPath) || is_link($patchPath)) {
            throw new RuntimeException('Reviewer patch is invalid.');
        }
        return [
            'schema_version' => 2,
            'lens' => $lens,
            'base_sha' => $baseSha,
            'head_sha' => $headSha,
            'context_policy' => 'zero_context_changed_lines_only',
            'binary_policy' => 'reject_before_model_input',
            'patch' => [
                'path' => 'review.patch',
                'bytes' => filesize($patchPath),
                'sha256' => self::hashFile($patchPath),
            ],
            'changed_path_index' => [
                'path' => 'changed-paths.json',
                'bytes' => filesize($changedPathIndex),
                'sha256' => self::hashFile($changedPathIndex),
            ],
            'changed_paths' => $paths,
            'trusted_base_policy' => $policy,
        ];
    }

    /** @return array{schema_version: int, manifest: array<string, mixed>, files: list<array<string, mixed>>} */
    public static function serialize(string $bundleRoot, int $maximumRawBytes): array
    {
        if ($maximumRawBytes < 1) {
            throw new RuntimeException('Reviewer serialization bound is invalid.');
        }
        $root = self::canonicalDirectory($bundleRoot);
        $manifestPath = $root . '/manifest.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Reviewer manifest is invalid.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($manifest) ||
            array_is_list($manifest) ||
            ($manifest['schema_version'] ?? null) !== 2 ||
            ($manifest['context_policy'] ?? null) !== 'zero_context_changed_lines_only' ||
            ($manifest['binary_policy'] ?? null) !== 'reject_before_model_input'
        ) {
            throw new RuntimeException('Reviewer manifest is invalid.');
        }
        $expectedFiles = ['manifest.json'];
        self::appendManifestFile($root, $manifest['patch'] ?? null, 'review.patch', $expectedFiles);
        self::appendManifestFile($root, $manifest['changed_path_index'] ?? null, 'changed-paths.json', $expectedFiles);
        $manifestChangedPaths = $manifest['changed_paths'] ?? null;
        if (!is_array($manifestChangedPaths) || !array_is_list($manifestChangedPaths)) {
            throw new RuntimeException('Reviewer manifest changed paths are invalid.');
        }
        if ($manifestChangedPaths !== self::readJsonList($root . '/changed-paths.json')) {
            throw new RuntimeException('Reviewer manifest changed paths do not match the index.');
        }
        $policy = $manifest['trusted_base_policy'] ?? null;
        if (!is_array($policy) || !array_is_list($policy) || $policy === []) {
            throw new RuntimeException('Reviewer manifest policy is invalid.');
        }
        foreach ($policy as $descriptor) {
            $path = is_array($descriptor) ? $descriptor['path'] ?? null : null;
            if (!is_string($path) || !str_starts_with($path, 'policy/')) {
                throw new RuntimeException('Reviewer manifest policy is invalid.');
            }
            self::appendManifestFile($root, $descriptor, $path, $expectedFiles);
        }
        if (count($expectedFiles) !== count(array_unique($expectedFiles))) {
            throw new RuntimeException('Reviewer manifest file allowlist is duplicated.');
        }
        sort($expectedFiles, SORT_STRING);

        $files = [];
        $foundFiles = [];
        $rawBytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->isLink() || !$entry->isFile()) {
                throw new RuntimeException('Reviewer bundle contains an invalid entry.');
            }
            $absolutePath = $entry->getPathname();
            $relativePath = substr($absolutePath, strlen($root) + 1);
            if (!is_string($relativePath) || !RepoPath::isNormalized($relativePath)) {
                throw new RuntimeException('Reviewer serialized path is invalid.');
            }
            if (!in_array($relativePath, $expectedFiles, true)) {
                throw new RuntimeException('Reviewer bundle contains a non-allowlisted file.');
            }
            $contents = file_get_contents($absolutePath);
            if (!is_string($contents)) {
                throw new RuntimeException('Reviewer bundle file is unreadable.');
            }
            if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
                throw new RuntimeException('Reviewer bundle contains non-text content.');
            }
            $rawBytes += strlen($contents);
            if ($rawBytes > $maximumRawBytes) {
                throw new RuntimeException('Reviewer serialized input exceeds the bounded size.');
            }
            $files[] = [
                'path' => $relativePath,
                'encoding' => 'utf8',
                'bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'content' => $contents,
            ];
            $foundFiles[] = $relativePath;
        }
        usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        sort($foundFiles, SORT_STRING);
        if ($foundFiles !== $expectedFiles) {
            throw new RuntimeException('Reviewer bundle is missing an allowlisted file.');
        }

        return ['schema_version' => 1, 'manifest' => $manifest, 'files' => $files];
    }

    /** @param list<string> $expectedFiles */
    private static function appendManifestFile(
        string $root,
        mixed $descriptor,
        string $expectedPath,
        array &$expectedFiles,
    ): void {
        if (
            !is_array($descriptor) ||
            ($descriptor['path'] ?? null) !== $expectedPath ||
            !RepoPath::isNormalized($expectedPath) ||
            !is_int($descriptor['bytes'] ?? null) ||
            $descriptor['bytes'] < 0 ||
            !is_string($descriptor['sha256'] ?? null) ||
            preg_match('/^[0-9a-f]{64}$/D', $descriptor['sha256']) !== 1
        ) {
            throw new RuntimeException('Reviewer manifest file descriptor is invalid.');
        }
        $absolutePath = $root . '/' . $expectedPath;
        if (
            !is_file($absolutePath) ||
            is_link($absolutePath) ||
            filesize($absolutePath) !== $descriptor['bytes'] ||
            self::hashFile($absolutePath) !== $descriptor['sha256']
        ) {
            throw new RuntimeException('Reviewer manifest file digest evidence is invalid.');
        }
        $expectedFiles[] = $expectedPath;
    }

    /** @return list<string> */
    private static function readJsonList(string $path): array
    {
        $values = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException('Reviewer JSON list is invalid.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || !RepoPath::isNormalized($value)) {
                throw new RuntimeException('Reviewer JSON path is invalid.');
            }
        }
        $sorted = $values;
        sort($sorted, SORT_STRING);
        if ($values !== $sorted || count($values) !== count(array_unique($values))) {
            throw new RuntimeException('Reviewer JSON path order is invalid.');
        }

        return $values;
    }

    private static function canonicalDirectory(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || is_link($path)) {
            throw new RuntimeException('Reviewer bundle root is invalid.');
        }

        return $resolved;
    }

    private static function assertSha(string $sha): void
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new RuntimeException('Reviewer commit binding is invalid.');
        }
    }

    private static function hashFile(string $path): string
    {
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256)) {
            throw new RuntimeException('Reviewer bundle digest failed.');
        }

        return $sha256;
    }
}

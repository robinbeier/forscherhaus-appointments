<?php

declare(strict_types=1);

use ReleaseGate\ReleaseArtifactValidator;

require_once __DIR__ . '/lib/ReleaseArtifactValidator.php';

$options = getopt('', ['root:', 'archive:', 'print-required-paths', 'print-generated-runtime-paths']);

if (isset($options['print-required-paths'])) {
    foreach (ReleaseArtifactValidator::requiredPaths() as $requiredPath) {
        fwrite(STDOUT, $requiredPath . PHP_EOL);
    }

    exit(0);
}

if (isset($options['print-generated-runtime-paths'])) {
    $root = isset($options['root']) ? trim((string) $options['root']) : '';

    if ($root === '') {
        fwrite(STDERR, "--print-generated-runtime-paths requires --root=PATH\n");
        exit(1);
    }

    try {
        foreach (ReleaseArtifactValidator::generatedRuntimeAssetPathsForDirectory($root) as $generatedPath) {
            fwrite(STDOUT, $generatedPath . PHP_EOL);
        }

        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

$root = isset($options['root']) ? trim((string) $options['root']) : '';
$archive = isset($options['archive']) ? trim((string) $options['archive']) : '';

if (($root === '' && $archive === '') || ($root !== '' && $archive !== '')) {
    fwrite(STDERR, "Usage: php scripts/release-gate/validate_release_artifact.php --root=PATH | --archive=PATH\n");
    exit(1);
}

try {
    if ($root !== '') {
        ReleaseArtifactValidator::assertDirectoryIsValid($root);
        fwrite(STDOUT, "[OK] Release artifact directory contains required files and no forbidden paths.\n");
        exit(0);
    }

    $archiveEntries = readArchiveEntries($archive);
    ReleaseArtifactValidator::assertArchiveEntriesAreValid(
        $archiveEntries,
        readArchiveEntryTypes($archive, ReleaseArtifactValidator::archiveTypePaths($archiveEntries)),
    );
    fwrite(STDOUT, "[OK] Release artifact archive contains required files and no forbidden paths.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @return list<string>
 */
function readArchiveEntries(string $archivePath): array
{
    if (!is_file($archivePath) || is_link($archivePath)) {
        throw new RuntimeException('Release artifact archive must be a regular non-symlink file: ' . $archivePath);
    }

    $stdout = runTarCommand(['tar', '-tzf', $archivePath], $archivePath);
    $entries = preg_split('/\r?\n/', $stdout) ?: [];

    return array_values(array_filter($entries, static fn(string $entry): bool => trim($entry) !== ''));
}

/**
 * @param list<string> $paths
 * @return array<string,list<string>>
 */
function readArchiveEntryTypes(string $archivePath, array $paths): array
{
    $stdout = runTarCommand(['tar', '-tvzf', $archivePath], $archivePath);
    $lines = preg_split('/\r?\n/', $stdout) ?: [];
    $types = [];
    $requiredPaths = array_fill_keys($paths, true);

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        $entryType = $line[0] ?? '';

        foreach ($paths as $path) {
            $pathPattern = preg_quote($path, '~');
            $matches = preg_match(
                '~(?:^|[[:space:]])(?:\./)?' . $pathPattern . '/?(?:[[:space:]]+(?:->|link to)[[:space:]].*)?$~',
                $line,
            );

            if ($matches !== 1) {
                continue;
            }

            $types[$path] ??= [];
            $types[$path][] = $entryType;
            break;
        }

        if ($entryType !== 'h') {
            continue;
        }

        $hardlinkTarget = readCanonicalArchiveHardlinkTarget($line);

        if (isset($requiredPaths[$hardlinkTarget])) {
            $types[$hardlinkTarget] ??= [];
            $types[$hardlinkTarget][] = 'hardlink-alias';
        }
    }

    return $types;
}

function readCanonicalArchiveHardlinkTarget(string $line): string
{
    $matches = [];
    $separatorPattern = '[[:space:]]link to[[:space:]]+';

    if (
        preg_match_all('~(?=' . $separatorPattern . ')~', $line) !== 1 ||
        preg_match('~' . $separatorPattern . '(.+)$~', $line, $matches) !== 1
    ) {
        throw new RuntimeException('Release artifact archive contains a malformed or non-canonical hardlink target.');
    }

    $target = $matches[1];

    if (str_starts_with($target, './')) {
        $target = substr($target, 2);
    }

    if ($target === '' || str_starts_with($target, '/')) {
        throw new RuntimeException('Release artifact archive contains a malformed or non-canonical hardlink target.');
    }

    foreach (explode('/', $target) as $component) {
        if ($component === '' || $component === '.' || $component === '..') {
            throw new RuntimeException(
                'Release artifact archive contains a malformed or non-canonical hardlink target.',
            );
        }
    }

    return $target;
}

/**
 * @param list<string> $command
 */
function runTarCommand(array $command, string $archivePath): string
{
    if (!is_file($archivePath) || is_link($archivePath)) {
        throw new RuntimeException('Release artifact archive must be a regular non-symlink file: ' . $archivePath);
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, dirname($archivePath));

    if (!is_resource($process)) {
        throw new RuntimeException('Could not open tar process for release artifact validation.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException('Could not list release artifact archive contents: ' . trim((string) $stderr));
    }

    return (string) $stdout;
}

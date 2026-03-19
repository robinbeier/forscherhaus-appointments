<?php

declare(strict_types=1);

use ReleaseGate\ReleaseArtifactValidator;

require_once __DIR__ . '/lib/ReleaseArtifactValidator.php';

$options = getopt('', ['root:', 'archive:', 'print-required-paths']);

if (isset($options['print-required-paths'])) {
    foreach (ReleaseArtifactValidator::requiredPaths() as $requiredPath) {
        fwrite(STDOUT, $requiredPath . PHP_EOL);
    }

    exit(0);
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
        fwrite(STDOUT, "[OK] Release artifact directory contains required files.\n");
        exit(0);
    }

    ReleaseArtifactValidator::assertArchiveEntriesAreValid(readArchiveEntries($archive));
    fwrite(STDOUT, "[OK] Release artifact archive contains required files.\n");
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
    if (!is_file($archivePath)) {
        throw new RuntimeException('Release artifact archive not found: ' . $archivePath);
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(['tar', '-tzf', $archivePath], $descriptorSpec, $pipes, dirname($archivePath));

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

    $entries = preg_split('/\r?\n/', (string) $stdout) ?: [];

    return array_values(array_filter($entries, static fn(string $entry): bool => trim($entry) !== ''));
}

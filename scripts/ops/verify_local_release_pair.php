<?php

declare(strict_types=1);

use Ops\DeploymentEvidenceAuthorityV1;
use Ops\ReleaseBuildProvenanceProducerV1;

require_once __DIR__ . '/lib/ReleaseBuildProvenanceProducerV1.php';

$args = getopt('', ['release:', 'commit:', 'archive:', 'provenance:']);
$required = ['release', 'commit', 'archive', 'provenance'];
foreach ($required as $key) {
    if (!isset($args[$key]) || !is_string($args[$key]) || $args[$key] === '') {
        fwrite(STDERR, "local release pair rejected\n");
        exit(64);
    }
}

try {
    $release = $args['release'];
    $commit = $args['commit'];
    if (
        preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $release) !== 1 ||
        preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1
    ) {
        throw new RuntimeException('invalid release identity');
    }

    $archivePath = $args['archive'];
    $provenancePath = $args['provenance'];
    foreach (
        [$archivePath => $release . '.tar.gz', $provenancePath => $release . '.build-provenance.json']
        as $path => $name
    ) {
        if (
            !str_starts_with($path, '/') ||
            str_contains($path, "\0") ||
            basename($path) !== $name ||
            is_link($path) ||
            !is_file($path)
        ) {
            throw new RuntimeException('unsafe local release path');
        }
        $parent = dirname($path);
        $parentStat = lstat($parent);
        $fileStat = lstat($path);
        if (
            !is_array($parentStat) ||
            !is_array($fileStat) ||
            is_link($parent) ||
            realpath($parent) !== $parent ||
            ($parentStat['uid'] ?? null) !== posix_geteuid() ||
            (($parentStat['mode'] ?? 0) & 0777) !== 0700 ||
            ($fileStat['uid'] ?? null) !== posix_geteuid() ||
            ($fileStat['nlink'] ?? null) !== 1 ||
            ($fileStat['size'] ?? 0) <= 0
        ) {
            throw new RuntimeException('unsafe local release identity');
        }
    }
    if (dirname($archivePath) !== dirname($provenancePath)) {
        throw new RuntimeException('release pair must share one private directory');
    }

    $archive = ReleaseBuildProvenanceProducerV1::inspectArchive($archivePath);
    $sidecarBefore = lstat($provenancePath);
    if (!is_array($sidecarBefore) || $sidecarBefore['size'] > DeploymentEvidenceAuthorityV1::MAX_FILE_BYTES) {
        throw new RuntimeException('unsafe release provenance size');
    }
    $bytes = file_get_contents($provenancePath);
    $sidecarAfter = lstat($provenancePath);
    if (!is_string($bytes) || !is_array($sidecarAfter) || strlen($bytes) !== $sidecarBefore['size']) {
        throw new RuntimeException('release provenance unreadable');
    }
    foreach (['dev', 'ino', 'mode', 'uid', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
        if ($sidecarBefore[$key] !== $sidecarAfter[$key]) {
            throw new RuntimeException('release provenance changed during inspection');
        }
    }

    $root = dirname(__DIR__, 2);
    $sources = [
        'build_script_sha256' => $root . '/build_release.sh',
        'composer_lock_sha256' => $root . '/composer.lock',
        'package_lock_sha256' => $root . '/package-lock.json',
        'deploy_ea_sha256' => $root . '/deploy_ea.sh',
    ];
    $sourceDigests = [];
    foreach ($sources as $key => $path) {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('release source missing');
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new RuntimeException('release source unreadable');
        }
        $sourceDigests[$key] = $digest;
    }

    $record = DeploymentEvidenceAuthorityV1::decodeAuthorizedBuildProvenance(
        $bytes,
        hash('sha256', $bytes),
        $release,
        $commit,
        $archive['sha256'],
        $archive['size_bytes'],
        $sourceDigests['deploy_ea_sha256'],
        $sourceDigests['deploy_ea_sha256'],
        $archive['entry_count'],
        $archive['stage_inode_count'],
        $archive['stage_unpacked_bytes'],
        ReleaseBuildProvenanceProducerV1::FIXED_TEMP_OVERHEAD_BYTES,
    );
    foreach ($sourceDigests as $key => $digest) {
        if (!hash_equals($record['source'][$key], $digest)) {
            throw new RuntimeException('release source contradicts provenance');
        }
    }
    fwrite(STDOUT, "verified\n");
} catch (Throwable) {
    fwrite(STDERR, "local release pair rejected\n");
    exit(70);
}

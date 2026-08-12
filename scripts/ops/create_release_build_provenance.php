<?php

declare(strict_types=1);

use Ops\DeploymentEvidenceAuthorityV1;
use Ops\ReleaseBuildProvenanceProducerV1;

require_once __DIR__ . '/lib/ReleaseBuildProvenanceProducerV1.php';

$args = getopt('', [
    'release:',
    'commit:',
    'stage:',
    'archive:',
    'build-script:',
    'composer-lock:',
    'package-lock:',
    'deploy-script:',
]);
$required = ['release', 'commit', 'stage', 'archive', 'build-script', 'composer-lock', 'package-lock', 'deploy-script'];
foreach ($required as $key) {
    if (!isset($args[$key]) || !is_string($args[$key]) || $args[$key] === '') {
        fwrite(STDERR, "invalid provenance producer input\n");
        exit(64);
    }
}
try {
    $record = ReleaseBuildProvenanceProducerV1::create(
        $args['release'],
        $args['commit'],
        $args['stage'],
        $args['archive'],
        $args['build-script'],
        $args['composer-lock'],
        $args['package-lock'],
        $args['deploy-script'],
    );
    fwrite(STDOUT, DeploymentEvidenceAuthorityV1::encodeFile($record));
} catch (Throwable) {
    fwrite(STDERR, "release provenance rejected\n");
    exit(70);
}

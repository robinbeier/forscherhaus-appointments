<?php

declare(strict_types=1);

function ordinary_probe_required_libraries(string $source, string $libraryRoot): array
{
    preg_match_all(
        "~^[ \\t]*require_once dirname\\(__DIR__\\) \\. '/release-gate/lib/([A-Za-z][A-Za-z0-9_]*)\\.php';[ \\t]*$~m",
        $source,
        $requires,
    );
    preg_match_all('/^use ReleaseGate\\\\([A-Za-z][A-Za-z0-9_]*);[ \\t]*$/m', $source, $imports);
    if ($requires[1] === [] || $imports[1] === [] || count($requires[1]) !== count(array_unique($requires[1]))) {
        throw new RuntimeException('Ordinary probe library contract is incomplete.');
    }

    $files = [];
    $declaredClasses = [];
    foreach ($requires[1] as $name) {
        $path = $libraryRoot . '/' . $name . '.php';
        $library = @file_get_contents($path);
        if ($library === false || !preg_match('/^namespace ReleaseGate;$/m', $library)) {
            throw new RuntimeException('Ordinary probe required library is unavailable.');
        }
        preg_match_all(
            '/^[ \\t]*(?:(?:final|abstract|readonly)[ \\t]+)*class[ \\t]+([A-Za-z][A-Za-z0-9_]*)\\b/m',
            $library,
            $classes,
        );
        $declaredClasses = array_merge($declaredClasses, $classes[1]);
        $files[] = $path;
    }
    if (array_diff($imports[1], $declaredClasses) !== []) {
        throw new RuntimeException('Ordinary probe import has no runtime require.');
    }

    return $files;
}

$entrypoint = @file_get_contents(dirname(__DIR__) . '/ops/ordinary_live_probe.php');
if ($entrypoint === false) {
    throw new RuntimeException('Ordinary probe entrypoint is unavailable.');
}
foreach (ordinary_probe_required_libraries($entrypoint, dirname(__DIR__) . '/release-gate/lib') as $file) {
    require_once $file;
}

<?php

declare(strict_types=1);

use Forscherhaus\AgentHarness\ReadonlyReviewBundle;

require_once __DIR__ . '/lib/ReadonlyReviewBundle.php';

$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/Ds', $argument, $matches) !== 1) {
        fwrite(STDERR, "Unknown option.\n");
        exit(2);
    }
    if (array_key_exists($matches[1], $options)) {
        fwrite(STDERR, "Duplicate option.\n");
        exit(2);
    }
    $options[$matches[1]] = $matches[2];
}

$required = static function (string $name) use ($options): string {
    $value = $options[$name] ?? null;
    if (!is_string($value) || $value === '') {
        throw new InvalidArgumentException('Missing --' . $name . '.');
    }

    return $value;
};

try {
    switch ($command) {
        case 'changed-paths':
            if ($options !== []) {
                throw new InvalidArgumentException('changed-paths accepts no options.');
            }
            $paths = ReadonlyReviewBundle::changedPathsFromNul((string) stream_get_contents(STDIN));
            fwrite(STDOUT, json_encode($paths, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            break;

        case 'assert-text-diff':
            $paths = json_decode(
                (string) file_get_contents($required('changed-paths')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            if (!is_array($paths)) {
                throw new RuntimeException('Reviewer changed-path evidence is invalid.');
            }
            ReadonlyReviewBundle::assertTextDiffNumstat((string) stream_get_contents(STDIN), $paths);
            break;

        case 'sanitize-patch':
            if ($options !== []) {
                throw new InvalidArgumentException('sanitize-patch accepts no options.');
            }
            fwrite(STDOUT, ReadonlyReviewBundle::sanitizeZeroContextPatch((string) stream_get_contents(STDIN)));
            break;

        case 'manifest':
            $manifest = ReadonlyReviewBundle::buildManifest(
                $required('bundle-root'),
                $required('lens'),
                $required('base-sha'),
                $required('head-sha'),
                $required('changed-paths'),
                $required('trusted-paths'),
            );
            fwrite(
                STDOUT,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
            );
            break;

        case 'serialize':
            $maximumRawBytes = filter_var($required('max-raw-bytes'), FILTER_VALIDATE_INT);
            if (!is_int($maximumRawBytes)) {
                throw new InvalidArgumentException('Invalid --max-raw-bytes.');
            }
            $serialization = ReadonlyReviewBundle::serialize($required('bundle-root'), $maximumRawBytes);
            fwrite(
                STDOUT,
                json_encode($serialization, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
            );
            break;

        case 'developer-instructions':
            $role = file_get_contents($required('role'));
            if (!is_string($role)) {
                throw new RuntimeException('Reviewer developer-instruction input is unavailable.');
            }
            fwrite(
                STDOUT,
                ReadonlyReviewBundle::buildDeveloperInstructions(
                    $role,
                    $required('lens'),
                    $required('base-sha'),
                    $required('head-sha'),
                ),
            );
            break;

        case 'toml-string':
            $value = file_get_contents($required('input'));
            if (!is_string($value)) {
                throw new RuntimeException('Reviewer TOML string input is unavailable.');
            }
            fwrite(STDOUT, ReadonlyReviewBundle::tomlString($value));
            break;

        case 'validate-prompt-roles':
            $developerInstructions = file_get_contents($required('developer'));
            if (!is_string($developerInstructions)) {
                throw new RuntimeException('Reviewer prompt-role developer input is unavailable.');
            }
            ReadonlyReviewBundle::assertPromptRoles(
                (string) stream_get_contents(STDIN),
                $developerInstructions,
                $required('user-probe'),
            );
            break;

        case 'model-catalog':
            $catalog = ReadonlyReviewBundle::restrictModelCatalog(
                (string) stream_get_contents(STDIN),
                $required('model'),
            );
            fwrite(STDOUT, json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            break;

        default:
            throw new InvalidArgumentException('Unsupported readonly-review bundle command.');
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}

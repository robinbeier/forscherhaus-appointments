<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

use InvalidArgumentException;
use JsonException;

require_once __DIR__ . '/RepoPath.php';

final class ReadonlyReviewOutput
{
    /** @var list<string> */
    private const SENSITIVE_FINDING_TEXT_PATTERNS = [
        '/[\x00-\x1F\x7F]/',
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/_=.-]{8,}\b/i',
        '/\b(?:sk|rk|pk|gh[pousr]|xox[baprs])[-_][A-Za-z0-9._-]{12,}\b/i',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\b(?:password|passwd|secret|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|session[_ -]?id|cookie)\s*[:=]\s*\S+/i',
        '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
        '#\b(?:https?|ssh)://\S+#i',
        '#/(?:root(?=/|\b)|(?:Users|home)/[^/\s`"\'<>{}\[\]()]+)#',
        '/\b[0-9a-f]{32,}\b/i',
        '#(?<![A-Za-z0-9+/_-])[A-Za-z0-9+/_-]{48,}={0,2}(?![A-Za-z0-9+/_-])#',
    ];

    /**
     * Structural keys, enums, types, and text limits are read from the exact-base
     * JSON schema. This validator adds only semantic commit/path binding and the
     * privacy policy that JSON Schema cannot express.
     *
     * @param list<string> $changedPaths
     * @return array<string, mixed>
     */
    public static function validate(
        string $output,
        string $expectedLens,
        string $expectedBaseSha,
        string $expectedHeadSha,
        array $changedPaths,
        ?string $schemaPath = null,
    ): array {
        $schema = self::loadSchema($schemaPath ?? dirname(__DIR__) . '/readonly-review-output.schema.json');
        $changedPathSet = self::changedPathSet($changedPaths);

        try {
            $review = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Reviewer output is not valid JSON.');
        }
        if (!is_array($review) || array_is_list($review)) {
            throw new InvalidArgumentException('Reviewer output has an invalid shape.');
        }

        $properties = self::objectPropertyMap($schema, 'Reviewer output schema is invalid.');
        self::assertExactKeys($review, self::requiredKeys($schema), 'Reviewer output has unexpected fields.');
        self::assertEnum($review['lens'] ?? null, $properties['lens'] ?? null, 'Reviewer output lens is invalid.');
        self::assertPattern($review['base_sha'] ?? null, $properties['base_sha'] ?? null);
        self::assertPattern($review['head_sha'] ?? null, $properties['head_sha'] ?? null);
        if (
            $review['lens'] !== $expectedLens ||
            $review['base_sha'] !== $expectedBaseSha ||
            $review['head_sha'] !== $expectedHeadSha
        ) {
            throw new InvalidArgumentException(
                'Reviewer output is not bound to the requested base, exact head, and lens.',
            );
        }

        self::assertEnum(
            $review['verdict'] ?? null,
            $properties['verdict'] ?? null,
            'Reviewer output verdict is invalid.',
        );
        $findings = $review['findings'] ?? null;
        $findingSchema = is_array($properties['findings'] ?? null) ? $properties['findings']['items'] ?? null : null;
        if (!is_array($findings) || !array_is_list($findings) || !is_array($findingSchema)) {
            throw new InvalidArgumentException('Reviewer output findings are invalid.');
        }
        if (($review['verdict'] === 'no_findings') !== ($findings === [])) {
            throw new InvalidArgumentException('Reviewer output verdict does not match its findings.');
        }
        foreach ($findings as $finding) {
            self::validateFinding($finding, $findingSchema, $changedPathSet);
        }

        return $review;
    }

    /** @return array<string, mixed> */
    private static function loadSchema(string $path): array
    {
        if (!str_starts_with($path, '/') || !is_file($path) || is_link($path)) {
            throw new InvalidArgumentException('Reviewer output schema is unavailable.');
        }
        try {
            $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Reviewer output schema is invalid.');
        }
        if (
            !is_array($schema) ||
            array_is_list($schema) ||
            ($schema['type'] ?? null) !== 'object' ||
            ($schema['additionalProperties'] ?? null) !== false
        ) {
            throw new InvalidArgumentException('Reviewer output schema is invalid.');
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function objectPropertyMap(array $schema, string $message): array
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties) || array_is_list($properties)) {
            throw new InvalidArgumentException($message);
        }

        return $properties;
    }

    /** @return list<string> */
    private static function requiredKeys(array $schema): array
    {
        $required = $schema['required'] ?? null;
        if (!is_array($required) || !array_is_list($required) || $required === []) {
            throw new InvalidArgumentException('Reviewer output schema is invalid.');
        }
        foreach ($required as $key) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('Reviewer output schema is invalid.');
            }
        }
        if (count($required) !== count(array_unique($required))) {
            throw new InvalidArgumentException('Reviewer output schema is invalid.');
        }

        return $required;
    }

    /** @param array<string, mixed> $value
     *  @param list<string> $expected
     */
    private static function assertExactKeys(array $value, array $expected, string $message): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($message);
        }
    }

    private static function assertEnum(mixed $value, mixed $schema, string $message): void
    {
        $allowed = is_array($schema) ? $schema['enum'] ?? null : null;
        if (!is_array($allowed) || !array_is_list($allowed) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }
    }

    private static function assertPattern(mixed $value, mixed $schema): void
    {
        $pattern = is_array($schema) ? $schema['pattern'] ?? null : null;
        if (
            !is_string($value) ||
            !is_string($pattern) ||
            str_contains($pattern, '~') ||
            @preg_match('~' . $pattern . '~D', $value) !== 1
        ) {
            throw new InvalidArgumentException('Reviewer output commit binding is invalid.');
        }
    }

    /** @param array<string, mixed> $schema
     *  @param array<string, true> $changedPathSet
     */
    private static function validateFinding(mixed $finding, array $schema, array $changedPathSet): void
    {
        if (
            !is_array($finding) ||
            array_is_list($finding) ||
            ($schema['type'] ?? null) !== 'object' ||
            ($schema['additionalProperties'] ?? null) !== false
        ) {
            throw new InvalidArgumentException('Reviewer finding has an invalid shape.');
        }
        self::assertExactKeys($finding, self::requiredKeys($schema), 'Reviewer finding has unexpected fields.');
        $properties = self::objectPropertyMap($schema, 'Reviewer finding schema is invalid.');
        self::assertEnum(
            $finding['priority'] ?? null,
            $properties['priority'] ?? null,
            'Reviewer finding priority is invalid.',
        );
        foreach (['title', 'impact', 'trigger'] as $field) {
            self::validateFindingText($finding[$field] ?? null, $properties[$field] ?? null);
        }
        $file = $finding['file'] ?? null;
        if (!is_string($file) || !RepoPath::isNormalized($file) || !isset($changedPathSet[$file])) {
            throw new InvalidArgumentException('Reviewer finding file is not a changed repository path.');
        }
        self::validateFindingLine($finding['line'] ?? null, $properties['line'] ?? null);
    }

    private static function validateFindingText(mixed $value, mixed $schema): void
    {
        $minimum = is_array($schema) ? $schema['minLength'] ?? null : null;
        $maximum = is_array($schema) ? $schema['maxLength'] ?? null : null;
        if (
            !is_string($value) ||
            !is_int($minimum) ||
            !is_int($maximum) ||
            $minimum < 0 ||
            $maximum < $minimum ||
            preg_match('//u', $value) !== 1
        ) {
            throw new InvalidArgumentException('Reviewer finding text is invalid.');
        }
        $length = preg_match_all('/./us', $value);
        if (!is_int($length) || $length < $minimum || $length > $maximum || trim($value) === '') {
            throw new InvalidArgumentException('Reviewer finding text is invalid.');
        }
        foreach (self::SENSITIVE_FINDING_TEXT_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new InvalidArgumentException('Reviewer finding text is not privacy-safe.');
            }
        }
    }

    private static function validateFindingLine(mixed $value, mixed $schema): void
    {
        $types = is_array($schema) ? $schema['type'] ?? null : null;
        $minimum = is_array($schema) ? $schema['minimum'] ?? null : null;
        if (
            !is_array($types) ||
            !in_array('integer', $types, true) ||
            !in_array('null', $types, true) ||
            !is_int($minimum) ||
            ($value !== null && (!is_int($value) || $value < $minimum))
        ) {
            throw new InvalidArgumentException('Reviewer finding line is invalid.');
        }
    }

    /** @param list<string> $changedPaths
     *  @return array<string, true>
     */
    private static function changedPathSet(array $changedPaths): array
    {
        if (!array_is_list($changedPaths)) {
            throw new InvalidArgumentException('Reviewer changed-path evidence is invalid.');
        }
        $set = [];
        foreach ($changedPaths as $path) {
            if (!is_string($path) || !RepoPath::isNormalized($path) || isset($set[$path])) {
                throw new InvalidArgumentException('Reviewer changed-path evidence is invalid.');
            }
            $set[$path] = true;
        }

        return $set;
    }
}

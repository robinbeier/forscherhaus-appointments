<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

/**
 * Closed, secret-free result contract for the anonymous booking-download probe.
 * Status codes, headers, URLs and capabilities are reduced to fixed classes
 * and booleans before a receipt is emitted.
 */
final class ReadOnlyProbeReceiptV1
{
    public const SCHEMA = 'read_only_probe.v1';
    public const PROBE = 'anonymous_booking_download_capabilities';

    /** @var array<int,string> */
    private const TARGET_CLASSES = ['production', 'local', 'unapproved'];

    public const OUTCOME_EXIT_CODES = [
        'passed' => 0,
        'application_failed' => 20,
        'environment_failed' => 21,
        'unknown' => 70,
    ];

    /** @var array<string,bool> */
    private const CHECK_DEFAULTS = [
        'modern_confirmation_redirect' => false,
        'legacy_confirmation_redirect' => false,
        'modern_ics_missing' => false,
        'legacy_ics_missing' => false,
        'modern_ics_headers_safe' => false,
        'legacy_ics_headers_safe' => false,
    ];

    /** @var array<int,string> */
    private const REDIRECT_CLASSES = ['appointments', 'unexpected', 'missing', 'malformed'];

    /** @var array<int,string> */
    private const ICS_HEADER_CLASSES = ['not_calendar_no_disposition', 'calendar', 'disposition', 'malformed'];

    /** @var array<int,string> */
    private const TOP_LEVEL_FIELDS = [
        'schema',
        'probe',
        'target_class',
        'outcome',
        'exit_code',
        'checks',
        'redirect_class',
        'ics_header_class',
        'check_count',
        'cleanup',
    ];

    /**
     * @param array<string,bool> $checks
     * @param array{modern:string,legacy:string} $redirectClass
     * @param array{modern:string,legacy:string} $icsHeaderClass
     * @return array<string,mixed>
     */
    public static function create(
        string $outcome,
        string $targetClass,
        array $checks = [],
        array $redirectClass = ['modern' => 'malformed', 'legacy' => 'malformed'],
        array $icsHeaderClass = ['modern' => 'malformed', 'legacy' => 'malformed'],
    ): array {
        $receipt = [
            'schema' => self::SCHEMA,
            'probe' => self::PROBE,
            'target_class' => $targetClass,
            'outcome' => $outcome,
            'exit_code' => self::OUTCOME_EXIT_CODES[$outcome] ?? -1,
            'checks' => array_merge(self::CHECK_DEFAULTS, $checks),
            'redirect_class' => $redirectClass,
            'ics_header_class' => $icsHeaderClass,
            'check_count' => count(self::CHECK_DEFAULTS),
            'cleanup' => 'not_applicable',
        ];
        self::validate($receipt);

        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    public static function validate(array $receipt): void
    {
        if (array_is_list($receipt)) {
            throw new RuntimeException('read-only probe receipt must be an object');
        }
        $actualKeys = array_keys($receipt);
        $expectedKeys = self::TOP_LEVEL_FIELDS;
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('read-only probe receipt fields are invalid');
        }
        if ($receipt['schema'] !== self::SCHEMA || $receipt['probe'] !== self::PROBE) {
            throw new RuntimeException('read-only probe receipt identity is invalid');
        }
        if (!is_string($receipt['target_class']) || !in_array($receipt['target_class'], self::TARGET_CLASSES, true)) {
            throw new RuntimeException('read-only probe target class is invalid');
        }
        if (!is_string($receipt['outcome']) || !array_key_exists($receipt['outcome'], self::OUTCOME_EXIT_CODES)) {
            throw new RuntimeException('read-only probe receipt outcome is invalid');
        }
        if (!is_int($receipt['exit_code']) || self::OUTCOME_EXIT_CODES[$receipt['outcome']] !== $receipt['exit_code']) {
            throw new RuntimeException('read-only probe receipt exit code is invalid');
        }
        if ($receipt['check_count'] !== count(self::CHECK_DEFAULTS)) {
            throw new RuntimeException('read-only probe receipt check count is invalid');
        }
        self::validateChecks($receipt['checks']);
        self::validateClasses($receipt['redirect_class'], self::REDIRECT_CLASSES, 'redirect class');
        self::validateClasses($receipt['ics_header_class'], self::ICS_HEADER_CLASSES, 'ICS header class');
        self::validateEvidenceConsistency($receipt['checks'], $receipt['redirect_class'], $receipt['ics_header_class']);
        if (
            $receipt['target_class'] === 'unapproved' &&
            ($receipt['outcome'] !== 'unknown' ||
                $receipt['exit_code'] !== self::OUTCOME_EXIT_CODES['unknown'] ||
                self::anyChecksPassed($receipt['checks']) ||
                $receipt['redirect_class'] !== ['modern' => 'malformed', 'legacy' => 'malformed'] ||
                $receipt['ics_header_class'] !== ['modern' => 'malformed', 'legacy' => 'malformed'])
        ) {
            throw new RuntimeException('unapproved target requires an unknown neutral receipt');
        }
        if (
            in_array($receipt['outcome'], ['environment_failed', 'unknown'], true) &&
            (self::anyChecksPassed($receipt['checks']) ||
                $receipt['redirect_class'] !== ['modern' => 'malformed', 'legacy' => 'malformed'] ||
                $receipt['ics_header_class'] !== ['modern' => 'malformed', 'legacy' => 'malformed'])
        ) {
            throw new RuntimeException('unclassified read-only probe cannot claim a security property');
        }
        if ($receipt['cleanup'] !== 'not_applicable') {
            throw new RuntimeException('read-only probe cleanup value is invalid');
        }
        if (
            $receipt['outcome'] === 'passed' &&
            (!self::allChecksPassed($receipt['checks']) ||
                $receipt['redirect_class'] !== ['modern' => 'appointments', 'legacy' => 'appointments'] ||
                $receipt['ics_header_class'] !== [
                    'modern' => 'not_calendar_no_disposition',
                    'legacy' => 'not_calendar_no_disposition',
                ])
        ) {
            throw new RuntimeException('passed read-only probe contains a failed check');
        }
        if ($receipt['outcome'] === 'application_failed' && self::allChecksPassed($receipt['checks'])) {
            throw new RuntimeException('application failure must contain a failed check');
        }
    }

    /** @param mixed $checks */
    private static function validateChecks(mixed $checks): void
    {
        if (!is_array($checks) || array_keys($checks) !== array_keys(self::CHECK_DEFAULTS)) {
            throw new RuntimeException('read-only probe checks are invalid');
        }
        foreach ($checks as $value) {
            if (!is_bool($value)) {
                throw new RuntimeException('read-only probe check value is invalid');
            }
        }
    }

    /** @param mixed $classes @param array<int,string> $allowed */
    private static function validateClasses(mixed $classes, array $allowed, string $label): void
    {
        if (!is_array($classes) || array_keys($classes) !== ['modern', 'legacy']) {
            throw new RuntimeException($label . ' shape is invalid');
        }
        foreach ($classes as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new RuntimeException($label . ' value is invalid');
            }
        }
    }

    /**
     * @param array<string,bool> $checks
     * @param array{modern:string,legacy:string} $redirectClass
     * @param array{modern:string,legacy:string} $icsHeaderClass
     */
    private static function validateEvidenceConsistency(
        array $checks,
        array $redirectClass,
        array $icsHeaderClass,
    ): void {
        foreach (['modern', 'legacy'] as $version) {
            if ($checks[$version . '_confirmation_redirect'] !== ($redirectClass[$version] === 'appointments')) {
                throw new RuntimeException('confirmation check contradicts redirect class');
            }
            if (
                $checks[$version . '_ics_headers_safe'] !==
                ($icsHeaderClass[$version] === 'not_calendar_no_disposition')
            ) {
                throw new RuntimeException('ICS header check contradicts header class');
            }
            if (!$checks[$version . '_ics_missing'] && $icsHeaderClass[$version] !== 'malformed') {
                throw new RuntimeException('ICS missing check contradicts header class');
            }
        }
    }

    /** @param array<string,bool> $checks */
    private static function allChecksPassed(array $checks): bool
    {
        return !in_array(false, $checks, true);
    }

    /** @param array<string,bool> $checks */
    private static function anyChecksPassed(array $checks): bool
    {
        return in_array(true, $checks, true);
    }

    /** @param array<string,mixed> $receipt */
    public static function canonicalJson(array $receipt): string
    {
        self::validate($receipt);
        $canonical = [];
        foreach (self::TOP_LEVEL_FIELDS as $field) {
            $canonical[$field] = $receipt[$field];
        }
        try {
            return json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('read-only probe receipt cannot be encoded', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    public static function decode(string $encoded): array
    {
        if ($encoded === '' || strlen($encoded) > 2048 || str_contains($encoded, "\0")) {
            throw new RuntimeException('read-only probe receipt encoding is invalid');
        }
        try {
            $receipt = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('read-only probe receipt JSON is invalid', 0, $exception);
        }
        if (!is_array($receipt)) {
            throw new RuntimeException('read-only probe receipt must be an object');
        }
        self::validate($receipt);
        if (!hash_equals(self::canonicalJson($receipt), $encoded)) {
            throw new RuntimeException('read-only probe receipt is not canonical');
        }

        return $receipt;
    }
}

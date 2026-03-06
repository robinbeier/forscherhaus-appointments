<?php

declare(strict_types=1);

namespace ReleaseGate;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

require_once __DIR__ . '/ZeroSurpriseProfile.php';

final class ZeroSurpriseCredentials
{
    /**
     * @param array<string, mixed> $cliOverrides
     * @return array{
     *   profile_name:string,
     *   profile_window:array<string, mixed>,
     *   credentials_file:?string,
     *   base_url:string,
     *   index_page:string,
     *   username:string,
     *   password:string,
     *   start_date:string,
     *   end_date:string,
     *   booking_search_days:int,
     *   retry_count:int,
     *   max_pdf_duration_ms:int,
     *   timezone:string,
     *   pdf_health_url:?string
     * }
     */
    public static function resolve(
        ?string $credentialsFile,
        string $profileName,
        array $cliOverrides = [],
        ?DateTimeImmutable $nowUtc = null,
    ): array {
        $definition = ZeroSurpriseProfile::loadDefinition($profileName);
        $ini = self::loadIniFile($credentialsFile);

        $timezone = self::resolveTimezone($definition, $ini, $cliOverrides);
        $resolvedProfile = ZeroSurpriseProfile::resolve($profileName, $nowUtc, $timezone);

        $baseUrl = self::resolveRequiredNonEmptyString('base_url', $ini, $cliOverrides);
        $indexPage = self::resolveRequiredStringAllowEmpty('index_page', $ini, $cliOverrides);
        $username = self::resolveRequiredNonEmptyString('username', $ini, $cliOverrides);
        $password = self::resolveRequiredNonEmptyString('password', $ini, $cliOverrides, false);

        $startDate = self::resolveDateString('start_date', $resolvedProfile['start_date'], $ini, $cliOverrides);
        $endDate = self::resolveDateString('end_date', $resolvedProfile['end_date'], $ini, $cliOverrides);
        if ($startDate > $endDate) {
            throw new InvalidArgumentException(
                'Resolved zero-surprise date window is invalid: start_date must be <= end_date.',
            );
        }

        $bookingSearchDays = self::resolvePositiveInt(
            'booking_search_days',
            $resolvedProfile['booking_search_days'],
            $ini,
            $cliOverrides,
        );
        $retryCount = self::resolveNonNegativeInt('retry_count', $resolvedProfile['retry_count'], $ini, $cliOverrides);
        $maxPdfDurationMs = self::resolvePositiveInt(
            'max_pdf_duration_ms',
            $resolvedProfile['max_pdf_duration_ms'],
            $ini,
            $cliOverrides,
        );

        $pdfHealthUrl = self::resolveOptionalString('pdf_health_url', $ini, $cliOverrides);
        $pdfHealthUrl = $pdfHealthUrl !== null && trim($pdfHealthUrl) !== '' ? trim($pdfHealthUrl) : null;

        return [
            'profile_name' => $resolvedProfile['name'],
            'profile_window' => $resolvedProfile['window'],
            'credentials_file' => $credentialsFile,
            'base_url' => $baseUrl,
            'index_page' => $indexPage,
            'username' => $username,
            'password' => $password,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'booking_search_days' => $bookingSearchDays,
            'retry_count' => $retryCount,
            'max_pdf_duration_ms' => $maxPdfDurationMs,
            'timezone' => $timezone,
            'pdf_health_url' => $pdfHealthUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadIniFile(?string $credentialsFile): array
    {
        if ($credentialsFile === null || trim($credentialsFile) === '') {
            return [];
        }

        if (!is_file($credentialsFile) || !is_readable($credentialsFile)) {
            throw new InvalidArgumentException('Credentials file is not readable: ' . $credentialsFile);
        }

        $parsed = parse_ini_file($credentialsFile, false, INI_SCANNER_RAW);
        if ($parsed === false || !is_array($parsed)) {
            throw new InvalidArgumentException('Could not parse INI credentials file: ' . $credentialsFile);
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $profileDefinition
     * @param array<string, mixed> $ini
     * @param array<string, mixed> $cliOverrides
     */
    private static function resolveTimezone(array $profileDefinition, array $ini, array $cliOverrides): string
    {
        $resolved = self::resolveOptionalString('timezone', $ini, $cliOverrides);
        $timezone =
            $resolved !== null && trim($resolved) !== '' ? trim($resolved) : (string) $profileDefinition['timezone'];

        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Resolved timezone is invalid: ' . $timezone, 0, $exception);
        }

        return $timezone;
    }

    /**
     * @param array<string, mixed> $ini
     * @param array<string, mixed> $cliOverrides
     */
    private static function resolveRequiredNonEmptyString(
        string $field,
        array $ini,
        array $cliOverrides,
        bool $trimValue = true,
    ): string {
        $resolved = self::resolveOptionalString($field, $ini, $cliOverrides);

        if ($resolved === null || trim($resolved) === '') {
            throw new InvalidArgumentException('Resolved zero-surprise field must not be empty: ' . $field);
        }

        return $trimValue ? trim($resolved) : $resolved;
    }

    /**
     * @param array<string, mixed> $ini
     * @param array<string, mixed> $cliOverrides
     */
    private static function resolveRequiredStringAllowEmpty(string $field, array $ini, array $cliOverrides): string
    {
        if (array_key_exists($field, $cliOverrides)) {
            $value = $cliOverrides[$field];

            return is_array($value) ? (string) end($value) : (string) $value;
        }

        if (array_key_exists($field, $ini)) {
            return (string) $ini[$field];
        }

        throw new InvalidArgumentException('Resolved zero-surprise field is missing: ' . $field);
    }

    /**
     * @param array<string, mixed> $ini
     * @param array<string, mixed> $cliOverrides
     */
    private static function resolveDateString(string $field, string $default, array $ini, array $cliOverrides): string
    {
        $resolved = self::resolveOptionalString($field, $ini, $cliOverrides);
        $value = $resolved !== null && trim($resolved) !== '' ? trim($resolved) : $default;

        self::assertDateString($value, $field);

        return $value;
    }

    /**
     * @param array<string, mixed> $ini
     * @param array<string, mixed> $cliOverrides
     */
    private static function resolvePositiveInt(string $field, int $default, array $ini, array $cliOverrides): int
    {
        $raw = self::resolveNumericRaw($field, $ini, $cliOverrides);

        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }

        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1) {
            $value = (int) trim($raw);
        } else {
            throw new InvalidArgumentException('Resolved zero-surprise field must be a positive integer: ' . $field);
        }

        if ($value <= 0) {
            throw new InvalidArgumentException('Resolved zero-surprise field must be a positive integer: ' . $field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $ini
     * @param array<string, mixed> $cliOverrides
     */
    private static function resolveNonNegativeInt(string $field, int $default, array $ini, array $cliOverrides): int
    {
        $raw = self::resolveNumericRaw($field, $ini, $cliOverrides);

        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }

        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1) {
            $value = (int) trim($raw);
        } else {
            throw new InvalidArgumentException(
                'Resolved zero-surprise field must be a non-negative integer: ' . $field,
            );
        }

        if ($value < 0) {
            throw new InvalidArgumentException(
                'Resolved zero-surprise field must be a non-negative integer: ' . $field,
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $ini
     * @param array<string, mixed> $cliOverrides
     */
    private static function resolveOptionalString(string $field, array $ini, array $cliOverrides): ?string
    {
        if (array_key_exists($field, $cliOverrides)) {
            $value = $cliOverrides[$field];

            return is_array($value) ? (string) end($value) : (string) $value;
        }

        if (array_key_exists($field, $ini)) {
            return (string) $ini[$field];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ini
     * @param array<string, mixed> $cliOverrides
     */
    private static function resolveNumericRaw(string $field, array $ini, array $cliOverrides): mixed
    {
        if (array_key_exists($field, $cliOverrides)) {
            return $cliOverrides[$field];
        }

        return $ini[$field] ?? null;
    }

    private static function assertDateString(string $value, string $field): void
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field . ' must use format YYYY-MM-DD.');
        }
    }
}

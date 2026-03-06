<?php

declare(strict_types=1);

namespace ReleaseGate;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ZeroSurpriseProfile
{
    public const DEFAULT_PROFILE = 'school-day-default';

    /**
     * @return array{
     *   name:string,
     *   timezone:string,
     *   window:array<string, mixed>,
     *   booking_search_days:int,
     *   retry_count:int,
     *   max_pdf_duration_ms:int
     * }
     */
    public static function loadDefinition(string $profileName): array
    {
        $trimmedName = trim($profileName);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Profile name must not be empty.');
        }

        $profiles = self::loadProfilesConfig();
        $definition = $profiles[$trimmedName] ?? null;

        if (!is_array($definition)) {
            throw new InvalidArgumentException('Unknown zero-surprise profile: ' . $trimmedName);
        }

        $timezone = trim((string) ($definition['timezone'] ?? ''));
        if ($timezone === '') {
            throw new InvalidArgumentException('Profile must define a non-empty timezone: ' . $trimmedName);
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Profile timezone is invalid: ' . $timezone, 0, $exception);
        }

        $window = $definition['window'] ?? null;
        if (!is_array($window)) {
            throw new InvalidArgumentException('Profile window definition is missing: ' . $trimmedName);
        }

        return [
            'name' => $trimmedName,
            'timezone' => $timezone,
            'window' => self::normalizeWindow($window, $trimmedName),
            'booking_search_days' => self::normalizePositiveInt(
                $definition['booking_search_days'] ?? null,
                'booking_search_days',
                $trimmedName,
            ),
            'retry_count' => self::normalizeNonNegativeInt(
                $definition['retry_count'] ?? null,
                'retry_count',
                $trimmedName,
            ),
            'max_pdf_duration_ms' => self::normalizePositiveInt(
                $definition['max_pdf_duration_ms'] ?? null,
                'max_pdf_duration_ms',
                $trimmedName,
            ),
        ];
    }

    /**
     * @return array{start_date:string,end_date:string}
     */
    public static function resolveWindow(
        array $profileDefinition,
        ?DateTimeImmutable $nowUtc = null,
        ?DateTimeZone $timezoneOverride = null,
    ): array {
        $window = $profileDefinition['window'] ?? null;
        if (!is_array($window)) {
            throw new InvalidArgumentException('Profile definition must contain window configuration.');
        }

        $timezoneName = (string) ($profileDefinition['timezone'] ?? '');
        if ($timezoneOverride === null) {
            try {
                $timezoneOverride = new DateTimeZone($timezoneName);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException('Profile timezone is invalid: ' . $timezoneName, 0, $exception);
            }
        }

        $type = (string) ($window['type'] ?? '');

        if ($type === 'trailing_days') {
            $days = self::normalizePositiveInt(
                $window['days'] ?? null,
                'window.days',
                (string) ($profileDefinition['name'] ?? 'profile'),
            );
            $now = ($nowUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($timezoneOverride);

            return [
                'start_date' => $now->sub(new DateInterval('P' . $days . 'D'))->format('Y-m-d'),
                'end_date' => $now->format('Y-m-d'),
            ];
        }

        if ($type === 'explicit_range') {
            $startDate = trim((string) ($window['start_date'] ?? ''));
            $endDate = trim((string) ($window['end_date'] ?? ''));
            self::assertDateString($startDate, 'window.start_date');
            self::assertDateString($endDate, 'window.end_date');

            if ($startDate > $endDate) {
                throw new InvalidArgumentException('Profile explicit_range requires start_date <= end_date.');
            }

            return [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];
        }

        throw new InvalidArgumentException('Unsupported profile window type: ' . $type);
    }

    /**
     * @return array{
     *   name:string,
     *   timezone:string,
     *   start_date:string,
     *   end_date:string,
     *   booking_search_days:int,
     *   retry_count:int,
     *   max_pdf_duration_ms:int,
     *   window:array<string, mixed>
     * }
     */
    public static function resolve(
        string $profileName,
        ?DateTimeImmutable $nowUtc = null,
        ?string $timezoneOverride = null,
    ): array {
        $definition = self::loadDefinition($profileName);
        $timezoneName =
            $timezoneOverride !== null && trim($timezoneOverride) !== ''
                ? trim($timezoneOverride)
                : $definition['timezone'];

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Resolved profile timezone is invalid: ' . $timezoneName, 0, $exception);
        }

        $window = self::resolveWindow($definition, $nowUtc, $timezone);

        return [
            'name' => $definition['name'],
            'timezone' => $timezoneName,
            'start_date' => $window['start_date'],
            'end_date' => $window['end_date'],
            'booking_search_days' => $definition['booking_search_days'],
            'retry_count' => $definition['retry_count'],
            'max_pdf_duration_ms' => $definition['max_pdf_duration_ms'],
            'window' => $definition['window'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadProfilesConfig(): array
    {
        $configPath = dirname(__DIR__) . '/config/zero_surprise_profiles.php';
        $profiles = require $configPath;

        if (!is_array($profiles)) {
            throw new RuntimeException('Zero-surprise profiles config must return an array: ' . $configPath);
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $window
     * @return array<string, mixed>
     */
    private static function normalizeWindow(array $window, string $profileName): array
    {
        $type = trim((string) ($window['type'] ?? ''));
        if ($type === '') {
            throw new InvalidArgumentException('Profile window.type must not be empty: ' . $profileName);
        }

        if ($type === 'trailing_days') {
            return [
                'type' => $type,
                'days' => self::normalizePositiveInt($window['days'] ?? null, 'window.days', $profileName),
            ];
        }

        if ($type === 'explicit_range') {
            $startDate = trim((string) ($window['start_date'] ?? ''));
            $endDate = trim((string) ($window['end_date'] ?? ''));
            self::assertDateString($startDate, 'window.start_date');
            self::assertDateString($endDate, 'window.end_date');

            if ($startDate > $endDate) {
                throw new InvalidArgumentException(
                    'Profile explicit_range requires start_date <= end_date: ' . $profileName,
                );
            }

            return [
                'type' => $type,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];
        }

        throw new InvalidArgumentException('Unsupported profile window type: ' . $type);
    }

    private static function normalizePositiveInt(mixed $value, string $field, string $profileName): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $normalized = (int) trim($value);
        } else {
            throw new InvalidArgumentException(
                sprintf('Profile %s.%s must be a positive integer.', $profileName, $field),
            );
        }

        if ($normalized <= 0) {
            throw new InvalidArgumentException(
                sprintf('Profile %s.%s must be a positive integer.', $profileName, $field),
            );
        }

        return $normalized;
    }

    private static function normalizeNonNegativeInt(mixed $value, string $field, string $profileName): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $normalized = (int) trim($value);
        } else {
            throw new InvalidArgumentException(
                sprintf('Profile %s.%s must be a non-negative integer.', $profileName, $field),
            );
        }

        if ($normalized < 0) {
            throw new InvalidArgumentException(
                sprintf('Profile %s.%s must be a non-negative integer.', $profileName, $field),
            );
        }

        return $normalized;
    }

    private static function assertDateString(string $value, string $field): void
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field . ' must use format YYYY-MM-DD.');
        }
    }
}

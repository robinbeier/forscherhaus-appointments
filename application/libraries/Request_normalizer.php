<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.8.0
 * ---------------------------------------------------------------------------- */

/**
 * Request normalization helper.
 *
 * Provides typed, compatibility-first normalization for controller request payloads.
 *
 * @package Libraries
 */
class Request_normalizer
{
    /**
     * Normalize a scalar string value.
     *
     * @param mixed $value
     * @param string|null $default
     * @param bool $optional
     */
    public function normalizeString(mixed $value, ?string $default = null, bool $optional = true): ?string
    {
        if (is_array($value) || is_object($value)) {
            return $default;
        }

        if ($value === null) {
            return $default;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return $optional ? null : $default ?? '';
        }

        return $normalized;
    }

    /**
     * Normalize integer-like input.
     *
     * @param mixed $value
     * @param int|null $default
     */
    public function normalizeInt(mixed $value, ?int $default = null): ?int
    {
        $normalized = $this->toInt($value);

        return $normalized ?? $default;
    }

    /**
     * Normalize positive integer-like input.
     *
     * @param mixed $value
     * @param int|null $default
     */
    public function normalizePositiveInt(mixed $value, ?int $default = null): ?int
    {
        $normalized = $this->toInt($value);

        if ($normalized === null || $normalized <= 0) {
            return $default;
        }

        return $normalized;
    }

    /**
     * Normalize boolean-like input.
     *
     * @param mixed $value
     * @param bool $default
     */
    public function normalizeBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }

            if ($value === 0) {
                return false;
            }

            return $default;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if ($normalized === '') {
                return $default;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Normalize strict Y-m-d date input.
     *
     * @param mixed $value
     * @param string|null $default
     */
    public function normalizeDateYmd(mixed $value, ?string $default = null): ?string
    {
        $candidate = $this->normalizeString($value, null, true);

        if ($candidate === null) {
            return $default;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

        if (!$date || $date->format('Y-m-d') !== $candidate) {
            return $default;
        }

        return $candidate;
    }

    /**
     * Normalize a scalar/list string input to ordered string list.
     *
     * @param mixed $value
     *
     * @return array<int, string>
     */
    public function normalizeStringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }

            $stringItem = trim((string) $item);

            if ($stringItem === '') {
                continue;
            }

            $normalized[] = $stringItem;
        }

        return $normalized;
    }

    /**
     * Normalize a scalar/list input to unique positive integer list.
     *
     * @param mixed $value
     *
     * @return array<int, int>
     */
    public function normalizePositiveIntList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $normalized = [];
        $seen = [];

        foreach ($value as $item) {
            $candidate = $this->normalizePositiveInt($item, null);

            if ($candidate === null) {
                continue;
            }

            if (isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;
            $normalized[] = $candidate;
        }

        return $normalized;
    }

    /**
     * Normalize an associative array payload.
     *
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    public function normalizeAssocArray(mixed $value): array
    {
        if (!is_array($value) || !$this->isAssoc($value)) {
            return [];
        }

        return $value;
    }

    private function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '' || !preg_match('/^-?\d+$/', $trimmed)) {
                return null;
            }

            return (int) $trimmed;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssoc(array $value): bool
    {
        $keys = array_keys($value);

        return array_keys($keys) !== $keys;
    }
}

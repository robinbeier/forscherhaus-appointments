<?php

declare(strict_types=1);

namespace CiContract;

final class BookingWriteReportSanitizer
{
    private const SENSITIVE_EXACT_KEYS = [
        'id',
        'primary_customer',
        'first_name',
        'last_name',
        'email',
        'mobile_number',
        'phone_number',
        'address',
        'city',
        'state',
        'zip_code',
        'custom_field_1',
        'custom_field_2',
        'custom_field_3',
        'custom_field_4',
        'custom_field_5',
    ];

    private const SENSITIVE_KEY_FRAGMENTS = [
        'appointment_id',
        'customer_id',
        'provider_id',
        'user_id',
        'hash',
        'authority',
        'token',
        'password',
        'secret',
        'cookie',
        'session',
        'nonce',
    ];

    /**
     * Remove capability, authority, credential, and personal payload values
     * before contract state becomes a CI artifact.
     */
    public static function sanitize(mixed $value, ?string $key = null): mixed
    {
        $normalizedKey = strtolower((string) $key);

        if (self::isSensitiveKey($normalizedKey)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = self::sanitize($childValue, (string) $childKey);
            }

            return $sanitized;
        }

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace('#(booking/reschedule|booking_cancellation/of)/[^/?\s"]+#', '$1/[redacted]', $value);
    }

    private static function isSensitiveKey(string $key): bool
    {
        if (in_array($key, self::SENSITIVE_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

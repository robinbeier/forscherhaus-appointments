<?php

declare(strict_types=1);

namespace CiContract;

final class BookedSlotMatcher
{
    /**
     * @param array<string, mixed> $appointment
     */
    public static function matches(array $appointment, int $providerId, int $serviceId, string $startDateTime): bool
    {
        $start = trim((string) ($appointment['start'] ?? ''));
        $status = trim((string) ($appointment['status'] ?? ''));
        $appointmentProviderId = self::resolveOptionalPositiveInt(
            $appointment['providerId'] ?? ($appointment['id_users_provider'] ?? null),
        );
        $appointmentServiceId = self::resolveOptionalPositiveInt(
            $appointment['serviceId'] ?? ($appointment['id_services'] ?? null),
        );
        $isUnavailabilityRaw = $appointment['isUnavailability'] ?? false;
        $isUnavailability = $isUnavailabilityRaw === true || (int) $isUnavailabilityRaw === 1;

        if ($start !== $startDateTime) {
            return false;
        }

        if ($appointmentProviderId !== $providerId || $appointmentServiceId !== $serviceId) {
            return false;
        }

        if ($status !== 'Booked') {
            return false;
        }

        return !$isUnavailability;
    }

    private static function resolveOptionalPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        $parsed = (int) $normalized;

        return $parsed > 0 ? $parsed : null;
    }
}

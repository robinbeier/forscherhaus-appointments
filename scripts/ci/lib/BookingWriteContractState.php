<?php

declare(strict_types=1);

namespace CiContract;

final class BookingWriteContractState
{
    /**
     * @param array<string, mixed> $state
     * @return array{start: string, end: string}
     */
    public static function requirePrimaryAppointmentWindow(array $state): array
    {
        $start = trim((string) ($state['primary_appointment_start'] ?? ''));
        $end = trim((string) ($state['primary_appointment_end'] ?? ''));

        if ($start === '' || $end === '') {
            throw new ContractAssertionException(
                'Primary appointment window state is required before running the unknown-hash cancellation check.',
            );
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }
}

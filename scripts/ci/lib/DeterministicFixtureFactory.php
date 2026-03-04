<?php

declare(strict_types=1);

namespace CiContract;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateHttpClient;

final class DeterministicFixtureFactory
{
    private int $markerCounter = 0;

    public function __construct(
        private readonly string $runId,
        private readonly int $bookingSearchDays = 14,
        private readonly string $timezone = 'UTC',
    ) {
    }

    public static function create(int $bookingSearchDays = 14): self
    {
        return new self(self::generateRunId(), max(1, $bookingSearchDays), date_default_timezone_get() ?: 'UTC');
    }

    public static function generateRunId(?DateTimeImmutable $now = null): string
    {
        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rand = bin2hex(random_bytes(2));

        return sprintf('ci-write-%s-%s', $instant->format('Ymd\THis\Z'), $rand);
    }

    public function runId(): string
    {
        return $this->runId;
    }

    /**
     * @return array<string, mixed>
     */
    public function createApiCustomerPayload(array $overrides = []): array
    {
        $marker = $this->nextMarker('customer');

        $payload = [
            'firstName' => 'CI',
            'lastName' => strtoupper(substr($marker, -6)),
            'email' => $this->markerEmail($marker),
            'phone' => '+49123456789',
            'address' => 'CI Write Contract',
            'city' => 'Berlin',
            'zip' => '10115',
            'timezone' => $this->timezone,
            'language' => 'english',
            'notes' => 'run:' . $this->runId,
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public function createBookingCustomerPayload(array $overrides = []): array
    {
        $marker = $this->nextMarker('booking-customer');

        $payload = [
            'first_name' => 'CI',
            'last_name' => strtoupper(substr($marker, -6)),
            'email' => $this->markerEmail($marker),
            'phone_number' => '+49123456789',
            'address' => 'CI Write Contract',
            'city' => 'Berlin',
            'zip_code' => '10115',
            'timezone' => $this->timezone,
            'notes' => 'run:' . $this->runId,
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public function createApiAppointmentPayload(
        int $customerId,
        int $providerId,
        int $serviceId,
        string $startDateTime,
        ?string $endDateTime = null,
        array $overrides = [],
    ): array {
        $marker = $this->nextMarker('appointment');
        $resolvedEndDateTime = $endDateTime ?? $this->deriveEndDateTime($startDateTime);

        $payload = [
            'start' => $startDateTime,
            'end' => $resolvedEndDateTime,
            'location' => 'CI-WRITE-' . strtoupper(substr($marker, -8)),
            'notes' => 'run:' . $this->runId,
            'status' => 'Booked',
            'customerId' => $customerId,
            'providerId' => $providerId,
            'serviceId' => $serviceId,
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public function createBookingAppointmentPayload(
        int $providerId,
        int $serviceId,
        string $startDateTime,
        ?string $endDateTime = null,
        array $overrides = [],
    ): array {
        $marker = $this->nextMarker('booking-appointment');
        $resolvedEndDateTime = $endDateTime ?? $this->deriveEndDateTime($startDateTime);

        $payload = [
            'start_datetime' => $startDateTime,
            'end_datetime' => $resolvedEndDateTime,
            'id_services' => $serviceId,
            'id_users_provider' => $providerId,
            'location' => 'CI-WRITE-' . strtoupper(substr($marker, -8)),
            'notes' => 'run:' . $this->runId,
            'color' => '',
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public function extractBookingBootstrap(string $html): array
    {
        $marker = 'const vars =';
        $markerPosition = strpos($html, $marker);

        if ($markerPosition === false) {
            throw new GateAssertionException('Could not locate booking bootstrap marker "const vars =".');
        }

        $braceStart = strpos($html, '{', $markerPosition);

        if ($braceStart === false) {
            throw new GateAssertionException('Could not locate opening JSON brace after booking bootstrap marker.');
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);

        for ($index = $braceStart; $index < $length; $index++) {
            $char = $html[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $json = substr($html, $braceStart, $index - $braceStart + 1);
                    $decoded = GateAssertions::decodeJson($json, 'booking bootstrap vars');

                    if (!is_array($decoded)) {
                        throw new GateAssertionException(
                            'Booking bootstrap vars payload must decode to a JSON object.',
                        );
                    }

                    return $decoded;
                }
            }
        }

        throw new GateAssertionException('Could not parse booking bootstrap vars JSON block.');
    }

    /**
     * @param array<string, mixed> $bootstrap
     * @return array<int, array{provider_id:int,service_id:int}>
     */
    public function resolveProviderServicePairs(array $bootstrap): array
    {
        $services = $bootstrap['available_services'] ?? null;
        $providers = $bootstrap['available_providers'] ?? null;

        if (!is_array($services) || $services === []) {
            throw new GateAssertionException('booking bootstrap "available_services" must be a non-empty array.');
        }

        if (!is_array($providers) || $providers === []) {
            throw new GateAssertionException('booking bootstrap "available_providers" must be a non-empty array.');
        }

        $serviceIds = [];
        foreach ($services as $index => $service) {
            if (!is_array($service)) {
                throw new GateAssertionException('available_services[' . $index . '] must be an object.');
            }

            $serviceIds[] = $this->toPositiveInt($service['id'] ?? null, 'available_services[' . $index . '].id');
        }

        $serviceLookup = array_fill_keys(array_values(array_unique($serviceIds)), true);
        $pairs = [];
        $seen = [];

        foreach ($providers as $providerIndex => $provider) {
            if (!is_array($provider)) {
                throw new GateAssertionException('available_providers[' . $providerIndex . '] must be an object.');
            }

            $providerId = $this->toPositiveInt(
                $provider['id'] ?? null,
                'available_providers[' . $providerIndex . '].id',
            );
            $providerServices = $provider['services'] ?? null;

            if (!is_array($providerServices)) {
                continue;
            }

            foreach ($providerServices as $serviceIndex => $providerServiceRaw) {
                $serviceId = $this->toPositiveInt(
                    $providerServiceRaw,
                    'available_providers[' . $providerIndex . '].services[' . $serviceIndex . ']',
                );

                if (!isset($serviceLookup[$serviceId])) {
                    continue;
                }

                $pairKey = $providerId . ':' . $serviceId;
                if (isset($seen[$pairKey])) {
                    continue;
                }

                $pairs[] = [
                    'provider_id' => $providerId,
                    'service_id' => $serviceId,
                ];
                $seen[$pairKey] = true;
            }
        }

        if ($pairs === []) {
            throw new GateAssertionException(
                'Could not resolve any provider/service pair from booking bootstrap data.',
            );
        }

        usort(
            $pairs,
            static fn(array $a, array $b): int => [$a['provider_id'], $a['service_id']] <=> [
                $b['provider_id'],
                $b['service_id'],
            ],
        );

        return $pairs;
    }

    /**
     * @param array<int, array{provider_id:int,service_id:int}> $providerServicePairs
     * @return array{
     *   provider_id:int,
     *   service_id:int,
     *   date:string,
     *   hour:string,
     *   start_datetime:string,
     *   end_datetime:string,
     *   mode:string,
     *   hours_count:int
     * }
     */
    public function resolveBookableSlot(GateHttpClient $client, int $httpTimeout, array $providerServicePairs): array
    {
        if ($providerServicePairs === []) {
            throw new GateAssertionException('Provider/service pairs list is empty.');
        }

        $startDate = new DateTimeImmutable('tomorrow', new DateTimeZone(date_default_timezone_get() ?: 'UTC'));

        for ($offset = 0; $offset < $this->bookingSearchDays; $offset++) {
            $candidateDate = $startDate->modify('+' . $offset . ' day')->format('Y-m-d');

            foreach ($providerServicePairs as $pair) {
                $hours = $this->fetchBookingAvailableHours(
                    $client,
                    $httpTimeout,
                    $pair['provider_id'],
                    $pair['service_id'],
                    $candidateDate,
                );

                if ($hours === []) {
                    continue;
                }

                $hour = $hours[0];
                $startDateTime = $candidateDate . ' ' . $hour . ':00';

                return [
                    'provider_id' => $pair['provider_id'],
                    'service_id' => $pair['service_id'],
                    'date' => $candidateDate,
                    'hour' => $hour,
                    'start_datetime' => $startDateTime,
                    'end_datetime' => $this->deriveEndDateTime($startDateTime),
                    'mode' => 'searched_window',
                    'hours_count' => count($hours),
                ];
            }
        }

        throw new GateAssertionException(
            sprintf(
                'No booking hours available across %d provider/service pairs in %d-day window.',
                count($providerServicePairs),
                $this->bookingSearchDays,
            ),
        );
    }

    public function markerMatches(?string $value): bool
    {
        return is_string($value) && str_contains($value, $this->runId);
    }

    private function markerEmail(string $marker): string
    {
        $local = preg_replace('/[^a-z0-9]+/', '-', strtolower($marker)) ?: 'ci-write';

        return $local . '@example.test';
    }

    private function nextMarker(string $prefix): string
    {
        $this->markerCounter++;

        return sprintf('%s-%s-%02d', $prefix, $this->runId, $this->markerCounter);
    }

    private function deriveEndDateTime(string $startDateTime): string
    {
        $start = new DateTimeImmutable($startDateTime, new DateTimeZone($this->timezone));
        $end = $start->add(new DateInterval('PT30M'));

        return $end->format('Y-m-d H:i:s');
    }

    /**
     * @return array<int, string>
     */
    private function fetchBookingAvailableHours(
        GateHttpClient $client,
        int $httpTimeout,
        int $providerId,
        int $serviceId,
        string $date,
    ): array {
        $response = $client->post(
            'booking/get_available_hours',
            [
                'provider_id' => $providerId,
                'service_id' => $serviceId,
                'selected_date' => $date,
                'manage_mode' => 0,
            ],
            $httpTimeout,
            true,
        );

        GateAssertions::assertStatus($response->statusCode, 200, 'POST /booking/get_available_hours');
        $decoded = GateAssertions::decodeJson($response->body, 'POST /booking/get_available_hours');

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new GateAssertionException('POST /booking/get_available_hours payload must be a JSON array.');
        }

        $hours = [];
        foreach ($decoded as $index => $hour) {
            if (!is_string($hour) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hour) !== 1) {
                throw new GateAssertionException(
                    'POST /booking/get_available_hours contains invalid hour at index ' . $index . '.',
                );
            }

            $hours[] = $hour;
        }

        return $hours;
    }

    private function toPositiveInt(mixed $value, string $context): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);
        } else {
            throw new GateAssertionException($context . ' must be a positive integer.');
        }

        if ($parsed <= 0) {
            throw new GateAssertionException($context . ' must be a positive integer.');
        }

        return $parsed;
    }
}

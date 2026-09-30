<?php

declare(strict_types=1);

namespace CiContract;

use ReleaseGate\GateAssertionException;

final class BookingEmailFieldPolicy
{
    /**
     * @param callable(string, string, bool, array<int, int>): string $request
     */
    public static function resolve(bool $syntheticCanary, callable $request): bool
    {
        if ($syntheticCanary) {
            return true;
        }

        $body = $request('GET', 'api/v1/settings/display_email', true, [200]);
        if (!is_string($body)) {
            throw new GateAssertionException('GET /api/v1/settings/display_email returned a malformed body.');
        }

        try {
            $setting = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new GateAssertionException(
                'GET /api/v1/settings/display_email returned invalid JSON.',
                previous: $exception,
            );
        }

        if (
            !is_array($setting) ||
            count($setting) !== 2 ||
            !array_key_exists('name', $setting) ||
            !array_key_exists('value', $setting) ||
            $setting['name'] !== 'display_email' ||
            !is_string($setting['value'])
        ) {
            throw new GateAssertionException(
                'GET /api/v1/settings/display_email returned an unknown or malformed setting.',
            );
        }

        if ($setting['value'] === '1') {
            return true;
        }

        if ($setting['value'] === '0') {
            return false;
        }

        throw new GateAssertionException('GET /api/v1/settings/display_email returned an unsupported value.');
    }
}

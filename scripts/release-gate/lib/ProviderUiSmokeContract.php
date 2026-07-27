<?php

declare(strict_types=1);

namespace ReleaseGate;

final class ProviderUiSmokeContract
{
    public const USERNAME = '__ea_provider_ui_smoke_v1';

    public const FIXTURE_KEY = 'prod-provider-ui-smoke-v1';

    public const PROVIDER_FIRST_NAME = 'Synthetic';

    public const PROVIDER_LAST_NAME = 'Provider UI Smoke V1';

    public const PROVIDER_EMAIL = 'provider-ui-smoke-v1@synthetic.invalid';

    public const CUSTOMER_FIRST_NAME = 'Synthetic';

    public const CUSTOMER_LAST_NAME = 'Parent UI Smoke V1';

    public const CUSTOMER_EMAIL = 'customer-provider-ui-smoke-v1@synthetic.invalid';

    public const CUSTOMER_PHONE = '0000000000';

    public const CUSTOMER_NOTE_SENTINEL = 'PROD_PROVIDER_UI_SMOKE_V1_PRIVATE_NOTE_SENTINEL';

    public const BOOKED_NOTE_SENTINEL = '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_BOOKED_INSIDE__';

    public const CANCELLED_NOTE_SENTINEL = '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_CANCELLED_INSIDE__';

    public const OUTSIDE_NOTE_SENTINEL = '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_BOOKED_OUTSIDE__';

    public const PRIMARY_START_DATE = '2099-02-12';

    public const PRIMARY_END_DATE = '2099-02-12';

    public const BOOKED_START_TIME = '10:00';

    public const BOOKED_END_TIME = '10:30';

    public const EMPTY_START_DATE = '2099-04-01';

    public const EMPTY_END_DATE = '2099-04-02';

    public const RESTORE_START_DATE = '2099-02-01';

    public const RESTORE_END_DATE = '2099-02-28';

    /**
     * The provider dashboard must never expose any of these integration or
     * customer-filter values through window.vars.
     *
     * @var list<string>
     */
    public const FORBIDDEN_SCRIPT_VAR_KEYS = [
        'customer_filter_providers',
        'google_client_id',
        'google_client_secret',
        'google_token',
        'google_calendar',
        'google_calendar_id',
        'google_sync',
        'caldav_url',
        'caldav_username',
        'caldav_password',
        'caldav_calendar',
        'caldav_calendar_id',
        'caldav_sync',
    ];

    private function __construct() {}

    /**
     * Keep the daemon session name short enough for macOS AF_UNIX socket limits.
     */
    public static function buildBrowserSessionId(): string
    {
        return 'pui-' . bin2hex(random_bytes(4));
    }

    /**
     * Decode only the JSON object embedded into the delivered window.vars script.
     *
     * Language keys such as "add_to_google_calendar" may legitimately occur later
     * in the response and must not be mistaken for provider integration settings.
     *
     * @return array<string, mixed>
     */
    public static function extractScriptVars(string $html): array
    {
        $marker = 'const vars =';
        $markerPosition = strpos($html, $marker);

        if ($markerPosition === false) {
            throw new \RuntimeException('Provider UI smoke response is missing the script-vars marker.');
        }

        $braceStart = strpos($html, '{', $markerPosition);

        if ($braceStart === false) {
            throw new \RuntimeException('Provider UI smoke response is missing the script-vars object.');
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);

        for ($index = $braceStart; $index < $length; $index++) {
            $character = $html[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($character === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
                continue;
            }

            if ($character === '{') {
                $depth++;
                continue;
            }

            if ($character !== '}') {
                continue;
            }

            $depth--;

            if ($depth !== 0) {
                continue;
            }

            $json = substr($html, $braceStart, $index - $braceStart + 1);

            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('Provider UI smoke script-vars JSON is invalid.', 0, $exception);
            }

            if (!is_array($decoded)) {
                throw new \RuntimeException('Provider UI smoke script-vars payload is not an object.');
            }

            return $decoded;
        }

        throw new \RuntimeException('Provider UI smoke script-vars object is incomplete.');
    }

    /**
     * @return list<string>
     */
    public static function expectedDateVariants(string $isoDate): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);

        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $isoDate) {
            return [];
        }

        return array_values(array_unique([$date->format('d/m/Y'), $date->format('m/d/Y'), $date->format('Y/m/d')]));
    }

    /**
     * @return list<string>
     */
    public static function expectedTimeVariants(string $militaryTime): array
    {
        $time = \DateTimeImmutable::createFromFormat('!H:i', $militaryTime);

        if (!$time instanceof \DateTimeImmutable || $time->format('H:i') !== $militaryTime) {
            return [];
        }

        return array_values(array_unique([$time->format('H:i'), strtolower($time->format('g:i a'))]));
    }

    /**
     * @return list<string>
     */
    public static function forbiddenPdfFragments(): array
    {
        return [
            self::CUSTOMER_EMAIL,
            self::CUSTOMER_PHONE,
            self::CUSTOMER_NOTE_SENTINEL,
            self::BOOKED_NOTE_SENTINEL,
            self::CANCELLED_NOTE_SENTINEL,
            self::OUTSIDE_NOTE_SENTINEL,
            self::PROVIDER_EMAIL,
        ];
    }
}

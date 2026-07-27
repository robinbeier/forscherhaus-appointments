<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * ---------------------------------------------------------------------------- */

/**
 * Fail-closed access policy for the reserved production provider UI smoke identity.
 *
 * The smoke account is intentionally more restricted than a regular provider. During
 * its short activation lease it can only reach its own provider dashboard and the two
 * provider PDF exports. Outside an active lease it cannot authenticate at all.
 */
final class Provider_ui_smoke_access_policy
{
    public const USERNAME = '__ea_provider_ui_smoke_v1';

    public const FIXTURE_KEY = 'prod-provider-ui-smoke-v1';

    public const DORMANT_ROLE_SLUG = 'provider-ui-smoke-dormant';

    public const DORMANT_NOTES = 'fh-provider-ui-smoke:v1:dormant';

    public const ACTIVE_NOTES_PREFIX = 'fh-provider-ui-smoke:v1:active:';

    public const SERVICE_NAME = '__EA_PROVIDER_UI_SMOKE_V1_SERVICE__';

    public const SERVICE_DESCRIPTION = '__EA_PROVIDER_UI_SMOKE_V1_SERVICE_DESCRIPTION__';

    public const MAX_LEASE_SECONDS = 600;

    /**
     * Determine whether any supported authentication surface names the reserved identity.
     */
    public static function isReservedIdentity(
        ?string $session_username,
        ?string $login_username,
        ?string $basic_auth_username,
    ): bool {
        return self::isReservedUsername($session_username) ||
            self::isReservedUsername($login_username) ||
            self::isReservedUsername($basic_auth_username);
    }

    /**
     * Match the database's case-insensitive PAD SPACE semantics for the reserved ASCII identity.
     *
     * MariaDB ignores trailing ASCII spaces when comparing the VARCHAR username. The request
     * and session checks must therefore classify the same padded spellings as reserved.
     */
    public static function isReservedUsername(?string $username): bool
    {
        return is_string($username) && strcasecmp(rtrim($username, ' '), self::USERNAME) === 0;
    }

    /**
     * Determine whether a routed request is allowed for an authenticated smoke session.
     */
    public static function isAllowedRoute(string $controller, string $method, string $http_method): bool
    {
        $route = strtolower($controller) . '::' . strtolower($method);
        $verb = strtoupper($http_method);

        return match ($route) {
            'dashboard::index' => $verb === 'GET',
            'dashboard::provider_metrics' => $verb === 'POST',
            'dashboard_export::provider_parent_appointments_pdf' => $verb === 'GET',
            'dashboard_export::provider_preparation_pdf' => $verb === 'GET',
            'logout::index' => $verb === 'GET',
            default => false,
        };
    }

    /**
     * Logout stays available after expiry or demotion so a captured browser can clear its session.
     */
    public static function isLogoutRoute(string $controller, string $method, string $http_method): bool
    {
        return strtolower($controller) === 'logout' &&
            strtolower($method) === 'index' &&
            strtoupper($http_method) === 'GET';
    }

    /**
     * Build the exact versioned active-state marker stored in users.notes.
     */
    public static function buildActiveNotes(DateTimeImmutable $issued_at, DateTimeImmutable $expires_at): string
    {
        $issued_at = $issued_at->setTimezone(new DateTimeZone('UTC'));
        $expires_at = $expires_at->setTimezone(new DateTimeZone('UTC'));
        $duration = $expires_at->getTimestamp() - $issued_at->getTimestamp();

        if ($duration < 1 || $duration > self::MAX_LEASE_SECONDS) {
            throw new InvalidArgumentException('Invalid provider UI smoke lease duration.');
        }

        return self::ACTIVE_NOTES_PREFIX .
            $issued_at->format('Y-m-d\TH:i:s\Z') .
            ':' .
            $expires_at->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Validate an exact active marker and its bounded, unexpired lease.
     */
    public static function hasActiveLease(string $notes, ?DateTimeImmutable $now = null): bool
    {
        $lease = self::parseActiveNotes($notes);

        if ($lease === null) {
            return false;
        }

        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $issued_at = $lease['issued_at'];
        $expires_at = $lease['expires_at'];
        $duration = $expires_at->getTimestamp() - $issued_at->getTimestamp();

        return $duration >= 1 &&
            $duration <= self::MAX_LEASE_SECONDS &&
            $issued_at->getTimestamp() <= $now->getTimestamp() &&
            $expires_at->getTimestamp() > $now->getTimestamp();
    }

    /**
     * Parse the exact versioned active marker.
     *
     * @return array{issued_at: DateTimeImmutable, expires_at: DateTimeImmutable}|null
     */
    public static function parseActiveNotes(string $notes): ?array
    {
        $timestamp_pattern = '(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)';
        $pattern =
            '/^' . preg_quote(self::ACTIVE_NOTES_PREFIX, '/') . $timestamp_pattern . ':' . $timestamp_pattern . '$/';

        if (!preg_match($pattern, $notes, $matches)) {
            return null;
        }

        $timezone = new DateTimeZone('UTC');
        $issued_at = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $matches[1], $timezone);
        $expires_at = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $matches[2], $timezone);

        if (
            !$issued_at ||
            !$expires_at ||
            $issued_at->format('Y-m-d\TH:i:s\Z') !== $matches[1] ||
            $expires_at->format('Y-m-d\TH:i:s\Z') !== $matches[2]
        ) {
            return null;
        }

        return [
            'issued_at' => $issued_at,
            'expires_at' => $expires_at,
        ];
    }
}

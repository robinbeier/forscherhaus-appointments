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
 * Fail-closed access policy for the reserved Customers UI smoke identities.
 */
final class Customers_ui_smoke_access_policy
{
    public const FIXTURE_KEY = 'prod-customers-ui-smoke-v1';

    public const DORMANT_ROLE_SLUG = 'customers-ui-smoke-dormant';

    public const ACTIVE_NOTES_PREFIX = 'fh-customers-ui-smoke:v1:active:';

    public const DORMANT_NOTES_PREFIX = 'fh-customers-ui-smoke:v1:dormant:';

    public const SEARCH_MARKER = '__EA_CUSTOMERS_UI_SMOKE_V1_EMPTY_SEARCH__';

    public const MAX_LEASE_SECONDS = 600;

    /**
     * @var array<string, string>
     */
    public const USERNAMES_BY_ROLE = [
        DB_SLUG_ADMIN => '__ea_customers_ui_smoke_admin_v1',
        DB_SLUG_PROVIDER => '__ea_customers_ui_smoke_provider_v1',
        DB_SLUG_SECRETARY => '__ea_customers_ui_smoke_secretary_v1',
        DB_SLUG_CUSTOMER => '__ea_customers_ui_smoke_customer_v1',
    ];

    /**
     * @return list<string>
     */
    public static function authorizedRoles(): array
    {
        return [DB_SLUG_ADMIN, DB_SLUG_PROVIDER, DB_SLUG_SECRETARY];
    }

    public static function isReservedUsername(?string $username): bool
    {
        return self::roleForUsername($username) !== null;
    }

    public static function roleForUsername(?string $username): ?string
    {
        if (!is_string($username)) {
            return null;
        }

        $normalized = rtrim($username, ' ');

        foreach (self::USERNAMES_BY_ROLE as $role => $reservedUsername) {
            if (strcasecmp($normalized, $reservedUsername) === 0) {
                return $role;
            }
        }

        return null;
    }

    public static function isAuthorizedRole(string $role): bool
    {
        return in_array($role, self::authorizedRoles(), true);
    }

    public static function isAllowedRoute(string $controller, string $method, string $httpMethod): bool
    {
        $route = strtolower($controller) . '::' . strtolower($method);
        $verb = strtoupper($httpMethod);

        return match ($route) {
            'customers::index' => $verb === 'GET',
            'customers::search' => $verb === 'POST',
            'logout::index' => $verb === 'GET',
            default => false,
        };
    }

    public static function isLogoutRoute(string $controller, string $method, string $httpMethod): bool
    {
        return strtolower($controller) === 'logout' &&
            strtolower($method) === 'index' &&
            strtoupper($httpMethod) === 'GET';
    }

    public static function isSafeSearchKeyword(string $keyword): bool
    {
        return $keyword === '' || hash_equals(self::SEARCH_MARKER, $keyword);
    }

    public static function dormantNotes(string $role): string
    {
        self::assertKnownRole($role);

        return self::DORMANT_NOTES_PREFIX . $role;
    }

    public static function buildActiveNotes(
        string $role,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
    ): string {
        self::assertKnownRole($role);
        $issuedAt = $issuedAt->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $expiresAt->setTimezone(new DateTimeZone('UTC'));
        $duration = $expiresAt->getTimestamp() - $issuedAt->getTimestamp();

        if ($duration < 1 || $duration > self::MAX_LEASE_SECONDS) {
            throw new InvalidArgumentException('Invalid Customers UI smoke lease duration.');
        }

        return self::ACTIVE_NOTES_PREFIX .
            $role .
            ':' .
            $issuedAt->format('Y-m-d\TH:i:s\Z') .
            ':' .
            $expiresAt->format('Y-m-d\TH:i:s\Z');
    }

    public static function hasActiveLease(string $notes, string $role, ?DateTimeImmutable $now = null): bool
    {
        $lease = self::parseActiveNotes($notes, $role);

        if ($lease === null) {
            return false;
        }

        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $duration = $lease['expires_at']->getTimestamp() - $lease['issued_at']->getTimestamp();

        return $duration >= 1 &&
            $duration <= self::MAX_LEASE_SECONDS &&
            $lease['issued_at']->getTimestamp() <= $now->getTimestamp() &&
            $lease['expires_at']->getTimestamp() > $now->getTimestamp();
    }

    /**
     * @return array{issued_at: DateTimeImmutable, expires_at: DateTimeImmutable}|null
     */
    public static function parseActiveNotes(string $notes, string $role): ?array
    {
        if (!array_key_exists($role, self::USERNAMES_BY_ROLE)) {
            return null;
        }

        $timestampPattern = '(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)';
        $prefix = self::ACTIVE_NOTES_PREFIX . $role . ':';
        $pattern = '/^' . preg_quote($prefix, '/') . $timestampPattern . ':' . $timestampPattern . '$/';

        if (!preg_match($pattern, $notes, $matches)) {
            return null;
        }

        $timezone = new DateTimeZone('UTC');
        $issuedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $matches[1], $timezone);
        $expiresAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $matches[2], $timezone);

        if (
            !$issuedAt ||
            !$expiresAt ||
            $issuedAt->format('Y-m-d\TH:i:s\Z') !== $matches[1] ||
            $expiresAt->format('Y-m-d\TH:i:s\Z') !== $matches[2]
        ) {
            return null;
        }

        return ['issued_at' => $issuedAt, 'expires_at' => $expiresAt];
    }

    private static function assertKnownRole(string $role): void
    {
        if (!array_key_exists($role, self::USERNAMES_BY_ROLE)) {
            throw new InvalidArgumentException('Unknown Customers UI smoke role.');
        }
    }
}

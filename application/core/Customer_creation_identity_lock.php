<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Builds the opaque database lock name shared by customer creation paths.
 *
 * This helper intentionally has no application-layer dependencies so models and
 * libraries can use the same lock contract without violating architecture rules.
 */
final class Customer_creation_identity_lock
{
    private const PREFIX = 'ea:public-create:';

    private function __construct() {}

    public static function name(?string $email, string $database_name): ?string
    {
        $normalized_email = strtolower(trim((string) $email));

        if ($normalized_email === '') {
            return null;
        }

        $identity_digest = hash('sha256', $database_name . "\0" . $normalized_email);

        return self::PREFIX . substr($identity_digest, 0, 40);
    }
}

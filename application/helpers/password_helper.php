<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.0.0
 * ---------------------------------------------------------------------------- */

/**
 * Generate a hash of password string.
 *
 * For user security, all system passwords are stored in hash string into the database. Use this method to produce the
 * hashed password.
 *
 * @param string $salt Salt value for current user. This value is stored on the database and is used when generating
 * the password hashes.
 * @param string $password Given string password.
 *
 * @return string Returns the hash string of the given password.
 *
 * @throws Exception
 */
function hash_password(string $salt, string $password): string
{
    if (strlen($password) > MAX_PASSWORD_LENGTH) {
        throw new InvalidArgumentException('The provided password is too long, please use a shorter value.');
    }

    if (!MODERN_PASSWORD_WRITES) {
        return hash_legacy_password($salt, $password);
    }

    return hash_password_argon2id($password);
}

function hash_password_argon2id(string $password): string
{
    if (strlen($password) > MAX_PASSWORD_LENGTH) {
        throw new InvalidArgumentException('The provided password is too long, please use a shorter value.');
    }

    if (!defined('PASSWORD_ARGON2ID')) {
        throw new RuntimeException('Argon2id password hashing is not available.');
    }

    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 19456,
        'time_cost' => 2,
        'threads' => 1,
    ]);
}

function hash_legacy_password(string $salt, string $password): string
{
    $half = (int) (strlen($salt) / 2);
    $hash = hash('sha256', substr($salt, 0, $half) . $password . substr($salt, $half));

    for ($i = 0; $i < 100000; $i++) {
        $hash = hash('sha256', $hash);
    }

    return $hash;
}

/**
 * Verify a stored password, accepting only the historical SHA-256 format or
 * the current Argon2id format. The salt argument is legacy-only.
 */
function verify_password(string $stored_hash, string $salt, string $password): bool
{
    if (strlen($password) > MAX_PASSWORD_LENGTH) {
        return false;
    }

    if (preg_match('/\A[a-f0-9]{64}\z/', $stored_hash) === 1) {
        return hash_equals($stored_hash, hash_legacy_password($salt, $password));
    }

    if (!defined('PASSWORD_ARGON2ID') || !str_starts_with($stored_hash, '$argon2id$')) {
        return false;
    }

    return password_verify($password, $stored_hash);
}

/**
 * Return whether a stored password should be replaced with current settings.
 */
function password_hash_needs_rehash(string $stored_hash): bool
{
    if (!defined('PASSWORD_ARGON2ID') || !str_starts_with($stored_hash, '$argon2id$')) {
        return true;
    }

    return password_needs_rehash($stored_hash, PASSWORD_ARGON2ID, [
        'memory_cost' => 19456,
        'time_cost' => 2,
        'threads' => 1,
    ]);
}

/**
 * Generate a new password salt.
 *
 * This method will not check if the salt is unique in database. This must be done
 * from the calling procedure.
 *
 * @return string Returns a salt string.
 */
function generate_salt(): string
{
    return bin2hex(random_bytes(50));
}

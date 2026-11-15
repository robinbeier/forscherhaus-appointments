<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

/** A directory failure that must be logged without disclosing account existence. */
final class LdapOperationalException extends RuntimeException {}

/**
 * Ldap_client library.
 *
 * Handles LDAP related functionality.
 *
 * @package Libraries
 */
class Ldap_client
{
    /** Bound connection and synchronous LDAP operation waits in seconds. */
    private const NETWORK_TIMEOUT_SECONDS = 10;

    private const OPERATION_TIMEOUT_SECONDS = 10;

    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Ldap_client constructor.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model('roles_model');

        $this->CI->load->library('timezones');
        $this->CI->load->library('accounts');
    }

    /**
     * Try authenticating the user with LDAP
     *
     * @param string $username
     * @param string $password
     *
     * @return array|null
     *
     * @throws Exception
     */
    public function check_login(string $username, string $password): ?array
    {
        if (empty($username)) {
            throw new InvalidArgumentException('No username value provided.');
        }

        $ldap_is_active = setting('ldap_is_active');

        if (!$ldap_is_active) {
            return null;
        }

        if (!extension_loaded('ldap')) {
            throw new LdapOperationalException('The LDAP extension is not available.');
        }

        // Match user by username

        $user = $this->CI->accounts->get_user_by_username($username);

        if (empty($user['ldap_dn'])) {
            return null; // User does not exist in Easy!Appointments
        }

        // Connect to LDAP server

        [$ldap_host, $ldap_port] = $this->configuredEndpoint();

        $connection = $this->establishSecureConnection($ldap_host, $ldap_port);

        $user_bind = @ldap_bind($connection, $user['ldap_dn'], $password);

        if ($user_bind) {
            // LDAP bind is a network operation. Re-read the identity after it
            // completes so a concurrent ldap_dn, role, or credential-version
            // change cannot establish a session from the stale pre-bind row.
            $current_user = $this->CI->accounts->get_user_by_username($username);
            if (!$this->identityMatches($user, $current_user)) {
                return null;
            }

            $role = $this->CI->roles_model->find($current_user['id_roles']);

            $default_timezone = $this->CI->timezones->get_default_timezone();

            return [
                'user_id' => $current_user['id'],
                'user_email' => $current_user['email'],
                'username' => $username,
                'timezone' => !empty($current_user['timezone']) ? $current_user['timezone'] : $default_timezone,
                'language' => !empty($current_user['language']) ? $current_user['language'] : Config::LANGUAGE,
                'role_slug' => $role['slug'],
                'credential_version' => max(0, (int) ($current_user['credential_version'] ?? 0)),
            ];
        }

        $this->handleBindFailure((int) @ldap_errno($connection));

        return null;
    }

    /** Reject a bind result if the account identity changed while LDAP ran. */
    protected function identityMatches(array $before, ?array $after): bool
    {
        return !empty($after) &&
            (int) ($after['id'] ?? 0) === (int) ($before['id'] ?? 0) &&
            (string) ($after['ldap_dn'] ?? '') === (string) ($before['ldap_dn'] ?? '') &&
            (int) ($after['id_roles'] ?? 0) === (int) ($before['id_roles'] ?? 0) &&
            (int) ($after['credential_version'] ?? 0) === (int) ($before['credential_version'] ?? 0);
    }

    /**
     * Handle a failed LDAP bind.
     *
     * LDAP result code 49 means that the supplied credentials are invalid. All
     * other failures indicate that authentication could not be evaluated
     * reliably and must be surfaced to the caller.
     *
     * @param int $error_code LDAP result code.
     *
     * @return void
     *
     * @throws LdapOperationalException When the failure is not invalid credentials.
     */
    protected function handleBindFailure(int $error_code): void
    {
        if ($error_code === 49) {
            return;
        }

        throw new LdapOperationalException(sprintf('LDAP authentication failed with result code %d.', $error_code));
    }

    /**
     * Fail closed when LDAP connection setup did not complete.
     *
     * @param mixed $connection LDAP connection handle.
     * @param bool $protocol_configured Whether the protocol option was set.
     *
     * @return void
     *
     * @throws LdapOperationalException When connection setup failed.
     */
    protected function assertConnectionReady(mixed $connection, bool $protocol_configured): void
    {
        if ($connection === false) {
            throw new LdapOperationalException('Unable to connect to the LDAP server.');
        }

        if (!$protocol_configured) {
            throw new LdapOperationalException('Unable to configure the LDAP connection.');
        }
    }

    /**
     * Establish an LDAPv3 connection and upgrade it to certificate-verified
     * StartTLS before any credentials are sent.
     *
     * @throws LdapOperationalException When TLS setup fails.
     */
    protected function establishSecureConnection(string $host, int $port): mixed
    {
        if (!$this->setTlsCertificateVerification()) {
            throw new LdapOperationalException('Unable to configure LDAP certificate verification.');
        }

        $connection = $this->connectToServer($host, $port);
        $connection_configured = $connection !== false && $this->configureTimeouts($connection);
        $protocol_configured = $connection_configured && $this->configureProtocol($connection);
        $this->assertConnectionReady($connection, $protocol_configured);

        if (!$this->startTls($connection)) {
            throw new LdapOperationalException('Unable to establish LDAP TLS.');
        }

        return $connection;
    }

    /**
     * Return the operator-pinned LDAP endpoint.
     *
     * Host and port settings in the database are intentionally not consulted:
     * callers with database write access must not be able to redirect a bind.
     *
     * @return array{0:string,1:int}
     * @throws LdapOperationalException When the local deployment is unconfigured.
     */
    protected function configuredEndpoint(): array
    {
        if (!defined('Config::LDAP_HOST') || !defined('Config::LDAP_PORT')) {
            throw new LdapOperationalException('LDAP endpoint is not configured.');
        }

        $host = Config::LDAP_HOST;
        $port = Config::LDAP_PORT;

        if (
            !is_string($host) ||
            $host === '' ||
            $host !== trim($host) ||
            !is_int($port) ||
            $port < 1 ||
            $port > 65535
        ) {
            throw new LdapOperationalException('LDAP endpoint is not configured.');
        }

        return [$host, $port];
    }

    protected function setTlsCertificateVerification(): bool
    {
        return @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
    }

    protected function connectToServer(string $host, int $port): mixed
    {
        return @ldap_connect($host, $port);
    }

    protected function configureProtocol(mixed $connection): bool
    {
        return @ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
    }

    /**
     * Bound connection establishment and synchronous LDAP operations before
     * StartTLS or bind can perform network I/O.
     */
    protected function configureTimeouts(mixed $connection): bool
    {
        return @ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, self::NETWORK_TIMEOUT_SECONDS) &&
            @ldap_set_option($connection, LDAP_OPT_TIMEOUT, self::OPERATION_TIMEOUT_SECONDS);
    }

    protected function startTls(mixed $connection): bool
    {
        return @ldap_start_tls($connection);
    }
}

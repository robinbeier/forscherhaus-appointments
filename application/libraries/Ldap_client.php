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

/**
 * Ldap_client library.
 *
 * Handles LDAP  related functionality.
 *
 * @package Libraries
 */
class Ldap_client
{
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
            throw new RuntimeException('The LDAP extension is not available.');
        }

        // Match user by username

        $user = $this->CI->accounts->get_user_by_username($username);

        if (empty($user['ldap_dn'])) {
            return null; // User does not exist in Easy!Appointments
        }

        // Connect to LDAP server

        $ldap_host = setting('ldap_host');
        $ldap_port = (int) setting('ldap_port');

        $connection = @ldap_connect($ldap_host, $ldap_port);
        $protocol_configured = $connection !== false && @ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        $this->assertConnectionReady($connection, $protocol_configured);

        $user_bind = @ldap_bind($connection, $user['ldap_dn'], $password);

        if ($user_bind) {
            $role = $this->CI->roles_model->find($user['id_roles']);

            $default_timezone = $this->CI->timezones->get_default_timezone();

            return [
                'user_id' => $user['id'],
                'user_email' => $user['email'],
                'username' => $username,
                'timezone' => !empty($user['timezone']) ? $user['timezone'] : $default_timezone,
                'language' => !empty($user['language']) ? $user['language'] : Config::LANGUAGE,
                'role_slug' => $role['slug'],
            ];
        }

        $this->handleBindFailure((int) @ldap_errno($connection));

        return null;
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
     * @throws RuntimeException When the failure is not invalid credentials.
     */
    protected function handleBindFailure(int $error_code): void
    {
        if ($error_code === 49) {
            return;
        }

        throw new RuntimeException(sprintf('LDAP authentication failed with result code %d.', $error_code));
    }

    /**
     * Fail closed when LDAP connection setup did not complete.
     *
     * @param mixed $connection LDAP connection handle.
     * @param bool $protocol_configured Whether the protocol option was set.
     *
     * @return void
     *
     * @throws RuntimeException When connection setup failed.
     */
    protected function assertConnectionReady(mixed $connection, bool $protocol_configured): void
    {
        if ($connection === false) {
            throw new RuntimeException('Unable to connect to the LDAP server.');
        }

        if (!$protocol_configured) {
            throw new RuntimeException('Unable to configure the LDAP connection.');
        }
    }
}

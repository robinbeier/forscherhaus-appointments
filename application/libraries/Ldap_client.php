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
        if (!extension_loaded('ldap')) {
            return null;
        }

        if (empty($username)) {
            throw new InvalidArgumentException('No username value provided.');
        }

        $ldap_is_active = setting('ldap_is_active');

        if (!$ldap_is_active) {
            return null;
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
        @ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
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

        return null;
    }
}

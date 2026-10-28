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
 * Accounts library.
 *
 * Handles account related functionality.
 *
 * @package Libraries
 */
class Accounts
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Accounts constructor.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model('users_model');
        $this->CI->load->model('roles_model');

        $this->CI->load->library('timezones');
    }

    /**
     * Authenticate the provided credentials.
     *
     * @param string $username Username.
     * @param string $password Password (non-hashed).
     *
     * @return array|null Returns an associative array with the PHP session data or NULL on failure.
     * @throws Exception
     */
    public function check_login(string $username, string $password): ?array
    {
        $user_settings = $this->CI->db->get_where('user_settings', ['username' => $username])->row_array();

        $stored_password = (string) ($user_settings['password'] ?? '');
        $salt = (string) ($user_settings['salt'] ?? '');

        if (!$this->verify_login_password($stored_password, $salt, $password) || empty($user_settings)) {
            return null;
        }

        if (MODERN_PASSWORD_WRITES && password_hash_needs_rehash($stored_password)) {
            $new_password = hash_password_argon2id($password);
            $new_salt = generate_salt();

            $updated = $this->CI->db
                ->where([
                    'id_users' => $user_settings['id_users'],
                    'username' => $username,
                    'password' => $stored_password,
                    'salt' => $salt,
                ])
                ->update('user_settings', ['password' => $new_password, 'salt' => $new_salt]);

            if (!$updated || $this->CI->db->affected_rows() !== 1) {
                // A concurrent writer won the CAS. Do not authenticate against
                // a row that changed during this request.
                return null;
            } else {
                $user_settings['password'] = $new_password;
                $user_settings['salt'] = $new_salt;
            }
        }

        // Verification is deliberately expensive. A reset, deletion or username
        // reassignment committed during that work invalidates this login, even
        // when this verifier did not need an upgrade.
        $current = $this->CI->db->get_where('user_settings', ['id_users' => $user_settings['id_users']])->row_array();
        if (
            !$current ||
            !hash_equals((string) $user_settings['username'], (string) $current['username']) ||
            !hash_equals((string) $user_settings['password'], (string) $current['password']) ||
            !hash_equals((string) $user_settings['salt'], (string) $current['salt'])
        ) {
            return null;
        }

        $user = $this->CI->users_model->find($user_settings['id_users']);

        $role = $this->CI->roles_model->find($user['id_roles']);

        $default_timezone = $this->CI->timezones->get_default_timezone();

        return [
            'user_id' => $user['id'],
            'user_email' => $user['email'],
            'username' => $user_settings['username'],
            'timezone' => !empty($user['timezone']) ? $user['timezone'] : $default_timezone,
            'language' => !empty($user['language']) ? $user['language'] : Config::LANGUAGE,
            'role_slug' => $role['slug'],
        ];
    }

    /**
     * Run one legacy and one current KDF on every credential shape. This keeps
     * known/wrong and unknown usernames on the same work path during migration.
     */
    private function verify_login_password(string $hash, string $salt, string $password): bool
    {
        $within_limit = strlen($password) <= MAX_PASSWORD_LENGTH;
        $bounded_password = $within_limit ? $password : substr($password, 0, MAX_PASSWORD_LENGTH);
        $legacy = $within_limit && preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1;
        $modern = $within_limit && (password_get_info($hash)['algoName'] ?? null) === 'argon2id';
        $valid = verify_password($hash, $salt, $password);
        if (!$legacy) {
            hash_legacy_password('synthetic-invalid-login', $bounded_password);
        }
        if (!$modern) {
            // Public, synthetic verifier: it is work padding, never an account.
            password_verify(
                $bounded_password,
                '$argon2id$v=19$m=19456,t=2,p=1$QW1JSDF3Q0Y0emYxRVpNVg$4CbCZKKBJ6gnVt8mYCflQClluxR2NdBYrd8nmW3S4zA',
            );
        }
        return $valid;
    }

    /**
     * Get the user's salt value.
     *
     * @param string $username Username.
     *
     * @return string Returns the salt value.
     */
    public function get_salt_by_username(string $username): string
    {
        $user_settings = $this->CI->db->get_where('user_settings', ['username' => $username])->row_array();

        return $user_settings['salt'] ?? '';
    }

    /**
     * Get the user full name.
     *
     * @param int $user_id User ID.
     *
     * @return string Returns the user full name.
     */
    public function get_user_display_name(int $user_id): string
    {
        $user = $this->CI->users_model->find($user_id);

        return $user['first_name'] . ' ' . $user['last_name'];
    }

    /**
     * Regenerate the password of the user that matches the provided username and email.
     *
     * @param string $username Username.
     * @param string $email Email.
     *
     * @return string Returns the new password on success or FALSE on failure.
     *
     * @throws Exception
     */
    public function regenerate_password(string $username, string $email): string
    {
        $query = $this->CI->db
            ->select('users.id')
            ->from('users')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where('users.email', $email)
            ->where('user_settings.username', $username)
            ->get();

        if (!$query->num_rows()) {
            throw new RuntimeException('The user was not found in the database with the provided info.');
        }

        $user = $query->row_array();

        // Generate a new password for the user.
        $new_password = random_string('alnum', 12);

        $salt = $this->get_salt_by_username($username);

        $hash_password = hash_password($salt, $new_password);

        $this->CI->users_model->set_setting($user['id'], 'password', $hash_password);

        return $new_password;
    }

    /**
     * Check if a user account exists or not.
     *
     * @param int $user_id
     *
     * @return bool
     */
    public function does_account_exist(int $user_id): bool
    {
        return $this->CI->users_model
            ->query()
            ->where(['id' => $user_id])
            ->get()
            ->num_rows() > 0;
    }

    /**
     * Get a user record based on the provided username value
     *
     * @param string $username
     *
     * @return array|null
     */
    public function get_user_by_username(string $username): ?array
    {
        $user_settings = $this->CI->db->get_where('user_settings', ['username' => $username])->row_array();

        if (!$user_settings) {
            return null;
        }

        $user_id = $user_settings['id_users'];

        return $this->CI->users_model->find($user_id);
    }
}

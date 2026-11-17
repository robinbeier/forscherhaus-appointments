<?php defined('BASEPATH') or exit('No direct script access allowed');

if (!class_exists('AuthenticationCapacityException')) {
    class AuthenticationCapacityException extends RuntimeException {}
}

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
        $matches = $this->CI->db
            ->select(
                'user_settings.id_users, user_settings.username, user_settings.password, user_settings.salt, ' .
                    'users.id AS user_id, users.email AS user_email, users.timezone AS user_timezone, ' .
                    'users.language AS user_language, users.ldap_dn, users.credential_version, roles.slug AS role_slug',
            )
            ->from('user_settings')
            ->join('users', 'users.id = user_settings.id_users', 'inner')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('user_settings.username', $username)
            ->limit(2)
            ->get()
            ->result_array();

        // Authentication must never choose an arbitrary row when the
        // username invariant is absent or being repaired.
        if (count($matches) !== 1) {
            // Keep the same legacy and Argon2id work as an invalid password
            // for an existing account, including when duplicate rows exist.
            $permit = $this->acquireKdfPermit();
            try {
                $this->verify_login_password('', '', $password, $permit);
            } finally {
                @flock($permit, LOCK_UN);
                @fclose($permit);
            }
            return null;
        }

        $user_settings = $matches[0];

        $stored_password = (string) ($user_settings['password'] ?? '');
        $salt = (string) ($user_settings['salt'] ?? '');

        $permit = $this->acquireKdfPermit();
        try {
            $local_password_valid = $this->verify_login_password($stored_password, $salt, $password, $permit);
            if (!$local_password_valid || empty($user_settings)) {
                return null;
            }

            if (
                MODERN_PASSWORD_WRITES &&
                (string) ($user_settings['ldap_dn'] ?? '') === '' &&
                password_hash_needs_rehash($stored_password)
            ) {
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
        } finally {
            @flock($permit, LOCK_UN);
            @fclose($permit);
        }

        // Verification is deliberately expensive. A reset, deletion or username
        // reassignment committed during that work invalidates this login, even
        // when this verifier did not need an upgrade.
        $current = $this->CI->db
            ->select(
                'user_settings.id_users, user_settings.username, user_settings.password, user_settings.salt, ' .
                    'users.id AS user_id, users.email AS user_email, users.timezone AS user_timezone, ' .
                    'users.language AS user_language, users.ldap_dn, users.credential_version, roles.slug AS role_slug',
            )
            ->from('user_settings')
            ->join('users', 'users.id = user_settings.id_users', 'inner')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('user_settings.id_users', $user_settings['id_users'])
            ->get()
            ->row_array();
        if (
            !$current ||
            !hash_equals((string) $user_settings['username'], (string) $current['username']) ||
            !hash_equals((string) $user_settings['password'], (string) $current['password']) ||
            !hash_equals((string) $user_settings['salt'], (string) $current['salt']) ||
            !hash_equals((string) ($user_settings['ldap_dn'] ?? ''), (string) ($current['ldap_dn'] ?? '')) ||
            (int) ($user_settings['credential_version'] ?? 0) !== (int) ($current['credential_version'] ?? 0)
        ) {
            return null;
        }

        // Directory-managed accounts must authenticate against LDAP. Keep the
        // local verifier above so browser login can fall through to LDAP with
        // equivalent password work, while API Basic authentication fails closed.
        if ((string) ($current['ldap_dn'] ?? '') !== '') {
            return null;
        }

        $default_timezone = $this->CI->timezones->get_default_timezone();

        return [
            'user_id' => $current['user_id'],
            'user_email' => $current['user_email'],
            'username' => $current['username'],
            'timezone' => !empty($current['user_timezone']) ? $current['user_timezone'] : $default_timezone,
            'language' => !empty($current['user_language']) ? $current['user_language'] : Config::LANGUAGE,
            'role_slug' => $current['role_slug'],
            'credential_version' => max(0, (int) ($current['credential_version'] ?? 0)),
        ];
    }

    /**
     * Run one legacy and one current KDF on every credential shape. This keeps
     * known/wrong and unknown usernames on the same work path during migration.
     */
    private function verify_login_password(string $hash, string $salt, string $password, mixed $permit = null): bool
    {
        $ownsPermit = $permit === null;
        $permit ??= $this->acquireKdfPermit();

        try {
            return $this->verify_login_password_with_permit($hash, $salt, $password);
        } finally {
            if ($ownsPermit) {
                @flock($permit, LOCK_UN);
                @fclose($permit);
            }
        }
    }

    /**
     * Bound concurrent password verification across web login and API Basic
     * authentication without queueing PHP workers. Unknown and known users
     * take the same KDF path after a permit is admitted.
     *
     * @return resource
     */
    private function acquireKdfPermit()
    {
        $cachePath = function_exists('config_item') ? (string) config_item('cache_path') : '';
        $cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);
        if ($cachePath === '' || !is_dir($cachePath) || is_link($cachePath)) {
            throw new AuthenticationCapacityException('Authentication capacity unavailable.');
        }

        $parent = dirname($cachePath);
        if (is_link($parent) || !is_dir($parent) || ($realParent = realpath($parent)) === false) {
            throw new AuthenticationCapacityException('Authentication capacity unavailable.');
        }
        $testLockRoot = PHP_SAPI === 'cli' && defined('ENVIRONMENT') && ENVIRONMENT === 'testing'
            ? getenv('EA_LOGIN_KDF_LOCK_ROOT')
            : false;
        $lockRoot = is_string($testLockRoot) && $testLockRoot !== ''
            ? $testLockRoot
            : '/var/www/html/.fh-login-kdf';
        if (is_link($lockRoot) || !is_dir($lockRoot) || ($realLockRoot = realpath($lockRoot)) !== $lockRoot) {
            throw new AuthenticationCapacityException('Authentication capacity unavailable.');
        }
        $lockRootStat = @stat($lockRoot);
        $expectedRootUid = 0;
        $expectedRootGid = 0;
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            $configuredUid = getenv('EA_LOGIN_KDF_LOCK_ROOT_UID');
            $configuredGid = getenv('EA_LOGIN_KDF_LOCK_ROOT_GID');
            if (is_string($configuredUid) && ctype_digit($configuredUid)) {
                $expectedRootUid = (int) $configuredUid;
            }
            if (is_string($configuredGid) && ctype_digit($configuredGid)) {
                $expectedRootGid = (int) $configuredGid;
            }
        }
        if (
            !is_array($lockRootStat) ||
            (($lockRootStat['mode'] ?? 0) & 0170000) !== 0040000 ||
            (($lockRootStat['mode'] ?? 0) & 0777) !== 0755 ||
            ($lockRootStat['uid'] ?? null) !== $expectedRootUid ||
            ($lockRootStat['gid'] ?? null) !== $expectedRootGid
        ) {
            throw new AuthenticationCapacityException('Authentication capacity unavailable.');
        }

        $permits = [];
        try {
            for ($slot = 0; $slot < 4; $slot++) {
                $path = $lockRoot . DIRECTORY_SEPARATOR . 'slot-' . $slot . '.lock';
                if (is_link($path) || (file_exists($path) && !is_file($path))) {
                    throw new AuthenticationCapacityException('Authentication capacity unavailable.');
                }

                if (!is_file($path) || is_link($path)) {
                    throw new AuthenticationCapacityException('Authentication capacity unavailable.');
                }
                $handle = @fopen($path, 'rb');
                if ($handle === false || !is_resource($handle)) {
                    throw new AuthenticationCapacityException('Authentication capacity unavailable.');
                }
                $permits[] = $handle;
                if (@flock($handle, LOCK_EX | LOCK_NB)) {
                    $descriptor = @fstat($handle);
                    $pathStat = @lstat($path);
                    $slotParentStat = $lockRootStat;
                    if (
                        !is_array($descriptor) || !is_array($pathStat) || !is_array($slotParentStat) ||
                        ($descriptor['dev'] ?? null) !== ($pathStat['dev'] ?? null) ||
                        ($descriptor['ino'] ?? null) !== ($pathStat['ino'] ?? null) ||
                        ($descriptor['nlink'] ?? null) !== 1 ||
                        ((int) ($descriptor['mode'] ?? 0) & 0170000) !== 0100000 ||
                        ($descriptor['uid'] ?? null) !== ($this->webUserId() ?? -1) ||
                        ($descriptor['gid'] ?? null) !== ($this->webGroupId() ?? -1) ||
                        (($descriptor['mode'] ?? 0) & 0777) !== 0600
                    ) {
                        @flock($handle, LOCK_UN);
                        throw new AuthenticationCapacityException('Authentication capacity unavailable.');
                    }
                    foreach ($permits as $candidate) {
                        if ($candidate !== $handle) {
                            @fclose($candidate);
                        }
                    }
                    return $handle;
                }
                @fclose($handle);
                array_pop($permits);
            }
        } catch (Throwable $e) {
            foreach ($permits as $handle) {
                @fclose($handle);
            }
            throw new AuthenticationCapacityException('Authentication capacity unavailable.', 0, $e);
        }

        throw new AuthenticationCapacityException('Authentication capacity unavailable.');
    }

    private function webUserId(): ?int
    {
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            $configured = getenv('EA_LOGIN_KDF_SLOT_UID');
            if (is_string($configured) && ctype_digit($configured)) {
                return (int) $configured;
            }
        }

        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            if (!function_exists('posix_getpwnam')) {
                return null;
            }

            $account = @posix_getpwnam('www-data');

            return is_array($account) && isset($account['uid']) ? (int) $account['uid'] : null;
        }

        if (!function_exists('posix_geteuid')) {
            return null;
        }

        $uid = @posix_geteuid();

        return is_int($uid) && $uid >= 0 ? $uid : null;
    }

    private function webGroupId(): ?int
    {
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            $configured = getenv('EA_LOGIN_KDF_SLOT_GID');
            if (is_string($configured) && ctype_digit($configured)) {
                return (int) $configured;
            }
        }

        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            if (!function_exists('posix_getgrnam')) {
                return null;
            }

            $group = @posix_getgrnam('www-data');

            return is_array($group) && isset($group['gid']) ? (int) $group['gid'] : null;
        }

        if (!function_exists('posix_getegid')) {
            return null;
        }

        $gid = @posix_getegid();

        return is_int($gid) && $gid >= 0 ? $gid : null;
    }

    private function verify_login_password_with_permit(string $hash, string $salt, string $password): bool
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

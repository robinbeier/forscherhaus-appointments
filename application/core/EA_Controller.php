<?php defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Provider_ui_smoke_access_policy.php';
require_once __DIR__ . '/Customers_ui_smoke_access_policy.php';

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.4.0
 * ---------------------------------------------------------------------------- */

/**
 * Easy!Appointments controller.
 *
 * @property EA_Benchmark $benchmark
 * @property EA_Cache $cache
 * @property EA_Calendar $calendar
 * @property EA_Config $config
 * @property EA_DB_forge $dbforge
 * @property EA_DB_query_builder $db
 * @property EA_DB_utility $dbutil
 * @property EA_Email $email
 * @property EA_Encrypt $encrypt
 * @property EA_Encryption $encryption
 * @property EA_Exceptions $exceptions
 * @property EA_Hooks $hooks
 * @property EA_Input $input
 * @property EA_Lang $lang
 * @property EA_Loader $load
 * @property EA_Log $log
 * @property EA_Migration $migration
 * @property EA_Output $output
 * @property EA_Profiler $profiler
 * @property EA_Router $router
 * @property EA_Security $security
 * @property EA_Session $session
 * @property EA_Upload $upload
 * @property EA_URI $uri
 *
 * @property Admins_model $admins_model
 * @property Appointments_model $appointments_model
 * @property Service_categories_model $service_categories_model
 * @property Consents_model $consents_model
 * @property Customers_model $customers_model
 * @property Providers_model $providers_model
 * @property Roles_model $roles_model
 * @property Secretaries_model $secretaries_model
 * @property Services_model $services_model
 * @property Settings_model $settings_model
 * @property Unavailabilities_model $unavailabilities_model
 * @property Users_model $users_model
 * @property Webhooks_model $webhooks_model
 * @property Blocked_periods_model $blocked_periods_model
 *
 * @property Accounts $accounts
 * @property Api $api
 * @property Availability $availability
 * @property Email_messages $email_messages
 * @property Captcha_builder $captcha_builder
 * @property Google_Sync $google_sync
 * @property Caldav_Sync $caldav_sync
 * @property Ics_file $ics_file
 * @property Instance $instance
 * @property Ldap_client $ldap_client
 * @property Notifications $notifications
 * @property Permissions $permissions
 * @property Synchronization $synchronization
 * @property Timezones $timezones
 * @property Webhooks_client $webhooks_client
 */
class EA_Controller extends CI_Controller
{
    /**
     * EA_Controller constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('accounts');

        $this->ensure_user_exists();
        $this->enforce_provider_ui_smoke_boundary();
        $this->enforce_customers_ui_smoke_boundary();
        $this->configure_timezone();
        $this->configure_language();
        $this->load_common_html_vars();
        $this->load_common_script_vars();

        rate_limit($this->input->ip_address());
    }

    private function ensure_user_exists()
    {
        $user_id = session('user_id');

        if (!$user_id || !$this->db->table_exists('users')) {
            return;
        }

        if (!$this->accounts->does_account_exist($user_id)) {
            session_destroy();

            abort(403, 'Forbidden');
        }
    }

    /**
     * Keep the reserved production smoke identity away from every real-data surface.
     *
     * This boundary deliberately checks the current database role and lease instead
     * of trusting session role data, which becomes stale when the short smoke run is
     * deactivated. It also recognizes login POSTs and API Basic Auth before those
     * authentication mechanisms create a session.
     */
    private function enforce_provider_ui_smoke_boundary(): void
    {
        $controller = (string) $this->router->class;
        $method = (string) $this->router->method;
        $http_method = strtoupper($this->input->method(true));
        $session_username = session('username');
        $session_username = is_string($session_username) ? $session_username : null;
        $basic_auth_username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $basic_auth_username = is_string($basic_auth_username) ? $basic_auth_username : null;
        $login_username = null;

        if (strtolower($controller) === 'login' && strtolower($method) === 'validate' && $http_method === 'POST') {
            $requested_username = request('username');
            $login_username = is_string($requested_username) ? $requested_username : null;
        }

        $session_is_reserved = Provider_ui_smoke_access_policy::isReservedUsername($session_username);
        $login_is_reserved = $this->is_provider_ui_smoke_auth_username($login_username);
        $basic_auth_is_reserved = $this->is_provider_ui_smoke_auth_username($basic_auth_username);

        if (!$session_is_reserved && !$login_is_reserved && !$basic_auth_is_reserved) {
            return;
        }

        if ($basic_auth_is_reserved) {
            abort(403, 'Forbidden');
        }

        if ($session_is_reserved) {
            if (Provider_ui_smoke_access_policy::isLogoutRoute($controller, $method, $http_method)) {
                return;
            }

            $principal = $this->load_provider_ui_smoke_principal((int) session('user_id'));

            if (
                $principal === null ||
                $principal['role_slug'] !== DB_SLUG_PROVIDER ||
                session('role_slug') !== DB_SLUG_PROVIDER ||
                empty($principal['password']) ||
                empty($principal['salt']) ||
                !Provider_ui_smoke_access_policy::hasActiveLease((string) $principal['notes'])
            ) {
                session_destroy();

                abort(403, 'Forbidden');
            }

            if (!Provider_ui_smoke_access_policy::isAllowedRoute($controller, $method, $http_method)) {
                abort(403, 'Forbidden');
            }

            return;
        }

        if ($login_is_reserved) {
            $principal = $this->load_provider_ui_smoke_principal();

            if (
                $principal === null ||
                $principal['role_slug'] !== DB_SLUG_PROVIDER ||
                empty($principal['password']) ||
                empty($principal['salt']) ||
                !Provider_ui_smoke_access_policy::hasActiveLease((string) $principal['notes'])
            ) {
                abort(403, 'Forbidden');
            }

            return;
        }

        abort(403, 'Forbidden');
    }

    /**
     * Resolve pre-session authentication names through the database collation and
     * compare the resulting stable user ID with the canonical smoke principal.
     *
     * This deliberately avoids attempting to reproduce MariaDB's Unicode collation
     * rules in PHP. The textual policy remains a fail-closed fallback when the
     * permanent principal has not been installed yet.
     */
    protected function is_provider_ui_smoke_auth_username(?string $username): bool
    {
        if (Provider_ui_smoke_access_policy::isReservedUsername($username)) {
            return true;
        }

        if (!is_string($username) || $username === '' || !$this->db->table_exists('user_settings')) {
            return false;
        }

        $principal = $this->load_provider_ui_smoke_principal();

        if ($principal === null) {
            return false;
        }

        $matched_user = $this->accounts->get_user_by_username($username);

        return is_array($matched_user) &&
            isset($matched_user['id']) &&
            (int) $matched_user['id'] === (int) $principal['id'];
    }

    /**
     * Load the reserved identity with strict cardinality and current database role.
     *
     * @return array<string, mixed>|null
     */
    private function load_provider_ui_smoke_principal(?int $user_id = null): ?array
    {
        $this->db
            ->select('users.id, users.notes, roles.slug AS role_slug, user_settings.password, user_settings.salt')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where('user_settings.username', Provider_ui_smoke_access_policy::USERNAME);

        if ($user_id !== null) {
            $this->db->where('users.id', $user_id);
        }

        $query = $this->db->get();

        if ($query->num_rows() !== 1) {
            return null;
        }

        return $query->row_array();
    }

    /**
     * Keep every Customers UI smoke role away from non-Customers and write routes.
     */
    private function enforce_customers_ui_smoke_boundary(): void
    {
        $controller = (string) $this->router->class;
        $method = (string) $this->router->method;
        $httpMethod = strtoupper($this->input->method(true));
        $sessionUsername = session('username');
        $sessionUsername = is_string($sessionUsername) ? $sessionUsername : null;
        $basicAuthUsername = $_SERVER['PHP_AUTH_USER'] ?? null;
        $basicAuthUsername = is_string($basicAuthUsername) ? $basicAuthUsername : null;
        $loginUsername = null;

        if (strtolower($controller) === 'login' && strtolower($method) === 'validate' && $httpMethod === 'POST') {
            $requestedUsername = request('username');
            $loginUsername = is_string($requestedUsername) ? $requestedUsername : null;
        }

        $sessionIsReserved = Customers_ui_smoke_access_policy::isReservedUsername($sessionUsername);
        $loginIsReserved = $this->is_customers_ui_smoke_auth_username($loginUsername);
        $basicAuthIsReserved = $this->is_customers_ui_smoke_auth_username($basicAuthUsername);

        if (!$sessionIsReserved && !$loginIsReserved && !$basicAuthIsReserved) {
            return;
        }

        if ($basicAuthIsReserved) {
            abort(403, 'Forbidden');
        }

        if ($sessionIsReserved) {
            if (Customers_ui_smoke_access_policy::isLogoutRoute($controller, $method, $httpMethod)) {
                return;
            }

            $principal = $this->load_customers_ui_smoke_principal((int) session('user_id'));
            $targetRole = is_array($principal)
                ? Customers_ui_smoke_access_policy::roleForUsername((string) $principal['username'])
                : null;

            if (
                $principal === null ||
                $targetRole === null ||
                $principal['role_slug'] !== $targetRole ||
                session('role_slug') !== $targetRole ||
                empty($principal['password']) ||
                empty($principal['salt']) ||
                !Customers_ui_smoke_access_policy::hasActiveLease((string) $principal['notes'], $targetRole)
            ) {
                session_destroy();
                abort(403, 'Forbidden');
            }

            if (!Customers_ui_smoke_access_policy::isAllowedRoute($controller, $method, $httpMethod)) {
                abort(403, 'Forbidden');
            }

            return;
        }

        if ($loginIsReserved) {
            $principal = $this->load_customers_ui_smoke_principal(null, $loginUsername);
            $targetRole = is_array($principal)
                ? Customers_ui_smoke_access_policy::roleForUsername((string) $principal['username'])
                : null;

            if (
                $principal === null ||
                $targetRole === null ||
                $principal['role_slug'] !== $targetRole ||
                empty($principal['password']) ||
                empty($principal['salt']) ||
                !Customers_ui_smoke_access_policy::hasActiveLease((string) $principal['notes'], $targetRole)
            ) {
                abort(403, 'Forbidden');
            }

            return;
        }

        abort(403, 'Forbidden');
    }

    protected function is_customers_ui_smoke_auth_username(?string $username): bool
    {
        if (Customers_ui_smoke_access_policy::isReservedUsername($username)) {
            return true;
        }

        if (!is_string($username) || $username === '' || !$this->db->table_exists('user_settings')) {
            return false;
        }

        return $this->load_customers_ui_smoke_principal(null, $username) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load_customers_ui_smoke_principal(?int $userId = null, ?string $authUsername = null): ?array
    {
        if ($authUsername !== null) {
            $matchedUser = $this->accounts->get_user_by_username($authUsername);

            if (!is_array($matchedUser) || !isset($matchedUser['id'])) {
                return null;
            }

            $userId = (int) $matchedUser['id'];
        }

        if ($userId === null || $userId <= 0) {
            return null;
        }

        $query = $this->db
            ->select(
                'users.id, users.notes, roles.slug AS role_slug, user_settings.username, ' .
                    'user_settings.password, user_settings.salt',
            )
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where('users.id', $userId)
            ->where_in('user_settings.username', array_values(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE))
            ->get();

        if ($query->num_rows() !== 1) {
            return null;
        }

        $principal = $query->row_array();

        return Customers_ui_smoke_access_policy::roleForUsername((string) $principal['username']) !== null
            ? $principal
            : null;
    }

    /**
     * Configure the language.
     */
    private function configure_language()
    {
        $session_language = session('language');

        if ($session_language) {
            $language_codes = config('language_codes');

            config([
                'language' => $session_language,
                'language_code' => array_search($session_language, $language_codes) ?: 'en',
            ]);
        }

        $this->lang->load('translations');
    }

    /**
     * Load common script vars for all requests.
     */
    private function load_common_html_vars()
    {
        html_vars([
            'base_url' => config('base_url'),
            'index_page' => config('index_page'),
            'available_languages' => config('available_languages'),
            'language' => $this->lang->language,
            'csrf_token' => $this->security->get_csrf_hash(),
        ]);
    }

    /**
     * Load common script vars for all requests.
     */
    private function load_common_script_vars()
    {
        script_vars([
            'base_url' => config('base_url'),
            'index_page' => config('index_page'),
            'available_languages' => config('available_languages'),
            'csrf_token' => $this->security->get_csrf_hash(),
            'language' => config('language'),
            'language_code' => config('language_code'),
        ]);
    }

    /**
     * Set the default timezone of the app, based on the selected setting.
     */
    private function configure_timezone(): void
    {
        if (!$this->db->table_exists('settings')) {
            return;
        }

        $default_timezone = setting('default_timezone');

        date_default_timezone_set($default_timezone);
    }
}

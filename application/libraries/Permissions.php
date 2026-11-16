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
 * Permissions library.
 *
 * Handles permission related functionality.
 *
 * @package Libraries
 */
class Permissions
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Permissions constructor.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model('appointments_model');
        $this->CI->load->model('roles_model');
        $this->CI->load->model('secretaries_model');
        $this->CI->load->model('users_model');

        $this->CI->load->library('timezones');
    }

    /**
     * Check if a user is allowed to manage the provided customer.
     *
     * The "limit_customer_access" setting changes the access permissions to customer entries. In order for a provider
     * or a secretary to be able to make changes to a customer, they will first need to at least have a single
     * appointment with them.
     *
     * @param int $user_id
     * @param int $customer_id
     *
     * @return bool
     */
    public function has_customer_access(
        int $user_id,
        int $customer_id,
        ?bool $locked_limit_customer_access = null,
    ): bool {
        try {
            $customer_role_id = $this->CI->users_model->value($customer_id, 'id_roles');
            $customer_role_slug = $this->CI->roles_model->value($customer_role_id, 'slug');
        } catch (InvalidArgumentException) {
            return false;
        }

        if ($customer_role_slug !== DB_SLUG_CUSTOMER) {
            return false;
        }

        $role_id = $this->CI->users_model->value($user_id, 'id_roles');

        $role_slug = $this->CI->roles_model->value($role_id, 'slug');

        if (!in_array($role_slug, [DB_SLUG_ADMIN, DB_SLUG_PROVIDER, DB_SLUG_SECRETARY], true)) {
            return false;
        }

        $limit_customer_access = $locked_limit_customer_access ?? setting('limit_customer_access');

        if ($role_slug === DB_SLUG_ADMIN || !$limit_customer_access) {
            return true;
        }

        if ($role_slug === DB_SLUG_PROVIDER) {
            return $this->CI->appointments_model
                ->query()
                ->where(['id_users_provider' => $user_id, 'id_users_customer' => $customer_id])
                ->get()
                ->num_rows() > 0;
        }

        if ($role_slug === DB_SLUG_SECRETARY) {
            $secretary = $this->CI->secretaries_model->find($user_id);

            foreach ($secretary['providers'] as $secretary_provider_id) {
                $has_appointments_with_customer =
                    $this->CI->appointments_model
                        ->query()
                        ->where(['id_users_provider' => $secretary_provider_id, 'id_users_customer' => $customer_id])
                        ->get()
                        ->num_rows() > 0;

                if ($has_appointments_with_customer) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /** Lock and read the relationship-scope policy for a transaction-scoped write. */
    public function lock_customer_access_setting(int $actor_user_id): bool
    {
        // An Admin's customer access does not depend on this setting. Avoid
        // taking the setting lock after the Admin actor lock: Basic Settings
        // API writes take those same locks in the opposite order at finalization.
        $actor = $this->CI->db
            ->query('SELECT `id_roles` FROM `' . $this->CI->db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE', [
                $actor_user_id,
            ])
            ->row_array();
        if (!$actor) {
            throw new RuntimeException('Could not lock customer access actor.');
        }
        $role = $this->CI->db
            ->query('SELECT `slug` FROM `' . $this->CI->db->dbprefix('roles') . '` WHERE `id` = ?', [
                (int) $actor['id_roles'],
            ])
            ->row_array();
        if (!$role) {
            throw new RuntimeException('Could not resolve customer access actor role.');
        }
        if ($role['slug'] === DB_SLUG_ADMIN) {
            return true;
        }

        $settings = $this->CI->db->dbprefix('settings');
        $setting = $this->CI->db
            ->query("SELECT `value` FROM `{$settings}` WHERE `name` = ? FOR UPDATE", ['limit_customer_access'])
            ->row_array();

        if (!$setting || !array_key_exists('value', $setting)) {
            throw new RuntimeException('Could not lock customer access setting.');
        }

        $value = (string) $setting['value'];
        if ($value === '1') {
            return true;
        }
        if ($value === '0') {
            return false;
        }

        throw new RuntimeException('Invalid customer access setting.');
    }
}

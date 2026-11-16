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

if (!function_exists('can')) {
    /**
     * Check if the currently logged-in user can perform an action
     *
     * Example:
     *
     * if (can('edit', 'appointments') === FALSE) abort(403);
     *
     * @param string $action
     * @param string $resource
     * @param int|null $user_id
     *
     * @return bool
     */
    function can(string $action, string $resource, ?int $user_id = null): bool
    {
        /** @var EA_Controller $CI */
        $CI = &get_instance();

        $CI->load->model('roles_model');
        $CI->load->model('users_model');

        if (empty($user_id)) {
            $role_slug = session('role_slug');
        } else {
            $user = $CI->users_model->find($user_id);

            $role_slug = $CI->roles_model->value($user['id_roles'], 'slug');
        }

        if (empty($role_slug)) {
            return false;
        }

        $permissions = $CI->roles_model->get_permissions_by_slug($role_slug);

        return $permissions[$resource][$action] ?? false;
    }
}

if (!function_exists('cannot')) {
    /**
     * Check if the currently logged-in user can perform an action
     *
     * Example:
     *
     * if (cannot('edit', 'appointments')) abort(403);
     *
     * @param string $action
     * @param string $resource
     * @param int|null $user_id
     *
     * @return bool
     */
    function cannot(string $action, string $resource, ?int $user_id = null): bool
    {
        return !can($action, $resource, $user_id);
    }
}

if (!function_exists('lock_actor_role')) {
    /**
     * Lock the persisted actor and exact role in that order for a write transaction.
     * The caller must keep the transaction open through its protected commit.
     */
    function lock_actor_role(int $user_id): array
    {
        if ($user_id <= 0) {
            return [];
        }

        /** @var EA_Controller $CI */
        $CI = &get_instance();
        if (!$CI->db->trans_active()) {
            return [];
        }
        $actor = $CI->db->query(
            'SELECT `id_roles` FROM `' . $CI->db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE',
            [$user_id],
        );
        if ($actor === false) {
            return [];
        }
        $actor_row = $actor->row_array();
        $role_id = (int) ($actor_row['id_roles'] ?? 0);
        if (!$actor_row || $role_id <= 0) {
            return [];
        }

        $role = $CI->db->query(
            'SELECT * FROM `' . $CI->db->dbprefix('roles') . '` WHERE `id` = ? FOR UPDATE',
            [$role_id],
        );
        if ($role === false) {
            return [];
        }
        return $role->row_array();
    }
}

if (!function_exists('can_with_locked_role')) {
    /** Check a transaction-scoped permission against the locked role row. */
    function can_with_locked_role(string $action, string $resource, int $user_id): bool
    {
        $resource_columns = [
            PRIV_APPOINTMENTS => 'appointments',
            PRIV_CUSTOMERS => 'customers',
            PRIV_SERVICES => 'services',
            PRIV_USERS => 'users',
            PRIV_SYSTEM_SETTINGS => 'system_settings',
            PRIV_USER_SETTINGS => 'user_settings',
            PRIV_BLOCKED_PERIODS => 'blocked_periods',
        ];
        $permission = match ($action) {
            'view' => PRIV_VIEW,
            'add' => PRIV_ADD,
            'edit' => PRIV_EDIT,
            'delete' => PRIV_DELETE,
            default => 0,
        };
        $column = $resource_columns[$resource] ?? null;
        if ($permission === 0 || $column === null) {
            return false;
        }

        $role_row = lock_actor_role($user_id);
        $mask = (int) ($role_row[$column] ?? -1);
        $known_mask = PRIV_VIEW | PRIV_ADD | PRIV_EDIT | PRIV_DELETE;

        return $role_row !== [] && $mask >= 0 && ($mask & ~$known_mask) === 0 && ($mask & $permission) === $permission;
    }
}

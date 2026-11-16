<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.1.0
 * ---------------------------------------------------------------------------- */

/**
 * Update controller.
 *
 * Handles the update related operations.
 *
 * @package Controllers
 */
class Update extends EA_Controller
{
    /**
     * Update constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('admins_model');
        $this->load->model('settings_model');
        $this->load->model('services_model');
        $this->load->model('providers_model');
        $this->load->model('customers_model');
    }

    /**
     * Explain the operator-only migration path to authorized users.
     * Database migrations may change schema and data, so this web endpoint must
     * never initiate them. Operators use the protected CLI release procedure.
     */
    public function index(): void
    {
        $user_id = (int) session('user_id');

        if (!$user_id || cannot('edit', PRIV_SYSTEM_SETTINGS, $user_id)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')), ['GET', 'HEAD'], true)) {
            abort(405, 'Method Not Allowed', ['Allow: GET, HEAD']);

            return;
        }

        $this->load->view('pages/update');
    }
}

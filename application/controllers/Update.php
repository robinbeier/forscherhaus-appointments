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
     * This method will update the instance to the latest available version in the server.
     *
     * IMPORTANT: The code files must exist in the server, this method will not fetch any new files but will update
     * the database schema.
     *
     * GET/HEAD display a confirmation page. Only an authorized POST, protected by
     * the framework's CSRF verification, may initialize and run migrations.
     */
    public function index(): void
    {
        try {
            $user_id = session('user_id');

            if (cannot('edit', PRIV_SYSTEM_SETTINGS)) {
                if ($user_id) {
                    abort(403, 'Forbidden');
                }

                redirect('login');

                return;
            }

            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));

            if ($method === 'POST') {
                // Instance construction can initialize migration tracking. Keep it behind both guards.
                $this->load->library('instance');
                $this->instance->migrate();
                $view = ['success' => true];
            } elseif (in_array($method, ['GET', 'HEAD'], true)) {
                $view = [
                    'success' => null,
                    'csrf_token_name' => $this->security->get_csrf_token_name(),
                    'csrf_token' => $this->security->get_csrf_hash(),
                ];
            } else {
                abort(405, 'Method Not Allowed', ['Allow: GET, HEAD, POST']);

                return;
            }
        } catch (Throwable $e) {
            $view = ['success' => false, 'exception' => $e->getMessage()];
        }

        html_vars($view);

        $this->load->view('pages/update');
    }
}

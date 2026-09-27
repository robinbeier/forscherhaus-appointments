<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.3.2
 * ---------------------------------------------------------------------------- */

/**
 * Consents controller.
 *
 * Handles user consent related operations.
 *
 * @package Controllers
 */
class Consents extends EA_Controller
{
    /** The public consent write route was retired when booking began recording consent on commit. */
    public function save(): void
    {
        abort(404, 'Not Found');
    }
}

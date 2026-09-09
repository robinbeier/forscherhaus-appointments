<?php defined('BASEPATH') or exit('No direct script access allowed');

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
 * Easy!Appointments session.
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
 */
class EA_Session extends CI_Session
{
    public function __construct(array $params = [])
    {
        parent::__construct($params);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->enforceInactivityTimeout(time(), (int) config_item('sess_expiration'));
        }
    }

    protected function enforceInactivityTimeout(int $now, int $expiration): void
    {
        if ($expiration <= 0) {
            return;
        }

        $has_activity = array_key_exists('__ea_last_activity', $_SESSION);
        $last_activity = $_SESSION['__ea_last_activity'] ?? null;
        $invalid_activity = $has_activity && (!is_int($last_activity) || $last_activity <= 0 || $last_activity > $now);
        $expired = is_int($last_activity) && $now - $last_activity >= $expiration;
        $legacy_login = !$has_activity && !empty($_SESSION['user_id']);

        if ($invalid_activity || $expired || $legacy_login) {
            // Remove identity before rotation: destroying a file alone does not clear this request.
            $_SESSION = [];
            $this->sess_regenerate(true);
        }

        $_SESSION['__ea_last_activity'] = $now;
    }
}

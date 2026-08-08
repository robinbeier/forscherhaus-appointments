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

use Jsvrcek\ICS\Exception\CalendarEventException;

require_once __DIR__ . '/Google.php';
require_once __DIR__ . '/Caldav.php';

/**
 * Console controller.
 *
 * Handles all the Console related operations.
 */
class Console extends EA_Controller
{
    /**
     * Console constructor.
     */
    public function __construct()
    {
        if (!is_cli()) {
            exit('No direct script access allowed');
        }

        parent::__construct();

        $this->load->dbutil();

        $this->load->library('instance');
        $this->load->library('customers_ui_smoke_fixture');
        $this->load->library('provider_ui_smoke_fixture');

        $this->load->model('admins_model');
        $this->load->model('customers_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('settings_model');
    }

    /**
     * Perform a console installation.
     *
     * Use this method to install Easy!Appointments directly from the terminal.
     *
     * Usage:
     *
     * php index.php console install
     *
     * @throws Exception
     */
    public function install(): void
    {
        $this->instance->migrate('fresh');

        $password = $this->instance->seed();

        response(
            PHP_EOL . '⇾ Installation completed, login with "administrator" / "' . $password . '".' . PHP_EOL . PHP_EOL,
        );
    }

    /**
     * Migrate the database to the latest state.
     *
     * Use this method to upgrade an Easy!Appointments instance to the latest database state.
     *
     * Notice:
     *
     * Do not use this method to install the app as it will not seed the database with the initial entries (admin,
     * provider, service, settings etc.).
     *
     * Usage:
     *
     * php index.php console migrate
     *
     * php index.php console migrate fresh
     *
     * @param string $type
     */
    public function migrate(string $type = ''): void
    {
        $this->instance->migrate($type);
    }

    /**
     * Seed the database with test data.
     *
     * Use this method to add test data to your database
     *
     * Usage:
     *
     * php index.php console seed
     * @throws Exception
     */
    public function seed(): void
    {
        $this->instance->seed();
    }

    /**
     * Create a database backup file.
     *
     * Use this method to back up your Easy!Appointments data.
     *
     * Usage:
     *
     * php index.php console backup
     *
     * php index.php console backup /path/to/backup/folder
     *
     * @throws Exception
     */
    public function backup(): void
    {
        $this->instance->backup($GLOBALS['argv'][3] ?? null);
    }

    /**
     * Trigger the synchronization of all provider calendars with Google Calendar.
     *
     * Use this method in a cronjob to automatically sync events between Easy!Appointments and Google Calendar.
     *
     * Notice:
     *
     * Google syncing must first be enabled for each individual provider from inside the backend calendar page.
     *
     * Usage:
     *
     * php index.php console sync
     *
     * @throws CalendarEventException
     * @throws Exception
     * @throws Throwable
     */
    public function sync(): void
    {
        $providers = $this->providers_model->get();

        foreach ($providers as $provider) {
            if (filter_var($provider['settings']['google_sync'], FILTER_VALIDATE_BOOLEAN)) {
                Google::sync((string) $provider['id']);
            }

            if (filter_var($provider['settings']['caldav_sync'], FILTER_VALIDATE_BOOLEAN)) {
                Caldav::sync((string) $provider['id']);
            }
        }
    }

    /**
     * Manage the isolated production provider UI smoke principal and short-lived fixture.
     *
     * Usage:
     *
     * php index.php console provider_ui_smoke install [credential-file] [state-file]
     * php index.php console provider_ui_smoke verify [credential-file] [state-file]
     * php index.php console provider_ui_smoke activate [credential-file] [state-file]
     * php index.php console provider_ui_smoke deactivate [credential-file] [state-file]
     * php index.php console provider_ui_smoke remove [credential-file] [state-file]
     */
    public function provider_ui_smoke(string $action = ''): void
    {
        if (!in_array($action, ['install', 'verify', 'activate', 'deactivate', 'remove'], true)) {
            $this->exit_provider_ui_smoke_error('invalid', 64);
        }

        $credential_file = $GLOBALS['argv'][4] ?? Provider_ui_smoke_fixture::DEFAULT_CREDENTIAL_FILE;
        $state_file = $GLOBALS['argv'][5] ?? Provider_ui_smoke_fixture::DEFAULT_STATE_FILE;

        if (!is_string($credential_file) || !is_string($state_file) || isset($GLOBALS['argv'][6])) {
            $this->exit_provider_ui_smoke_error('invalid', 64);
        }

        try {
            $state = $this->provider_ui_smoke_fixture->run($action, $credential_file, $state_file);

            response('provider_ui_smoke action=' . $action . ' state=' . $state . ' result=ok' . PHP_EOL);
        } catch (Throwable) {
            $this->exit_provider_ui_smoke_error($action, 1);
        }
    }

    /**
     * Emit the generic lifecycle error before exiting; response() is not flushed after exit().
     */
    private function exit_provider_ui_smoke_error(string $action, int $exit_code): never
    {
        fwrite(STDOUT, 'provider_ui_smoke action=' . $action . ' state=error result=error' . PHP_EOL);
        exit($exit_code);
    }

    /**
     * Manage the isolated Customers UI smoke principals.
     */
    public function customers_ui_smoke(string $action = ''): void
    {
        if (!in_array($action, ['install', 'verify', 'activate', 'deactivate', 'remove'], true)) {
            $this->exit_customers_ui_smoke_error('invalid', 64);
        }

        $credentialFile = $GLOBALS['argv'][4] ?? Customers_ui_smoke_fixture::DEFAULT_CREDENTIAL_FILE;
        $stateFile = $GLOBALS['argv'][5] ?? Customers_ui_smoke_fixture::DEFAULT_STATE_FILE;

        if (!is_string($credentialFile) || !is_string($stateFile) || isset($GLOBALS['argv'][6])) {
            $this->exit_customers_ui_smoke_error('invalid', 64);
        }

        try {
            $state = $this->customers_ui_smoke_fixture->run($action, $credentialFile, $stateFile);
            response('customers_ui_smoke action=' . $action . ' state=' . $state . ' result=ok' . PHP_EOL);
        } catch (Throwable) {
            $this->exit_customers_ui_smoke_error($action, 1);
        }
    }

    private function exit_customers_ui_smoke_error(string $action, int $exitCode): never
    {
        fwrite(STDOUT, 'customers_ui_smoke action=' . $action . ' state=error result=error' . PHP_EOL);
        exit($exitCode);
    }

    /**
     * Show help information about the console capabilities.
     *
     * Use this method to see the available commands.
     *
     * Usage:
     *
     * php index.php console help
     */
    public function help(): void
    {
        $help = [
            '',
            'Easy!Appointments ' . config('version'),
            '',
            'Usage:',
            '',
            '⇾ php index.php console [command] [arguments]',
            '',
            'Commands:',
            '',
            '⇾ php index.php console migrate',
            '⇾ php index.php console migrate fresh',
            '⇾ php index.php console migrate up',
            '⇾ php index.php console migrate down',
            '⇾ php index.php console seed',
            '⇾ php index.php console install',
            '⇾ php index.php console backup',
            '⇾ php index.php console sync',
            '⇾ php index.php console customers_ui_smoke <install|verify|activate|deactivate|remove>',
            '⇾ php index.php console provider_ui_smoke <install|verify|activate|deactivate|remove>',
            '',
            '',
        ];

        response(implode(PHP_EOL, $help));
    }
}

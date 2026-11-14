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

require_once __DIR__ . '/../core/EA_Migration.php';

/**
 * Instance library.
 *
 * Handles all Easy!Appointments instance related functionality.
 *
 * @package Libraries
 */
class Instance
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Installation constructor.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model('admins_model');
        $this->CI->load->model('services_model');
        $this->CI->load->model('providers_model');
        $this->CI->load->model('customers_model');

        $this->CI->load->library('timezones');
        $this->CI->load->library('migration');
    }

    /**
     * Migrate the database to the latest state.
     *
     * @param string $type Provide "fresh" to revert previous migrations and start from the beginning or "up"/"down" to step.
     */
    public function migrate(string $type = ''): void
    {
        $current_version = $this->CI->migration->current_version();

        if ($type === 'up') {
            if (!$this->CI->migration->version($current_version + 1)) {
                show_error($this->CI->migration->error_string());
            }

            return;
        }

        if ($type === 'down') {
            if (!$this->CI->migration->version($current_version - 1)) {
                show_error($this->CI->migration->error_string());
            }

            return;
        }

        if ($type === 'fresh' && !$this->CI->migration->version(0)) {
            show_error($this->CI->migration->error_string());
        }

        if ($this->CI->migration->latest() === false) {
            show_error($this->CI->migration->error_string());
        }
    }

    /**
     * Seed the database with test data.
     *
     * @return string Return's the administrator user password.
     *
     * @throws Exception
     */
    public function seed(): string
    {
        // Settings

        setting([
            'company_name' => 'Company Name',
            'company_link' => 'https://example.org',
            'dashboard_conflict_threshold' => '0.90',
        ]);

        // Admin

        $password = 'administrator';

        $this->CI->admins_model->save([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.org',
            'phone_number' => '+10000000000',
            'settings' => [
                'username' => 'administrator',
                'password' => $password,
                'notifications' => true,
                'calendar_view' => CALENDAR_VIEW_DEFAULT,
            ],
        ]);

        // Service

        $service_id = $this->CI->services_model->save([
            'name' => 'Service',
            'duration' => '30',
            'price' => '0',
            'currency' => '',
            'availabilities_type' => 'flexible',
            'attendants_number' => '1',
        ]);

        // Provider

        $this->CI->providers_model->save([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.org',
            'phone_number' => '+10000000000',
            'services' => [$service_id],
            'settings' => [
                'username' => 'janedoe',
                'password' => random_string(),
                'working_plan' => setting('company_working_plan'),
                'working_plan_exceptions' => '{}',
                'notifications' => true,
                'google_sync' => false,
                'sync_past_days' => 30,
                'sync_future_days' => 90,
                'calendar_view' => CALENDAR_VIEW_DEFAULT,
            ],
        ]);

        // Customer

        $this->CI->customers_model->save([
            'first_name' => 'James',
            'last_name' => 'Doe',
            'email' => 'james@example.org',
            'phone_number' => '+10000000000',
        ]);

        return $password;
    }

    /**
     * Create a database backup file.
     *
     * @param string|null $path Override the default backup path (storage/backups/*).
     *
     * @throws Exception
     */
    public function backup(?string $path = null): void
    {
        $path = $path ?? APPPATH . '/../storage/backups';
        $path = $this->validate_backup_path($path);

        $contents = $this->CI->dbutil->backup();

        $this->write_backup_file($path, $contents);
    }

    private function validate_backup_path(string $path): string
    {
        $absolutePath = $this->absolute_path($path);
        if (!file_exists($absolutePath) || is_link($absolutePath) || !is_dir($absolutePath)) {
            throw new InvalidArgumentException('The backup path does not exist: ' . $path);
        }

        $operatorOwner = $this->trusted_checkout_owner($absolutePath);
        $cursor = rtrim($absolutePath, '/\\');
        while ($cursor !== '' && $cursor !== '.' && $cursor !== DIRECTORY_SEPARATOR) {
            if (is_link($cursor)) {
                throw new InvalidArgumentException('The backup path has a symlinked ancestor: ' . $path);
            }
            $ancestor = lstat($cursor);
            if (
                $ancestor === false ||
                !$this->is_trusted_owner($ancestor, $operatorOwner) ||
                (($ancestor['mode'] & 0022) !== 0 && ($ancestor['mode'] & 01000) === 0)
            ) {
                throw new InvalidArgumentException('The backup path has an unsafe ancestor: ' . $path);
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        $resolved = realpath($absolutePath);
        $identity = $resolved === false ? false : lstat($resolved);
        if (
            $identity === false ||
            !$this->is_trusted_owner($identity, $operatorOwner) ||
            ($identity['mode'] & 0022) !== 0
        ) {
            throw new InvalidArgumentException('The backup path is not privately writable: ' . $path);
        }

        if (!is_writable($resolved)) {
            throw new RuntimeException('The backup path is not writable: ' . $path);
        }

        return $resolved;
    }

    /**
     * Create a backup file without following or overwriting a pre-existing leaf.
     *
     * @throws RuntimeException
     */
    private function write_backup_file(string $path, string $contents): string
    {
        $directory = lstat($path);
        $operatorOwner = $this->trusted_checkout_owner($path);
        if (
            $directory === false ||
            !$this->is_trusted_owner($directory, $operatorOwner) ||
            ($directory['mode'] & 0022) !== 0
        ) {
            throw new RuntimeException('The backup path is not privately writable: ' . $path);
        }

        $previousUmask = umask(0077);
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $filename = 'easyappointments-backup-' . bin2hex(random_bytes(16)) . '.gz';
                $target = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $filename;
                $handle = $this->create_backup_handle($target);

                if ($handle === false) {
                    continue;
                }

                try {
                    $currentDirectory = lstat($path);
                    if (
                        $currentDirectory === false ||
                        $currentDirectory['dev'] !== $directory['dev'] ||
                        $currentDirectory['ino'] !== $directory['ino']
                    ) {
                        throw new RuntimeException('The backup path changed while opening: ' . $path);
                    }

                    $file = fstat($handle);
                    if ($file === false || ($file['mode'] & 0777) !== 0600 || !flock($handle, LOCK_EX)) {
                        throw new RuntimeException('Unable to secure the backup file: ' . $target);
                    }

                    $written = 0;
                    $length = strlen($contents);
                    while ($written < $length) {
                        $chunk = fwrite($handle, substr($contents, $written));
                        if ($chunk === false || $chunk === 0) {
                            throw new RuntimeException('Unable to write the backup file: ' . $target);
                        }
                        $written += $chunk;
                    }

                    if (!fflush($handle)) {
                        throw new RuntimeException('Unable to flush the backup file: ' . $target);
                    }

                    $currentDirectory = lstat($path);
                    if (
                        $currentDirectory === false ||
                        $currentDirectory['dev'] !== $directory['dev'] ||
                        $currentDirectory['ino'] !== $directory['ino']
                    ) {
                        throw new RuntimeException('The backup path changed while writing: ' . $path);
                    }

                    flock($handle, LOCK_UN);
                    fclose($handle);

                    return $target;
                } catch (Throwable $exception) {
                    $file = @fstat($handle);
                    $current = @lstat($target);
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    if (
                        $file !== false &&
                        $current !== false &&
                        $file['dev'] === $current['dev'] &&
                        $file['ino'] === $current['ino']
                    ) {
                        @unlink($target);
                    }
                    throw $exception;
                }
            }

            throw new RuntimeException('Unable to create an exclusive backup file in: ' . $path);
        } finally {
            umask($previousUmask);
        }
    }

    /** @return resource|false */
    private function create_backup_handle(string $target)
    {
        return @fopen($target, 'x+b');
    }

    /** @param array<string, int> $identity */
    private function is_trusted_owner(array $identity, ?int $operatorOwner = null): bool
    {
        return function_exists('posix_geteuid') &&
            ($identity['uid'] === 0 ||
                $identity['uid'] === posix_geteuid() ||
                ($operatorOwner !== null && $identity['uid'] === $operatorOwner));
    }

    private function trusted_checkout_owner(string $path): ?int
    {
        if (!defined('APPPATH') || !function_exists('posix_geteuid')) {
            return null;
        }

        $defaultPath = realpath(rtrim(APPPATH, '/\\') . '/../storage/backups');
        $requestedPath = realpath($path);
        $checkoutRoot = realpath(rtrim(APPPATH, '/\\') . '/..');
        if (
            $defaultPath === false ||
            $requestedPath === false ||
            $checkoutRoot === false ||
            $requestedPath !== $defaultPath
        ) {
            return null;
        }

        $identity = lstat($checkoutRoot);
        return $identity === false ? null : $identity['uid'];
    }

    private function absolute_path(string $path): string
    {
        if ($path === '' || $path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new RuntimeException('Unable to resolve the backup path: ' . $path);
        }

        return rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}

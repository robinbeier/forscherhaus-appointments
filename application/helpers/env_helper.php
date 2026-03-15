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

if (!function_exists('env')) {
    /**
     * Gets the value of an environment variable.
     *
     * Example:
     *
     * $debug = env('debug', FALSE);
     *
     * @param string $key Environment key.
     * @param mixed|null $default Default value in case the requested variable has no value.
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    function env(string $key, mixed $default = null): mixed
    {
        if (empty($key)) {
            throw new InvalidArgumentException('The $key argument cannot be empty.');
        }

        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        $processValue = getenv($key);
        if ($processValue !== false) {
            return $processValue;
        }

        $serverValue = env_server_value($key);
        if ($serverValue !== null) {
            return $serverValue;
        }

        return $default;
    }
}

if (!function_exists('env_server_value')) {
    function env_server_value(string $key): ?string
    {
        foreach ([$key, 'REDIRECT_' . $key] as $candidate) {
            if (!array_key_exists($candidate, $_SERVER)) {
                continue;
            }

            return (string) $_SERVER[$candidate];
        }

        return null;
    }
}

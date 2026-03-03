<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Open Source Web Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) 2013 - 2020, Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.4.3
 * ---------------------------------------------------------------------------- */

/**
 * Localization Controller
 *
 * Contains all the localization related methods.
 *
 * @package Controllers
 */
class Localization extends EA_Controller
{
    /**
     * Change system language for current user.
     *
     * The language setting is stored in session data and retrieved every time the user visits any of the system pages.
     *
     * Notice: This method used to be in the Backend_api.php.
     */
    public function change_language(): void
    {
        try {
            $request_dto = $this->authRequestDtoFactory()->buildLocalizationRequestDto();
            $language = $request_dto->language;

            // Check if language exists in the available languages.
            if (!in_array($language, config('available_languages'))) {
                throw new RuntimeException('Translations for the given language does not exist (' . $language . ').');
            }

            session(['language' => $language]);

            config(['language' => $language]);

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    private function authRequestDtoFactory(): Auth_request_dto_factory
    {
        if (
            isset($this->auth_request_dto_factory) &&
            $this->auth_request_dto_factory instanceof Auth_request_dto_factory
        ) {
            return $this->auth_request_dto_factory;
        }

        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();

        if (
            !isset($CI->auth_request_dto_factory) ||
            !$CI->auth_request_dto_factory instanceof Auth_request_dto_factory
        ) {
            $CI->load->library('auth_request_dto_factory');
        }

        $this->auth_request_dto_factory = $CI->auth_request_dto_factory;

        return $this->auth_request_dto_factory;
    }
}

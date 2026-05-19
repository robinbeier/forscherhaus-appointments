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
 * Login page.
 *
 * This module implements the functionality of the login page.
 */
App.Pages.Login = (function () {
    const $loginForm = $('#login-form');
    const $username = $('#username');
    const $password = $('#password');
    const $forgotPassword = $('.forgot-password');
    const $alert = $('.alert');

    /**
     * Login Button "Click"
     *
     * Make an ajax call to the server and check whether the user's credentials are right.
     *
     * If yes then redirect him to his desired page, otherwise display a message.
     */
    function onLoginFormSubmit(event) {
        event.preventDefault();

        const username = $username.val();
        const password = $password.val();

        if (!username || !password) {
            return;
        }

        $alert.addClass('d-none').removeClass('alert-danger alert-success alert-info');

        App.Http.Login.validate(username, password).done((response) => {
            if (response.success) {
                window.location.href = vars('dest_url');
            } else {
                $alert.text(lang('login_failed'));
                $alert.removeClass('d-none alert-danger alert-success alert-info').addClass('alert-danger');
            }
        });
    }

    function onForgotPasswordClick(event) {
        event.preventDefault();

        $alert.text(lang('password_recovery_contact_robin'));
        $alert.removeClass('d-none alert-danger alert-success alert-info').addClass('alert-info');
    }

    $loginForm.on('submit', onLoginFormSubmit);
    $forgotPassword.on('click', onForgotPasswordClick);

    return {};
})();

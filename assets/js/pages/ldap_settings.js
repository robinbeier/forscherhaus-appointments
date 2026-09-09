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
 * LDAP settings page.
 *
 * This module implements the functionality of the LDAP settings page.
 */
App.Pages.LdapSettings = (function () {
    const $saveSettings = $('#save-settings');

    /**
     * Apply the setting values to the UI form.
     *
     * @param {Array} ldapSettings
     */
    function deserialize(ldapSettings) {
        ldapSettings.forEach((ldapSetting) => {
            const $field = $('[data-field="' + ldapSetting.name + '"]');

            $field.is(':checkbox')
                ? $field.prop('checked', Boolean(Number(ldapSetting.value)))
                : $field.val(ldapSetting.value);
        });
    }

    /**
     * Prepare an array of setting values based on the UI form.
     *
     * @return {Array}
     */
    function serialize() {
        const ldapSettings = [];

        $('[data-field]').each((index, field) => {
            const $field = $(field);

            ldapSettings.push({
                name: $field.data('field'),
                value: $field.is(':checkbox') ? Number($field.prop('checked')) : $field.val(),
            });
        });

        return ldapSettings;
    }

    /**
     * Save the current server settings.
     */
    function saveSettings() {
        const ldapSettings = serialize();

        return App.Http.LdapSettings.save(ldapSettings);
    }

    /**
     * Save the account information.
     */
    function onSaveSettingsClick() {
        saveSettings().done(() => {
            App.Layouts.Backend.displayNotification(lang('settings_saved'));
        });
    }

    /**
     * Initialize the module.
     */
    function initialize() {
        $saveSettings.on('click', onSaveSettingsClick);

        const ldapSettings = vars('ldap_settings');

        deserialize(ldapSettings);
    }

    document.addEventListener('DOMContentLoaded', initialize);

    return {};
})();

<?php extend('layouts/backend_layout'); ?>

<?php section('content'); ?>

<div id="ldap-settings-page" class="container backend-page">
    <div class="row">
        <div class="col-sm-3 offset-sm-1">
            <?php component('settings_nav'); ?>
        </div>
        <div id="ldap-settings" class="col-sm-6">
            <form>
                <fieldset>
                    <div class="d-flex justify-content-between align-items-center border-bottom mb-4 py-2">
                        <h4 class="text-black-50 mb-0 fw-light">
                            <?= lang('ldap') ?>
                        </h4>

                        <div>
                            <a href="<?= site_url('integrations') ?>" class="btn btn-outline-primary me-2">
                                <i class="fas fa-chevron-left me-2"></i>
                                <?= lang('back') ?>
                            </a>

                            <?php if (can('edit', PRIV_SYSTEM_SETTINGS)): ?>
                                <button type="button" id="save-settings" class="btn btn-primary">
                                    <i class="fas fa-check-square me-2"></i>
                                    <?= lang('save') ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!extension_loaded('ldap')): ?>
                        <div class="alert alert-warning">
                            <?= lang('ldap_extension_not_loaded') ?>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="mb-3">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="ldap-is-active"
                                           data-field="ldap_is_active">
                                    <label class="form-check-label" for="ldap-is-active">
                                        <?= lang('active') ?>
                                    </label>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="ldap-host">
                                    <?= lang('host') ?>
                                </label>
                                <input id="ldap-host" class="form-control" data-field="ldap_host">
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="ldap-port">
                                    <?= lang('port') ?>
                                </label>
                                <input id="ldap-port" class="form-control" data-field="ldap_port">
                            </div>
                        </div>
                    </div>

                    <?php slot('after_primary_appointment_fields'); ?>
                </fieldset>
            </form>
        </div>
    </div>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>

<script src="<?= asset_url('assets/js/http/ldap_settings_http_client.js') ?>"></script>
<script src="<?= asset_url('assets/js/pages/ldap_settings.js') ?>"></script>

<?php end_section('scripts'); ?>
